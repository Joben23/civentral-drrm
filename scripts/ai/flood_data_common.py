"""Shared Phase 7B flood-data governance utilities.

This module intentionally uses only the Python standard library and contains no
TensorFlow code. It validates evidence records; it does not infer, impute, or
repair values.
"""

from __future__ import annotations

import csv
import hashlib
import json
import math
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, Tuple


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DEFAULT_MANIFEST = WORKSPACE / "manifests" / "source-manifest.json"
DEFAULT_GOVERNANCE = WORKSPACE / "config" / "governance-v1.json"
DEFAULT_BARANGAYS = REPO_ROOT / "data" / "import" / "caloocan-barangays-current-unaffected.geojson"
DEFAULT_REVIEWED_DATA = WORKSPACE / "data" / "reviewed"
DEFAULT_AUTHORIZATION = WORKSPACE / "manifests" / "training-authorization.json"

SCHEMA_VERSION = "1.0.0"
ALLOWED_LABELS = {
    "VERIFIED_FLOOD_OBSERVED",
    "VERIFIED_NO_FLOOD_OBSERVED",
    "UNKNOWN",
    "AMBIGUOUS",
    "EXCLUDED",
}
TRAINING_LABELS = {"VERIFIED_FLOOD_OBSERVED", "VERIFIED_NO_FLOOD_OBSERVED"}
ALLOWED_SUSCEPTIBILITY = {"LF", "MF", "HF", "VHF", "NONE"}
ALLOWED_CONFIDENCE = {"HIGH", "MEDIUM", "LOW", None}
TRAINING_CONFIDENCE = {"HIGH", "MEDIUM"}
ALLOWED_REVIEW = {"UNREVIEWED", "REQUIRES_HUMAN_REVIEW", "REVIEWED", "REJECTED"}
ALLOWED_MAPPING = {"VALIDATED", "UNKNOWN_SPATIAL_MAPPING"}
ALLOWED_LEAKAGE = {"NOT_REVIEWED", "PASSED", "FAILED"}
ALLOWED_NEGATIVE_BASIS = {
    "EXPLICIT_LGU_MONITORING_LOG",
    "EXPLICIT_AUTHORITATIVE_NO_FLOOD_REPORT",
    "VALIDATED_OBSERVATION_LOG",
    None,
}
APPROVED_SOURCE_STATUS = "APPROVED_FOR_GOVERNED_USE"
ALLOWED_SOURCE_TYPES = {
    "GIS_COVARIATE", "SPATIAL_REFERENCE", "FORECAST_API", "FORECAST_ARCHIVE",
    "OBSERVED_WEATHER", "WEATHER_BUNDLE", "INCIDENT_REPORT", "MONITORING_LOG",
    "SATELLITE_DERIVED",
}
ALLOWED_SOURCE_REVIEW = {
    "APPROVED_FOR_GOVERNED_USE", "REQUIRES_HUMAN_REVIEW", "ACCESS_PENDING",
    "METADATA_PENDING", "REJECTED",
}
REQUIRED_WEATHER_UNITS = {
    "forecast_rainfall_24h_mm": "mm",
    "antecedent_rainfall_24h_mm": "mm",
    "antecedent_rainfall_72h_mm": "mm",
}
REQUIRED_ACCUMULATION_SEMANTICS = {
    "forecast_rainfall_24h_mm": "FORECAST_TOTAL_VALID_24H",
    "antecedent_rainfall_24h_mm": "OBSERVED_TOTAL_PRE_ISSUANCE_24H",
    "antecedent_rainfall_72h_mm": "OBSERVED_TOTAL_PRE_ISSUANCE_72H",
}
REQUIRED_FEATURES = (
    "forecast_rainfall_24h_mm",
    "antecedent_rainfall_24h_mm",
    "antecedent_rainfall_72h_mm",
    "mgb_flood_susceptibility_code",
)
RAIN_FIELDS = REQUIRED_FEATURES[:3]
SOURCE_FIELDS = ("flood_evidence_source", "weather_source", "susceptibility_source")
REQUIRED_FIELDS = (
    "schema_version",
    "record_id",
    "event_id",
    "spatial_unit_type",
    "spatial_mapping_status",
    "barangay_psgc",
    "barangay_name",
    "forecast_issued_at",
    "valid_from",
    "valid_until",
    "forecast_rainfall_24h_mm",
    "antecedent_rainfall_24h_mm",
    "antecedent_rainfall_72h_mm",
    "rainfall_unit",
    "rainfall_metadata_status",
    "mgb_flood_susceptibility_code",
    "month_sin",
    "month_cos",
    "flood_outcome",
    "label_status",
    "label_confidence",
    "negative_evidence_basis",
    "flood_evidence_source",
    "weather_source",
    "susceptibility_source",
    "source_document_reference",
    "source_retrieved_at",
    "review_status",
    "reviewed_at",
    "review_notes",
    "leakage_review_status",
)
NUMERIC_CSV_FIELDS = set(RAIN_FIELDS) | {"month_sin", "month_cos"}
INTEGER_CSV_FIELDS = {"flood_outcome"}
NULL_LITERALS = {"", "null", "none", "na", "n/a"}
AMBIGUOUS_176_PSGC = {"PH1307501176", "1380100176"} | {str(value) for value in range(1380100189, 1380100195)}
AMBIGUOUS_176_NAME = re.compile(r"^barangay\s*176(?:\s*-?\s*[a-f])?$", re.IGNORECASE)


