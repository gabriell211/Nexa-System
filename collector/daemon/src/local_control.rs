//! Administrative review server. The socket lives beside the protected SQLite
//! file and is reachable only through a mode-0700 service-owned directory
//! and a mode-0600 Unix socket. Never bind TCP or expose a browser endpoint.
use nexa_collector_core::{
    local_control::{socket_path, ControlRequest, ScheduleControlRequest, FleetControlRequest, BroadcastControlRequest, ControlResponse, MAX_MESSAGE_BYTES, PROTOCOL_VERSION},
    CollectorConfig, Outbox,
};
use std::{
    fs, io::{self, BufRead, Read, Write}, net::Shutdown,
    os::unix::{fs::{FileTypeExt, PermissionsExt}, net::{UnixListener, UnixStream}},
    path::{Path, PathBuf}, sync::{atomic::{AtomicBool, Ordering}, Arc},
    thread::{self, JoinHandle}, time::Duration,
};
use zeroize::Zeroizing;

struct Cleanup(PathBuf);
impl Drop for Cleanup {
    fn drop(&mut self) { let _ = fs::remove_file(&self.0); }
}

// On macOS the filesystem mode of an AF_UNIX socket is not a sufficient
// authentication primitive on its own. Verify native peer credentials too.
// Only the isolated service UID or root may send state-changing requests.
#[cfg(target_os = "macos")]
fn authorized_peer(stream: &UnixStream) -> bool {
    use std::os::fd::AsRawFd;
    unsafe extern "C" {
        fn getpeereid(s: i32, euid: *mut u32, egid: *mut u32) -> i32;
        fn geteuid() -> u32;
    }
    let mut uid = 0_u32;
    let mut gid = 0_u32;
    // SAFETY: all pointers are to live stack values, and the fd is owned by
    // the accepted UnixStream for the duration of this call.
    unsafe {
        getpeereid(stream.as_raw_fd(), &mut uid, &mut gid) == 0
            && (uid == geteuid() || uid == 0)
    }
}

#[cfg(not(target_os = "macos"))]
fn authorized_peer(_stream: &UnixStream) -> bool {
    // Linux enforces both the private directory and socket inode modes.
    true
}

fn private_parent(path: &Path) -> io::Result<()> {
    let parent = path.parent().ok_or_else(|| io::Error::other("socket parent missing"))?;
    let metadata = fs::symlink_metadata(parent)?;
    if !metadata.is_dir() || metadata.permissions().mode() & 0o077 != 0 {
        return Err(io::Error::new(io::ErrorKind::PermissionDenied,
            "control socket requires a private, non-symlink service data directory"));
    }
    Ok(())
}

