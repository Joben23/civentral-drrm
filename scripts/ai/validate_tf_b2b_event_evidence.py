#!/usr/bin/env python3
"""Validate TF-B2B evidence without creating labels or training data."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from dataclasses import dataclass
from datetime import date, datetime
from pathlib import Path, PurePosixPath, PureWindowsPath
from typing import Any

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DEFAULT_EVIDENCE = WORKSPACE / "manifests/tf-b2b-event-evidence-adjudication.json"
DEFAULT_BACKLOG = WORKSPACE / "manifests/tf-b2b-negative-evidence-acquisition-backlog.json"
SCHEMA_DIRECTORY = WORKSPACE / "schemas"
EVIDENCE_SCHEMA = SCHEMA_DIRECTORY / "tf-b2b-event-evidence-adjudication.schema.json"
BACKLOG_SCHEMA = SCHEMA_DIRECTORY / "tf-b2b-negative-evidence-acquisition-backlog.schema.json"
LOCAL_SCHEMA_FILES = (
    EVIDENCE_SCHEMA,
    BACKLOG_SCHEMA,
    SCHEMA_DIRECTORY / "tf-b2b-canonical-event.schema.json",
    SCHEMA_DIRECTORY / "tf-b2b-location-observation.schema.json",
    SCHEMA_DIRECTORY / "tf-b2b-source-document.schema.json",
)
CURRENT_PSGC_REFERENCE = "data/import/caloocan-current-psgc.json"
LEGACY_176_OBSERVATION_ID = "caloocan-2019-historical-barangay-176"
LEGACY_176_IDENTITY = {
    "event_id": "caloocan-flood-candidate-2019-06-24",
    "source_document_id": "dromic-caloocan-2019-terminal-report",
    "reported_location_text": "Barangay 176",
    "historical_barangay_name": "Barangay 176",
    "location_type": "BARANGAY",
    "mapping_status": "UNRESOLVED_HISTORICAL_BOUNDARY",
    "mapping_method": "EXPLICIT_HISTORICAL_BARANGAY_NO_CURRENT_CROSSWALK",
    "mapping_confidence": "UNRESOLVED",
    "barangay_psgc": None,
    "barangay_name": None,
    "training_eligible": False,
}
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
ID_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{2,159}$")
PSGC_RE = re.compile(r"^138010[0-9]{4}$")

EVIDENCE = {"CONFIRMED_EVENT_EVIDENCE", "POSSIBLE_EVENT_EVIDENCE", "INSUFFICIENT_EVIDENCE", "REJECTED_EVIDENCE", "UNKNOWN"}
OBS_TYPES = {"CONFIRMED_FLOOD_OBSERVED", "POSSIBLE_FLOOD", "CITY_LEVEL_FLOOD_ONLY", "LOCATION_REPORTED_BUT_BARANGAY_UNRESOLVED", "INSUFFICIENT_EVIDENCE"}
AVAILABILITY = {"ACQUIRED", "INDEXED_ONLY", "EXTERNAL_ACCESS_REQUIRED", "NOT_ACQUIRED"}
RELATIONSHIPS = {"INITIAL", "DISCOVERY_ONLY", "SUPPLEMENTS", "SUPERSEDES", "LATER_CUMULATIVE", "CONFLICTING_REVISION"}
ROLES = {"PRIMARY_EVENT_EVIDENCE", "DISCOVERY_EVENT_EVIDENCE", "SUPPLEMENTAL_IMPACT_CONTEXT", "NON_CONFIRMING_ACQUIRED_SOURCE"}
LOCATIONS = {"CITY", "BARANGAY", "BARANGAY_LOCALITY", "STREET", "LOCALITY", "FACILITY", "BRIDGE", "UNNAMED_BARANGAY"}
MAPPING_STATUS = {"RESOLVED_CURRENT_PSGC", "UNRESOLVED_HISTORICAL_BOUNDARY", "UNREVIEWED_EXPLICIT_BARANGAY_NUMBER", "NO_BARANGAY_STATED", "UNNAMED_BARANGAY", "CITY_LEVEL_ONLY"}
MAPPING_METHOD = {"EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE", "EXPLICIT_HISTORICAL_BARANGAY_NO_CURRENT_CROSSWALK", "EXPLICIT_BARANGAY_NUMBER_MAPPING_NOT_REVIEWED", "NO_AUTOMATIC_STREET_OR_LOCALITY_MAPPING", "NO_BARANGAY_MAPPING_CITY_SCOPE", "NO_MAPPING_UNNAMED_BARANGAY"}
TRAINING_REASONS = {"HUMAN_REVIEW_REQUIRED", "LEAKAGE_REVIEW_NOT_PASSED", "TARGET_HORIZON_NOT_APPROVED", "NO_APPROVED_PREDICTION_CUTOFF", "SOURCE_DOCUMENT_NOT_ACQUIRED", "TEMPORAL_WINDOW_UNRESOLVED", "SOURCE_TIMEZONE_UNRESOLVED", "BARANGAY_MAPPING_UNRESOLVED", "CITY_LEVEL_ONLY", "STREET_OR_LOCALITY_NOT_MAPPED", "SOURCE_REVISION_CONFLICT", "ACQUIRED_SOURCE_DOES_NOT_CONFIRM_CALOOCAN_FLOOD"}

TOP_KEYS = {"schema_version", "phase", "dataset_classification", "normalization_basis", "governance", "source_documents", "events", "observations", "unresolved_issues", "summary"}
GOV_KEYS = {"system_checked", "human_approval_claimed", "training_dataset_created", "training_matrix_created", "tensorflow_training_performed", "four_class_labels_created", "negative_labels_created", "mgb_susceptibility_used_as_target", "rainfall_regression_used_as_flood_evidence", "target_horizon_status", "training_authorization_status"}
SOURCE_KEYS = {"source_document_id", "event_id", "source_organization", "source_document_title", "source_revision", "source_public_reference", "source_publication_date", "source_time_text", "source_timezone", "source_availability", "source_acquisition_date", "source_hash", "local_file", "revision_relationship", "related_document_id", "preferred_for_event_scope", "evidence_role", "notes"}
EVENT_KEYS = {"event_id", "event_parent_id", "event_name", "weather_system", "start_date", "end_date", "timezone", "source_document_ids", "preferred_source_document_id", "preferred_source_basis", "caloocan_relevance", "spatial_precision", "temporal_precision", "evidence_status", "label_status", "system_check_status", "review_status", "reviewer", "review_timestamp", "leakage_review_status", "candidate_prediction_cutoff", "candidate_target_window", "target_horizon_status", "training_eligible", "training_ineligibility_reasons", "source_revision_conflicts", "notes"}
OBS_KEYS = {"observation_id", "event_id", "episode_cluster_id", "barangay_psgc", "barangay_name", "historical_barangay_name", "reported_location_text", "location_type", "source_document_id", "source_reference", "source_revision", "corroborating_source_document_ids", "source_access_status", "observation_type", "label_status", "flood_present", "flood_absent", "flood_depth", "flood_depth_source_text", "depth_unit", "observation_date", "timestamp", "source_time_text", "timestamp_precision", "source_timezone", "system_check_status", "review_status", "reviewer", "review_timestamp", "mapping_status", "mapping_method", "mapping_confidence", "leakage_review_status", "training_eligible", "training_ineligibility_reasons", "notes"}
ISSUE_KEYS = {"issue_id", "event_id", "category", "severity", "status", "description", "required_resolution", "blocking_training"}
SUMMARY_KEYS = {"candidate_event_count", "normalized_event_count", "confirmed_event_evidence_count", "possible_event_evidence_count", "insufficient_event_evidence_count", "rejected_event_evidence_count", "unknown_event_evidence_count", "source_document_count", "missing_official_document_count", "location_observation_count", "unresolved_location_observation_count", "unresolved_temporal_event_count", "confirmed_flood_observation_count", "barangay_resolved_positive_observation_count", "confirmed_unresolved_positive_observation_count", "human_approved_positive_count", "training_eligible_positive_count", "verified_negative_count", "training_eligible_weather_event_count", "unresolved_event_count", "target_horizon_approved"}
BACKLOG_KEYS = {"schema_version", "phase", "status", "negative_label_rule", "prohibited_negative_bases", "candidate_sources", "summary"}
BACKLOG_SOURCE_KEYS = {"source_candidate_id", "source_organization", "source_type", "desired_geographic_coverage", "desired_temporal_coverage", "acquisition_status", "acceptable_affirmative_evidence", "required_fields", "cannot_establish_negative_alone", "notes"}

@dataclass(frozen=True)
class ValidationIssue:
    code: str
    path: str
    message: str

    def as_dict(self) -> dict[str, str]:
        return {"code": self.code, "path": self.path, "message": self.message}


class RepositoryPathError(ValueError):
    """Raised before any manifest-controlled path can escape the repository."""


def read_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def resolve_repository_file(repo_root: Path, value: Any) -> Path:
    """Resolve a manifest path without permitting absolute paths or traversal.

    ``resolve()`` also follows symlinks/reparse points, so the final containment
    check rejects links that escape the repository before callers read bytes.
    """
    if not isinstance(value, str) or not value:
        raise RepositoryPathError("Repository path must be a non-empty string.")
    normalized = value.replace("\\", "/")
    if PurePosixPath(normalized).is_absolute() or PureWindowsPath(value).is_absolute():
        raise RepositoryPathError("Absolute repository paths are prohibited.")
    if ".." in PurePosixPath(normalized).parts:
        raise RepositoryPathError("Parent traversal is prohibited.")

    root = repo_root.resolve(strict=True)
    candidate = (root / Path(normalized)).resolve(strict=False)
    if candidate == root or not candidate.is_relative_to(root):
        raise RepositoryPathError("Resolved path is outside the repository.")
    if not candidate.is_file():
        raise RepositoryPathError("Repository file does not exist.")
    return candidate


def validate_schema_instance(instance: Any, schema_path: Path) -> list[ValidationIssue]:
    """Validate an instance with Draft 2020-12 and a local-only schema registry."""
    try:
        from jsonschema import Draft202012Validator, FormatChecker
        from referencing import Registry, Resource
    except ImportError:
        return [ValidationIssue(
            "SCHEMA_VALIDATOR_UNAVAILABLE",
            "$",
            "Install the pinned development dependencies before validating TF-B2B evidence.",
        )]

    try:
        allowed_schemas = {path.resolve(strict=True) for path in LOCAL_SCHEMA_FILES}
        resolved_schema = schema_path.resolve(strict=True)
        if resolved_schema not in allowed_schemas:
            raise ValueError("Schema is not in the fixed TF-B2B local registry.")
        root_schema = read_json(resolved_schema)
        registry = Registry()
        for local_schema_path in LOCAL_SCHEMA_FILES:
            schema = read_json(local_schema_path)
            Draft202012Validator.check_schema(schema)
            registry = registry.with_resource(schema["$id"], Resource.from_contents(schema))
        validator = Draft202012Validator(
            root_schema,
            registry=registry,
            format_checker=FormatChecker(),
        )
    except (OSError, KeyError, ValueError) as exc:
        return [ValidationIssue("SCHEMA_CONFIGURATION_ERROR", "$", str(exc))]

    issues: list[ValidationIssue] = []
    for error in sorted(validator.iter_errors(instance), key=lambda item: tuple(str(part) for part in item.absolute_path)):
        location = "$" + "".join(
            f"[{part}]" if isinstance(part, int) else f".{part}"
            for part in error.absolute_path
        )
        issues.append(ValidationIssue("JSON_SCHEMA_VIOLATION", location, error.message))
    return issues


def governed_barangay_pairs(repo_root: Path) -> tuple[dict[str, str], list[ValidationIssue]]:
    """Load all governed current Caloocan PSGC/name pairs from the fixed reference."""
    issues: list[ValidationIssue] = []
    try:
        path = resolve_repository_file(repo_root, CURRENT_PSGC_REFERENCE)
        payload = read_json(path)
        rows = payload["barangays"]
    except (RepositoryPathError, OSError, json.JSONDecodeError, KeyError, TypeError) as exc:
        add(issues, "INVALID_PSGC_REFERENCE", CURRENT_PSGC_REFERENCE, str(exc))
        return {}, issues

    pairs: dict[str, str] = {}
    if not isinstance(rows, list) or len(rows) != 193:
        add(issues, "INVALID_PSGC_REFERENCE", CURRENT_PSGC_REFERENCE, "Expected 193 governed barangays.")
        return {}, issues
    for index, row in enumerate(rows):
        if not isinstance(row, dict):
            add(issues, "INVALID_PSGC_REFERENCE", f"{CURRENT_PSGC_REFERENCE}[{index}]", "Expected an object.")
            continue
        code, name = row.get("current_psgc_10_digit"), row.get("barangay_name")
        if not isinstance(code, str) or not PSGC_RE.fullmatch(code) or not isinstance(name, str) or not name:
            add(issues, "INVALID_PSGC_REFERENCE", f"{CURRENT_PSGC_REFERENCE}[{index}]", "Invalid PSGC/name pair.")
        elif code in pairs or name in pairs.values():
            add(issues, "DUPLICATE_PSGC_REFERENCE", f"{CURRENT_PSGC_REFERENCE}[{index}]", "Duplicate PSGC or name.")
        else:
            pairs[code] = name
    return pairs, issues


def add(issues: list[ValidationIssue], code: str, path: str, message: str) -> None:
    issues.append(ValidationIssue(code, path, message))


def strict(issues: list[ValidationIssue], value: Any, keys: set[str], path: str) -> bool:
    if not isinstance(value, dict):
        add(issues, "EXPECTED_OBJECT", path, "Expected an object.")
        return False
    missing, extra = sorted(keys - set(value)), sorted(set(value) - keys)
    if missing:
        add(issues, "MISSING_FIELDS", path, "Missing: " + ", ".join(missing))
    if extra:
        add(issues, "UNEXPECTED_FIELDS", path, "Unexpected: " + ", ".join(extra))
    return not missing and not extra


def iso_date(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    try:
        return date.fromisoformat(value).isoformat() == value
    except ValueError:
        return False


def iso_datetime(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    try:
        parsed = datetime.fromisoformat(value[:-1] + "+00:00" if value.endswith("Z") else value)
    except ValueError:
        return False
    return parsed.tzinfo is not None and parsed.utcoffset() is not None


def string_list(value: Any) -> bool:
    return isinstance(value, list) and all(isinstance(item, str) and item for item in value)


def expected_summary(payload: dict[str, Any]) -> dict[str, Any]:
    events, sources, observations = payload["events"], payload["source_documents"], payload["observations"]
    counts = Counter(item["evidence_status"] for item in events)
    confirmed = [item for item in observations if item["observation_type"] == "CONFIRMED_FLOOD_OBSERVED"]
    return {
        "candidate_event_count": len(events),
        "normalized_event_count": len(events),
        "confirmed_event_evidence_count": counts["CONFIRMED_EVENT_EVIDENCE"],
        "possible_event_evidence_count": counts["POSSIBLE_EVENT_EVIDENCE"],
        "insufficient_event_evidence_count": counts["INSUFFICIENT_EVIDENCE"],
        "rejected_event_evidence_count": counts["REJECTED_EVIDENCE"],
        "unknown_event_evidence_count": counts["UNKNOWN"],
        "source_document_count": len(sources),
        "missing_official_document_count": sum(item["source_availability"] != "ACQUIRED" for item in sources),
        "location_observation_count": len(observations),
        "unresolved_location_observation_count": sum(item["mapping_status"] != "RESOLVED_CURRENT_PSGC" for item in observations),
        "unresolved_temporal_event_count": sum(item["temporal_precision"] != "EXACT_TIMESTAMP" for item in events),
        "confirmed_flood_observation_count": len(confirmed),
        "barangay_resolved_positive_observation_count": sum(item["mapping_status"] == "RESOLVED_CURRENT_PSGC" for item in confirmed),
        "confirmed_unresolved_positive_observation_count": sum(item["mapping_status"] != "RESOLVED_CURRENT_PSGC" for item in confirmed),
        "human_approved_positive_count": sum(item["review_status"] == "HUMAN_APPROVED" for item in confirmed),
        "training_eligible_positive_count": sum(item["training_eligible"] for item in confirmed),
        "verified_negative_count": sum(item["flood_absent"] is True for item in observations),
        "training_eligible_weather_event_count": sum(item["training_eligible"] for item in events),
        "unresolved_event_count": sum(item["review_status"] != "HUMAN_APPROVED" for item in events),
        "target_horizon_approved": False,
    }

def validate_evidence_manifest(payload: Any, repo_root: Path = REPO_ROOT) -> list[ValidationIssue]:
    issues = validate_schema_instance(payload, EVIDENCE_SCHEMA)
    if not strict(issues, payload, TOP_KEYS, "$"):
        return issues
    identity = (payload["schema_version"], payload["phase"], payload["dataset_classification"])
    if identity != ("1.0.0", "TF-B2B", "GOVERNED_EVENT_EVIDENCE_NOT_TRAINING_DATA"):
        add(issues, "INVALID_MANIFEST_IDENTITY", "$", "Unexpected TF-B2B manifest identity.")
    basis = payload["normalization_basis"]
    if not string_list(basis) or len(basis) != len(set(basis)):
        add(issues, "INVALID_NORMALIZATION_BASIS", "$.normalization_basis", "References must be unique paths.")
    else:
        for index, reference in enumerate(basis):
            try:
                resolve_repository_file(repo_root, reference)
            except RepositoryPathError as exc:
                add(issues, "UNSAFE_NORMALIZATION_INPUT", f"$.normalization_basis[{index}]", str(exc))
        if CURRENT_PSGC_REFERENCE not in basis:
            add(issues, "MISSING_PSGC_REFERENCE", "$.normalization_basis", "Governed current PSGC reference is required.")
    barangay_pairs, pair_issues = governed_barangay_pairs(repo_root)
    issues.extend(pair_issues)
    governance = payload["governance"]
    if strict(issues, governance, GOV_KEYS, "$.governance"):
        safe = {
            "system_checked": True, "human_approval_claimed": False,
            "training_dataset_created": False, "training_matrix_created": False,
            "tensorflow_training_performed": False, "four_class_labels_created": False,
            "negative_labels_created": False, "mgb_susceptibility_used_as_target": False,
            "rainfall_regression_used_as_flood_evidence": False,
            "target_horizon_status": "NOT_APPROVED",
            "training_authorization_status": "NOT_APPROVED",
        }
        for key, value in safe.items():
            if governance[key] != value:
                add(issues, "UNSAFE_GOVERNANCE_STATE", f"$.governance.{key}", f"Expected {value!r}.")

    events: dict[str, dict[str, Any]] = {}
    if not isinstance(payload["events"], list):
        add(issues, "EXPECTED_ARRAY", "$.events", "Expected an array.")
    else:
        for index, event in enumerate(payload["events"]):
            path = f"$.events[{index}]"
            if not strict(issues, event, EVENT_KEYS, path):
                continue
            event_id = event["event_id"]
            if not isinstance(event_id, str) or not ID_RE.fullmatch(event_id) or event_id in events:
                add(issues, "INVALID_OR_DUPLICATE_EVENT_ID", f"{path}.event_id", "Invalid or duplicate ID.")
            else:
                events[event_id] = event
            if event["event_parent_id"] != event_id:
                add(issues, "INVALID_EVENT_PARENT", path, "Report revisions are not independent events.")
            if event["evidence_status"] not in EVIDENCE:
                add(issues, "INVALID_EVIDENCE_STATUS", path, "Invalid evidence status.")
            if event["spatial_precision"] not in {"CITY", "MULTI_BARANGAY", "STREET_OR_LOCALITY"}:
                add(issues, "INVALID_SPATIAL_PRECISION", path, "Invalid spatial precision.")
            if event["temporal_precision"] not in {"DAY_ONLY", "DATE_RANGE", "HOUR_TIMEZONE_UNKNOWN", "BOUNDED_UPPER_LIMIT_TIMEZONE_UNKNOWN", "UNKNOWN"}:
                add(issues, "INVALID_TEMPORAL_PRECISION", path, "Invalid temporal precision.")
            for field in ("start_date", "end_date"):
                if event[field] is not None and not iso_date(event[field]):
                    add(issues, "INVALID_DATE", f"{path}.{field}", "Expected ISO date or null.")
            if event["start_date"] and event["end_date"] and event["end_date"] < event["start_date"]:
                add(issues, "INVALID_DATE_RANGE", path, "Event end precedes start.")
            if event["timezone"] is not None:
                add(issues, "UNJUSTIFIED_TIMEZONE", path, "No source timezone is governed.")
            if event["label_status"] != "UNKNOWN":
                add(issues, "EVENT_LABEL_CREATED", path, "Training label must remain UNKNOWN.")
            review_safe = event["system_check_status"] == "SYSTEM_CHECKED" and event["review_status"] == "HUMAN_REVIEW_REQUIRED" and event["reviewer"] is None and event["review_timestamp"] is None
            gates_safe = event["leakage_review_status"] == "NOT_REVIEWED" and event["candidate_prediction_cutoff"] is None and event["candidate_target_window"] is None and event["target_horizon_status"] == "NOT_APPROVED" and event["training_eligible"] is False
            if not review_safe:
                add(issues, "FALSE_HUMAN_APPROVAL", path, "Only system checking has occurred.")
            if not gates_safe:
                add(issues, "UNSAFE_EVENT_TRAINING_STATE", path, "Training gates must remain closed.")
            reasons = event["training_ineligibility_reasons"]
            if not string_list(reasons) or not reasons or any(item not in TRAINING_REASONS for item in reasons):
                add(issues, "INVALID_TRAINING_REASON", path, "Use governed ineligibility reasons.")
            if not string_list(event["source_document_ids"]) or not event["source_document_ids"]:
                add(issues, "MISSING_EVENT_SOURCES", path, "Event requires source documents.")

    sources: dict[str, dict[str, Any]] = {}
    hashes: dict[str, str] = {}
    preferred = Counter()
    if not isinstance(payload["source_documents"], list):
        add(issues, "EXPECTED_ARRAY", "$.source_documents", "Expected an array.")
    else:
        for index, source in enumerate(payload["source_documents"]):
            path = f"$.source_documents[{index}]"
            if not strict(issues, source, SOURCE_KEYS, path):
                continue
            source_id = source["source_document_id"]
            if not isinstance(source_id, str) or not ID_RE.fullmatch(source_id) or source_id in sources:
                add(issues, "INVALID_OR_DUPLICATE_SOURCE_ID", path, "Invalid or duplicate source ID.")
            else:
                sources[source_id] = source
            if source["event_id"] not in events:
                add(issues, "UNKNOWN_SOURCE_EVENT", path, "Unknown event.")
            if source["source_availability"] not in AVAILABILITY or source["revision_relationship"] not in RELATIONSHIPS or source["evidence_role"] not in ROLES:
                add(issues, "INVALID_SOURCE_CLASSIFICATION", path, "Invalid source classification.")
            if source["source_publication_date"] is not None and not iso_date(source["source_publication_date"]):
                add(issues, "INVALID_DATE", path, "Invalid publication date.")
            if source["source_timezone"] is not None:
                add(issues, "UNJUSTIFIED_SOURCE_TIMEZONE", path, "Source timezone is not verified.")
            if source["source_availability"] == "ACQUIRED":
                digest, local_file = source["source_hash"], source["local_file"]
                if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
                    add(issues, "MISSING_SOURCE_HASH", path, "Acquired source requires SHA-256.")
                elif digest in hashes:
                    add(issues, "DUPLICATE_SOURCE_HASH", path, f"Duplicates {hashes[digest]}.")
                else:
                    hashes[digest] = source_id
                if not local_file or not iso_datetime(source["source_acquisition_date"]):
                    add(issues, "INCOMPLETE_ACQUISITION_METADATA", path, "Acquired source requires file and timestamp.")
                else:
                    try:
                        acquired_path = resolve_repository_file(repo_root, local_file)
                    except RepositoryPathError as exc:
                        add(issues, "UNSAFE_SOURCE_FILE", f"{path}.local_file", str(exc))
                    else:
                        if hashlib.sha256(acquired_path.read_bytes()).hexdigest() != digest:
                            add(issues, "SOURCE_HASH_MISMATCH", path, "Local bytes do not match.")
            elif any(source[field] is not None for field in ("source_hash", "local_file", "source_acquisition_date")):
                add(issues, "UNACQUIRED_SOURCE_HAS_ARTIFACT", path, "Unacquired source claims artifact metadata.")
            if source["preferred_for_event_scope"]:
                preferred[source["event_id"]] += 1

    for source_id, source in sources.items():
        related = source["related_document_id"]
        if source["revision_relationship"] in {"INITIAL", "DISCOVERY_ONLY"}:
            if related is not None:
                add(issues, "UNEXPECTED_REVISION_PARENT", source_id, "Unexpected revision parent.")
        elif related not in sources:
            add(issues, "UNKNOWN_REVISION_PARENT", source_id, "Revision parent is missing.")
        elif sources[related]["event_id"] != source["event_id"]:
            add(issues, "CROSS_EVENT_REVISION", source_id, "Revision crosses event parents.")
    for event_id, event in events.items():
        for source_id in event["source_document_ids"]:
            if source_id not in sources or sources[source_id]["event_id"] != event_id:
                add(issues, "INVALID_EVENT_SOURCE", event_id, f"Invalid source {source_id}.")
        selected = event["preferred_source_document_id"]
        if selected is None and preferred[event_id]:
            add(issues, "PREFERRED_SOURCE_MISMATCH", event_id, "Marked source lacks event selection.")
        if selected is not None and (selected not in event["source_document_ids"] or not sources.get(selected, {}).get("preferred_for_event_scope")):
            add(issues, "PREFERRED_SOURCE_MISMATCH", event_id, "Invalid preferred source.")
        if preferred[event_id] > 1:
            add(issues, "MULTIPLE_PREFERRED_SOURCES", event_id, "Only one preferred source is allowed.")

    observation_ids: set[str] = set()
    legacy_176_seen = False
    if not isinstance(payload["observations"], list):
        add(issues, "EXPECTED_ARRAY", "$.observations", "Expected an array.")
    else:
        for index, observation in enumerate(payload["observations"]):
            path = f"$.observations[{index}]"
            if not strict(issues, observation, OBS_KEYS, path):
                continue
            observation_id = observation["observation_id"]
            if not isinstance(observation_id, str) or not ID_RE.fullmatch(observation_id) or observation_id in observation_ids:
                add(issues, "INVALID_OR_DUPLICATE_OBSERVATION_ID", path, "Invalid or duplicate observation ID.")
            observation_ids.add(str(observation_id))
            event_id, source_id = observation["event_id"], observation["source_document_id"]
            if event_id not in events or source_id not in sources or sources.get(source_id, {}).get("event_id") != event_id:
                add(issues, "INVALID_OBSERVATION_PROVENANCE", path, "Event/source relationship is invalid.")
            corroborating = observation["corroborating_source_document_ids"]
            if not isinstance(corroborating, list) or any(item not in sources or sources[item]["event_id"] != event_id for item in corroborating):
                add(issues, "INVALID_CORROBORATING_SOURCE", path, "Corroborating source is missing.")
            if observation["observation_type"] not in OBS_TYPES or observation["source_access_status"] not in AVAILABILITY:
                add(issues, "INVALID_OBSERVATION_CLASSIFICATION", path, "Invalid observation classification.")
            if source_id in sources and observation["source_access_status"] != sources[source_id]["source_availability"]:
                add(issues, "SOURCE_ACCESS_MISMATCH", path, "Observation access status differs from its source document.")
            if source_id in sources and observation["source_revision"] != sources[source_id]["source_revision"]:
                add(issues, "SOURCE_REVISION_MISMATCH", path, "Observation revision differs from its source document.")
            if observation["label_status"] != "UNKNOWN":
                add(issues, "OBSERVATION_LABEL_CREATED", path, "Training label must remain UNKNOWN.")
            if observation["flood_absent"] is not None:
                add(issues, "FABRICATED_NEGATIVE", path, "TF-B2B requires flood_absent=null; no affirmative negatives exist.")
            if observation["observation_type"] == "CONFIRMED_FLOOD_OBSERVED":
                if observation["flood_present"] is not True:
                    add(issues, "CONFIRMED_WITHOUT_POSITIVE", path, "Confirmed evidence requires flood_present=true.")
                if source_id in sources and sources[source_id]["source_availability"] != "ACQUIRED":
                    add(issues, "CONFIRMED_FROM_UNACQUIRED_SOURCE", path, "Confirmed observation requires acquired source bytes.")
            elif observation["flood_present"] is not None:
                add(issues, "POSSIBLE_EVIDENCE_PROMOTED", path, "Only acquired confirmed evidence can set flood_present.")
            mapping_ok = observation["location_type"] in LOCATIONS and observation["mapping_status"] in MAPPING_STATUS and observation["mapping_method"] in MAPPING_METHOD and observation["mapping_confidence"] in {"HIGH", "UNRESOLVED"}
            if not mapping_ok:
                add(issues, "INVALID_MAPPING_STATE", path, "Invalid location/mapping state.")
            psgc = observation["barangay_psgc"]
            if psgc is not None and (not isinstance(psgc, str) or not PSGC_RE.fullmatch(psgc)):
                add(issues, "INVALID_PSGC", path, "Invalid Caloocan PSGC.")
            mapping_rules = {
                "RESOLVED_CURRENT_PSGC": ("EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE", "HIGH", {"BARANGAY"}),
                "UNRESOLVED_HISTORICAL_BOUNDARY": ("EXPLICIT_HISTORICAL_BARANGAY_NO_CURRENT_CROSSWALK", "UNRESOLVED", {"BARANGAY"}),
                "UNREVIEWED_EXPLICIT_BARANGAY_NUMBER": ("EXPLICIT_BARANGAY_NUMBER_MAPPING_NOT_REVIEWED", "UNRESOLVED", {"BARANGAY", "BARANGAY_LOCALITY"}),
                "NO_BARANGAY_STATED": ("NO_AUTOMATIC_STREET_OR_LOCALITY_MAPPING", "UNRESOLVED", {"STREET", "LOCALITY", "FACILITY", "BRIDGE"}),
                "UNNAMED_BARANGAY": ("NO_MAPPING_UNNAMED_BARANGAY", "UNRESOLVED", {"UNNAMED_BARANGAY"}),
                "CITY_LEVEL_ONLY": ("NO_BARANGAY_MAPPING_CITY_SCOPE", "UNRESOLVED", {"CITY"}),
            }
            mapping_status = observation["mapping_status"]
            rule = mapping_rules.get(mapping_status)
            if rule is None or (observation["mapping_method"], observation["mapping_confidence"]) != rule[:2] or observation["location_type"] not in rule[2]:
                add(issues, "INCOHERENT_MAPPING_STATE", path, "Mapping status, method, confidence, and location type are inconsistent.")
            if mapping_status == "RESOLVED_CURRENT_PSGC":
                name = observation["barangay_name"]
                if psgc is None or name is None:
                    add(issues, "INCOMPLETE_RESOLVED_MAPPING", path, "Resolved mapping requires PSGC and name.")
                elif barangay_pairs.get(psgc) != name:
                    add(issues, "UNGOVERNED_PSGC_NAME_PAIR", path, "PSGC/name pair is absent from the governed Caloocan reference.")
                if observation["historical_barangay_name"] != name:
                    add(issues, "INCOHERENT_HISTORICAL_NAME", path, "Resolved evidence must preserve the same source-reported barangay name.")
            elif psgc is not None or observation["barangay_name"] is not None:
                add(issues, "UNAPPROVED_BARANGAY_MAPPING", path, "Unresolved evidence cannot have a canonical mapping.")

            if mapping_status in {"UNRESOLVED_HISTORICAL_BOUNDARY", "UNREVIEWED_EXPLICIT_BARANGAY_NUMBER"}:
                if not isinstance(observation["historical_barangay_name"], str) or not observation["historical_barangay_name"]:
                    add(issues, "MISSING_SOURCE_BARANGAY_IDENTITY", path, "Unresolved explicit barangay evidence requires its source-reported historical name.")
            elif mapping_status in {"NO_BARANGAY_STATED", "UNNAMED_BARANGAY", "CITY_LEVEL_ONLY"} and observation["historical_barangay_name"] is not None:
                add(issues, "INCOHERENT_HISTORICAL_NAME", path, "This mapping state cannot claim a historical barangay name.")

            historical_text = str(observation.get("historical_barangay_name") or "").strip().casefold()
            reported_text = str(observation.get("reported_location_text") or "").strip().casefold()
            governed_legacy_176 = (
                observation_id == LEGACY_176_OBSERVATION_ID
                or (
                    event_id == LEGACY_176_IDENTITY["event_id"]
                    and source_id == LEGACY_176_IDENTITY["source_document_id"]
                    and (historical_text == "barangay 176" or reported_text == "barangay 176")
                )
            )
            if governed_legacy_176:
                legacy_176_seen = True
                if observation_id != LEGACY_176_OBSERVATION_ID or any(observation.get(field) != expected for field, expected in LEGACY_176_IDENTITY.items()):
                    add(issues, "LEGACY_176_IDENTITY_VIOLATION", path, "Governed historical Barangay 176 identity and unresolved mapping are immutable in TF-B2B.")
            if observation["observation_date"] is not None and not iso_date(observation["observation_date"]):
                add(issues, "INVALID_DATE", path, "Invalid observation date.")
            if observation["timestamp"] is not None and (not iso_datetime(observation["timestamp"]) or observation["source_timezone"] is None):
                add(issues, "UNJUSTIFIED_TIMESTAMP", path, "Normalized timestamp requires a governed timezone.")
            if observation["timestamp_precision"] not in {"DAY_ONLY", "DATE_RANGE", "HOUR_TIMEZONE_UNKNOWN", "UNKNOWN"}:
                add(issues, "INVALID_TIMESTAMP_PRECISION", path, "Invalid timestamp precision.")
            depth = observation["flood_depth"]
            if depth is not None and (isinstance(depth, bool) or not isinstance(depth, (int, float)) or depth < 0):
                add(issues, "INVALID_DEPTH", path, "Depth must be non-negative or null.")
            if observation["depth_unit"] not in {"FEET", "QUALITATIVE_GUTTER_DEPTH", None}:
                add(issues, "INVALID_DEPTH_UNIT", path, "Invalid depth unit.")
            review_safe = observation["system_check_status"] == "SYSTEM_CHECKED" and observation["review_status"] == "HUMAN_REVIEW_REQUIRED" and observation["reviewer"] is None and observation["review_timestamp"] is None
            gates_safe = observation["leakage_review_status"] == "NOT_REVIEWED" and observation["training_eligible"] is False
            if not review_safe:
                add(issues, "FALSE_OBSERVATION_APPROVAL", path, "Only system checking has occurred.")
            if not gates_safe:
                add(issues, "UNSAFE_OBSERVATION_TRAINING_STATE", path, "Training gates must remain closed.")
            reasons = observation["training_ineligibility_reasons"]
            if not string_list(reasons) or not reasons or any(item not in TRAINING_REASONS for item in reasons):
                add(issues, "INVALID_TRAINING_REASON", path, "Use governed ineligibility reasons.")

    if not legacy_176_seen:
        add(issues, "MISSING_LEGACY_176_OBSERVATION", "$.observations", "Governed historical Barangay 176 observation is required.")

    issue_ids: set[str] = set()
    if not isinstance(payload["unresolved_issues"], list):
        add(issues, "EXPECTED_ARRAY", "$.unresolved_issues", "Expected an array.")
    else:
        categories = {"SOURCE_ACQUISITION", "TEMPORAL", "SPATIAL", "REVISION_CONFLICT", "HUMAN_REVIEW", "TARGET_GOVERNANCE"}
        for index, item in enumerate(payload["unresolved_issues"]):
            path = f"$.unresolved_issues[{index}]"
            if not strict(issues, item, ISSUE_KEYS, path):
                continue
            issue_id = item["issue_id"]
            if not isinstance(issue_id, str) or not ID_RE.fullmatch(issue_id) or issue_id in issue_ids:
                add(issues, "INVALID_OR_DUPLICATE_ISSUE_ID", path, "Invalid or duplicate issue ID.")
            issue_ids.add(str(issue_id))
            state_ok = item["event_id"] in events and item["category"] in categories and item["severity"] in {"CRITICAL", "HIGH", "MEDIUM"} and item["status"] == "OPEN" and item["blocking_training"] is True
            if not state_ok:
                add(issues, "INVALID_OPEN_ISSUE", path, "Current issue must be an open training blocker.")

    if strict(issues, payload["summary"], SUMMARY_KEYS, "$.summary"):
        for key, value in expected_summary(payload).items():
            if payload["summary"][key] != value:
                add(issues, "SUMMARY_MISMATCH", f"$.summary.{key}", f"Expected {value!r}.")
    return issues

def validate_negative_backlog(payload: Any) -> list[ValidationIssue]:
    issues = validate_schema_instance(payload, BACKLOG_SCHEMA)
    if not strict(issues, payload, BACKLOG_KEYS, "$"):
        return issues
    identity = (payload["schema_version"], payload["phase"], payload["status"])
    if identity != ("1.0.0", "TF-B2B", "ACQUISITION_REQUIRED"):
        add(issues, "INVALID_BACKLOG_IDENTITY", "$", "Expected unacquired TF-B2B backlog.")
    rule = payload["negative_label_rule"].lower()
    if "absence" not in rule or "not" not in rule:
        add(issues, "UNSAFE_NEGATIVE_RULE", "$.negative_label_rule", "Absence-of-report negatives must be rejected.")
    if not string_list(payload["prohibited_negative_bases"]):
        add(issues, "INVALID_PROHIBITED_BASES", "$.prohibited_negative_bases", "Expected governed bases.")
    seen: set[str] = set()
    for index, source in enumerate(payload["candidate_sources"]):
        path = f"$.candidate_sources[{index}]"
        if not strict(issues, source, BACKLOG_SOURCE_KEYS, path):
            continue
        source_id = source["source_candidate_id"]
        if not isinstance(source_id, str) or not ID_RE.fullmatch(source_id) or source_id in seen:
            add(issues, "INVALID_OR_DUPLICATE_BACKLOG_SOURCE", path, "Invalid or duplicate source.")
        seen.add(str(source_id))
        safe = source["acquisition_status"] == "NOT_ACQUIRED" and source["cannot_establish_negative_alone"] is True and string_list(source["required_fields"])
        if not safe:
            add(issues, "UNSAFE_NEGATIVE_SOURCE", path, "Candidate source requires acquisition, affirmative evidence, and review.")
    summary_keys = {"candidate_source_count", "acquired_source_count", "verified_negative_count", "training_use_authorized"}
    if strict(issues, payload["summary"], summary_keys, "$.summary"):
        expected = {"candidate_source_count": len(payload["candidate_sources"]), "acquired_source_count": 0, "verified_negative_count": 0, "training_use_authorized": False}
        for key, value in expected.items():
            if payload["summary"][key] != value:
                add(issues, "BACKLOG_SUMMARY_MISMATCH", f"$.summary.{key}", f"Expected {value!r}.")
    return issues


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--evidence", default=DEFAULT_EVIDENCE.relative_to(REPO_ROOT).as_posix())
    parser.add_argument("--negative-backlog", default=DEFAULT_BACKLOG.relative_to(REPO_ROOT).as_posix())
    args = parser.parse_args(argv)
    try:
        evidence_path = resolve_repository_file(REPO_ROOT, args.evidence)
        backlog_path = resolve_repository_file(REPO_ROOT, args.negative_backlog)
        evidence, backlog = read_json(evidence_path), read_json(backlog_path)
    except (RepositoryPathError, OSError, json.JSONDecodeError) as exc:
        print(json.dumps({"success": False, "code": "READ_ERROR", "message": str(exc)}, indent=2))
        return 2
    issues = validate_evidence_manifest(evidence) + validate_negative_backlog(backlog)
    output = {
        "success": not issues,
        "code": "TF_B2B_EVIDENCE_VALID" if not issues else "TF_B2B_EVIDENCE_INVALID",
        "issue_count": len(issues),
        "issues": [item.as_dict() for item in issues],
        "summary": evidence.get("summary"),
    }
    print(json.dumps(output, indent=2, ensure_ascii=False, sort_keys=True))
    return 0 if not issues else 1


if __name__ == "__main__":
    raise SystemExit(main())
