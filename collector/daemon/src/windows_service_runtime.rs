//! Native Windows Service Control Manager integration.
//! Runs only when SCM starts the binary with --service. CLI mode is unchanged.
use std::{
    ffi::OsString,
    fs::{self, OpenOptions},
    io::Write,
    path::PathBuf,
    sync::{atomic::{AtomicBool, Ordering}, Arc},
    time::Duration,
};
use nexa_collector_core::{CollectorConfig, DiscoverySchedule};
use uuid::Uuid;
use zeroize::{Zeroize, Zeroizing};
use windows_service::{
    define_windows_service,
    service::{
        ServiceControl, ServiceControlAccept, ServiceExitCode, ServiceState,
        ServiceStatus, ServiceType,
    },
    service_control_handler::{self, ServiceControlHandlerResult},
    service_dispatcher,
};

const SERVICE_NAME: &str = "NexaCollector";
define_windows_service!(ffi_service_main, service_main);

/// Consume the protected UTF-16 request created by the NSIS wizard. Secrets
/// never appear in process arguments, the SCM registry, installer details or
/// config.json. This command runs elevated only after NSIS locked the ACL.
pub fn provision() -> Result<(), Box<dyn std::error::Error>> {
    let base = std::env::var_os("ProgramData").ok_or("ProgramData is unavailable")?;
    provision_at(PathBuf::from(base).join("NexaCollector"))
}

fn provision_at(root: PathBuf) -> Result<(), Box<dyn std::error::Error>> {
    let credentials = root.join("credentials");
    let request = credentials.join("install-request.utf16");
    let config_path = root.join("config.json");
    let token_path = credentials.join("NEXA_COLLECTOR_TOKEN");
    let key_path = credentials.join("NEXA_COLLECTOR_DB_KEY_HEX");
    if !request.exists() {
        if config_path.is_file() && token_path.is_file() && key_path.is_file() {
            return Ok(()); // Reinstall preserves the customer's binding.
        }
        return Err("installer provisioning request is missing".into());
    }
    if token_path.exists() || key_path.exists() {
        return Err("existing credentials require manual recovery before reprovisioning".into());
    }
    let mut bytes = Zeroizing::new(fs::read(&request)?);
    if bytes.len() < 4 || bytes.len() % 2 != 0 || bytes[..2] != [0xff, 0xfe] {
        return Err("installer request encoding is invalid".into());
    }
    let units = Zeroizing::new(bytes[2..].chunks_exact(2)
        .map(|pair| u16::from_le_bytes([pair[0], pair[1]])).collect::<Vec<_>>());
    bytes.zeroize();
    let body = Zeroizing::new(String::from_utf16(&units)?);
    let fields: Vec<&str> = body.split('\n').map(|value| value.trim_end_matches('\r')).collect();
    if fields.len() != 7 || !fields[6].is_empty() {
        return Err("installer request has invalid fields".into());
    }
    let collector_id = Uuid::parse_str(fields[0]).map_err(|_| "collector ID is invalid")?;
    if collector_id.is_nil() { return Err("collector ID is invalid".into()); }
    let token = fields[1];
    if token.len() != 64 || !token.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err("collector key must contain 64 hexadecimal characters".into());
    }
    let proxy_url = if fields[3].is_empty() { None } else { Some(fields[3].to_owned()) };
    let proxy_auth = !fields[4].is_empty() || !fields[5].is_empty();
    if proxy_auth && (fields[4].is_empty() || fields[5].is_empty() || proxy_url.is_none()) {
        return Err("proxy username, password and URL must be supplied together".into());
    }
    let mut config = if config_path.exists() {
        nexa_collector_core::read_config(&config_path)?
    } else {
        CollectorConfig {
            collector_id, api_url: fields[2].to_owned(), proxy_url: None, proxy_auth: None,
            interval_seconds: 1800, heartbeat_interval_seconds: 60,
            max_pending_samples: 50_000, max_pending_payload_bytes: 64 * 1024 * 1024,
            max_sqlite_files_bytes: 512 * 1024 * 1024,
            authorized_subnets: Vec::new(), discovery_schedule: DiscoverySchedule::default(),
            printers: Vec::new(), usb_scanners: Vec::new(),
        }
    };
    config.collector_id = collector_id;
    config.api_url = fields[2].to_owned();
    config.proxy_url = proxy_url;
    config.validate()?;
    let config_json = serde_json::to_vec_pretty(&config)?;
    let key = Zeroizing::new(format!("{}{}", Uuid::new_v4().simple(), Uuid::new_v4().simple()));
    let user_path = credentials.join("NEXA_PROXY_USERNAME");
    let password_path = credentials.join("NEXA_PROXY_PASSWORD");
    if user_path.exists() || password_path.exists() {
        return Err("existing proxy credentials require manual recovery".into());
    }

    let mut created = Vec::new();
    let staged_config = root.join("config.installing.json");
    let result = (|| -> std::io::Result<()> {
        write_new(&staged_config, &config_json)?;
        created.push(staged_config.clone());
        write_new(&key_path, key.as_bytes())?;
        created.push(key_path.clone());
        write_new(&token_path, token.as_bytes())?;
        created.push(token_path.clone());
        if proxy_auth {
            write_new(&user_path, fields[4].as_bytes())?;
            created.push(user_path.clone());
            write_new(&password_path, fields[5].as_bytes())?;
            created.push(password_path.clone());
        }
        if config_path.exists() {
            let backup = root.join("config.before-install.json");
            fs::rename(&config_path, &backup)?;
            if let Err(error) = fs::rename(&staged_config, &config_path) {
                let _ = fs::rename(&backup, &config_path);
                return Err(error);
            }
            let _ = fs::remove_file(backup);
        } else {
            fs::rename(&staged_config, &config_path)?;
        }
        Ok(())
    })();
    if let Err(error) = result {
        for path in created { let _ = fs::remove_file(path); }
        return Err(error.into());
    }
    fs::remove_file(request)?;
    Ok(())
}

