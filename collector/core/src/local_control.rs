//! Local admin protocol: private Unix sockets and experimental Windows named pipe.
//! Windows control is disabled by default pending field validation.
//! Messages contain no credentials and are never forwarded to the cloud.
use crate::candidate_review::ReviewAction;
use crate::fleet::FleetCommand;
use crate::DiscoverySchedule;
use serde::{Deserialize, Serialize};
use std::{net::Ipv4Addr, path::{Path, PathBuf}};

pub const PROTOCOL_VERSION: u8 = 1;
pub const MAX_MESSAGE_BYTES: u64 = 512;

#[cfg(windows)]
pub const WINDOWS_PIPE_NAME: &str = r"\\.\pipe\NexaCollector.Control.v1";

#[cfg(windows)]
pub fn submit_windows(ip: Ipv4Addr, action: ReviewAction) -> Result<(), String> {
    let payload = serde_json::to_vec(&ControlRequest {
        version: PROTOCOL_VERSION, ip, action,
    }).map_err(|_| "Requisição local inválida.".to_owned())?;
    submit_windows_payload(&payload)
}

#[cfg(windows)]
fn submit_windows_payload(payload: &[u8]) -> Result<(), String> {
    use windows_sys::Win32::System::Pipes::CallNamedPipeW;
    let name: Vec<u16> = WINDOWS_PIPE_NAME.encode_utf16().chain(Some(0)).collect();
    if payload.len() as u64 > MAX_MESSAGE_BYTES {
        return Err("Requisição local excede o limite.".into());
    }
    let mut reply = [0_u8; MAX_MESSAGE_BYTES as usize + 1];
    let mut read = 0_u32;
    // The service creates a local-only, DACL-restricted named pipe.
    // No password, API token, or DB encryption key is sent to the pipe.
    let ok = unsafe {
        CallNamedPipeW(
            name.as_ptr(),
            payload.as_ptr() as _, payload.len() as u32,
            reply.as_mut_ptr() as _, reply.len() as u32,
            &mut read, 30_000,
        )
    };
    if ok == 0 {
        let code = std::io::Error::last_os_error().raw_os_error();
        return Err(match code {
            Some(2 | 232 | 233) => "Serviço NexaCollector não está atendendo no canal local. Confirme em Serviços do Windows se está em execução.".into(),
            Some(5) => "Acesso negado ao canal protegido. Execute o Nexa Collector como administrador.".into(),
            Some(121 | 231) => "Canal ocupado ou sem resposta. Verifique o estado do NexaCollector e tente novamente.".into(),
            _ => format!("Não foi possível conectar ao serviço NexaCollector (erro Windows {}).", code.unwrap_or(-1)),
        });
    }
    if read as u64 > MAX_MESSAGE_BYTES {
        return Err("Resposta local acima do limite.".into());
    }
    let answer: ControlResponse = serde_json::from_slice(&reply[..read as usize])
        .map_err(|_| "Resposta local inválida.".to_owned())?;
    if answer.accepted {
        Ok(())
    } else {
        Err(answer.reason.unwrap_or_else(|| "O serviço recusou a operação.".into()))
    }
}



#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ControlRequest {
    pub version: u8,
    pub ip: Ipv4Addr,
    pub action: ReviewAction,
}

