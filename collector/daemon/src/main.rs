use nexa_collector_core::{
    collect_snmp_v2c, heartbeat_sleep_seconds, read_config, send_heartbeat, send_heartbeat_with_watchdog, upload_pending,
    upload_error_kind, CollectorConfig, CollectorError, DiscoveryReport, DiscoverySchedule, Outbox, UploadRetryPolicy, WatchdogHeartbeat,
};
use chrono::{Duration as ChronoDuration, Utc};
use std::{
    env, error::Error, fs, path::Path, sync::{atomic::{AtomicBool, Ordering}, mpsc, Arc},
    thread, time::{Duration, Instant},
};
use zeroize::Zeroizing;

mod progress_watchdog;
mod recovery_guard;

#[cfg(unix)]
mod local_control;
#[cfg(windows)]
mod windows_local_control;

#[cfg(windows)]
mod windows_service_runtime;
#[cfg(target_os = "macos")]
mod macos_launchd_runtime;

fn main() -> Result<(), Box<dyn Error>> {
    let args: Vec<String> = env::args().collect();
    if args.len() == 2 && args[1] == "--mib-catalog" {
        // Offline read-only metadata dump; does not scan any IP address,
        // access SNMP secrets or enroll a monitored printer.
        let payload = serde_json::json!({
            "schema_version": 1,
            "coverage": "generic_printer_mib_v2_not_model_certified",
            "manufacturers": nexa_collector_core::mib_registry::manufacturer_entries(),
            "standard_oids": nexa_collector_core::mib_registry::OIDS,
        });
        println!("{}", serde_json::to_string_pretty(&payload)?);
        return Ok(());
    }
    if args.len() == 2 && args[1] == "--discover-usb" {
        let candidates = nexa_collector_core::usb_devices::discover_usb_devices()?;
        println!("{}", serde_json::to_string_pretty(&candidates)?);
        return Ok(());
    }
    if args.len() == 2 && args[1] == "--read-canon-drc225-usb" {
        let meter = nexa_collector_core::canon_drc225::read()?;
        println!("{}", serde_json::to_string_pretty(&meter)?);
        return Ok(());
    }
    if args.len() == 2 && args[1] == "--discover-local" {
        let candidates = nexa_collector_core::discovery::discover_local()?;
        println!("{}", serde_json::to_string_pretty(&candidates)?);
        return Ok(());
    }
    #[cfg(windows)]
    if args.len() == 2 && args[1] == "--service" {
        return windows_service_runtime::dispatch();
    }
    #[cfg(target_os = "macos")]
    if args.len() == 2 && args[1] == "--launchd" {
        return macos_launchd_runtime::dispatch();
    }

    run_application(args, Arc::new(AtomicBool::new(false)), || Ok(()), false, false)
}

