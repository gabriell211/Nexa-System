//! Foreground launchd process. launchd supervises exits, not blocked loops.
//! All credentials are supplied as pre-provisioned root-owned files.
use std::{
    error::Error,
    path::PathBuf,
    sync::{atomic::AtomicBool, Arc},
};

use signal_hook::consts::signal::{SIGINT, SIGTERM};

const BASE_DIR: &str = "/Library/Application Support/NexaCollector";
const DAEMON_PATH: &str = "/Library/PrivilegedHelperTools/NexaCollector/nexa-collector-daemon";

pub(super) fn arguments() -> Vec<String> {
    let base = PathBuf::from(BASE_DIR);
    vec![
        DAEMON_PATH.to_owned(),
        base.join("config.json").to_string_lossy().into_owned(),
        base.join("data").join("queue.sqlite").to_string_lossy().into_owned(),
    ]
}

/// launchd sends SIGTERM on bootout; flag handlers avoid performing IO
/// from within a signal handler and allow SQLite to close on normal return.
pub(super) fn dispatch() -> Result<(), Box<dyn Error>> {
    let stop = Arc::new(AtomicBool::new(false));
    signal_hook::flag::register(SIGTERM, Arc::clone(&stop))?;
    signal_hook::flag::register(SIGINT, Arc::clone(&stop))?;
    super::run_application(arguments(), stop, || Ok(()), false, true)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn launchd_arguments_use_only_deterministic_local_paths() {
        let args = arguments();
        assert_eq!(args.len(), 3);
        assert_eq!(args[0], DAEMON_PATH);
        assert_eq!(args[1], "/Library/Application Support/NexaCollector/config.json");
        assert_eq!(args[2], "/Library/Application Support/NexaCollector/data/queue.sqlite");
        assert!(!args.iter().any(|arg| arg.contains("TOKEN") || arg.contains("KEY")));
    }
}
