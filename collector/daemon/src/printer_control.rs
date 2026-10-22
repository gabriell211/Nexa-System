//! Privileged printer mutations shared by Unix socket and Windows named pipe.
//! The daemon verifies the meter locally; the UI never sees SNMP credentials.
use chrono::Utc;
use nexa_collector_core::{
    collect_snmp_v2c,
    fleet::FleetCommand,
    CollectorConfig, Outbox,
};

pub fn execute(
    outbox: &mut Outbox,
    config: &CollectorConfig,
    command: &FleetCommand,
    community: Option<&str>,
) -> Result<(), String> {
    let proof = if let FleetCommand::Monitor { printer_id } = command {
        let fleet = outbox.printer_fleet(config)
            .map_err(|_| "Lista local de impressoras inválida.".to_owned())?;
        let device = fleet.iter().find(|item| item.config.printer_id == *printer_id)
            .ok_or_else(|| "Impressora não cadastrada neste Collector.".to_owned())?;
        let community = community.filter(|v| !v.is_empty())
            .ok_or_else(|| "Credencial SNMP de leitura não configurada no serviço.".to_owned())?;
        collect_snmp_v2c(&device.config, community.as_bytes())
            .map_err(|_| "O contador SNMP não respondeu com um valor válido. Monitoramento não ativado.".to_owned())?;
        Some(Utc::now().to_rfc3339())
    } else {
        None
    };
    outbox.apply_printer_command(config, command, proof)
        .map_err(|e| match e {
            nexa_collector_core::CollectorError::Configuration(message) => message,
            _ => "Não foi possível salvar a lista operacional protegida.".into(),
        })
}