fn run_application(
    args: Vec<String>,
    shutdown: Arc<AtomicBool>,
    on_ready: impl FnOnce() -> Result<(), Box<dyn Error>>,
    windows_service_mode: bool,
    macos_launchd_mode: bool,
) -> Result<(), Box<dyn Error>> {
    let migrate = args.len() == 5 && args[3] == "--migrate-encrypted-to";
    let rotate = args.len() == 5 && args[3] == "--rotate-encryption-to";
    let restore = args.len() == 5 && args[3] == "--restore-quarantined";
    let list_quarantine = args.len() == 4 && args[3] == "--list-quarantined";
    let storage_status = args.len() == 4 && args[3] == "--storage-status";
    let watchdog_status = args.len() == 4 && args[3] == "--watchdog-status";
    let watchdog_reset = args.len() == 4 && args[3] == "--reset-watchdog-circuit";
    let once = args.len() == 4 && args[3] == "--once";
    let review_candidate = args.len() == 6 && args[3] == "--review-candidate";
    if args.len() < 3 || !(args.len() == 3 || once || list_quarantine || storage_status || watchdog_status || watchdog_reset || review_candidate || restore || migrate || rotate)
        || (windows_service_mode && args.len() != 3)
        || (macos_launchd_mode && args.len() != 3)
    {
        eprintln!("Usage: nexa-collector-daemon <config.json> <queue.sqlite> [--once | --list-quarantined | --storage-status | --watchdog-status | --reset-watchdog-circuit | --restore-quarantined <sample-uuid> | --migrate-encrypted-to <new-queue.sqlite> | --rotate-encryption-to <new-queue.sqlite> | --review-candidate <ipv4> <save|ignore|reset>]");
        std::process::exit(2);
    }
    let cfg = read_config(&args[1])?;
    if windows_service_mode && !cfg.usb_scanners.is_empty() {
        // The installer enables SCM restart-on-failure. This configuration is
        // unsupported in session 0, so stop cleanly instead of restarting in
        // a loop and repeatedly attempting to open the vendor GUI.
        eprintln!("Canon USB meter requires an interactive user session; Windows Service stopped without collecting readings");
        return Ok(());
    }
    // systemd LoadCredential exposes files under CREDENTIALS_DIRECTORY.
    // Never fall back to inherited env variables if that directory is set.
    let has_systemd_credentials = env::var_os("CREDENTIALS_DIRECTORY").is_some();
    let supervised_mode = has_systemd_credentials || windows_service_mode || macos_launchd_mode;
    let recovery_guard = recovery_guard::RecoveryGuard::for_queue(Path::new(&args[2]));
    if watchdog_status {
        let (recent, open, history) = recovery_guard.status(Utc::now())?;
        println!(
            "Watchdog: recent_incidents={} circuit_open={} retained_incidents={} threshold=3 window_minutes=30",
            recent, open, history
        );
        return Ok(());
    }
    if watchdog_reset {
        // Explicit human acknowledgement: inspect logs, SQLite health, and
        // ensure the service is stopped before issuing this operator command.
        recovery_guard.reset(Utc::now())?;
        println!("Watchdog incident counter acknowledged; prior history retained; manually restart service after reviewing cause");
        return Ok(());
    }
    if supervised_mode && args.len() == 3 {
        match recovery_guard.circuit_open(Utc::now()) {
            Ok(true) => {
                // A normal exit prevents launchd KeepAlive, systemd on-failure
                // and SCM failure actions from creating an endless crash loop.
                eprintln!("Collector watchdog circuit open: repeated worker stalls in 30 minutes; service stopped until operator review; outbox preserved");
                return Ok(());
            }
            Err(error) => {
                eprintln!("Collector watchdog journal cannot be trusted ({error}); service stopped for operator review; outbox preserved");
                return Ok(());
            }
            Ok(false) => {}
        }
    }
    if rotate {
        // Rotation MUST never modify its source, discover the new key from an
        // isolated environment variable, or trigger any upload/heartbeat.
        let old_key = load_secret("NEXA_COLLECTOR_DB_KEY_HEX", windows_service_mode, macos_launchd_mode)?
            .ok_or("Old encryption key is required for offline rotation")?;
        let new_key = load_secret("NEXA_COLLECTOR_NEW_DB_KEY_HEX", windows_service_mode, macos_launchd_mode)?
            .ok_or("New encryption key is required for offline rotation")?;
        let report = Outbox::rotate_encryption_key_offline(
            &args[2], &args[4], cfg.max_pending_samples,
            cfg.max_pending_payload_bytes, cfg.max_sqlite_files_bytes,
            &old_key, &new_key,
        )?;
        println!(
            "Encrypted key rotation verified: pending={} quarantined={} metadata_records={}. Source retained. Update key and database path together before restarting.",
            report.pending, report.quarantined, report.state_entries,
        );
        return Ok(());
    }
    if migrate {
        // Never open or rewrite the source with the encrypted store routine.
        // Stop the daemon first, use a NEW destination, and switch paths
        // manually after inspecting the report. No network calls occur here.
        let key = load_secret("NEXA_COLLECTOR_DB_KEY_HEX", windows_service_mode, macos_launchd_mode)?
            .ok_or("NEXA_COLLECTOR_DB_KEY_HEX is required for offline migration")?;
        let report = Outbox::migrate_plaintext_to_encrypted(
            &args[2], &args[4], cfg.max_pending_samples,
            cfg.max_pending_payload_bytes, cfg.max_sqlite_files_bytes, &key,
        )?;
        println!(
            "Encrypted migration verified: pending={} quarantined={} metadata_records={}. Source remains intact. Change DB path and verify the new database before restarting the service.",
            report.pending, report.quarantined, report.state_entries,
        );
        return Ok(());
    }
    // In service mode, missing DB key must never silently create plaintext
    // storage. Legacy manually launched collectors keep opt-in compatibility.
    let db_key = load_secret("NEXA_COLLECTOR_DB_KEY_HEX", windows_service_mode, macos_launchd_mode)?;
    if supervised_mode && db_key.is_none() {
        return Err("supervised collector requires a database encryption credential".into());
    }
    let mut outbox = match db_key.as_deref() {
        Some(key) => Outbox::open_encrypted_disk_guard(
            &args[2], cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes, key,
        )?,
        None => Outbox::open_with_disk_guard(
            &args[2], cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes,
        )?,
    };
    // Physical disk diagnostics must not trigger SNMP, heartbeat, or uploads.
    if storage_status {
        let footprint = outbox.sqlite_footprint()?;
        println!(
            "DB={} WAL={} SHM={} total={} soft_limit={} threshold_reached={}",
            footprint.database_bytes, footprint.wal_bytes, footprint.shm_bytes,
            footprint.total_bytes, footprint.soft_limit_bytes, footprint.soft_limit_reached
        );
        println!(
            "pending={} quarantined={} retained_payload_bytes={}",
            outbox.pending()?, outbox.quarantined_count()?, outbox.retained_payload_bytes()?
        );
        return Ok(());
    }
    // Administrative maintenance only: protected database file permissions
    // are the authorization boundary. No SNMP, HTTP or service lifecycle
    // action happens here. The candidate is never registered for monitoring.
    if review_candidate {
        let ip = args[4].parse::<std::net::Ipv4Addr>()?;
        let decision = args[5].parse::<nexa_collector_core::candidate_review::ReviewAction>()?;
        match outbox.review_candidate(ip, decision)? {
            Some(review) => println!(
                "Candidate {} noted as {:?}; printer ownership and meter verification are still required",
                review.candidate.ip, review.decision
            ),
            None => println!("Candidate {} review cleared; no monitored printer changed", ip),
        }
        return Ok(());
    }
    // Operator-only commands do not start SNMP polling or heartbeat workers.
    if list_quarantine {
        for item in outbox.quarantined_readings(100)? {
            println!(
                "UUID={} printer={} collected={} isolated={} reason={}",
                item.sample_id, item.printer_id, item.collected_at,
                item.quarantined_at, item.reason,
            );
        }
        println!("Quarantined: {}", outbox.quarantined_count()?);
        return Ok(());
    }
    if restore {
        let id = uuid::Uuid::parse_str(&args[4])?;
        if !outbox.restore_quarantined(id)? {
            return Err("Sample not found in local quarantine".into());
        }
        println!("Sample restored into pending outbox for explicit retry");
        return Ok(());
    }

    // Separate supervised communication loop: long SNMP scans, empty meter
    // batches and backlogged uploads must not delay agent health checks.
    let token = load_secret("NEXA_COLLECTOR_TOKEN", windows_service_mode, macos_launchd_mode)?;
    let snmp_community = if cfg.printers.is_empty() {
        None
    } else {
        load_secret("NEXA_SNMP_COMMUNITY", windows_service_mode, macos_launchd_mode)?
    };
    if supervised_mode && (token.is_none() || (!cfg.printers.is_empty() && snmp_community.is_none())) {
        return Err("supervised collector requires token and configured SNMP credentials".into());
    }
    // Separate progress checkpoints: the heartbeat worker can no longer be
    // masked by a responsive main loop (and vice versa). Manual CLI modes do
    // not start liveness supervision.
    let progress = if supervised_mode && !once {
        Some(Arc::new(progress_watchdog::ProgressWatchdog::new(Arc::clone(&shutdown))))
    } else {
        None
    };
    if once {
        if let Some(ref secret) = token {
            if send_heartbeat(&cfg, secret).is_err() {
                eprintln!("Heartbeat unavailable; no device state inferred");
            }
        }
    } else {
        let heartbeat_cfg = cfg.clone();
        let heartbeat_token = token.clone();
        let heartbeat_shutdown = Arc::clone(&shutdown);
        let heartbeat_progress = progress.clone();
        let heartbeat_guard = recovery_guard.clone();
        thread::spawn(move || {
            loop {
                if heartbeat_shutdown.load(Ordering::Relaxed) { break; }
                if let Some(ref secret) = heartbeat_token {
                    let diagnostics = match heartbeat_guard.snapshot(Utc::now()) {
                        Ok(health) => Some(WatchdogHeartbeat {
                            recent_incidents: health.recent_incidents,
                            circuit_open: health.circuit_open,
                        }),
                        Err(error) => {
                            eprintln!("Watchdog diagnostics unavailable ({error}); heartbeat sends no watchdog status");
                            None
                        }
                    };
                    if send_heartbeat_with_watchdog(&heartbeat_cfg, secret, diagnostics).is_err() {
                        eprintln!("Heartbeat unavailable; collector identity or network must be checked");
                    }
                } else {
                    eprintln!("Collector token absent; heartbeat skipped");
                }
                // A network error is not a dead worker. The HTTP attempt
                // finished; only the heartbeat worker refreshes this signal.
                if let Some(ref monitor) = heartbeat_progress {
                    monitor.heartbeat_progressed();
                }
                let delay = heartbeat_sleep_seconds(
                    heartbeat_cfg.heartbeat_interval_seconds, heartbeat_cfg.collector_id,
                );
                for _ in 0..delay {
                    if heartbeat_shutdown.load(Ordering::Relaxed) { return; }
                    thread::sleep(Duration::from_secs(1));
                    // Each completed interruptible sleep proves worker
                    // liveness during long configured heartbeat intervals.
                    if let Some(ref monitor) = heartbeat_progress {
                        monitor.heartbeat_progressed();
                    }
                }
            }
        });
    }

    let mut upload_retry = UploadRetryPolicy::default();
    // Persisted wall-clock deadline survives restarts; the live timer remains monotonic.
    let stored_retry = outbox.delivery_diagnostics()?;
    let retry_seconds = stored_retry.seconds_until_retry(Utc::now())?;
    upload_retry.restore(Instant::now(), retry_seconds, stored_retry.consecutive_failures);
    let mut next_poll = Instant::now();
    // The scheduler never registers printers or generates readings. Its
    // non-sensitive candidate report is stored in the existing outbox state.
    // A worker thread keeps slow probes independent from uploads/heartbeats.
    // A corrupted optional discovery report must not block legacy installations
    // whose scheduling feature is disabled.
    let mut active_schedule = match outbox.discovery_schedule_override(&cfg.authorized_subnets) {
        Ok(Some(schedule)) => schedule,
        Ok(None) => cfg.discovery_schedule.clone(),
        Err(_) => {
            eprintln!("Stored discovery policy failed validation; scheduling disabled without interrupting meter uploads");
            DiscoverySchedule::default()
        }
    };
    let previous_discovery = if active_schedule.enabled {
        outbox.discovery_report()?
    } else {
        DiscoveryReport::default()
    };
    let interval = ChronoDuration::hours(active_schedule.interval_hours as i64);
    let now = Utc::now();
    let mut next_discovery = previous_discovery.last_completed_at
        .filter(|last| *last <= now)
        .map(|last| last + interval)
        .unwrap_or(now);
    let mut discovery_receiver: Option<mpsc::Receiver<std::io::Result<Vec<nexa_collector_core::discovery::DiscoveryCandidate>>>> = None;
    on_ready()?;
    // The OS protects this control channel, not a bearer token exposed to JS.
    // No channel is started during one-shot maintenance or unsupervised runs.
    #[cfg(unix)]
    let _control_thread = if supervised_mode && !once {
        match local_control::start(
            Path::new(&args[2]), cfg.clone(),
            db_key.clone().ok_or("encrypted service storage required for local control")?,
            Arc::clone(&shutdown),
        ) {
            Ok(handle) => Some(handle),
            Err(error) => {
                eprintln!("Administrative local control disabled: {error}");
                None
            }
        }
    } else {
        None
    };
    #[cfg(windows)]
    let _windows_control = if windows_service_mode && !once
        && env::var("NEXA_EXPERIMENTAL_WINDOWS_CONTROL").as_deref() == Ok("1")
    {
        match windows_local_control::start(
            Path::new(&args[2]).to_path_buf(), cfg.clone(),
            db_key.clone().ok_or("Windows protected database key required")?,
            Arc::clone(&shutdown),
        ) {
            Ok(handle) => Some(handle),
            Err(error) => {
                eprintln!("Windows experimental named pipe disabled: {error}");
                None
            }
        }
    } else {
        None
    };
    // Begin supervising both workers only after startup has completed.
    // The watcher cannot renew either worker's checkpoint itself.
    if let Some(ref monitor) = progress {
        progress_watchdog::start_watchdog(Arc::clone(monitor), recovery_guard.clone());
    }

    loop {
        if shutdown.load(Ordering::Relaxed) { break; }
        if !once {
            // The privileged IPC server writes only a bounded, verified
            // schedule override. Read it every loop (at most every ~5 s)
            // without restarting or delaying the meter/heartbeat workers.
            let latest = match outbox.discovery_schedule_override(&cfg.authorized_subnets) {
                Ok(Some(schedule)) => schedule,
                Ok(None) => cfg.discovery_schedule.clone(),
                Err(_) => DiscoverySchedule::default(),
            };
            if latest != active_schedule {
                active_schedule = latest;
                // Settings changed: do not attach the results of a prior scan
                // to a newly authorized schedule.
                discovery_receiver = None;
                next_discovery = Utc::now();
            }
        }
        if active_schedule.enabled && !once {
            if let Some(receiver) = discovery_receiver.as_ref() {
                match receiver.try_recv() {
                    Ok(result) => {
                        let scan_at = Utc::now();
                        let (candidates, count, kind) = match result {
                            Ok(found) => {
                                let count = found.len();
                                (found.into_iter().take(200).collect(), count, None)
                            }
                            Err(_) => (Vec::new(), 0, Some("discovery-io".to_owned())),
                        };
                        outbox.save_discovery_report(&DiscoveryReport {
                            last_completed_at: Some(scan_at),
                            last_error_kind: kind,
                            observed_candidates: count,
                            candidates,
                        })?;
                        next_discovery = scan_at + ChronoDuration::hours(active_schedule.interval_hours as i64);
                        discovery_receiver = None;
                    }
                    Err(mpsc::TryRecvError::Empty) => {}
                    Err(mpsc::TryRecvError::Disconnected) => {
                        let scan_at = Utc::now();
                        outbox.save_discovery_report(&DiscoveryReport {
                            last_completed_at: Some(scan_at),
                            last_error_kind: Some("discovery-worker-failed".into()),
                            ..DiscoveryReport::default()
                        })?;
                        next_discovery = scan_at + ChronoDuration::hours(active_schedule.interval_hours as i64);
                        discovery_receiver = None;
                    }
                }
            }
            if discovery_receiver.is_none() && Utc::now() >= next_discovery {
                let (sender, receiver) = mpsc::channel();
                let authorized = cfg.authorized_subnets.clone();
                let requested = active_schedule.cidrs.clone();
                thread::spawn(move || {
                    let scanned = nexa_collector_core::discovery::discover_authorized_ranges(
                        &authorized, &requested,
                    );
                    let _ = sender.send(scanned);
                });
                discovery_receiver = Some(receiver);
            }
        }
        if Instant::now() >= next_poll {
            let disk_limited = outbox.disk_soft_limit_reached()?;
            if disk_limited {
                eprintln!("SQLite physical file footprint reached configured soft guard; SNMP polling deferred; ACKs remain enabled");
            } else if outbox.at_capacity()? {
                eprintln!("Outbox count/payload budget saturated; new SNMP samples deferred");
            } else if let Some(ref community) = snmp_community {
                for printer in &cfg.printers {
                    if shutdown.load(Ordering::Relaxed) { break; }
                    if outbox.at_capacity()? {
                        eprintln!("Outbox full; remaining printers deferred");
                        break;
                    }
                    match collect_snmp_v2c(printer, community.as_bytes()) {
                        Ok(reading) => {
                            match outbox.enqueue(&reading) {
                                Ok(_) => {}
                                Err(CollectorError::OutboxFull { .. }
                                    | CollectorError::OutboxBytesFull { .. }
                                    | CollectorError::SqliteSoftLimit { .. }) => {
                                    eprintln!("Outbox at configured retention capacity; new reading not persisted; further SNMP polling deferred");
                                    break;
                                }
                                Err(error) => return Err(Box::new(error)),
                            }
                        }
                        Err(_) => eprintln!("SNMP reading unavailable for printer {}", printer.printer_id),
                    }
                    // One unsuccessful device scan is still bounded work.
                    // Without this tick a large fleet of slow/offline printers
                    // could be mistaken for a deadlock.
                    if let Some(ref watcher) = progress {
                        watcher.progressed();
                    }
                }
            } else {
                eprintln!("SNMP credential not configured; polling skipped");
            }
            if !disk_limited && !cfg.usb_scanners.is_empty() {
                for scanner in &cfg.usb_scanners {
                    if shutdown.load(Ordering::Relaxed) || outbox.at_capacity()? { break; }
                    let present = nexa_collector_core::usb_devices::discover_usb_devices()
                        .map(|devices| devices.into_iter().any(|device| {
                            device.instance_id.eq_ignore_ascii_case(&scanner.instance_id)
                                && device.friendly_name.eq_ignore_ascii_case("CANON DR-C225 USB")
                                && device.status.eq_ignore_ascii_case("OK")
                                && device.vid_pid.as_deref() == Some("1083:1658")
                        }))
                        .unwrap_or(false);
                    if !present {
                        eprintln!("Configured Canon USB scanner {} is absent; no reading enqueued", scanner.printer_id);
                    } else {
                        match nexa_collector_core::canon_drc225::read() {
                            Ok(meter) => {
                                let reading = meter.into_reading(scanner.printer_id);
                                match outbox.enqueue(&reading) {
                                    Ok(_) => {},
                                    Err(CollectorError::OutboxFull { .. }
                                        | CollectorError::OutboxBytesFull { .. }
                                        | CollectorError::SqliteSoftLimit { .. }) => {
                                        eprintln!("Outbox capacity reached; Canon USB reading not persisted");
                                        break;
                                    }
                                    Err(error) => return Err(Box::new(error)),
                                }
                            }
                            Err(_) => eprintln!("Canon USB meter unavailable for equipment {}; no reading enqueued", scanner.printer_id),
                        }
                    }
                    if let Some(ref watcher) = progress { watcher.progressed(); }
                }
            }
            // Avoid even nonessential state writes while physical storage is
            // above the safety threshold. Upload ACKs can still be processed.
            if !disk_limited {
                outbox.mark_run()?;
            }
            next_poll = Instant::now() + Duration::from_secs(cfg.interval_seconds);
            if let Some(ref watcher) = progress {
                watcher.progressed();
            }
        }

        if let Some(ref secret) = token {
            attempt_upload(&cfg, secret, &mut outbox, &mut upload_retry)?;
        } else if once {
            eprintln!("Collector token not configured; queued samples retained");
        }
        if let Some(ref watcher) = progress {
            watcher.progressed();
        }

        if once {
            println!("Outbox pending: {}, quarantined: {}, retained payload bytes: {}, SQLite file bytes: {}", outbox.pending()?, outbox.quarantined_count()?, outbox.retained_payload_bytes()?, outbox.sqlite_footprint()?.total_bytes);
            break;
        }
        // Upload retry scheduling runs independently of the (possibly 24-hour)
        // SNMP polling interval, but never performs network retries in a tight loop.
        for _ in 0..5 {
            if shutdown.load(Ordering::Relaxed) { break; }
            thread::sleep(Duration::from_secs(1));
        }
        if let Some(ref watcher) = progress {
            watcher.progressed();
        }
    }
    // The watchdog uses the same shutdown token to avoid treating a normal
    // service stop as a stall after the daemon has returned.
    shutdown.store(true, Ordering::Release);
    Ok(())
}

