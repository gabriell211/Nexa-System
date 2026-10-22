//! Privileged SNMP broadcast discovery. Results are stored only in the
//! service's local SQLite state and must still be reviewed before enrollment.
use chrono::Utc;
use nexa_collector_core::{
    broadcast_discovery::discover_broadcast,
    CollectorConfig, DiscoveryReport, Outbox,
};

pub fn execute(
    outbox: &Outbox, cfg: &CollectorConfig, cidrs: &[String], community: Option<&str>,
) -> Result<(), String> {
    let credential = community.filter(|value| !value.is_empty())
        .ok_or_else(|| "Credencial SNMP protegida não configurada no serviço.".to_owned())?;
    let candidates = discover_broadcast(
        &cfg.authorized_subnets, cidrs, credential.as_bytes(),
    ).map_err(|error| format!("Busca rápida recusada ou indisponível: {error}"))?;
    outbox.save_discovery_report(&DiscoveryReport {
        last_completed_at: Some(Utc::now()),
        last_error_kind: None,
        observed_candidates: candidates.len(),
        candidates,
    }).map_err(|_| "Não foi possível salvar os candidatos encontrados.".to_owned())
}
