//! On-demand, read-only Windows USB printer and imaging device discovery.
//! USB presence and driver status do not prove that a meter can be read.

use serde::{Deserialize, Serialize};
use std::io;

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum UsbDeviceType {
    Printer,
    Scanner,
    /// Windows classifies cameras and some scanner drivers as imaging devices.
    /// Never claim an ambiguous imaging entry is a printer or scanner.
    Imaging,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields)]
pub struct UsbDeviceCandidate {
    pub friendly_name: String,
    pub instance_id: String,
    pub status: String,
    pub device_type: UsbDeviceType,
    /// VID:PID only, never a USB serial number.
    pub vid_pid: Option<String>,
}

fn is_usb_instance_id(instance_id: &str) -> bool {
    let id = instance_id.to_ascii_uppercase();
    let Some(rest) = id.strip_prefix("USB\\VID_") else {
        return id.starts_with("USBPRINT\\") && id.len() > "USBPRINT\\".len();
    };
    let bytes = rest.as_bytes();
    bytes.len() >= 13
        && bytes[..4].iter().all(u8::is_ascii_hexdigit)
        && &bytes[4..9] == b"&PID_"
        && bytes[9..13].iter().all(u8::is_ascii_hexdigit)
        && (bytes.len() == 13 || matches!(bytes[13], b'&' | b'\\'))
}

fn vid_pid_from_instance(instance_id: &str) -> Option<String> {
    let id = instance_id.to_ascii_uppercase();
    let rest = id.strip_prefix("USB\\VID_")?;
    Some(format!("{}:{}", &rest[..4], &rest[9..13]))
}

fn parse_candidates(json: &str) -> io::Result<Vec<UsbDeviceCandidate>> {
    let mut devices: Vec<UsbDeviceCandidate> = serde_json::from_str(json)
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidData, "invalid Windows USB discovery response"))?;

    for device in &devices {
        let name = device.friendly_name.trim();
        if name.is_empty()
            || name.len() > 512
            || device.instance_id.len() > 1024
            || !is_usb_instance_id(&device.instance_id)
            || device.status.is_empty()
            || device.status.len() > 100
        {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                "invalid USB device identity or status",
            ));
        }

        let expected_vid_pid = vid_pid_from_instance(&device.instance_id);
        if device.vid_pid != expected_vid_pid {
            return Err(io::Error::new(
                io::ErrorKind::InvalidData,
                "USB vendor/product identification does not match Windows PnP",
            ));
        }
    }
    devices.sort_by_key(|device| device.instance_id.to_ascii_uppercase());
    devices.dedup_by(|a, b| a.instance_id.eq_ignore_ascii_case(&b.instance_id));
    Ok(devices)
}

/// Enumerate physically present Windows USB print and imaging interfaces.
/// Does not scan documents, reset counters, edit settings or report meter values.
#[cfg(windows)]
pub fn discover_usb_devices() -> io::Result<Vec<UsbDeviceCandidate>> {
    use std::process::Command;

    // Constant PowerShell script: no caller-controlled code or executable path.
    // PrintQueue software/virtual/network-only entries must not be treated as
    // physically connected USB devices. USBPRINT and USB VID/PID are accepted.
    const SCRIPT: &str = r#"
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
$devices = @(Get-PnpDevice -PresentOnly -ErrorAction Stop | Where-Object {
    $_.Class -in @('Printer', 'PrintQueue', 'Image') -and
    $_.FriendlyName -and
    ($_.InstanceId -match '^USB\\VID_[0-9A-Fa-f]{4}&PID_[0-9A-Fa-f]{4}(?:&|\\|$)' -or
     $_.InstanceId -like 'USBPRINT\*')
} | ForEach-Object {
    $id = [string]$_.InstanceId
    $name = [string]$_.FriendlyName
    $idMatch = [regex]::Match($id, '(?i)VID_([0-9a-f]{4})&PID_([0-9a-f]{4})')
    $kind = if ($_.Class -in @('Printer', 'PrintQueue')) {
        'printer'
    } elseif ($name -match '(?i)scan|imageFORMULA|DR[- ]?C[0-9]+') {
        'scanner'
    } else {
        'imaging'
    }
    [PSCustomObject]@{
        friendly_name = $name
        instance_id = $id
        status = [string]$_.Status
        device_type = $kind
        vid_pid = if ($idMatch.Success) {
            ($idMatch.Groups[1].Value + ':' + $idMatch.Groups[2].Value).ToUpperInvariant()
        } else {
            $null
        }
    }
})
ConvertTo-Json -InputObject $devices -Compress -Depth 3
"#;
    let output = Command::new("powershell.exe")
        .args(["-NoProfile", "-NonInteractive", "-Command", SCRIPT])
        .output()?;
    if !output.status.success() {
        return Err(io::Error::new(
            io::ErrorKind::Other,
            "Windows USB print/imaging device enumeration failed",
        ));
    }
    if output.stdout.len() > 262_144 {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "Windows USB device enumeration exceeded its size limit",
        ));
    }
    let raw = String::from_utf8(output.stdout)
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidData, "invalid Windows USB discovery encoding"))?;
    parse_candidates(raw.trim())
}

