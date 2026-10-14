use aes_gcm::{
    aead::{Aead, AeadCore, OsRng, Payload},
    Aes256Gcm, KeyInit, Nonce,
};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use chrono::{DateTime, Utc};
use zeroize::Zeroizing;
use ipnet::IpNet;
use rusqlite::{params, Connection, OpenFlags, OptionalExtension, TransactionBehavior};
use serde::{Deserialize, Serialize};
use std::{
    fs::{self, OpenOptions},
    net::{IpAddr, SocketAddr},
    path::{Path, PathBuf},
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
    #[error("outbox retained payload byte budget exceeded ({limit} bytes); pending and quarantined readings are preserved")]
    OutboxBytesFull { limit: u64 },
    #[error("local SQLite file footprint has reached its configured soft limit ({limit} bytes); existing readings are preserved")]
    SqliteSoftLimit { limit: u64 },
    #[error("sample UUID reused with different reading content")]
    SampleConflict,
    #[error("encrypted collector storage requires its configured encryption key")]
    EncryptionRequired,
    #[error("existing unencrypted readings require a separate audited migration before enabling encryption")]
    EncryptionMigrationRequired,
    #[error("collector payload encryption or authentication failed")]
    Crypto,
    #[error("encrypted destination has an incomplete or invalid migration marker")]
    MigrationIncomplete,
}

/// Non-sensitive local transfer history. Stored as a single JSON document in SQLite.
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
#[serde(default, deny_unknown_fields)]
pub struct DeliveryDiagnostics {
    pub last_success_at: Option<String>,
    pub last_failure_at: Option<String>,
    pub last_error_kind: Option<String>,
    pub next_attempt_at: Option<String>,
    pub consecutive_failures: u32,
}

impl DeliveryDiagnostics {
    /// Use a bounded wall-clock delta only to restore an in-memory timer.
    /// Invalid stored timestamps are surfaced, not silently reset.
    pub fn seconds_until_retry(&self, now: DateTime<Utc>) -> Result<u64, CollectorError> {
        match self.next_attempt_at.as_deref() {
            None => Ok(0),
            Some(raw) => {
                let when = DateTime::parse_from_rfc3339(raw).map_err(|_| {
                    CollectorError::Configuration("invalid stored delivery retry timestamp".into())
                })?;
                Ok(when.signed_duration_since(now).num_seconds().clamp(0, 21_600) as u64)
            }
        }
    }
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

