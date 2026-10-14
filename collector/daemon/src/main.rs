use nexa_collector_core::{
    collect_snmp_v2c, heartbeat_sleep_seconds, read_config, send_heartbeat, upload_pending,
    upload_error_kind, CollectorConfig, CollectorError, Outbox, UploadRetryPolicy,
};
use chrono::{Duration as ChronoDuration, Utc};
use std::{env, error::Error, thread, time::{Duration, Instant}};

fn main() -> Result<(), Box<dyn Error>> {
    let args: Vec<String> = env::args().collect();
    if args.len() < 3 || args.len() > 4 {
        eprintln!("Usage: nexa-collector-daemon <config.json> <queue.sqlite> [--once]");
        std::process::exit(2);
    }
    let cfg = read_config(&args[1])?;
    let mut outbox = Outbox::open_bounded(&args[2], cfg.max_pending_samples)?;
    let once = args.get(3).is_some_and(|v| v == "--once");

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
            if outbox.pending()? >= cfg.max_pending_samples {
                eprintln!("Outbox saturated; new SNMP samples deferred until confirmed uploads release space");
            } else if let Ok(community) = env::var("NEXA_SNMP_COMMUNITY") {
                for printer in &cfg.printers {
                    if outbox.pending()? >= cfg.max_pending_samples {
                        eprintln!("Outbox full; remaining printers deferred");
                        break;
                    }
                    match collect_snmp_v2c(printer, community.as_bytes()) {
                        Ok(reading) => {
                            outbox.enqueue(&reading)?;
                        }
                        Err(_) => eprintln!("SNMP reading unavailable for printer {}", printer.printer_id),
                    }
                }
            } else {
                eprintln!("SNMP credential not configured; polling skipped");
            }
            outbox.mark_run()?;
            next_poll = Instant::now() + Duration::from_secs(cfg.interval_seconds);
        }

        if let Some(ref secret) = token {
            attempt_upload(&cfg, secret, &mut outbox, &mut upload_retry)?;
        } else if once {
            eprintln!("Collector token not configured; queued samples retained");
        }

        if once {
            println!("Outbox pending: {}", outbox.pending()?);
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
    match upload_pending(cfg, token, outbox) {
        Ok(acked) => {
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