@dataclass(frozen=True)
class LoadedRecord:
    data: Dict[str, Any]
    source_file: str
    row_number: int


@dataclass(frozen=True)
class ValidationIssue:
    source_file: str
    row_number: int
    record_id: Optional[str]
    code: str
    message: str
    severity: str = "ERROR"

    def as_dict(self) -> Dict[str, Any]:
        return {
            "source_file": self.source_file,
            "row_number": self.row_number,
            "record_id": self.record_id,
            "severity": self.severity,
            "code": self.code,
            "message": self.message,
        }


def read_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, indent=2, ensure_ascii=False, sort_keys=True)
        handle.write("\n")


def parse_iso_datetime(value: Any) -> Optional[datetime]:
    if not isinstance(value, str) or not value.strip():
        return None
    candidate = value.strip()
    if candidate.endswith("Z"):
        candidate = candidate[:-1] + "+00:00"
    try:
        parsed = datetime.fromisoformat(candidate)
    except ValueError:
        return None
    if parsed.tzinfo is None or parsed.utcoffset() is None:
        return None
    return parsed


def month_components(timestamp: datetime) -> Tuple[float, float]:
    angle = 2.0 * math.pi * (timestamp.month - 1) / 12.0
    return math.sin(angle), math.cos(angle)


def _csv_value(field: str, value: str) -> Any:
    stripped = value.strip()
    if stripped.lower() in NULL_LITERALS:
        return None
    if field in INTEGER_CSV_FIELDS:
        if re.fullmatch(r"[+-]?\d+", stripped):
            return int(stripped)
        return stripped
    if field in NUMERIC_CSV_FIELDS:
        try:
            return float(stripped)
        except ValueError:
            return stripped
    return stripped


def _records_from_json(path: Path) -> List[Dict[str, Any]]:
    payload = read_json(path)
    if isinstance(payload, list):
        records = payload
    elif isinstance(payload, dict) and isinstance(payload.get("records"), list):
        records = payload["records"]
    elif isinstance(payload, dict):
        records = [payload]
    else:
        raise ValueError("JSON input must be an object, an array, or an object with a records array.")
    if not all(isinstance(record, dict) for record in records):
        raise ValueError("Every JSON record must be an object.")
    return [dict(record) for record in records]


