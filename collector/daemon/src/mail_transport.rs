//! Encrypted SMTP/POP3 transport for installations that cannot send readings
//! through Web Services. A reading leaves the outbox only after a matching,
//! authenticated acknowledgement from the Nexa server mailbox.
use aes_gcm::{aead::{Aead, AeadCore, OsRng, Payload}, Aes256Gcm, KeyInit, Nonce};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use nexa_collector_core::{CollectorConfig, CollectorError, MailConfig, Outbox, WatchdogHeartbeat};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{collections::HashSet, io::Write, path::Path, process::{Command, Stdio}};
use uuid::Uuid;
use zeroize::Zeroizing;

const AAD: &[u8] = b"nexa-collector-mail-v1";

#[derive(Serialize, Deserialize)]
struct Envelope {
    version: u8,
    collector_id: Uuid,
    nonce: String,
    ciphertext: String,
}

#[derive(Deserialize)]
struct Ack {
    kind: String,
    collector_id: Uuid,
    acknowledged: Vec<Uuid>,
}

fn cipher(token: &str) -> Result<Aes256Gcm, CollectorError> {
    if token.len() != 64 || !token.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(CollectorError::Configuration("collector key must be 64 hex characters".into()));
    }
    let key = Sha256::digest(token.as_bytes());
    Aes256Gcm::new_from_slice(&key).map_err(|_| CollectorError::Crypto)
}

fn seal(cfg: &CollectorConfig, token: &str, body: &serde_json::Value) -> Result<Envelope, CollectorError> {
    let nonce = Aes256Gcm::generate_nonce(&mut OsRng);
    let bytes = serde_json::to_vec(body)?;
    let ciphertext = cipher(token)?.encrypt(&nonce, Payload { msg: &bytes, aad: AAD })
        .map_err(|_| CollectorError::Crypto)?;
    Ok(Envelope {
        version: 1, collector_id: cfg.collector_id,
        nonce: BASE64.encode(nonce), ciphertext: BASE64.encode(ciphertext),
    })
}

fn open(cfg: &CollectorConfig, token: &str, envelope: Envelope) -> Result<Ack, CollectorError> {
    if envelope.version != 1 || envelope.collector_id != cfg.collector_id {
        return Err(CollectorError::InvalidAcknowledgement);
    }
    let nonce = BASE64.decode(&envelope.nonce).map_err(|_| CollectorError::InvalidAcknowledgement)?;
    let ciphertext = BASE64.decode(&envelope.ciphertext).map_err(|_| CollectorError::InvalidAcknowledgement)?;
    if nonce.len() != 12 || ciphertext.len() < 17 { return Err(CollectorError::InvalidAcknowledgement); }
    let plain = cipher(token)?.decrypt(Nonce::from_slice(&nonce), Payload { msg: &ciphertext, aad: AAD })
        .map_err(|_| CollectorError::InvalidAcknowledgement)?;
    let ack: Ack = serde_json::from_slice(&plain).map_err(|_| CollectorError::InvalidAcknowledgement)?;
    if ack.kind != "ack" || ack.collector_id != cfg.collector_id || ack.acknowledged.is_empty() {
        return Err(CollectorError::InvalidAcknowledgement);
    }
    Ok(ack)
}

fn quote(value: &str) -> Result<String, CollectorError> {
    if value.bytes().any(|b| b.is_ascii_control()) {
        return Err(CollectorError::MailTransport);
    }
    Ok(format!("\"{}\"", value.replace('\\', "\\\\").replace('"', "\\\"")))
}

fn curl(config: &str) -> Result<Vec<u8>, CollectorError> {
    // -q ignores the service account's curlrc. All credentials are in stdin,
    // never command-line arguments, process lists or installer logs.
    let executable = if cfg!(windows) { "curl.exe" } else { "curl" };
    let mut child = Command::new(executable)
        .args(["-q", "--config", "-"])
        .stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::null())
        .spawn().map_err(|_| CollectorError::MailTransport)?;
    child.stdin.take().ok_or(CollectorError::MailTransport)?
        .write_all(config.as_bytes()).map_err(|_| CollectorError::MailTransport)?;
    let output = child.wait_with_output().map_err(|_| CollectorError::MailTransport)?;
    if !output.status.success() || output.stdout.len() > 300_000 {
        return Err(CollectorError::MailTransport);
    }
    Ok(output.stdout)
}

