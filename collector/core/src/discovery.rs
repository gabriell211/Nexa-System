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
    time::{Duration, Instant},
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
    /// A read-only eSCL capability response identifies a scanner endpoint.
    /// It does not prove a successful scan or expose page counters.
    pub escl_scanner: bool,
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


fn invalid_scope(reason: &'static str) -> std::io::Error {
    std::io::Error::new(std::io::ErrorKind::InvalidInput, reason)
}

/// Plan a bounded, explicit scan. Requested ranges must be contained in the
/// administrator-provisioned `authorized_subnets` from the Collector config.
/// An open TCP port never counts as a registered or approved printer.
///
/// This operation never expands to a whole interface or public network.
pub fn plan_authorized_hosts(
    authorized_subnets: &[String],
    requested_cidrs: &[String],
) -> std::io::Result<Vec<Ipv4Addr>> {
    if requested_cidrs.is_empty() || requested_cidrs.len() > 4 {
        return Err(invalid_scope("choose between one and four IPv4 ranges"));
    }
    let allowed: Vec<Ipv4Net> = authorized_subnets
        .iter()
        .filter_map(|value| value.parse::<Ipv4Net>().ok())
        .collect();
    let mut hosts = BTreeSet::new();
    for value in requested_cidrs {
        let net = value.parse::<Ipv4Net>()
            .map_err(|_| invalid_scope("invalid IPv4 CIDR"))?;
        if net.prefix_len() < 24 {
            return Err(invalid_scope("discovery ranges cannot be larger than /24"));
        }
        // Check both ends: a subnet must not cross from RFC1918 into a
        // public/reserved region even when the authorized net is too broad.
        if !net.network().is_private() || !net.broadcast().is_private()
            || net.network().is_loopback() || net.network().is_link_local()
            || net.broadcast().is_link_local()
        {
            return Err(invalid_scope("only private RFC1918 IPv4 ranges are allowed"));
        }
        if !allowed.iter().any(|scope| {
            scope.contains(&net.network()) && scope.contains(&net.broadcast())
        }) {
            return Err(invalid_scope("requested CIDR is outside authorized subnets"));
        }
        for ip in net.hosts() {
            if !ip.is_private() || ip.is_loopback() || ip.is_link_local() {
                return Err(invalid_scope("range includes an unauthorized host"));
            }
            hosts.insert(ip);
            if hosts.len() > MAX_HOSTS {
                return Err(invalid_scope("authorized discovery exceeds 1024 hosts"));
            }
        }
    }
    if hosts.is_empty() {
        return Err(invalid_scope("selected networks contain no usable hosts"));
    }
    Ok(hosts.into_iter().collect())
}

/// Synchronous core called by the console in a background thread. The daemon
/// does not enroll, claim ownership, or upload discovered candidates.
pub fn discover_authorized_ranges(
    authorized_subnets: &[String],
    requested_cidrs: &[String],
) -> std::io::Result<Vec<DiscoveryCandidate>> {
    Ok(scan_hosts(plan_authorized_hosts(authorized_subnets, requested_cidrs)?))
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
                if let Some(candidate) = probe_candidate(ip, &PRINT_PORTS) {
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

fn probe_candidate(ip: Ipv4Addr, ports: &[u16]) -> Option<DiscoveryCandidate> {
    let print_ports: Vec<u16> = ports
        .iter()
        .copied()
        .filter(|port| {
            TcpStream::connect_timeout(&SocketAddr::from((ip, *port)), CONNECT_TIMEOUT).is_ok()
        })
        .collect();
    // Check eSCL even when no print port is open: dedicated network scanners
    // must not disappear from the candidate list.
    let escl_scanner = probe_escl(ip, 80);
    if print_ports.is_empty() && !escl_scanner {
        return None;
    }
    Some(DiscoveryCandidate {
        ip,
        print_ports,
        http_server: http_server(ip),
        escl_scanner,
    })
}

fn probe_escl(ip: Ipv4Addr, port: u16) -> bool {
    let Ok(mut stream) = TcpStream::connect_timeout(&SocketAddr::from((ip, port)), CONNECT_TIMEOUT)
    else {
        return false;
    };
    let request = format!(
        "GET /eSCL/ScannerCapabilities HTTP/1.1\r\nHost: {ip}\r\nConnection: close\r\n\r\n"
    );
    if stream
        .set_read_timeout(Some(Duration::from_secs(1)))
        .is_err()
        || stream.set_write_timeout(Some(CONNECT_TIMEOUT)).is_err()
        || stream.write_all(request.as_bytes()).is_err()
    {
        return false;
    }
    let mut response = Vec::with_capacity(2048);
    let mut chunk = [0u8; 2048];
    let deadline = Instant::now() + Duration::from_secs(1);
    while response.len() < 8192 && Instant::now() < deadline {
        let Ok(size) = stream.read(&mut chunk) else {
            break;
        };
        if size == 0 {
            break;
        }
        response.extend_from_slice(&chunk[..size]);
        if let Some(header_end) = response.windows(4).position(|part| part == b"\r\n\r\n") {
            let (headers, body_with_separator) = response.split_at(header_end);
            let body = &body_with_separator[4..];
            let status_ok =
                headers.starts_with(b"HTTP/1.0 200 ") || headers.starts_with(b"HTTP/1.1 200 ");
            if status_ok
                && (body
                    .windows(b"<scan:ScannerCapabilities".len())
                    .any(|part| part == b"<scan:ScannerCapabilities")
                    || body
                        .windows(b"<ScannerCapabilities".len())
                        .any(|part| part == b"<ScannerCapabilities"))
            {
                return true;
            }
        }
    }
    false
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
        sanitize_server_header(value)
    })
}

