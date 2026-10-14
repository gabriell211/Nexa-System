//! Local liveness supervision for the *main collector loop*.
//!
//! This is intentionally independent of collector-to-API heartbeat traffic:
//! being able to send heartbeat does not prove that SNMP collection and the
//! durable outbox can make progress. Only supervised services arm the watchdog.
//! A stalled process exits with a nonzero status; systemd/SCM/launchd then apply
//! their existing bounded restart policies. Nothing is removed from SQLite.
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
    Stalled,
    Stopping,
}

#[derive(Debug)]
pub(super) struct ProgressWatchdog {
    last_progress: Mutex<Instant>,
    stopping: Arc<AtomicBool>,
}

impl ProgressWatchdog {
    pub(super) fn new(stopping: Arc<AtomicBool>) -> Self {
        Self {
            last_progress: Mutex::new(Instant::now()),
            stopping,
        }
    }

    /// Only the foreground collection/delivery loop is allowed to acknowledge
    /// useful progress; the watcher thread itself must never refresh this.
    pub(super) fn progressed(&self) {
        let mut last = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        *last = Instant::now();
    }

    fn state_at(&self, now: Instant, timeout: Duration) -> ProgressState {
        if self.stopping.load(Ordering::Acquire) {
            return ProgressState::Stopping;
        }
        let last = self.last_progress.lock().unwrap_or_else(|err| err.into_inner());
        if now.saturating_duration_since(*last) >= timeout {
            ProgressState::Stalled
        } else {
            ProgressState::Healthy
        }
    }
}

/// Deliberately terminates the hung process instead of calling SQLite code
/// from a second thread; the already-configured OS service manager is
/// responsible for bounded retries. SQLite WAL remains authoritative.
pub(super) fn start_watchdog(shutdown: Arc<AtomicBool>) -> Arc<ProgressWatchdog> {
    let progress = Arc::new(ProgressWatchdog::new(shutdown));
    let watcher = Arc::clone(&progress);
    thread::spawn(move || loop {
        thread::sleep(CHECK_INTERVAL);
        match watcher.state_at(Instant::now(), STALL_TIMEOUT) {
            ProgressState::Healthy => {}
            ProgressState::Stopping => return,
            ProgressState::Stalled => {
                // Recheck the shutdown flag just before the forced exit.
                if watcher.stopping.load(Ordering::Acquire) {
                    return;
                }
                eprintln!(
                    "Collector watchdog: main loop stalled beyond 180 seconds; exiting for service-manager recovery; outbox not purged"
                );
                std::process::exit(WATCHDOG_EXIT_CODE);
            }
        }
    });
    progress
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn watchdog_detects_real_main_loop_stall_not_heartbeat_activity() {
        let stopping = Arc::new(AtomicBool::new(false));
        let watchdog = ProgressWatchdog::new(stopping);
        let start = Instant::now();
        {
            let mut last = watchdog.last_progress.lock().unwrap();
            *last = start;
        }
        assert_eq!(watchdog.state_at(start + Duration::from_secs(179), STALL_TIMEOUT), ProgressState::Healthy);
        assert_eq!(watchdog.state_at(start + Duration::from_secs(180), STALL_TIMEOUT), ProgressState::Stalled);
        assert_eq!(watchdog.state_at(start + Duration::from_secs(240), STALL_TIMEOUT), ProgressState::Stalled);
    }

    #[test]
    fn shutdown_always_prevents_forced_restart() {
        let stopping = Arc::new(AtomicBool::new(false));
        let watchdog = ProgressWatchdog::new(Arc::clone(&stopping));
        let before = Instant::now();
        {
            let mut last = watchdog.last_progress.lock().unwrap();
            *last = before;
        }
        stopping.store(true, Ordering::Release);
        assert_eq!(watchdog.state_at(before + Duration::from_secs(500), STALL_TIMEOUT), ProgressState::Stopping);
    }

    #[test]
    fn progress_resets_stall_timer_and_time_source_is_monotonic() {
        let stopping = Arc::new(AtomicBool::new(false));
        let watchdog = ProgressWatchdog::new(stopping);
        let past = Instant::now() - Duration::from_secs(200);
        {
            let mut last = watchdog.last_progress.lock().unwrap();
            *last = past;
        }
        assert_eq!(watchdog.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::Stalled);
        watchdog.progressed();
        assert_eq!(watchdog.state_at(Instant::now(), STALL_TIMEOUT), ProgressState::Healthy);
    }
}
