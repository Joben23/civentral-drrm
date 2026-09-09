"""Governed Phase 3B1 flood pilot dataset utilities.

Standard-library only. This module validates and aggregates evidence; it never
downloads data, imputes rainfall, trains a model, or changes model readiness.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import math
from collections import Counter
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Mapping, Sequence, Tuple

from flood_data_common import (
    DEFAULT_MANIFEST,
    REPO_ROOT,
    WORKSPACE,
    load_manifest,
    parse_iso_datetime,
    read_json,
    validate_manifest,
)
from resolve_mgb_susceptibility import resolve_point


DEFAULT_EVENT_REGISTRY = WORKSPACE / "manifests" / "flood-event-registry.json"
DEFAULT_LABEL_PROTOCOL = WORKSPACE / "config" / "label-protocol-v1.json"
DEFAULT_PRECIPITATION_DATA = WORKSPACE / "data" / "reviewed" / "precipitation"
DEFAULT_PILOT_OUTPUT = WORKSPACE / "data" / "processed" / "pilot" / "flood-pilot-v1.csv"
DEFAULT_PILOT_MANIFEST = WORKSPACE / "data" / "manifests" / "flood-pilot-v1.manifest.json"

EVENT_LABELS = {"FLOOD_CONFIRMED", "NO_FLOOD_CONFIRMED", "UNKNOWN"}
TRAINING_EVENT_LABELS = {"FLOOD_CONFIRMED", "NO_FLOOD_CONFIRMED"}
NEGATIVE_EVIDENCE = {
    "EXPLICIT_LGU_MONITORING_NO_FLOOD",
    "EXPLICIT_AUTHORITATIVE_SITUATION_LOG_NO_FLOOD",
    "HUMAN_REVIEWED_AUTHORITATIVE_MONITORING_NO_FLOOD",
}
PROHIBITED_NEGATIVE_PHRASES = (
    "no report found", "no reports found", "no citizen report",
    "no dromic entry", "no ndrrmc entry", "absence of report", "missing record",
)
MAPPING_METHODS = {
    "POINT_CONTAINING_CELL", "NEAREST_VALID_CELL", "AREA_WEIGHTED_POLYGON",
}
PRECIPITATION_SOURCE_ROLES = {
    "OBSERVED_PRECIPITATION", "OPTIONAL_PRECIPITATION_CROSS_CHECK",
}
EVENT_SOURCE_ROLES = {"FLOOD_EVENT_EVIDENCE", "NEGATIVE_EVENT_EVIDENCE"}
FIXTURE_CLASSIFICATION = "TEST_ONLY_SYNTHETIC_NOT_FOR_TRAINING"
REAL_PRECIPITATION_CLASSIFICATION = "REAL_SOURCE_DATA"
REAL_EVENT_CLASSIFICATION = "GOVERNED_EVENT_CANDIDATES"
PILOT_CLASSIFICATION = "GOVERNED_PILOT_CANDIDATE"
AGGREGATION_WINDOWS = (1, 3, 6, 12, 24, 72)

OUTPUT_FIELDS = (
    "record_id", "event_id", "window_id", "prediction_cutoff",
    "target_window_start", "target_window_end", "forecast_horizon_hours",
    "location_scope", "barangay_psgc", "latitude", "longitude", "label_status",
    "flood_outcome", "precipitation_data_type", "precipitation_source_id",
    "precipitation_product_version", "mapping_method", "mapping_version",
    "source_resolution", "source_grid_cells_json", "mapping_distance_m",
    "mapping_coverage_fraction", "mapping_limitation_note", "rainfall_1h_mm",
    "rainfall_3h_mm", "rainfall_6h_mm", "rainfall_12h_mm", "rainfall_24h_mm",
    "rainfall_72h_mm", "label_protocol_version",
)
EVENT_FIELDS = {
    "event_id", "candidate_reference", "hazard_type", "event_start", "event_end",
    "event_time_status", "location_scope", "barangay_psgc", "latitude", "longitude",
    "source_ids", "source_document_refs", "evidence_summary", "label_status",
    "negative_evidence_basis", "review_status", "reviewed_by", "reviewed_at",
    "leakage_review_status", "training_eligible", "exclusion_reason", "candidate_windows",
}
WINDOW_FIELDS = {
    "window_id", "prediction_cutoff", "target_window_start", "target_window_end",
    "label_observed_at", "forecast_horizon_hours", "precipitation_mapping",
}
MAPPING_FIELDS = {
    "source_id", "mapping_method", "mapping_version", "source_resolution",
    "source_grid_cells", "distance_m", "coverage_fraction", "limitation_note",
}
PRECIPITATION_FIELDS = {
    "observation_id", "data_type", "source_id", "product_version", "observed_at_start",
    "observed_at_end", "latitude", "longitude", "grid_cell_id", "native_value",
    "native_units", "precipitation_mm", "normalization_method", "quality_flag",
    "retrieved_at", "raw_file_reference", "raw_file_checksum", "source_resolution",
}


@dataclass(frozen=True)
class PilotIssue:
    code: str
    subject_id: str
    message: str

    def as_dict(self) -> Dict[str, str]:
        return {"code": self.code, "subject_id": self.subject_id, "message": self.message}


class AggregationError(ValueError):
    """A deterministic precipitation aggregation could not be completed."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(float(value))


def _timestamp(value: Any, field: str) -> datetime:
    result = parse_iso_datetime(value)
    if result is None:
        raise ValueError(f"{field} must be an ISO-8601 timestamp with a UTC offset")
    return result.astimezone(timezone.utc)


def load_event_registry(path: Path = DEFAULT_EVENT_REGISTRY) -> Dict[str, Any]:
    payload = read_json(path)
    if not isinstance(payload, dict):
        raise ValueError("Flood event registry must be a JSON object.")
    return payload


