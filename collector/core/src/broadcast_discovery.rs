//! Bounded SNMPv2c directed-broadcast discovery. Broadcast is best-effort:
//! many devices and networks intentionally ignore it. No credentials exposed
//! to the Console, no SNMP SET, no enrollment and no counter certification.
use crate::discovery::{plan_authorized_hosts, DiscoveryCandidate};
use if_addrs::{get_if_addrs, IfAddr};
use ipnet::Ipv4Net;
use std::{collections::BTreeSet, io, net::{Ipv4Addr, SocketAddrV4, UdpSocket},
    time::{Duration, Instant}};
use uuid::Uuid;

const MAX_RESPONSES: usize = 128;
const BROADCAST_WAIT: Duration = Duration::from_secs(2);
const DESCR_OID: [u8; 8] = [43, 6, 1, 2, 1, 1, 1, 0];

fn invalid(message: &'static str) -> io::Error {
    io::Error::new(io::ErrorKind::InvalidInput, message)
}

fn ber(tag: u8, payload: &[u8]) -> Vec<u8> {
    let mut out = vec![tag];
    if payload.len() < 128 {
        out.push(payload.len() as u8);
    } else {
        out.push(0x81);
        out.push(payload.len() as u8);
    }
    out.extend_from_slice(payload);
    out
}

fn get_request(community: &[u8], id: u32) -> io::Result<Vec<u8>> {
    if community.is_empty() || community.len() > 64 {
        return Err(invalid("SNMP community length must be between 1 and 64 bytes"));
    }
    let mut binding = ber(0x06, &DESCR_OID);
    binding.extend_from_slice(&ber(0x05, &[]));
    let binding = ber(0x30, &binding);
    let mut pdu = ber(0x02, &id.to_be_bytes());
    pdu.extend_from_slice(&[0x02, 0x01, 0, 0x02, 0x01, 0]);
    pdu.extend_from_slice(&ber(0x30, &binding));
    let pdu = ber(0xa0, &pdu);
    let mut message = vec![0x02, 0x01, 0x01];
    message.extend_from_slice(&ber(0x04, community));
    message.extend_from_slice(&pdu);
    Ok(ber(0x30, &message))
}

fn tlv(bytes: &[u8]) -> Option<(u8, &[u8], &[u8])> {
    if bytes.len() < 2 { return None; }
    let (n, size) = match bytes[1] {
        0..=127 => (bytes[1] as usize, 2),
        0x81 if bytes.len() >= 3 => (bytes[2] as usize, 3),
        0x82 if bytes.len() >= 4 => (usize::from(u16::from_be_bytes([bytes[2], bytes[3]])), 4),
        _ => return None,
    };
    let end = size + n;
    if end > bytes.len() { return None; }
    Some((bytes[0], &bytes[size..end], &bytes[end..]))
}

fn positive_integer(bytes: &[u8]) -> Option<u32> {
    if bytes.is_empty() || bytes.len() > 5 || bytes[0] & 0x80 != 0 { return None; }
    bytes.iter().try_fold(0u32, |n, part| n.checked_mul(256)?.checked_add(*part as u32))
}

fn valid_response(bytes: &[u8], community: &[u8], id: u32) -> bool {
    let Some((0x30, msg, rest)) = tlv(bytes) else { return false; };
    if !rest.is_empty() { return false; }
    let Some((0x02, version, next)) = tlv(msg) else { return false; };
    if positive_integer(version) != Some(1) { return false; }
    let Some((0x04, observed, next)) = tlv(next) else { return false; };
    if observed != community { return false; }
    let Some((0xa2, response, rest)) = tlv(next) else { return false; };
    if !rest.is_empty() { return false; }
    let Some((0x02, request_id, next)) = tlv(response) else { return false; };
    if positive_integer(request_id) != Some(id) { return false; }
    let Some((0x02, status, next)) = tlv(next) else { return false; };
    if positive_integer(status) != Some(0) { return false; }
    let Some((0x02, _, next)) = tlv(next) else { return false; };
    let Some((0x30, binds, rest)) = tlv(next) else { return false; };
    if !rest.is_empty() { return false; }
    let Some((0x30, binding, rest)) = tlv(binds) else { return false; };
    if !rest.is_empty() { return false; }
    let Some((0x06, oid, value)) = tlv(binding) else { return false; };
    if oid != DESCR_OID { return false; }
    let Some((kind, _, remaining)) = tlv(value) else { return false; };
    remaining.is_empty() && !matches!(kind, 0x80 | 0x81 | 0x82 | 0x05)
}

/// Returns only requested /24-or-smaller broadcast addresses. A /32 is
/// valid for detailed search but cannot have a directed broadcast.
pub fn plan_broadcast_networks(
    allowed: &[String], requested: &[String],
) -> io::Result<Vec<Ipv4Net>> {
    plan_authorized_hosts(allowed, requested)?;
    let mut nets = BTreeSet::new();
    for raw in requested {
        let net: Ipv4Net = raw.parse().map_err(|_| invalid("invalid broadcast CIDR"))?;
        if net.prefix_len() >= 31 {
            return Err(invalid("broadcast needs a subnet with at least two usable hosts"));
        }
        nets.insert((u32::from(net.network()), net.prefix_len()));
    }
    nets.into_iter().map(|(network, prefix)| {
        Ipv4Net::new(Ipv4Addr::from(network), prefix)
            .map_err(|_| invalid("invalid IPv4 network"))
    }).collect()
}

