//! Administrative review server. The socket lives beside the protected SQLite
//! file and is reachable only through a mode-0700 service-owned directory
//! and a mode-0600 Unix socket. Never bind TCP or expose a browser endpoint.
use nexa_collector_core::{
    local_control::{socket_path, ControlRequest, ControlResponse, MAX_MESSAGE_BYTES, PROTOCOL_VERSION},
    CollectorConfig, Outbox,
};
use std::{
    fs, io::{self, Read, Write}, net::Shutdown,
    os::unix::{fs::{FileTypeExt, PermissionsExt}, net::{UnixListener, UnixStream}},
    path::{Path, PathBuf}, sync::{atomic::{AtomicBool, Ordering}, Arc},
    thread::{self, JoinHandle}, time::Duration,
};
use zeroize::Zeroizing;

struct Cleanup(PathBuf);
impl Drop for Cleanup {
    fn drop(&mut self) { let _ = fs::remove_file(&self.0); }
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
    shutdown: Arc<AtomicBool>,
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
                    let response = handle(&mut stream, &queue, &cfg, &key);
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
) -> ControlResponse {
    let denied = |reason: &'static str| ControlResponse {
        accepted: false, reason: Some(reason.into()),
    };
    if stream.set_read_timeout(Some(Duration::from_secs(2))).is_err() {
        return denied("Erro no canal local.");
    }
    let mut input = Vec::new();
    if stream.take(MAX_MESSAGE_BYTES + 1).read_to_end(&mut input).is_err()
        || input.len() as u64 > MAX_MESSAGE_BYTES
    {
        return denied("Mensagem inválida ou acima do limite.");
    }
    let Ok(request) = serde_json::from_slice::<ControlRequest>(&input) else {
        return denied("Comando local inválido.");
    };
    if request.version != PROTOCOL_VERSION {
        return denied("Versão do protocolo não suportada.");
    }
    let Ok(mut outbox) = Outbox::open_encrypted_disk_guard(
        queue, cfg.max_pending_samples, cfg.max_pending_payload_bytes,
        cfg.max_sqlite_files_bytes, key,
    ) else {
        return denied("Armazenamento protegido indisponível.");
    };
    match outbox.review_candidate(request.ip, request.action) {
        Ok(_) => ControlResponse { accepted: true, reason: None },
        Err(_) => denied("A decisão foi recusada: candidato ausente, desatualizado ou sem autorização."),
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
            }],
        }).unwrap();
        drop(outbox);

        let stopping = Arc::new(AtomicBool::new(false));
        let handle = start(&queue, config.clone(), key.clone(), Arc::clone(&stopping)).unwrap();
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
    fn rejects_socket_parent_with_group_or_world_access() {
        let dir = tempfile::tempdir().unwrap();
        fs::set_permissions(dir.path(), fs::Permissions::from_mode(0o755)).unwrap();
        let denied = private_parent(&dir.path().join("queue.sqlite.control.sock"));
        assert!(denied.is_err());
    }
}
