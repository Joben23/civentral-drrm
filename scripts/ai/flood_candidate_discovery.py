"""Fail-closed helpers for Phase 3B3-A flood candidate discovery.

Groups report revisions, flags possible duplicate episodes, validates discovery
metadata, and computes acquisition priority. It never labels or trains.
"""

from __future__ import annotations

import copy
import re
from dataclasses import dataclass
from datetime import date
from typing import Any, Dict, Iterable, List, Mapping, Sequence, Tuple


PRIORITY_CRITERIA = (
    "official_government_source", "caloocan_explicit", "flooding_explicit",
    "event_date_supported", "event_timing_available", "location_detail_available",
    "multiple_official_reports", "source_documents_accessible",
    "independent_from_june_2019", "imerg_historical_availability",
)
DOCUMENT_ACCESS = {
    "AVAILABLE_FOR_ACQUISITION", "PARTIALLY_AVAILABLE",
    "EXTERNAL_ACCESS_REQUIRED", "NOT_FOUND",
}
ACTIVE_CANDIDATE_SOURCE_STATUS = {"PROPOSED", "ACQUIRED"}
SPATIAL_STATUS = {"UNREVIEWED", "UNKNOWN_SPATIAL_MAPPING"}
LOCATION_PRECISION = {"CITY", "STREET_OR_LOCALITY", "MULTI_BARANGAY"}
IMERG_AVAILABILITY = {
    "WITHIN_KNOWN_1998_PRESENT_COVERAGE_METADATA_ONLY",
    "OUTSIDE_KNOWN_COVERAGE", "UNVERIFIED",
}


@dataclass(frozen=True)
class CandidateIssue:
    candidate_id: str
    code: str
    message: str


def _norm(value: Any) -> str:
    return re.sub(r"[^a-z0-9]+", " ", str(value or "").lower()).strip()


def acquisition_priority(criteria: Mapping[str, Any]) -> Tuple[int, str]:
    """Return a stable evidence-acquisition score and band without mutation."""
    score = sum(1 for key in PRIORITY_CRITERIA if criteria.get(key) is True)
    if score >= 8:
        return score, "HIGH_PRIORITY"
    if score >= 5:
        return score, "MEDIUM_PRIORITY"
    return score, "LOW_PRIORITY"


def cluster_report_records(records: Iterable[Mapping[str, Any]]) -> List[Dict[str, Any]]:
    """Group report revisions by explicit episode key; never infer a merge."""
    clusters: Dict[str, Dict[str, Any]] = {}
    for record in records:
        episode_key = record.get("event_cluster_id")
        report = record.get("report")
        if not isinstance(episode_key, str) or not episode_key.strip():
            raise ValueError("Every report record requires an explicit event_cluster_id.")
        if not isinstance(report, Mapping) or not isinstance(report.get("url"), str):
            raise ValueError("Every report record requires report metadata with a URL.")
        cluster = clusters.setdefault(episode_key, {"event_cluster_id": episode_key, "reports": []})
        if report["url"] not in {item["url"] for item in cluster["reports"]}:
            cluster["reports"].append(dict(report))
    for cluster in clusters.values():
        cluster["reports"].sort(key=lambda item: item["url"])
    return [clusters[key] for key in sorted(clusters)]


def duplicate_reasons(left: Mapping[str, Any], right: Mapping[str, Any]) -> List[str]:
    """Return deterministic duplicate signals; callers must review, not merge."""
    reasons: List[str] = []
    if left.get("event_cluster_id") == right.get("event_cluster_id"):
        reasons.append("SAME_EVENT_CLUSTER_ID")
    left_urls = {x.get("url") for x in left.get("official_document_links", []) if isinstance(x, Mapping)}
    right_urls = {x.get("url") for x in right.get("official_document_links", []) if isinstance(x, Mapping)}
    if (left_urls - {None}) & (right_urls - {None}):
        reasons.append("SHARED_OFFICIAL_DOCUMENT")
    left_window = left.get("event_window", {})
    right_window = right.get("event_window", {})
    if (
        left_window.get("start") == right_window.get("start")
        and left.get("hazard_type") == right.get("hazard_type")
        and _norm(left.get("agency")) == _norm(right.get("agency"))
        and _norm(left.get("location_wording")) == _norm(right.get("location_wording"))
    ):
        reasons.append("SAME_DATE_HAZARD_AGENCY_LOCATION")
    if _norm(left.get("official_source")) == _norm(right.get("official_source")) and left.get("hazard_type") == right.get("hazard_type"):
        reasons.append("SAME_NORMALIZED_INCIDENT_TITLE")
    return sorted(set(reasons))


def find_possible_duplicates(candidates: Sequence[Mapping[str, Any]]) -> List[Dict[str, Any]]:
    flags: List[Dict[str, Any]] = []
    for index, left in enumerate(candidates):
        for right in candidates[index + 1:]:
            reasons = duplicate_reasons(left, right)
            if reasons:
                flags.append({
                    "candidate_ids": sorted([str(left.get("candidate_id")), str(right.get("candidate_id"))]),
                    "reasons": reasons,
                    "action": "FLAG_FOR_REVIEW_NO_AUTOMATIC_MERGE",
                })
    return sorted(flags, key=lambda item: item["candidate_ids"])


