//! Review decisions are local operator notes, not equipment identity or permission to poll.
//! They never modify CollectorConfig.printers or create meter samples.
use crate::{discovery::DiscoveryCandidate, CollectorError};
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use std::{collections::HashSet, net::Ipv4Addr, str::FromStr};

pub const MAX_REVIEWS: usize = 200;

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum CandidateDecision {
    Saved,
    Ignored,
}

#[derive(Clone, Copy, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum ReviewAction {
    Save,
    Ignore,
    Reset,
}

impl FromStr for ReviewAction {
    type Err = CollectorError;
    fn from_str(value: &str) -> Result<Self, Self::Err> {
        match value {
            "save" => Ok(Self::Save),
            "ignore" => Ok(Self::Ignore),
            "reset" => Ok(Self::Reset),
            _ => Err(CollectorError::Configuration("invalid candidate review action".into())),
        }
    }
}

#[derive(Clone, Debug, Deserialize, Serialize)]
#[serde(deny_unknown_fields)]
pub struct CandidateReview {
    // Snapshot of observed evidence, not proof of hardware identity.
    pub candidate: DiscoveryCandidate,
    pub decision: CandidateDecision,
    pub decided_at: DateTime<Utc>,
}

pub fn validate_reviews(reviews: &[CandidateReview]) -> Result<(), CollectorError> {
    if reviews.len() > MAX_REVIEWS {
        return Err(CollectorError::Configuration("candidate review limit exceeded".into()));
    }
    let mut unique = HashSet::<Ipv4Addr>::new();
    for review in reviews {
        if !unique.insert(review.candidate.ip) {
            return Err(CollectorError::Configuration("duplicate reviewed IP".into()));
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_unsupported_decisions() {
        assert_eq!("save".parse::<ReviewAction>().unwrap(), ReviewAction::Save);
        assert!("monitor".parse::<ReviewAction>().is_err());
        assert!("".parse::<ReviewAction>().is_err());
    }
}
