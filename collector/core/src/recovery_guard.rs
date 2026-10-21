//! Persistent, local watchdog restart circuit.
//! Only genuine watchdog exits count: ordinary network errors do not.
//! Stored beside the SQLite queue, with no credentials, samples or PII.
use chrono::{DateTime, Duration as ChronoDuration, Utc};
use serde::{Deserialize, Serialize};
use std::{
    fs::{self, File, OpenOptions},
    io::{ErrorKind, Write},
    path::{Path, PathBuf},
};
use uuid::Uuid;

pub const MAX_STALLS: usize = 3;
const WINDOW_MINUTES: i64 = 30;
const MAX_HISTORY: usize = 32;

#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct StallEvent {
    pub at: DateTime<Utc>,
    pub worker: String,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
struct Journal {
    version: u8,
    events: Vec<StallEvent>,
    last_reset_at: Option<DateTime<Utc>>,
}

#[derive(Debug)]
pub enum RecoveryError {
    Io(std::io::Error),
    Invalid(&'static str),
    Json(serde_json::Error),
}

impl std::fmt::Display for RecoveryError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Io(_) => write!(f, "watchdog journal filesystem unavailable"),
            Self::Invalid(message) => write!(f, "watchdog journal invalid: {message}"),
            Self::Json(_) => write!(f, "watchdog journal JSON invalid"),
        }
    }
}
impl std::error::Error for RecoveryError {}
impl From<std::io::Error> for RecoveryError {
    fn from(error: std::io::Error) -> Self { Self::Io(error) }
}
impl From<serde_json::Error> for RecoveryError {
    fn from(error: serde_json::Error) -> Self { Self::Json(error) }
}

/// Sanitized incident history; never includes meter readings, credentials or host paths.
#[derive(Clone, Debug, Serialize)]
pub struct WatchdogSnapshot {
    pub recent_incidents: usize,
    pub circuit_open: bool,
    pub retained_incidents: usize,
    pub threshold: usize,
    pub window_minutes: i64,
    pub last_reset_at: Option<DateTime<Utc>>,
    pub incidents: Vec<StallEvent>,
}

#[derive(Clone, Debug)]
pub struct RecoveryGuard {
    file: PathBuf,
}

impl RecoveryGuard {
    pub fn for_queue(queue: &Path) -> Self {
        let mut name = queue.as_os_str().to_os_string();
        name.push(".watchdog.json");
        Self { file: PathBuf::from(name) }
    }

    fn load(&self) -> Result<Journal, RecoveryError> {
        match fs::symlink_metadata(&self.file) {
            Err(error) if error.kind() == ErrorKind::NotFound => {
                return Ok(Journal { version: 1, ..Journal::default() });
            }
            Err(error) => return Err(error.into()),
            Ok(metadata) if !metadata.file_type().is_file() || metadata.file_type().is_symlink() =>
                return Err(RecoveryError::Invalid("not a regular file")),
            Ok(metadata) if metadata.len() > 16 * 1024 =>
                return Err(RecoveryError::Invalid("file exceeds size limit")),
            Ok(_) => {}
        }
        let journal: Journal = serde_json::from_slice(&fs::read(&self.file)?)?;
        if journal.version != 1 || journal.events.len() > MAX_HISTORY {
            return Err(RecoveryError::Invalid("unsupported version or oversized event list"));
        }
        for event in &journal.events {
            if !matches!(event.worker.as_str(), "main-loop" | "heartbeat-worker") {
                return Err(RecoveryError::Invalid("unknown watchdog worker"));
            }
        }
        Ok(journal)
    }

    fn count_recent(journal: &Journal, now: DateTime<Utc>) -> usize {
        let since = now - ChronoDuration::minutes(WINDOW_MINUTES);
        journal.events.iter().filter(|e| {
            e.at > since && e.at <= now
                && journal.last_reset_at.is_none_or(|reset| e.at > reset)
        }).count()
    }

    fn validate_clock(journal: &Journal, now: DateTime<Utc>) -> Result<(), RecoveryError> {
        // count_recent excludes timestamps after `now`, and a future reset
        // suppresses earlier incidents. No grace period is safe here: even a
        // short clock rollback could reopen the circuit.
        if journal.events.iter().any(|event| event.at > now)
            || journal.last_reset_at.is_some_and(|reset| reset > now)
        {
            return Err(RecoveryError::Invalid("journal timestamp lies in the future"));
        }
        Ok(())
    }