fn attempt_upload(
    cfg: &CollectorConfig,
    token: &str,
    outbox: &mut Outbox,
    policy: &mut UploadRetryPolicy,
) -> Result<(), CollectorError> {
    if !policy.ready(Instant::now()) {
        return Ok(());
    }
    let quarantined_before = outbox.quarantined_count()?;
    match upload_pending(cfg, token, outbox) {
        Ok(acked) => {
            let quarantined_after = outbox.quarantined_count()?;
            if quarantined_after > quarantined_before {
                eprintln!(
                    "One reading isolated after individual HTTP 422; review local quarantine before retrying"
                );
            }
            // Successful ACK or empty queue clears prior error diagnostics.
            // A no-op does not fabricate an upload success timestamp.
            let pending = outbox.pending()?;
            if acked > 0 || pending == 0 {
                let mut diagnostics = outbox.delivery_diagnostics()?;
                if acked > 0 {
                    diagnostics.last_success_at = Some(Utc::now().to_rfc3339());
                }
                diagnostics.last_error_kind = None;
                diagnostics.next_attempt_at = None;
                diagnostics.consecutive_failures = 0;
                outbox.save_delivery_diagnostics(&diagnostics)?;
            }
            policy.on_success(Instant::now());
            if acked > 0 {
                println!("Confirmed {acked} queued samples; {pending} remaining");
            }
        }
        Err(error) => {
            // A local SQLite or persisted payload failure needs explicit service
            // supervision, not endless network retries masking corrupted storage.
            if matches!(
                &error,
                CollectorError::Storage(_) | CollectorError::Io(_) | CollectorError::Json(_)
                | CollectorError::Crypto | CollectorError::EncryptionRequired
                | CollectorError::EncryptionMigrationRequired
            ) {
                return Err(error);
            }
            let delay = policy.on_failure(Instant::now(), cfg.collector_id, &error);
            let mut diagnostics = outbox.delivery_diagnostics()?;
            let now = Utc::now();
            diagnostics.last_error_kind = Some(upload_error_kind(&error).to_owned());
            diagnostics.last_failure_at = Some(now.to_rfc3339());
            diagnostics.next_attempt_at = Some(
                (now + ChronoDuration::seconds(delay as i64)).to_rfc3339()
            );
            diagnostics.consecutive_failures = policy.failures();
            outbox.save_delivery_diagnostics(&diagnostics)?;
            eprintln!(
                "Upload deferred: category={}, next attempt in approximately {} seconds; outbox preserved",
                upload_error_kind(&error),
                delay
            );
        }
    }
    Ok(())
}