pub fn discover_broadcast(
    allowed: &[String], requested: &[String], community: &[u8],
) -> io::Result<Vec<DiscoveryCandidate>> {
    let nets = plan_broadcast_networks(allowed, requested)?;
    let local = get_if_addrs()?;
    // No directed broadcast towards remote subnets; this is a local-LAN
    // operation and never attempts a router/network-wide broadcast.
    for target in &nets {
        let on_link = local.iter().any(|iface| {
            let IfAddr::V4(ref address) = iface.addr else { return false };
            if !address.ip.is_private() || address.ip.is_loopback() { return false; }
            let Ok(interface) = Ipv4Net::with_netmask(address.ip, address.netmask)
                else { return false };
            interface.contains(&target.network()) && interface.contains(&target.broadcast())
        });
        if !on_link {
            return Err(invalid("broadcast CIDR is not on a directly connected local interface"));
        }
    }
    let socket = UdpSocket::bind((Ipv4Addr::UNSPECIFIED, 0))?;
    socket.set_broadcast(true)?;
    socket.set_read_timeout(Some(Duration::from_millis(180)))?;
    let mut found = BTreeSet::new();
    for net in nets {
        let nonce = Uuid::new_v4();
        let mut raw_id = [0u8; 4];
        raw_id.copy_from_slice(&nonce.as_bytes()[0..4]);
        let id = u32::from_be_bytes(raw_id) & 0x7fff_ffff;
        let packet = get_request(community, id)?;
        socket.send_to(&packet, SocketAddrV4::new(net.broadcast(), 161))?;
        let deadline = Instant::now() + BROADCAST_WAIT;
        while Instant::now() < deadline && found.len() < MAX_RESPONSES {
            let mut response = [0u8; 2048];
            match socket.recv_from(&mut response) {
                Ok((size, std::net::SocketAddr::V4(addr)))
                    if net.contains(addr.ip())
                        && *addr.ip() != net.network()
                        && *addr.ip() != net.broadcast()
                        && valid_response(&response[..size], community, id) =>
                    { found.insert(*addr.ip()); }
                Ok(_) => {}
                Err(err) if matches!(err.kind(), io::ErrorKind::TimedOut | io::ErrorKind::WouldBlock) => {}
                Err(err) => return Err(err),
            }
        }
    }
    Ok(found.into_iter().map(|ip| DiscoveryCandidate {
        ip, print_ports: Vec::new(), http_server: None, escl_scanner: false,
        reference: None,
    }).collect())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn validates_authorized_scope_and_directed_broadcast() {
        let allowed = vec!["192.168.15.0/24".into()];
        let nets = plan_broadcast_networks(&allowed, &["192.168.15.0/24".into()]).unwrap();
        assert_eq!(nets[0].broadcast(), "192.168.15.255".parse::<Ipv4Addr>().unwrap());
        assert!(plan_broadcast_networks(&allowed, &["192.168.16.0/24".into()]).is_err());
        assert!(plan_broadcast_networks(&allowed, &["8.8.8.0/24".into()]).is_err());
        assert!(plan_broadcast_networks(&allowed, &["192.168.15.1/32".into()]).is_err());
    }
    #[test]
    fn accepts_only_authenticated_bounded_snmp_get_responses() {
        let community = b"private";
        let id = 100u32;
        let mut binding = ber(0x06, &DESCR_OID);
        binding.extend_from_slice(&ber(0x04, b"Printer"));
        let mut pdu = ber(0x02, &id.to_be_bytes());
        pdu.extend_from_slice(&[0x02, 0x01, 0, 0x02, 0x01, 0]);
        pdu.extend_from_slice(&ber(0x30, &ber(0x30, &binding)));
        let mut msg = vec![0x02, 0x01, 0x01];
        msg.extend_from_slice(&ber(0x04, community));
        msg.extend_from_slice(&ber(0xa2, &pdu));
        let valid = ber(0x30, &msg);
        assert!(valid_response(&valid, community, id));
        assert!(!valid_response(&valid, b"public", id));
        assert!(!valid_response(&valid, community, 101));
        assert!(!valid_response(&valid[..valid.len() - 1], community, id));
    }

    #[test]
    fn broadcast_get_is_bounded_and_requires_matching_response() {
        let packet = get_request(b"private", 45).unwrap();
        let (tag, _, tail) = tlv(&packet).unwrap();
        assert_eq!(tag, 0x30);
        assert!(tail.is_empty());
        assert!(get_request(&[], 3).is_err());
        assert!(!valid_response(&packet, b"private", 45));
        assert!(!valid_response(&packet, b"public", 45));
    }
}