    /// Read-only projection shared by the daemon, Tauri console and optional
    /// heartbeat telemetry. Invalid journal data fails closed.
    pub fn snapshot(&self, now: DateTime<Utc>) -> Result<WatchdogSnapshot, RecoveryError> {
        let journal = self.load()?;
        Self::validate_clock(&journal, now)?;
        let recent = Self::count_recent(&journal, now);
        let retained = journal.events.len();
        Ok(WatchdogSnapshot {
            recent_incidents: recent,
            circuit_open: recent >= MAX_STALLS,
            retained_incidents: retained,
            threshold: MAX_STALLS,
            window_minutes: WINDOW_MINUTES,
            last_reset_at: journal.last_reset_at,
            incidents: journal.events,
        })
    }

    pub fn status(&self, now: DateTime<Utc>) -> Result<(usize, bool, usize), RecoveryError> {
        let journal = self.load()?;
        // Clock rollback or tampered future dates must never bypass the
        // circuit; refuse startup until the operator reviews the journal.
        Self::validate_clock(&journal, now)?;
        let recent = Self::count_recent(&journal, now);
        Ok((recent, recent >= MAX_STALLS, journal.events.len()))
    }

    /// On a repeatedly hanging service, stop successfully to prevent macOS
    /// launchd (or SCM/systemd) from endlessly respawning. Requires operator
    /// acknowledgement and/or later explicit service startup.
    pub fn circuit_open(&self, now: DateTime<Utc>) -> Result<bool, RecoveryError> {
        Ok(self.status(now)?.1)
    }

    pub fn record(&self, worker: &str, now: DateTime<Utc>) -> Result<(), RecoveryError> {
        if !matches!(worker, "main-loop" | "heartbeat-worker") {
            return Err(RecoveryError::Invalid("unknown watchdog worker"));
        }
        let _lock = self.lock_writer()?;
        let mut journal = self.load()?;
        journal.events.push(StallEvent { at: now, worker: worker.into() });
        // Bounded diagnostic history: recent incidents survive operator reset.
        if journal.events.len() > MAX_HISTORY {
            journal.events.drain(..journal.events.len() - MAX_HISTORY);
        }
        self.write(&journal)
    }

    pub fn reset(&self, now: DateTime<Utc>) -> Result<(), RecoveryError> {
        let _lock = self.lock_writer()?;
        let mut journal = self.load()?;
        journal.last_reset_at = Some(now);
        self.write(&journal)
    }