    pub fn restore(&mut self, now: Instant, wait_seconds: u64, failures: u32) {
        self.failures = failures;
        self.next_attempt = if wait_seconds > 0 {
            Some(now + Duration::from_secs(wait_seconds.min(21_600)))
        } else {
            None
        };
    }
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
        CollectorError::Storage(_) | CollectorError::Io(_) | CollectorError::Json(_)
        | CollectorError::Crypto | CollectorError::EncryptionRequired
        | CollectorError::EncryptionMigrationRequired | CollectorError::MigrationIncomplete => "local-storage",
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
const DEFAULT_MAX_PENDING_PAYLOAD_BYTES: u64 = 64 * 1024 * 1024;
fn default_max_pending_payload_bytes() -> u64 { DEFAULT_MAX_PENDING_PAYLOAD_BYTES }
const DEFAULT_MAX_SQLITE_FILES_BYTES: u64 = 512 * 1024 * 1024;
fn default_max_sqlite_files_bytes() -> u64 { DEFAULT_MAX_SQLITE_FILES_BYTES }

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
    #[serde(default = "default_max_pending_payload_bytes")]
    pub max_pending_payload_bytes: u64,
    #[serde(default = "default_max_sqlite_files_bytes")]
    pub max_sqlite_files_bytes: u64,
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
        if !(1..=1_073_741_824).contains(&self.max_pending_payload_bytes) {
            return Err(CollectorError::Configuration(
                "max_pending_payload_bytes must be 1..1073741824".into(),
            ));
        }
        if !(1..=68_719_476_736).contains(&self.max_sqlite_files_bytes) {
            return Err(CollectorError::Configuration(
                "max_sqlite_files_bytes must be 1..68719476736".into(),
            ));
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

#[derive(Clone, Debug, Serialize)]
pub struct QuarantinedReading {
    pub sample_id: String,
    pub printer_id: u64,
    pub collected_at: String,
    pub quarantined_at: String,
    pub reason: String,
}

/// File sizes are observational and may change across SQLite connections.
#[derive(Clone, Debug, Serialize)]
pub struct SqliteFootprint {
    pub database_bytes: u64,
    pub wal_bytes: u64,
    pub shm_bytes: u64,
    pub total_bytes: u64,
    pub soft_limit_bytes: u64,
    pub soft_limit_reached: bool,
}

/// AES-256-GCM payload encryption, authenticated to the sample UUID.
/// Table indexes/UUIDs/timestamps and diagnostic metadata remain unencrypted.
const ENCRYPTION_MODE: &str = "aes-256-gcm-v1";
const ENCRYPTION_PREFIX: &str = "enc:v1:";
const KEY_CHECK_ID: &str = "nexa-collector-database-key-check";
const KEY_CHECK_PLAINTEXT: &str = "nexa-collector-key-check-v1";

struct PayloadCipher {
    cipher: Aes256Gcm,
}

impl PayloadCipher {
    fn from_hex(hex_key: &str) -> Result<Self, CollectorError> {
        if hex_key.len() != 64 || !hex_key.bytes().all(|c| c.is_ascii_hexdigit()) {
            return Err(CollectorError::Configuration(
                "NEXA_COLLECTOR_DB_KEY_HEX must contain exactly 64 hexadecimal characters".into(),
            ));
        }
        let mut raw = Zeroizing::new([0_u8; 32]);
        for (index, slot) in raw.iter_mut().enumerate() {
            *slot = u8::from_str_radix(&hex_key[index * 2..index * 2 + 2], 16)
                .map_err(|_| CollectorError::Crypto)?;
        }
        Ok(Self { cipher: Aes256Gcm::new_from_slice(&raw[..]).map_err(|_| CollectorError::Crypto)? })
    }

    fn seal(&self, text: &str, associated_id: &str) -> Result<String, CollectorError> {
        let nonce = Aes256Gcm::generate_nonce(&mut OsRng);
        let ciphertext = self.cipher.encrypt(
            &nonce,
            Payload { msg: text.as_bytes(), aad: associated_id.as_bytes() },
        ).map_err(|_| CollectorError::Crypto)?;
        let mut result = Vec::with_capacity(nonce.len() + ciphertext.len());
        result.extend_from_slice(&nonce);
        result.extend_from_slice(&ciphertext);
        Ok(format!("{ENCRYPTION_PREFIX}{}", BASE64.encode(result)))
    }

    fn open(&self, stored: &str, associated_id: &str) -> Result<String, CollectorError> {
        let encoded = stored.strip_prefix(ENCRYPTION_PREFIX).ok_or(CollectorError::Crypto)?;
        let decoded = BASE64.decode(encoded).map_err(|_| CollectorError::Crypto)?;
        if decoded.len() < 12 + 16 { return Err(CollectorError::Crypto); }
        let nonce = Nonce::from_slice(&decoded[..12]);
        let plaintext = self.cipher.decrypt(
            nonce,
            Payload { msg: &decoded[12..], aad: associated_id.as_bytes() },
        ).map_err(|_| CollectorError::Crypto)?;
        String::from_utf8(plaintext).map_err(|_| CollectorError::Crypto)
    }
}

#[derive(Debug, Clone, Copy, Serialize)]
pub struct MigrationReport {
    pub pending: u64,
    pub quarantined: u64,
    pub state_entries: u64,
}

pub struct Outbox {
    connection: Connection,
    cipher: Option<PayloadCipher>,
    database_path: PathBuf,
    max_pending_samples: u64,
    max_pending_payload_bytes: u64,
    max_sqlite_files_bytes: u64,
}

impl Outbox {
    pub fn open(path: impl AsRef<Path>) -> Result<Self, CollectorError> {
        Self::open_with_limits(path, DEFAULT_MAX_PENDING_SAMPLES, DEFAULT_MAX_PENDING_PAYLOAD_BYTES)
    }

    pub fn open_bounded(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
    ) -> Result<Self, CollectorError> {
        Self::open_with_limits(path, max_pending_samples, DEFAULT_MAX_PENDING_PAYLOAD_BYTES)
    }

    /// Limits count and serialized payload bytes across both outbox and quarantine.
    /// This is NOT a hard SQLite database/WAL/filesystem byte quota.
    pub fn open_with_limits(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
        max_pending_payload_bytes: u64,
    ) -> Result<Self, CollectorError> {
        Self::open_with_disk_guard(
            path, max_pending_samples, max_pending_payload_bytes,
            DEFAULT_MAX_SQLITE_FILES_BYTES,
        )
    }

    /// Soft safety guard only: never promises a filesystem hard quota.
    /// SQLite indexes, WAL and SHM are observed, but not atomically reserved.
    pub fn open_with_disk_guard(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
        max_pending_payload_bytes: u64,
        max_sqlite_files_bytes: u64,
    ) -> Result<Self, CollectorError> {
        Self::open_database(path, max_pending_samples, max_pending_payload_bytes, max_sqlite_files_bytes, None, false)
    }

    /// Opt-in for new, empty databases. Existing plaintext databases with
    /// readings are never converted in-place (WAL/free-page remnants).
    pub fn open_encrypted_disk_guard(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
        max_pending_payload_bytes: u64,
        max_sqlite_files_bytes: u64,
        hex_key: &str,
    ) -> Result<Self, CollectorError> {
        let cipher = PayloadCipher::from_hex(hex_key)?;
        Self::open_database(
            path, max_pending_samples, max_pending_payload_bytes,
            max_sqlite_files_bytes, Some(cipher), false,
        )
    }

    fn open_database(
        path: impl AsRef<Path>,
        max_pending_samples: u64,
        max_pending_payload_bytes: u64,
        max_sqlite_files_bytes: u64,
        cipher: Option<PayloadCipher>,
        fresh_migration: bool,
    ) -> Result<Self, CollectorError> {
        if !(1..=1_000_000).contains(&max_pending_samples) {
            return Err(CollectorError::Configuration(
                "max_pending_samples must be 1..1000000".into(),
            ));
        }
        if !(1..=1_073_741_824).contains(&max_pending_payload_bytes) {
            return Err(CollectorError::Configuration(
                "max_pending_payload_bytes must be 1..1073741824".into(),
            ));
        }
        if !(1..=68_719_476_736).contains(&max_sqlite_files_bytes) {
            return Err(CollectorError::Configuration(
                "max_sqlite_files_bytes must be 1..68719476736".into(),
            ));
        }
        let database_path = path.as_ref().to_path_buf();
        // Refuse in-place encryption of *any* prior SQLite file, even if the
        // current tables are empty: deleted plaintext may persist in WAL/pages.
        let previously_existing = database_path.exists();
        let mut db = Connection::open(&database_path)?;
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
            );
            CREATE TABLE IF NOT EXISTS quarantined_readings (
                sample_id TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                queued_at TEXT NOT NULL,
                quarantined_at TEXT NOT NULL,
                reason TEXT NOT NULL
            );"
        )?;
        // Explicit mode marker: encrypted stores cannot be opened without a
        // matching key; plaintext stores with readings cannot be auto-migrated.
        let mode: Option<String> = db.query_row(
            "SELECT value FROM state WHERE key = 'payload_encryption_mode'",
            [], |row| row.get(0),
        ).optional()?;
        match (mode.as_deref(), cipher.as_ref()) {
            (None, None) => {}
            (Some(ENCRYPTION_MODE), None) => return Err(CollectorError::EncryptionRequired),
            (Some(ENCRYPTION_MODE), Some(cipher)) => {
                let check: String = db.query_row(
                    "SELECT value FROM state WHERE key = 'payload_encryption_check'",
                    [], |row| row.get(0),
                ).map_err(|_| CollectorError::Crypto)?;
                if cipher.open(&check, KEY_CHECK_ID)? != KEY_CHECK_PLAINTEXT {
                    return Err(CollectorError::Crypto);
                }
                // A crash mid-copy must never expose a partial outbox to the
                // daemon, including when the encryption key is valid.
                let migration: Option<String> = db.query_row(
                    "SELECT value FROM state WHERE key = 'migration_status'",
                    [], |row| row.get(0),
                ).optional()?;
                if migration.as_deref().is_some_and(|v| v != "complete") {
                    return Err(CollectorError::MigrationIncomplete);
                }
            }
            (None, Some(cipher)) => {
                if previously_existing && !fresh_migration {
                    return Err(CollectorError::EncryptionMigrationRequired);
                }
                let retained: u64 = db.query_row(
                    "SELECT (SELECT COUNT(*) FROM outbox)
                          + (SELECT COUNT(*) FROM quarantined_readings)",
                    [], |row| row.get(0),
                )?;
                if retained > 0 {
                    return Err(CollectorError::EncryptionMigrationRequired);
                }
                let check = cipher.seal(KEY_CHECK_PLAINTEXT, KEY_CHECK_ID)?;
                let transaction = db.transaction()?;
                transaction.execute(
                    "INSERT INTO state(key, value) VALUES ('payload_encryption_check', ?1)",
                    [&check],
                )?;
                transaction.execute(
                    "INSERT INTO state(key, value) VALUES ('payload_encryption_mode', ?1)",
                    [ENCRYPTION_MODE],
                )?;
                if fresh_migration {
                    transaction.execute(
                        "INSERT INTO state(key, value) VALUES ('migration_status', 'incomplete')",
                        [],
                    )?;
                }
                transaction.commit()?;
            }
            _ => return Err(CollectorError::Crypto),
        }
        Ok(Self {
            connection: db, cipher, database_path, max_pending_samples,
            max_pending_payload_bytes, max_sqlite_files_bytes,
        })
    }