fn write_new(path: &std::path::Path, bytes: &[u8]) -> std::io::Result<()> {
    let mut file = OpenOptions::new().write(true).create_new(true).open(path)?;
    file.write_all(bytes)?;
    file.sync_all()
}

pub fn dispatch() -> Result<(), Box<dyn std::error::Error>> {
    service_dispatcher::start(SERVICE_NAME, ffi_service_main)?;
    Ok(())
}

fn service_main(_args: Vec<OsString>) {
    if let Err(error) = run_service() {
        // Never print tokens, SQLite payloads or Windows credential contents.
        eprintln!("Collector Windows service lifecycle error: {error}");
    }
}

fn status(state: ServiceState, success: bool) -> ServiceStatus {
    ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: state,
        controls_accepted: if state == ServiceState::Running {
            ServiceControlAccept::STOP
        } else {
            ServiceControlAccept::empty()
        },
        exit_code: if success {
            ServiceExitCode::Win32(0)
        } else {
            ServiceExitCode::Win32(1)
        },
        checkpoint: if state == ServiceState::StartPending { 1 } else { 0 },
        wait_hint: if state == ServiceState::StartPending {
            Duration::from_secs(60)
        } else {
            Duration::default()
        },
        process_id: None,
    }
}

fn service_paths() -> Result<Vec<String>, Box<dyn std::error::Error>> {
    let program_data = std::env::var_os("ProgramData")
        .ok_or("ProgramData environment is unavailable")?;
    let root = PathBuf::from(program_data).join("NexaCollector");
    let config = root.join("config.json");
    let queue = root.join("data").join("queue.sqlite");
    Ok(vec![
        std::env::current_exe()?.to_string_lossy().into_owned(),
        config.to_string_lossy().into_owned(),
        queue.to_string_lossy().into_owned(),
    ])
}

