use chrono::Utc;
use nexa_collector_core::{
    canon_drc225::{self, CanonDrc225Meter},
    candidate_review::{CandidateReview, ReviewAction},
    discovery::{self, DiscoveryCandidate},
    usb_devices::{self, UsbDeviceCandidate},
    recovery_guard::{RecoveryGuard, WatchdogSnapshot},
    read_config, DeliveryDiagnostics, DiscoveryReport, DiscoverySchedule, Outbox, QuarantinedReading, SqliteFootprint,
};
use serde::Serialize;
use std::env;

#[derive(Serialize)]
struct CanonMeterView {
    meter_scan_total: String,
    roller_count_resettable: String,
    collected_at: String,
}

#[derive(Serialize)]
struct PrinterInfo {
    printer_id: u64,
    ip: String,
    oid: String,
}

#[derive(Serialize)]
struct UsbScannerInfo {
    printer_id: u64,
    profile: String,
}

#[derive(Serialize)]
struct CollectorSnapshot {
    configured: bool,
    collector_id: Option<String>,
    endpoint: Option<String>,
    interval_seconds: Option<u64>,
    authorized_subnets: Vec<String>,
    discovery_schedule: Option<DiscoverySchedule>,
    discovery_report: Option<DiscoveryReport>,
    candidate_reviews: Vec<CandidateReview>,
    candidate_reviews_error: Option<String>,
    local_control_supported: bool,
    schedule_control_supported: bool,
    printers: Vec<PrinterInfo>,
    usb_scanners: Vec<UsbScannerInfo>,
    queue_pending: Option<u64>,
    quarantine_count: Option<u64>,
    quarantine_preview: Vec<QuarantinedReading>,
    queue_capacity: Option<u64>,
    payload_bytes_retained: Option<u64>,
    payload_encryption_enabled: Option<bool>,
    payload_bytes_limit: Option<u64>,
    sqlite_footprint: Option<SqliteFootprint>,
    delivery: Option<DeliveryDiagnostics>,
    last_run: Option<String>,
    watchdog: Option<WatchdogSnapshot>,
    watchdog_error: Option<String>,
    error: Option<String>,
}