pub fn start(
    queue: &Path, cfg: CollectorConfig, key: Zeroizing<String>,
    community: Option<Zeroizing<String>>, shutdown: Arc<AtomicBool>,
) -> io::Result<JoinHandle<()>> {
    let path = socket_path(queue);
    private_parent(&path)?;
    match fs::symlink_metadata(&path) {
        Ok(metadata) => {
            if !metadata.file_type().is_socket() {
                return Err(io::Error::new(io::ErrorKind::AlreadyExists,
                    "control socket path occupied by non-socket file"));
            }
            if UnixStream::connect(&path).is_ok() {
                return Err(io::Error::new(io::ErrorKind::AlreadyExists,
                    "another Collector control listener is already active"));
            }
            fs::remove_file(&path)?;
        }
        Err(e) if e.kind() == io::ErrorKind::NotFound => {}
        Err(e) => return Err(e),
    }
    let listener = UnixListener::bind(&path)?;
    if let Err(e) = fs::set_permissions(&path, fs::Permissions::from_mode(0o600)) {
        let _ = fs::remove_file(&path);
        return Err(e);
    }
    listener.set_nonblocking(true)?;
    let queue = queue.to_path_buf();
    Ok(thread::spawn(move || {
        let _cleanup = Cleanup(path);
        while !shutdown.load(Ordering::Relaxed) {
            match listener.accept() {
                Ok((mut stream, _)) => {
                    // Serializing requests bounds CPU, memory, and database
                    // write concurrency. One local request per connection.
                    // macOS/BSD may inherit O_NONBLOCK from the listener.
                    // Restore blocking reads before applying a finite read timeout:
                    // otherwise a fragmented request can be rejected with WouldBlock.
                    let response = if stream.set_nonblocking(false).is_err() {
                        ControlResponse {
                            accepted: false,
                            reason: Some("Erro no canal local.".into()),
                        }
                    } else if authorized_peer(&stream) {
                        handle(&mut stream, &queue, &cfg, &key, community.as_ref().map(|secret| secret.as_str()))
                    } else {
                        ControlResponse {
                            accepted: false,
                            reason: Some("Acesso ao canal local negado pelo sistema operacional.".into()),
                        }
                    };
                    let _ = stream.set_write_timeout(Some(Duration::from_secs(2)));
                    let _ = stream.write_all(&serde_json::to_vec(&response).unwrap_or_default());
                    let _ = stream.shutdown(Shutdown::Write);
                }
                Err(e) if e.kind() == io::ErrorKind::WouldBlock => {
                    thread::sleep(Duration::from_millis(80));
                }
                Err(_) => thread::sleep(Duration::from_millis(150)),
            }
        }
    }))
}