def load_precipitation_data(path: Path = DEFAULT_PRECIPITATION_DATA) -> Dict[str, Any]:
    empty = {
        "schema_version": "1.0.0",
        "dataset_classification": REAL_PRECIPITATION_CLASSIFICATION,
        "records": [],
    }
    if not path.exists():
        return empty
    paths = [path] if path.is_file() else sorted(
        child for child in path.rglob("*.json") if child.is_file() and not child.name.startswith(".")
    )
    if not paths:
        return empty
    records: List[Dict[str, Any]] = []
    classifications: set[str] = set()
    for source_path in paths:
        payload = read_json(source_path)
        if not isinstance(payload, dict) or not isinstance(payload.get("records"), list):
            raise ValueError(f"Precipitation file must contain a records array: {source_path}")
        classifications.add(str(payload.get("dataset_classification")))
        for record in payload["records"]:
            if not isinstance(record, dict):
                raise ValueError(f"Precipitation records must be JSON objects: {source_path}")
            records.append(dict(record))
    if len(classifications) != 1:
        raise ValueError("Precipitation files with different dataset classifications cannot be mixed.")
    return {
        "schema_version": "1.0.0",
        "dataset_classification": classifications.pop(),
        "records": records,
    }


def validate_label_protocol(protocol: Mapping[str, Any]) -> List[PilotIssue]:
    required_values = {
        "protocol_version": "1.0.0",
        "status": "DRAFT_NOT_APPROVED",
        "target_definition_status": "PENDING",
        "forecast_horizon_status": "NOT_FINALIZED",
        "forecast_features_status": "NOT_AVAILABLE_FOR_TRAINING",
        "missing_rainfall_imputation": "PROHIBITED",
        "training_authorization_default": "NOT_APPROVED",
    }
    issues = [
        PilotIssue("INVALID_LABEL_PROTOCOL", "label-protocol", f"{field} must be {expected}.")
        for field, expected in required_values.items()
        if protocol.get(field) != expected
    ]
    if protocol.get("observation_forecast_substitution_prohibited") is not True:
        issues.append(PilotIssue("INVALID_LABEL_PROTOCOL", "label-protocol", "Observed/forecast substitution must be prohibited."))
    if tuple(protocol.get("candidate_antecedent_window_hours", [])) != AGGREGATION_WINDOWS:
        issues.append(PilotIssue("INVALID_LABEL_PROTOCOL", "label-protocol", "Candidate antecedent windows must match the builder contract."))
    return issues


def validate_mapping(mapping: Mapping[str, Any], subject_id: str) -> List[PilotIssue]:
    issues: List[PilotIssue] = []
    missing = sorted(MAPPING_FIELDS - set(mapping))
    extras = sorted(set(mapping) - MAPPING_FIELDS)
    if missing:
        issues.append(PilotIssue("MISSING_MAPPING_FIELDS", subject_id, f"Missing mapping fields: {', '.join(missing)}"))
    if extras:
        issues.append(PilotIssue("UNEXPECTED_MAPPING_FIELDS", subject_id, f"Unexpected mapping fields: {', '.join(extras)}"))
    method = mapping.get("mapping_method")
    if method not in MAPPING_METHODS:
        issues.append(PilotIssue("UNSUPPORTED_MAPPING_METHOD", subject_id, "An explicit supported mapping method is required."))
    for field in ("source_id", "mapping_version", "source_resolution", "limitation_note"):
        if not isinstance(mapping.get(field), str) or not str(mapping.get(field)).strip():
            issues.append(PilotIssue("MISSING_MAPPING_METADATA", subject_id, f"{field} is required."))
    cells = mapping.get("source_grid_cells")
    if not isinstance(cells, list) or not cells:
        issues.append(PilotIssue("MISSING_GRID_CELLS", subject_id, "At least one source grid cell is required."))
        return issues
    identifiers: set[str] = set()
    weights: List[float] = []
    for cell in cells:
        if not isinstance(cell, dict):
            issues.append(PilotIssue("INVALID_GRID_CELL", subject_id, "Grid-cell mappings must be objects."))
            continue
        cell_id = cell.get("grid_cell_id")
        weight = cell.get("weight")
        if not isinstance(cell_id, str) or not cell_id.strip() or cell_id in identifiers:
            issues.append(PilotIssue("INVALID_GRID_CELL", subject_id, "Grid-cell identifiers must be unique non-empty strings."))
        else:
            identifiers.add(cell_id)
        if not _is_number(weight) or not 0 < float(weight) <= 1:
            issues.append(PilotIssue("INVALID_MAPPING_WEIGHT", subject_id, "Every mapping weight must be in (0, 1]."))
        else:
            weights.append(float(weight))
    if weights and not math.isclose(sum(weights), 1.0, abs_tol=1e-9):
        issues.append(PilotIssue("INVALID_MAPPING_WEIGHT_SUM", subject_id, "Grid-cell mapping weights must sum to 1."))
    if method in {"POINT_CONTAINING_CELL", "NEAREST_VALID_CELL"} and len(cells) != 1:
        issues.append(PilotIssue("INVALID_POINT_MAPPING", subject_id, "Point and nearest-cell mappings require exactly one grid cell."))
    distance = mapping.get("distance_m")
    if method == "NEAREST_VALID_CELL" and (not _is_number(distance) or float(distance) < 0):
        issues.append(PilotIssue("MISSING_NEAREST_DISTANCE", subject_id, "Nearest-cell mapping requires a non-negative distance_m."))
    coverage = mapping.get("coverage_fraction")
    if method == "AREA_WEIGHTED_POLYGON" and (not _is_number(coverage) or not 0 < float(coverage) <= 1):
        issues.append(PilotIssue("MISSING_AREA_COVERAGE", subject_id, "Area-weighted mapping requires coverage_fraction in (0, 1]."))
    return issues