fn base_config(mail: &MailConfig, password: &str, pop3: bool, path: &str) -> Result<Zeroizing<String>, CollectorError> {
    let (scheme, host, port) = if pop3 {
        (if mail.pop3_implicit_tls { "pop3s" } else { "pop3" }, &mail.pop3_host, mail.pop3_port)
    } else {
        (if mail.smtp_implicit_tls { "smtps" } else { "smtp" }, &mail.smtp_host, mail.smtp_port)
    };
    let mut config = Zeroizing::new(String::from("silent\nshow-error\nfail\nssl-reqd\nconnect-timeout = 15\nmax-time = 30\n"));
    config.push_str(&format!("url = {}\n", quote(&format!("{scheme}://{host}:{port}/{path}"))?));
    config.push_str(&format!("user = {}\n", quote(&format!("{}:{password}", mail.address))?));
    Ok(config)
}

fn send(cfg: &CollectorConfig, password: &str, envelope: &Envelope, queue_path: &Path) -> Result<(), CollectorError> {
    let mail = cfg.mail.as_ref().ok_or(CollectorError::MailTransport)?;
    let mut file = tempfile::NamedTempFile::new_in(queue_path.parent().ok_or(CollectorError::MailTransport)?)
        .map_err(|_| CollectorError::MailTransport)?;
    let encoded = BASE64.encode(serde_json::to_vec(envelope)?);
    write!(file, "From: {}\r\nTo: {}\r\nSubject: Nexa Collector data\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=us-ascii\r\nContent-Transfer-Encoding: 7bit\r\n\r\n", mail.address, mail.recipient)
        .map_err(|_| CollectorError::MailTransport)?;
    for chunk in encoded.as_bytes().chunks(76) {
        file.write_all(chunk).and_then(|_| file.write_all(b"\r\n"))
            .map_err(|_| CollectorError::MailTransport)?;
    }
    file.flush().map_err(|_| CollectorError::MailTransport)?;
    let mut config = base_config(mail, password, false, "")?;
    config.push_str(&format!("mail-from = {}\nmail-rcpt = {}\nupload-file = {}\n",
        quote(&mail.address)?, quote(&mail.recipient)?, quote(&file.path().to_string_lossy())?));
    curl(&config)?;
    Ok(())
}

fn pop3(cfg: &CollectorConfig, password: &str, path: &str, request: Option<&str>) -> Result<Vec<u8>, CollectorError> {
    let mail = cfg.mail.as_ref().ok_or(CollectorError::MailTransport)?;
    let mut config = base_config(mail, password, true, path)?;
    if let Some(command) = request {
        config.push_str(&format!("request = {}\n", quote(command)?));
    }
    curl(&config)
}

fn decoded_body(message: &[u8]) -> Result<Envelope, CollectorError> {
    let text = std::str::from_utf8(message).map_err(|_| CollectorError::InvalidAcknowledgement)?;
    let (header, body) = text.split_once("\r\n\r\n").or_else(|| text.split_once("\n\n"))
        .ok_or(CollectorError::InvalidAcknowledgement)?;
    if header.to_ascii_lowercase().contains("content-transfer-encoding: quoted-printable") {
        // Symfony may encode the raw ACK body as quoted-printable. It contains
        // only base64 characters, so only soft wraps and '=' padding matter.
        let cleaned = body.replace("=\r\n", "").replace("=\n", "").replace("=3D", "=").replace("=3d", "=");
        return decode_envelope(&cleaned);
    }
    decode_envelope(body)
}

fn decode_envelope(body: &str) -> Result<Envelope, CollectorError> {
    let encoded: String = body.chars().filter(|ch| !ch.is_ascii_whitespace()).collect();
    let bytes = BASE64.decode(encoded).map_err(|_| CollectorError::InvalidAcknowledgement)?;
    serde_json::from_slice(&bytes).map_err(|_| CollectorError::InvalidAcknowledgement)
}