fn handle(
    stream: &mut UnixStream, queue: &Path, cfg: &CollectorConfig, key: &str,
    community: Option<&str>,
) -> ControlResponse {
    let denied = |reason: &'static str| ControlResponse {
        accepted: false, reason: Some(reason.into()),
    };
    if stream.set_read_timeout(Some(Duration::from_secs(2))).is_err() {
        return denied("Erro no canal local.");
    }
    let mut input = Vec::new();
    // Bounded newline-delimited frame; do not wait for EOF/half-close.
    let received = std::io::BufReader::new(stream)
        .take(MAX_MESSAGE_BYTES + 2).read_until(b'\n', &mut input);
    if received.is_err() || input.last() != Some(&b'\n')
        || input.len() as u64 > MAX_MESSAGE_BYTES + 1
    {
        return denied("Mensagem inválida ou acima do limite.");
    }
    // A message must match exactly one closed, versioned request shape.
    // Unknown fields, enrollment commands and arbitrary paths are rejected.
    let review = serde_json::from_slice::<ControlRequest>(&input).ok();
    let schedule = serde_json::from_slice::<ScheduleControlRequest>(&input).ok();
    let fleet = serde_json::from_slice::<FleetControlRequest>(&input).ok();
    let broadcast = serde_json::from_slice::<BroadcastControlRequest>(&input).ok();
    if usize::from(review.is_some()) + usize::from(schedule.is_some()) + usize::from(fleet.is_some()) + usize::from(broadcast.is_some()) != 1 {
        return denied("Comando local inválido.");
    }
    if review.as_ref().map(|r| r.version)
        .or_else(|| schedule.as_ref().map(|r| r.version))
        .or_else(|| fleet.as_ref().map(|r| r.version))
        .or_else(|| broadcast.as_ref().map(|r| r.version)) != Some(PROTOCOL_VERSION)
    {
        return denied("Versão do protocolo não suportada.");
    }
    let Ok(mut outbox) = Outbox::open_encrypted_disk_guard(
        queue, cfg.max_pending_samples, cfg.max_pending_payload_bytes,
        cfg.max_sqlite_files_bytes, key,
    ) else {
        return denied("Armazenamento protegido indisponível.");
    };
    if let Some(request) = review {
        match outbox.review_candidate(request.ip, request.action) {
            Ok(_) => ControlResponse { accepted: true, reason: None },
            Err(_) => denied("A decisão foi recusada: candidato ausente, desatualizado ou sem autorização."),
        }
    } else if let Some(request) = schedule {
        // Never update authorized_subnets in config.json. The only new scope
        // allowed here is a subset of the administrator's immutable CIDRs.
        match outbox.save_discovery_schedule_override(&cfg.authorized_subnets, &request.schedule) {
            Ok(_) => ControlResponse { accepted: true, reason: None },
            Err(_) => denied("Agenda inválida, faixas fora do escopo autorizado ou limite excedido."),
        }
    } else if let Some(request) = fleet {
        match crate::printer_control::execute(&mut outbox, cfg, &request.command, community) {
            Ok(_) => ControlResponse { accepted: true, reason: None },
            Err(reason) => ControlResponse { accepted: false, reason: Some(reason) },
        }
    } else if let Some(request) = broadcast {
        match crate::broadcast_control::execute(&outbox, cfg, &request.cidrs, community) {
            Ok(_) => ControlResponse { accepted: true, reason: None },
            Err(reason) => ControlResponse { accepted: false, reason: Some(reason) },
        }
    } else {
        denied("Comando local inválido.")
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use nexa_collector_core::{
        candidate_review::{CandidateDecision, ReviewAction},
        discovery::DiscoveryCandidate, local_control::submit, DiscoveryReport,
    };
    use chrono::Utc;

    fn config() -> CollectorConfig {
        serde_json::from_value(serde_json::json!({
            "collector_id": uuid::Uuid::new_v4(), "api_url": "https://nexa.example",
            "interval_seconds": 300, "authorized_subnets": ["10.20.1.0/24"],
            "printers": []
        })).unwrap()
    }

    #[test]
    fn socket_is_private_and_reviews_persist_without_generating_readings() {
        let dir = tempfile::tempdir().unwrap();
        // The runner may have an unusual umask; use the same private
        // permissions as the real service StateDirectory before binding.
        fs::set_permissions(dir.path(), fs::Permissions::from_mode(0o700)).unwrap();
        let queue = dir.path().join("queue.sqlite");
        let config = config();
        let key = Zeroizing::new("11".repeat(32));
        let ip = "10.20.1.7".parse().unwrap();
        let mut outbox = Outbox::open_encrypted_disk_guard(
            &queue, config.max_pending_samples, config.max_pending_payload_bytes,
            config.max_sqlite_files_bytes, &key,
        ).unwrap();
        outbox.save_discovery_report(&DiscoveryReport {
            last_completed_at: Some(Utc::now()), last_error_kind: None,
            observed_candidates: 1,
            candidates: vec![DiscoveryCandidate {
                ip, print_ports: vec![9100], http_server: None, escl_scanner: false,
                            reference: None, catalog_hint: None,
}],
        }).unwrap();
        drop(outbox);

        let stopping = Arc::new(AtomicBool::new(false));
        let handle = start(&queue, config.clone(), key.clone(), None, Arc::clone(&stopping)).unwrap();
        let socket = socket_path(&queue);
        assert_eq!(fs::metadata(&socket).unwrap().permissions().mode() & 0o777, 0o600);
        submit(&queue, ip, ReviewAction::Save).unwrap();
        assert!(submit(&queue, "10.20.1.8".parse().unwrap(), ReviewAction::Save).is_err());
        submit(&queue, ip, ReviewAction::Ignore).unwrap();
        let mut reopened = Outbox::open_encrypted_disk_guard(
            &queue, config.max_pending_samples, config.max_pending_payload_bytes,
            config.max_sqlite_files_bytes, &key,
        ).unwrap();
        assert_eq!(reopened.pending().unwrap(), 0);
        assert_eq!(reopened.candidate_reviews().unwrap()[0].decision, CandidateDecision::Ignored);
        submit(&queue, ip, ReviewAction::Reset).unwrap();
        assert!(reopened.candidate_reviews().unwrap().is_empty());
        stopping.store(true, Ordering::Relaxed);
        handle.join().unwrap();
        assert!(!socket.exists());
    }

    #[test]
    fn socket_accepts_fragmented_requests_without_premature_would_block() {
        use nexa_collector_core::DiscoverySchedule;

        let dir = tempfile::tempdir().unwrap();
        fs::set_permissions(dir.path(), fs::Permissions::from_mode(0o700)).unwrap();
        let queue = dir.path().join("queue.sqlite");
        let cfg = config();
        let key = Zeroizing::new("11".repeat(32));
        let store = Outbox::open_encrypted_disk_guard(
            &queue, cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes, &key,
        ).unwrap();
        drop(store);

        let stop = Arc::new(AtomicBool::new(false));
        let server = start(&queue, cfg, key, None, Arc::clone(&stop)).unwrap();
        let mut client = UnixStream::connect(socket_path(&queue)).unwrap();
        client.set_read_timeout(Some(Duration::from_secs(3))).unwrap();

        let request = serde_json::to_vec(&ScheduleControlRequest {
            version: PROTOCOL_VERSION,
            schedule: DiscoverySchedule {
                enabled: true, interval_hours: 6, cidrs: vec!["10.20.1.0/24".into()],
            },
        }).unwrap();
        let split = request.len() / 2;
        client.write_all(&request[..split]).unwrap();
        // The server must wait for the rest of the frame rather than returning
        // WouldBlock when the listener's accept socket is nonblocking on macOS.
        thread::sleep(Duration::from_millis(120));
        client.write_all(&request[split..]).unwrap();
        client.write_all(b"\n").unwrap();

        let mut response = Vec::new();
        client.read_to_end(&mut response).unwrap();
        let accepted: ControlResponse = serde_json::from_slice(&response).unwrap();
        assert!(accepted.accepted, "{:?}", accepted.reason);

        stop.store(true, Ordering::Relaxed);
        server.join().unwrap();
    }

    #[test]
    fn socket_updates_schedule_without_mutating_meter_configuration() {
        use nexa_collector_core::{DiscoverySchedule, local_control::submit_schedule};
        let dir = tempfile::tempdir().unwrap();
        fs::set_permissions(dir.path(), fs::Permissions::from_mode(0o700)).unwrap();
        let queue = dir.path().join("queue.sqlite");
        let cfg = config();
        let key = Zeroizing::new("11".repeat(32));
        let store = Outbox::open_encrypted_disk_guard(
            &queue, cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes, &key,
        ).unwrap();
        drop(store);
        let stop = Arc::new(AtomicBool::new(false));
        let server = start(&queue, cfg.clone(), key.clone(), None, Arc::clone(&stop)).unwrap();
        let desired = DiscoverySchedule {
            enabled: true, interval_hours: 6, cidrs: vec!["10.20.1.0/24".into()],
        };
        submit_schedule(&queue, desired).unwrap();
        let invalid = DiscoverySchedule {
            enabled: true, interval_hours: 5, cidrs: vec!["10.20.1.0/24".into()],
        };
        assert!(submit_schedule(&queue, invalid).is_err());
        assert!(submit_schedule(&queue, DiscoverySchedule {
            enabled: true, interval_hours: 24, cidrs: vec!["10.22.1.0/24".into()],
        }).is_err());
        let reopened = Outbox::open_encrypted_disk_guard(
            &queue, cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes, &key,
        ).unwrap();
        assert_eq!(reopened.discovery_schedule_override(&cfg.authorized_subnets)
            .unwrap().unwrap().interval_hours, 6);
        assert_eq!(reopened.pending().unwrap(), 0);
        stop.store(true, Ordering::Relaxed);
        server.join().unwrap();
    }

    #[test]
    fn rejects_socket_parent_with_group_or_world_access() {
        let dir = tempfile::tempdir().unwrap();
        fs::set_permissions(dir.path(), fs::Permissions::from_mode(0o755)).unwrap();
        let denied = private_parent(&dir.path().join("queue.sqlite.control.sock"));
        assert!(denied.is_err());
    }
}