def _records_from_jsonl(path: Path) -> List[Dict[str, Any]]:
    records: List[Dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            if not line.strip():
                continue
            payload = json.loads(line)
            if not isinstance(payload, dict):
                raise ValueError(f"JSONL line {line_number} must be an object.")
            records.append(dict(payload))
    return records


def _records_from_csv(path: Path) -> List[Dict[str, Any]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            raise ValueError("CSV input has no header.")
        if len(reader.fieldnames) != len(set(reader.fieldnames)):
            raise ValueError("CSV input contains duplicate column names.")
        return [
            {field: _csv_value(field, value or "") for field, value in row.items()}
            for row in reader
        ]


def discover_record_files(input_path: Path) -> List[Path]:
    if input_path.is_file():
        return [input_path]
    if not input_path.exists():
        raise FileNotFoundError(f"Input path does not exist: {input_path}")
    suffixes = {".json", ".jsonl", ".csv"}
    return sorted(
        path for path in input_path.rglob("*")
        if path.is_file() and path.suffix.lower() in suffixes and not path.name.startswith(".")
    )


def load_records(input_path: Path) -> List[LoadedRecord]:
    loaded: List[LoadedRecord] = []
    for path in discover_record_files(input_path):
        suffix = path.suffix.lower()
        if suffix == ".csv":
            records = _records_from_csv(path)
        elif suffix == ".jsonl":
            records = _records_from_jsonl(path)
        else:
            records = _records_from_json(path)
        for index, record in enumerate(records, start=1):
            loaded.append(LoadedRecord(record, str(path), index))
    return loaded


def load_barangay_reference(path: Path = DEFAULT_BARANGAYS) -> Dict[str, str]:
    payload = read_json(path)
    features = payload.get("features") if isinstance(payload, dict) else None
    if not isinstance(features, list):
        raise ValueError("Barangay reference must be a GeoJSON FeatureCollection.")
    result: Dict[str, str] = {}
    for feature in features:
        properties = feature.get("properties", {}) if isinstance(feature, dict) else {}
        psgc = properties.get("current_psgc_10_digit")
        name = properties.get("current_barangay_name")
        if not isinstance(psgc, str) or not isinstance(name, str):
            raise ValueError("Barangay reference contains a feature without current 10-digit PSGC/name metadata.")
        if psgc in result:
            raise ValueError(f"Duplicate barangay identifier in reference: {psgc}")
        result[psgc] = name
    if len(result) != 187:
        raise ValueError(f"Expected the reconciled 187-barangay reference; found {len(result)} entries.")
    if any(code in result for code in AMBIGUOUS_176_PSGC) or any(AMBIGUOUS_176_NAME.match(name) for name in result.values()):
        raise ValueError("Reconciled barangay reference must exclude legacy/split Barangay 176 entries.")
    return result


def load_manifest(path: Path = DEFAULT_MANIFEST) -> Tuple[Dict[str, Any], Dict[str, Dict[str, Any]]]:
    payload = read_json(path)
    if not isinstance(payload, dict) or payload.get("manifest_version") != "1.0.0":
        raise ValueError("Provenance manifest_version must be 1.0.0.")
    sources = payload.get("sources")
    if not isinstance(sources, list):
        raise ValueError("Provenance manifest sources must be an array.")
    by_id: Dict[str, Dict[str, Any]] = {}
    for source in sources:
        if not isinstance(source, dict) or not isinstance(source.get("source_id"), str):
            raise ValueError("Every provenance source must have a string source_id.")
        source_id = source["source_id"]
        if source_id in by_id:
            raise ValueError(f"Duplicate provenance source_id: {source_id}")
        by_id[source_id] = source
    return payload, by_id


def validate_manifest(
    manifest: Mapping[str, Any], repo_root: Path = REPO_ROOT
) -> List[ValidationIssue]:
    issues: List[ValidationIssue] = []
    required = {
        "source_id", "source_name", "agency", "source_type", "official_reference",
        "local_file", "retrieved_at", "coverage", "time_range", "units",
        "accumulation_semantics", "version", "license_usage_notes", "sha256",
        "review_status", "review_notes",
    }
    seen: set[str] = set()
    sources = manifest.get("sources", [])
    for index, source in enumerate(sources, start=1):
        source_id = source.get("source_id") if isinstance(source, dict) else None
        prefix = str(source_id) if source_id else f"manifest-row-{index}"
        if not isinstance(source, dict):
            issues.append(ValidationIssue("manifest", index, None, "INVALID_SOURCE", "Source entry is not an object."))
            continue
        missing = sorted(required - set(source))
        if missing:
            issues.append(ValidationIssue("manifest", index, prefix, "MISSING_MANIFEST_FIELDS", f"Missing fields: {', '.join(missing)}"))
        extras = sorted(set(source) - required)
        if extras:
            issues.append(ValidationIssue("manifest", index, prefix, "UNEXPECTED_MANIFEST_FIELDS", f"Unexpected fields: {', '.join(extras)}"))
        sensitive_names = [key for key in source if re.search(r"token|password|secret|credential|api[_-]?key", key, re.IGNORECASE)]
        if sensitive_names:
            issues.append(ValidationIssue("manifest", index, prefix, "SENSITIVE_MANIFEST_FIELD", f"Credential-like fields are prohibited: {', '.join(sorted(sensitive_names))}"))
        if not isinstance(source_id, str) or not re.fullmatch(r"[a-z0-9][a-z0-9._-]+", source_id):
            issues.append(ValidationIssue("manifest", index, prefix, "INVALID_SOURCE_ID", "source_id must use lowercase letters, numbers, dots, underscores, or hyphens."))
        elif source_id in seen:
            issues.append(ValidationIssue("manifest", index, source_id, "DUPLICATE_SOURCE_ID", "source_id is duplicated."))
        else:
            seen.add(source_id)
        reference = source.get("official_reference")
        if not isinstance(reference, str) or not reference.startswith("https://"):
            issues.append(ValidationIssue("manifest", index, prefix, "INVALID_OFFICIAL_REFERENCE", "official_reference must be an HTTPS URL."))
        if source.get("source_type") not in tuple(ALLOWED_SOURCE_TYPES):
            issues.append(ValidationIssue("manifest", index, prefix, "INVALID_SOURCE_TYPE", "source_type is not allowed."))
        if source.get("review_status") not in tuple(ALLOWED_SOURCE_REVIEW):
            issues.append(ValidationIssue("manifest", index, prefix, "INVALID_SOURCE_REVIEW_STATUS", "review_status is not allowed."))
        if not isinstance(source.get("coverage"), str) or not source.get("coverage", "").strip():
            issues.append(ValidationIssue("manifest", index, prefix, "MISSING_COVERAGE", "coverage must be documented."))
        if not isinstance(source.get("license_usage_notes"), str) or not source.get("license_usage_notes", "").strip():
            issues.append(ValidationIssue("manifest", index, prefix, "MISSING_USAGE_NOTES", "license/usage notes must be documented, including when unknown."))
        local_file = source.get("local_file")
        checksum = source.get("sha256")
        if local_file is not None:
            if not isinstance(local_file, str) or Path(local_file).is_absolute() or ".." in Path(local_file).parts:
                issues.append(ValidationIssue("manifest", index, prefix, "UNSAFE_LOCAL_FILE", "local_file must be a repository-relative path without parent traversal."))
            else:
                resolved = repo_root / local_file
                if not resolved.is_file():
                    issues.append(ValidationIssue("manifest", index, prefix, "LOCAL_FILE_NOT_FOUND", f"Manifest local file does not exist: {local_file}"))
                elif not isinstance(checksum, str) or not re.fullmatch(r"[a-f0-9]{64}", checksum):
                    issues.append(ValidationIssue("manifest", index, prefix, "MISSING_CHECKSUM", "A local file requires a lowercase SHA-256 checksum."))
                else:
                    actual = hashlib.sha256(resolved.read_bytes()).hexdigest()
                    if actual != checksum:
                        issues.append(ValidationIssue("manifest", index, prefix, "CHECKSUM_MISMATCH", f"Checksum does not match {local_file}."))
        elif checksum is not None:
            issues.append(ValidationIssue("manifest", index, prefix, "ORPHAN_CHECKSUM", "sha256 must be null when no local_file exists."))
    return issues


def _issue(record: LoadedRecord, code: str, message: str) -> ValidationIssue:
    record_id = record.data.get("record_id")
    return ValidationIssue(
        record.source_file,
        record.row_number,
        str(record_id) if record_id is not None else None,
        code,
        message,
    )


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(float(value))


def is_ambiguous_barangay_176(psgc: Any, name: Any) -> bool:
    return (
        isinstance(psgc, str) and psgc.strip() in AMBIGUOUS_176_PSGC
    ) or (
        isinstance(name, str) and AMBIGUOUS_176_NAME.match(name.strip()) is not None
    )


def validate_record(
    loaded: LoadedRecord,
    sources: Mapping[str, Mapping[str, Any]],
    barangays: Mapping[str, str],
    mode: str = "reviewed",
) -> List[ValidationIssue]:
    record = loaded.data
    issues: List[ValidationIssue] = []
    if mode not in {"canonical", "reviewed"}:
        raise ValueError("Validation mode must be canonical or reviewed.")

    missing_keys = [field for field in REQUIRED_FIELDS if field not in record]
    if missing_keys:
        issues.append(_issue(loaded, "MISSING_REQUIRED_FIELDS", f"Missing fields: {', '.join(missing_keys)}"))
    extras = sorted(set(record) - set(REQUIRED_FIELDS))
    if extras:
        issues.append(_issue(loaded, "UNEXPECTED_FIELDS", f"Unexpected fields: {', '.join(extras)}"))

    if record.get("schema_version") != SCHEMA_VERSION:
        issues.append(_issue(loaded, "INVALID_SCHEMA_VERSION", f"schema_version must be {SCHEMA_VERSION}."))
    for identity_field in ("record_id", "event_id"):
        value = record.get(identity_field)
        if not isinstance(value, str) or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{2,127}", value) is None:
            issues.append(_issue(loaded, "INVALID_IDENTIFIER", f"{identity_field} has an invalid format."))
    if record.get("spatial_unit_type") != "BARANGAY":
        issues.append(_issue(loaded, "INVALID_SPATIAL_UNIT", "spatial_unit_type must be BARANGAY."))

    mapping_status = record.get("spatial_mapping_status")
    if mapping_status not in tuple(ALLOWED_MAPPING):
        issues.append(_issue(loaded, "INVALID_SPATIAL_MAPPING_STATUS", "spatial_mapping_status is not allowed."))
    psgc = record.get("barangay_psgc")
    name = record.get("barangay_name")
    if is_ambiguous_barangay_176(psgc, name):
        issues.append(_issue(loaded, "UNKNOWN_SPATIAL_MAPPING_BARANGAY_176", "Legacy Barangay 176 and Barangays 176-A through 176-F cannot be mapped with the validated 187-feature geometry."))
        if mapping_status != "UNKNOWN_SPATIAL_MAPPING":
            issues.append(_issue(loaded, "INVALID_176_MAPPING_STATUS", "An unresolved Barangay 176 reference must use UNKNOWN_SPATIAL_MAPPING."))
    elif mapping_status == "VALIDATED":
        if not isinstance(psgc, str) or psgc not in barangays:
            issues.append(_issue(loaded, "UNKNOWN_BARANGAY_IDENTIFIER", "barangay_psgc is not in the validated 187-barangay reference."))
        elif name != barangays[psgc]:
            issues.append(_issue(loaded, "BARANGAY_NAME_MISMATCH", f"barangay_name must exactly match {barangays[psgc]!r} for {psgc}."))
    if mapping_status == "UNKNOWN_SPATIAL_MAPPING" and mode == "reviewed":
        issues.append(_issue(loaded, "UNRESOLVED_SPATIAL_MAPPING", "Reviewed training data requires a validated spatial mapping."))

    timestamps: Dict[str, Optional[datetime]] = {}
    for field in ("forecast_issued_at", "valid_from", "valid_until", "source_retrieved_at", "reviewed_at"):
        value = record.get(field)
        if value is None and field in {"source_retrieved_at", "reviewed_at"}:
            timestamps[field] = None
            continue
        timestamps[field] = parse_iso_datetime(value)
        if timestamps[field] is None:
            issues.append(_issue(loaded, "INVALID_TIMESTAMP", f"{field} must be an ISO-8601 timestamp with a UTC offset."))
    issued = timestamps.get("forecast_issued_at")
    valid_from = timestamps.get("valid_from")
    valid_until = timestamps.get("valid_until")
    if issued and valid_from and valid_from < issued:
        issues.append(_issue(loaded, "FORECAST_AFTER_WINDOW_START", "forecast_issued_at must not be after valid_from."))
    if valid_from and valid_until:
        seconds = (valid_until.astimezone(timezone.utc) - valid_from.astimezone(timezone.utc)).total_seconds()
        if seconds != 24 * 60 * 60:
            issues.append(_issue(loaded, "INVALID_VALID_WINDOW", "valid_from to valid_until must be exactly 24 hours."))
        if valid_until <= valid_from:
            issues.append(_issue(loaded, "IMPOSSIBLE_DATE_ORDER", "valid_until must be after valid_from."))

    for field in RAIN_FIELDS:
        value = record.get(field)
        if value is None:
            if mode == "reviewed":
                issues.append(_issue(loaded, "MISSING_REQUIRED_MODEL_FEATURE", f"{field} is missing; values are never imputed."))
        elif not _is_number(value):
            issues.append(_issue(loaded, "INVALID_RAINFALL", f"{field} must be a finite number or null."))
        elif float(value) < 0:
            issues.append(_issue(loaded, "INVALID_RAINFALL_RANGE", f"{field} cannot be negative."))
    if mode == "reviewed":
        if record.get("rainfall_unit") != "mm":
            issues.append(_issue(loaded, "UNVERIFIED_RAINFALL_UNIT", "Reviewed records require explicitly verified millimetre units."))
        if record.get("rainfall_metadata_status") != "VERIFIED":
            issues.append(_issue(loaded, "UNVERIFIED_RAINFALL_SEMANTICS", "Rainfall units and accumulation semantics must be VERIFIED."))
    elif record.get("rainfall_unit") not in ("mm", None):
        issues.append(_issue(loaded, "INVALID_RAINFALL_UNIT", "rainfall_unit must be mm or null."))
    if record.get("rainfall_metadata_status") not in {"VERIFIED", "UNVERIFIED"}:
        issues.append(_issue(loaded, "INVALID_RAINFALL_METADATA_STATUS", "rainfall_metadata_status is invalid."))

    susceptibility = record.get("mgb_flood_susceptibility_code")
    if susceptibility is None:
        if mode == "reviewed":
            issues.append(_issue(loaded, "MISSING_REQUIRED_MODEL_FEATURE", "mgb_flood_susceptibility_code is missing."))
    elif susceptibility not in tuple(ALLOWED_SUSCEPTIBILITY):
        issues.append(_issue(loaded, "INVALID_SUSCEPTIBILITY", "Susceptibility must be LF, MF, HF, VHF, NONE, or null."))

    if valid_from:
        expected_sin, expected_cos = month_components(valid_from)
        for field, expected in (("month_sin", expected_sin), ("month_cos", expected_cos)):
            value = record.get(field)
            if value is not None and (not _is_number(value) or not math.isclose(float(value), expected, abs_tol=1e-9)):
                issues.append(_issue(loaded, "INVALID_DERIVED_MONTH", f"{field} does not match the valid_from month. Leave it null or provide the exact derived value."))

    label = record.get("label_status")
    outcome = record.get("flood_outcome")
    confidence = record.get("label_confidence")
    negative_basis = record.get("negative_evidence_basis")
    if label not in tuple(ALLOWED_LABELS):
        issues.append(_issue(loaded, "INVALID_LABEL_STATUS", "label_status is not allowed."))
    if outcome not in (0, 1, None) or isinstance(outcome, bool):
        issues.append(_issue(loaded, "INVALID_FLOOD_OUTCOME", "flood_outcome must be 0, 1, or null."))
    expected_outcome = {
        "VERIFIED_FLOOD_OBSERVED": 1,
        "VERIFIED_NO_FLOOD_OBSERVED": 0,
    }.get(label) if isinstance(label, str) else None
    if label in tuple(TRAINING_LABELS) and outcome != expected_outcome:
        issues.append(_issue(loaded, "LABEL_OUTCOME_MISMATCH", f"{label} requires flood_outcome={expected_outcome}."))
    if label not in tuple(TRAINING_LABELS) and outcome is not None:
        issues.append(_issue(loaded, "NONTRAINING_LABEL_HAS_OUTCOME", "UNKNOWN, AMBIGUOUS, and EXCLUDED require flood_outcome=null."))
    if confidence not in tuple(ALLOWED_CONFIDENCE):
        issues.append(_issue(loaded, "INVALID_LABEL_CONFIDENCE", "label_confidence is not allowed."))
    if label in tuple(TRAINING_LABELS) and confidence not in tuple(TRAINING_CONFIDENCE):
        issues.append(_issue(loaded, "INSUFFICIENT_LABEL_EVIDENCE", "A verified label requires HIGH or MEDIUM CIVENTRAL evidence confidence."))
    if label not in tuple(TRAINING_LABELS) and confidence in tuple(TRAINING_CONFIDENCE):
        issues.append(_issue(loaded, "UNVERIFIED_HIGH_CONFIDENCE_LABEL", "HIGH/MEDIUM confidence cannot promote an unverified label."))
    if negative_basis not in tuple(ALLOWED_NEGATIVE_BASIS):
        issues.append(_issue(loaded, "INVALID_NEGATIVE_EVIDENCE_BASIS", "negative_evidence_basis is not allowed."))
    if label == "VERIFIED_NO_FLOOD_OBSERVED" and negative_basis is None:
        issues.append(_issue(loaded, "MISSING_EXPLICIT_NEGATIVE_EVIDENCE", "A verified negative requires an explicit authoritative monitoring basis; no-report-found is prohibited."))
    if label != "VERIFIED_NO_FLOOD_OBSERVED" and negative_basis is not None:
        issues.append(_issue(loaded, "UNEXPECTED_NEGATIVE_EVIDENCE", "negative_evidence_basis is only valid for a verified negative."))
    if mode == "reviewed" and label not in tuple(TRAINING_LABELS):
        issues.append(_issue(loaded, "NONTRAINING_LABEL", "Reviewed training preparation accepts only VERIFIED_FLOOD_OBSERVED or VERIFIED_NO_FLOOD_OBSERVED."))
    if label == "VERIFIED_NO_FLOOD_OBSERVED":
        negative_text = " ".join(
            str(record.get(field) or "")
            for field in ("review_notes", "source_document_reference")
        ).lower()
        if any(phrase in negative_text for phrase in ("no report found", "no reports found", "absence of report", "no record found")):
            issues.append(_issue(loaded, "PROHIBITED_ABSENCE_AS_NEGATIVE", "Absence of a report or record cannot support VERIFIED_NO_FLOOD_OBSERVED."))

    if record.get("review_status") not in tuple(ALLOWED_REVIEW):
        issues.append(_issue(loaded, "INVALID_REVIEW_STATUS", "review_status is not allowed."))
    if record.get("leakage_review_status") not in tuple(ALLOWED_LEAKAGE):
        issues.append(_issue(loaded, "INVALID_LEAKAGE_REVIEW_STATUS", "leakage_review_status is not allowed."))
    if mode == "reviewed":
        if record.get("review_status") != "REVIEWED" or timestamps.get("reviewed_at") is None:
            issues.append(_issue(loaded, "LABEL_NOT_REVIEWED", "Training-eligible records require REVIEWED and a reviewed_at timestamp."))
        if not isinstance(record.get("review_notes"), str) or not record.get("review_notes", "").strip():
            issues.append(_issue(loaded, "MISSING_REVIEW_NOTES", "Reviewed records require non-empty review_notes."))
        if record.get("leakage_review_status") != "PASSED":
            issues.append(_issue(loaded, "LEAKAGE_REVIEW_NOT_PASSED", "Training-eligible records require leakage_review_status=PASSED."))
        if not isinstance(record.get("source_document_reference"), str) or not record.get("source_document_reference", "").strip():
            issues.append(_issue(loaded, "MISSING_SOURCE_DOCUMENT", "A reviewed label requires a source document reference."))
        if timestamps.get("source_retrieved_at") is None:
            issues.append(_issue(loaded, "MISSING_SOURCE_RETRIEVAL_TIME", "A reviewed label requires source_retrieved_at."))

    for source_field in SOURCE_FIELDS:
        source_id = record.get(source_field)
        required_for_reviewed = mode == "reviewed"
        if source_field == "flood_evidence_source" and label in TRAINING_LABELS:
            required_for_reviewed = True
        if source_id is None:
            if required_for_reviewed:
                issues.append(_issue(loaded, "MISSING_PROVENANCE_SOURCE", f"{source_field} is required."))
            continue
        if not isinstance(source_id, str) or source_id not in sources:
            issues.append(_issue(loaded, "UNKNOWN_PROVENANCE_SOURCE", f"{source_field} does not reference a manifest source_id."))
        elif mode == "reviewed" and sources[source_id].get("review_status") != APPROVED_SOURCE_STATUS:
            issues.append(_issue(loaded, "UNAPPROVED_PROVENANCE_SOURCE", f"{source_field} source {source_id!r} is not approved for governed use."))

    if mode == "reviewed":
        evidence_source_id = record.get("flood_evidence_source")
        evidence_provenance = sources.get(evidence_source_id, {}) if isinstance(evidence_source_id, str) else {}
        evidence_type = evidence_provenance.get("source_type")
        if label == "VERIFIED_FLOOD_OBSERVED" and evidence_type not in ("INCIDENT_REPORT", "MONITORING_LOG", "SATELLITE_DERIVED"):
            issues.append(_issue(loaded, "INVALID_POSITIVE_EVIDENCE_TYPE", "A verified flood requires an incident report, monitoring log, or quality-reviewed satellite-derived source."))
        if label == "VERIFIED_NO_FLOOD_OBSERVED" and evidence_type not in ("INCIDENT_REPORT", "MONITORING_LOG"):
            issues.append(_issue(loaded, "INVALID_NEGATIVE_EVIDENCE_TYPE", "A verified negative requires an explicit authoritative incident report or monitoring log."))
        weather_source_id = record.get("weather_source")
        weather_provenance = sources.get(weather_source_id, {}) if isinstance(weather_source_id, str) else {}
        if weather_provenance:
            if weather_provenance.get("source_type") not in ("FORECAST_ARCHIVE", "WEATHER_BUNDLE"):
                issues.append(_issue(loaded, "INVALID_WEATHER_SOURCE_TYPE", "Training weather provenance must be a governed forecast archive or weather bundle."))
            if weather_provenance.get("units") != REQUIRED_WEATHER_UNITS:
                issues.append(_issue(loaded, "WEATHER_UNITS_NOT_VERIFIED", "The weather source manifest must explicitly declare millimetres for all three rainfall fields."))
            if weather_provenance.get("accumulation_semantics") != REQUIRED_ACCUMULATION_SEMANTICS:
                issues.append(_issue(loaded, "WEATHER_ACCUMULATION_NOT_VERIFIED", "The weather source manifest must explicitly declare the forecast and pre-issuance accumulation windows."))
        susceptibility_source_id = record.get("susceptibility_source")
        susceptibility_provenance = sources.get(susceptibility_source_id, {}) if isinstance(susceptibility_source_id, str) else {}
        if susceptibility_provenance and susceptibility_provenance.get("source_type") != "GIS_COVARIATE":
            issues.append(_issue(loaded, "INVALID_SUSCEPTIBILITY_SOURCE_TYPE", "susceptibility_source must reference a GIS_COVARIATE manifest entry."))

    return issues


def validate_records(
    records: Sequence[LoadedRecord],
    sources: Mapping[str, Mapping[str, Any]],
    barangays: Mapping[str, str],
    mode: str = "reviewed",
) -> List[ValidationIssue]:
    issues: List[ValidationIssue] = []
    record_ids: Dict[str, LoadedRecord] = {}
    observation_keys: Dict[Tuple[Any, ...], LoadedRecord] = {}
    for loaded in records:
        issues.extend(validate_record(loaded, sources, barangays, mode=mode))
        record_id = loaded.data.get("record_id")
        if isinstance(record_id, str):
            if record_id in record_ids:
                issues.append(_issue(loaded, "DUPLICATE_RECORD_ID", f"record_id duplicates {record_ids[record_id].source_file}:{record_ids[record_id].row_number}."))
            else:
                record_ids[record_id] = loaded
        key = (
            loaded.data.get("spatial_unit_type"),
            loaded.data.get("barangay_psgc"),
            loaded.data.get("forecast_issued_at"),
            loaded.data.get("valid_from"),
            loaded.data.get("valid_until"),
        )
        if all(part is not None for part in key):
            if key in observation_keys:
                prior = observation_keys[key]
                issues.append(_issue(loaded, "DUPLICATE_OBSERVATION", f"Spatial unit/issuance/window duplicates {prior.source_file}:{prior.row_number}."))
            else:
                observation_keys[key] = loaded
    return issues


def training_eligible(record: Mapping[str, Any]) -> bool:
    return (
        record.get("label_status") in tuple(TRAINING_LABELS)
        and record.get("flood_outcome") in (0, 1)
        and not isinstance(record.get("flood_outcome"), bool)
        and record.get("label_confidence") in tuple(TRAINING_CONFIDENCE)
        and record.get("review_status") == "REVIEWED"
        and record.get("spatial_mapping_status") == "VALIDATED"
        and record.get("rainfall_metadata_status") == "VERIFIED"
        and record.get("leakage_review_status") == "PASSED"
    )


def summarize_issues(issues: Sequence[ValidationIssue]) -> Dict[str, int]:
    summary: Dict[str, int] = {}
    for issue in issues:
        summary[issue.code] = summary.get(issue.code, 0) + 1
    return dict(sorted(summary.items()))
