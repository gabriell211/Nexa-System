//! Independent liveness signals for the Collector's main and heartbeat workers.
//!
//! API/network success is NOT required to stay alive: only evidence that each
//! worker continues completing its bounded work (or sleeping cooperatively).
//! If either worker stops making progress the OS supervisor can restart the
//! process without modifying the durable SQLite outbox.
use crate::recovery_guard::RecoveryGuard;
use chrono::Utc;
use std::{
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc, Mutex,
    },
    thread,
    time::{Duration, Instant},
};

pub(super) const STALL_TIMEOUT: Duration = Duration::from_secs(180);
const CHECK_INTERVAL: Duration = Duration::from_secs(5);
const WATCHDOG_EXIT_CODE: i32 = 70;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum ProgressState {
    Healthy,
    MainStalled,
    HeartbeatStalled,
    Stopping,
}

#[derive(Debug)]
struct Checkpoints {
    main: Instant,
    heartbeat: Instant,
}

#[derive(Debug)]
pub(super) struct ProgressWatchdog {
    last_progress: Mutex<Checkpoints>,
    stopping: Arc<AtomicBool>,
}

impl ProgressWatchdog {
    pub(super) fn new(stopping: Arc<AtomicBool>) -> Self {
        let now = Instant::now();
        Self {
            last_progress: Mutex::new(Checkpoints { main: now, heartbeat: now }),
            stopping,
        }
    }

    /// Reset both initial timestamps *once* when startup is ready; after
    /// arming only each respective worker may renew its own checkpoint.
    pub(super) fn arm(&self) {
        let now = Instant::now();
        let mut checkpoints = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        checkpoints.main = now;
        checkpoints.heartbeat = now;
    }

    pub(super) fn progressed(&self) {
        let mut checkpoints = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        checkpoints.main = Instant::now();
    }

    /// The heartbeat worker calls this after a completed HTTP attempt and
    /// once per second of its interruptible sleep. Failed/rejected HTTP
    /// requests still count as worker progress, not successful server health.
    pub(super) fn heartbeat_progressed(&self) {
        let mut checkpoints = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        checkpoints.heartbeat = Instant::now();
    }

    fn state_at(&self, now: Instant, timeout: Duration) -> ProgressState {
        if self.stopping.load(Ordering::Acquire) {
            return ProgressState::Stopping;
        }
        let checkpoints = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        if now.saturating_duration_since(checkpoints.main) >= timeout {
            ProgressState::MainStalled
        } else if now.saturating_duration_since(checkpoints.heartbeat) >= timeout {
            ProgressState::HeartbeatStalled
        } else {
            ProgressState::Healthy
        }
    }
}

/// Watchdog only observes state. It cannot checkpoint on its own.
/// A hung worker exits with a nonzero status; existing service policy controls
/// restart/backoff. The watchdog never opens/deletes/alters SQLite.
pub(super) fn start_watchdog(progress: Arc<ProgressWatchdog>, guard: RecoveryGuard) {
    run_watcher(progress, guard, STALL_TIMEOUT, CHECK_INTERVAL);
}

