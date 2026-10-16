//! Read-only Windows USB discovery for Canon DR-C225 scanners.
//! Detection is NOT verification of the device's lifetime scan meter.
//! No WIA/TWAIN scanning, USB control transfer, driver settings or reset is performed.

use serde::{Deserialize, Serialize};
use std::io;

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct CanonUsbCandidate {
    pub friendly_name: String,
    pub instance_id: String,
    pub status: String,
    /// True only when the Windows device name explicitly identifies revision II.
    /// A generic "DR-C225" name must be checked on the physical scanner.
    pub model_confirmed: bool,
}

fn parse_candidates(json: &str) -> io::Result<Vec<CanonUsbCandidate>> {
    let mut candidates: Vec<CanonUsbCandidate> = serde_json::from_str(json)
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidData, "invalid Windows USB discovery response"))?;
    if candidates.iter().any(|device| {
        device.instance_id.is_empty()
            || device.instance_id.len() > 1024
            || device.friendly_name.is_empty()
            || device.friendly_name.len() > 512
            || !device.instance_id.to_ascii_uppercase().starts_with("USB\\VID_04A9&")
            || !device.friendly_name.to_ascii_uppercase().replace('-', "").replace(' ', "").contains("DRC225")
            || device.status.len() > 100
    }) {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "Canon USB discovery returned an invalid device identity",
        ));
    }
    candidates.sort_by(|a, b| a.instance_id.cmp(&b.instance_id));
    candidates.dedup_by(|a, b| a.instance_id == b.instance_id);
    Ok(candidates)
}

/// Only enumerates *present* USB devices. Does not initiate a scan or read
/// unverified counters. Caller must distinguish discovery from meter homologation.
#[cfg(windows)]
pub fn discover_canon_usb() -> io::Result<Vec<CanonUsbCandidate>> {
    use std::process::Command;

    // No user-supplied PowerShell statements, path or interpolated arguments.
    // Canon USB vendor ID 04A9 alone is insufficient: require DR-C225 in name.
    const SCRIPT: &str = r#"
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$devices = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
    $_.InstanceId -like 'USB\VID_04A9*' -and
    $_.FriendlyName -match '(?i)DR[- ]?C225'
} | ForEach-Object {
    [PSCustomObject]@{
        friendly_name = [string]$_.FriendlyName
        instance_id = [string]$_.InstanceId
        status = [string]$_.Status
        model_confirmed = [bool]($_.FriendlyName -match '(?i)DR[- ]?C225[- ]*(II|2)\b')
    }
})
ConvertTo-Json -InputObject $devices -Compress -Depth 3
"#;
    let result = Command::new("powershell.exe")
        .args(["-NoProfile", "-NonInteractive", "-Command", SCRIPT])
        .output()?;
    if !result.status.success() {
        return Err(io::Error::new(
            io::ErrorKind::PermissionDenied,
            "Windows USB enumeration failed; verify PnP access and Canon driver",
        ));
    }
    if result.stdout.len() > 65_536 {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "Windows USB discovery response exceeds the allowed size",
        ));
    }
    let output = String::from_utf8(result.stdout)
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidData, "invalid Windows USB discovery encoding"))?;
    parse_candidates(output.trim())
}

#[cfg(not(windows))]
pub fn discover_canon_usb() -> io::Result<Vec<CanonUsbCandidate>> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "Canon DR-C225 II USB discovery requires Windows",
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn accepts_present_matching_canon_windows_device() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 II USB","instance_id":"USB\\VID_04A9&PID_1234\\UNIT1","status":"OK","model_confirmed":true}]"#,
        ).unwrap();
        assert_eq!(devices.len(), 1);
        assert!(devices[0].model_confirmed);
        assert_eq!(devices[0].status, "OK");
    }

    #[test]
    fn generic_name_is_not_claimed_as_revision_two() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 USB","instance_id":"USB\\VID_04A9&PID_1234\\UNIT1","status":"OK","model_confirmed":false}]"#,
        ).unwrap();
        assert!(!devices[0].model_confirmed);
    }

    #[test]
    fn empty_scan_is_not_a_meter_reading() {
        assert!(parse_candidates("[]").unwrap().is_empty());
    }

    #[test]
    fn refuses_inconsistent_vendor_or_unknown_fields() {
        assert!(parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 II USB","instance_id":"USB\\VID_9999&PID_1234","status":"OK","model_confirmed":true}]"#,
        ).is_err());
        assert!(parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 II USB","instance_id":"USB\\VID_04A9","status":"OK","model_confirmed":true,"meter_total":999}]"#,
        ).is_err());
    }

    #[test]
    fn duplicate_pnp_entries_are_not_duplicate_scanners() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 II USB","instance_id":"USB\\VID_04A9&PID_1234\\UNIT1","status":"OK","model_confirmed":true},{"friendly_name":"CANON DR-C225 II USB","instance_id":"USB\\VID_04A9&PID_1234\\UNIT1","status":"OK","model_confirmed":true}]"#,
        ).unwrap();
        assert_eq!(devices.len(), 1);
    }
}