fn receive_acks(cfg: &CollectorConfig, token: &str, password: &str, outbox: &mut Outbox) -> Result<usize, CollectorError> {
    let listing = pop3(cfg, password, "", None)?;
    let mut ids: Vec<usize> = String::from_utf8_lossy(&listing).lines().filter_map(|line| {
        let mut parts = line.split_ascii_whitespace();
        let id = parts.next()?.parse::<usize>().ok()?;
        parts.next()?.parse::<usize>().ok()?;
        Some(id)
    }).collect();
    ids.sort_unstable();
    let mut total = 0;
    for id in ids.into_iter().rev().take(100).rev() {
        let message = pop3(cfg, password, &id.to_string(), None)?;
        let Ok(envelope) = decoded_body(&message) else { continue; };
        let Ok(ack) = open(cfg, token, envelope) else { continue; };
        let unique: HashSet<_> = ack.acknowledged.iter().copied().collect();
        if unique.len() != ack.acknowledged.len() { continue; }
        let pending: HashSet<_> = outbox.next_batch(100)?.iter().map(|sample| sample.sample_id).collect();
        if !ack.acknowledged.iter().all(|id| pending.contains(id)) {
            if ack.acknowledged.iter().any(|id| pending.contains(id)) { continue; }
        } else {
            outbox.acknowledge(&ack.acknowledged)?;
            total += ack.acknowledged.len();
        }
        // A repeated signed ACK is harmless; delete it after verification.
        pop3(cfg, password, &id.to_string(), Some("DELE"))?;
    }
    Ok(total)
}

pub fn upload_pending(cfg: &CollectorConfig, token: &str, password: &str, outbox: &mut Outbox, queue_path: &Path) -> Result<usize, CollectorError> {
    let acked = receive_acks(cfg, token, password, outbox)?;
    let samples = outbox.next_batch(100)?;
    if samples.is_empty() { return Ok(acked); }
    let envelope = seal(cfg, token, &serde_json::json!({
        "kind": "samples", "collector_id": cfg.collector_id,
        "reply_to": cfg.mail.as_ref().ok_or(CollectorError::MailTransport)?.address,
        "samples": samples,
    }))?;
    send(cfg, password, &envelope, queue_path)?;
    if acked == 0 { return Err(CollectorError::MailAwaitingAck); }
    Ok(acked)
}

pub fn heartbeat(cfg: &CollectorConfig, token: &str, password: &str, watchdog: Option<WatchdogHeartbeat>, queue_path: &Path) -> Result<(), CollectorError> {
    let envelope = seal(cfg, token, &serde_json::json!({
        "kind": "heartbeat", "collector_id": cfg.collector_id,
        "watchdog": watchdog,
    }))?;
    send(cfg, password, &envelope, queue_path)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn curl_config_quotes_secrets_and_rejects_line_injection() {
        assert_eq!(quote("a\\b\"c").unwrap(), "\"a\\\\b\\\"c\"");
        assert!(quote("secret\nurl = http://evil").is_err());
    }

    #[test]
    fn decrypts_php_openssl_ack_only_for_bound_collector() {
        let cfg: CollectorConfig = serde_json::from_value(serde_json::json!({
            "collector_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
            "api_url": "https://nexa.example.com", "interval_seconds": 300,
            "printers": []
        })).unwrap();
        let envelope = Envelope {
            version: 1, collector_id: cfg.collector_id,
            nonce: "AAECAwQFBgcICQoL".into(),
            ciphertext: "VklCEAdZmSz81CbFCpIeSQP1r7C4mSPGrn8j3cbBs85dOz03f05bzOC35EVRT8SRKmC83b8LffFMXWjK0w8UsoyrKsb0Ts3bLi1jDZOB1BremJj43H/fxO1lejAcOg5NiPcQ8fsMQtz8Nq6GELvx6BHKbWKwXHrpFkHdivgRw+kDUFtZ/G2dr77iOc4=".into(),
        };
        let ack = open(&cfg, &"ab".repeat(32), envelope).unwrap();
        assert_eq!(ack.acknowledged, vec![Uuid::parse_str("f47ac10b-58cc-4372-a567-0e02b2c3d480").unwrap()]);
    }
}
