//! Manufacturer-neutral MIB discovery metadata; **not** a list of certified models.
//! Read-only OIDs from IETF Printer-MIB v2 (RFC 3805), HOST-RESOURCES-MIB
//! (RFC 2790) and SNMPv2-MIB (RFC 3418).
//!
//! Exact model/firmware/OID/unit combinations require controlled field tests.
//! No entry in this registry enables polling, customer enrollment or billing.
use serde::Serialize;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Manufacturer {
    Ricoh,
    KonicaMinolta,
    Kyocera,
    Hp,
    Samsung,
    Canon,
    Epson,
    Xerox,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Kind {
    Identification,
    Counter,
    CounterUnit,
    Consumable,
    ConsumableCapacity,
    ConsumableLevel,
    Status,
    Alert,
}

#[derive(Clone, Debug, Serialize)]
pub struct MibObject {
    pub name: &'static str,
    pub module: &'static str,
    /// Numeric *column* OID; for a table, obtain instance indexes with WALK.
    pub oid: &'static str,
    pub kind: Kind,
    /// Never implies this field is verified for a particular device.
    pub requires_instance_walk: bool,
    pub billing_approved: bool,
}

#[derive(Clone, Debug, Serialize)]
pub struct ManufacturerEntry {
    pub manufacturer: Manufacturer,
    pub enterprise: u32,
    pub enterprise_oid: String,
    pub mib_families: &'static [&'static str],
    pub coverage: &'static str,
    pub source: &'static str,
}

pub const VENDORS: [(Manufacturer, u32, &[&str], &str); 8] = [
    (Manufacturer::Ricoh, 367, &["RicohPrivateMIB", "Printer-MIB"], "https://emea.ricoh-developer.com/node/636"),
    (Manufacturer::KonicaMinolta, 18334, &["Printer-MIB", "vendor-dependent private MIB"], "https://manuals.konicaminolta.eu/bizhub-651i-551i-451i/EN/contents/WC_12_03_05.html"),
    (Manufacturer::Kyocera, 1347, &["KMCOMMON-MIB", "KYOCERA-MIB", "Printer-MIB"], "https://mibs.observium.org/object/KMCOMMON-MIB/kyocera"),
    (Manufacturer::Hp, 11, &["HP Jetdirect (NP) MIB", "HP PML MIB", "Printer-MIB"], "https://support.hp.com/br-pt/document/ish_6720123-7035621-16"),
    (Manufacturer::Samsung, 236, &["SAMSUNG-COMMON-MIB", "SAMSUNG-PRINTER-EXT-MIB", "Printer-MIB"], "https://mibs.observium.org/mib/SAMSUNG-COMMON-MIB/"),
    (Manufacturer::Canon, 1602, &["Printer-MIB", "Canon private MIB (device dependent)"], "https://files.canon-europe.com/files/soft03-48695/Manual/imageRUNNER_ADVANCE_6500i_Series_User_Guide_PT.pdf"),
    (Manufacturer::Epson, 1248, &["Printer-MIB", "Epson private MIB (device dependent)"], "https://download4.epson.biz/sec_pubs/wf-c8690b/admg/en/manual.pdf"),
    (Manufacturer::Xerox, 253, &["XEROX-COMMON-MIB", "XEROX-GENERAL-MIB", "Printer-MIB"], "https://origin-download.support.xerox.com/pub/drivers/MIBs/"),
];

pub fn manufacturer_entries() -> Vec<ManufacturerEntry> {
    VENDORS.iter().map(|(manufacturer, pen, mibs, source)| ManufacturerEntry {
        manufacturer: *manufacturer,
        enterprise: *pen,
        enterprise_oid: format!("1.3.6.1.4.1.{pen}"),
        mib_families: mibs,
        coverage: "generic_standard_discovery_only_not_model_certified",
        source,
    }).collect()
}