fn sanitize_server_header(value: &str) -> Option<String> {
    // Some printers embed serial numbers in Server. The unauthenticated
    // header is only a product hint, so do not surface a device identifier.
    let end = value
        .to_ascii_lowercase()
        .find("serial")
        .unwrap_or(value.len());
    let clean: String = value[..end]
        .trim()
        .trim_end_matches(';')
        .trim()
        .chars()
        .filter(|c| c.is_ascii_graphic() || *c == ' ')
        .take(120)
        .collect();
    (!clean.is_empty()).then_some(clean)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::net::TcpListener;

    #[test]
    fn manual_discovery_requires_authorized_bounded_rfc1918_ranges() {
        let authorized = vec!["10.12.0.0/16".to_owned(), "192.168.1.0/24".to_owned()];
        let allowed = plan_authorized_hosts(
            &authorized,
            &["10.12.4.0/30".into(), "192.168.1.50/32".into()],
        ).unwrap();
        assert!(allowed.contains(&"10.12.4.1".parse().unwrap()));
        assert!(allowed.contains(&"10.12.4.2".parse().unwrap()));
        assert!(allowed.contains(&"192.168.1.50".parse().unwrap()));
        assert_eq!(allowed.len(), 3);

        for denied in ["10.13.0.0/24", "8.8.8.8/32", "127.0.0.1/32",
            "169.254.2.0/24", "10.12.0.0/16", "bad", "224.0.0.0/24",
            "192.168.2.0/24"
        ] {
            assert!(plan_authorized_hosts(&authorized, &[denied.into()]).is_err(), "{denied}");
        }
        assert!(plan_authorized_hosts(&authorized, &[]).is_err());
        assert!(plan_authorized_hosts(&authorized, &vec!["10.12.4.0/24".into(); 5]).is_err());
    }

    #[test]
    fn overlapping_scopes_do_not_duplicate_hosts_or_exceed_limit() {
        let authorized = vec!["10.20.0.0/16".to_string()];
        let hosts = plan_authorized_hosts(
            &authorized, &["10.20.1.0/24".into(), "10.20.1.128/25".into()],
        ).unwrap();
        assert_eq!(hosts.len(), 254);
        assert_eq!(hosts.first().unwrap(), &"10.20.1.1".parse().unwrap());
        assert_eq!(hosts.last().unwrap(), &"10.20.1.254".parse().unwrap());
    }

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
        assert!(local_scan_net("10.1.1.1".parse().unwrap(), "0.0.0.0".parse().unwrap()).is_none());
        assert!(
            local_scan_net("10.1.1.1".parse().unwrap(), "255.0.255.0".parse().unwrap()).is_none()
        );
    }

    #[test]
    fn only_reachable_print_port_creates_a_candidate() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let port = listener.local_addr().unwrap().port();
        let candidate = probe_candidate(Ipv4Addr::LOCALHOST, &[port]).unwrap();
        assert_eq!(candidate.print_ports, vec![port]);
        assert!(candidate.http_server.is_none());
    }

    #[test]
    fn escl_requires_successful_capabilities_response() {
        fn probe_response(response: &'static [u8]) -> bool {
            let listener = TcpListener::bind("127.0.0.1:0").unwrap();
            let port = listener.local_addr().unwrap().port();
            let server = std::thread::spawn(move || {
                let (mut stream, _) = listener.accept().unwrap();
                let mut request = [0u8; 512];
                let size = stream.read(&mut request).unwrap();
                assert!(request[..size].starts_with(b"GET /eSCL/ScannerCapabilities "));
                stream.write_all(response).unwrap();
            });
            let found = probe_escl(Ipv4Addr::LOCALHOST, port);
            server.join().unwrap();
            found
        }
        assert!(probe_response(b"HTTP/1.0 200 OK\r\nContent-Type: text/xml\r\n\r\n<?xml version=\"1.0\"?><scan:ScannerCapabilities/>"));
        assert!(!probe_response(
            b"HTTP/1.0 500 Error\r\nContent-Type: text/xml\r\n\r\n<scan:ScannerCapabilities/>"
        ));
        assert!(!probe_response(
            b"HTTP/1.0 200 OK\r\nContent-Type: text/html\r\n\r\n<html>Printer only</html>"
        ));
    }

    #[test]
    fn does_not_expose_serial_from_printer_server_header() {
        assert_eq!(
            sanitize_server_header("HP HTTP Server; OfficeJet Pro 7740; Serial Number: secret"),
            Some("HP HTTP Server; OfficeJet Pro 7740".into())
        );
    }
}