    /// Offline migration. The source stays intact and locked against writes
    /// for the duration of the verified copy. The destination MUST NOT exist.
    ///
    /// This function does not switch a running daemon or delete plaintext data.
    /// Operators must stop the service and explicitly change its DB path.
    pub fn migrate_plaintext_to_encrypted(
        source_path: impl AsRef<Path>,
        destination_path: impl AsRef<Path>,
        max_pending_samples: u64,
        max_pending_payload_bytes: u64,
        max_sqlite_files_bytes: u64,
        hex_key: &str,
    ) -> Result<MigrationReport, CollectorError> {
        let cipher = PayloadCipher::from_hex(hex_key)?;
        let source_path = source_path.as_ref();
        let destination_path = destination_path.as_ref();
        let source_canonical = fs::canonicalize(source_path)?;
        let parent = destination_path.parent().filter(|p| !p.as_os_str().is_empty())
            .unwrap_or(Path::new("."));
        let destination_dir = fs::canonicalize(parent)?;
        let destination_name = destination_path.file_name().ok_or_else(|| {
            CollectorError::Configuration("destination must be a new SQLite filename".into())
        })?;
        if destination_dir.join(destination_name) == source_canonical {
            return Err(CollectorError::Configuration(
                "source and destination paths must differ".into()
            ));
        }

        let mut source = Connection::open_with_flags(
            &source_canonical, OpenFlags::SQLITE_OPEN_READ_WRITE,
        )?;
        source.busy_timeout(Duration::from_secs(5))?;
        // BEGIN IMMEDIATE ensures no concurrent writer changes the original
        // snapshot before the copy/verification finishes.
        let source_lock = source.transaction_with_behavior(TransactionBehavior::Immediate)?;
        let source_mode: Option<String> = source_lock.query_row(
            "SELECT value FROM state WHERE key = 'payload_encryption_mode'",
            [], |row| row.get(0),
        ).optional()?;
        if source_mode.is_some() {
            return Err(CollectorError::EncryptionMigrationRequired);
        }
        let check: String = source_lock.query_row(
            "PRAGMA quick_check(1)", [], |row| row.get(0),
        )?;
        if check != "ok" {
            return Err(CollectorError::Configuration("source SQLite integrity check failed".into()));
        }

        // Exclusive creation prevents accidental overwrite of any existing
        // file/symlink. An unsuccessful copy leaves a marked incomplete DB.
        let mut new_file = OpenOptions::new();
        new_file.write(true).create_new(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            new_file.mode(0o600);
        }
        let handle = new_file.open(destination_path)?;
        drop(handle);
        let mut destination = Self::open_database(
            destination_path, max_pending_samples, max_pending_payload_bytes,
            max_sqlite_files_bytes, Some(cipher), true,
        )?;
        destination.connection.pragma_update(None, "synchronous", "FULL")?;
        let cipher = destination.cipher.as_ref().ok_or(CollectorError::Crypto)?;
        let copy = destination.connection.transaction()?;
        let mut report = MigrationReport { pending: 0, quarantined: 0, state_entries: 0 };

        {
            let mut stmt = source_lock.prepare(
                "SELECT sample_id, payload, queued_at FROM outbox ORDER BY queued_at, sample_id"
            )?;
            let rows = stmt.query_map([], |row| {
                Ok((row.get::<_, String>(0)?, row.get::<_, String>(1)?, row.get::<_, String>(2)?))
            })?;
            for row in rows {
                let (id, raw, queued_at) = row?;
                let plaintext = Zeroizing::new(raw);
                let reading: Reading = serde_json::from_str(&plaintext)?;
                if reading.sample_id.to_string() != id {
                    return Err(CollectorError::SampleConflict);
                }
                let encrypted = cipher.seal(&plaintext, &id)?;
                if cipher.open(&encrypted, &id)?.as_str() != plaintext.as_str() {
                    return Err(CollectorError::Crypto);
                }
                copy.execute(
                    "INSERT INTO outbox(sample_id, payload, queued_at) VALUES (?1, ?2, ?3)",
                    params![id, encrypted, queued_at],
                )?;
                report.pending += 1;
            }
        }
        {
            let mut stmt = source_lock.prepare(
                "SELECT sample_id, payload, queued_at, quarantined_at, reason
                 FROM quarantined_readings ORDER BY queued_at, sample_id"
            )?;
            let rows = stmt.query_map([], |row| {
                Ok((
                    row.get::<_, String>(0)?, row.get::<_, String>(1)?,
                    row.get::<_, String>(2)?, row.get::<_, String>(3)?,
                    row.get::<_, String>(4)?,
                ))
            })?;
            for row in rows {
                let (id, raw, queued_at, quarantined_at, reason) = row?;
                let plaintext = Zeroizing::new(raw);
                let reading: Reading = serde_json::from_str(&plaintext)?;
                if reading.sample_id.to_string() != id {
                    return Err(CollectorError::SampleConflict);
                }
                let encrypted = cipher.seal(&plaintext, &id)?;
                if cipher.open(&encrypted, &id)?.as_str() != plaintext.as_str() {
                    return Err(CollectorError::Crypto);
                }
                copy.execute(
                    "INSERT INTO quarantined_readings(sample_id, payload, queued_at, quarantined_at, reason)
                     VALUES (?1, ?2, ?3, ?4, ?5)",
                    params![id, encrypted, queued_at, quarantined_at, reason],
                )?;
                report.quarantined += 1;
            }
        }
        {
            let mut stmt = source_lock.prepare(
                "SELECT key, value FROM state
                 WHERE key NOT IN ('payload_encryption_mode', 'payload_encryption_check', 'migration_status')
                 ORDER BY key"
            )?;
            let rows = stmt.query_map([], |row| {
                Ok((row.get::<_, String>(0)?, row.get::<_, String>(1)?))
            })?;
            for row in rows {
                let (key, value) = row?;
                copy.execute("INSERT INTO state(key, value) VALUES (?1, ?2)", params![key, value])?;
                report.state_entries += 1;
            }
        }
        for (table, expected) in [
            ("outbox", report.pending),
            ("quarantined_readings", report.quarantined),
        ] {
            // Identifiers are hard-coded, never derived from user input.
            let actual: u64 = copy.query_row(
                &format!("SELECT COUNT(*) FROM {table}"), [], |row| row.get(0),
            )?;
            if actual != expected {
                return Err(CollectorError::Configuration("migration row count mismatch".into()));
            }
        }
        copy.commit()?;
        let destination_check: String = destination.connection.query_row(
            "PRAGMA quick_check(1)", [], |row| row.get(0),
        )?;
        if destination_check != "ok" {
            return Err(CollectorError::Configuration(
                "encrypted destination integrity check failed".into()
            ));
        }
        // Publish a complete DB only AFTER row-count, AEAD and SQLite checks.
        let marked = destination.connection.execute(
            "UPDATE state SET value = 'complete'
             WHERE key = 'migration_status' AND value = 'incomplete'", [],
        )?;
        if marked != 1 {
            return Err(CollectorError::MigrationIncomplete);
        }
        source_lock.commit()?;
        Ok(report)
    }