/// The objectID is an OBJECT IDENTIFIER, not the vendor's visible model name.
/// Never substring-match an OID (e.g., 1602 must not match 16020).
/// Some printer engines/OEM devices use another manufacturer's PEN; they
/// remain unclassified until additional evidence is captured.
pub fn vendor_from_sys_object_id(raw: &str) -> Option<Manufacturer> {
    let arcs = parse_oid(raw)?;
    if arcs.len() < 7 || arcs[..6] != [1, 3, 6, 1, 4, 1] {
        return None;
    }
    VENDORS.iter()
        .find_map(|(vendor, pen, _, _)| (arcs[6] == *pen).then_some(*vendor))
}

/// Strict numeric OID parser; a malformed response cannot be used to pick
/// vendor profiles or claim support for a model. Leading '.' is accepted.
pub fn parse_oid(input: &str) -> Option<Vec<u32>> {
    let raw = input.trim().strip_prefix('.').unwrap_or(input.trim());
    if raw.is_empty() || raw.len() > 512 {
        return None;
    }
    let arcs: Vec<u32> = raw.split('.')
        .map(|part| {
            if part.is_empty() || !part.bytes().all(|b| b.is_ascii_digit()) {
                None
            } else {
                part.parse::<u32>().ok()
            }
        }).collect::<Option<_>>()?;
    if arcs.len() < 2 || arcs.len() > 128 || arcs[0] > 2 || arcs[1] > 39 && arcs[0] < 2 {
        return None;
    }
    Some(arcs)
}