#[tauri::command]
fn get_snapshot() -> CollectorSnapshot {
    let mut snapshot = CollectorSnapshot {
        configured: false,
        collector_id: None,
        endpoint: None,
        interval_seconds: None,
        authorized_subnets: Vec::new(),
        discovery_schedule: None,
        discovery_report: None,
        candidate_reviews: Vec::new(),
        candidate_reviews_error: None,
        local_control_supported: cfg!(unix)
            || (cfg!(windows) && env::var("NEXA_EXPERIMENTAL_WINDOWS_CONTROL").as_deref() == Ok("1")),
        schedule_control_supported: cfg!(unix),
        printers: Vec::new(),
        usb_scanners: Vec::new(),
        queue_pending: None,
        quarantine_count: None,
        quarantine_preview: Vec::new(),
        queue_capacity: None,
        payload_bytes_retained: None,
        payload_encryption_enabled: None,
        payload_bytes_limit: None,
        sqlite_footprint: None,
        delivery: None,
        last_run: None,
        watchdog: None,
        watchdog_error: None,
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
                snapshot.authorized_subnets = config.authorized_subnets.clone();
                snapshot.discovery_schedule = Some(config.discovery_schedule.clone());
                snapshot.queue_capacity = Some(config.max_pending_samples);
                snapshot.payload_bytes_limit = Some(config.max_pending_payload_bytes);
                capacity_limits = Some((config.max_pending_samples, config.max_pending_payload_bytes, config.max_sqlite_files_bytes));
                snapshot.printers = config.printers.into_iter().map(|p| PrinterInfo {
                    printer_id: p.printer_id,
                    ip: p.ip.to_string(),
                    oid: p.marker_counter_oid.iter().map(u64::to_string).collect::<Vec<_>>().join("."),
                }).collect();
                snapshot.usb_scanners = config.usb_scanners.into_iter().map(|s| UsbScannerInfo {
                    printer_id: s.printer_id,
                    profile: s.profile,
                }).collect();
            }
            Err(_) => snapshot.error = Some("Configuração não encontrada ou inválida.".into()),
        },
        Err(_) => snapshot.error = Some("NEXA_COLLECTOR_CONFIG não definida.".into()),
    }

    if let Ok(path) = env::var("NEXA_COLLECTOR_DB") {
        // Use the exact daemon parser. Reading the incident journal never
        // opens SQLite, resets the circuit, or invokes any privileged command.
        match RecoveryGuard::for_queue(std::path::Path::new(&path)).snapshot(Utc::now()) {
            Ok(diagnostics) => snapshot.watchdog = Some(diagnostics),
            Err(_) => snapshot.watchdog_error = Some(
                "Diário do watchdog inacessível ou inválido; verifique permissões e integridade.".into()
            ),
        }
        // Read-only dashboard; no daemon lifecycle commands or credential exposure.
        if std::path::Path::new(&path).exists() {
            let (samples, payload_bytes, file_bytes) = capacity_limits.unwrap_or((
                50_000, 64 * 1024 * 1024, 512 * 1024 * 1024,
            ));
            let opened = match env::var("NEXA_COLLECTOR_DB_KEY_HEX") {
                Ok(key) => Outbox::open_encrypted_read_only_disk_guard(
                    &path, samples, payload_bytes, file_bytes, &key,
                ),
                Err(env::VarError::NotPresent) => Outbox::open_read_only_with_disk_guard(
                    &path, samples, payload_bytes, file_bytes,
                ),
                Err(_) => {
                    snapshot.error = Some("Chave de criptografia local inválida.".into());
                    return snapshot;
                }
            };
            match opened {
                Ok(outbox) => {
                    snapshot.payload_encryption_enabled = Some(outbox.payload_encryption_enabled());
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
                    match outbox.discovery_schedule_override(&snapshot.authorized_subnets) {
                        Ok(Some(updated)) => snapshot.discovery_schedule = Some(updated),
                        Ok(None) => {},
                        Err(_) => snapshot.error = Some(
                            "Agenda local armazenada inválida; o serviço desativa a descoberta.".into()
                        ),
                    }
                    match outbox.candidate_reviews() {
                        Ok(reviews) => snapshot.candidate_reviews = reviews,
                        Err(_) => snapshot.candidate_reviews_error = Some(
                            "Decisões locais de candidatos corrompidas/inacessíveis. Não são consideradas válidas.".into()
                        ),
                    }
                    match outbox.discovery_report() {
                        Ok(report) => snapshot.discovery_report = Some(report),
                        Err(_) => snapshot.error = Some(
                            "Histórico local da varredura está inválido ou inacessível.".into()
                        ),
                    }
                }
                Err(error) => snapshot.error = Some(match error {
                    nexa_collector_core::CollectorError::EncryptionRequired =>
                        "A fila é criptografada e exige NEXA_COLLECTOR_DB_KEY_HEX.".into(),
                    nexa_collector_core::CollectorError::EncryptionMigrationRequired =>
                        "Banco legado com dados: migração cifrada in-place bloqueada para proteger leituras.".into(),
                    nexa_collector_core::CollectorError::Crypto =>
                        "Chave de criptografia incorreta ou dados locais sem integridade.".into(),
                    _ => "Não foi possível consultar a fila local.".into(),
                }),
            }
        }
    }
    snapshot
}

#[tauri::command]
async fn discover_local_printers() -> Result<Vec<DiscoveryCandidate>, String> {
    tauri::async_runtime::spawn_blocking(discovery::discover_local)
        .await.map_err(|_| "A busca local foi interrompida.".to_owned())?
        .map_err(|_| "Não foi possível consultar as interfaces de rede locais.".to_owned())
}

#[tauri::command]
async fn discover_authorized_printers(cidrs: Vec<String>) -> Result<Vec<DiscoveryCandidate>, String> {
    // Never accept a list of networks from the UI as authorization. Load
    // the exact provisioned service config and re-check the entire scope in
    // Rust before sending a single discovery packet.
    tauri::async_runtime::spawn_blocking(move || {
        let path = env::var("NEXA_COLLECTOR_CONFIG")
            .map_err(|_| "Configuração do Collector indisponível.".to_owned())?;
        let config = read_config(path)
            .map_err(|_| "Configuração do Collector inválida ou inacessível.".to_owned())?;
        discovery::discover_authorized_ranges(&config.authorized_subnets, &cidrs)
            .map_err(|e| e.to_string())
    })
    .await.map_err(|_| "A varredura autorizada foi interrompida.".to_owned())?
}