    /// Measures current on-disk files used by the SQLite WAL database.
    /// WAL/SHM legitimately disappear when other connections close.
    /// This is a soft preflight, not an atomic filesystem reservation.
    pub fn sqlite_footprint(&self) -> Result<SqliteFootprint, CollectorError> {
        let bytes = |path: &Path| -> Result<u64, CollectorError> {
            match fs::metadata(path) {
                Ok(metadata) => Ok(metadata.len()),
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(0),
                Err(error) => Err(error.into()),
            }
        };
        let sidecar = |suffix: &str| {
            let mut pathname = self.database_path.as_os_str().to_os_string();
            pathname.push(suffix);
            PathBuf::from(pathname)
        };
        let database_bytes = fs::metadata(&self.database_path)?.len();
        let wal_bytes = bytes(&sidecar("-wal"))?;
        let shm_bytes = bytes(&sidecar("-shm"))?;
        let total_bytes = database_bytes.saturating_add(wal_bytes).saturating_add(shm_bytes);
        Ok(SqliteFootprint {
            database_bytes, wal_bytes, shm_bytes, total_bytes,
            soft_limit_bytes: self.max_sqlite_files_bytes,
            soft_limit_reached: total_bytes >= self.max_sqlite_files_bytes,
        })
    }

    pub fn disk_soft_limit_reached(&self) -> Result<bool, CollectorError> {
        Ok(self.sqlite_footprint()?.soft_limit_reached)
    }

    pub fn payload_encryption_enabled(&self) -> bool { self.cipher.is_some() }

    fn encode_payload(&self, plaintext: &str, sample_id: &str) -> Result<String, CollectorError> {
        match &self.cipher {
            Some(cipher) => cipher.seal(plaintext, sample_id),
            None => Ok(plaintext.to_owned()),
        }
    }

    fn decode_payload(&self, stored: &str, sample_id: &str) -> Result<String, CollectorError> {
        match &self.cipher {
            Some(cipher) => cipher.open(stored, sample_id),
            None if stored.starts_with(ENCRYPTION_PREFIX) => Err(CollectorError::EncryptionRequired),
            None => Ok(stored.to_owned()),
        }
    }