def validate_event_registry(
    registry: Mapping[str, Any], sources: Mapping[str, Mapping[str, Any]], *, validate_city: bool = True
) -> List[PilotIssue]:
    issues: List[PilotIssue] = []
    if registry.get("registry_version") != "1.0.0":
        issues.append(PilotIssue("INVALID_EVENT_REGISTRY_VERSION", "registry", "registry_version must be 1.0.0."))
    if registry.get("label_protocol_version") != "1.0.0":
        issues.append(PilotIssue("INVALID_LABEL_PROTOCOL_VERSION", "registry", "label_protocol_version must be 1.0.0."))
    if registry.get("dataset_classification") not in {REAL_EVENT_CLASSIFICATION, FIXTURE_CLASSIFICATION}:
        issues.append(PilotIssue("INVALID_EVENT_DATA_CLASSIFICATION", "registry", "Event registry classification is invalid."))
    if registry.get("training_authorization_status") != "NOT_APPROVED":
        issues.append(PilotIssue("EVENT_REGISTRY_CANNOT_AUTHORIZE_TRAINING", "registry", "Phase 3B1 event registries must remain NOT_APPROVED."))
    events = registry.get("events")
    if not isinstance(events, list):
        return issues + [PilotIssue("INVALID_EVENT_LIST", "registry", "events must be an array.")]
    seen: set[str] = set()
    for index, event in enumerate(events, start=1):
        subject = str(event.get("event_id") or f"event-{index}") if isinstance(event, dict) else f"event-{index}"
        if not isinstance(event, dict):
            issues.append(PilotIssue("INVALID_EVENT", subject, "Event entries must be objects."))
            continue
        missing = sorted(EVENT_FIELDS - set(event))
        extras = sorted(set(event) - EVENT_FIELDS)
        if missing:
            issues.append(PilotIssue("MISSING_EVENT_FIELDS", subject, f"Missing event fields: {', '.join(missing)}"))
        if extras:
            issues.append(PilotIssue("UNEXPECTED_EVENT_FIELDS", subject, f"Unexpected event fields: {', '.join(extras)}"))
        event_id = event.get("event_id")
        if not isinstance(event_id, str) or not event_id.strip() or event_id in seen:
            issues.append(PilotIssue("INVALID_EVENT_ID", subject, "event_id must be unique and non-empty."))
        else:
            seen.add(event_id)
        if event.get("hazard_type") != "FLOOD":
            issues.append(PilotIssue("INVALID_HAZARD_TYPE", subject, "Only FLOOD events are supported."))
        label = event.get("label_status")
        if label not in EVENT_LABELS:
            issues.append(PilotIssue("INVALID_EVENT_LABEL", subject, "label_status is not allowed."))
        if label == "UNKNOWN" and event.get("training_eligible") is not False:
            issues.append(PilotIssue("UNKNOWN_CANNOT_BE_TRAINING_ELIGIBLE", subject, "UNKNOWN must remain excluded and cannot become a negative."))
        if label != "NO_FLOOD_CONFIRMED" and event.get("negative_evidence_basis") is not None:
            issues.append(PilotIssue("UNEXPECTED_NEGATIVE_EVIDENCE", subject, "Negative evidence is only valid for NO_FLOOD_CONFIRMED."))
        if label == "NO_FLOOD_CONFIRMED":
            if event.get("negative_evidence_basis") not in NEGATIVE_EVIDENCE:
                issues.append(PilotIssue("MISSING_EXPLICIT_NEGATIVE_EVIDENCE", subject, "A confirmed negative requires affirmative authoritative no-flood evidence."))
            evidence_text = " ".join([
                str(event.get("evidence_summary") or ""),
                *[str(value) for value in event.get("source_document_refs", []) if value is not None],
            ]).lower()
            if any(phrase in evidence_text for phrase in PROHIBITED_NEGATIVE_PHRASES):
                issues.append(PilotIssue("PROHIBITED_MISSING_REPORT_NEGATIVE", subject, "Missing reports cannot establish a negative label."))
        if label in TRAINING_EVENT_LABELS:
            if event.get("review_status") != "REVIEWED" or event.get("leakage_review_status") != "PASSED":
                issues.append(PilotIssue("EVENT_NOT_FULLY_REVIEWED", subject, "Confirmed labels require REVIEWED and leakage PASSED."))
            if not isinstance(event.get("reviewed_by"), str) or not event.get("reviewed_by", "").strip():
                issues.append(PilotIssue("MISSING_REVIEWER", subject, "Confirmed labels require reviewed_by."))
            if parse_iso_datetime(event.get("reviewed_at")) is None:
                issues.append(PilotIssue("MISSING_REVIEW_TIMESTAMP", subject, "Confirmed labels require reviewed_at."))
            if not event.get("source_document_refs"):
                issues.append(PilotIssue("MISSING_EVENT_SOURCE_DOCUMENT", subject, "Confirmed labels require acquired source document references."))
        source_ids = event.get("source_ids")
        if not isinstance(source_ids, list) or not source_ids:
            issues.append(PilotIssue("MISSING_EVENT_PROVENANCE", subject, "At least one event evidence source_id is required."))
        else:
            for source_id in source_ids:
                source = sources.get(source_id)
                if source is None:
                    issues.append(PilotIssue("UNKNOWN_EVENT_SOURCE", subject, f"Unknown event source: {source_id}"))
                elif source.get("source_role") not in EVENT_SOURCE_ROLES:
                    issues.append(PilotIssue("INVALID_EVENT_SOURCE_ROLE", subject, f"Source {source_id} is not flood-event evidence."))
        latitude, longitude = event.get("latitude"), event.get("longitude")
        if (latitude is None) != (longitude is None):
            issues.append(PilotIssue("INCOMPLETE_EVENT_COORDINATES", subject, "Latitude and longitude must be supplied together."))
        if latitude is not None and longitude is not None:
            if not _is_number(latitude) or not _is_number(longitude) or not -90 <= float(latitude) <= 90 or not -180 <= float(longitude) <= 180:
                issues.append(PilotIssue("INVALID_EVENT_COORDINATES", subject, "Event coordinates are outside legal ranges."))
            elif validate_city:
                try:
                    resolved = resolve_point(float(longitude), float(latitude))
                    if resolved.get("status") == "OUTSIDE_CALOOCAN":
                        issues.append(PilotIssue("EVENT_OUTSIDE_CALOOCAN", subject, "Event coordinates are outside Caloocan."))
                except (OSError, ValueError) as exc:
                    issues.append(PilotIssue("EVENT_SPATIAL_VALIDATION_FAILED", subject, str(exc)))
        elif event.get("location_scope") == "POINT":
            issues.append(PilotIssue("POINT_LOCATION_REQUIRES_COORDINATES", subject, "POINT events require validated coordinates."))
        start = parse_iso_datetime(event.get("event_start")) if event.get("event_start") is not None else None
        end = parse_iso_datetime(event.get("event_end")) if event.get("event_end") is not None else None
        if event.get("event_time_status") == "VALIDATED" and (start is None or end is None):
            issues.append(PilotIssue("UNRESOLVED_EVENT_TIME", subject, "VALIDATED event timing requires start and end."))
        if start and end and end <= start:
            issues.append(PilotIssue("INVALID_EVENT_TIME_ORDER", subject, "event_end must follow event_start."))
        windows = event.get("candidate_windows")
        if not isinstance(windows, list):
            issues.append(PilotIssue("INVALID_CANDIDATE_WINDOWS", subject, "candidate_windows must be an array."))
            continue
        for window in windows:
            window_id = str(window.get("window_id") or "unknown-window") if isinstance(window, dict) else "unknown-window"
            window_subject = f"{subject}:{window_id}"
            if not isinstance(window, dict):
                issues.append(PilotIssue("INVALID_CANDIDATE_WINDOW", window_subject, "Candidate window must be an object."))
                continue
            missing = sorted(WINDOW_FIELDS - set(window))
            extras = sorted(set(window) - WINDOW_FIELDS)
            if missing:
                issues.append(PilotIssue("MISSING_WINDOW_FIELDS", window_subject, f"Missing window fields: {', '.join(missing)}"))
            if extras:
                issues.append(PilotIssue("UNEXPECTED_WINDOW_FIELDS", window_subject, f"Unexpected window fields: {', '.join(extras)}"))
            try:
                cutoff = _timestamp(window.get("prediction_cutoff"), "prediction_cutoff")
                target_start = _timestamp(window.get("target_window_start"), "target_window_start")
                target_end = _timestamp(window.get("target_window_end"), "target_window_end")
                observed = _timestamp(window.get("label_observed_at"), "label_observed_at")
                if not cutoff < target_start < target_end:
                    issues.append(PilotIssue("INVALID_TARGET_WINDOW_ORDER", window_subject, "prediction_cutoff must precede the target evaluation period."))
                if observed < target_start:
                    issues.append(PilotIssue("LABEL_OBSERVED_BEFORE_TARGET", window_subject, "label_observed_at cannot precede the target period."))
                horizon = window.get("forecast_horizon_hours")
                expected = (target_end - cutoff).total_seconds() / 3600
                if not _is_number(horizon) or float(horizon) <= 0 or not math.isclose(float(horizon), expected, abs_tol=1e-9):
                    issues.append(PilotIssue("INVALID_FORECAST_HORIZON", window_subject, "forecast_horizon_hours must describe cutoff through target-window end."))
            except ValueError as exc:
                issues.append(PilotIssue("INVALID_WINDOW_TIMESTAMP", window_subject, str(exc)))
            mapping = window.get("precipitation_mapping")
            if not isinstance(mapping, dict):
                issues.append(PilotIssue("MISSING_PRECIPITATION_MAPPING", window_subject, "An explicit precipitation mapping is required."))
            else:
                issues.extend(validate_mapping(mapping, window_subject))
    return issues


