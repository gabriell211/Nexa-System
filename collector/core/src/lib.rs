use chrono::{DateTime, Utc};
use ipnet::IpNet;
use rusqlite::{params, Connection, OptionalExtension};
use serde::{Deserialize, Serialize};
use std::{
    fs,
    net::{IpAddr, SocketAddr},
    path::Path,
    str::FromStr,
    time::{Duration, Instant},
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
    #[error("transport request or response failed")]
    Transport(#[from] reqwest::Error),
    #[error("server rejected upload with HTTP {status}")]
    HttpStatus { status: u16, retry_after_seconds: Option<u64> },
    #[error("server acknowledgement is invalid")]
    InvalidAcknowledgement,
    #[error("SNMP read failed")]
    Snmp,
    #[error("outbox reached configured sample limit ({limit}); pending readings are retained")]
    OutboxFull { limit: u64 },
    #[error("sample UUID reused with different reading content")]
    SampleConflict,
}

/// Typed delivery state controls retries independently from the SNMP polling interval.
/// The queue itself is never deleted or mutated by this policy.
#[derive(Debug, Default)]
pub struct UploadRetryPolicy {
    failures: u32,
    next_attempt: Option<Instant>,
}

impl UploadRetryPolicy {
    pub fn ready(&self, now: Instant) -> bool {
        self.next_attempt.is_none_or(|deadline| now >= deadline)
    }

    /// Avoid flooding the API while still promptly draining batches after ACK.
    pub fn on_success(&mut self, now: Instant) {
        self.failures = 0;
        self.next_attempt = Some(now + Duration::from_secs(10));
    }

    /// Exponential delays (15s..1h), deterministic 10% jitter, with server
    /// Retry-After respected up to 6 hours for HTTP 429/503 only.
    /// Invalid payload/identity responses are throttled to allow operator action.
    pub fn on_failure(&mut self, now: Instant, collector_id: Uuid, error: &CollectorError) -> u64 {
        self.failures = self.failures.saturating_add(1);
        let exponent = self.failures.saturating_sub(1).min(8);
        let base = 15_u64.saturating_mul(1_u64 << exponent).min(3600);
        let spread = (base / 10).max(1);
        let jitter = ((collector_id.as_u128() as u64) ^ self.failures as u64) % (spread + 1);
        let mut delay = base + jitter;

        match error {
            CollectorError::HttpStatus { status, retry_after_seconds } => {
                if matches!(*status, 429 | 503) {
                    if let Some(after) = retry_after_seconds {
                        delay = delay.max((*after).min(21_600));
                    }
                }
                if (400..500).contains(status) && !matches!(*status, 408 | 425 | 429) {
                    delay = delay.max(900);
                }
            }
            CollectorError::InvalidAcknowledgement => delay = delay.max(900),
            _ => {}
        }
        self.next_attempt = Some(now + Duration::from_secs(delay));
        delay
    }

    pub fn failures(&self) -> u32 { self.failures }
}

/// Never include request bodies, authentication tokens, or server error bodies in logs.
pub fn upload_error_kind(error: &CollectorError) -> &'static str {
    match error {
        CollectorError::HttpStatus { status: 401 | 403, .. } => "authorization",
        CollectorError::HttpStatus { status: 408 | 425 | 429, .. } => "retryable-http",
        CollectorError::HttpStatus { status: 500..=599, .. } => "server-unavailable",
        CollectorError::HttpStatus { .. } => "http-rejected",
        CollectorError::Transport(_) => "transport",
        CollectorError::InvalidAcknowledgement => "invalid-ack",
        CollectorError::Storage(_) | CollectorError::Io(_) | CollectorError::Json(_) => "local-storage",
        _ => "configuration",
    }
}

/// Retry-After delta seconds: malformed values (including HTTP dates) do not
/// bypass the exponential fallback. Absolute-date support remains pending.
fn retry_after_delta(value: Option<&reqwest::header::HeaderValue>) -> Option<u64> {
    value?
        .to_str()
        .ok()?
        .trim()
        .parse::<u64>()
        .ok()
        .map(|seconds| seconds.min(21_600))
}

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PrinterConfig {
    pub printer_id: u64,
    pub ip: IpAddr,
    pub marker_counter_oid: Vec<u64>,
}

