use nexa_collector_core::{
    collect_snmp_v2c, heartbeat_sleep_seconds, read_config, send_heartbeat, upload_pending,
    upload_error_kind, CollectorConfig, CollectorError, Outbox, UploadRetryPolicy,
};
use chrono::{Duration as ChronoDuration, Utc};
use std::{env, error::Error, thread, time::{Duration, Instant}};

fn main() -> Result<(), Box<dyn Error>> {
    let args: Vec<String> = env::args().collect();
    let migrate = args.len() == 5 && args[3] == "--migrate-encrypted-to";
    let rotate = args.len() == 5 && args[3] == "--rotate-encryption-to";
    let restore = args.len() == 5 && args[3] == "--restore-quarantined";
    let list_quarantine = args.len() == 4 && args[3] == "--list-quarantined";
    let storage_status = args.len() == 4 && args[3] == "--storage-status";
    let once = args.len() == 4 && args[3] == "--once";
    if args.len() < 3 || !(args.len() == 3 || once || list_quarantine || storage_status || restore || migrate || rotate) {
        eprintln!("Usage: nexa-collector-daemon <config.json> <queue.sqlite> [--once | --list-quarantined | --storage-status | --restore-quarantined <sample-uuid> | --migrate-encrypted-to <new-queue.sqlite> | --rotate-encryption-to <new-queue.sqlite>]");
        std::process::exit(2);
    }
    let cfg = read_config(&args[1])?;
    if rotate {
        // Rotation MUST never modify its source, discover the new key from an
        // isolated environment variable, or trigger any upload/heartbeat.
        let old_key = env::var("NEXA_COLLECTOR_DB_KEY_HEX")
            .map_err(|_| "Old encryption key is required for offline rotation")?;
        let new_key = env::var("NEXA_COLLECTOR_NEW_DB_KEY_HEX")
            .map_err(|_| "New encryption key is required for offline rotation")?;
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
        let key = env::var("NEXA_COLLECTOR_DB_KEY_HEX")
            .map_err(|_| "NEXA_COLLECTOR_DB_KEY_HEX is required for offline migration")?;
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
    // A configured key is mandatory to reopen an encrypted database. Legacy
    // plaintext databases are never migrated in-place.
    let mut outbox = match env::var("NEXA_COLLECTOR_DB_KEY_HEX") {
        Ok(key) => Outbox::open_encrypted_disk_guard(
            &args[2], cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes, &key,
        )?,
        Err(env::VarError::NotPresent) => Outbox::open_with_disk_guard(
            &args[2], cfg.max_pending_samples, cfg.max_pending_payload_bytes,
            cfg.max_sqlite_files_bytes,
        )?,
        Err(_) => return Err("NEXA_COLLECTOR_DB_KEY_HEX contains invalid environment data".into()),
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
    let token = env::var("NEXA_COLLECTOR_TOKEN").ok();
    if once {
        if let Some(ref secret) = token {
            if send_heartbeat(&cfg, secret).is_err() {
                eprintln!("Heartbeat unavailable; no device state inferred");
            }
        }
    } else {
        let heartbeat_cfg = cfg.clone();
        let heartbeat_token = token.clone();
        thread::spawn(move || {
            loop {
                if let Some(ref secret) = heartbeat_token {
                    if send_heartbeat(&heartbeat_cfg, secret).is_err() {
                        eprintln!("Heartbeat unavailable; collector identity or network must be checked");
                    }
                } else {
                    eprintln!("Collector token absent; heartbeat skipped");
                }
                thread::sleep(Duration::from_secs(heartbeat_sleep_seconds(
                    heartbeat_cfg.heartbeat_interval_seconds, heartbeat_cfg.collector_id,
                )));
            }
        });
    }

    let mut upload_retry = UploadRetryPolicy::default();
    // Persisted wall-clock deadline survives restarts; the live timer remains monotonic.
    let stored_retry = outbox.delivery_diagnostics()?;
    let retry_seconds = stored_retry.seconds_until_retry(Utc::now())?;
    upload_retry.restore(Instant::now(), retry_seconds, stored_retry.consecutive_failures);
    let mut next_poll = Instant::now();

    loop {
        if Instant::now() >= next_poll {
            let disk_limited = outbox.disk_soft_limit_reached()?;
            if disk_limited {
                eprintln!("SQLite physical file footprint reached configured soft guard; SNMP polling deferred; ACKs remain enabled");
            } else if outbox.at_capacity()? {
                eprintln!("Outbox count/payload budget saturated; new SNMP samples deferred");
            } else if let Ok(community) = env::var("NEXA_SNMP_COMMUNITY") {
                for printer in &cfg.printers {
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
                }
            } else {
                eprintln!("SNMP credential not configured; polling skipped");
            }
            // Avoid even nonessential state writes while physical storage is
            // above the safety threshold. Upload ACKs can still be processed.
            if !disk_limited {
                outbox.mark_run()?;
            }
            next_poll = Instant::now() + Duration::from_secs(cfg.interval_seconds);
        }

        if let Some(ref secret) = token {
            attempt_upload(&cfg, secret, &mut outbox, &mut upload_retry)?;
        } else if once {
            eprintln!("Collector token not configured; queued samples retained");
        }

        if once {
            println!("Outbox pending: {}, quarantined: {}, retained payload bytes: {}, SQLite file bytes: {}", outbox.pending()?, outbox.quarantined_count()?, outbox.retained_payload_bytes()?, outbox.sqlite_footprint()?.total_bytes);
            break;
        }
        // Upload retry scheduling runs independently of the (possibly 24-hour)
        // SNMP polling interval, but never performs network retries in a tight loop.
        thread::sleep(Duration::from_secs(5));
    }
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