def validate_precipitation_data(
    payload: Mapping[str, Any], sources: Mapping[str, Mapping[str, Any]]
) -> List[PilotIssue]:
    issues: List[PilotIssue] = []
    if payload.get("schema_version") != "1.0.0":
        issues.append(PilotIssue("INVALID_PRECIPITATION_SCHEMA_VERSION", "precipitation", "schema_version must be 1.0.0."))
    if payload.get("dataset_classification") not in {REAL_PRECIPITATION_CLASSIFICATION, FIXTURE_CLASSIFICATION}:
        issues.append(PilotIssue("INVALID_PRECIPITATION_CLASSIFICATION", "precipitation", "Dataset classification is invalid."))
    records = payload.get("records")
    if not isinstance(records, list):
        return issues + [PilotIssue("INVALID_PRECIPITATION_RECORDS", "precipitation", "records must be an array.")]
    seen: set[str] = set()
    for index, record in enumerate(records, start=1):
        subject = str(record.get("observation_id") or f"observation-{index}") if isinstance(record, dict) else f"observation-{index}"
        if not isinstance(record, dict):
            issues.append(PilotIssue("INVALID_PRECIPITATION_RECORD", subject, "Precipitation entries must be objects."))
            continue
        missing = sorted(PRECIPITATION_FIELDS - set(record))
        extras = sorted(set(record) - PRECIPITATION_FIELDS)
        if missing:
            issues.append(PilotIssue("MISSING_PRECIPITATION_FIELDS", subject, f"Missing precipitation fields: {', '.join(missing)}"))
        if extras:
            issues.append(PilotIssue("UNEXPECTED_PRECIPITATION_FIELDS", subject, f"Unexpected precipitation fields: {', '.join(extras)}"))
        observation_id = record.get("observation_id")
        if not isinstance(observation_id, str) or not observation_id.strip() or observation_id in seen:
            issues.append(PilotIssue("INVALID_OBSERVATION_ID", subject, "observation_id must be unique and non-empty."))
        else:
            seen.add(observation_id)
        if record.get("data_type") != "OBSERVED_PRECIPITATION":
            issues.append(PilotIssue("FORECAST_RECORD_IN_OBSERVED_DATASET", subject, "Observed and forecast precipitation records must remain separate."))
        source_id = record.get("source_id")
        source = sources.get(source_id) if isinstance(source_id, str) else None
        if source is None:
            issues.append(PilotIssue("UNSUPPORTED_PRECIPITATION_SOURCE", subject, "source_id is not in the governed registry."))
        else:
            if source.get("source_role") not in PRECIPITATION_SOURCE_ROLES:
                issues.append(PilotIssue("UNSUPPORTED_PRECIPITATION_SOURCE", subject, "Source role is not observed precipitation or an explicit cross-check."))
            if record.get("product_version") != source.get("product_version"):
                issues.append(PilotIssue("SOURCE_VERSION_MISMATCH", subject, "Observation product_version must match the source registry."))
            if record.get("source_resolution") != source.get("spatial_resolution"):
                issues.append(PilotIssue("SOURCE_RESOLUTION_MISMATCH", subject, "Observation source_resolution must match the source registry."))
        try:
            start = _timestamp(record.get("observed_at_start"), "observed_at_start")
            end = _timestamp(record.get("observed_at_end"), "observed_at_end")
            retrieved = _timestamp(record.get("retrieved_at"), "retrieved_at")
            if not start < end:
                issues.append(PilotIssue("INVALID_OBSERVATION_INTERVAL", subject, "observed_at_end must follow observed_at_start."))
            if retrieved < end:
                issues.append(PilotIssue("INVALID_RETRIEVAL_TIME", subject, "retrieved_at cannot precede the observation interval."))
        except ValueError as exc:
            issues.append(PilotIssue("INVALID_PRECIPITATION_TIMESTAMP", subject, str(exc)))
        latitude, longitude = record.get("latitude"), record.get("longitude")
        if not _is_number(latitude) or not -90 <= float(latitude) <= 90 or not _is_number(longitude) or not -180 <= float(longitude) <= 180:
            issues.append(PilotIssue("INVALID_PRECIPITATION_COORDINATES", subject, "Grid coordinates are outside legal ranges."))
        if not isinstance(record.get("grid_cell_id"), str) or not record.get("grid_cell_id", "").strip():
            issues.append(PilotIssue("MISSING_GRID_CELL_ID", subject, "grid_cell_id is required."))
        native_value = record.get("native_value")
        precipitation = record.get("precipitation_mm")
        if not _is_number(native_value):
            issues.append(PilotIssue("INVALID_NATIVE_PRECIPITATION", subject, "native_value must be finite."))
        if not _is_number(precipitation) or float(precipitation) < 0:
            issues.append(PilotIssue("MISSING_OR_INVALID_PRECIPITATION", subject, "precipitation_mm must be non-negative and is never imputed."))
        method = record.get("normalization_method")
        if _is_number(native_value) and _is_number(precipitation):
            duration_hours = None
            try:
                duration_hours = (_timestamp(record.get("observed_at_end"), "end") - _timestamp(record.get("observed_at_start"), "start")).total_seconds() / 3600
            except ValueError:
                pass
            expected = None
            if method == "NO_CONVERSION_NATIVE_MM_ACCUMULATION" and record.get("native_units") == "mm":
                expected = float(native_value)
            elif method == "RATE_TO_INTERVAL_ACCUMULATION" and record.get("native_units") == "mm/hr" and duration_hours is not None:
                expected = float(native_value) * duration_hours
            elif method == "METERS_TO_MILLIMETERS" and record.get("native_units") == "m":
                expected = float(native_value) * 1000
            if expected is None or not math.isclose(float(precipitation), expected, rel_tol=1e-9, abs_tol=1e-9):
                issues.append(PilotIssue("UNVERIFIED_PRECIPITATION_CONVERSION", subject, "Native value, unit, interval, and precipitation_mm conversion are inconsistent."))
        if record.get("quality_flag") not in {"VALIDATED", "PROVISIONAL", "SUSPECT", "MISSING"}:
            issues.append(PilotIssue("INVALID_QUALITY_FLAG", subject, "quality_flag is invalid."))
        checksum = record.get("raw_file_checksum")
        if not isinstance(checksum, str) or len(checksum) != 64 or any(character not in "0123456789abcdef" for character in checksum):
            issues.append(PilotIssue("INVALID_RAW_CHECKSUM", subject, "A lowercase SHA-256 raw file checksum is required."))
        raw_reference = record.get("raw_file_reference")
        if not isinstance(raw_reference, str) or not raw_reference.strip():
            issues.append(PilotIssue("MISSING_RAW_FILE_REFERENCE", subject, "Immutable raw file reference is required."))
        elif Path(raw_reference).is_absolute() or ".." in Path(raw_reference).parts:
            issues.append(PilotIssue("UNSAFE_RAW_FILE_REFERENCE", subject, "Raw file reference must be repository-relative without parent traversal."))
        else:
            raw_path = REPO_ROOT / raw_reference
            if not raw_path.is_file():
                issues.append(PilotIssue("RAW_FILE_NOT_FOUND", subject, "Raw file reference does not exist."))
            elif isinstance(checksum, str) and hashlib.sha256(raw_path.read_bytes()).hexdigest() != checksum:
                issues.append(PilotIssue("RAW_FILE_CHECKSUM_MISMATCH", subject, "Raw file checksum does not match the immutable source."))
    return issues


