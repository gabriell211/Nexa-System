use chrono::Utc;
use nexa_collector_core::{
    canon_drc225::{self, CanonDrc225Meter},
    candidate_review::{CandidateReview, ReviewAction},
    discovery::{self, DiscoveryCandidate},
    fleet::{FleetCommand, FleetPrinter},
    reference_catalog::{self, ReferenceSearchResult},
    usb_devices::{self, UsbDeviceCandidate},
    recovery_guard::{RecoveryGuard, WatchdogSnapshot},
    read_config, CommunicationMode, DeliveryDiagnostics, DiscoveryReport, DiscoverySchedule, Outbox, QuarantinedReading, SqliteFootprint,
};
use serde::Serialize;
use std::env;

#[cfg(windows)]
fn installed_root() -> Option<std::path::PathBuf> {
    env::var_os("ProgramData").map(|path| std::path::PathBuf::from(path).join("NexaCollector"))
}

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
    monitored: bool,
    last_tested_at: Option<String>,
    manufacturer: Option<String>,
    model: Option<String>,
    serial: Option<String>,
    mac: Option<String>,
    reference_model_id: Option<u64>,
    auto_detect_reference: bool,
    catalog_auto_supported: bool,
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
    communication_mode: Option<String>,
    interval_seconds: Option<u64>,
    authorized_subnets: Vec<String>,
    discovery_schedule: Option<DiscoverySchedule>,
    discovery_report: Option<DiscoveryReport>,
    broadcast_report: Option<DiscoveryReport>,
    candidate_reviews: Vec<CandidateReview>,
    candidate_reviews_error: Option<String>,
    local_control_supported: bool,
    schedule_control_supported: bool,
    sample_configuration: bool,
    windows_service_status: Option<String>,
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

#[cfg(windows)]
fn windows_service_status() -> Option<String> {
    let result = std::process::Command::new("sc.exe")
        .args(["query", "NexaCollector"]).output().ok()?;
    if !result.status.success() { return Some("Não instalado".into()); }
    let output = String::from_utf8_lossy(&result.stdout);
    let state = if output.contains("RUNNING") { "Em execução" }
        else if output.contains("STOPPED") { "Parado" }
        else { "Indisponível ou iniciando" };
    Some(state.into())
}

#[cfg(not(windows))]
fn windows_service_status() -> Option<String> { None }

