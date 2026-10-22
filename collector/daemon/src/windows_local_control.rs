//! Privileged local Windows named pipe. Restricts connection via DACL to
//! LocalSystem and elevated Builtin Administrators, rejecting remote clients.
//! Never grants Everyone/Users access or exposes a TCP control endpoint.
use nexa_collector_core::{
    local_control::{ControlRequest, FleetControlRequest, BroadcastControlRequest, ControlResponse, MAX_MESSAGE_BYTES, PROTOCOL_VERSION, WINDOWS_PIPE_NAME},
    CollectorConfig, Outbox,
};
use std::{ffi::c_void, io, ptr::{null, null_mut}, path::PathBuf,
    sync::{atomic::{AtomicBool, Ordering}, Arc}, thread::{self, JoinHandle}};
use windows_sys::Win32::{
    Foundation::{CloseHandle, GetLastError, LocalFree, ERROR_PIPE_CONNECTED, INVALID_HANDLE_VALUE, HANDLE},
    Security::{SECURITY_ATTRIBUTES},
    Security::Authorization::ConvertStringSecurityDescriptorToSecurityDescriptorW,
    Storage::FileSystem::{ReadFile, WriteFile, FlushFileBuffers, PIPE_ACCESS_DUPLEX, FILE_FLAG_FIRST_PIPE_INSTANCE},
    System::Pipes::{
        CreateNamedPipeW, ConnectNamedPipe, DisconnectNamedPipe,
        PIPE_TYPE_MESSAGE, PIPE_READMODE_MESSAGE,
        PIPE_WAIT, PIPE_REJECT_REMOTE_CLIENTS,
    },
};
use zeroize::Zeroizing;

const ACCESS_SDDL: &str = "D:P(A;;GA;;;SY)(A;;GA;;;BA)";
const MAX_MESSAGE: usize = MAX_MESSAGE_BYTES as usize;

// A named pipe is protected by its Win32 DACL; filesystem folder
// permissions do not govern \\.\\pipe\\ objects.
struct Handle(HANDLE);
// SAFETY: Win32 kernel HANDLEs can be moved across threads; this wrapper
// retains exclusive ownership and calls CloseHandle exactly once.
unsafe impl Send for Handle {}
impl Drop for Handle {
    fn drop(&mut self) { unsafe { CloseHandle(self.0); } }
}

fn create_pipe() -> io::Result<Handle> {
    let name: Vec<u16> = WINDOWS_PIPE_NAME.encode_utf16().chain(Some(0)).collect();
    let sddl: Vec<u16> = ACCESS_SDDL.encode_utf16().chain(Some(0)).collect();
    let mut descriptor: *mut c_void = null_mut();
    // SAFETY: SDDL and output pointers are valid for this OS call.
    if unsafe {
        ConvertStringSecurityDescriptorToSecurityDescriptorW(
            sddl.as_ptr(), 1, &mut descriptor, null_mut(),
        )
    } == 0 {
        return Err(io::Error::last_os_error());
    }
    let attributes = SECURITY_ATTRIBUTES {
        nLength: std::mem::size_of::<SECURITY_ATTRIBUTES>() as u32,
        lpSecurityDescriptor: descriptor,
        bInheritHandle: 0,
    };
    // SAFETY: the pipe copies the security descriptor; local memory is then freed.
    let pipe = unsafe {
        CreateNamedPipeW(
            name.as_ptr(), PIPE_ACCESS_DUPLEX | FILE_FLAG_FIRST_PIPE_INSTANCE,
            PIPE_TYPE_MESSAGE | PIPE_READMODE_MESSAGE | PIPE_WAIT | PIPE_REJECT_REMOTE_CLIENTS,
            1, MAX_MESSAGE_BYTES as u32, MAX_MESSAGE_BYTES as u32, 5000,
            &attributes,
        )
    };
    unsafe { LocalFree(descriptor); }
    if pipe == INVALID_HANDLE_VALUE {
        Err(io::Error::last_os_error())
    } else {
        Ok(Handle(pipe))
    }
}