def _mapping_cells(mapping: Mapping[str, Any]) -> List[Tuple[str, float]]:
    cells = [
        (str(cell["grid_cell_id"]), float(cell["weight"]))
        for cell in mapping["source_grid_cells"]
    ]
    return sorted(cells, key=lambda item: item[0])


def aggregate_antecedent_precipitation(
    records: Sequence[Mapping[str, Any]], mapping: Mapping[str, Any], prediction_cutoff: datetime, window_hours: int
) -> Dict[str, Any]:
    cutoff = prediction_cutoff.astimezone(timezone.utc)
    window_start = cutoff - timedelta(hours=window_hours)
    source_id = mapping["source_id"]
    cell_totals: List[Tuple[str, float, float, List[str]]] = []
    product_versions: set[str] = set()
    for cell_id, weight in _mapping_cells(mapping):
        selected: List[Tuple[datetime, datetime, Mapping[str, Any]]] = []
        for record in records:
            if record.get("source_id") != source_id or record.get("grid_cell_id") != cell_id:
                continue
            try:
                start = _timestamp(record.get("observed_at_start"), "observed_at_start")
                end = _timestamp(record.get("observed_at_end"), "observed_at_end")
            except ValueError:
                continue
            if start >= window_start and end <= cutoff:
                selected.append((start, end, record))
        selected.sort(key=lambda item: (item[0], item[1], str(item[2].get("observation_id"))))
        if not selected:
            raise AggregationError("RAINFALL_COVERAGE_INCOMPLETE", f"No pre-cutoff precipitation covers {cell_id} for {window_hours}h.")
        cursor = window_start
        total = 0.0
        observation_ids: List[str] = []
        for start, end, record in selected:
            if start != cursor or end <= start:
                raise AggregationError("RAINFALL_COVERAGE_INCOMPLETE", f"Precipitation intervals are gapped or overlapping for {cell_id}.")
            if end > cutoff:
                raise AggregationError("POST_CUTOFF_RAINFALL", "Post-cutoff precipitation cannot become an antecedent feature.")
            value = record.get("precipitation_mm")
            if not _is_number(value) or float(value) < 0:
                raise AggregationError("MISSING_RAINFALL_NOT_IMPUTED", "Missing rainfall is never filled with zero.")
            if record.get("data_type") != "OBSERVED_PRECIPITATION":
                raise AggregationError("FORECAST_OBSERVED_SUBSTITUTION", "Forecast precipitation cannot be aggregated as observed precipitation.")
            if record.get("quality_flag") != "VALIDATED":
                raise AggregationError("UNVALIDATED_PRECIPITATION", "Only validated precipitation observations may be aggregated.")
            total += float(value)
            observation_ids.append(str(record.get("observation_id")))
            product_versions.add(str(record.get("product_version")))
            cursor = end
        if cursor != cutoff:
            raise AggregationError("RAINFALL_COVERAGE_INCOMPLETE", f"Precipitation does not cover the complete {window_hours}h interval for {cell_id}.")
        cell_totals.append((cell_id, weight, total, observation_ids))
    if len(product_versions) != 1:
        raise AggregationError("MIXED_PRODUCT_VERSIONS", "One aggregate cannot mix precipitation product versions.")
    return {
        "precipitation_mm": sum(weight * total for _, weight, total, _ in cell_totals),
        "source_id": source_id,
        "product_version": next(iter(product_versions)),
        "data_type": "OBSERVED_PRECIPITATION",
        "source_resolution": mapping["source_resolution"],
        "mapping_method": mapping["mapping_method"],
        "mapping_version": mapping["mapping_version"],
        "source_grid_cells": [cell_id for cell_id, _, _, _ in cell_totals],
        "observation_ids": [identifier for _, _, _, identifiers in cell_totals for identifier in identifiers],
    }