fn run_service() -> windows_service::Result<()> {
    let shutdown = Arc::new(AtomicBool::new(false));
    let handler_shutdown = Arc::clone(&shutdown);
    let event_handler = move |event| match event {
        ServiceControl::Stop => {
            handler_shutdown.store(true, Ordering::SeqCst);
            ServiceControlHandlerResult::NoError
        }
        ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
        _ => ServiceControlHandlerResult::NotImplemented,
    };
    let handle = service_control_handler::register(SERVICE_NAME, event_handler)?;
    handle.set_service_status(status(ServiceState::StartPending, true))?;

    let result = service_paths().and_then(|args| {
        super::run_application(
            args, shutdown,
            || {
                handle.set_service_status(status(ServiceState::Running, true))?;
                Ok(())
            },
            true, false,
        )
    });

    let succeeded = result.is_ok();
    if let Err(ref error) = result {
        // Errors are classified by the daemon; avoid replaying sensitive
        // payloads or HTTP response content in the Windows event log.
        eprintln!("NexaCollector failed to initialize or run: {error}");
    }
    handle.set_service_status(status(ServiceState::Stopped, succeeded))?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn request(root: &std::path::Path, token: &str) {
        fs::create_dir_all(root.join("credentials")).unwrap();
        let payload = format!(
            "{}\r\n{}\r\nhttps://nexa.example.com\r\nhttp://proxy.example:8080\r\noperator\r\nsecret\r\n",
            "f47ac10b-58cc-4372-a567-0e02b2c3d479", token,
        );
        let mut bytes = vec![0xff, 0xfe];
        for unit in payload.encode_utf16() { bytes.extend_from_slice(&unit.to_le_bytes()); }
        fs::write(root.join("credentials/install-request.utf16"), bytes).unwrap();
    }

    #[test]
    fn provisions_portal_identity_proxy_and_private_credentials() {
        let dir = tempfile::tempdir().unwrap();
        let root = dir.path().join("NexaCollector");
        let token = "ab".repeat(32);
        request(&root, &token);
        provision_at(root.clone()).unwrap();
        let cfg = nexa_collector_core::read_config(root.join("config.json")).unwrap();
        assert_eq!(cfg.collector_id.to_string(), "f47ac10b-58cc-4372-a567-0e02b2c3d479");
        assert_eq!(cfg.api_url, "https://nexa.example.com");
        assert_eq!(cfg.proxy_url.as_deref(), Some("http://proxy.example:8080"));
        assert!(cfg.authorized_subnets.is_empty());
        assert!(cfg.printers.is_empty());
        let credentials = root.join("credentials");
        assert_eq!(fs::read_to_string(credentials.join("NEXA_COLLECTOR_TOKEN")).unwrap(), token);
        assert_eq!(fs::read_to_string(credentials.join("NEXA_PROXY_USERNAME")).unwrap(), "operator");
        assert_eq!(fs::read_to_string(credentials.join("NEXA_PROXY_PASSWORD")).unwrap(), "secret");
        let key = fs::read_to_string(credentials.join("NEXA_COLLECTOR_DB_KEY_HEX")).unwrap();
        assert_eq!(key.len(), 64);
        assert!(key.bytes().all(|byte| byte.is_ascii_hexdigit()));
        assert!(!credentials.join("install-request.utf16").exists());
        provision_at(root.clone()).unwrap();
        assert_eq!(fs::read_to_string(credentials.join("NEXA_COLLECTOR_DB_KEY_HEX")).unwrap(), key);
    }

    #[test]
    fn refuses_invalid_portal_token_without_creating_binding() {
        let dir = tempfile::tempdir().unwrap();
        let root = dir.path().join("NexaCollector");
        request(&root, "invalid");
        assert!(provision_at(root.clone()).is_err());
        assert!(!root.join("config.json").exists());
        assert!(!root.join("credentials/NEXA_COLLECTOR_TOKEN").exists());
    }

    #[test]
    fn service_only_accepts_stop_after_running() {
        let pending = status(ServiceState::StartPending, true);
        assert!(pending.controls_accepted.is_empty());
        assert_eq!(pending.checkpoint, 1);
        assert_eq!(pending.wait_hint, Duration::from_secs(60));

        let running = status(ServiceState::Running, true);
        assert!(running.controls_accepted.contains(ServiceControlAccept::STOP));
        assert_eq!(running.exit_code, ServiceExitCode::Win32(0));

        let stopped = status(ServiceState::Stopped, false);
        assert!(stopped.controls_accepted.is_empty());
        assert_eq!(stopped.exit_code, ServiceExitCode::Win32(1));
    }
}
