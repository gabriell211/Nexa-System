//! Operational printer roster stored in the protected Collector SQLite state.
//! This is a local monitoring roster, not proof of cloud enrollment or billing certification.
use crate::{CollectorConfig, CollectorError, PrinterConfig};
use serde::{Deserialize, Serialize};
use std::{collections::HashSet, net::{IpAddr, Ipv4Addr}};

pub const MAX_FLEET_PRINTERS: usize = 500;

#[derive(Clone, Debug)]
pub struct FleetIdentity {
    pub manufacturer: Option<String>,
    pub model: Option<String>,
    pub serial: Option<String>,
    pub mac: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FleetPrinter {
    pub config: PrinterConfig,
    pub monitored: bool,
    pub last_tested_at: Option<String>,
    pub manufacturer: Option<String>,
    pub model: Option<String>,
    pub serial: Option<String>,
    pub mac: Option<String>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(tag = "action", rename_all = "snake_case", deny_unknown_fields)]
pub enum FleetCommand {
    Add { printer_id: u64, ip: Ipv4Addr, oid: Vec<u64>, auto_detect_reference: bool },
    Monitor { printer_id: u64 },
    Disable { printer_id: u64 },
    Remove { printer_id: u64 },
}

impl FleetCommand {
    pub fn printer_id(&self) -> u64 {
        match self {
            Self::Add { printer_id, .. } | Self::Monitor { printer_id }
            | Self::Disable { printer_id } | Self::Remove { printer_id } => *printer_id,
        }
    }
}

pub fn from_config(cfg: &CollectorConfig) -> Vec<FleetPrinter> {
    cfg.printers.iter().cloned().map(|config| FleetPrinter {
        config, monitored: true, last_tested_at: None,
        manufacturer: None, model: None, serial: None, mac: None,
    }).collect()
}

/// Always validate the *entire* roster against the administrator's immutable
/// scope. Neither the Console nor this override can grant new subnets or tokens.
pub fn validate(cfg: &CollectorConfig, fleet: &[FleetPrinter]) -> Result<(), CollectorError> {
    if fleet.len() > MAX_FLEET_PRINTERS {
        return Err(CollectorError::Configuration("printer roster capacity exceeded".into()));
    }
    let mut ips = HashSet::new();
    for item in fleet {
        let IpAddr::V4(ip) = item.config.ip else {
            return Err(CollectorError::Configuration("only IPv4 printer addresses are supported".into()));
        };
        crate::snmp_inventory::authorized_device(ip, &cfg.authorized_subnets)
            .map_err(|reason| CollectorError::Configuration(reason.into()))?;
        if !ips.insert(ip) {
            return Err(CollectorError::Configuration("duplicate IP in monitored fleet".into()));
        }
        for text in [&item.manufacturer, &item.model, &item.serial, &item.mac] {
            if text.as_deref().is_some_and(|value| value.len() > 200) {
                return Err(CollectorError::Configuration("device identity field too long".into()));
            }
        }
    }
    let mut check = cfg.clone();
    check.printers = fleet.iter().map(|item| item.config.clone()).collect();
    check.validate()
}

pub fn apply(
    cfg: &CollectorConfig,
    fleet: &mut Vec<FleetPrinter>,
    command: &FleetCommand,
    tested_at: Option<String>,
) -> Result<(), CollectorError> {
    let id = command.printer_id();
    if id == 0 {
        return Err(CollectorError::Configuration("equipment ID must be positive".into()));
    }
    // Validate against a staged clone so rejected actions cannot partially mutate
    // the caller's view of the local roster.
    let mut staged = fleet.clone();
    match command {
        FleetCommand::Add { ip, oid, auto_detect_reference, .. } => {
            if staged.iter().any(|entry| entry.config.printer_id == id) {
                return Err(CollectorError::Configuration("equipment ID already registered".into()));
            }
            if staged.len() >= MAX_FLEET_PRINTERS {
                return Err(CollectorError::Configuration("printer roster is full".into()));
            }
            staged.push(FleetPrinter {
                config: PrinterConfig {
                    printer_id: id, ip: IpAddr::V4(*ip),
                    marker_counter_oid: oid.clone(),
                    reference_model_id: None,
                    auto_detect_reference: *auto_detect_reference,
                },
                monitored: false, last_tested_at: None,
                manufacturer: None, model: None, serial: None, mac: None,
            });
        }
        FleetCommand::Monitor { .. } => {
            let item = staged.iter_mut().find(|entry| entry.config.printer_id == id)
                .ok_or_else(|| CollectorError::Configuration("equipment not registered".into()))?;
            let tested_at = tested_at.ok_or_else(|| CollectorError::Configuration(
                "SNMP meter test must succeed before monitoring".into(),
            ))?;
            item.monitored = true;
            item.last_tested_at = Some(tested_at);
        }
        FleetCommand::Disable { .. } => {
            let item = staged.iter_mut().find(|entry| entry.config.printer_id == id)
                .ok_or_else(|| CollectorError::Configuration("equipment not registered".into()))?;
            item.monitored = false;
        }
        FleetCommand::Remove { .. } => {
            let before = staged.len();
            staged.retain(|entry| entry.config.printer_id != id);
            if staged.len() == before {
                return Err(CollectorError::Configuration("equipment not registered".into()));
            }
        }
    }
    validate(cfg, &staged)?;
    *fleet = staged;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    fn sample_config() -> CollectorConfig {
        let mut cfg: CollectorConfig = serde_json::from_str(include_str!("../../config.example.json")).unwrap();
        cfg.printers.clear();
        cfg.authorized_subnets = vec!["192.168.15.0/24".into()];
        cfg.discovery_schedule.enabled = false;
        cfg
    }

    #[test]
    fn lifecycle_enforces_real_test_before_enable() {
        let cfg = sample_config();
        let mut fleet = vec![];
        let add = FleetCommand::Add {
            printer_id: 42, ip: "192.168.15.45".parse().unwrap(),
            oid: vec![1,3,6,1,2,1,43,10,2,1,4,1,1],
            auto_detect_reference: false,
        };
        apply(&cfg, &mut fleet, &add, None).unwrap();
        assert!(!fleet[0].monitored);
        assert!(apply(&cfg, &mut fleet, &FleetCommand::Monitor { printer_id: 42 }, None).is_err());
        apply(&cfg, &mut fleet, &FleetCommand::Monitor { printer_id: 42 }, Some("2026-10-22T12:00:00Z".into())).unwrap();
        assert!(fleet[0].monitored);
        apply(&cfg, &mut fleet, &FleetCommand::Disable { printer_id: 42 }, None).unwrap();
        assert!(!fleet[0].monitored);
        apply(&cfg, &mut fleet, &FleetCommand::Remove { printer_id: 42 }, None).unwrap();
        assert!(fleet.is_empty());
    }

    #[test]
    fn prevents_duplicate_identity_and_out_of_scope_ip() {
        let cfg = sample_config();
        let mut fleet = vec![];
        let add = |id, address: &str| FleetCommand::Add {
            printer_id: id, ip: address.parse().unwrap(),
            oid: vec![1,3,6,1,2,1,43,10,2,1,4,1,1],
            auto_detect_reference: false,
        };
        apply(&cfg, &mut fleet, &add(9, "192.168.15.8"), None).unwrap();
        assert!(apply(&cfg, &mut fleet, &add(9, "192.168.15.9"), None).is_err());
        assert!(apply(&cfg, &mut fleet, &add(10, "192.168.15.8"), None).is_err());
        assert!(apply(&cfg, &mut fleet, &add(10, "8.8.8.8"), None).is_err());
        assert_eq!(fleet.len(), 1);
    }

    #[test]
    fn sqlite_printer_roster_survives_restart_and_rejects_conflicts_atomically() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("local.sqlite");
        let cfg = sample_config();
        let add = FleetCommand::Add {
            printer_id: 55, ip: "192.168.15.55".parse().unwrap(),
            oid: vec![1, 3, 6, 1, 2, 1, 43, 10, 2, 1, 4, 1, 1],
            auto_detect_reference: false,
        };
        let mut db = crate::Outbox::open(&path).unwrap();
        db.apply_printer_command(&cfg, &add, None, None).unwrap();
        assert_eq!(db.printer_fleet(&cfg).unwrap().len(), 1);
        assert!(db.apply_printer_command(&cfg, &add, None, None).is_err());
        assert_eq!(db.printer_fleet(&cfg).unwrap().len(), 1);
        drop(db);
        let mut reopened = crate::Outbox::open(&path).unwrap();
        let saved = reopened.printer_fleet(&cfg).unwrap();
        assert_eq!(saved[0].config.printer_id, 55);
        assert!(!saved[0].monitored);
        reopened.apply_printer_command(&cfg, &FleetCommand::Monitor {
            printer_id: 55,
        }, Some("2026-10-22T12:00:00Z".into()), Some(FleetIdentity {
            manufacturer: Some("Kyocera".into()),
            model: Some("ECOSYS".into()),
            serial: None,
            mac: None,
        })).unwrap();
        assert!(reopened.printer_fleet(&cfg).unwrap()[0].monitored);
        assert_eq!(reopened.printer_fleet(&cfg).unwrap()[0].manufacturer.as_deref(), Some("Kyocera"));
        reopened.apply_printer_command(&cfg, &FleetCommand::Disable {
            printer_id: 55,
        }, None, None).unwrap();
        assert!(!reopened.printer_fleet(&cfg).unwrap()[0].monitored);
        reopened.apply_printer_command(&cfg, &FleetCommand::Remove {
            printer_id: 55,
        }, None, None).unwrap();
        assert!(reopened.printer_fleet(&cfg).unwrap().is_empty());
    }

    #[test]
    fn rejects_unknown_commands_and_forged_monitoring_proof() {
        assert!(serde_json::from_str::<FleetCommand>(r#"{"action":"wipe","printer_id":1}"#).is_err());
        assert!(serde_json::from_str::<FleetCommand>(r#"{"action":"monitor","printer_id":1,"tested_at":"fake"}"#).is_err());
    }
}