def _pilot_source_reasons(source: Mapping[str, Any] | None, role_set: set[str]) -> List[str]:
    if source is None:
        return ["UNKNOWN_SOURCE_PROVENANCE"]
    reasons: List[str] = []
    if source.get("source_role") not in role_set:
        reasons.append("UNSUPPORTED_SOURCE_ROLE")
    if source.get("status") != "APPROVED_FOR_PILOT":
        reasons.append("SOURCE_NOT_APPROVED_FOR_PILOT")
    if source.get("review_status") != "APPROVED_FOR_GOVERNED_USE":
        reasons.append("SOURCE_GOVERNANCE_REVIEW_NOT_APPROVED")
    if source.get("availability_status") != "ACQUIRED":
        reasons.append("SOURCE_NOT_ACQUIRED")
    if source.get("license_or_usage_status") != "PERMITTED_FOR_PILOT":
        reasons.append("SOURCE_USAGE_NOT_APPROVED")
    if not source.get("retrieved_at") or not source.get("local_file") or not source.get("sha256"):
        reasons.append("INCOMPLETE_ACQUISITION_PROVENANCE")
    for field in ("product_version", "citation", "temporal_resolution", "spatial_resolution"):
        if source.get(field) is None or not str(source.get(field)).strip():
            reasons.append("INCOMPLETE_SOURCE_METADATA")
            break
    return reasons


def _exclusion(event_id: str, window_id: str | None, reasons: Iterable[str]) -> Dict[str, Any]:
    return {"event_id": event_id, "window_id": window_id, "reasons": sorted(set(reasons))}


