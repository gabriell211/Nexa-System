//! Interactive laboratory reader for the Canon DR-C225 maintenance page.
//! Canon has not published this GUI as a stable counter API. The scanner must
//! be the only present DR-C225 and the signed Canon utility must be installed.

use crate::{CollectorError, Reading};
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use std::io;
use uuid::Uuid;

pub const PROFILE_ID: &str = "canon-dr-c225-usb-utility-v1";

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct CanonDrc225Meter {
    pub profile: String,
    pub model_observed: String,
    pub meter_scan_total: u64,
    pub roller_count_resettable: u64,
    pub collected_at: DateTime<Utc>,
    pub source: String,
}

fn parse_meter(json: &[u8]) -> Result<CanonDrc225Meter, CollectorError> {
    if json.len() > 4096 {
        return Err(CollectorError::Configuration(
            "Canon meter response exceeds size limit".into(),
        ));
    }
    let meter: CanonDrc225Meter = serde_json::from_slice(json)?;
    if meter.profile != PROFILE_ID
        || meter.model_observed != "DR-C225"
        || meter.source != "canon-imageformula-utility-maintenance-ui"
        || meter.meter_scan_total > i64::MAX as u64
        || meter.roller_count_resettable > i64::MAX as u64
        || meter.collected_at > Utc::now() + chrono::Duration::minutes(5)
        || meter.collected_at < Utc::now() - chrono::Duration::minutes(5)
    {
        return Err(CollectorError::Configuration(
            "Canon meter response failed validation".into(),
        ));
    }
    Ok(meter)
}

impl CanonDrc225Meter {
    pub fn into_reading(self, printer_id: u64) -> Reading {
        Reading {
            sample_id: Uuid::new_v4(),
            printer_id,
            collected_at: self.collected_at,
            meter_total: None,
            meter_mono: None,
            meter_color: None,
            meter_scan: Some(self.meter_scan_total),
        }
    }
}

#[cfg(windows)]
pub fn read() -> Result<CanonDrc225Meter, CollectorError> {
    use std::{
        io::Read,
        process::{Command, Stdio},
        thread,
        time::{Duration, Instant},
    };
    const SCRIPT: &str = include_str!("../../scripts/read-canon-drc225-usb.ps1");
    let args = ["-NoProfile", "-NonInteractive", "-Command", SCRIPT];
    let mut child = match Command::new("powershell.exe")
        .args(args)
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
    {
        Ok(child) => child,
        Err(error) if error.kind() == io::ErrorKind::NotFound => Command::new("pwsh.exe")
            .args(args)
            .stdout(Stdio::piped())
            .stderr(Stdio::null())
            .spawn()?,
        Err(error) => return Err(error.into()),
    };
    let deadline = Instant::now() + Duration::from_secs(60);
    let status = loop {
        if let Some(status) = child.try_wait()? {
            break status;
        }
        if Instant::now() >= deadline {
            let _ = child.kill();
            let _ = child.wait();
            return Err(CollectorError::Configuration(
                "Canon utility timed out; no reading enqueued".into(),
            ));
        }
        thread::sleep(Duration::from_millis(100));
    };
    if !status.success() {
        return Err(CollectorError::Configuration(
            "Canon DR-C225 USB meter unavailable; inspect the interactive diagnostic".into(),
        ));
    }
    let mut output = Vec::new();
    child
        .stdout
        .take()
        .ok_or_else(|| CollectorError::Configuration("Canon meter output unavailable".into()))?
        .take(4097)
        .read_to_end(&mut output)?;
    parse_meter(&output)
}

#[cfg(not(windows))]
pub fn read() -> Result<CanonDrc225Meter, CollectorError> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "Canon DR-C225 USB reader requires Windows",
    )
    .into())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_separate_lifetime_and_resettable_counts() {
        let json = format!(
            r#"{{"profile":"{}","model_observed":"DR-C225","meter_scan_total":169360,"roller_count_resettable":150,"collected_at":"{}","source":"canon-imageformula-utility-maintenance-ui"}}"#,
            PROFILE_ID,
            Utc::now().to_rfc3339()
        );
        let meter = parse_meter(json.as_bytes()).unwrap();
        let reading = meter.into_reading(7);
        assert_eq!(reading.meter_scan, Some(169360));
        assert_eq!(reading.meter_total, None);
    }

    #[test]
    fn rejects_wrong_profile_and_extra_fields() {
        let json = format!(
            r#"{{"profile":"canon-other","model_observed":"DR-C225","meter_scan_total":1,"roller_count_resettable":0,"collected_at":"{}","source":"canon-imageformula-utility-maintenance-ui"}}"#,
            Utc::now().to_rfc3339()
        );
        assert!(parse_meter(json.as_bytes()).is_err());
        let injected = json.replace(
            "\"meter_scan_total\":1",
            "\"meter_scan_total\":1,\"printer_id\":2",
        );
        assert!(parse_meter(injected.as_bytes()).is_err());
    }
}
