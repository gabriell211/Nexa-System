//! Native Windows Service Control Manager integration.
//! Runs only when SCM starts the binary with --service. CLI mode is unchanged.
use std::{
    ffi::OsString,
    path::PathBuf,
    sync::{atomic::{AtomicBool, Ordering}, Arc},
    time::Duration,
};
use windows_service::{
    define_windows_service,
    service::{
        ServiceControl, ServiceControlAccept, ServiceExitCode, ServiceState,
        ServiceStatus, ServiceType,
    },
    service_control_handler::{self, ServiceControlHandlerResult},
    service_dispatcher,
};

const SERVICE_NAME: &str = "NexaCollector";
define_windows_service!(ffi_service_main, service_main);

pub fn dispatch() -> Result<(), Box<dyn std::error::Error>> {
    service_dispatcher::start(SERVICE_NAME, ffi_service_main)?;
    Ok(())
}

fn service_main(_args: Vec<OsString>) {
    if let Err(error) = run_service() {
        // Never print tokens, SQLite payloads or Windows credential contents.
        eprintln!("Collector Windows service lifecycle error: {error}");
    }
}

fn status(state: ServiceState, success: bool) -> ServiceStatus {
    ServiceStatus {
        service_type: ServiceType::OWN_PROCESS,
        current_state: state,
        controls_accepted: if state == ServiceState::Running {
            ServiceControlAccept::STOP
        } else {
            ServiceControlAccept::empty()
        },
        exit_code: if success {
            ServiceExitCode::Win32(0)
        } else {
            ServiceExitCode::Win32(1)
        },
        checkpoint: if state == ServiceState::StartPending { 1 } else { 0 },
        wait_hint: if state == ServiceState::StartPending {
            Duration::from_secs(60)
        } else {
            Duration::default()
        },
        process_id: None,
    }
}

fn service_paths() -> Result<Vec<String>, Box<dyn std::error::Error>> {
    let program_data = std::env::var_os("ProgramData")
        .ok_or("ProgramData environment is unavailable")?;
    let root = PathBuf::from(program_data).join("NexaCollector");
    let config = root.join("config.json");
    let queue = root.join("data").join("queue.sqlite");
    Ok(vec![
        std::env::current_exe()?.to_string_lossy().into_owned(),
        config.to_string_lossy().into_owned(),
        queue.to_string_lossy().into_owned(),
    ])
}

fn run_service() -> windows_service::Result<()> {
    let shutdown = Arc::new(AtomicBool::new(false));
    let handler_shutdown = Arc::clone(&shutdown);
    let event_handler = move |event| match event {
        ServiceControl::Stop => {
            handler_shutdown.store(true, Ordering::SeqCst);
            ServiceControlHandlerResult::NoError
        }
        ServiceControl::Interrogate => ServiceControlHandlerResult::NoError,
        _ => ServiceControlHandlerResult::NotImplemented,
    };
    let handle = service_control_handler::register(SERVICE_NAME, event_handler)?;
    handle.set_service_status(status(ServiceState::StartPending, true))?;

    let result = service_paths().and_then(|args| {
        super::run_application(
            args, shutdown,
            || {
                handle.set_service_status(status(ServiceState::Running, true))?;
                Ok(())
            },
            true,
        )
    });

    let succeeded = result.is_ok();
    if let Err(ref error) = result {
        // Errors are classified by the daemon; avoid replaying sensitive
        // payloads or HTTP response content in the Windows event log.
        eprintln!("NexaCollector failed to initialize or run: {error}");
    }
    handle.set_service_status(status(ServiceState::Stopped, succeeded))?;
    Ok(())
}
