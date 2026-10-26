//! Read-only PrintWayy compatibility snapshot supplied with the local installer.
//! Its approval flags describe PrintWayy, not a Nexa field certification.
//! No catalog entry alone enrolls a device or authorizes billing.
use serde::{Deserialize, Serialize};
use std::collections::BTreeSet;
use std::sync::OnceLock;

const DATA: &str = include_str!("../data/printwayy_reference_2026-09-22.json");
static CATALOG: OnceLock<ReferenceCatalog> = OnceLock::new();

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum ReferenceStatus {
    NotTested,
    Approved,
    Incompatible,
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ReferenceModel {
    pub source_model_id: u64,
    pub manufacturer: String,
    pub model: String,
    pub network: ReferenceStatus,
    pub usb: ReferenceStatus,
    /// Candidates for PrintWayy's total counter. A unique candidate can be
    /// selected by an explicit local model ID; it is never a Nexa certificate.
    pub network_total_oid_candidates: Vec<String>,
}

#[derive(Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct ReferenceCatalog {
    pub schema_version: u32,
    pub source: String,
    pub snapshot_at: String,
    pub source_sha256: String,
    pub models: Vec<ReferenceModel>,
}

#[derive(Debug, Serialize)]
pub struct ReferenceSearchResult {
    pub source: String,
    pub snapshot_at: String,
    pub total_catalog: usize,
    pub total_matches: usize,
    pub results: Vec<ReferenceModel>,
    pub nexa_field_certified: bool,
}

pub fn catalog() -> &'static ReferenceCatalog {
    CATALOG.get_or_init(|| {
        serde_json::from_str(DATA).expect("bundled PrintWayy reference catalog must be valid")
    })
}

pub fn model_by_id(id: u64) -> Option<&'static ReferenceModel> {
    catalog()
        .models
        .iter()
        .find(|model| model.source_model_id == id)
}

/// Match an observed device name only at alphanumeric word boundaries. Model
/// punctuation is ignored so `WF-C5890` and `WF C5890` can agree, while `M401`
/// cannot silently match `M401n`. Short numeric names require explicit IDs.
fn folded_match_length(observed: &str, model: &str, minimum_length: usize) -> Option<usize> {
    let folded_model: Vec<char> = model.chars()
        .filter(char::is_ascii_alphanumeric)
        .map(|c| c.to_ascii_uppercase()).collect();
    if folded_model.len() < minimum_length { return None; }
    let original: Vec<char> = observed.chars().collect();
    let compact: Vec<(char, bool, bool)> = original.iter().enumerate()
        .filter_map(|(index, character)| character.is_ascii_alphanumeric().then(|| (
            character.to_ascii_uppercase(),
            index == 0 || !original[index - 1].is_ascii_alphanumeric(),
            index + 1 == original.len() || !original[index + 1].is_ascii_alphanumeric(),
        ))).collect();
    compact.windows(folded_model.len()).any(|window| {
        window.first().is_some_and(|part| part.1)
            && window.last().is_some_and(|part| part.2)
            && window.iter().map(|part| part.0).eq(folded_model.iter().copied())
    }).then_some(folded_model.len())
}

fn model_match_length(observed: &str, model: &str) -> Option<usize> {
    folded_match_length(observed, model, 4)
}

/// Interpret a Windows USB friendly name as PrintWayy reference evidence only
/// if the name contains both manufacturer and the strongest model name, and
/// every matching source record agrees on its USB status.
pub fn usb_status_for_friendly_name(name: &str) -> (Vec<u64>, Option<ReferenceStatus>) {
    let mut matches = strongest_name_matches(&[name.to_owned()]);
    matches.retain(|model| folded_match_length(name, &model.manufacturer, 2).is_some());
    if matches.is_empty() { return (Vec::new(), None); }
    let status = matches.first().map(|m| m.usb).filter(|status| {
        matches.iter().all(|model| model.usb == *status)
    });
    (matches.into_iter().map(|model| model.source_model_id).collect(), status)
}

/// Return every strongest name match. A caller must inspect every match's
/// interface status before claiming compatibility; duplicate source records
/// can disagree. The result is advisory identity evidence, never enrollment.
pub fn strongest_name_matches(observed: &[String]) -> Vec<&'static ReferenceModel> {
    let mut best = 0;
    let mut matches = Vec::new();
    for model in &catalog().models {
        let length = observed.iter()
            .filter_map(|text| model_match_length(text, &model.model))
            .max().unwrap_or(0);
        if length == 0 { continue; }
        if length > best {
            best = length;
            matches.clear();
        }
        if length == best { matches.push(model); }
    }
    matches
}

