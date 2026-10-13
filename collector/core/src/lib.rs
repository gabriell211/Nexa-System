use chrono::{DateTime, Utc};
use ipnet::IpNet;
use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::{IpAddr, SocketAddr},
    path::Path,
    str::FromStr,
    time::Duration,
};
use thiserror::Error;
use uuid::Uuid;

#[derive(Debug, Error)]
pub enum CollectorError {
    #[error("configuration invalid: {0}")]
    Configuration(String),
    #[error("local store: {0}")]
    Storage(#[from] rusqlite::Error),
    #[error("filesystem: {0}")]
    Io(#[from] std::io::Error),
    #[error("json: {0}")]
    Json(#[from] serde_json::Error),
    #[error("transport failed: {0}")]
    Transport(#[from] reqwest::Error),
    #[error("SNMP read failed")]
    Snmp,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrinterConfig {
    pub printer_id: u64,
    pub ip: IpAddr,
    pub marker_counter_oid: Vec<u64>,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CollectorConfig {
    pub collector_id: Uuid,
    pub api_url: String,
    pub interval_seconds: u64,
    pub authorized_subnets: Vec<String>,
    pub printers: Vec<PrinterConfig>,
}

impl CollectorConfig {
    pub fn validate(&self) -> Result<(), CollectorError> {
        if self.interval_seconds < 60 || self.interval_seconds > 86400 {
            return Err(CollectorError::Configuration("interval must be 60..86400 seconds".into()));
        }
        if !self.api_url.starts_with("https://") {
            return Err(CollectorError::Configuration("api_url must use HTTPS".into()));
        }
        if self.authorized_subnets.is_empty() {
            return Err(CollectorError::Configuration("at least one authorized CIDR is required".into()));
        }
        let subnets: Vec<IpNet> = self.authorized_subnets.iter()
            .map(|cidr| IpNet::from_str(cidr).map_err(|_| CollectorError::Configuration("invalid CIDR".into())))
            .collect::<Result<_,_>>()?;

        let mut ids = std::collections::HashSet::new();
        for printer in &self.printers {
            if printer.printer_id == 0 || !ids.insert(printer.printer_id) {
                return Err(CollectorError::Configuration("duplicate/invalid printer ID".into()));
            }
            if !subnets.iter().any(|net| net.contains(&printer.ip)) {
                return Err(CollectorError::Configuration(format!("printer {} is not within authorized subnets", printer.printer_id)));
            }
            if printer.marker_counter_oid.len() < 2 || printer.marker_counter_oid.len() > 128 {
                return Err(CollectorError::Configuration(format!("printer {} requires an explicitly verified meter OID", printer.printer_id)));
            }
        }
        Ok(())
    }
}

pub fn read_config(path: impl AsRef<Path>) -> Result<CollectorConfig, CollectorError> {
    let value: CollectorConfig = serde_json::from_slice(&fs::read(path)?)?;
    value.validate()?;
    Ok(value)
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Reading {
    pub sample_id: Uuid,
    pub printer_id: u64,
    pub collected_at: DateTime<Utc>,
    pub meter_total: Option<u64>,
    pub meter_mono: Option<u64>,
    pub meter_color: Option<u64>,
}

pub struct Outbox {
    connection: Connection,
}

impl Outbox {
    pub fn open(path: impl AsRef<Path>) -> Result<Self, CollectorError> {
        let db = Connection::open(path)?;
        db.pragma_update(None, "journal_mode", "WAL")?;
        db.pragma_update(None, "busy_timeout", 5000_i64)?;
        db.execute_batch(
            "CREATE TABLE IF NOT EXISTS outbox (
                sample_id TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                queued_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );"
        )?;
        Ok(Self { connection: db })
    }

    pub fn enqueue(&self, reading: &Reading) -> Result<bool, CollectorError> {
        let affected = self.connection.execute(
            "INSERT OR IGNORE INTO outbox(sample_id, payload, queued_at) VALUES (?1, ?2, ?3)",
            params![reading.sample_id.to_string(), serde_json::to_string(reading)?, Utc::now().to_rfc3339()],
        )?;
        Ok(affected > 0)
    }

    pub fn pending(&self) -> Result<u64, CollectorError> {
        Ok(self.connection.query_row("SELECT COUNT(*) FROM outbox", [], |row| row.get::<_,u64>(0))?)
    }

    pub fn next_batch(&self, limit: usize) -> Result<Vec<Reading>, CollectorError> {
        let mut stmt = self.connection.prepare(
            "SELECT payload FROM outbox ORDER BY queued_at, sample_id LIMIT ?1"
        )?;
        let rows = stmt.query_map([limit.clamp(1,100) as i64], |row| row.get::<_,String>(0))?;
        rows.map(|row| serde_json::from_str(&row?).map_err(CollectorError::from)).collect()
    }

    pub fn acknowledge(&mut self, sample_ids: &[Uuid]) -> Result<(), CollectorError> {
        let tx = self.connection.transaction()?;
        for id in sample_ids {
            tx.execute("DELETE FROM outbox WHERE sample_id = ?1", [id.to_string()])?;
        }
        tx.commit()?;
        Ok(())
    }

    pub fn mark_run(&self) -> Result<(), CollectorError> {
        self.connection.execute(
            "INSERT INTO state(key, value) VALUES ('last_run', ?1)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [Utc::now().to_rfc3339()],
        )?;
        Ok(())
    }

    pub fn last_run(&self) -> Result<Option<String>, CollectorError> {
        Ok(self.connection.query_row(
            "SELECT value FROM state WHERE key = 'last_run'", [], |row| row.get(0)
        ).optional()?)
    }
}

pub fn collect_snmp_v2c(
    printer: &PrinterConfig,
    community: &[u8],
) -> Result<Reading, CollectorError> {
    use snmp2::{Oid, SyncSession, Value};
    if community.is_empty() { return Err(CollectorError::Configuration("SNMP credential not provided".into())); }
    let endpoint = SocketAddr::new(printer.ip, 161);
    let mut session = SyncSession::new_v2c(endpoint, community, Some(Duration::from_secs(3)), 0)
        .map_err(|_| CollectorError::Snmp)?;
    let oid = Oid::from(printer.marker_counter_oid.as_slice()).map_err(|_| CollectorError::Snmp)?;
    let mut pdu = session.get(&oid).map_err(|_| CollectorError::Snmp)?;
    let counter = match pdu.varbinds.next().map(|(_, value)| value) {
        Some(Value::Counter64(v)) => Some(v),
        Some(Value::Counter32(v)) | Some(Value::Unsigned32(v)) => Some(v as u64),
        Some(Value::Integer(v)) if v >= 0 => Some(v as u64),
        _ => None,
    };
    if counter.is_none() { return Err(CollectorError::Snmp); }

    Ok(Reading {
        sample_id: Uuid::new_v4(),
        printer_id: printer.printer_id,
        collected_at: Utc::now(),
        meter_total: counter,
        meter_mono: None,
        meter_color: None,
    })
}

#[derive(Serialize)]
struct IngestBody<'a> {
    collector_id: Uuid,
    samples: &'a [Reading],
}
#[derive(Deserialize)]
struct IngestAck {
    acknowledged: Vec<Uuid>,
}

pub fn upload_pending(
    cfg: &CollectorConfig,
    token: &str,
    outbox: &mut Outbox,
) -> Result<usize, CollectorError> {
    if token.is_empty() {
        return Err(CollectorError::Configuration("NEXA_COLLECTOR_TOKEN is required".into()));
    }
    let samples = outbox.next_batch(100)?;
    if samples.is_empty() { return Ok(0); }
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(15))
        .redirect(reqwest::redirect::Policy::none())
        .build()?;
    let response = client
        .post(format!("{}/api/v1/collector/ingest", cfg.api_url.trim_end_matches('/')))
        .bearer_auth(token)
        .json(&IngestBody { collector_id: cfg.collector_id, samples: &samples })
        .send()?
        .error_for_status()?;
    let ack: IngestAck = response.json()?;
    let sent_ids: std::collections::HashSet<Uuid> = samples.iter().map(|s| s.sample_id).collect();
    if ack.acknowledged.iter().any(|id| !sent_ids.contains(id)) {
        return Err(CollectorError::Configuration("invalid server acknowledgement".into()));
    }
    outbox.acknowledge(&ack.acknowledged)?;
    Ok(ack.acknowledged.len())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn outbox_is_durable_and_deduplicated() {
        let tmp = tempfile::tempdir().unwrap();
        let file = tmp.path().join("queue.sqlite");
        let sample = Reading {
            sample_id: Uuid::new_v4(), printer_id: 17, collected_at: Utc::now(),
            meter_total: Some(15), meter_mono: None, meter_color: None,
        };
        {
            let outbox = Outbox::open(&file).unwrap();
            assert!(outbox.enqueue(&sample).unwrap());
            assert!(!outbox.enqueue(&sample).unwrap());
            assert_eq!(outbox.pending().unwrap(), 1);
        }
        let mut reopened = Outbox::open(&file).unwrap();
        assert_eq!(reopened.pending().unwrap(), 1);
        reopened.acknowledge(&[sample.sample_id]).unwrap();
        assert_eq!(reopened.pending().unwrap(), 0);
    }

    #[test]
    fn disallows_outside_authorized_cidr() {
        let cfg = CollectorConfig {
            collector_id: Uuid::new_v4(), api_url: "https://nexa.example".into(),
            interval_seconds: 300, authorized_subnets: vec!["192.168.1.0/24".into()],
            printers: vec![PrinterConfig {
                printer_id: 1, ip: "10.1.1.10".parse().unwrap(),
                marker_counter_oid: vec![1,3,6,1,2,1,43,10,2,1,4,1,1],
            }],
        };
        assert!(cfg.validate().is_err());
    }

    #[test]
    fn rejects_insecure_server_endpoint() {
        let cfg = CollectorConfig {
            collector_id: Uuid::new_v4(), api_url: "http://example.test".into(),
            interval_seconds: 300, authorized_subnets: vec!["192.168.1.0/24".into()],
            printers: vec![],
        };
        assert!(cfg.validate().is_err());
    }
}
