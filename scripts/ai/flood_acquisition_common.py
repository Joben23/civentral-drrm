"""Fail-closed Phase 3B2 evidence and IMERG acquisition utilities.

No function downloads data, assigns a label, trains TensorFlow, or changes
model readiness.
"""

from __future__ import annotations

import hashlib
import json
import math
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any, Dict, List, Mapping, Sequence, Tuple
from urllib.parse import parse_qsl, urlsplit

from flood_data_common import REPO_ROOT, read_json, validate_manifest


WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DEFAULT_SOURCE_MANIFEST = WORKSPACE / "manifests" / "source-manifest.json"
DEFAULT_EVIDENCE_EXTRACTION = WORKSPACE / "manifests" / "dromic-caloocan-2019-evidence-extraction.json"
DEFAULT_IMERG_ACQUISITION = WORKSPACE / "manifests" / "imerg-caloocan-2019-exploratory-acquisition.json"
DEFAULT_CITY_BOUNDARY = REPO_ROOT / "data" / "import" / "caloocan-city-boundary.geojson"
DEFAULT_BARANGAYS = REPO_ROOT / "data" / "import" / "caloocan-barangays-current-unaffected.geojson"
DEFAULT_REVIEWED_PRECIPITATION = WORKSPACE / "data" / "reviewed" / "precipitation" / "GPM_3IMERGHH_V07B_20190623T160000Z_20190624T160000Z_Caloocan-neighborhood.json"

SCHEMA_VERSION = "1.0.0"
DROMIC_SOURCE_ID = "dswd_dromic_caloocan_flood_2019-06-24"
IMERG_SOURCE_ID = "nasa_gpm_imerg_final_hh_v07"
EXTRACTION_STATUS = "CANDIDATE_MACHINE_EXTRACTION_NOT_HUMAN_REVIEWED"
EXPLORATORY_WINDOW = "EXPLORATORY_SOURCE_ACQUISITION_WINDOW"
EARTHDATA_AUTH_REQUIRED = "EARTHDATA_AUTHENTICATION_REQUIRED"
CITY_BOUNDARY_SHA256 = "9647f3cac1758a07cfdc6a5bb8767fe9e4f1eb70b4e7d2c14a99abf2de1f9d50"
IMERG_SHORT_NAME = "GPM_3IMERGHH"
IMERG_COLLECTION_VERSION = "07"
IMERG_PROCESSING_ID = "V07B"
IMERG_DOI = "10.5067/GPM/IMERG/3B-HH/07"
IMERG_VARIABLE = "precipitation"
IMERG_NATIVE_UNITS = "mm/hr"
IMERG_RESOLUTION = Decimal("0.1")
IMERG_INTERVAL_MINUTES = 30
NASA_HOSTS = {"cmr.earthdata.nasa.gov", "data.gesdisc.earthdata.nasa.gov", "disc.gsfc.nasa.gov", "gis.earthdata.nasa.gov", "gpm.nasa.gov"}
SENSITIVE_PARTS = ("token", "password", "secret", "credential", "api_key", "apikey")
EVIDENCE_STATUSES = {"EXPLICITLY_STATED", "DERIVED_FROM_DOCUMENT_STRUCTURE", "UNKNOWN"}


@dataclass(frozen=True)
class AcquisitionIssue:
    code: str
    subject_id: str
    message: str


class AcquisitionValidationError(ValueError):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def _is_number(value: Any) -> bool:
    return isinstance(value, (int, float, Decimal)) and not isinstance(value, bool) and math.isfinite(float(value))


def _utc_timestamp(value: Any, field: str) -> datetime:
    if not isinstance(value, str):
        raise AcquisitionValidationError("INVALID_UTC_TIMESTAMP", f"{field} must be ISO-8601 UTC.")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise AcquisitionValidationError("INVALID_UTC_TIMESTAMP", f"{field} is invalid.") from exc
    if parsed.tzinfo is None or parsed.utcoffset() != timedelta(0):
        raise AcquisitionValidationError("NON_UTC_TIMESTAMP", f"{field} must explicitly use UTC.")
    return parsed.astimezone(timezone.utc)