fn default_heartbeat_interval() -> u64 { 60 }

const DEFAULT_MAX_PENDING_SAMPLES: u64 = 50_000;
fn default_max_pending_samples() -> u64 { DEFAULT_MAX_PENDING_SAMPLES }

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CollectorConfig {
    pub collector_id: Uuid,
    pub api_url: String,
    pub interval_seconds: u64,
    #[serde(default = "default_heartbeat_interval")]
    pub heartbeat_interval_seconds: u64,
    #[serde(default = "default_max_pending_samples")]
    pub max_pending_samples: u64,
    pub authorized_subnets: Vec<String>,
    pub printers: Vec<PrinterConfig>,
}

impl CollectorConfig {
    pub fn validate(&self) -> Result<(), CollectorError> {
        if self.interval_seconds < 60 || self.interval_seconds > 86400 {
            return Err(CollectorError::Configuration("interval must be 60..86400 seconds".into()));
        }
        if !(30..=3600).contains(&self.heartbeat_interval_seconds) {
            return Err(CollectorError::Configuration("heartbeat_interval_seconds must be 30..3600".into()));
        }
        if !(1..=1_000_000).contains(&self.max_pending_samples) {
            return Err(CollectorError::Configuration("max_pending_samples must be 1..1000000".into()));
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
    max_pending_samples: u64,
}

impl Outbox {
    pub fn open(path: impl AsRef<Path>) -> Result<Self, CollectorError> {
        Self::open_bounded(path, DEFAULT_MAX_PENDING_SAMPLES)
    }

    pub fn open_bounded(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
    ) -> Result<Self, CollectorError> {
        if !(1..=1_000_000).contains(&max_pending_samples) {
            return Err(CollectorError::Configuration(
                "max_pending_samples must be 1..1000000".into(),
            ));
        }
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
        Ok(Self { connection: db, max_pending_samples })
    }

    pub fn enqueue(&self, reading: &Reading) -> Result<bool, CollectorError> {
        let sample_id = reading.sample_id.to_string();
        let payload = serde_json::to_string(reading)?;

        // The capacity condition and insertion form a single SQLite write.
        // Never discard old samples to make room for new readings.
        let affected = self.connection.execute(
            "INSERT OR IGNORE INTO outbox(sample_id, payload, queued_at)
             SELECT ?1, ?2, ?3
             WHERE (SELECT COUNT(*) FROM outbox) < ?4",
            params![sample_id, payload, Utc::now().to_rfc3339(), self.max_pending_samples],
        )?;
        if affected > 0 {
            return Ok(true);
        }

        // Retry of an identical sample is idempotent even when the queue is full.
        // A reused UUID with different content is never silently ignored.
        let existing: Option<String> = self.connection.query_row(
            "SELECT payload FROM outbox WHERE sample_id = ?1",
            [&sample_id],
            |row| row.get(0),
        ).optional()?;
        match existing {
            Some(saved) if saved == payload => Ok(false),
            Some(_) => Err(CollectorError::SampleConflict),
            None => Err(CollectorError::OutboxFull { limit: self.max_pending_samples }),
        }
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

/// Never delete a queued sample unless it was part of this exact request.
/// Partial acknowledgements are allowed: other samples stay in the outbox.
fn validate_upload_ack(
    samples: &[Reading],
    acknowledged: &[Uuid],
) -> Result<(), CollectorError> {
    if acknowledged.is_empty() {
        return Err(CollectorError::InvalidAcknowledgement);
    }
    let requested: std::collections::HashSet<Uuid> =
        samples.iter().map(|sample| sample.sample_id).collect();
    let received: std::collections::HashSet<Uuid> = acknowledged.iter().copied().collect();
    if acknowledged.len() != received.len()
        || received.iter().any(|id| !requested.contains(id))
    {
        return Err(CollectorError::InvalidAcknowledgement);
    }
    Ok(())
}

#[derive(Serialize)]
struct HeartbeatBody {
    collector_id: Uuid,
}
#[derive(Deserialize)]
struct HeartbeatAck {
    acknowledged: bool,
    collector_id: Uuid,
}

/// A successful HTTP POST is not enough: verify the bound identity and ACK.
/// No meter or device state is uploaded as part of this heartbeat.
pub fn send_heartbeat(cfg: &CollectorConfig, token: &str) -> Result<(), CollectorError> {
    if token.len() != 64 || !token.as_bytes().iter().all(|b| b.is_ascii_hexdigit()) {
        return Err(CollectorError::Configuration("collector token must be 64 hex characters".into()));
    }
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(15))
        .redirect(reqwest::redirect::Policy::none())
        .build()?;
    let ack: HeartbeatAck = client
        .post(format!("{}/api/v1/collector/heartbeat", cfg.api_url.trim_end_matches('/')))
        .bearer_auth(token)
        .json(&HeartbeatBody { collector_id: cfg.collector_id })
        .send()?.error_for_status()?.json()?;
    if !ack.acknowledged || ack.collector_id != cfg.collector_id {
        return Err(CollectorError::Configuration("heartbeat ACK identity mismatch".into()));
    }
    Ok(())
}

/// Deterministic up-to-10% jitter spreads restarts across collectors without
/// causing frequent retries during connectivity failures.
pub fn heartbeat_sleep_seconds(seconds: u64, collector_id: Uuid) -> u64 {
    let spread = (seconds / 10).max(1);
    seconds + (collector_id.as_u128() as u64 % (spread + 1))
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
        .send()?;
    if !response.status().is_success() {
        return Err(CollectorError::HttpStatus {
            status: response.status().as_u16(),
            retry_after_seconds: retry_after_delta(
                response.headers().get(reqwest::header::RETRY_AFTER)
            ),
        });
    }
    let ack: IngestAck = response.json()?;
    validate_upload_ack(&samples, &ack.acknowledged)?;
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

    fn sample_with_meter(meter: u64) -> Reading {
        Reading {
            sample_id: Uuid::new_v4(),
            printer_id: 17,
            collected_at: Utc::now(),
            meter_total: Some(meter),
            meter_mono: None,
            meter_color: None,
        }
    }

    #[test]
    fn bounded_outbox_pauses_without_discarding_and_recovers_after_ack() {
        let tmp = tempfile::tempdir().unwrap();
        let path = tmp.path().join("queue.sqlite");
        let first = sample_with_meter(10);
        let second = sample_with_meter(11);
        let third = sample_with_meter(12);
        {
            let outbox = Outbox::open_bounded(&path, 2).unwrap();
            assert!(outbox.enqueue(&first).unwrap());
            assert!(outbox.enqueue(&second).unwrap());
            assert!(matches!(
                outbox.enqueue(&third),
                Err(CollectorError::OutboxFull { limit: 2 })
            ));
            assert_eq!(outbox.pending().unwrap(), 2);
            assert_eq!(outbox.next_batch(100).unwrap().len(), 2);
        }
        let mut reopened = Outbox::open_bounded(&path, 2).unwrap();
        assert_eq!(reopened.pending().unwrap(), 2);
        assert!(!reopened.enqueue(&first).unwrap());
        assert!(matches!(
            reopened.enqueue(&third),
            Err(CollectorError::OutboxFull { limit: 2 })
        ));
        reopened.acknowledge(&[first.sample_id]).unwrap();
        assert_eq!(reopened.pending().unwrap(), 1);
        assert!(reopened.enqueue(&third).unwrap());
        assert_eq!(reopened.pending().unwrap(), 2);
        let remaining = reopened.next_batch(10).unwrap();
        assert_eq!(remaining[0].sample_id, second.sample_id);
        assert_eq!(remaining[1].sample_id, third.sample_id);
    }

    #[test]
    fn outbox_rejects_colliding_sample_uuid_even_when_full() {
        let tmp = tempfile::tempdir().unwrap();
        let outbox = Outbox::open_bounded(tmp.path().join("queue.sqlite"), 1).unwrap();
        let saved = sample_with_meter(10);
        let mut changed = saved.clone();
        changed.meter_total = Some(999);
        assert!(outbox.enqueue(&saved).unwrap());
        assert!(matches!(outbox.enqueue(&changed), Err(CollectorError::SampleConflict)));
        assert_eq!(outbox.pending().unwrap(), 1);
        assert_eq!(outbox.next_batch(1).unwrap()[0].meter_total, Some(10));
    }

    #[test]
    fn rejects_invalid_max_pending_configuration() {
        let mut cfg = CollectorConfig {
            collector_id: Uuid::new_v4(),
            api_url: "https://nexa.example".into(),
            interval_seconds: 300,
            heartbeat_interval_seconds: 60,
            max_pending_samples: 0,
            authorized_subnets: vec!["192.0.2.0/24".into()],
            printers: vec![],
        };
        assert!(cfg.validate().is_err());
        cfg.max_pending_samples = 1_000_001;
        assert!(cfg.validate().is_err());
        cfg.max_pending_samples = 1;
        assert!(cfg.validate().is_ok());
    }

    #[test]
    fn disallows_outside_authorized_cidr() {
        let cfg = CollectorConfig {
            collector_id: Uuid::new_v4(), api_url: "https://nexa.example".into(),
            interval_seconds: 300, heartbeat_interval_seconds: 60, max_pending_samples: 50_000, authorized_subnets: vec!["192.168.1.0/24".into()],
            printers: vec![PrinterConfig {
                printer_id: 1, ip: "10.1.1.10".parse().unwrap(),
                marker_counter_oid: vec![1,3,6,1,2,1,43,10,2,1,4,1,1],
            }],
        };
        assert!(cfg.validate().is_err());
    }

    #[test]
    fn validates_heartbeat_separately_from_meter_polling() {
        let mut cfg = CollectorConfig {
            collector_id: Uuid::new_v4(), api_url: "https://nexa.example".into(),
            interval_seconds: 1800, heartbeat_interval_seconds: 60, max_pending_samples: 50_000,
            authorized_subnets: vec!["192.168.1.0/24".into()], printers: vec![],
        };
        assert!(cfg.validate().is_ok());
        cfg.heartbeat_interval_seconds = 0;
        assert!(cfg.validate().is_err());
        cfg.heartbeat_interval_seconds = 3601;
        assert!(cfg.validate().is_err());
        cfg.heartbeat_interval_seconds = 30;
        assert!(cfg.validate().is_ok());
    }

    #[test]
    fn legacy_configs_default_to_60_second_heartbeats() {
        let id = Uuid::new_v4();
        let raw = serde_json::json!({
            "collector_id": id, "api_url": "https://nexa.example",
            "interval_seconds": 1800,
            "authorized_subnets": ["192.168.1.0/24"], "printers": []
        });
        let cfg: CollectorConfig = serde_json::from_value(raw).unwrap();
        assert_eq!(cfg.heartbeat_interval_seconds, 60);
        assert_eq!(cfg.max_pending_samples, DEFAULT_MAX_PENDING_SAMPLES);
        assert!(cfg.validate().is_ok());
        let pause = heartbeat_sleep_seconds(60, id);
        assert!((60..=66).contains(&pause));
    }

    #[test]
    fn rejects_unknown_duplicate_and_empty_upload_ack_without_consumption() {
        let temp = tempfile::tempdir().unwrap();
        let mut outbox = Outbox::open_bounded(temp.path().join("queue.sqlite"), 3).unwrap();
        let one = sample_with_meter(12);
        let two = sample_with_meter(13);
        outbox.enqueue(&one).unwrap();
        outbox.enqueue(&two).unwrap();
        let samples = outbox.next_batch(100).unwrap();
        assert!(validate_upload_ack(&samples, &[]).is_err());
        assert!(validate_upload_ack(&samples, &[Uuid::new_v4()]).is_err());
        assert!(validate_upload_ack(&samples, &[one.sample_id, one.sample_id]).is_err());
        assert_eq!(outbox.pending().unwrap(), 2);
        assert!(validate_upload_ack(&samples, &[one.sample_id]).is_ok());
        outbox.acknowledge(&[one.sample_id]).unwrap();
        assert_eq!(outbox.pending().unwrap(), 1);
        assert_eq!(outbox.next_batch(10).unwrap()[0].sample_id, two.sample_id);
    }

    #[test]
    fn exponential_retry_tracks_network_failures_without_mutating_queue() {
        let id = Uuid::nil();
        let start = Instant::now();
        let mut retry = UploadRetryPolicy::default();
        assert!(retry.ready(start));
        let delay1 = retry.on_failure(start, id, &CollectorError::Snmp);
        assert!((15..=16).contains(&delay1));
        assert!(!retry.ready(start + Duration::from_secs(delay1 - 1)));
        assert!(retry.ready(start + Duration::from_secs(delay1)));
        let delay2 = retry.on_failure(start, id, &CollectorError::Snmp);
        assert!((30..=33).contains(&delay2));
        for _ in 0..15 {
            retry.on_failure(start, id, &CollectorError::Snmp);
        }
        assert_eq!(retry.failures(), 17);
        let max_delay = retry.on_failure(start, id, &CollectorError::Snmp);
        assert!((3600..=3960).contains(&max_delay));
        retry.on_success(start);
        assert_eq!(retry.failures(), 0);
        assert!(!retry.ready(start + Duration::from_secs(9)));
        assert!(retry.ready(start + Duration::from_secs(10)));
    }

    #[test]
    fn honors_rate_limits_and_fails_closed_for_auth_or_bad_ack() {
        let start = Instant::now();
        let id = Uuid::nil();
        let mut retry = UploadRetryPolicy::default();
        let delay = retry.on_failure(start, id, &CollectorError::HttpStatus {
            status: 429,
            retry_after_seconds: Some(2400),
        });
        assert_eq!(delay, 2400);
        assert!(!retry.ready(start + Duration::from_secs(2399)));
        assert!(retry.ready(start + Duration::from_secs(2400)));

        retry.on_success(start);
        let auth = retry.on_failure(start, id, &CollectorError::HttpStatus {
            status: 401,
            retry_after_seconds: None,
        });
        assert!(auth >= 900);
        retry.on_success(start);
        let bad_ack = retry.on_failure(start, id, &CollectorError::InvalidAcknowledgement);
        assert!(bad_ack >= 900);
    }

    #[test]
    fn ignores_malformed_or_untrusted_retry_after_values() {
        use reqwest::header::HeaderValue;
        assert_eq!(retry_after_delta(Some(&HeaderValue::from_static("23"))), Some(23));
        assert_eq!(retry_after_delta(Some(&HeaderValue::from_static("99999999"))), Some(21600));
        assert_eq!(retry_after_delta(Some(&HeaderValue::from_static("Wed, 21 Oct 2030 07:28:00 GMT"))), None);
        assert_eq!(retry_after_delta(Some(&HeaderValue::from_static("bad"))), None);
        assert_eq!(retry_after_delta(None), None);
    }

    #[test]
    fn queue_survives_a_failed_upload_retry_without_ack() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("queue.sqlite");
        let sample = sample_with_meter(93);
        {
            let outbox = Outbox::open_bounded(&path, 1).unwrap();
            outbox.enqueue(&sample).unwrap();
            let mut retry = UploadRetryPolicy::default();
            retry.on_failure(Instant::now(), Uuid::nil(), &CollectorError::HttpStatus {
                status: 503,
                retry_after_seconds: Some(200),
            });
            assert_eq!(outbox.pending().unwrap(), 1);
        }
        let reopened = Outbox::open_bounded(path, 1).unwrap();
        assert_eq!(reopened.pending().unwrap(), 1);
        assert_eq!(reopened.next_batch(1).unwrap()[0].sample_id, sample.sample_id);
    }

    #[test]
    fn rejects_insecure_server_endpoint() {
        let cfg = CollectorConfig {
            collector_id: Uuid::new_v4(), api_url: "http://example.test".into(),
            interval_seconds: 300, heartbeat_interval_seconds: 60, max_pending_samples: 50_000,
            authorized_subnets: vec!["192.168.1.0/24".into()],
            printers: vec![],
        };
        assert!(cfg.validate().is_err());
    }
}