/// Resolve a network meter only when the strongest observed model names all
/// have PrintWayy network approval and agree on one direct total OID.
pub fn unique_network_total_for_identity(observed: &[String]) -> Result<Vec<u64>, &'static str> {
    let mut candidates = BTreeSet::new();
    for identity in observed {
        let matches: Vec<_> = strongest_name_matches(std::slice::from_ref(identity))
            .into_iter()
            .filter(|model| observed.iter().any(|text| {
                folded_match_length(text, &model.manufacturer, 2).is_some()
            }))
            .collect();
        if matches.is_empty() { continue; }
        if matches.iter().any(|m| m.network != ReferenceStatus::Approved) {
            return Err("SNMP identity matches a model without uniform network approval");
        }
        if matches.iter().any(|m| m.network_total_oid_candidates.len() != 1) {
            return Err("SNMP identity has no unambiguous direct total-counter OID");
        }
        candidates.extend(matches.iter()
            .flat_map(|m| m.network_total_oid_candidates.iter().map(String::as_str)));
    }
    if candidates.is_empty() { return Err("SNMP identity did not match a reference model"); }
    if candidates.len() != 1 {
        return Err("SNMP identity fields disagree on the total-counter OID");
    }
    let oid = candidates.into_iter().next().unwrap();
    crate::mib_registry::parse_oid(oid)
        .map(|arcs| arcs.into_iter().map(u64::from).collect())
        .ok_or("reference total-counter OID is malformed")
}

/// Identify a referenced network model even when the source has no usable
/// total-counter OID. Reference approval is not Nexa hardware certification.
#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
pub struct NetworkReferenceMatch {
    pub manufacturer: String,
    pub model: String,
    pub reference_model_ids: Vec<u64>,
    pub network_status: Option<ReferenceStatus>,
    pub total_counter_oid: Option<String>,
}

/// Require matching manufacturer + model from live SNMP identity. Conflicting
/// source rows or different model names are retained as unknown, never approved.
pub fn network_reference_for_identity(observed: &[String]) -> Option<NetworkReferenceMatch> {
    let mut matches = Vec::new();
    for identity in observed {
        let candidates = strongest_name_matches(std::slice::from_ref(identity));
        matches.extend(candidates.into_iter().filter(|entry| {
            observed.iter().any(|text|
                folded_match_length(text, &entry.manufacturer, 2).is_some()
            )
        }));
    }
    matches.sort_by_key(|entry| entry.source_model_id);
    matches.dedup_by_key(|entry| entry.source_model_id);
    let first = *matches.first()?;
    // Do not merge similarly named models from different vendors or versions.
    if matches.iter().any(|entry| {
        !entry.model.eq_ignore_ascii_case(&first.model)
            || !entry.manufacturer.eq_ignore_ascii_case(&first.manufacturer)
    }) {
        return None;
    }
    let status = matches.iter().all(|entry| entry.network == first.network)
        .then_some(first.network);
    let unique_oid = if status == Some(ReferenceStatus::Approved) {
        let oids: BTreeSet<&str> = matches.iter()
            .filter_map(|entry| match entry.network_total_oid_candidates.as_slice() {
                [only] => Some(only.as_str()),
                _ => None,
            }).collect();
        (oids.len() == 1
            && matches.iter().all(|entry| entry.network_total_oid_candidates.len() == 1))
            .then(|| oids.into_iter().next().unwrap().to_owned())
            .filter(|oid| crate::mib_registry::parse_oid(oid).is_some())
    } else {
        None
    };
    Some(NetworkReferenceMatch {
        manufacturer: first.manufacturer.clone(),
        model: first.model.clone(),
        reference_model_ids: matches.into_iter().map(|entry| entry.source_model_id).collect(),
        network_status: status,
        total_counter_oid: unique_oid,
    })
}

/// Returns only an unambiguous total-counter OID for a model approved for
/// network monitoring in the source snapshot. The operator still binds the
/// correct physical device and backend equipment ID in CollectorConfig.
pub fn unique_network_total_oid(id: u64) -> Result<Vec<u64>, &'static str> {
    let model = model_by_id(id).ok_or("unknown reference model ID")?;
    if model.network != ReferenceStatus::Approved {
        return Err("reference model is not approved for network monitoring");
    }
    let [oid] = model.network_total_oid_candidates.as_slice() else {
        return Err("reference model has no unique total-counter OID");
    };
    crate::mib_registry::parse_oid(oid)
        .map(|arcs| arcs.into_iter().map(u64::from).collect())
        .ok_or("reference total-counter OID is malformed")
}