#[cfg(windows)]
fn provision_network_range_windows(cidrs: &[String]) -> Result<(), String> {
    use std::{fs, process::Command, thread, time::Duration};
    if env::var_os("NEXA_COLLECTOR_CONFIG").is_some()
        || env::var_os("NEXA_COLLECTOR_DB").is_some() {
        return Err("Console em modo personalizado/teste. Configure o serviço instalado em ProgramData, não o arquivo de exemplo.".into());
    }
    let root = installed_root().ok_or("ProgramData indisponível.")?;
    let config_path = root.join("config.json");
    let mut config = read_config(&config_path)
        .map_err(|_| "Configuração do serviço não encontrada em ProgramData. Instale e vincule o agente primeiro.")?;
    if config.collector_id.to_string() == "00000000-0000-4000-8000-000000000000"
        || config.api_url.contains("nexa.example.com") {
        return Err("Configuração de exemplo. Vincule o Collector ao cliente antes de autorizar redes.".into());
    }
    let grants = discovery::validate_network_grants(cidrs).map_err(str::to_owned)?;
    let mut authorized = config.authorized_subnets.clone();
    for grant in grants {
        if !authorized.contains(&grant) { authorized.push(grant); }
    }
    if authorized.len() > 16 {
        return Err("Limite de 16 faixas autorizadas no ponto de coleta.".into());
    }
    config.authorized_subnets = authorized;
    config.validate().map_err(|e| format!("As faixas informadas invalidariam a configuração atual: {e}"))?;
    let service = Command::new("sc.exe").args(["query", "NexaCollector"])
        .output().map_err(|_| "Não foi possível consultar o serviço do Windows.")?;
    if !service.status.success() {
        return Err("Serviço NexaCollector não encontrado. Instale o agente completo.".into());
    }
    let running = String::from_utf8_lossy(&service.stdout).contains("RUNNING");
    // Use a temporary file under the same protected ProgramData directory.
    // Administrators must have write permission. Never copy keys or tokens.
    let previous = fs::read(&config_path).map_err(|_| "Não foi possível ler o cadastro existente.")?;
    let staged = root.join("config.network-staged.json");
    let write_staged = (|| -> Result<(), String> {
        use std::io::Write;
        let mut file = fs::OpenOptions::new().write(true).create_new(true)
            .open(&staged).map_err(|_| "Não foi possível criar configuração protegida; execute como administrador.")?;
        file.write_all(&serde_json::to_vec_pretty(&config)
            .map_err(|_| "Falha ao serializar configuração.")?)
            .map_err(|_| "Não foi possível salvar a configuração protegida.")?;
        file.sync_all().map_err(|_| "Falha ao confirmar gravação da configuração.")?;
        Ok(())
    })();
    if let Err(error) = write_staged {
        let _ = fs::remove_file(&staged);
        return Err(error);
    }
    if running {
        let stopped = Command::new("sc.exe").args(["stop", "NexaCollector"]).status()
            .map_err(|_| "Não foi possível solicitar a parada do serviço.")?;
        if !stopped.success() {
            let _ = fs::remove_file(&staged);
            return Err("Acesso negado ao serviço. Execute o Console como administrador.".into());
        }
        let mut stopped_ok = false;
        for _ in 0..40 {
            thread::sleep(Duration::from_millis(250));
            if let Ok(result) = Command::new("sc.exe").args(["query", "NexaCollector"]).output() {
                if result.status.success()
                    && String::from_utf8_lossy(&result.stdout).contains("STOPPED") {
                    stopped_ok = true;
                    break;
                }
            }
        }
        if !stopped_ok {
            let _ = fs::remove_file(&staged);
            return Err("O serviço não parou para atualizar a configuração. Reinicie-o pela ferramenta Serviços.".into());
        }
    }
    if fs::rename(&staged, &config_path).is_err() {
        let _ = fs::remove_file(&staged);
        if running { let _ = Command::new("sc.exe").args(["start", "NexaCollector"]).status(); }
        return Err("Falha ao substituir a configuração protegida. Dados anteriores preservados.".into());
    }
    if running {
        if Command::new("sc.exe").args(["start", "NexaCollector"]).status()
            .is_ok_and(|status| status.success()) {
            return Ok(());
        }
        // Best-effort rollback of configuration, retaining the old connection.
        let _ = fs::write(&config_path, previous);
        let _ = Command::new("sc.exe").args(["start", "NexaCollector"]).status();
        return Err("Falha ao reiniciar o serviço. Configuração anterior restaurada; verifique o serviço Windows.".into());
    }
    Ok(())
}

#[tauri::command]
async fn authorize_network_ranges(cidrs: Vec<String>) -> Result<(), String> {
    #[cfg(windows)]
    {
        tauri::async_runtime::spawn_blocking(move || provision_network_range_windows(&cidrs))
            .await.map_err(|_| "Atualização das faixas interrompida.".to_owned())?
    }
    #[cfg(not(windows))]
    {
        let _ = cidrs;
        Err("A autorização de faixas pelo Console está disponível somente no Windows; use o administrador do serviço neste sistema.".into())
    }
}

fn catalog_auto_supported(
    printer: &nexa_collector_core::PrinterConfig,
    manufacturer: Option<&str>, model: Option<&str>,
) -> bool {
    if let Some(model_id) = printer.reference_model_id {
        return reference_catalog::unique_network_total_oid(model_id).is_ok();
    }
    if !printer.auto_detect_reference { return false; }
    let observed = match (manufacturer, model) {
        (Some(vendor), Some(name)) => vec![format!("{vendor} {name}")],
        (None, Some(name)) => vec![name.to_owned()],
        _ => return false,
    };
    reference_catalog::network_reference_for_identity(&observed)
        .is_some_and(|reference| {
            reference.network_status == Some(reference_catalog::ReferenceStatus::Approved)
                && reference.total_counter_oid.is_some()
        })
}