#[tauri::command]
async fn discover_usb_printers() -> Result<Vec<UsbDeviceCandidate>, String> {
    tauri::async_runtime::spawn_blocking(usb_devices::discover_usb_devices)
        .await.map_err(|_| "A consulta USB foi interrompida.".to_owned())?
        .map_err(|_| "Não foi possível consultar as impressoras e dispositivos de imagem USB neste Windows.".to_owned())
}

#[tauri::command]
async fn read_canon_drc225_meter() -> Result<CanonMeterView, String> {
    tauri::async_runtime::spawn_blocking(canon_drc225::read)
        .await.map_err(|_| "A leitura Canon foi interrompida.".to_owned())?
        .map_err(|_| "O contador Canon está indisponível. Confira se há apenas um DR-C225 USB conectado e se o utilitário oficial está instalado.".to_owned())
        .map(|meter: CanonDrc225Meter| CanonMeterView {
            meter_scan_total: meter.meter_scan_total.to_string(),
            roller_count_resettable: meter.roller_count_resettable.to_string(),
            collected_at: meter.collected_at.to_rfc3339(),
        })
}

#[tauri::command]
async fn review_local_candidate(ip: String, action: String) -> Result<(), String> {
    let ip = ip.parse::<std::net::Ipv4Addr>()
        .map_err(|_| "Endereço IPv4 inválido.".to_owned())?;
    let action = action.parse::<ReviewAction>()
        .map_err(|_| "Ação de revisão inválida.".to_owned())?;
    #[cfg(unix)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            let queue = env::var_os("NEXA_COLLECTOR_DB")
                .ok_or_else(|| "NEXA_COLLECTOR_DB não configurado.".to_owned())?;
            nexa_collector_core::local_control::submit(
                std::path::Path::new(&queue), ip, action,
            )
        }).await.map_err(|_| "O canal local foi interrompido.".to_owned())?
    }
    #[cfg(windows)]
    {
        if env::var("NEXA_EXPERIMENTAL_WINDOWS_CONTROL").as_deref() != Ok("1") {
            return Err("Canal administrativo Windows experimental desativado.".into());
        }
        tauri::async_runtime::spawn_blocking(move || {
            nexa_collector_core::local_control::submit_windows(ip, action)
        }).await.map_err(|_| "Canal Windows interrompido.".to_owned())?
    }
    #[cfg(not(any(unix, windows)))]
    {
        let _ = (ip, action);
        Err("Controle local não suportado nesta plataforma.".into())
    }
}

#[tauri::command]
async fn save_discovery_schedule(schedule: DiscoverySchedule) -> Result<(), String> {
    #[cfg(unix)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            let config_path = env::var_os("NEXA_COLLECTOR_CONFIG")
                .ok_or_else(|| "Configuração do serviço não encontrada.".to_owned())?;
            let config = read_config(config_path)
                .map_err(|_| "Configuração autorizada do serviço inválida.".to_owned())?;
            schedule.validate_for(&config.authorized_subnets)
                .map_err(|_| "Intervalo ou faixas fora do escopo autorizado.".to_owned())?;
            let queue = env::var_os("NEXA_COLLECTOR_DB")
                .ok_or_else(|| "Banco local do Collector não configurado.".to_owned())?;
            nexa_collector_core::local_control::submit_schedule(
                std::path::Path::new(&queue), schedule,
            )
        }).await.map_err(|_| "A alteração da agenda foi interrompida.".to_owned())?
    }
    #[cfg(not(unix))]
    {
        let _ = schedule;
        Err("Edição da agenda disponível somente no IPC Unix protegido nesta etapa.".into())
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![get_snapshot, discover_local_printers, discover_authorized_printers, discover_usb_printers, read_canon_drc225_meter, review_local_candidate, save_discovery_schedule])
        .run(tauri::generate_context!())
        .expect("failed to start Nexa Collector Console");
}