/// Bounded read-only lookup for the local Console. Empty search lists the
/// first models in catalog order; `total_matches` always covers all entries.
pub fn search(
    query: &str,
    filter: &str,
    limit: usize,
) -> Result<ReferenceSearchResult, &'static str> {
    let query = query.trim();
    if query.chars().count() > 120 || limit == 0 || limit > 200 {
        return Err("invalid catalog search bounds");
    }
    if !matches!(filter, "all" | "network" | "usb" | "both") {
        return Err("invalid catalog interface filter");
    }
    let needle = query.to_lowercase();
    let mut total_matches = 0;
    let mut results = Vec::new();
    for model in &catalog().models {
        let included = match filter {
            "network" => model.network == ReferenceStatus::Approved,
            "usb" => model.usb == ReferenceStatus::Approved,
            "both" => {
                model.network == ReferenceStatus::Approved && model.usb == ReferenceStatus::Approved
            }
            _ => true,
        };
        if !included
            || !format!("{} {}", model.manufacturer, model.model)
                .to_lowercase()
                .contains(&needle)
        {
            continue;
        }
        total_matches += 1;
        if results.len() < limit {
            results.push(model.clone());
        }
    }
    let catalog = catalog();
    Ok(ReferenceSearchResult {
        source: catalog.source.clone(),
        snapshot_at: catalog.snapshot_at.clone(),
        total_catalog: catalog.models.len(),
        total_matches,
        results,
        nexa_field_certified: false,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashSet;

    #[test]
    fn snapshot_has_all_models_and_source_status_counts() {
        let data = catalog();
        assert_eq!(data.schema_version, 1);
        assert_eq!(data.models.len(), 2_142);
        assert_eq!(
            data.models
                .iter()
                .filter(|m| m.network == ReferenceStatus::Approved)
                .count(),
            1_898
        );
        assert_eq!(
            data.models
                .iter()
                .filter(|m| m.usb == ReferenceStatus::Approved)
                .count(),
            920
        );
        assert_eq!(
            data.models
                .iter()
                .filter(|m| m.network == ReferenceStatus::Approved
                    && m.usb == ReferenceStatus::Approved)
                .count(),
            688
        );
        assert_eq!(
            data.models
                .iter()
                .map(|m| m.source_model_id)
                .collect::<HashSet<_>>()
                .len(),
            2_142
        );
    }

    #[test]
    fn lookup_preserves_statuses_and_rejects_ambiguous_or_missing_oids() {
        let hp = model_by_id(31_215).unwrap();
        assert_eq!(hp.model, "OfficeJet 7740");
        assert_eq!(hp.network, ReferenceStatus::Approved);
        assert_eq!(hp.usb, ReferenceStatus::NotTested);
        assert!(unique_network_total_oid(hp.source_model_id).is_err());
        let epson = model_by_id(31_946).unwrap();
        assert_eq!(epson.model, "L6270");
        assert!(unique_network_total_oid(epson.source_model_id).is_ok());
        assert!(unique_network_total_oid(u64::MAX).is_err());
        let without_oid = catalog()
            .models
            .iter()
            .find(|m| {
                m.network == ReferenceStatus::Approved && m.network_total_oid_candidates.is_empty()
            })
            .unwrap();
        assert!(unique_network_total_oid(without_oid.source_model_id).is_err());
    }

    #[test]
    fn search_is_bounded_and_filters_each_interface() {
        let result = search("L6270", "both", 50).unwrap();
        assert_eq!(result.total_matches, 1);
        assert_eq!(result.results[0].manufacturer, "EPSON");
        assert!(!result.nexa_field_certified);
        assert_eq!(search("", "network", 1).unwrap().total_matches, 1_898);
        assert_eq!(search("", "usb", 1).unwrap().total_matches, 920);
        assert!(search("", "all", 201).is_err());
    }

    #[test]
    fn all_approved_network_models_are_detectable_without_inventing_meters() {
        let epson = network_reference_for_identity(&["EPSON L6270 Series".into()]).unwrap();
        assert_eq!(epson.reference_model_ids, vec![31_946]);
        assert_eq!(epson.network_status, Some(ReferenceStatus::Approved));
        assert!(epson.total_counter_oid.is_some());

        let hp = network_reference_for_identity(&["HP OfficeJet 7740".into()]).unwrap();
        assert_eq!(hp.network_status, Some(ReferenceStatus::Approved));
        assert_eq!(hp.reference_model_ids, vec![31_215]);
        assert_eq!(hp.total_counter_oid, None);

        assert!(network_reference_for_identity(&["L6270 Series".into()]).is_none());
        assert!(network_reference_for_identity(&["Unrecognized device".into()]).is_none());
        assert!(network_reference_for_identity(&[
            "EPSON L6270 Series".into(), "HP OfficeJet 7740".into(),
        ]).is_none());
    }

    #[test]
    fn identity_match_is_boundary_aware_and_requires_uniform_approval() {
        assert_eq!(model_match_length("EPSON L6270 Series", "L6270"), Some(5));
        assert_eq!(model_match_length("EPSON WF C5890 Series", "WF-C5890"), Some(7));
        assert_eq!(model_match_length("LaserJet M401n", "M401"), None);
        assert_eq!(model_match_length("HP 206", "206"), None);
        let observed = vec!["EPSON L6270 Series".to_owned()];
        let expected = unique_network_total_oid(31_946).unwrap();
        assert_eq!(unique_network_total_for_identity(&observed).unwrap(), expected);
        assert!(unique_network_total_for_identity(&["L6270 Series".to_owned()]).is_err());
        assert!(unique_network_total_for_identity(&["unknown device".to_owned()]).is_err());
        let (ids, status) = usb_status_for_friendly_name("EPSON L6270 Series");
        assert!(ids.contains(&31_946));
        assert_eq!(status, Some(ReferenceStatus::Approved));
        assert_eq!(usb_status_for_friendly_name("Generic USB Printer"), (vec![], None));
        let (conflicting_ids, conflicting_status) = usb_status_for_friendly_name("EPSON WF-6090");
        assert!(conflicting_ids.contains(&31_929) && conflicting_ids.contains(&31_930));
        assert_eq!(conflicting_status, None);
    }
}