    pub fn enqueue(&self, reading: &Reading) -> Result<bool, CollectorError> {
        let sample_id = reading.sample_id.to_string();
        let plain = serde_json::to_string(reading)?;
        let payload = self.encode_payload(&plain, &sample_id)?;

        // Conservative preflight for the physical database/WAL footprint.
        // Existing UUIDs are still checked below for idempotency. This cannot
        // bound the bytes generated by an individual SQLite transaction.
        let disk_limited = self.disk_soft_limit_reached()?;
        // The count and payload constraints share a single SQLite statement.
        let affected = if disk_limited { 0 } else { self.connection.execute(
            "INSERT OR IGNORE INTO outbox(sample_id, payload, queued_at)
             SELECT ?1, ?2, ?3
             WHERE NOT EXISTS (
                 SELECT 1 FROM quarantined_readings WHERE sample_id = ?1
             ) AND (SELECT COUNT(*) FROM outbox)
                 + (SELECT COUNT(*) FROM quarantined_readings) < ?4
               AND (SELECT COALESCE(SUM(LENGTH(CAST(payload AS BLOB))), 0) FROM outbox)
                 + (SELECT COALESCE(SUM(LENGTH(CAST(payload AS BLOB))), 0) FROM quarantined_readings)
                 + LENGTH(CAST(?2 AS BLOB)) <= ?5",
            params![
                sample_id, payload, Utc::now().to_rfc3339(), 
                self.max_pending_samples, self.max_pending_payload_bytes
            ],
        )? };
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
            Some(saved) if self.decode_payload(&saved, &sample_id)? == plain => Ok(false),
            Some(_) => Err(CollectorError::SampleConflict),
            None => {
                let quarantined: bool = self.connection.query_row(
                    "SELECT EXISTS(SELECT 1 FROM quarantined_readings WHERE sample_id = ?1)",
                    [&sample_id], |row| row.get(0),
                )?;
                if quarantined {
                    Err(CollectorError::SampleConflict)
                } else {
                    if disk_limited {
                        Err(CollectorError::SqliteSoftLimit {
                            limit: self.max_sqlite_files_bytes,
                        })
                    } else if self.retained()? >= self.max_pending_samples {
                        Err(CollectorError::OutboxFull { limit: self.max_pending_samples })
                    } else {
                        Err(CollectorError::OutboxBytesFull {
                            limit: self.max_pending_payload_bytes
                        })
                    }
                }
            },
        }
    }

    pub fn pending(&self) -> Result<u64, CollectorError> {
        Ok(self.connection.query_row("SELECT COUNT(*) FROM outbox", [], |row| row.get::<_,u64>(0))?)
    }

    /// Byte sum of serialized payload columns, including quarantined readings.
    /// Excludes SQLite page/index metadata, free pages, WAL and SHM sizes.
    pub fn retained_payload_bytes(&self) -> Result<u64, CollectorError> {
        Ok(self.connection.query_row(
            "SELECT
               (SELECT COALESCE(SUM(LENGTH(CAST(payload AS BLOB))), 0) FROM outbox)
             + (SELECT COALESCE(SUM(LENGTH(CAST(payload AS BLOB))), 0) FROM quarantined_readings)",
            [], |row| row.get::<_, u64>(0)
        )?)
    }

    pub fn at_capacity(&self) -> Result<bool, CollectorError> {
        Ok(
            self.retained()? >= self.max_pending_samples
            || self.retained_payload_bytes()? >= self.max_pending_payload_bytes
            || self.disk_soft_limit_reached()?
        )
    }

    /// All retained records consume the same capacity; isolation never frees
    /// storage to collect more while rejected samples await operator review.
    pub fn retained(&self) -> Result<u64, CollectorError> {
        Ok(self.pending()? + self.quarantined_count()?)
    }

    pub fn quarantined_count(&self) -> Result<u64, CollectorError> {
        Ok(self.connection.query_row(
            "SELECT COUNT(*) FROM quarantined_readings", [],
            |row| row.get::<_, u64>(0),
        )?)
    }

    /// Sanitized metadata only. The original reading stays in SQLite and is
    /// not exposed through the console, logs or API.
    pub fn quarantined_readings(&self, limit: usize) -> Result<Vec<QuarantinedReading>, CollectorError> {
        let mut query = self.connection.prepare(
            "SELECT sample_id, payload, quarantined_at, reason
             FROM quarantined_readings ORDER BY quarantined_at, sample_id LIMIT ?1"
        )?;
        let rows = query.query_map([limit.clamp(1, 100) as i64], |row| {
            let id: String = row.get(0)?;
            let payload: String = row.get(1)?;
            let quarantined_at: String = row.get(2)?;
            let reason: String = row.get(3)?;
            Ok((id, payload, quarantined_at, reason))
        })?;
        rows.map(|row| {
            let (sample_id, payload, quarantined_at, reason) = row?;
            let reading: Reading = serde_json::from_str(&self.decode_payload(&payload, &sample_id)?)?;
            if reading.sample_id.to_string() != sample_id {
                return Err(CollectorError::SampleConflict);
            }
            Ok(QuarantinedReading {
                sample_id, printer_id: reading.printer_id,
                collected_at: reading.collected_at.to_rfc3339(),
                quarantined_at, reason,
            })
        }).collect()
    }

    /// Moves one individually rejected sample in a single transaction.
    /// The raw JSON is always retained; no implicit purge of financial meters.
    pub fn quarantine(&mut self, sample_id: Uuid, reason: &'static str) -> Result<(), CollectorError> {
        let transaction = self.connection.transaction()?;
        let moved = transaction.execute(
            "INSERT INTO quarantined_readings(sample_id, payload, queued_at, quarantined_at, reason)
             SELECT sample_id, payload, queued_at, ?2, ?3 FROM outbox WHERE sample_id = ?1",
            params![sample_id.to_string(), Utc::now().to_rfc3339(), reason],
        )?;
        if moved != 1 {
            return Err(CollectorError::SampleConflict);
        }
        let deleted = transaction.execute(
            "DELETE FROM outbox WHERE sample_id = ?1", [sample_id.to_string()],
        )?;
        if deleted != 1 {
            return Err(CollectorError::SampleConflict);
        }
        transaction.commit()?;
        Ok(())
    }

    /// Explicit recovery action. A repeated UUID already in outbox is never
    /// overwritten; operator must resolve the server-side cause before retry.
    pub fn restore_quarantined(&mut self, sample_id: Uuid) -> Result<bool, CollectorError> {
        let transaction = self.connection.transaction()?;
        let restored = transaction.execute(
            "INSERT OR IGNORE INTO outbox(sample_id, payload, queued_at)
             SELECT sample_id, payload, queued_at FROM quarantined_readings
             WHERE sample_id = ?1",
            [sample_id.to_string()],
        )?;
        if restored == 0 {
            let exists: bool = transaction.query_row(
                "SELECT EXISTS(SELECT 1 FROM quarantined_readings WHERE sample_id = ?1)",
                [sample_id.to_string()], |row| row.get(0)
            )?;
            if exists {
                return Err(CollectorError::SampleConflict);
            }
            return Ok(false);
        }
        let deleted = transaction.execute(
            "DELETE FROM quarantined_readings WHERE sample_id = ?1",
            [sample_id.to_string()],
        )?;
        if deleted != 1 { return Err(CollectorError::SampleConflict); }
        transaction.commit()?;
        Ok(true)
    }

    pub fn next_batch(&self, limit: usize) -> Result<Vec<Reading>, CollectorError> {
        let mut stmt = self.connection.prepare(
            "SELECT sample_id, payload FROM outbox ORDER BY queued_at, sample_id LIMIT ?1"
        )?;
        let rows = stmt.query_map([limit.clamp(1,100) as i64], |row| {
            Ok((row.get::<_,String>(0)?, row.get::<_,String>(1)?))
        })?;
        rows.map(|row| {
            let (sample_id, stored) = row?;
            let plain = self.decode_payload(&stored, &sample_id)?;
            let reading: Reading = serde_json::from_str(&plain)?;
            if reading.sample_id.to_string() != sample_id {
                return Err(CollectorError::Crypto);
            }
            Ok(reading)
        }).collect()
    }

    pub fn acknowledge(&mut self, sample_ids: &[Uuid]) -> Result<(), CollectorError> {
        let tx = self.connection.transaction()?;
        for id in sample_ids {
            tx.execute("DELETE FROM outbox WHERE sample_id = ?1", [id.to_string()])?;
        }
        tx.commit()?;
        Ok(())
    }

    pub fn delivery_diagnostics(&self) -> Result<DeliveryDiagnostics, CollectorError> {
        let raw: Option<String> = self.connection.query_row(
            "SELECT value FROM state WHERE key = 'delivery_diagnostics'",
            [],
            |row| row.get(0),
        ).optional()?;
        raw.map(|value| serde_json::from_str(&value).map_err(CollectorError::from))
            .transpose()
            .map(|value| value.unwrap_or_default())
    }

    pub fn save_delivery_diagnostics(&self, diagnostics: &DeliveryDiagnostics) -> Result<(), CollectorError> {
        let serialized = serde_json::to_string(diagnostics)?;
        self.connection.execute(
            "INSERT INTO state(key, value) VALUES ('delivery_diagnostics', ?1)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [&serialized],
        )?;
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

/// Interpret the backend's batch-level 422 cautiously. First retry *one*
/// sample: only an independently rejected UUID is quarantined. Other failures
/// (including authorization, rate limits and network outages) retain the queue.
fn process_upload_batch<F>(
    outbox: &mut Outbox,
    samples: &[Reading],
    mut send: F,
) -> Result<usize, CollectorError>
where
    F: FnMut(&[Reading]) -> Result<Vec<Uuid>, CollectorError>,
{
    if samples.is_empty() { return Ok(0); }
    let acknowledged = match send(samples) {
        Ok(ids) => ids,
        Err(CollectorError::HttpStatus { status: 422, .. }) => {
            let first = &samples[..1];
            match if samples.len() == 1 {
                Err(CollectorError::HttpStatus { status: 422, retry_after_seconds: None })
            } else {
                send(first)
            } {
                Ok(ids) => {
                    validate_upload_ack(first, &ids)?;
                    outbox.acknowledge(&ids)?;
                    return Ok(ids.len());
                }
                Err(CollectorError::HttpStatus { status: 422, .. }) => {
                    outbox.quarantine(first[0].sample_id, "individually-rejected-422")?;
                    return Ok(0);
                }
                Err(other) => return Err(other),
            }
        }
        Err(other) => return Err(other),
    };
    validate_upload_ack(samples, &acknowledged)?;
    outbox.acknowledge(&acknowledged)?;
    Ok(acknowledged.len())
}

fn send_ingest_samples(
    cfg: &CollectorConfig,
    token: &str,
    samples: &[Reading],
    client: &reqwest::blocking::Client,
) -> Result<Vec<Uuid>, CollectorError> {
    let response = client
        .post(format!("{}/api/v1/collector/ingest", cfg.api_url.trim_end_matches('/')))
        .bearer_auth(token)
        .json(&IngestBody { collector_id: cfg.collector_id, samples })
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
    Ok(ack.acknowledged)
}

pub fn upload_pending(
    cfg: &CollectorConfig,
    token: &str,
    outbox: &mut Outbox,
) -> Result<usize, CollectorError> {
    if token.len() != 64 || !token.as_bytes().iter().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(CollectorError::Configuration(
            "collector token must be 64 hex characters".into(),
        ));
    }
    let samples = outbox.next_batch(100)?;
    if samples.is_empty() { return Ok(0); }
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(15))
        .redirect(reqwest::redirect::Policy::none())
        .build()?;
    process_upload_batch(outbox, &samples, |subset| {
        send_ingest_samples(cfg, token, subset, &client)
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    const TEST_DB_KEY: &str = "ce7af92eab45e368f57e0998d3fcb36c9dc3ec6a8857352a24ef106952841eb0";
    const OTHER_DB_KEY: &str = "cafef00dcafef00dcafef00dcafef00dcafef00dcafef00dcafef00dcafef00d";

    #[test]
    fn encrypted_payload_roundtrip_dedup_quarantine_restore_and_ack() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("encrypted.sqlite");
        let original = sample_with_meter(830_112);
        let mut encrypted = Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, TEST_DB_KEY).unwrap();
        assert!(encrypted.payload_encryption_enabled());
        assert!(encrypted.enqueue(&original).unwrap());
        assert!(!encrypted.enqueue(&original).unwrap());

        let raw: String = encrypted.connection.query_row(
            "SELECT payload FROM outbox WHERE sample_id = ?1",
            [original.sample_id.to_string()], |row| row.get(0),
        ).unwrap();
        assert!(raw.starts_with(ENCRYPTION_PREFIX));
        assert!(!raw.contains("830112"));
        assert!(!raw.contains("meter_total"));
        let mut changed = original.clone();
        changed.meter_total = Some(1);
        assert!(matches!(encrypted.enqueue(&changed), Err(CollectorError::SampleConflict)));
        assert_eq!(encrypted.next_batch(10).unwrap()[0].meter_total, Some(830_112));

        encrypted.quarantine(original.sample_id, "individually-rejected-422").unwrap();
        let preserved_ciphertext: String = encrypted.connection.query_row(
            "SELECT payload FROM quarantined_readings WHERE sample_id = ?1",
            [original.sample_id.to_string()], |row| row.get(0),
        ).unwrap();
        assert_eq!(raw, preserved_ciphertext);
        assert_eq!(encrypted.quarantined_readings(10).unwrap()[0].printer_id, original.printer_id);
        drop(encrypted);

        assert!(matches!(
            Outbox::open(&path),
            Err(CollectorError::EncryptionRequired)
        ));
        assert!(matches!(
            Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, OTHER_DB_KEY),
            Err(CollectorError::Crypto)
        ));
        let mut reopened = Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, TEST_DB_KEY).unwrap();
        assert!(reopened.restore_quarantined(original.sample_id).unwrap());
        assert_eq!(reopened.next_batch(10).unwrap()[0].sample_id, original.sample_id);
        reopened.acknowledge(&[original.sample_id]).unwrap();
        assert_eq!(reopened.retained().unwrap(), 0);
    }

    #[test]
    fn encrypted_database_never_permits_plaintext_downgrade_or_legacy_in_place_conversion() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("old.sqlite");
        let sample = sample_with_meter(1337);
        {
            let outbox = Outbox::open(&path).unwrap();
            outbox.enqueue(&sample).unwrap();
        }
        assert!(matches!(
            Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, TEST_DB_KEY),
            Err(CollectorError::EncryptionMigrationRequired)
        ));
        assert_eq!(Outbox::open(&path).unwrap().next_batch(10).unwrap()[0].sample_id, sample.sample_id);
        {
            let mut old = Outbox::open(&path).unwrap();
            old.acknowledge(&[sample.sample_id]).unwrap();
        }
        // Even an emptied old file may retain plaintext in free pages/WAL.
        assert!(matches!(
            Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, TEST_DB_KEY),
            Err(CollectorError::EncryptionMigrationRequired)
        ));
    }

    #[test]
    fn rejects_invalid_encryption_keys_and_corrupted_authenticated_ciphertext() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("secure.sqlite");
        assert!(matches!(
            Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, "bad"),
            Err(CollectorError::Configuration(_))
        ));
        let reading = sample_with_meter(250);
        let outbox = Outbox::open_encrypted_disk_guard(&path, 10, 16_384, 512 * 1024 * 1024, TEST_DB_KEY).unwrap();
        outbox.enqueue(&reading).unwrap();
        outbox.connection.execute(
            "UPDATE outbox SET payload = 'enc:v1:bad-ciphertext' WHERE sample_id = ?1",
            [reading.sample_id.to_string()],
        ).unwrap();
        assert!(matches!(outbox.next_batch(10), Err(CollectorError::Crypto)));
        assert_eq!(outbox.pending().unwrap(), 1);
        assert!(matches!(outbox.enqueue(&reading), Err(CollectorError::Crypto)));
    }

    #[test]
    fn encrypted_payloads_use_unique_nonces_and_bind_sample_identity() {
        let cipher = PayloadCipher::from_hex(TEST_DB_KEY).unwrap();
        let left = Uuid::new_v4().to_string();
        let right = Uuid::new_v4().to_string();
        let one = cipher.seal("hello", &left).unwrap();
        let two = cipher.seal("hello", &left).unwrap();
        assert_ne!(one, two);
        assert_eq!(cipher.open(&one, &left).unwrap(), "hello");
        assert!(matches!(cipher.open(&one, &right), Err(CollectorError::Crypto)));
    }

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
    fn physical_sqlite_footprint_sums_database_and_existing_wal_sidecars() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("queue.sqlite");
        let outbox = Outbox::open_with_disk_guard(&path, 10, 4096, 512 * 1024 * 1024).unwrap();
        outbox.enqueue(&sample_with_meter(19)).unwrap();
        let footprint = outbox.sqlite_footprint().unwrap();
        let db_size = std::fs::metadata(&path).unwrap().len();
        assert_eq!(footprint.database_bytes, db_size);
        let sidecar_size = |suffix: &str| -> u64 {
            let mut name = path.as_os_str().to_os_string();
            name.push(suffix);
            std::fs::metadata(PathBuf::from(name)).map(|m| m.len()).unwrap_or(0)
        };
        assert_eq!(footprint.wal_bytes, sidecar_size("-wal"));
        assert_eq!(footprint.shm_bytes, sidecar_size("-shm"));
        assert_eq!(footprint.total_bytes, db_size + footprint.wal_bytes + footprint.shm_bytes);
        assert!(!footprint.soft_limit_reached);
    }

    #[test]
    fn disk_soft_limit_blocks_new_readings_but_allows_ack_and_quarantine_recovery() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("queue.sqlite");
        let first = sample_with_meter(11);
        let second = sample_with_meter(12);
        {
            let outbox = Outbox::open(&path).unwrap();
            outbox.enqueue(&first).unwrap();
        }
        let mut outbox = Outbox::open_with_disk_guard(&path, 10, 4096, 1).unwrap();
        assert!(outbox.disk_soft_limit_reached().unwrap());
        assert!(outbox.at_capacity().unwrap());
        assert!(!outbox.enqueue(&first).unwrap());
        assert!(matches!(outbox.enqueue(&second), Err(CollectorError::SqliteSoftLimit { limit: 1 })));
        assert_eq!(outbox.pending().unwrap(), 1);
        outbox.quarantine(first.sample_id, "individually-rejected-422").unwrap();
        assert_eq!(outbox.quarantined_count().unwrap(), 1);
        assert!(outbox.restore_quarantined(first.sample_id).unwrap());
        assert_eq!(outbox.next_batch(10).unwrap()[0].sample_id, first.sample_id);
        outbox.acknowledge(&[first.sample_id]).unwrap();
        assert_eq!(outbox.pending().unwrap(), 0);
    }

    #[test]
    fn legacy_config_accepts_default_soft_limit_and_rejects_invalid_values() {
        let mut cfg: CollectorConfig = serde_json::from_value(serde_json::json!({
            "collector_id": Uuid::new_v4(),
            "api_url": "https://nexa.example",
            "interval_seconds": 600,
            "authorized_subnets": ["192.0.2.0/24"],
            "printers": []
        })).unwrap();
        assert_eq!(cfg.max_sqlite_files_bytes, 512 * 1024 * 1024);
        cfg.max_sqlite_files_bytes = 0;
        assert!(cfg.validate().is_err());
        cfg.max_sqlite_files_bytes = 68_719_476_737;
        assert!(cfg.validate().is_err());
        cfg.max_sqlite_files_bytes = 1;
        assert!(cfg.validate().is_ok());
    }

    #[test]
    fn retained_payload_bytes_limit_prevents_silent_growth_and_recovers_after_ack() {
        let temp = tempfile::tempdir().unwrap();
        let db = temp.path().join("queue.sqlite");
        let first = sample_with_meter(10);
        let second = sample_with_meter(11);
        let third = sample_with_meter(12);
        let each = serde_json::to_string(&first).unwrap().len() as u64;
        assert_eq!(serde_json::to_string(&second).unwrap().len() as u64, each);
        let mut outbox = Outbox::open_with_limits(&db, 4, each * 2 - 1).unwrap();
        assert!(outbox.enqueue(&first).unwrap());
        assert!(matches!(
            outbox.enqueue(&second), Err(CollectorError::OutboxBytesFull { .. })
        ));
        assert_eq!(outbox.retained_payload_bytes().unwrap(), each);
        assert_eq!(outbox.pending().unwrap(), 1);
        assert!(!outbox.enqueue(&first).unwrap());
        outbox.quarantine(first.sample_id, "individually-rejected-422").unwrap();
        assert_eq!(outbox.retained_payload_bytes().unwrap(), each);
        assert!(matches!(
            outbox.enqueue(&third), Err(CollectorError::OutboxBytesFull { .. })
        ));
        assert!(outbox.restore_quarantined(first.sample_id).unwrap());
        assert_eq!(outbox.retained_payload_bytes().unwrap(), each);
        drop(outbox);
        let mut outbox = Outbox::open_with_limits(&db, 4, each * 2 - 1).unwrap();
        assert_eq!(outbox.retained_payload_bytes().unwrap(), each);
        outbox.acknowledge(&[first.sample_id]).unwrap();
        assert_eq!(outbox.retained_payload_bytes().unwrap(), 0);
        assert!(outbox.enqueue(&second).unwrap());
    }

    #[test]
    fn payload_quota_can_be_lower_than_legacy_retained_data_without_purging_it() {
        let temp = tempfile::tempdir().unwrap();
        let db = temp.path().join("queue.sqlite");
        let first = sample_with_meter(10);
        let second = sample_with_meter(11);
        {
            let outbox = Outbox::open_bounded(&db, 5).unwrap();
            outbox.enqueue(&first).unwrap();
            outbox.enqueue(&second).unwrap();
        }
        let mut reopened = Outbox::open_with_limits(&db, 5, 1).unwrap();
        assert!(reopened.at_capacity().unwrap());
        assert_eq!(reopened.pending().unwrap(), 2);
        assert!(matches!(reopened.enqueue(&sample_with_meter(12)), Err(CollectorError::OutboxBytesFull { .. })));
        reopened.acknowledge(&[first.sample_id]).unwrap();
        assert_eq!(reopened.pending().unwrap(), 1);
        reopened.acknowledge(&[second.sample_id]).unwrap();
        assert_eq!(reopened.retained_payload_bytes().unwrap(), 0);
    }

    #[test]
    fn rejects_invalid_payload_budget_while_legacy_json_receives_default() {
        let mut cfg: CollectorConfig = serde_json::from_value(serde_json::json!({
            "collector_id": Uuid::new_v4(),
            "api_url": "https://nexa.example",
            "interval_seconds": 300,
            "authorized_subnets": ["192.0.2.0/24"],
            "printers": []
        })).unwrap();
        assert_eq!(cfg.max_pending_payload_bytes, 64 * 1024 * 1024);
        cfg.max_pending_payload_bytes = 0;
        assert!(cfg.validate().is_err());
        cfg.max_pending_payload_bytes = 1_073_741_825;
        assert!(cfg.validate().is_err());
        cfg.max_pending_payload_bytes = 1;
        assert!(cfg.validate().is_ok());
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
            max_pending_payload_bytes: 64 * 1024 * 1024,
            max_sqlite_files_bytes: 512 * 1024 * 1024,
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
            interval_seconds: 300, heartbeat_interval_seconds: 60, max_pending_samples: 50_000, max_pending_payload_bytes: 64 * 1024 * 1024, max_sqlite_files_bytes: 512 * 1024 * 1024, authorized_subnets: vec!["192.168.1.0/24".into()],
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
            max_pending_payload_bytes: 64 * 1024 * 1024,
            max_sqlite_files_bytes: 512 * 1024 * 1024,
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
        assert_eq!(cfg.max_pending_payload_bytes, DEFAULT_MAX_PENDING_PAYLOAD_BYTES);
        assert_eq!(cfg.max_sqlite_files_bytes, DEFAULT_MAX_SQLITE_FILES_BYTES);
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
    fn delivery_diagnostics_survive_restart_without_exposing_secrets() {
        let tmp = tempfile::tempdir().unwrap();
        let db = tmp.path().join("queue.sqlite");
        let moment = Utc::now();
        let next = moment + chrono::Duration::minutes(30);
        let initial = DeliveryDiagnostics {
            last_success_at: None,
            last_failure_at: Some(moment.to_rfc3339()),
            last_error_kind: Some("authorization".to_string()),
            next_attempt_at: Some(next.to_rfc3339()),
            consecutive_failures: 4,
        };
        {
            let outbox = Outbox::open_bounded(&db, 2).unwrap();
            let reading = sample_with_meter(21);
            outbox.enqueue(&reading).unwrap();
            outbox.save_delivery_diagnostics(&initial).unwrap();
        }
        let reopened = Outbox::open_bounded(&db, 2).unwrap();
        assert_eq!(reopened.pending().unwrap(), 1);
        let stored = reopened.delivery_diagnostics().unwrap();
        assert_eq!(stored.last_error_kind.as_deref(), Some("authorization"));
        assert_eq!(stored.consecutive_failures, 4);
        assert!((1798..=1800).contains(&stored.seconds_until_retry(moment).unwrap()));
        let mut policy = UploadRetryPolicy::default();
        let now = Instant::now();
        policy.restore(now, stored.seconds_until_retry(moment).unwrap(), stored.consecutive_failures);
        assert!(!policy.ready(now));
        assert!(policy.ready(now + Duration::from_secs(1801)));
    }

    #[test]
    fn invalid_saved_delivery_timestamp_does_not_bypass_fail_closed_restore() {
        let diagnostics = DeliveryDiagnostics {
            next_attempt_at: Some("not-a-timestamp".into()),
            ..DeliveryDiagnostics::default()
        };
        assert!(matches!(
            diagnostics.seconds_until_retry(Utc::now()),
            Err(CollectorError::Configuration(_))
        ));
    }

    #[test]
    fn isolates_only_single_reading_confirmed_rejected_and_preserves_the_rest() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("queue.sqlite");
        let good = sample_with_meter(10);
        let bad = sample_with_meter(11);
        let newer = sample_with_meter(12);
        let mut outbox = Outbox::open_bounded(&path, 3).unwrap();
        outbox.enqueue(&good).unwrap();
        outbox.enqueue(&bad).unwrap();
        outbox.enqueue(&newer).unwrap();

        // Simulate the backend's atomic batch 422: a batch containing 'bad'
        // is rejected, even when other readings are legitimate.
        let fake_api = |batch: &[Reading]| -> Result<Vec<Uuid>, CollectorError> {
            if batch.iter().any(|reading| reading.sample_id == bad.sample_id) {
                return Err(CollectorError::HttpStatus {
                    status: 422, retry_after_seconds: None,
                });
            }
            Ok(batch.iter().map(|reading| reading.sample_id).collect())
        };

        for _ in 0..3 {
            let batch = outbox.next_batch(100).unwrap();
            if batch.is_empty() { break; }
            process_upload_batch(&mut outbox, &batch, fake_api).unwrap();
        }
        assert_eq!(outbox.pending().unwrap(), 0);
        assert_eq!(outbox.quarantined_count().unwrap(), 1);
        assert_eq!(outbox.retained().unwrap(), 1);
        let quarantined = outbox.quarantined_readings(10).unwrap();
        assert_eq!(quarantined[0].sample_id, bad.sample_id.to_string());
        assert_eq!(quarantined[0].reason, "individually-rejected-422");

        // No silent data loss, no new data admitted in excess of capacity,
        // and quarantine contents remain recoverable after a process restart.
        drop(outbox);
        let mut reopened = Outbox::open_bounded(&path, 3).unwrap();
        assert_eq!(reopened.quarantined_count().unwrap(), 1);
        assert!(reopened.restore_quarantined(bad.sample_id).unwrap());
        assert_eq!(reopened.next_batch(10).unwrap()[0].sample_id, bad.sample_id);
        assert_eq!(reopened.quarantined_count().unwrap(), 0);
        assert!(!reopened.restore_quarantined(bad.sample_id).unwrap());
    }

    #[test]
    fn quarantine_counts_toward_capacity_and_restoration_does_not_duplicate() {
        let temp = tempfile::tempdir().unwrap();
        let mut outbox = Outbox::open_bounded(temp.path().join("queue.sqlite"), 1).unwrap();
        let bad = sample_with_meter(12);
        outbox.enqueue(&bad).unwrap();
        outbox.quarantine(bad.sample_id, "individually-rejected-422").unwrap();
        assert_eq!(outbox.pending().unwrap(), 0);
        assert_eq!(outbox.retained().unwrap(), 1);
        assert!(matches!(
            outbox.enqueue(&sample_with_meter(13)),
            Err(CollectorError::OutboxFull { limit: 1 })
        ));
        assert!(matches!(outbox.enqueue(&bad), Err(CollectorError::SampleConflict)));
        assert!(outbox.restore_quarantined(bad.sample_id).unwrap());
        assert_eq!(outbox.retained().unwrap(), 1);
        outbox.acknowledge(&[bad.sample_id]).unwrap();
        assert_eq!(outbox.retained().unwrap(), 0);
        assert!(outbox.enqueue(&sample_with_meter(14)).unwrap());
    }

    #[test]
    fn invalid_ack_or_transient_error_never_quarantines_any_reading() {
        let temp = tempfile::tempdir().unwrap();
        let mut outbox = Outbox::open_bounded(temp.path().join("queue.sqlite"), 3).unwrap();
        let sample = sample_with_meter(41);
        outbox.enqueue(&sample).unwrap();
        let samples = outbox.next_batch(10).unwrap();
        let wrong_ack = process_upload_batch(&mut outbox, &samples, |_| Ok(vec![Uuid::new_v4()]));
        assert!(matches!(wrong_ack, Err(CollectorError::InvalidAcknowledgement)));
        let throttled = process_upload_batch(&mut outbox, &samples, |_| {
            Err(CollectorError::HttpStatus { status: 429, retry_after_seconds: Some(60) })
        });
        assert!(matches!(throttled, Err(CollectorError::HttpStatus { status: 429, .. })));
        assert_eq!(outbox.pending().unwrap(), 1);
        assert_eq!(outbox.quarantined_count().unwrap(), 0);
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
            max_pending_payload_bytes: 64 * 1024 * 1024,
            max_sqlite_files_bytes: 512 * 1024 * 1024,
            authorized_subnets: vec!["192.168.1.0/24".into()],
            printers: vec![],
        };
        assert!(cfg.validate().is_err());
    }
}