def _issue(candidate: Mapping[str, Any], code: str, message: str) -> CandidateIssue:
    return CandidateIssue(str(candidate.get("candidate_id") or "candidate-index"), code, message)


def validate_candidate_index(index: Mapping[str, Any]) -> List[CandidateIssue]:
    issues: List[CandidateIssue] = []
    if index.get("index_version") != "1.1.0":
        issues.append(CandidateIssue("candidate-index", "INVALID_INDEX_VERSION", "index_version must be 1.1.0."))
    if index.get("training_use_prohibited") is not True:
        issues.append(CandidateIssue("candidate-index", "TRAINING_USE_NOT_PROHIBITED", "Discovery candidates cannot be used for training."))
    if index.get("prediction_target_status") != "PENDING_EVIDENCE_REVIEW":
        issues.append(CandidateIssue("candidate-index", "TARGET_PREMATURELY_SELECTED", "The ML target must remain pending."))
    candidates = index.get("candidates")
    if not isinstance(candidates, list):
        return issues + [CandidateIssue("candidate-index", "INVALID_CANDIDATES", "candidates must be an array.")]
    seen_ids: set[str] = set()
    seen_clusters: set[str] = set()
    required = {
        "candidate_id", "registry_event_id", "event_cluster_id", "hazard_type", "event_window",
        "official_source", "agency", "source_id", "source_reference", "official_incident_page",
        "official_document_links", "source_classification", "source_status", "document_access_status",
        "location_wording", "supported_barangays", "location_precision", "barangay_mapping_status",
        "legacy_barangay_176_remapped", "evidence_summary", "uncertainties", "label_status",
        "negative_evidence_basis", "spatial_mapping_status", "review_status", "training_eligible",
        "imerg_historical_availability", "priority_criteria", "priority_score", "acquisition_priority",
        "secondary_corroboration", "phase_3b3b_acquisition_selected", "notes",
    }
    for candidate in candidates:
        if not isinstance(candidate, Mapping):
            issues.append(CandidateIssue("candidate-index", "INVALID_CANDIDATE", "Each candidate must be an object."))
            continue
        missing = sorted(required - set(candidate))
        if missing:
            issues.append(_issue(candidate, "MISSING_CANDIDATE_FIELDS", ", ".join(missing)))
        candidate_id = candidate.get("candidate_id")
        if not isinstance(candidate_id, str) or not candidate_id:
            issues.append(_issue(candidate, "INVALID_CANDIDATE_ID", "candidate_id is required."))
        elif candidate_id in seen_ids:
            issues.append(_issue(candidate, "DUPLICATE_CANDIDATE_ID", "candidate_id must be unique."))
        else:
            seen_ids.add(candidate_id)
        cluster_id = candidate.get("event_cluster_id")
        if not isinstance(cluster_id, str) or not cluster_id:
            issues.append(_issue(candidate, "MISSING_EVENT_CLUSTER_ID", "An explicit event cluster is required."))
        elif cluster_id in seen_clusters:
            issues.append(_issue(candidate, "DUPLICATE_EVENT_CLUSTER", "Report revisions must remain one candidate event."))
        else:
            seen_clusters.add(cluster_id)
        if candidate.get("hazard_type") != "FLOOD":
            issues.append(_issue(candidate, "INVALID_HAZARD_TYPE", "Only flood candidates belong in this registry."))
        if candidate.get("label_status") != "UNKNOWN":
            issues.append(_issue(candidate, "DISCOVERY_LABEL_NOT_UNKNOWN", "Discovery cannot assign a flood or no-flood label."))
        if candidate.get("negative_evidence_basis") is not None:
            issues.append(_issue(candidate, "NEGATIVE_INFERRED_DURING_DISCOVERY", "Discovery cannot create negative evidence."))
        if candidate.get("review_status") != "REQUIRES_HUMAN_REVIEW":
            issues.append(_issue(candidate, "HUMAN_REVIEW_BYPASSED", "Candidate review must remain pending."))
        if candidate.get("training_eligible") is not False:
            issues.append(_issue(candidate, "DISCOVERY_CANDIDATE_TRAINING_ELIGIBLE", "Discovery candidates cannot be training eligible."))
        if candidate.get("source_classification") != "PRIMARY_OFFICIAL_GOVERNMENT":
            issues.append(_issue(candidate, "MISSING_PRIMARY_OFFICIAL_EVIDENCE", "Primary evidence must be an official government source."))
        if candidate.get("source_status") not in ACTIVE_CANDIDATE_SOURCE_STATUS:
            issues.append(_issue(candidate, "INVALID_SOURCE_STATUS", "An active candidate source must be PROPOSED or ACQUIRED; rejected findings belong in screened_out."))
        if candidate.get("document_access_status") not in DOCUMENT_ACCESS:
            issues.append(_issue(candidate, "INVALID_DOCUMENT_ACCESS_STATUS", "Document access status is invalid."))
        if candidate.get("spatial_mapping_status") not in SPATIAL_STATUS:
            issues.append(_issue(candidate, "INVALID_SPATIAL_MAPPING_STATUS", "Spatial mapping status is invalid."))
        if candidate.get("location_precision") not in LOCATION_PRECISION:
            issues.append(_issue(candidate, "INVALID_LOCATION_PRECISION", "Location precision is invalid."))
        if candidate.get("imerg_historical_availability") not in IMERG_AVAILABILITY:
            issues.append(_issue(candidate, "INVALID_IMERG_AVAILABILITY", "IMERG availability metadata is invalid."))
        window = candidate.get("event_window")
        if not isinstance(window, Mapping) or not isinstance(window.get("start"), str):
            issues.append(_issue(candidate, "MISSING_EVENT_DATE", "A supported calendar date is required."))
        else:
            try:
                date.fromisoformat(window["start"])
                if window.get("end") is not None:
                    date.fromisoformat(window["end"])
            except (TypeError, ValueError):
                issues.append(_issue(candidate, "INVALID_EVENT_DATE", "Event dates must use YYYY-MM-DD."))
            if window.get("report_issue_used_as_event_time") is not False:
                issues.append(_issue(candidate, "REPORT_ISSUE_TIME_USED_AS_ONSET", "Report issue time cannot become event onset."))
        links = candidate.get("official_document_links")
        if not isinstance(links, list) or not links:
            issues.append(_issue(candidate, "MISSING_OFFICIAL_EVIDENCE", "At least one official document locator is required."))
        else:
            for link in links:
                if not isinstance(link, Mapping) or not str(link.get("url", "")).startswith("https://"):
                    issues.append(_issue(candidate, "INVALID_OFFICIAL_DOCUMENT", "Official document links must be HTTPS metadata objects."))
                elif link.get("access_status") not in DOCUMENT_ACCESS:
                    issues.append(_issue(candidate, "INVALID_DOCUMENT_ACCESS_STATUS", "Each document needs an allowed access status."))
        location_wording = _norm(candidate.get("location_wording"))
        for barangay in candidate.get("supported_barangays", []):
            if _norm(barangay) not in location_wording:
                issues.append(_issue(candidate, "UNSUPPORTED_BARANGAY_INFERENCE", f"{barangay!r} is not present in official location wording."))
        if candidate.get("legacy_barangay_176_remapped") is not False:
            issues.append(_issue(candidate, "LEGACY_BARANGAY_176_REMAPPED", "Legacy Barangay 176 must not be silently remapped."))
        if "barangay 176" in location_wording or "brgy 176" in location_wording:
            if any(re.search(r"176\s*-\s*[a-f]", str(value), re.IGNORECASE) for value in candidate.get("supported_barangays", [])):
                issues.append(_issue(candidate, "LEGACY_BARANGAY_176_REMAPPED", "Legacy Barangay 176 was expanded without official support."))
        criteria = candidate.get("priority_criteria")
        if not isinstance(criteria, Mapping) or set(criteria) != set(PRIORITY_CRITERIA) or any(not isinstance(value, bool) for value in criteria.values()):
            issues.append(_issue(candidate, "INVALID_PRIORITY_CRITERIA", "All priority criteria must be explicit booleans."))
        else:
            expected_score, expected_priority = acquisition_priority(criteria)
            if candidate.get("priority_score") != expected_score or candidate.get("acquisition_priority") != expected_priority:
                issues.append(_issue(candidate, "NONDETERMINISTIC_PRIORITY", "Stored priority does not match deterministic criteria."))
        if candidate.get("secondary_corroboration") and candidate.get("source_classification") != "PRIMARY_OFFICIAL_GOVERNMENT":
            issues.append(_issue(candidate, "SECONDARY_EVIDENCE_USED_AS_PRIMARY", "Secondary evidence cannot replace official primary evidence."))
    if find_possible_duplicates(candidates):
        issues.append(CandidateIssue("candidate-index", "UNRESOLVED_DUPLICATE_CANDIDATES", "Possible duplicate episodes require explicit resolution."))
    if index.get("additional_candidate_count") != max(0, len(candidates) - 1):
        issues.append(CandidateIssue("candidate-index", "INCORRECT_ADDITIONAL_CANDIDATE_COUNT", "additional_candidate_count is inconsistent."))
    return issues


def reprioritize(candidate: Mapping[str, Any]) -> Dict[str, Any]:
    """Return a copy with deterministic priority while preserving label state."""
    result = copy.deepcopy(dict(candidate))
    score, priority = acquisition_priority(result.get("priority_criteria", {}))
    result["priority_score"] = score
    result["acquisition_priority"] = priority
    return result