#[tauri::command]
fn get_snapshot() -> CollectorSnapshot {
    let mut snapshot = CollectorSnapshot {
        configured: false,
        collector_id: None,
        endpoint: None,
        communication_mode: None,
        interval_seconds: None,
        authorized_subnets: Vec::new(),
        discovery_schedule: None,
        discovery_report: None,
        broadcast_report: None,
        candidate_reviews: Vec::new(),
        candidate_reviews_error: None,
        local_control_supported: cfg!(unix) || cfg!(windows),
        schedule_control_supported: cfg!(unix),
        sample_configuration: false,
        windows_service_status: windows_service_status(),
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
    let mut provisioned_config = None;
    let config_path = env::var("NEXA_COLLECTOR_CONFIG").ok().or_else(|| {
        #[cfg(windows)]
        { installed_root().map(|root| root.join("config.json").to_string_lossy().into_owned()) }
        #[cfg(not(windows))]
        { None }
    });
    match config_path {
        Some(path) => match read_config(path) {
            Ok(config) => {
                snapshot.configured = true;
                snapshot.sample_configuration =
                    config.collector_id.to_string() == "00000000-0000-4000-8000-000000000000"
                    || config.api_url.contains("nexa.example.com");
                // A demo/customized Console must never send administrative
                // mutations to a separately installed production daemon.
                if snapshot.sample_configuration || (cfg!(windows)
                    && snapshot.windows_service_status.as_deref() != Some("Em execução")) {
                    snapshot.local_control_supported = false;
                }
                snapshot.collector_id = Some(config.collector_id.to_string());
                snapshot.communication_mode = Some(match config.communication_mode {
                    CommunicationMode::WebServices => "Internet (Web Services)",
                    CommunicationMode::Email => "E-mail (SMTP + POP3)",
                }.into());
                snapshot.endpoint = Some(match &config.mail {
                    Some(mail) => mail.recipient.clone(),
                    None => config.api_url.clone(),
                });
                snapshot.interval_seconds = Some(config.interval_seconds);
                snapshot.authorized_subnets = config.authorized_subnets.clone();
                snapshot.discovery_schedule = Some(config.discovery_schedule.clone());
                snapshot.queue_capacity = Some(config.max_pending_samples);
                snapshot.payload_bytes_limit = Some(config.max_pending_payload_bytes);
                capacity_limits = Some((config.max_pending_samples, config.max_pending_payload_bytes, config.max_sqlite_files_bytes));
                snapshot.printers = config.printers.iter().map(|p| PrinterInfo {
                    printer_id: p.printer_id,
                    ip: p.ip.to_string(),
                    monitored: true, last_tested_at: None,
                    manufacturer: None, model: None, serial: None, mac: None,
                    oid: if p.auto_detect_reference { "Detectado por SNMP".into() } else {
                        p.effective_counter_oid().unwrap_or_default().iter().map(u64::to_string).collect::<Vec<_>>().join(".")
                    },
                    reference_model_id: p.reference_model_id,
                    auto_detect_reference: p.auto_detect_reference,
                    catalog_auto_supported: catalog_auto_supported(p, None, None),
                }).collect();
                snapshot.usb_scanners = config.usb_scanners.iter().map(|s| UsbScannerInfo {
                    printer_id: s.printer_id,
                    profile: s.profile.clone(),
                }).collect();
                provisioned_config = Some(config);
            }
            Err(_) => snapshot.error = Some("Configuração não encontrada ou inválida.".into()),
        },
        None => snapshot.error = Some("Configuração do Collector não encontrada.".into()),
    }

    let default_db = env::var("NEXA_COLLECTOR_DB").is_err();
    let db_path = env::var("NEXA_COLLECTOR_DB").ok().or_else(|| {
        #[cfg(windows)]
        { installed_root().map(|root| root.join("data").join("queue.sqlite").to_string_lossy().into_owned()) }
        #[cfg(not(windows))]
        { None }
    });
    if let Some(path) = db_path {
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
            let key = env::var("NEXA_COLLECTOR_DB_KEY_HEX").or_else(|error| {
                #[cfg(windows)]
                {
                    if default_db && matches!(error, env::VarError::NotPresent) {
                        if let Some(root) = installed_root() {
                            return std::fs::read_to_string(root.join("credentials").join("NEXA_COLLECTOR_DB_KEY_HEX"))
                                .map_err(|_| env::VarError::NotPresent);
                        }
                    }
                }
                Err(error)
            });
            let opened = match key {
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
                    if let Some(ref configured) = provisioned_config {
                        match outbox.printer_fleet(configured) {
                            Ok(fleet) => {
                                snapshot.printers = fleet.into_iter().map(|entry: FleetPrinter| {
                                    let p = entry.config;
                                    let catalog_approved = catalog_auto_supported(
                                        &p, entry.manufacturer.as_deref(), entry.model.as_deref(),
                                    );
                                    PrinterInfo {
                                        printer_id: p.printer_id,
                                        ip: p.ip.to_string(),
                                        oid: if p.auto_detect_reference { "Detecção SNMP automática".into() }
                                            else { p.effective_counter_oid().unwrap_or_default().iter()
                                                .map(u64::to_string).collect::<Vec<_>>().join(".") },
                                        monitored: entry.monitored,
                                        last_tested_at: entry.last_tested_at,
                                        manufacturer: entry.manufacturer, model: entry.model,
                                        serial: entry.serial, mac: entry.mac,
                                        reference_model_id: p.reference_model_id,
                                        auto_detect_reference: p.auto_detect_reference,
                                        catalog_auto_supported: catalog_approved,
                                    }
                                }).collect();
                            }
                            Err(_) => snapshot.error = Some(
                                "Cadastro local de impressoras inválido; coleta bloqueada até correção.".into()
                            ),
                        }
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
                    match outbox.broadcast_report() {
                        Ok(report) => snapshot.broadcast_report = Some(report),
                        Err(_) => snapshot.error = Some(
                            "Histórico local do broadcast está inválido ou inacessível.".into()
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
fn search_reference_models(query: String, interface: String) -> Result<ReferenceSearchResult, String> {
    reference_catalog::search(&query, &interface, 100).map_err(str::to_owned)
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
        let path = env::var("NEXA_COLLECTOR_CONFIG").ok().or_else(|| {
            #[cfg(windows)]
            { installed_root().map(|root| root.join("config.json").to_string_lossy().into_owned()) }
            #[cfg(not(windows))]
            { None }
        }).ok_or_else(|| "Configuração do Collector indisponível.".to_owned())?;
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

#[tauri::command]
async fn discover_broadcast_printers(cidrs: Vec<String>) -> Result<(), String> {
    #[cfg(unix)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            let queue = env::var_os("NEXA_COLLECTOR_DB")
                .ok_or_else(|| "Banco do serviço não configurado.".to_owned())?;
            nexa_collector_core::local_control::submit_broadcast(
                std::path::Path::new(&queue), cidrs,
            )
        }).await.map_err(|_| "Busca broadcast interrompida.".to_owned())?
    }
    #[cfg(windows)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            nexa_collector_core::local_control::submit_windows_broadcast(cidrs)
        }).await.map_err(|_| "Busca broadcast Windows interrompida.".to_owned())?
    }
    #[cfg(not(any(unix, windows)))]
    {
        let _ = cidrs;
        Err("Busca broadcast indisponível neste sistema.".into())
    }
}

#[tauri::command]
async fn printer_action(command: FleetCommand) -> Result<(), String> {
    #[cfg(unix)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            let path = env::var_os("NEXA_COLLECTOR_DB")
                .ok_or_else(|| "Banco do serviço não configurado.".to_owned())?;
            nexa_collector_core::local_control::submit_fleet(
                std::path::Path::new(&path), command,
            )
        }).await.map_err(|_| "Canal administrativo interrompido.".to_owned())?
    }
    #[cfg(windows)]
    {
        tauri::async_runtime::spawn_blocking(move || {
            nexa_collector_core::local_control::submit_windows_fleet(command)
        }).await.map_err(|_| "Canal administrativo Windows interrompido.".to_owned())?
    }
    #[cfg(not(any(unix, windows)))]
    {
        let _ = command;
        Err("Controle de impressoras não suportado neste sistema.".into())
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .invoke_handler(tauri::generate_handler![get_snapshot, authorize_network_ranges, search_reference_models, discover_local_printers, discover_authorized_printers, discover_usb_printers, read_canon_drc225_meter, review_local_candidate, save_discovery_schedule, printer_action, discover_broadcast_printers])
        .run(tauri::generate_context!())
        .expect("failed to start Nexa Collector Console");
}