fn handle_connection(pipe: HANDLE, config: &CollectorConfig, queue: &PathBuf, key: &str, community: Option<&str>) {
    let accepted = unsafe { ConnectNamedPipe(pipe, null_mut()) };
    if accepted == 0 && unsafe { GetLastError() } != ERROR_PIPE_CONNECTED {
        return;
    }
    let mut buffer = [0_u8; MAX_MESSAGE + 1];
    let mut read = 0_u32;
    let result = unsafe {
        ReadFile(pipe, buffer.as_mut_ptr(), buffer.len() as u32,
            &mut read, null_mut())
    };
    let response = if result == 0 || read as usize > MAX_MESSAGE {
        ControlResponse { accepted: false, reason: Some("Mensagem local inválida.".into()) }
    } else if let Ok(request) = serde_json::from_slice::<ControlRequest>(&buffer[..read as usize]) {
        if request.version != PROTOCOL_VERSION {
            ControlResponse { accepted: false, reason: Some("Versão de protocolo inválida.".into()) }
        } else {
            match Outbox::open_encrypted_disk_guard(
                queue, config.max_pending_samples, config.max_pending_payload_bytes,
                config.max_sqlite_files_bytes, key,
            ).and_then(|mut outbox| outbox.review_candidate(request.ip, request.action)) {
                Ok(_) => ControlResponse { accepted: true, reason: None },
                Err(_) => ControlResponse {
                    accepted: false,
                    reason: Some("Candidato indisponível, inválido ou descoberta desatualizada.".into()),
                },
            }
        }
    } else if let Ok(request) = serde_json::from_slice::<FleetControlRequest>(&buffer[..read as usize]) {
        if request.version != PROTOCOL_VERSION {
            ControlResponse { accepted: false, reason: Some("Versão de protocolo inválida.".into()) }
        } else {
            match Outbox::open_encrypted_disk_guard(
                queue, config.max_pending_samples, config.max_pending_payload_bytes,
                config.max_sqlite_files_bytes, key,
            ) {
                Ok(mut outbox) => match crate::printer_control::execute(
                    &mut outbox, config, &request.command, community,
                ) {
                    Ok(_) => ControlResponse { accepted: true, reason: None },
                    Err(reason) => ControlResponse { accepted: false, reason: Some(reason) },
                },
                Err(_) => ControlResponse { accepted: false, reason: Some("Armazenamento protegido indisponível.".into()) },
            }
        }
    } else if let Ok(request) = serde_json::from_slice::<BroadcastControlRequest>(&buffer[..read as usize]) {
        if request.version != PROTOCOL_VERSION {
            ControlResponse { accepted: false, reason: Some("Versão de protocolo inválida.".into()) }
        } else {
            match Outbox::open_encrypted_disk_guard(
                queue, config.max_pending_samples, config.max_pending_payload_bytes,
                config.max_sqlite_files_bytes, key,
            ) {
                Ok(outbox) => match crate::broadcast_control::execute(
                    &outbox, config, &request.cidrs, community,
                ) {
                    Ok(_) => ControlResponse { accepted: true, reason: None },
                    Err(reason) => ControlResponse { accepted: false, reason: Some(reason) },
                },
                Err(_) => ControlResponse { accepted: false, reason: Some("Armazenamento protegido indisponível.".into()) },
            }
        }
    } else {
        ControlResponse { accepted: false, reason: Some("Comando não permitido.".into()) }
    };
    let output = serde_json::to_vec(&response).unwrap_or_default();
    let mut written = 0_u32;
    // SAFETY: pipe remains valid through the single request and response.
    unsafe {
        WriteFile(pipe, output.as_ptr() as _, output.len() as u32, &mut written, null_mut());
        FlushFileBuffers(pipe);
        DisconnectNamedPipe(pipe);
    }
}

pub fn start(
    queue: PathBuf, config: CollectorConfig, key: Zeroizing<String>,
    community: Option<Zeroizing<String>>, shutdown: Arc<AtomicBool>,
) -> io::Result<JoinHandle<()>> {
    // Create the first instance before returning to detect invalid DACLs,
    // occupied pipe names and unsupported OS capabilities at startup.
    let first = create_pipe()?;
    Ok(thread::spawn(move || {
        let mut pipe = first;
        while !shutdown.load(Ordering::Relaxed) {
            handle_connection(pipe.0, &config, &queue, &key, community.as_deref());
            drop(pipe);
            if shutdown.load(Ordering::Relaxed) { break; }
            match create_pipe() {
                Ok(next) => pipe = next,
                Err(_) => break,
            }
        }
        // Blocking ConnectNamedPipe is terminated when the service process
        // exits; the worker is never detached to a different long-lived host.
    }))
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn sddl_restricts_pipe_to_system_and_administrators() {
        assert_eq!(ACCESS_SDDL, "D:P(A;;GA;;;SY)(A;;GA;;;BA)");
        assert!(WINDOWS_PIPE_NAME.starts_with(r"\\.\pipe\"));
        assert!(MAX_MESSAGE_BYTES <= 512);
    }
}