fn run_watcher(
    progress: Arc<ProgressWatchdog>,
    guard: RecoveryGuard,
    timeout: Duration,
    interval: Duration,
) {
    progress.arm();
    thread::spawn(move || loop {
        thread::sleep(interval);
        let kind = match progress.state_at(Instant::now(), timeout) {
            ProgressState::Healthy => continue,
            ProgressState::Stopping => return,
            ProgressState::MainStalled => "main-loop",
            ProgressState::HeartbeatStalled => "heartbeat-worker",
        };
        if progress.stopping.load(Ordering::Acquire) {
            return;
        }
        // Persist the incident before terminating. A failing disk must not
        // block recovery; a corrupt journal causes subsequent boots to halt
        // safely and request manual inspection.
        if let Err(error) = guard.record(kind, Utc::now()) {
            eprintln!("Collector watchdog: incident journal unavailable ({error}); disk may require operator attention");
        }
        eprintln!(
            "Collector watchdog: {kind} stalled; requesting service-manager recovery; SQLite outbox not purged"
        );
        std::process::exit(WATCHDOG_EXIT_CODE);
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::process::Command;

    /// Executes an actual watchdog exit in a subprocess, never the parent
    /// test runner and never a production executable test hook.
    #[test]
    fn watchdog_process_test_child() {
        let Some(queue) = std::env::var_os("NEXA_INTERNAL_WATCHDOG_CHILD_QUEUE") else { return };
        let guard = RecoveryGuard::for_queue(std::path::Path::new(&queue));
        let progress = Arc::new(ProgressWatchdog::new(Arc::new(AtomicBool::new(false))));
        run_watcher(progress, guard, Duration::from_millis(70), Duration::from_millis(10));
        thread::sleep(Duration::from_secs(2));
        panic!("watchdog did not terminate hung child");
    }

    #[test]
    fn induced_hang_exits_nonzero_and_opens_persistent_circuit_after_three_incidents() {
        let temp = tempfile::tempdir().unwrap();
        let queue = temp.path().join("queue.sqlite");
        std::fs::write(&queue, b"do-not-touch-original-queue").unwrap();
        let guard = RecoveryGuard::for_queue(&queue);
        let exe = std::env::current_exe().unwrap();
        for attempt in 1..=3 {
            let child = Command::new(&exe)
                .arg("--exact")
                .arg("progress_watchdog::tests::watchdog_process_test_child")
                .arg("--nocapture")
                .env("NEXA_INTERNAL_WATCHDOG_CHILD_QUEUE", &queue)
                .output().unwrap();
            assert_eq!(child.status.code(), Some(WATCHDOG_EXIT_CODE),
                "induced watchdog hang attempt {attempt} should exit 70; stderr={}",
                String::from_utf8_lossy(&child.stderr));
            let (recent, open, _) = guard.status(Utc::now()).unwrap();
            assert_eq!(recent, attempt);
            assert_eq!(open, attempt >= 3);
            assert_eq!(std::fs::read(&queue).unwrap(), b"do-not-touch-original-queue");
        }
        guard.reset(Utc::now()).unwrap();
        assert!(!guard.circuit_open(Utc::now()).unwrap());
    }


    fn fixed_checkpoint(w: &ProgressWatchdog, now: Instant) {
        let mut state = w.last_progress.lock().unwrap();
        state.main = now;
        state.heartbeat = now;
    }

    #[test]
    fn main_loop_stall_is_not_masked_by_heartbeat_worker_activity() {
        let watcher = ProgressWatchdog::new(Arc::new(AtomicBool::new(false)));
        let before = Instant::now() - Duration::from_secs(200);
        fixed_checkpoint(&watcher, before);
        watcher.heartbeat_progressed();
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::MainStalled);
    }

    #[test]
    fn heartbeat_stall_is_not_masked_by_main_loop_activity() {
        let watcher = ProgressWatchdog::new(Arc::new(AtomicBool::new(false)));
        let before = Instant::now() - Duration::from_secs(200);
        fixed_checkpoint(&watcher, before);
        watcher.progressed();
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::HeartbeatStalled);
    }

    #[test]
    fn exact_stall_boundary_identifies_the_correct_worker() {
        let watcher = ProgressWatchdog::new(Arc::new(AtomicBool::new(false)));
        let start = Instant::now();
        fixed_checkpoint(&watcher, start);
        assert_eq!(watcher.state_at(start + Duration::from_secs(179), STALL_TIMEOUT), ProgressState::Healthy);
        assert_eq!(watcher.state_at(start + Duration::from_secs(180), STALL_TIMEOUT), ProgressState::MainStalled);
        {
            let mut checkpoints = watcher.last_progress.lock().unwrap();
            checkpoints.main = start + Duration::from_secs(180);
        }
        assert_eq!(watcher.state_at(start + Duration::from_secs(180), STALL_TIMEOUT), ProgressState::HeartbeatStalled);
    }

    #[test]
    fn both_workers_refresh_independently() {
        let watcher = ProgressWatchdog::new(Arc::new(AtomicBool::new(false)));
        fixed_checkpoint(&watcher, Instant::now() - Duration::from_secs(200));
        watcher.progressed();
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::HeartbeatStalled);
        watcher.heartbeat_progressed();
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::Healthy);
    }

    #[test]
    fn shutdown_suppresses_both_watchdog_failure_paths() {
        let shutdown = Arc::new(AtomicBool::new(false));
        let watcher = ProgressWatchdog::new(Arc::clone(&shutdown));
        fixed_checkpoint(&watcher, Instant::now() - Duration::from_secs(300));
        shutdown.store(true, Ordering::Release);
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::Stopping);
    }

    #[test]
    fn arming_resets_startup_delays_without_disguising_later_stalls() {
        let watcher = ProgressWatchdog::new(Arc::new(AtomicBool::new(false)));
        fixed_checkpoint(&watcher, Instant::now() - Duration::from_secs(300));
        watcher.arm();
        assert_eq!(watcher.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::Healthy);
    }
}