    fn lock_writer(&self) -> Result<File, RecoveryError> {
        // The lock has a stable inode/path, unlike the atomically replaced
        // journal. Keep it open across the complete read-modify-write cycle so
        // a daemon incident cannot race an operator acknowledgement or a
        // second writer and erase a committed event.
        let mut name = self.file.as_os_str().to_os_string();
        name.push(".lock");
        let lock_path = PathBuf::from(name);
        match fs::symlink_metadata(&lock_path) {
            Ok(meta) if !meta.file_type().is_file() || meta.file_type().is_symlink() =>
                return Err(RecoveryError::Invalid("writer lock is not a regular file")),
            Ok(_) => {},
            Err(error) if error.kind() == ErrorKind::NotFound => {},
            Err(error) => return Err(error.into()),
        }
        let mut options = OpenOptions::new();
        options.read(true).write(true).create(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let file = options.open(lock_path)?;
        if !file.metadata()?.file_type().is_file() {
            return Err(RecoveryError::Invalid("writer lock is not a regular file"));
        }
        file.lock()?;
        Ok(file)
    }

    fn write(&self, journal: &Journal) -> Result<(), RecoveryError> {
        // Regular file only; an invalid/symlinked journal must never be
        // silently replaced. The containing OS service directory is private.
        match fs::symlink_metadata(&self.file) {
            Ok(meta) if !meta.file_type().is_file() || meta.file_type().is_symlink() =>
                return Err(RecoveryError::Invalid("not a regular file")),
            Ok(_) => {},
            Err(error) if error.kind() == ErrorKind::NotFound => {},
            Err(error) => return Err(error.into()),
        }
        let encoded = serde_json::to_vec(journal)?;
        // The old journal stays intact until the complete replacement is on
        // disk. A unique, create_new file in the same directory avoids both
        // partial readers and accidental traversal of a stale temp symlink.
        let mut temp_name = self.file.as_os_str().to_os_string();
        temp_name.push(format!(".tmp.{}", Uuid::new_v4()));
        let temp_path = PathBuf::from(temp_name);
        let mut options = OpenOptions::new();
        options.write(true).create_new(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let result = (|| -> Result<(), RecoveryError> {
            let mut file = options.open(&temp_path)?;
            file.write_all(&encoded)?;
            file.sync_all()?;
            drop(file); // Windows cannot replace an open file.
            // Recheck immediately before replacement so a journal changed to
            // a symlink or directory during the write is never overwritten.
            match fs::symlink_metadata(&self.file) {
                Ok(meta) if !meta.file_type().is_file() || meta.file_type().is_symlink() =>
                    return Err(RecoveryError::Invalid("not a regular file")),
                Ok(_) => {},
                Err(error) if error.kind() == ErrorKind::NotFound => {},
                Err(error) => return Err(error.into()),
            }
            fs::rename(&temp_path, &self.file)?;
            #[cfg(unix)]
            {
                // Persist the directory entry as well as the JSON contents.
                let parent = self.file.parent()
                    .filter(|path| !path.as_os_str().is_empty())
                    .unwrap_or(Path::new("."));
                fs::File::open(parent)?.sync_all()?;
            }
            Ok(())
        })();
        if result.is_err() {
            let _ = fs::remove_file(&temp_path);
        }
        result
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{process::Command, thread, time::{Duration, Instant}};

    #[test]
    fn repeated_watchdog_exits_open_circuit_without_erasing_history() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        let now = Utc::now();
        assert_eq!(guard.status(now).unwrap(), (0, false, 0));
        for i in 0..3 {
            let worker = if i % 2 == 0 { "main-loop" } else { "heartbeat-worker" };
            guard.record(worker, now - ChronoDuration::seconds(3 - i)).unwrap();
        }
        assert_eq!(guard.status(now).unwrap(), (3, true, 3));
        guard.reset(now).unwrap();
        assert_eq!(guard.status(now).unwrap(), (0, false, 3));
        guard.record("heartbeat-worker", now + ChronoDuration::seconds(1)).unwrap();
        assert_eq!(guard.status(now + ChronoDuration::seconds(1)).unwrap(), (1, false, 4));
    }

    #[test]
    fn read_only_snapshot_redacts_payloads_and_preserves_operator_acknowledgement() {
        let tmp = tempfile::tempdir().unwrap();
        let path = tmp.path().join("queue.sqlite");
        let guard = RecoveryGuard::for_queue(&path);
        let now = Utc::now();
        guard.record("main-loop", now - ChronoDuration::minutes(1)).unwrap();
        guard.record("heartbeat-worker", now - ChronoDuration::seconds(1)).unwrap();
        let view = guard.snapshot(now).unwrap();
        assert_eq!((view.recent_incidents, view.retained_incidents, view.circuit_open), (2, 2, false));
        assert_eq!(view.incidents[0].worker, "main-loop");
        assert_eq!(view.incidents[1].worker, "heartbeat-worker");
        let encoded = serde_json::to_string(&view).unwrap();
        assert!(!encoded.contains("queue.sqlite"));
        assert!(!encoded.contains("token"));
        guard.reset(now).unwrap();
        let after = guard.snapshot(now).unwrap();
        assert_eq!((after.recent_incidents, after.retained_incidents), (0, 2));
        assert_eq!(after.last_reset_at, Some(now));
        assert!(!after.circuit_open);
    }

    #[test]
    fn read_only_snapshot_rejects_journal_tampering_and_future_clock() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        let now = Utc::now();
        guard.record("main-loop", now + ChronoDuration::minutes(15)).unwrap();
        assert!(guard.snapshot(now).is_err());
        fs::write(&guard.file, b"{bad").unwrap();
        assert!(guard.snapshot(now).is_err());
    }

    #[test]
    fn window_expiry_disarms_old_incidents() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("db.sqlite"));
        let now = Utc::now();
        for minutes in [33, 32, 31] {
            guard.record("main-loop", now - ChronoDuration::minutes(minutes)).unwrap();
        }
        assert_eq!(guard.status(now).unwrap(), (0, false, 3));
    }

    #[test]
    fn corrupted_journal_fails_closed_instead_of_resetting_counter() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        fs::write(&guard.file, b"{invalid").unwrap();
        assert!(matches!(guard.circuit_open(Utc::now()), Err(RecoveryError::Json(_))));
        assert!(guard.reset(Utc::now()).is_err());
    }

    #[test]
    fn future_incident_timestamps_fail_closed_on_clock_rollback() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        let now = Utc::now();
        guard.record("main-loop", now + ChronoDuration::minutes(1)).unwrap();
        assert!(matches!(guard.circuit_open(now), Err(RecoveryError::Invalid(_))));
    }

    #[test]
    fn future_reset_cannot_suppress_the_watchdog_circuit() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        let now = Utc::now();
        for _ in 0..MAX_STALLS {
            guard.record("main-loop", now - ChronoDuration::minutes(1)).unwrap();
        }
        assert!(guard.circuit_open(now).unwrap());
        guard.reset(now + ChronoDuration::minutes(1)).unwrap();
        assert!(matches!(guard.circuit_open(now), Err(RecoveryError::Invalid(_))));
        assert!(matches!(guard.snapshot(now), Err(RecoveryError::Invalid(_))));
    }

    #[test]
    fn rejects_unknown_event_causes_without_modifying_files() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        assert!(guard.record("malformed", Utc::now()).is_err());
        assert!(!guard.file.exists());
    }

    #[test]
    fn incident_journal_is_separate_from_sqlite_and_contains_no_readings() {
        let tmp = tempfile::tempdir().unwrap();
        let queue = tmp.path().join("queue.sqlite");
        fs::write(&queue, b"unaltered-content").unwrap();
        let guard = RecoveryGuard::for_queue(&queue);
        guard.record("heartbeat-worker", Utc::now()).unwrap();
        assert_eq!(fs::read(&queue).unwrap(), b"unaltered-content");
        let recorded = fs::read_to_string(&guard.file).unwrap();
        assert!(recorded.contains("heartbeat-worker"));
        assert!(!recorded.contains("unaltered-content"));
    }

    #[test]
    fn concurrent_console_reads_see_only_complete_journals() {
        let tmp = tempfile::tempdir().unwrap();
        let guard = RecoveryGuard::for_queue(&tmp.path().join("queue.sqlite"));
        let reader = guard.clone();
        let now = Utc::now();
        let writer = std::thread::spawn(move || {
            for _ in 0..128 {
                guard.record("main-loop", now).unwrap();
            }
        });
        for _ in 0..512 {
            let snapshot = reader.snapshot(now).unwrap();
            assert!(snapshot.retained_incidents <= MAX_HISTORY);
        }
        writer.join().unwrap();
        assert_eq!(reader.snapshot(now).unwrap().retained_incidents, MAX_HISTORY);
        let leftovers: Vec<_> = fs::read_dir(tmp.path()).unwrap()
            .map(|entry| entry.unwrap().file_name())
            .filter(|name| name.to_string_lossy().contains(".tmp."))
            .collect();
        assert!(leftovers.is_empty());
    }

    #[test]
    fn concurrent_writer_process_child() {
        let Some(queue) = std::env::var_os("NEXA_INTERNAL_WATCHDOG_WRITER_QUEUE") else { return };
        let mode = std::env::var("NEXA_INTERNAL_WATCHDOG_WRITER_MODE").unwrap();
        let queue = PathBuf::from(queue);
        let directory = queue.parent().unwrap();
        fs::write(directory.join(format!("ready-{mode}")), b"ready").unwrap();
        // Windows CI can delay spawning subprocesses while compiling and
        // scanning executables. Keep a bounded wait without hiding deadlocks.
        let deadline = Instant::now() + Duration::from_secs(if cfg!(windows) { 90 } else { 30 });
        while !directory.join("go").exists() {
            assert!(Instant::now() < deadline, "concurrent writer start timed out");
            thread::sleep(Duration::from_millis(10));
        }
        let guard = RecoveryGuard::for_queue(&queue);
        for completed in 1..=8 {
            if mode == "reset" {
                guard.reset(Utc::now()).unwrap();
            } else {
                guard.record(&mode, Utc::now()).unwrap();
            }
            // Independent, test-only progress markers identify precisely
            // which process is blocked if the bounded test times out.
            fs::write(directory.join(format!("progress-{mode}")), completed.to_string()).unwrap();
        }
    }

    #[test]
    fn concurrent_process_writers_preserve_all_events_and_reset() {
        let tmp = tempfile::tempdir().unwrap();
        let queue = tmp.path().join("queue.sqlite");
        let executable = std::env::current_exe().unwrap();
        let modes = ["main-loop", "heartbeat-worker", "reset"];
        let mut children: Vec<_> = modes.iter().map(|mode| {
            Command::new(&executable)
                .arg("--exact")
                .arg("recovery_guard::tests::concurrent_writer_process_child")
                .env("NEXA_INTERNAL_WATCHDOG_WRITER_QUEUE", &queue)
                .env("NEXA_INTERNAL_WATCHDOG_WRITER_MODE", mode)
                .spawn().unwrap()
        }).collect();
        let timeout = Duration::from_secs(if cfg!(windows) { 90 } else { 30 });
        let deadline = Instant::now() + timeout;
        while modes.iter().any(|mode| !tmp.path().join(format!("ready-{mode}")).exists()) {
            for (index, child) in children.iter_mut().enumerate() {
                if let Some(status) = child.try_wait().unwrap() {
                    panic!("watchdog writer {} exited before starting: {status}", modes[index]);
                }
            }
            if Instant::now() >= deadline {
                let missing: Vec<_> = modes.iter()
                    .filter(|mode| !tmp.path().join(format!("ready-{mode}")).exists())
                    .copied().collect();
                for child in &mut children { let _ = child.kill(); }
                for child in &mut children { let _ = child.wait(); }
                panic!("concurrent writer children did not become ready: {missing:?}");
            }
            thread::sleep(Duration::from_millis(10));
        }
        fs::write(tmp.path().join("go"), b"go").unwrap();

        // Poll all children instead of blocking on the first, so a failed
        // writer is reported immediately and no orphan keeps the lock alive.
        let finish_deadline = Instant::now() + timeout;
        let mut finished = [false; 3];
        loop {
            for (index, child) in children.iter_mut().enumerate() {
                if finished[index] { continue; }
                if let Some(status) = child.try_wait().unwrap() {
                    if !status.success() {
                        for child in &mut children { let _ = child.kill(); }
                        for child in &mut children { let _ = child.wait(); }
                        panic!("concurrent writer {} failed: {status}", modes[index]);
                    }
                    finished[index] = true;
                }
            }
            if finished.iter().all(|done| *done) { break; }
            if Instant::now() >= finish_deadline {
                let progress: Vec<_> = modes.iter().enumerate().map(|(index, mode)| {
                    let marker = tmp.path().join(format!("progress-{mode}"));
                    let completed = fs::read_to_string(marker).unwrap_or_else(|_| "0".into());
                    format!("{mode}: {completed}/8 writes, exited={}", finished[index])
                }).collect();
                for child in &mut children { let _ = child.kill(); }
                for child in &mut children { let _ = child.wait(); }
                panic!("concurrent writer child timed out after {timeout:?}: {progress:?}");
            }
            thread::sleep(Duration::from_millis(10));
        }
        let snapshot = RecoveryGuard::for_queue(&queue).snapshot(Utc::now()).unwrap();
        assert_eq!(snapshot.retained_incidents, 16);
        assert_eq!(snapshot.incidents.iter().filter(|e| e.worker == "main-loop").count(), 8);
        assert_eq!(snapshot.incidents.iter().filter(|e| e.worker == "heartbeat-worker").count(), 8);
        assert!(snapshot.last_reset_at.is_some());
    }
}