def _iso_z(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def _contains_sensitive_key(value: Any) -> bool:
    if isinstance(value, Mapping):
        for key, nested in value.items():
            normalized = str(key).lower().replace("-", "_")
            if any(part in normalized for part in SENSITIVE_PARTS) or _contains_sensitive_key(nested):
                return True
    return isinstance(value, list) and any(_contains_sensitive_key(item) for item in value)


def _safe_nasa_url(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    parsed = urlsplit(value)
    if parsed.scheme != "https" or parsed.hostname not in NASA_HOSTS or parsed.username or parsed.password:
        return False
    return not any(any(part in key.lower().replace("-", "_") for part in SENSITIVE_PARTS) for key, _ in parse_qsl(parsed.query, keep_blank_values=True))


def _artifact_map(source: Mapping[str, Any]) -> Dict[str, Mapping[str, Any]]:
    artifacts = source.get("artifacts")
    return {str(item["artifact_id"]): item for item in artifacts if isinstance(item, Mapping) and item.get("artifact_id")} if isinstance(artifacts, list) else {}


def validate_evidence_extraction(extraction: Mapping[str, Any], source: Mapping[str, Any]) -> List[AcquisitionIssue]:
    issues: List[AcquisitionIssue] = []
    subject = str(extraction.get("extraction_id") or "evidence-extraction")
    required = {"schema_version", "extraction_id", "event_id", "extraction_status", "primary_source_id", "source_documents", "supported_event_date", "supported_event_start", "supported_event_end", "event_time_status", "report_issue_times_are_event_onset", "supported_location_scope", "supported_barangays", "source_evidence", "uncertainties", "recommended_review_questions", "secondary_corroboration", "label_status", "review_status", "reviewed_by", "human_approved", "training_eligible"}
    if required - set(extraction):
        issues.append(AcquisitionIssue("MISSING_EVIDENCE_EXTRACTION_FIELDS", subject, ", ".join(sorted(required - set(extraction)))))
    if set(extraction) - required:
        issues.append(AcquisitionIssue("UNEXPECTED_EVIDENCE_EXTRACTION_FIELDS", subject, ", ".join(sorted(set(extraction) - required))))
    if _contains_sensitive_key(extraction):
        issues.append(AcquisitionIssue("SENSITIVE_ACQUISITION_FIELD", subject, "Credential-like fields are prohibited."))
    expected = {"schema_version": SCHEMA_VERSION, "extraction_status": EXTRACTION_STATUS, "primary_source_id": DROMIC_SOURCE_ID, "event_time_status": "UNRESOLVED", "supported_event_start": None, "supported_event_end": None, "report_issue_times_are_event_onset": False, "label_status": "UNKNOWN", "review_status": "REQUIRES_HUMAN_REVIEW", "reviewed_by": None, "human_approved": False, "training_eligible": False}
    for field, value in expected.items():
        if extraction.get(field) != value:
            issues.append(AcquisitionIssue("UNSAFE_EVIDENCE_REVIEW_STATE", subject, f"{field} must remain {value!r}."))
    if source.get("source_id") != DROMIC_SOURCE_ID or source.get("source_role") != "FLOOD_EVENT_EVIDENCE":
        issues.append(AcquisitionIssue("INVALID_PRIMARY_EVIDENCE_SOURCE", subject, "The governed DROMIC source is required."))
    report_paths = {str(item.get("local_file")) for item in _artifact_map(source).values() if item.get("artifact_role") == "REPORT_DOCUMENT" and item.get("status") == "ACQUIRED_VALIDATED"}
    documents = extraction.get("source_documents")
    if not isinstance(documents, list) or len(documents) < 2:
        issues.append(AcquisitionIssue("MISSING_DROMIC_SOURCE_DOCUMENTS", subject, "Both official reports are required."))
    else:
        for document in documents:
            reference = document.get("source_document_ref") if isinstance(document, Mapping) else None
            if reference not in report_paths:
                issues.append(AcquisitionIssue("UNVERIFIED_DROMIC_SOURCE_DOCUMENT", subject, str(reference)))
            if not isinstance(document, Mapping) or document.get("issue_time_is_event_onset") is not False:
                issues.append(AcquisitionIssue("REPORT_ISSUE_TIME_USED_AS_ONSET", subject, "Issue time cannot become onset."))
    event_date = extraction.get("supported_event_date")
    if not isinstance(event_date, Mapping) or event_date.get("value") != "2019-06-24" or event_date.get("evidence_status") != "EXPLICITLY_STATED":
        issues.append(AcquisitionIssue("UNSUPPORTED_EVENT_DATE", subject, "Record the explicit event date without inventing a time."))
    if extraction.get("supported_location_scope") != "MULTI_BARANGAY":
        issues.append(AcquisitionIssue("INVALID_SUPPORTED_LOCATION_SCOPE", subject, "Reports support a multi-barangay scope."))
    barangays = extraction.get("supported_barangays")
    actual = {item.get("name") for item in barangays if isinstance(item, Mapping)} if isinstance(barangays, list) else set()
    if actual != {"Barangay 175", "Barangay 176", "Barangay 177", "Barangay 178"}:
        issues.append(AcquisitionIssue("INCOMPLETE_SUPPORTED_BARANGAYS", subject, "Terminal report names Barangays 175-178."))
    for item in extraction.get("source_evidence", []):
        if not isinstance(item, Mapping) or item.get("evidence_status") not in EVIDENCE_STATUSES:
            issues.append(AcquisitionIssue("INVALID_EVIDENCE_STATUS", subject, "Every fact requires an evidence status."))
    secondary = extraction.get("secondary_corroboration")
    if not isinstance(secondary, list):
        issues.append(AcquisitionIssue("INVALID_SECONDARY_CORROBORATION", subject, "Secondary corroboration must be an array."))
    elif secondary and (extraction.get("label_status") != "UNKNOWN" or extraction.get("human_approved") is not False):
        issues.append(AcquisitionIssue("SECONDARY_EVIDENCE_AUTO_CONFIRMED_LABEL", subject, "Secondary evidence cannot confirm the label."))
    if not extraction.get("uncertainties"):
        issues.append(AcquisitionIssue("MISSING_EVENT_UNCERTAINTIES", subject, "Uncertainties are required."))
    if not extraction.get("recommended_review_questions"):
        issues.append(AcquisitionIssue("MISSING_HUMAN_REVIEW_QUESTIONS", subject, "Review questions are required."))
    return issues


def validate_imerg_acquisition(acquisition: Mapping[str, Any], source: Mapping[str, Any]) -> List[AcquisitionIssue]:
    issues: List[AcquisitionIssue] = []
    subject = str(acquisition.get("acquisition_id") or "imerg-acquisition")
    required = {"schema_version", "acquisition_id", "source_id", "status", "acquisition_type", "product", "access", "acquisition_window", "spatial_request", "artifact_ids", "normalization", "validated_coverage", "training_effect"}
    optional = {"authorization"}
    if required - set(acquisition):
        issues.append(AcquisitionIssue("MISSING_IMERG_ACQUISITION_FIELDS", subject, ", ".join(sorted(required - set(acquisition)))))
    if set(acquisition) - required - optional:
        issues.append(AcquisitionIssue("UNEXPECTED_IMERG_ACQUISITION_FIELDS", subject, ", ".join(sorted(set(acquisition) - required - optional))))
    if _contains_sensitive_key(acquisition):
        issues.append(AcquisitionIssue("SENSITIVE_ACQUISITION_FIELD", subject, "Credential-like fields are prohibited."))
    expected_identity = {"schema_version": SCHEMA_VERSION, "source_id": IMERG_SOURCE_ID, "status": "ACQUIRED_TECHNICALLY_VALIDATED_REVIEW_PENDING", "acquisition_type": EXPLORATORY_WINDOW}
    for field, value in expected_identity.items():
        if acquisition.get(field) != value:
            issues.append(AcquisitionIssue("INVALID_IMERG_ACQUISITION_IDENTITY", subject, f"{field} must be {value!r}."))
    product = acquisition.get("product") if isinstance(acquisition.get("product"), Mapping) else {}
    expected_product = {"short_name": IMERG_SHORT_NAME, "collection_version": IMERG_COLLECTION_VERSION, "processing_id": IMERG_PROCESSING_ID, "doi": IMERG_DOI, "run": "FINAL_RUN_RESEARCH_PRODUCT", "spatial_resolution_degrees": 0.1, "temporal_resolution_minutes": 30, "variable_name": IMERG_VARIABLE, "native_units": IMERG_NATIVE_UNITS, "time_semantics": "UTC_INTERVAL_START"}
    for field, value in expected_product.items():
        if product.get(field) != value:
            issues.append(AcquisitionIssue("INVALID_IMERG_PRODUCT_METADATA", subject, f"product.{field} must be {value!r}."))
    try:
        if _utc_timestamp(product.get("historical_coverage_start"), "historical_coverage_start") > datetime(2019, 6, 24, tzinfo=timezone.utc):
            issues.append(AcquisitionIssue("IMERG_JUNE_2019_NOT_COVERED", subject, "Coverage must include June 2019."))
    except AcquisitionValidationError as exc:
        issues.append(AcquisitionIssue(exc.code, subject, str(exc)))
    access = acquisition.get("access") if isinstance(acquisition.get("access"), Mapping) else {}
    if access.get("original_granule_access") != EARTHDATA_AUTH_REQUIRED or access.get("authentication_material_present") is not False:
        issues.append(AcquisitionIssue("UNSAFE_EARTHDATA_AUTH_STATE", subject, "Earthdata authentication must remain explicit and credential-free."))
    if access.get("original_granule_downloaded") is not False or access.get("representation") != "OFFICIAL_EARTHDATA_IMAGESERVER_SAMPLES":
        issues.append(AcquisitionIssue("MISREPRESENTED_IMERG_ACQUISITION", subject, "Public samples are not original HDF5 granules."))
    if not _safe_nasa_url(access.get("public_subset_service")):
        issues.append(AcquisitionIssue("UNSUPPORTED_NASA_ENDPOINT", subject, "Only an allowlisted NASA HTTPS endpoint is permitted."))
    window = acquisition.get("acquisition_window") if isinstance(acquisition.get("acquisition_window"), Mapping) else {}
    if window.get("classification") != EXPLORATORY_WINDOW:
        issues.append(AcquisitionIssue("ACQUISITION_WINDOW_IS_NOT_EXPLORATORY", subject, "Window must be exploratory."))
    for flag in ("target_window", "prediction_window", "training_window"):
        if window.get(flag) is not False:
            issues.append(AcquisitionIssue("ACQUISITION_WINDOW_MISCLASSIFIED", subject, f"{flag} must be false."))
    legacy_timezone = window.get("source_timezone") == "Asia/Manila UTC+08:00"
    enteng_timezone = (
        window.get("source_timezone") == "UNSPECIFIED"
        and window.get("research_alignment_timezone") == "Asia/Manila"
        and window.get("timezone_basis") == "PROJECT_RESEARCH_ASSUMPTION"
    )
    if window.get("timezone_assumption_status") != "REQUIRES_HUMAN_REVIEW" or not (legacy_timezone or enteng_timezone) or not window.get("conversion_method"):
        issues.append(AcquisitionIssue("TIMEZONE_CONVERSION_NOT_EXPLICIT", subject, "Local-date conversion and review status must be explicit."))
    try:
        start = _utc_timestamp(window.get("start_utc"), "start_utc")
        end = _utc_timestamp(window.get("end_utc_exclusive"), "end_utc_exclusive")
        if end <= start or end - start > timedelta(hours=96):
            issues.append(AcquisitionIssue("UNBOUNDED_ACQUISITION_WINDOW", subject, "Window must be positive and at most 96 hours."))
    except AcquisitionValidationError as exc:
        issues.append(AcquisitionIssue(exc.code, subject, str(exc)))
    spatial = acquisition.get("spatial_request") if isinstance(acquisition.get("spatial_request"), Mapping) else {}
    bounds = spatial.get("requested_bounds_wgs84")
    if not isinstance(bounds, list) or len(bounds) != 4 or not all(_is_number(value) for value in bounds):
        issues.append(AcquisitionIssue("INVALID_SPATIAL_BOUNDS", subject, "Four numeric WGS84 bounds are required."))
    elif not (120.7 <= float(bounds[0]) < float(bounds[2]) <= 121.3 and 14.4 <= float(bounds[1]) < float(bounds[3]) <= 15.0):
        issues.append(AcquisitionIssue("UNBOUNDED_SPATIAL_REQUEST", subject, "Bounds must remain tight around Caloocan."))
    if spatial.get("source_resolution_degrees") != 0.1 or spatial.get("mapping_method_selected") is not False:
        issues.append(AcquisitionIssue("INVALID_SPATIAL_MAPPING_STATE", subject, "Retain native resolution and leave mapping unselected."))
    if spatial.get("coarse_resolution_limitation_retained") is not True or not spatial.get("limitation_note"):
        issues.append(AcquisitionIssue("MISSING_COARSE_SPATIAL_LIMITATION", subject, "The coarse-grid limitation is required."))
    artifact_ids = acquisition.get("artifact_ids")
    artifacts = _artifact_map(source)
    if not isinstance(artifact_ids, list) or not artifact_ids:
        issues.append(AcquisitionIssue("MISSING_IMERG_ARTIFACTS", subject, "Artifact IDs are required."))
    else:
        for artifact_id in artifact_ids:
            artifact = artifacts.get(str(artifact_id))
            if artifact is None:
                issues.append(AcquisitionIssue("UNKNOWN_IMERG_ARTIFACT", subject, str(artifact_id)))
            elif not _safe_nasa_url(artifact.get("official_url")):
                issues.append(AcquisitionIssue("UNSUPPORTED_NASA_ENDPOINT", subject, str(artifact_id)))
    normalization = acquisition.get("normalization") if isinstance(acquisition.get("normalization"), Mapping) else {}
    if normalization.get("source_variable_verified") is not True or normalization.get("source_units_verified") is not True:
        issues.append(AcquisitionIssue("UNVERIFIED_IMERG_UNITS", subject, "Variable and units must be verified first."))
    if normalization.get("formula") != "precipitation_mm = native_rate_mm_per_hour * 0.5 hours" or normalization.get("interval_hours") != 0.5:
        issues.append(AcquisitionIssue("INVALID_IMERG_NORMALIZATION", subject, "The half-hour formula is not governed."))
    if normalization.get("missing_fill_policy") != "REJECT" or normalization.get("quality_status") != "PROVISIONAL_NO_QUALITY_INDEX":
        issues.append(AcquisitionIssue("INVALID_IMERG_QUALITY_POLICY", subject, "Missing/fill values must be rejected and quality limits retained."))
    if normalization.get("observed_forecast_separation") is not True:
        issues.append(AcquisitionIssue("OBSERVED_FORECAST_SEPARATION_DISABLED", subject, "Observed samples cannot be substituted for archived as-issued forecasts."))
    training = acquisition.get("training_effect") if isinstance(acquisition.get("training_effect"), Mapping) else {}
    for field in ("creates_training_row", "sets_event_label", "sets_training_ready", "authorizes_training"):
        if training.get(field) is not False:
            issues.append(AcquisitionIssue("ACQUISITION_CHANGED_TRAINING_STATE", subject, f"{field} must remain false."))
    if source.get("source_id") != IMERG_SOURCE_ID or source.get("status") not in {"ACQUIRED", "VALIDATED"} or source.get("availability_status") != "ACQUIRED":
        issues.append(AcquisitionIssue("IMERG_SOURCE_NOT_ACQUIRED", subject, "Registry must record acquired IMERG data."))
    if source.get("review_status") == "APPROVED_FOR_GOVERNED_USE" or source.get("status") == "APPROVED_FOR_PILOT":
        issues.append(AcquisitionIssue("IMERG_SOURCE_PREMATURELY_APPROVED", subject, "Acquisition cannot approve pilot training."))
    return issues


def _decimal_sample(value: Any) -> Decimal:
    if value is None or isinstance(value, bool):
        raise AcquisitionValidationError("MISSING_OR_FILL_PRECIPITATION", "Missing/fill precipitation is rejected.")
    text = str(value).strip()
    if not text or text.lower() in {"nan", "null", "none", "nodata"}:
        raise AcquisitionValidationError("MISSING_OR_FILL_PRECIPITATION", "Missing/fill precipitation is rejected.")
    try:
        parsed = Decimal(text)
    except InvalidOperation as exc:
        raise AcquisitionValidationError("MISSING_OR_FILL_PRECIPITATION", "Precipitation is not numeric.") from exc
    if not parsed.is_finite() or parsed < 0:
        raise AcquisitionValidationError("MISSING_OR_FILL_PRECIPITATION", "Invalid precipitation is rejected, never replaced with zero.")
    return parsed


def _grid_cell_id(longitude: Decimal, latitude: Decimal) -> str:
    return f"GPM_3IMERGHH_V07B:lat={latitude:.2f}:lon={longitude:.2f}"


def normalize_imerg_samples(
    acquisition: Mapping[str, Any], source: Mapping[str, Any], *, repo_root: Path = REPO_ROOT
) -> Dict[str, Any]:
    """Normalize verified ImageServer samples without modifying raw artifacts."""
    manifest_issues = validate_manifest({"manifest_version": "1.1.0", "sources": [dict(source)]}, repo_root=repo_root)
    if manifest_issues:
        raise AcquisitionValidationError("INVALID_SOURCE_PROVENANCE", manifest_issues[0].message)
    acquisition_issues = validate_imerg_acquisition(acquisition, source)
    if acquisition_issues:
        raise AcquisitionValidationError(acquisition_issues[0].code, acquisition_issues[0].message)

    artifacts = _artifact_map(source)
    sample_artifacts = [
        artifacts[str(artifact_id)] for artifact_id in acquisition["artifact_ids"]
        if str(artifact_id) in artifacts
        and artifacts[str(artifact_id)].get("artifact_role") == "IMAGESERVER_SAMPLES"
        and artifacts[str(artifact_id)].get("status") == "ACQUIRED_VALIDATED"
    ]
    if not sample_artifacts:
        raise AcquisitionValidationError("MISSING_IMERG_SAMPLES", "No validated ImageServer sample artifact is available.")

    requested = acquisition["spatial_request"].get("requested_grid_centers")
    if not isinstance(requested, list) or not requested:
        raise AcquisitionValidationError("MISSING_REQUESTED_GRID_CENTERS", "Explicit requested grid centers are required.")
    expected_cells = {
        _grid_cell_id(Decimal(str(item["longitude"])), Decimal(str(item["latitude"])))
        for item in requested if isinstance(item, Mapping)
    }
    if len(expected_cells) != len(requested):
        raise AcquisitionValidationError("DUPLICATE_REQUESTED_GRID_CELL", "Requested grid centers must be unique.")

    records: List[Dict[str, Any]] = []
    seen: set[Tuple[str, str]] = set()
    for artifact in sample_artifacts:
        raw_path = repo_root / str(artifact["local_file"])
        payload = read_json(raw_path)
        samples = payload.get("samples") if isinstance(payload, Mapping) else None
        if not isinstance(samples, list):
            raise AcquisitionValidationError("INVALID_IMERG_SAMPLE_FILE", f"Missing samples array: {artifact['artifact_id']}")
        retrieved_at = _iso_z(_utc_timestamp(artifact["retrieved_at"], "artifact.retrieved_at"))
        for index, sample in enumerate(samples):
            if not isinstance(sample, Mapping):
                raise AcquisitionValidationError("INVALID_IMERG_SAMPLE", f"Sample {index} is not an object.")
            location = sample.get("location") if isinstance(sample.get("location"), Mapping) else {}
            attributes = sample.get("attributes") if isinstance(sample.get("attributes"), Mapping) else {}
            longitude = Decimal(str(location.get("x"))).quantize(Decimal("0.01"))
            latitude = Decimal(str(location.get("y"))).quantize(Decimal("0.01"))
            if not (-180 <= longitude <= 180 and -90 <= latitude <= 90):
                raise AcquisitionValidationError("INVALID_IMERG_COORDINATES", f"Sample {index} coordinates are invalid.")
            cell_id = _grid_cell_id(longitude, latitude)
            if cell_id not in expected_cells:
                raise AcquisitionValidationError("UNREQUESTED_IMERG_GRID_CELL", cell_id)
            if attributes.get("variable") != IMERG_VARIABLE:
                raise AcquisitionValidationError("INVALID_IMERG_VARIABLE", f"Sample {index} variable is not precipitation.")
            if not _is_number(sample.get("resolution")) or not math.isclose(float(sample["resolution"]), 0.1, abs_tol=1e-9):
                raise AcquisitionValidationError("INVALID_IMERG_RESOLUTION", f"Sample {index} resolution is not 0.1 degree.")
            if not _is_number(attributes.get("stdtime")):
                raise AcquisitionValidationError("INVALID_IMERG_TIMESTAMP", f"Sample {index} has no StdTime.")
            observed_start = datetime.fromtimestamp(float(attributes["stdtime"]) / 1000.0, tz=timezone.utc)
            observed_end = observed_start + timedelta(minutes=IMERG_INTERVAL_MINUTES)
            native_rate = _decimal_sample(sample.get("value"))
            precipitation_mm = native_rate * Decimal("0.5")
            start_text = _iso_z(observed_start)
            key = (cell_id, start_text)
            if key in seen:
                raise AcquisitionValidationError("DUPLICATE_IMERG_INTERVAL", f"Duplicate {cell_id} at {start_text}.")
            seen.add(key)
            records.append({
                "observation_id": f"{cell_id}:{observed_start.strftime('%Y%m%dT%H%M%SZ')}",
                "data_type": "OBSERVED_PRECIPITATION",
                "source_id": IMERG_SOURCE_ID,
                "product_version": IMERG_PROCESSING_ID,
                "observed_at_start": start_text,
                "observed_at_end": _iso_z(observed_end),
                "latitude": float(latitude),
                "longitude": float(longitude),
                "grid_cell_id": cell_id,
                "native_value": float(native_rate),
                "native_units": IMERG_NATIVE_UNITS,
                "precipitation_mm": float(precipitation_mm),
                "normalization_method": "RATE_TO_INTERVAL_ACCUMULATION",
                "quality_flag": "PROVISIONAL",
                "retrieved_at": retrieved_at,
                "raw_file_reference": str(artifact["local_file"]),
                "raw_file_checksum": str(artifact["sha256"]),
                "source_resolution": str(source["spatial_resolution"]),
            })
    records.sort(key=lambda item: (item["observed_at_start"], item["grid_cell_id"]))
    return {"schema_version": SCHEMA_VERSION, "dataset_classification": "REAL_SOURCE_DATA", "records": records}


def precipitation_coverage_report(
    precipitation: Mapping[str, Any], acquisition: Mapping[str, Any]
) -> Dict[str, Any]:
    records = precipitation.get("records")
    if not isinstance(records, list):
        raise AcquisitionValidationError("INVALID_PRECIPITATION_DATASET", "records must be an array.")
    start = _utc_timestamp(acquisition["acquisition_window"]["start_utc"], "start_utc")
    end = _utc_timestamp(acquisition["acquisition_window"]["end_utc_exclusive"], "end_utc_exclusive")
    expected_times: List[str] = []
    cursor = start
    while cursor < end:
        expected_times.append(_iso_z(cursor))
        cursor += timedelta(minutes=IMERG_INTERVAL_MINUTES)
    expected_cells = {
        _grid_cell_id(Decimal(str(item["longitude"])), Decimal(str(item["latitude"])))
        for item in acquisition["spatial_request"]["requested_grid_centers"]
    }
    counts = Counter((str(row.get("grid_cell_id")), str(row.get("observed_at_start"))) for row in records)
    duplicate_count = sum(count - 1 for count in counts.values() if count > 1)
    expected_pairs = {(cell, timestamp) for cell in expected_cells for timestamp in expected_times}
    actual_pairs = set(counts)
    missing_pairs = sorted(expected_pairs - actual_pairs)
    unexpected_pairs = sorted(actual_pairs - expected_pairs)
    invalid_count = 0
    values: List[float] = []
    totals: Dict[str, float] = defaultdict(float)
    for row in records:
        value = row.get("precipitation_mm")
        if not _is_number(value) or float(value) < 0:
            invalid_count += 1
            continue
        values.append(float(value))
        totals[str(row.get("grid_cell_id"))] += float(value)
    timestamps = sorted({str(row.get("observed_at_start")) for row in records})
    cells = sorted({str(row.get("grid_cell_id")) for row in records})
    return {
        "first_timestamp_utc": timestamps[0] if timestamps else None,
        "last_interval_start_utc": timestamps[-1] if timestamps else None,
        "last_interval_end_utc": max((str(row.get("observed_at_end")) for row in records), default=None),
        "expected_interval_count_per_cell": len(expected_times),
        "actual_unique_interval_count": len(timestamps),
        "expected_observation_count": len(expected_pairs),
        "actual_observation_count": len(records),
        "valid_observation_count": len(values),
        "missing_interval_count": len(missing_pairs),
        "duplicate_interval_count": duplicate_count,
        "unexpected_interval_count": len(unexpected_pairs),
        "fill_or_invalid_value_count": invalid_count,
        "minimum_interval_precipitation_mm": min(values) if values else None,
        "maximum_interval_precipitation_mm": max(values) if values else None,
        "spatial_cell_count": len(cells),
        "spatial_cells": cells,
        "total_precipitation_mm_by_cell": {key: round(totals[key], 9) for key in sorted(totals)},
        "complete": not missing_pairs and not duplicate_count and not unexpected_pairs and not invalid_count and len(cells) == len(expected_cells),
    }


def write_json_atomic(path: Path, payload: Mapping[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        raise FileExistsError(f"Refusing to overwrite immutable output: {path}")
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    temporary.replace(path)


def _point_in_ring(point: Tuple[float, float], ring: Sequence[Sequence[float]]) -> bool:
    x, y = point
    inside = False
    for index in range(len(ring) - 1):
        x1, y1 = float(ring[index][0]), float(ring[index][1])
        x2, y2 = float(ring[index + 1][0]), float(ring[index + 1][1])
        cross = (x - x1) * (y2 - y1) - (y - y1) * (x2 - x1)
        if abs(cross) < 1e-12 and min(x1, x2) - 1e-12 <= x <= max(x1, x2) + 1e-12 and min(y1, y2) - 1e-12 <= y <= max(y1, y2) + 1e-12:
            return True
        if (y1 > y) != (y2 > y):
            boundary_x = (x2 - x1) * (y - y1) / (y2 - y1) + x1
            if x < boundary_x:
                inside = not inside
    return inside


def _point_in_polygon(point: Tuple[float, float], polygon: Sequence[Sequence[Sequence[float]]]) -> bool:
    return bool(polygon) and _point_in_ring(point, polygon[0]) and not any(_point_in_ring(point, hole) for hole in polygon[1:])


def _segments_intersect(a: Tuple[float, float], b: Tuple[float, float], c: Tuple[float, float], d: Tuple[float, float]) -> bool:
    def orientation(p: Tuple[float, float], q: Tuple[float, float], r: Tuple[float, float]) -> float:
        return (q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0])

    def on_segment(p: Tuple[float, float], q: Tuple[float, float], r: Tuple[float, float]) -> bool:
        return abs(orientation(p, q, r)) < 1e-12 and min(p[0], q[0]) - 1e-12 <= r[0] <= max(p[0], q[0]) + 1e-12 and min(p[1], q[1]) - 1e-12 <= r[1] <= max(p[1], q[1]) + 1e-12

    values = (orientation(a, b, c), orientation(a, b, d), orientation(c, d, a), orientation(c, d, b))
    if values[0] * values[1] < 0 and values[2] * values[3] < 0:
        return True
    return any((abs(values[i]) < 1e-12 and on_segment(*(pair))) for i, pair in enumerate(((a, b, c), (a, b, d), (c, d, a), (c, d, b))))


def _polygon_intersects_rect(polygon: Sequence[Sequence[Sequence[float]]], bounds: Tuple[float, float, float, float]) -> bool:
    west, south, east, north = bounds
    corners = ((west, south), (west, north), (east, north), (east, south))
    if any(_point_in_polygon(corner, polygon) for corner in corners):
        return True
    rectangle_edges = tuple(zip(corners, corners[1:] + corners[:1]))
    for ring in polygon:
        for coordinate in ring:
            if west <= float(coordinate[0]) <= east and south <= float(coordinate[1]) <= north:
                return True
        for index in range(len(ring) - 1):
            start = (float(ring[index][0]), float(ring[index][1]))
            end = (float(ring[index + 1][0]), float(ring[index + 1][1]))
            if any(_segments_intersect(start, end, edge_start, edge_end) for edge_start, edge_end in rectangle_edges):
                return True
    return False


def _polygons(geometry: Mapping[str, Any]) -> List[Sequence[Sequence[Sequence[float]]]]:
    if geometry.get("type") == "Polygon":
        return [geometry.get("coordinates", [])]
    if geometry.get("type") == "MultiPolygon":
        return list(geometry.get("coordinates", []))
    return []


def _haversine_m(point_a: Tuple[float, float], point_b: Tuple[float, float]) -> float:
    lon1, lat1 = map(math.radians, point_a)
    lon2, lat2 = map(math.radians, point_b)
    delta_lon, delta_lat = lon2 - lon1, lat2 - lat1
    value = math.sin(delta_lat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(delta_lon / 2) ** 2
    return 6371008.8 * 2 * math.asin(math.sqrt(value))


def _representative_point(geometry: Mapping[str, Any]) -> Tuple[float, float]:
    polygons = _polygons(geometry)
    if not polygons or not polygons[0] or not polygons[0][0]:
        raise AcquisitionValidationError("INVALID_REPRESENTATIVE_GEOMETRY", "No polygon coordinates are available.")
    ring = polygons[0][0]
    west = min(float(item[0]) for item in ring)
    east = max(float(item[0]) for item in ring)
    south = min(float(item[1]) for item in ring)
    north = max(float(item[1]) for item in ring)
    candidate = ((west + east) / 2, (south + north) / 2)
    if _point_in_polygon(candidate, polygons[0]):
        return candidate
    return (float(ring[0][0]), float(ring[0][1]))


def analyze_caloocan_imerg_grid(
    acquisition: Mapping[str, Any], *, city_boundary_path: Path = DEFAULT_CITY_BOUNDARY,
    barangay_path: Path = DEFAULT_BARANGAYS,
) -> Dict[str, Any]:
    boundary_bytes = city_boundary_path.read_bytes()
    if hashlib.sha256(boundary_bytes).hexdigest() != CITY_BOUNDARY_SHA256:
        raise AcquisitionValidationError("CITY_BOUNDARY_CHECKSUM_MISMATCH", "Caloocan boundary changed; spatial analysis requires review.")
    boundary = json.loads(boundary_bytes.decode("utf-8-sig"))
    geometries = [feature.get("geometry", {}) for feature in boundary.get("features", [])]
    requested = acquisition["spatial_request"]["requested_grid_centers"]
    cells: List[Dict[str, Any]] = []
    centers: List[Tuple[float, float, str]] = []
    half = float(IMERG_RESOLUTION / 2)
    for item in requested:
        longitude, latitude = float(item["longitude"]), float(item["latitude"])
        cell_id = _grid_cell_id(Decimal(str(longitude)), Decimal(str(latitude)))
        bounds = (longitude - half, latitude - half, longitude + half, latitude + half)
        intersects = any(_polygon_intersects_rect(polygon, bounds) for geometry in geometries for polygon in _polygons(geometry))
        cells.append({"grid_cell_id": cell_id, "center": {"longitude": longitude, "latitude": latitude}, "bounds_wgs84": list(bounds), "intersects_caloocan": intersects})
        centers.append((longitude, latitude, cell_id))

    barangays = read_json(barangay_path)
    representatives: List[Dict[str, Any]] = []
    for feature in barangays.get("features", []):
        properties = feature.get("properties", {})
        name = str(properties.get("current_barangay_name") or properties.get("adm4_name") or properties.get("barangay_name") or properties.get("name") or properties.get("barangay") or "")
        normalized = name.lower().replace("brgy.", "barangay").replace("brgy", "barangay").strip()
        if normalized not in {"barangay 175", "barangay 177", "barangay 178"}:
            continue
        longitude, latitude = _representative_point(feature.get("geometry", {}))
        containing = [cell for cell in cells if cell["bounds_wgs84"][0] <= longitude < cell["bounds_wgs84"][2] and cell["bounds_wgs84"][1] <= latitude < cell["bounds_wgs84"][3]]
        nearest = min(centers, key=lambda value: _haversine_m((longitude, latitude), (value[0], value[1])))
        representatives.append({
            "source": "DERIVED_FROM_GOVERNED_BARANGAY_GEOMETRY",
            "barangay_name": name,
            "longitude": round(longitude, 9),
            "latitude": round(latitude, 9),
            "containing_grid_cell_id": containing[0]["grid_cell_id"] if containing else None,
            "nearest_grid_cell_id": nearest[2],
            "nearest_center_distance_m": round(_haversine_m((longitude, latitude), (nearest[0], nearest[1])), 3),
        })
    return {
        "analysis_type": "NON_TRAINING_SPATIAL_MAPPING_ANALYSIS",
        "city_boundary": city_boundary_path.relative_to(REPO_ROOT).as_posix(),
        "city_boundary_sha256": CITY_BOUNDARY_SHA256,
        "source_resolution_degrees": 0.1,
        "requested_cell_count": len(cells),
        "caloocan_intersecting_cell_count": sum(1 for cell in cells if cell["intersects_caloocan"]),
        "cells": cells,
        "representative_locations": sorted(representatives, key=lambda item: item["barangay_name"]),
        "area_weighting": {
            "technically_possible": True,
            "performed": False,
            "reason": "Governed Caloocan polygons and explicit IMERG cell footprints exist, but geodesic intersection weighting requires a separately reviewed mapping implementation.",
        },
        "mapping_method_selected": False,
        "coarse_resolution_limitation": "IMERG 0.1-degree cells span multiple local areas and are not barangay-resolution ground truth.",
    }
