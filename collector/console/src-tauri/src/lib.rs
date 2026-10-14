use nexa_collector_core::{read_config, DeliveryDiagnostics, Outbox, QuarantinedReading, SqliteFootprint};
use serde::Serialize;
use std::env;

#[derive(Serialize)]
struct PrinterInfo {
    printer_id: u64,
    ip: String,
    oid: String,
}

#[derive(Serialize)]
struct CollectorSnapshot {
    configured: bool,
    collector_id: Option<String>,
    endpoint: Option<String>,
    interval_seconds: Option<u64>,
    printers: Vec<PrinterInfo>,
    queue_pending: Option<u64>,
    quarantine_count: Option<u64>,
    quarantine_preview: Vec<QuarantinedReading>,
    queue_capacity: Option<u64>,
    payload_bytes_retained: Option<u64>,
    payload_bytes_limit: Option<u64>,
    sqlite_footprint: Option<SqliteFootprint>,
    delivery: Option<DeliveryDiagnostics>,
    last_run: Option<String>,
    error: Option<String>,
}

#[tauri::command]
fn get_snapshot() -> CollectorSnapshot {
    let mut snapshot = CollectorSnapshot {
        configured: false,
        collector_id: None,
        endpoint: None,
        interval_seconds: None,
        printers: Vec::new(),
        queue_pending: None,
        quarantine_count: None,
        quarantine_preview: Vec::new(),
        queue_capacity: None,
        payload_bytes_retained: None,
        payload_bytes_limit: None,
        sqlite_footprint: None,
        delivery: None,
        last_run: None,
        error: None,
    };

    let mut capacity_limits = None;
    match env::var("NEXA_COLLECTOR_CONFIG") {
        Ok(path) => match read_config(path) {
            Ok(config) => {
                snapshot.configured = true;
                snapshot.collector_id = Some(config.collector_id.to_string());
                snapshot.endpoint = Some(config.api_url);
                snapshot.interval_seconds = Some(config.interval_seconds);
                snapshot.queue_capacity = Some(config.max_pending_samples);
                snapshot.payload_bytes_limit = Some(config.max_pending_payload_bytes);
                capacity_limits = Some((config.max_pending_samples, config.max_pending_payload_bytes, config.max_sqlite_files_bytes));
                snapshot.printers = config.printers.into_iter().map(|p| PrinterInfo {
                    printer_id: p.printer_id,
                    ip: p.ip.to_string(),
                    oid: p.marker_counter_oid.iter().map(u64::to_string).collect::<Vec<_>>().join("."),
                }).collect();
            }
            Err(_) => snapshot.error = Some("Configuração não encontrada ou inválida.".into()),
        },
        Err(_) => snapshot.error = Some("NEXA_COLLECTOR_CONFIG não definida.".into()),
    }

    if let Ok(path) = env::var("NEXA_COLLECTOR_DB") {
        // Read-only dashboard; no daemon lifecycle commands or credential exposure.
        if std::path::Path::new(&path).exists() {
            let opened = if let Some((samples, payload_bytes, file_bytes)) = capacity_limits {
                Outbox::open_with_disk_guard(&path, samples, payload_bytes, file_bytes)
            } else {
                Outbox::open(&path)
            };
            match opened {
                Ok(outbox) => {
                    snapshot.queue_pending = outbox.pending().ok();
                    match outbox.sqlite_footprint() {
                        Ok(footprint) => snapshot.sqlite_footprint = Some(footprint),
                        Err(_) => snapshot.error = Some("Não foi possível verificar o espaço físico do SQLite.".into()),
                    }
                    snapshot.payload_bytes_retained = outbox.retained_payload_bytes().ok();
                    snapshot.quarantine_count = outbox.quarantined_count().ok();
                    match outbox.quarantined_readings(25) {
                        Ok(readings) => snapshot.quarantine_preview = readings,
                        Err(_) => snapshot.error = Some("Não foi possível ler os registros isolados.".into()),
                    }
                    match outbox.delivery_diagnostics() {
                        Ok(delivery) => snapshot.delivery = Some(delivery),
                        Err(_) => snapshot.error = Some("Histórico de transmissão local inválido.".into()),
                    }
                    snapshot.last_run = outbox.last_run().ok().flatten();
                }
                Err(_) => snapshot.error = Some("Não foi possível consultar a fila local.".into()),
            }
        }
    }
    snapshot
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![get_snapshot])
        .run(tauri::generate_context!())
        .expect("failed to start Nexa Collector Console");
}