/// Separate typed schedule command; never shares a field with printer
/// enrollment or permits changing administrator-provisioned subnet grants.
#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ScheduleControlRequest {
    pub version: u8,
    pub schedule: DiscoverySchedule,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct FleetControlRequest {
    pub version: u8,
    pub command: FleetCommand,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct BroadcastControlRequest {
    pub version: u8,
    pub cidrs: Vec<String>,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ControlResponse {
    pub accepted: bool,
    pub reason: Option<String>,
}

pub fn socket_path(queue_path: &Path) -> PathBuf {
    let mut path = queue_path.as_os_str().to_os_string();
    path.push(".control.sock");
    PathBuf::from(path)
}

/// Client with finite reads and timeouts. Authorization is enforced by
/// the OS on the private parent directory and the mode-0600 socket inode.
/// Caller never receives or sends collector secrets.
#[cfg(unix)]
pub fn submit(
    queue_path: &Path, ip: Ipv4Addr, action: ReviewAction,
) -> Result<(), String> {
    let payload = serde_json::to_vec(&ControlRequest {
        version: PROTOCOL_VERSION, ip, action,
    }).map_err(|_| "Requisição local inválida.".to_owned())?;
    submit_payload(queue_path, &payload)
}

#[cfg(unix)]
pub fn submit_fleet(queue_path: &Path, command: FleetCommand) -> Result<(), String> {
    let payload = serde_json::to_vec(&FleetControlRequest {
        version: PROTOCOL_VERSION, command,
    }).map_err(|_| "Comando de impressora inválido.".to_owned())?;
    submit_payload(queue_path, &payload)
}

#[cfg(windows)]
pub fn submit_windows_fleet(command: FleetCommand) -> Result<(), String> {
    let payload = serde_json::to_vec(&FleetControlRequest {
        version: PROTOCOL_VERSION, command,
    }).map_err(|_| "Comando de impressora inválido.".to_owned())?;
    submit_windows_payload(&payload)
}

#[cfg(unix)]
pub fn submit_broadcast(queue: &Path, cidrs: Vec<String>) -> Result<(), String> {
    let payload = serde_json::to_vec(&BroadcastControlRequest {
        version: PROTOCOL_VERSION, cidrs,
    }).map_err(|_| "Busca broadcast inválida.".to_owned())?;
    submit_payload(queue, &payload)
}

#[cfg(windows)]
pub fn submit_windows_broadcast(cidrs: Vec<String>) -> Result<(), String> {
    let payload = serde_json::to_vec(&BroadcastControlRequest {
        version: PROTOCOL_VERSION, cidrs,
    }).map_err(|_| "Busca broadcast inválida.".to_owned())?;
    submit_windows_payload(&payload)
}

#[cfg(unix)]
pub fn submit_schedule(queue_path: &Path, schedule: DiscoverySchedule) -> Result<(), String> {
    let payload = serde_json::to_vec(&ScheduleControlRequest {
        version: PROTOCOL_VERSION, schedule,
    }).map_err(|_| "Configuração de agenda inválida.".to_owned())?;
    submit_payload(queue_path, &payload)
}

#[cfg(unix)]
fn submit_payload(queue_path: &Path, payload: &[u8]) -> Result<(), String> {
    use std::{io::{Read, Write}, os::unix::net::UnixStream, time::Duration};
    let mut stream = UnixStream::connect(socket_path(queue_path))
        .map_err(|_| "Canal de controle indisponível ou acesso negado. Execute com conta autorizada.".to_owned())?;
    stream.set_read_timeout(Some(Duration::from_secs(24)))
        .map_err(|_| "Não foi possível configurar o timeout do canal local.".to_owned())?;
    stream.set_write_timeout(Some(Duration::from_secs(3)))
        .map_err(|_| "Não foi possível configurar o timeout do canal local.".to_owned())?;
    if payload.len() as u64 > MAX_MESSAGE_BYTES {
        return Err("Requisição local maior que o limite permitido.".into());
    }
    stream.write_all(&payload)
        .map_err(|_| "Falha ao enviar comando administrativo.".to_owned())?;
    // Read bounded newline-delimited commands, not EOF/half-close semantics.
    stream.write_all(b"\n")
        .map_err(|_| "Falha ao concluir a requisição local.".to_owned())?;
    let mut reply = Vec::new();
    stream.take(MAX_MESSAGE_BYTES + 1).read_to_end(&mut reply)
        .map_err(|_| "Não houve resposta do serviço de controle.".to_owned())?;
    if reply.len() as u64 > MAX_MESSAGE_BYTES {
        return Err("Resposta local excedeu o limite.".into());
    }
    let answer: ControlResponse = serde_json::from_slice(&reply)
        .map_err(|_| "Resposta local inválida.".to_owned())?;
    if answer.accepted {
        Ok(())
    } else {
        Err(answer.reason.unwrap_or_else(|| "O serviço recusou a operação.".into()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn schedule_protocol_rejects_ambiguous_or_unexpected_fields() {
        let valid = ScheduleControlRequest {
            version: PROTOCOL_VERSION,
            schedule: DiscoverySchedule {
                enabled: true, interval_hours: 24,
                cidrs: vec!["10.1.2.0/24".into()],
            },
        };
        let json = serde_json::to_vec(&valid).unwrap();
        assert!(json.len() <= MAX_MESSAGE_BYTES as usize);
        let parsed: ScheduleControlRequest = serde_json::from_slice(&json).unwrap();
        assert_eq!(parsed.schedule.interval_hours, 24);
        assert!(serde_json::from_str::<ScheduleControlRequest>(
            r#"{"version":1,"schedule":{"enabled":true,"interval_hours":24,"cidrs":[]}, "token":"bad"}"#
        ).is_err());
        assert!(serde_json::from_str::<ScheduleControlRequest>(
            r#"{"version":1,"ip":"10.1.2.4","action":"save"}"#
        ).is_err());
    }

    #[test]
    fn rejects_unknown_protocol_fields_and_actions() {
        assert!(serde_json::from_str::<ControlRequest>(
            r#"{"version":1,"ip":"10.1.1.2","action":"monitor"}"#
        ).is_err());
        assert!(serde_json::from_str::<ControlRequest>(
            r#"{"version":1,"ip":"10.1.1.2","action":"save","token":"unsafe"}"#
        ).is_err());
        let command = ControlRequest {
            version: PROTOCOL_VERSION, ip: "10.2.0.6".parse().unwrap(),
            action: ReviewAction::Ignore,
        };
        let serialized = serde_json::to_string(&command).unwrap();
        assert!(!serialized.contains("token"));
        let restored: ControlRequest = serde_json::from_str(&serialized).unwrap();
        assert_eq!(restored.action, ReviewAction::Ignore);
    }
}