def render_pilot_csv(rows: Sequence[Mapping[str, Any]]) -> bytes:
    stream = io.StringIO(newline="")
    writer = csv.DictWriter(stream, fieldnames=OUTPUT_FIELDS, extrasaction="raise", lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    return stream.getvalue().encode("utf-8")


def build_pilot_dataset(
    registry: Mapping[str, Any],
    precipitation: Mapping[str, Any],
    manifest: Mapping[str, Any],
    sources: Mapping[str, Mapping[str, Any]],
    protocol: Mapping[str, Any],
    *,
    dataset_version: str,
    created_at: str,
    validate_city: bool = True,
) -> Tuple[List[Dict[str, Any]], Dict[str, Any]]:
    protocol_issues = validate_label_protocol(protocol)
    event_issues = validate_event_registry(registry, sources, validate_city=validate_city)
    precipitation_issues = validate_precipitation_data(precipitation, sources)
    manifest_issues = validate_manifest(manifest, repo_root=REPO_ROOT)
    fixture_data = (
        registry.get("dataset_classification") == FIXTURE_CLASSIFICATION
        or precipitation.get("dataset_classification") == FIXTURE_CLASSIFICATION
    )
    records = precipitation.get("records", []) if isinstance(precipitation.get("records"), list) else []
    events = registry.get("events", []) if isinstance(registry.get("events"), list) else []
    rows: List[Dict[str, Any]] = []
    exclusions: List[Dict[str, Any]] = []
    global_reasons: List[str] = []
    if manifest_issues:
        global_reasons.append("SOURCE_REGISTRY_INVALID")
    if protocol_issues:
        global_reasons.append("LABEL_PROTOCOL_INVALID")
    if precipitation_issues:
        global_reasons.append("PRECIPITATION_DATA_INVALID")
    if any(issue.subject_id == "registry" for issue in event_issues):
        global_reasons.append("EVENT_REGISTRY_INVALID")
    if fixture_data:
        global_reasons.append("TEST_FIXTURE_NOT_FOR_TRAINING")
    event_issue_codes: Dict[str, List[str]] = {}
    for issue in event_issues:
        event_key = issue.subject_id.split(":", 1)[0]
        event_issue_codes.setdefault(event_key, []).append(issue.code)

    valid_events = sorted(
        (entry for entry in events if isinstance(entry, dict)), key=lambda entry: str(entry.get("event_id"))
    )
    for event in valid_events:
        event_id = str(event.get("event_id") or "unknown-event")
        base_reasons = list(global_reasons)
        base_reasons.extend(event_issue_codes.get(event_id, []))
        label = event.get("label_status")
        if label == "UNKNOWN":
            base_reasons.append("UNKNOWN_LABEL")
        elif label not in TRAINING_EVENT_LABELS:
            base_reasons.append("UNSUPPORTED_LABEL")
        if event.get("training_eligible") is not True:
            base_reasons.append("EVENT_NOT_TRAINING_ELIGIBLE")
        if event.get("review_status") != "REVIEWED":
            base_reasons.append("EVENT_NOT_REVIEWED")
        if event.get("leakage_review_status") != "PASSED":
            base_reasons.append("LEAKAGE_REVIEW_NOT_PASSED")
        for source_id in event.get("source_ids", []):
            base_reasons.extend(_pilot_source_reasons(sources.get(source_id), EVENT_SOURCE_ROLES))
        windows = event.get("candidate_windows") if isinstance(event.get("candidate_windows"), list) else []
        if not windows:
            exclusions.append(_exclusion(event_id, None, base_reasons + ["NO_REVIEWED_CANDIDATE_WINDOW"]))
            continue
        valid_windows = sorted(
            (entry for entry in windows if isinstance(entry, dict)), key=lambda entry: str(entry.get("window_id"))
        )
        for window in valid_windows:
            window_id = str(window.get("window_id") or "unknown-window")
            reasons = list(base_reasons)
            mapping = window.get("precipitation_mapping") if isinstance(window.get("precipitation_mapping"), dict) else {}
            weather_source_id = mapping.get("source_id")
            weather_source = sources.get(weather_source_id) if isinstance(weather_source_id, str) else None
            reasons.extend(_pilot_source_reasons(weather_source, PRECIPITATION_SOURCE_ROLES))
            aggregates: Dict[int, Dict[str, Any]] = {}
            try:
                cutoff = _timestamp(window.get("prediction_cutoff"), "prediction_cutoff")
            except ValueError:
                cutoff = None
                reasons.append("INVALID_PREDICTION_CUTOFF")
            if cutoff is not None:
                for hours in AGGREGATION_WINDOWS:
                    try:
                        aggregates[hours] = aggregate_antecedent_precipitation(records, mapping, cutoff, hours)
                    except (AggregationError, KeyError, TypeError, ValueError) as exc:
                        reasons.append(exc.code if isinstance(exc, AggregationError) else "SPATIAL_OR_TEMPORAL_AGGREGATION_FAILED")
                        break
            if reasons:
                exclusions.append(_exclusion(event_id, window_id, reasons))
                continue
            aggregate_reference = aggregates[AGGREGATION_WINDOWS[0]]
            row = {
                "record_id": f"pilot:{event_id}:{window_id}:{weather_source_id}",
                "event_id": event_id,
                "window_id": window_id,
                "prediction_cutoff": window["prediction_cutoff"],
                "target_window_start": window["target_window_start"],
                "target_window_end": window["target_window_end"],
                "forecast_horizon_hours": window["forecast_horizon_hours"],
                "location_scope": event.get("location_scope"),
                "barangay_psgc": event.get("barangay_psgc"),
                "latitude": event.get("latitude"),
                "longitude": event.get("longitude"),
                "label_status": label,
                "flood_outcome": 1 if label == "FLOOD_CONFIRMED" else 0,
                "precipitation_data_type": "OBSERVED_PRECIPITATION",
                "precipitation_source_id": weather_source_id,
                "precipitation_product_version": aggregate_reference["product_version"],
                "mapping_method": mapping["mapping_method"],
                "mapping_version": mapping["mapping_version"],
                "source_resolution": mapping["source_resolution"],
                "source_grid_cells_json": json.dumps(
                    [
                        {"grid_cell_id": cell_id, "weight": weight}
                        for cell_id, weight in _mapping_cells(mapping)
                    ],
                    separators=(",", ":"),
                    sort_keys=True,
                ),
                "mapping_distance_m": mapping.get("distance_m"),
                "mapping_coverage_fraction": mapping.get("coverage_fraction"),
                "mapping_limitation_note": mapping["limitation_note"],
                "label_protocol_version": protocol.get("protocol_version"),
            }
            for hours in AGGREGATION_WINDOWS:
                row[f"rainfall_{hours}h_mm"] = float(format(aggregates[hours]["precipitation_mm"], ".12g"))
            rows.append(row)

    rows.sort(key=lambda row: (
        str(row["event_id"]), str(row["prediction_cutoff"]), str(row["window_id"]),
        str(row["precipitation_source_id"]),
    ))
    exclusions.sort(key=lambda row: (str(row["event_id"]), str(row.get("window_id")), tuple(row["reasons"])))
    csv_bytes = render_pilot_csv(rows)
    positives = sum(1 for row in rows if row["label_status"] == "FLOOD_CONFIRMED")
    negatives = sum(1 for row in rows if row["label_status"] == "NO_FLOOD_CONFIRMED")
    labels = positives + negatives
    starts = sorted(str(row["target_window_start"]) for row in rows)
    ends = sorted(str(row["target_window_end"]) for row in rows)
    reason_counts = Counter(reason for exclusion in exclusions for reason in exclusion["reasons"])
    used_source_ids = sorted({
        str(source_id)
        for event in events if isinstance(event, dict)
        for source_id in [
            *event.get("source_ids", []),
            *[
                window.get("precipitation_mapping", {}).get("source_id")
                for window in event.get("candidate_windows", []) if isinstance(window, dict)
            ],
        ]
        if source_id
    })
    source_versions = {source_id: sources.get(source_id, {}).get("product_version") for source_id in used_source_ids}
    source_licenses = {source_id: sources.get(source_id, {}).get("license_or_usage_status") for source_id in used_source_ids}
    precipitation_coverage = Counter(str(row["precipitation_source_id"]) for row in rows)
    spatial_psgc = sorted({str(row["barangay_psgc"]) for row in rows if row.get("barangay_psgc")})
    spatial_scopes = sorted({str(row["location_scope"]) for row in rows if row.get("location_scope")})
    missing_precipitation = sum(1 for record in records if not _is_number(record.get("precipitation_mm")))
    result_manifest = {
        "manifest_version": "1.0.0",
        "dataset_version": dataset_version,
        "created_at": _timestamp(created_at, "created_at").isoformat().replace("+00:00", "Z"),
        "dataset_classification": FIXTURE_CLASSIFICATION if fixture_data else PILOT_CLASSIFICATION,
        "record_count": len(rows),
        "positive_count": positives,
        "negative_count": negatives,
        "unknown_event_count": sum(1 for event in events if isinstance(event, dict) and event.get("label_status") == "UNKNOWN"),
        "independent_event_count": len({str(row["event_id"]) for row in rows}),
        "date_range": {"start": starts[0] if starts else None, "end": ends[-1] if ends else None},
        "spatial_coverage": {"barangay_psgc": spatial_psgc, "location_scopes": spatial_scopes},
        "precipitation_source_coverage": dict(sorted(precipitation_coverage.items())),
        "source_versions": source_versions,
        "source_license_status": source_licenses,
        "label_protocol_version": str(protocol.get("protocol_version")),
        "feature_schema_version": "PILOT_CANDIDATE_FEATURES_1.0.0",
        "forecast_features_status": "NOT_AVAILABLE_FOR_TRAINING",
        "missingness": {
            "precipitation_records_with_missing_mm": missing_precipitation,
            "candidate_windows_missing_required_antecedent_coverage": reason_counts.get("RAINFALL_COVERAGE_INCOMPLETE", 0),
        },
        "excluded_record_count": len(exclusions),
        "exclusion_reason_counts": dict(sorted(reason_counts.items())),
        "exclusions": exclusions,
        "leakage_failure_count": sum(
            count for reason, count in reason_counts.items()
            if reason in {"LEAKAGE_REVIEW_NOT_PASSED", "POST_CUTOFF_RAINFALL", "INVALID_TARGET_WINDOW_ORDER"}
        ),
        "class_ratio": {
            "positive_fraction": positives / labels if labels else None,
            "negative_fraction": negatives / labels if labels else None,
        },
        "dataset_sha256": hashlib.sha256(csv_bytes).hexdigest(),
        "training_authorization_status": "NOT_APPROVED",
        "training_ready": False,
        "tensorflow_training_performed": False,
        "model_artifact_created": False,
    }
    return rows, result_manifest


def load_pilot_inputs(
    event_registry_path: Path = DEFAULT_EVENT_REGISTRY,
    precipitation_path: Path = DEFAULT_PRECIPITATION_DATA,
    manifest_path: Path = DEFAULT_MANIFEST,
    protocol_path: Path = DEFAULT_LABEL_PROTOCOL,
) -> Tuple[Dict[str, Any], Dict[str, Any], Dict[str, Any], Dict[str, Dict[str, Any]], Dict[str, Any]]:
    registry = load_event_registry(event_registry_path)
    precipitation = load_precipitation_data(precipitation_path)
    manifest, sources = load_manifest(manifest_path)
    protocol = read_json(protocol_path)
    if not isinstance(protocol, dict) or validate_label_protocol(protocol):
        raise ValueError("Label protocol must be version 1.0.0.")
    return registry, precipitation, manifest, sources, protocol


def write_pilot_csv(path: Path, rows: Sequence[Mapping[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(render_pilot_csv(rows))
