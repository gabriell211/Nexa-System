//! Local administrative protocol: Unix-domain sockets only. Windows is denied
//! until an equivalent ACL-restricted native named-pipe transport is provided.
//! Messages contain no credentials and are never forwarded to the cloud.
use crate::candidate_review::ReviewAction;
use serde::{Deserialize, Serialize};
use std::{net::Ipv4Addr, path::{Path, PathBuf}};

pub const PROTOCOL_VERSION: u8 = 1;
pub const MAX_MESSAGE_BYTES: u64 = 512;

#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ControlRequest {
    pub version: u8,
    pub ip: Ipv4Addr,
    pub action: ReviewAction,
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
    use std::{io::{Read, Write}, net::Shutdown, os::unix::net::UnixStream, time::Duration};
    let mut stream = UnixStream::connect(socket_path(queue_path))
        .map_err(|_| "Canal de controle indisponível ou acesso negado. Execute com conta autorizada.".to_owned())?;
    stream.set_read_timeout(Some(Duration::from_secs(8)))
        .map_err(|_| "Não foi possível configurar o timeout do canal local.".to_owned())?;
    stream.set_write_timeout(Some(Duration::from_secs(3)))
        .map_err(|_| "Não foi possível configurar o timeout do canal local.".to_owned())?;
    let payload = serde_json::to_vec(&ControlRequest {
        version: PROTOCOL_VERSION, ip, action,
    }).map_err(|_| "Requisição local inválida.".to_owned())?;
    if payload.len() as u64 > MAX_MESSAGE_BYTES {
        return Err("Requisição local maior que o limite permitido.".into());
    }
    stream.write_all(&payload)
        .map_err(|_| "Falha ao enviar comando administrativo.".to_owned())?;
    stream.shutdown(Shutdown::Write)
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