/// Extract complete, *walked* table indices; returning None for the column
/// itself prevents inventing .1.1 for heterogeneous printer configurations.
pub fn instance_indices(column: &str, walked_oid: &str, count: usize) -> Option<Vec<u32>> {
    let column = parse_oid(column)?;
    let actual = parse_oid(walked_oid)?;
    if actual.len() != column.len() + count || !actual.starts_with(&column) {
        return None;
    }
    Some(actual[column.len()..].to_vec())
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum MarkerCounterUnit {
    Impressions,
    Sheets,
    Other,
}

/// Printer MIB: 7=impressions, 8=sheets. Neither proves monochrome/color
/// separation, simplex/duplex semantics, accounting totals or billing fitness.
pub fn marker_counter_unit(code: i32) -> MarkerCounterUnit {
    match code {
        7 => MarkerCounterUnit::Impressions,
        8 => MarkerCounterUnit::Sheets,
        _ => MarkerCounterUnit::Other,
    }
}

/// -3 (some remaining), -2 (unknown), -1 (other) are *not* 0%.
/// Return None for uncertain capacities/units and out-of-range readings.
pub fn supply_percentage(level: i32, capacity: i32) -> Option<u8> {
    if level < 0 || capacity <= 0 || level > capacity {
        return None;
    }
    Some(((u64::from(level as u32) * 100) / u64::from(capacity as u32)) as u8)
}

pub const OIDS: &[MibObject] = &[
    MibObject { name: "sysDescr", module: "SNMPv2-MIB", oid: "1.3.6.1.2.1.1.1.0", kind: Kind::Identification, requires_instance_walk: false, billing_approved: false },
    MibObject { name: "sysObjectID", module: "SNMPv2-MIB", oid: "1.3.6.1.2.1.1.2.0", kind: Kind::Identification, requires_instance_walk: false, billing_approved: false },
    MibObject { name: "sysName", module: "SNMPv2-MIB", oid: "1.3.6.1.2.1.1.5.0", kind: Kind::Identification, requires_instance_walk: false, billing_approved: false },
    MibObject { name: "hrDeviceDescr", module: "HOST-RESOURCES-MIB", oid: "1.3.6.1.2.1.25.3.2.1.3", kind: Kind::Identification, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "hrPrinterStatus", module: "HOST-RESOURCES-MIB", oid: "1.3.6.1.2.1.25.3.5.1.1", kind: Kind::Status, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "hrPrinterDetectedErrorState", module: "HOST-RESOURCES-MIB", oid: "1.3.6.1.2.1.25.3.5.1.2", kind: Kind::Alert, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtGeneralPrinterName", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.5.1.1.16", kind: Kind::Identification, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtGeneralSerialNumber", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.5.1.1.17", kind: Kind::Identification, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerCounterUnit", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.10.2.1.3", kind: Kind::CounterUnit, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerLifeCount", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.10.2.1.4", kind: Kind::Counter, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerPowerOnCount", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.10.2.1.5", kind: Kind::Counter, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerProcessColorants", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.10.2.1.6", kind: Kind::Identification, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesClass", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.4", kind: Kind::Consumable, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesType", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.5", kind: Kind::Consumable, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesDescription", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.6", kind: Kind::Consumable, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesSupplyUnit", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.7", kind: Kind::Consumable, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesMaxCapacity", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.8", kind: Kind::ConsumableCapacity, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerSuppliesLevel", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.11.1.1.9", kind: Kind::ConsumableLevel, requires_instance_walk: true, billing_approved: false },
    MibObject { name: "prtMarkerColorantValue", module: "Printer-MIB", oid: "1.3.6.1.2.1.43.12.1.1.4", kind: Kind::Consumable, requires_instance_walk: true, billing_approved: false },
];

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn all_eight_manufacturers_have_distinct_enterprise_ids() {
        let entries = manufacturer_entries();
        assert_eq!(entries.len(), 8);
        let distinct: std::collections::HashSet<_> =
            entries.iter().map(|entry| entry.enterprise).collect();
        assert_eq!(distinct.len(), 8);
        for entry in entries {
            assert_eq!(vendor_from_sys_object_id(&format!("{}.5.10.1", entry.enterprise_oid)), Some(entry.manufacturer));
            assert_eq!(entry.coverage, "generic_standard_discovery_only_not_model_certified");
        }
    }

    #[test]
    fn wrong_or_ambiguous_sys_object_id_never_guesses_vendor() {
        for value in [
            "1.3.6.1.4.1.16020.1", "1.3.6.1.4.1.180", "1.3.6.1.2.1.43",
            ".1.3.6.1.4.1.9999999.2", "HP LaserJet", "1.3.6.1.4.1.-1", "",
            "1.3.6.1.4.1.11something", "1..3.6.1.4.1.11", "1.3.6.1.4.1.0",
        ] {
            assert_eq!(vendor_from_sys_object_id(value), None, "{value}");
        }
        assert_eq!(vendor_from_sys_object_id(".1.3.6.1.4.1.1602.12"), Some(Manufacturer::Canon));
    }

    #[test]
    fn discovered_table_index_is_not_fabricated() {
        let column = "1.3.6.1.2.1.43.10.2.1.4";
        assert_eq!(instance_indices(column, "1.3.6.1.2.1.43.10.2.1.4.2.1", 2), Some(vec![2,1]));
        assert_eq!(instance_indices(column, "1.3.6.1.2.1.43.10.2.1.4.1", 2), None);
        assert_eq!(instance_indices(column, column, 2), None);
        assert_eq!(instance_indices(column, "1.3.6.1.2.1.43.10.2.1.45.1.1", 2), None);
    }

    #[test]
    fn supplies_and_counters_do_not_invent_page_counts_or_percentages() {
        assert_eq!(marker_counter_unit(7), MarkerCounterUnit::Impressions);
        assert_eq!(marker_counter_unit(8), MarkerCounterUnit::Sheets);
        assert_eq!(marker_counter_unit(3), MarkerCounterUnit::Other);
        assert_eq!(supply_percentage(-3, 100), None);
        assert_eq!(supply_percentage(-2, 100), None);
        assert_eq!(supply_percentage(-1, 100), None);
        assert_eq!(supply_percentage(0, 0), None);
        assert_eq!(supply_percentage(110, 100), None);
        assert_eq!(supply_percentage(0, 100), Some(0));
        assert_eq!(supply_percentage(100, 100), Some(100));
        assert_eq!(supply_percentage(30, 200), Some(15));
        assert!(OIDS.iter().all(|item| !item.billing_approved && parse_oid(item.oid).is_some()));
    }
}
