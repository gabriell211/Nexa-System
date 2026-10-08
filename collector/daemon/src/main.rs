use nexa_collector_core::{collect_snmp_v2c, read_config, upload_pending, Outbox};
use std::{env, error::Error, thread, time::Duration};

fn main() -> Result<(), Box<dyn Error>> {
    let args: Vec<String> = env::args().collect();
    if args.len() < 3 || args.len() > 4 {
        eprintln!("Usage: nexa-collector-daemon <config.json> <queue.sqlite> [--once]");
        std::process::exit(2);
    }
    let cfg = read_config(&args[1])?;
    let mut outbox = Outbox::open(&args[2])?;
    let once = args.get(3).is_some_and(|v| v == "--once");

    loop {
        // Credentials are external to config and never written to the SQLite outbox.
        if let Ok(community) = env::var("NEXA_SNMP_COMMUNITY") {
            for printer in &cfg.printers {
                match collect_snmp_v2c(printer, community.as_bytes()) {
                    Ok(reading) => { outbox.enqueue(&reading)?; }
                    Err(_) => eprintln!("SNMP unavailable for printer ID {}", printer.printer_id),
                }
            }
        } else {
            eprintln!("SNMP credential not configured; polling skipped");
        }

        outbox.mark_run()?;
        if let Ok(token) = env::var("NEXA_COLLECTOR_TOKEN") {
            if let Err(_) = upload_pending(&cfg, &token, &mut outbox) {
                eprintln!("Upload unavailable; queued readings retained");
            }
        }
        println!("Outbox pending: {}", outbox.pending()?);

        if once { break; }
        thread::sleep(Duration::from_secs(cfg.interval_seconds));
    }
    Ok(())
}
