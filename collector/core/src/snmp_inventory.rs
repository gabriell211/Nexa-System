//! Explicit, read-only, bounded SNMP v2c MIB diagnostics for a single
//! administrator-authorized IP. Inventory evidence never enables monitoring,
//! bills a counter, or adds a device to CollectorConfig.printers.
//!
//! SNMPv2c credentials and responses are not encrypted on the LAN. Production
//! environments should prefer independently verified SNMPv3 authPriv.
use crate::mib_registry::{self, Kind, Manufacturer, MarkerCounterUnit};
use ipnet::IpNet;
use serde::Serialize;
use snmp2::{Oid, SyncSession, Value};
use std::{collections::BTreeMap, net::{IpAddr, Ipv4Addr, SocketAddr}, str::FromStr, time::Duration};

const ROW_LIMIT: u32 = 8;
const TIMEOUT: Duration = Duration::from_secs(2);
const MAX_FIELD_CHARS: usize = 200;
const MAX_ERROR_COLUMNS: usize = 19;

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(tag = "type", content = "value", rename_all = "snake_case")]
pub enum ObservedValue {
    Text(String),
    Oid(String),
    Number(i64),
    Counter(u64),
    Unsupported,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct ObservedField {
    pub name: String,
    pub oid: String,
    pub index: Vec<u32>,
    pub value: ObservedValue,
    pub standard: bool,
    /// An SNMP value by itself is never permission to invoice.
    pub certified_for_billing: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct MarkerObservation {
    pub index: Vec<u32>,
    pub total: u64,
    pub unit: MarkerCounterUnit,
    pub eligible_for_billing: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct SupplyObservation {
    pub index: Vec<u32>,
    pub description: Option<String>,
    pub level: Option<i64>,
    pub capacity: Option<i64>,
    pub percentage: Option<u8>,
}

#[derive(Clone, Debug, Serialize)]
pub struct InventorySnapshot {
    pub ip: Ipv4Addr,
    pub manufacturer: Option<Manufacturer>,
    pub model_hint: Option<String>,
    pub serial_hint: Option<String>,
    pub markers: Vec<MarkerObservation>,
    pub supplies: Vec<SupplyObservation>,
    pub fields: Vec<ObservedField>,
    /// Missing MIB columns are not a zero meter or an empty cartridge.
    pub column_errors: Vec<String>,
    pub coverage: &'static str,
    pub can_enable_monitoring: bool,
    pub billing_approved: bool,
}

pub fn authorized_device(
    ip: Ipv4Addr, authorized_subnets: &[String],
) -> Result<(), &'static str> {
    if !ip.is_private() || ip.is_loopback() || ip.is_link_local()
        || ip.is_broadcast() || ip.is_unspecified()
    {
        return Err("SNMP inspection requires a private, authorized unicast IPv4 address");
    }
    let in_scope = authorized_subnets.iter()
        .filter_map(|value| IpNet::from_str(value).ok())
        .any(|cidr| cidr.contains(&IpAddr::V4(ip)));
    if !in_scope { return Err("inspection IP is outside administrator authorized ranges"); }
    Ok(())
}

fn bounded_text(bytes: &[u8]) -> String {
    let text = String::from_utf8_lossy(bytes);
    text.chars().filter(|c| !c.is_control())
        .take(MAX_FIELD_CHARS).collect::<String>()
}

fn convert(value: Value<'_>) -> ObservedValue {
    match value {
        Value::OctetString(bytes) => ObservedValue::Text(bounded_text(bytes)),
        Value::ObjectIdentifier(oid) => ObservedValue::Oid(oid.to_id_string()),
        Value::Integer(number) => ObservedValue::Number(number),
        Value::Counter32(number) | Value::Unsigned32(number) =>
            ObservedValue::Counter(number as u64),
        Value::Counter64(number) => ObservedValue::Counter(number),
        _ => ObservedValue::Unsupported,
    }
}

fn item_name(base: &str) -> Option<&'static str> {
    mib_registry::OIDS.iter().find(|obj| obj.oid == base)
        .map(|obj| obj.name)
}

fn row(base: &str, oid: &str, value: ObservedValue, expected_index_len: usize) -> Option<ObservedField> {
    let index = if expected_index_len == 0 {
        if oid != base { return None; }
        Vec::new()
    } else {
        mib_registry::instance_indices(base, oid, expected_index_len)?
    };
    Some(ObservedField {
        name: item_name(base)?.to_owned(), oid: oid.to_owned(), index,
        value, standard: true, certified_for_billing: false,
    })
}

fn valid_index_len(name: &str) -> usize {
    match name {
        "sysDescr" | "sysObjectID" | "sysName" => 0,
        "hrDeviceDescr" | "hrPrinterStatus" | "hrPrinterDetectedErrorState" |
        "prtGeneralPrinterName" | "prtGeneralSerialNumber" => 1,
        _ => 2,
    }
}

fn integer(value: &ObservedValue) -> Option<i64> {
    match value {
        ObservedValue::Number(v) => Some(*v),
        ObservedValue::Counter(v) => i64::try_from(*v).ok(),
        _ => None,
    }
}

fn value_as_text(value: &ObservedValue) -> Option<String> {
    match value {
        ObservedValue::Text(text) if !text.is_empty() => Some(text.clone()),
        _ => None,
    }
}

fn first_text(fields: &[ObservedField], names: &[&str]) -> Option<String> {
    names.iter().find_map(|name| fields.iter()
        .find(|field| field.name == *name)
        .and_then(|item| value_as_text(&item.value)))
}

/// Join only exact marker/supply table indices. No index guesses and no
/// assumption that a raw count is pages, duplex, color, copies or scans.
pub fn normalize_snapshot(
    ip: Ipv4Addr, fields: Vec<ObservedField>, errors: Vec<String>,
) -> InventorySnapshot {
    let manufacturer = fields.iter().find(|field| field.name == "sysObjectID")
        .and_then(|f| match &f.value {
            ObservedValue::Oid(value) => mib_registry::vendor_from_sys_object_id(value),
            _ => None,
        });
    let model_hint = first_text(&fields, &["prtGeneralPrinterName", "hrDeviceDescr", "sysDescr"]);
    let serial_hint = first_text(&fields, &["prtGeneralSerialNumber"]);
    let mut units = BTreeMap::<Vec<u32>, MarkerCounterUnit>::new();
    let mut counters = BTreeMap::<Vec<u32>, u64>::new();
    for field in &fields {
        match field.name.as_str() {
            "prtMarkerCounterUnit" => {
                if let Some(code) = integer(&field.value).and_then(|x| i32::try_from(x).ok()) {
                    units.insert(field.index.clone(), mib_registry::marker_counter_unit(code));
                }
            }
            "prtMarkerLifeCount" => {
                if let Some(count) = integer(&field.value).filter(|v| *v >= 0) {
                    counters.insert(field.index.clone(), count as u64);
                }
            }
            _ => {}
        }
    }
    let markers = counters.into_iter().map(|(index, total)| MarkerObservation {
        unit: units.get(&index).copied().unwrap_or(MarkerCounterUnit::Other),
        total, index, eligible_for_billing: false,
    }).collect();
    #[derive(Default)]
    struct SupplyParts { description: Option<String>, level: Option<i64>, capacity: Option<i64> }
    let mut supplies_by_index = BTreeMap::<Vec<u32>, SupplyParts>::new();
    for field in &fields {
        match field.name.as_str() {
            "prtMarkerSuppliesDescription" => {
                supplies_by_index.entry(field.index.clone()).or_default().description =
                    value_as_text(&field.value);
            }
            "prtMarkerSuppliesLevel" => {
                supplies_by_index.entry(field.index.clone()).or_default().level = integer(&field.value);
            }
            "prtMarkerSuppliesMaxCapacity" => {
                supplies_by_index.entry(field.index.clone()).or_default().capacity = integer(&field.value);
            }
            _ => {}
        }
    }
    let supplies = supplies_by_index.into_iter().map(|(index, p)| {
        let percentage = p.level.and_then(|l| i32::try_from(l).ok())
            .zip(p.capacity.and_then(|c| i32::try_from(c).ok()))
            .and_then(|(level, cap)| mib_registry::supply_percentage(level, cap));
        SupplyObservation { index, description:p.description, level:p.level,
            capacity:p.capacity, percentage }
    }).collect();
    InventorySnapshot {
        ip, manufacturer, model_hint, serial_hint, markers, supplies,
        fields, column_errors: errors,
        coverage: "diagnostic_only_unverified_models_and_firmware",
        can_enable_monitoring: false, billing_approved: false,
    }
}

fn scalar(session: &mut SyncSession, base: &'static str) -> Result<Option<ObservedField>, String> {
    let arcs: Vec<u64> = mib_registry::parse_oid(base).unwrap().into_iter().map(u64::from).collect();
    let oid = Oid::from(&arcs).map_err(|_| "invalid catalog OID")?;
    let mut pdu = session.get(&oid).map_err(|_| "SNMP GET failed")?;
    let field = pdu.varbinds.next().and_then(|(response_oid, value)| {
        row(base, &response_oid.to_id_string(), convert(value), 0)
    }).filter(|field| field.value != ObservedValue::Unsupported);
    Ok(field)
}

fn column(session: &mut SyncSession, base: &'static str, name: &str) -> Result<Vec<ObservedField>, String> {
    let arcs: Vec<u64> = mib_registry::parse_oid(base).unwrap().into_iter().map(u64::from).collect();
    let oid = Oid::from(&arcs).map_err(|_| "invalid catalog OID")?;
    let pdu = session.getbulk(&[&oid], 0, ROW_LIMIT).map_err(|_| "SNMP GETBULK failed")?;
    let length = valid_index_len(name);
    Ok(pdu.varbinds.filter_map(|(response_oid, value)| {
        row(base, &response_oid.to_id_string(), convert(value), length)
    }).filter(|f| f.value != ObservedValue::Unsupported)
        .take(ROW_LIMIT as usize).collect())
}

/// Explicit opt-in diagnostic; does not walk arbitrary device OIDs or
/// mutate/monitor a printer. Credentials are provided via existing protected
/// service secret loading, never accepted as command-line arguments.
pub fn inspect_v2c(
    ip: Ipv4Addr, authorized_subnets: &[String], community: &[u8],
) -> Result<InventorySnapshot, String> {
    authorized_device(ip, authorized_subnets).map_err(str::to_owned)?;
    if community.is_empty() { return Err("SNMP credential not provided".into()); }
    let mut session = SyncSession::new_v2c(
        SocketAddr::from((ip, 161)), community, Some(TIMEOUT), 0,
    ).map_err(|_| "unable to create SNMP read-only session")?;
    let mut fields = Vec::new();
    let mut errors = Vec::new();
    for item in mib_registry::OIDS {
        // Explicit catalogue allowlist: no arbitrary GET/SET and no unbounded WALK.
        let result = if item.requires_instance_walk {
            column(&mut session, item.oid, item.name)
        } else {
            scalar(&mut session, item.oid).map(|opt| opt.into_iter().collect())
        };
        match result {
            Ok(values) if values.is_empty() => errors.push(format!("{}: no supported response", item.name)),
            Ok(mut values) => fields.append(&mut values),
            Err(reason) => {
                if errors.len() < MAX_ERROR_COLUMNS { errors.push(format!("{}: {reason}", item.name)); }
            }
        };
    }
    // A failed/mismatched SNMP agent must not be described as a printer.
    if fields.iter().all(|f| f.name != "sysObjectID" && f.name != "sysDescr") {
        return Err("SNMP identity unavailable; no device inventory created".into());
    }
    Ok(normalize_snapshot(ip, fields, errors))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn field(name:&str, suffix: &[u32], value: ObservedValue) -> ObservedField {
        let oid = mib_registry::OIDS.iter().find(|x| x.name == name).unwrap();
        let full = format!("{}{}", oid.oid, suffix.iter().map(|v| format!(".{v}")).collect::<String>());
        row(oid.oid, &full, value, suffix.len()).unwrap()
    }

    #[test]
    fn authorized_ip_is_required_even_for_diagnostics() {
        let scope = vec!["10.15.0.0/16".to_owned()];
        assert!(authorized_device("10.15.9.4".parse().unwrap(), &scope).is_ok());
        assert!(authorized_device("10.16.9.4".parse().unwrap(), &scope).is_err());
        assert!(authorized_device("8.8.8.8".parse().unwrap(), &vec!["0.0.0.0/0".into()]).is_err());
        assert!(authorized_device("127.0.0.1".parse().unwrap(), &scope).is_err());
        assert!(authorized_device("169.254.3.4".parse().unwrap(), &scope).is_err());
    }

    #[test]
    fn joins_only_matching_dynamic_indices_and_does_not_approve_billable_counts() {
        let ip = "10.15.1.3".parse().unwrap();
        let values = vec![
            field("sysObjectID",&[],ObservedValue::Oid("1.3.6.1.4.1.2435.1".into())),
            field("prtGeneralPrinterName",&[1],ObservedValue::Text("Brother MFC".into())),
            field("prtGeneralSerialNumber",&[1],ObservedValue::Text("SERIAL".into())),
            field("prtMarkerCounterUnit",&[1,2],ObservedValue::Number(7)),
            field("prtMarkerCounterUnit",&[1,4],ObservedValue::Number(8)),
            field("prtMarkerLifeCount",&[1,2],ObservedValue::Counter(42)),
            field("prtMarkerLifeCount",&[1,3],ObservedValue::Counter(101)),
            field("prtMarkerSuppliesDescription",&[1,4],ObservedValue::Text("Cyan".into())),
            field("prtMarkerSuppliesLevel",&[1,4],ObservedValue::Number(-2)),
            field("prtMarkerSuppliesMaxCapacity",&[1,4],ObservedValue::Number(100)),
            field("prtMarkerSuppliesDescription",&[1,5],ObservedValue::Text("Black".into())),
            field("prtMarkerSuppliesLevel",&[1,5],ObservedValue::Number(60)),
            field("prtMarkerSuppliesMaxCapacity",&[1,5],ObservedValue::Number(120)),
        ];
        let snapshot = normalize_snapshot(ip, values, Vec::new());
        assert_eq!(snapshot.manufacturer, Some(Manufacturer::Brother));
        assert_eq!(snapshot.markers.len(), 2);
        assert_eq!(snapshot.markers[0].unit, MarkerCounterUnit::Impressions);
        assert_eq!(snapshot.markers[1].unit, MarkerCounterUnit::Other);
        assert!(snapshot.markers.iter().all(|v| !v.eligible_for_billing));
        assert_eq!(snapshot.supplies.len(), 2);
        assert_eq!(snapshot.supplies[0].percentage, None);
        assert_eq!(snapshot.supplies[1].percentage, Some(50));
        assert_eq!(snapshot.serial_hint.as_deref(), Some("SERIAL"));
        assert!(!snapshot.billing_approved && !snapshot.can_enable_monitoring);
    }

    #[test]
    fn truncated_or_unknown_values_never_count_as_verified() {
        assert_eq!(bounded_text(&[b'A',0,b'B',b'\n']), "AB");
        assert!(row("1.3.6.1.2.1.43.10.2.1.4",
            "1.3.6.1.2.1.43.10.2.1.4.1", ObservedValue::Counter(10), 2).is_none());
        assert!(row("1.3.6.1.2.1.43.10.2.1.4",
            "1.3.6.1.2.1.43.10.2.1.45.1.2", ObservedValue::Counter(10), 2).is_none());
    }
}