#[cfg(not(windows))]
pub fn discover_usb_devices() -> io::Result<Vec<UsbDeviceCandidate>> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "USB printer discovery is currently supported on Windows only",
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn detects_actual_canon_drc225_without_requiring_the_wrong_vendor() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"CANON DR-C225 USB","instance_id":"USB\\VID_1083&PID_1658\\UNIT1","status":"OK","device_type":"scanner","vid_pid":"1083:1658"}]"#,
        ).unwrap();
        assert_eq!(devices.len(), 1);
        assert_eq!(devices[0].device_type, UsbDeviceType::Scanner);
        assert_eq!(devices[0].vid_pid.as_deref(), Some("1083:1658"));
    }

    #[test]
    fn detects_generic_usb_printers_across_manufacturers() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"HP LaserJet","instance_id":"USBPRINT\\HP_LaserJet\\UNIT1","status":"OK","device_type":"printer","vid_pid":null},{"friendly_name":"Brother Printer","instance_id":"USB\\VID_04F9&PID_1234\\UNIT2","status":"OK","device_type":"printer","vid_pid":"04F9:1234"}]"#,
        ).unwrap();
        assert_eq!(devices.len(), 2);
        assert!(devices.iter().all(|x| x.device_type == UsbDeviceType::Printer));
    }

    #[test]
    fn do_not_assume_an_imaging_device_is_a_scanner() {
        let devices = parse_candidates(
            r#"[{"friendly_name":"USB camera","instance_id":"USB\\VID_1234&PID_5678\\UNIT3","status":"OK","device_type":"imaging","vid_pid":"1234:5678"}]"#,
        ).unwrap();
        assert_eq!(devices[0].device_type, UsbDeviceType::Imaging);
    }

    #[test]
    fn rejects_network_and_virtual_queues_even_when_named_usb() {
        for id in ["SWD\\PRINTENUM\\{printer}", "WSDPRINT\\EXAMPLE", "USB\\VID_ZZZZ&PID_1234"] {
            assert!(!is_usb_instance_id(id));
        }
        assert!(parse_candidates(
            r#"[{"friendly_name":"Network Printer","instance_id":"SWD\\PRINTENUM\\PRINTER","status":"OK","device_type":"printer","vid_pid":null}]"#,
        ).is_err());
    }

    #[test]
    fn refuses_spoofed_vendor_product_and_meter_fields() {
        assert!(parse_candidates(
            r#"[{"friendly_name":"Canon","instance_id":"USB\\VID_1083&PID_1658\\UNIT","status":"OK","device_type":"scanner","vid_pid":"04A9:1658"}]"#,
        ).is_err());
        assert!(parse_candidates(
            r#"[{"friendly_name":"Canon","instance_id":"USB\\VID_1083&PID_1658\\UNIT","status":"OK","device_type":"scanner","vid_pid":"1083:1658","meter_total":42}]"#,
        ).is_err());
    }

    #[test]
    fn empty_response_and_duplicate_usb_interface() {
        assert!(parse_candidates("[]").unwrap().is_empty());
        let devices = parse_candidates(
            r#"[{"friendly_name":"Printer","instance_id":"USB\\VID_1234&PID_5678\\UNIT","status":"OK","device_type":"printer","vid_pid":"1234:5678"},{"friendly_name":"Printer","instance_id":"USB\\VID_1234&PID_5678\\UNIT","status":"OK","device_type":"printer","vid_pid":"1234:5678"}]"#,
        ).unwrap();
        assert_eq!(devices.len(), 1);
    }

    #[test]
    fn validates_all_vendor_product_usb_paths_before_slicing() {
        for id in ["USB\\VID_1", "USB\\VID_1234&PID_FOOO", "USB\\VID_1234&PID_5678xyz", "USBPRINT\\"] {
            assert!(!is_usb_instance_id(id));
        }
        assert_eq!(
            vid_pid_from_instance("USB\\VID_1083&PID_1658\\UNIT"),
            Some("1083:1658".into())
        );
    }
}
