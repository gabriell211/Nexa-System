//! Bounded, read-only discovery of print services on directly connected private IPv4 networks.
//! A candidate is never a registered printer or a verified meter.

use if_addrs::{get_if_addrs, IfAddr};
use ipnet::Ipv4Net;
use serde::Serialize;
use std::{
    collections::BTreeSet,
    io::{Read, Write},
    net::{Ipv4Addr, SocketAddr, TcpStream},
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc, Mutex,
    },
    thread,
    time::Duration,
};

const PRINT_PORTS: [u16; 3] = [9100, 515, 631];
const CONNECT_TIMEOUT: Duration = Duration::from_millis(180);
const MAX_HOSTS: usize = 1024;
const WORKERS: usize = 16;

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct DiscoveryCandidate {
    pub ip: Ipv4Addr,
    /// A reachable print port is evidence of a candidate, not a model or meter certification.
    pub print_ports: Vec<u16>,
    pub http_server: Option<String>,
}

/// Clamp large interfaces to the host's own /24, and skip public, loopback and
/// link-local interfaces. Discovery never scans the internet or an entire /16.
fn local_scan_net(ip: Ipv4Addr, mask: Ipv4Addr) -> Option<Ipv4Net> {
    if !ip.is_private() || ip.is_loopback() || ip.is_link_local() {
        return None;
    }
    let bits = u32::from(mask);
    let prefix = bits.count_ones() as u8;
    if prefix < 8 {
        return None;
    }
    if bits != (u32::MAX.checked_shl(32 - prefix as u32).unwrap_or(0)) {
        return None;
    }
    Ipv4Net::new(ip, prefix.max(24)).ok().map(|net| net.trunc())
}

pub fn discover_local() -> std::io::Result<Vec<DiscoveryCandidate>> {
    let interfaces = get_if_addrs()?;
    let mut hosts = BTreeSet::new();
    for interface in interfaces {
        if let IfAddr::V4(address) = interface.addr {
            if let Some(net) = local_scan_net(address.ip, address.netmask) {
                for host in net.hosts() {
                    if host != address.ip {
                        hosts.insert(host);
                    }
                }
            }
        }
    }
    if hosts.len() > MAX_HOSTS {
        return Err(std::io::Error::new(
            std::io::ErrorKind::InvalidInput,
            "local discovery exceeds the host limit",
        ));
    }
    Ok(scan_hosts(hosts.into_iter().collect()))
}

fn scan_hosts(hosts: Vec<Ipv4Addr>) -> Vec<DiscoveryCandidate> {
    let hosts = Arc::new(hosts);
    let cursor = AtomicUsize::new(0);
    let found = Mutex::new(Vec::new());
    thread::scope(|scope| {
        for _ in 0..WORKERS.min(hosts.len()) {
            let hosts = Arc::clone(&hosts);
            let cursor = &cursor;
            let found = &found;
            scope.spawn(move || loop {
                let index = cursor.fetch_add(1, Ordering::Relaxed);
                let Some(ip) = hosts.get(index).copied() else {
                    break;
                };
                if let Some(candidate) = probe_ports(ip, &PRINT_PORTS) {
                    found
                        .lock()
                        .expect("discovery result mutex poisoned")
                        .push(candidate);
                }
            });
        }
    });
    let mut result = found.into_inner().expect("discovery result mutex poisoned");
    result.sort_by_key(|candidate| candidate.ip);
    result
}

fn probe_ports(ip: Ipv4Addr, ports: &[u16]) -> Option<DiscoveryCandidate> {
    let print_ports: Vec<u16> = ports
        .iter()
        .copied()
        .filter(|port| {
            TcpStream::connect_timeout(&SocketAddr::from((ip, *port)), CONNECT_TIMEOUT).is_ok()
        })
        .collect();
    if print_ports.is_empty() {
        return None;
    }
    Some(DiscoveryCandidate {
        ip,
        print_ports,
        http_server: http_server(ip),
    })
}

fn http_server(ip: Ipv4Addr) -> Option<String> {
    let mut stream =
        TcpStream::connect_timeout(&SocketAddr::from((ip, 80)), CONNECT_TIMEOUT).ok()?;
    stream.set_read_timeout(Some(CONNECT_TIMEOUT)).ok()?;
    stream.set_write_timeout(Some(CONNECT_TIMEOUT)).ok()?;
    stream
        .write_all(b"HEAD / HTTP/1.0\r\nHost: printer\r\n\r\n")
        .ok()?;
    let mut buf = [0u8; 2048];
    let size = stream.read(&mut buf).ok()?;
    let headers = String::from_utf8_lossy(&buf[..size]);
    headers.lines().find_map(|line| {
        let (name, value) = line.split_once(':')?;
        if !name.eq_ignore_ascii_case("server") {
            return None;
        }
        let clean: String = value
            .trim()
            .chars()
            .filter(|c| c.is_ascii_graphic() || *c == ' ')
            .take(120)
            .collect();
        (!clean.is_empty()).then_some(clean)
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::net::TcpListener;

    #[test]
    fn private_large_network_is_limited_to_local_24() {
        assert_eq!(
            local_scan_net(
                "192.168.15.174".parse().unwrap(),
                "255.255.0.0".parse().unwrap()
            )
            .unwrap()
            .to_string(),
            "192.168.15.0/24"
        );
        assert_eq!(
            local_scan_net(
                "10.2.3.9".parse().unwrap(),
                "255.255.255.240".parse().unwrap()
            )
            .unwrap()
            .to_string(),
            "10.2.3.0/28"
        );
        assert!(local_scan_net(
            "169.254.1.2".parse().unwrap(),
            "255.255.0.0".parse().unwrap()
        )
        .is_none());
        assert!(
            local_scan_net("8.8.8.8".parse().unwrap(), "255.255.255.0".parse().unwrap()).is_none()
        );
        assert!(local_scan_net(
            "10.1.1.1".parse().unwrap(),
            "0.0.0.0".parse().unwrap()
        )
        .is_none());
        assert!(
            local_scan_net("10.1.1.1".parse().unwrap(), "255.0.255.0".parse().unwrap()).is_none()
        );
    }

    #[test]
    fn only_reachable_print_port_creates_a_candidate() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        let candidate = probe_ports(Ipv4Addr::LOCALHOST, &[port]).unwrap();
        assert_eq!(candidate.print_ports, vec![port]);
        assert!(candidate.http_server.is_none());
    }
}