const MAX_CREDENTIAL_BYTES: u64 = 4096;

/// Read service credentials without exposing them in command line arguments,
/// environment-variable unit definitions or diagnostic messages.
fn read_secret_file(path: &Path) -> Result<Zeroizing<String>, Box<dyn Error>> {
    if fs::metadata(path)?.len() > MAX_CREDENTIAL_BYTES {
        return Err("credential exceeds maximum accepted size".into());
    }
    // Own the original allocation so it is zeroized on every return path.
    // Do not create a second unprotected String containing a secret.
    let mut secret = Zeroizing::new(
        String::from_utf8(fs::read(path)?).map_err(|_| "credential is not UTF-8")?
    );
    let trimmed_len = secret.trim_end_matches(&['\r', '\n'][..]).len();
    let text = &secret[..trimmed_len];
    if text.is_empty() || text.starts_with('\u{feff}')
        || text.contains('\r') || text.contains('\n') || text.contains('\0')
    {
        return Err("credential is empty or contains an invalid separator".into());
    }
    secret.truncate(trimmed_len);
    Ok(secret)
}

/// In systemd mode missing credential files are fatal, NEVER silently
/// replaced with environment variables or a plaintext DB configuration.
/// Outside systemd, preserve explicit development CLI environment support.
fn load_secret(
    name: &str,
    windows_service_mode: bool,
    macos_launchd_mode: bool,
) -> Result<Option<Zeroizing<String>>, Box<dyn Error>> {
    if macos_launchd_mode {
        #[cfg(target_os = "macos")]
        {
            return read_secret_file(&Path::new("/Library/Application Support/NexaCollector")
                .join("credentials").join(name)).map(Some);
        }
        #[cfg(not(target_os = "macos"))]
        return Err("macOS launchd credentials are unavailable on this platform".into());
    }
    if windows_service_mode {
        #[cfg(windows)]
        {
            // Only pre-provisioned LocalService-readable files under ProgramData.
            // The installer restricts ACLs and never puts secrets in service args.
            let base = env::var_os("ProgramData").ok_or("ProgramData environment is missing")?;
            return read_secret_file(
                &Path::new(&base).join("NexaCollector").join("credentials").join(name)
            ).map(Some);
        }
        #[cfg(not(windows))]
        return Err("Windows service credentials are unavailable on this platform".into());
    }
    if let Some(directory) = env::var_os("CREDENTIALS_DIRECTORY") {
        let file = Path::new(&directory).join(name);
        return read_secret_file(&file).map(Some);
    }
    match env::var(name) {
        Ok(value) if !value.is_empty() => Ok(Some(Zeroizing::new(value))),
        Ok(_) => Err("empty credential supplied".into()),
        Err(env::VarError::NotPresent) => Ok(None),
        Err(_) => Err("credential environment contains invalid Unicode".into()),
    }
}

#[cfg(test)]
mod credential_tests {
    use super::*;

    #[test]
    fn loads_systemd_credential_text_without_trailing_newline() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("token");
        fs::write(&path, b"abcdef1234\r\n").unwrap();
        assert_eq!(read_secret_file(&path).unwrap().as_str(), "abcdef1234");
    }

    #[test]
    fn refuses_oversized_multiline_or_empty_credentials() {
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("secret");
        for value in [b"first\nsecond".as_slice(), b"\n", b"abc\0def"] {
            fs::write(&path, value).unwrap();
            assert!(read_secret_file(&path).is_err());
        }
        fs::write(&path, vec![b'x'; (MAX_CREDENTIAL_BYTES + 1) as usize]).unwrap();
        assert!(read_secret_file(&path).is_err());
    }

    #[test]
    fn missing_credential_file_fails_closed() {
        let directory = tempfile::tempdir().unwrap();
        assert!(read_secret_file(&directory.path().join("absent")).is_err());
    }
}
