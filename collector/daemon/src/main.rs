use nexa_collector_core::{
    collect_snmp_v2c, heartbeat_sleep_seconds, read_config, send_heartbeat, upload_pending, Outbox,
};
use std::{env, error::Error, thread, time::Duration};

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

    loop {
        // Drain before polling to free capacity after a network outage.
        // A full queue is never truncated or overwritten.
        if let Some(ref secret) = token {
            if upload_pending(&cfg, secret, &mut outbox).is_err() {
                eprintln!("Upload unavailable; queued readings retained");
            }
        }

        let pending = outbox.pending()?;
        if pending >= cfg.max_pending_samples {
            eprintln!("Collector outbox full; polling paused until acknowledged uploads free space");
        } else if let Ok(community) = env::var("NEXA_SNMP_COMMUNITY") {
            for printer in &cfg.printers {
                if outbox.pending()? >= cfg.max_pending_samples {
                    eprintln!("Collector outbox full; remaining printers deferred");
                    break;
                }
                match collect_snmp_v2c(printer, community.as_bytes()) {
                    Ok(reading) => {
                        outbox.enqueue(&reading)?;
                    }
                    Err(_) => eprintln!("SNMP unavailable for printer ID {}", printer.printer_id),
                }
            }
        } else {
            eprintln!("SNMP credential not configured; polling skipped");
        }

        outbox.mark_run()?;
        if let Some(ref secret) = token {
            if upload_pending(&cfg, secret, &mut outbox).is_err() {
                eprintln!("Upload unavailable; queued readings retained");
            }
        }
        println!("Outbox pending: {}", outbox.pending()?);

        if once { break; }
        thread::sleep(Duration::from_secs(cfg.interval_seconds));
    }
    Ok(())
}
