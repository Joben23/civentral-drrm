"""Fail-closed Phase 3B3-B evidence integrity and worksheet validation.

This module validates local evidence and governance metadata. It never
downloads source material, assigns a label, creates a training window, imports
TensorFlow, or mutates a raw evidence file.
"""

from __future__ import annotations

import hashlib
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any, List, Mapping, Sequence
from urllib.parse import urlsplit


REPO_ROOT = Path(__file__).resolve().parents[2]
RAW_EVIDENCE_ROOT = REPO_ROOT / "ml" / "flood-risk" / "data" / "raw" / "event-evidence"
WORKSHEET_FIELDS = {
    "schema_version", "phase", "worksheet_id", "event_id", "hazard_type",
    "discovery_sources", "acquisition_sources", "machine_validation",
    "report_time_evidence", "caloocan_evidence_status",
    "explicit_caloocan_facts", "occurrence_time_evidence", "location_evidence",
    "affected_population_evidence", "evacuation_displacement_evidence",
    "flood_passability_depth_evidence", "conflicting_incomplete_facts",
    "uncertainties", "human_review_questions",
    "positive_label_supported_by_acquired_dromic", "negative_evidence_basis",
    "label_status", "review_status", "reviewed_by", "human_approved",
    "training_eligible", "candidate_windows", "imerg_acquired_for_candidate",
}
WORKSHEET_OPTIONAL_FIELDS = {
    "phase_3b3c1_temporal_assessment",
    "phase_3b3c1b_temporal_assessment",
}
DOCX_MEDIA_TYPE = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"


@dataclass(frozen=True)
class EvidenceIssue:
    code: str
    subject_id: str
    message: str


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _is_official_dromic_url(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    parsed = urlsplit(value)
    return parsed.scheme == "https" and parsed.hostname == "dromic.dswd.gov.ph" and not parsed.username and not parsed.password


def validate_pdf_artifact(artifact: Mapping[str, Any], repo_root: Path = REPO_ROOT) -> List[EvidenceIssue]:
    """Validate immutable PDF identity without trusting its extension/name."""
    subject = str(artifact.get("artifact_id") or "unknown-artifact")
    issues: List[EvidenceIssue] = []
    relative = artifact.get("local_file")
    if not isinstance(relative, str):
        return [EvidenceIssue("MISSING_RAW_PATH", subject, "A repository-relative raw path is required.")]
    path = (repo_root / relative).resolve()
    raw_root = (repo_root / "ml" / "flood-risk" / "data" / "raw" / "event-evidence").resolve()
    try:
        path.relative_to(raw_root)
    except ValueError:
        return [EvidenceIssue("RAW_PATH_OUTSIDE_GOVERNED_DIRECTORY", subject, str(path))]
    if not path.is_file():
        return [EvidenceIssue("RAW_FILE_NOT_FOUND", subject, str(path))]
    data = path.read_bytes()
    head = data[:512].lstrip().lower()
    if head.startswith((b"<!doctype html", b"<html")) or b"<html" in head:
        issues.append(EvidenceIssue("HTML_RENAMED_AS_PDF", subject, "HTML/error content is not PDF evidence."))
    if not data.startswith(b"%PDF-"):
        issues.append(EvidenceIssue("INVALID_PDF_SIGNATURE", subject, "Raw bytes must begin with %PDF-."))
    if b"%%EOF" not in data[-4096:]:
        issues.append(EvidenceIssue("MISSING_PDF_EOF", subject, "A complete PDF EOF marker is required."))
    if artifact.get("media_type") != "application/pdf":
        issues.append(EvidenceIssue("INVALID_MEDIA_TYPE", subject, "media_type must be application/pdf."))
    if artifact.get("byte_length") != len(data):
        issues.append(EvidenceIssue("BYTE_LENGTH_MISMATCH", subject, "Recorded byte length does not match the raw file."))
    checksum = artifact.get("sha256")
    if not isinstance(checksum, str) or len(checksum) != 64:
        issues.append(EvidenceIssue("CHECKSUM_REQUIRED", subject, "A lowercase SHA-256 checksum is required."))
    elif checksum != sha256_file(path):
        issues.append(EvidenceIssue("CHECKSUM_MISMATCH", subject, "Recorded SHA-256 does not match the raw file."))
    if artifact.get("original_filename") != path.name:
        issues.append(EvidenceIssue("ORIGINAL_FILENAME_MISMATCH", subject, "The original filename must be preserved exactly."))
    title = artifact.get("title")
    if not isinstance(title, str) or not title.strip():
        issues.append(EvidenceIssue("INTERNAL_TITLE_REQUIRED", subject, "An internally verified report title is required."))
    if path.name.lower() == "index.pdf" and isinstance(title, str) and title.strip().lower() in {"index", "index.pdf"}:
        issues.append(EvidenceIssue("GENERIC_FILENAME_USED_AS_TITLE", subject, "index.pdf cannot replace the internal report title."))
    if not _is_official_dromic_url(artifact.get("official_url")):
        issues.append(EvidenceIssue("INVALID_OFFICIAL_DROMIC_LOCATOR", subject, "An official DROMIC HTTPS locator is required."))
    return issues


def validate_docx_artifact(artifact: Mapping[str, Any], repo_root: Path = REPO_ROOT) -> List[EvidenceIssue]:
    """Validate an immutable OOXML report without trusting its extension/name."""
    subject = str(artifact.get("artifact_id") or "unknown-artifact")
    relative = artifact.get("local_file")
    if not isinstance(relative, str):
        return [EvidenceIssue("MISSING_RAW_PATH", subject, "A repository-relative raw path is required.")]
    path = (repo_root / relative).resolve()
    raw_root = (repo_root / "ml" / "flood-risk" / "data" / "raw" / "event-evidence").resolve()
    try:
        path.relative_to(raw_root)
    except ValueError:
        return [EvidenceIssue("RAW_PATH_OUTSIDE_GOVERNED_DIRECTORY", subject, str(path))]
    if not path.is_file():
        return [EvidenceIssue("RAW_FILE_NOT_FOUND", subject, str(path))]

    issues: List[EvidenceIssue] = []
    data = path.read_bytes()
    head = data[:512].lstrip().lower()
    if head.startswith((b"<!doctype html", b"<html")) or b"<html" in head:
        issues.append(EvidenceIssue("HTML_RENAMED_AS_DOCX", subject, "HTML/error content is not DOCX evidence."))
    if not data.startswith(b"PK\x03\x04"):
        issues.append(EvidenceIssue("INVALID_DOCX_SIGNATURE", subject, "Raw bytes must begin with a ZIP local-file header."))
    try:
        with zipfile.ZipFile(path) as package:
            required_members = {"[Content_Types].xml", "_rels/.rels", "word/document.xml"}
            missing_members = sorted(required_members - set(package.namelist()))
            if missing_members:
                issues.append(EvidenceIssue("INVALID_DOCX_PACKAGE", subject, f"Missing OOXML members: {', '.join(missing_members)}"))
            corrupt_member = package.testzip()
            if corrupt_member is not None:
                issues.append(EvidenceIssue("CORRUPT_DOCX_PACKAGE", subject, corrupt_member))
            if "[Content_Types].xml" in package.namelist():
                content_types = package.read("[Content_Types].xml")
                required_type = b"application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"
                if required_type not in content_types:
                    issues.append(EvidenceIssue("INVALID_DOCX_CONTENT_TYPE", subject, "The package is not a WordprocessingML document."))
    except (OSError, RuntimeError, zipfile.BadZipFile):
        issues.append(EvidenceIssue("INVALID_DOCX_PACKAGE", subject, "The raw file is not a readable OOXML ZIP package."))

    if artifact.get("media_type") != DOCX_MEDIA_TYPE:
        issues.append(EvidenceIssue("INVALID_MEDIA_TYPE", subject, f"media_type must be {DOCX_MEDIA_TYPE}."))
    if artifact.get("byte_length") != len(data):
        issues.append(EvidenceIssue("BYTE_LENGTH_MISMATCH", subject, "Recorded byte length does not match the raw file."))
    checksum = artifact.get("sha256")
    if not isinstance(checksum, str) or len(checksum) != 64:
        issues.append(EvidenceIssue("CHECKSUM_REQUIRED", subject, "A lowercase SHA-256 checksum is required."))
    elif checksum != sha256_file(path):
        issues.append(EvidenceIssue("CHECKSUM_MISMATCH", subject, "Recorded SHA-256 does not match the raw file."))
    if artifact.get("original_filename") != path.name:
        issues.append(EvidenceIssue("ORIGINAL_FILENAME_MISMATCH", subject, "The original filename must be preserved exactly."))
    title = artifact.get("title")
    if not isinstance(title, str) or not title.strip():
        issues.append(EvidenceIssue("INTERNAL_TITLE_REQUIRED", subject, "An internally verified report title is required."))
    if not _is_official_dromic_url(artifact.get("official_url")):
        issues.append(EvidenceIssue("INVALID_OFFICIAL_DROMIC_LOCATOR", subject, "An official DROMIC HTTPS locator is required."))
    return issues


def validate_temporal_assessment(worksheet: Mapping[str, Any]) -> List[EvidenceIssue]:
    """Enforce the Phase 3B3-C1 boundary between timing evidence and ML cutoffs."""
    assessment = worksheet.get("phase_3b3c1_temporal_assessment")
    if assessment is None:
        return []
    subject = str(worksheet.get("worksheet_id") or "unknown-worksheet")
    issues: List[EvidenceIssue] = []
    if not isinstance(assessment, Mapping):
        return [EvidenceIssue("INVALID_TEMPORAL_ASSESSMENT", subject, "Temporal assessment must be an object.")]
    if assessment.get("phase") != "PHASE_3B3C1_ULYSSES_TEMPORAL_EVIDENCE":
        issues.append(EvidenceIssue("INVALID_TEMPORAL_ASSESSMENT_PHASE", subject, "Unexpected temporal assessment phase."))
    quality = assessment.get("temporal_quality")
    allowed_quality = {
        "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND",
        "DATE_ONLY_EVIDENCE",
        "TEMPORAL_EVIDENCE_INSUFFICIENT",
    }
    if quality not in allowed_quality:
        issues.append(EvidenceIssue("INVALID_TEMPORAL_QUALITY", subject, str(quality)))
    start = assessment.get("candidate_event_time_start")
    end = assessment.get("candidate_event_time_end")
    occurrence = worksheet.get("occurrence_time_evidence")
    explicit_occurrence = isinstance(occurrence, list) and any(
        isinstance(item, Mapping) and item.get("evidence_status") == "EXPLICITLY_STATED"
        for item in occurrence
    )
    if quality == "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND":
        if not isinstance(start, str) or not isinstance(end, str) or not explicit_occurrence:
            issues.append(EvidenceIssue("UNSUPPORTED_TEMPORAL_ENVELOPE", subject, "A bounded envelope requires explicit occurrence evidence and both endpoints."))
    elif start is not None or end is not None:
        issues.append(EvidenceIssue("UNSUPPORTED_TEMPORAL_ENVELOPE", subject, "Date-only or insufficient evidence cannot populate envelope endpoints."))
    if assessment.get("prediction_cutoff") is not None:
        issues.append(EvidenceIssue("PREDICTION_CUTOFF_CREATED", subject, "Phase 3B3-C1 cannot create a prediction cutoff."))
    if assessment.get("human_review_required") is not True:
        issues.append(EvidenceIssue("HUMAN_REVIEW_BYPASSED", subject, "Temporal evidence remains subject to human review."))
    if assessment.get("timezone_status") != "TIMEZONE_REQUIRES_HUMAN_REVIEW":
        issues.append(EvidenceIssue("TIMEZONE_UNCERTAINTY_NOT_PRESERVED", subject, "Unstated source timezone must remain unresolved."))
    if assessment.get("general_metro_manila_timing_is_caloocan_event_timing") is not False:
        issues.append(EvidenceIssue("GENERAL_TIMING_USED_FOR_CALOOCAN", subject, "General Metro Manila timing cannot establish Caloocan event timing."))
    if assessment.get("affected_population_establishes_onset") is not False:
        issues.append(EvidenceIssue("POPULATION_USED_AS_ONSET", subject, "Affected-population counts cannot establish onset."))
    return issues


def validate_enteng_temporal_assessment(worksheet: Mapping[str, Any]) -> List[EvidenceIssue]:
    """Keep indexed Enteng timing evidence separate from acquisition and ML state."""
    assessment = worksheet.get("phase_3b3c1b_temporal_assessment")
    if assessment is None:
        return []
    subject = str(worksheet.get("worksheet_id") or "unknown-worksheet")
    issues: List[EvidenceIssue] = []
    if not isinstance(assessment, Mapping):
        return [EvidenceIssue("INVALID_ENTENG_TEMPORAL_ASSESSMENT", subject, "Enteng temporal assessment must be an object.")]
    if assessment.get("phase") != "PHASE_3B3C1B_ENTENG_TEMPORAL_EVIDENCE":
        issues.append(EvidenceIssue("INVALID_ENTENG_TEMPORAL_PHASE", subject, "Unexpected Enteng temporal assessment phase."))
    quality = assessment.get("temporal_quality")
    allowed_quality = {
        "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND",
        "DATE_AND_OCCURRENCE_POINT_EVIDENCE_ONLY",
        "TEMPORAL_EVIDENCE_INSUFFICIENT",
    }
    if quality not in allowed_quality:
        issues.append(EvidenceIssue("INVALID_ENTENG_TEMPORAL_QUALITY", subject, str(quality)))
    start = assessment.get("candidate_event_time_start")
    end = assessment.get("candidate_event_time_end")
    occurrence = assessment.get("occurrence_evidence")
    subsidence = assessment.get("subsidence_evidence")
    if quality == "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND":
        if (
            not isinstance(start, str)
            or not start.strip()
            or not isinstance(end, str)
            or not end.strip()
            or not isinstance(occurrence, list)
            or not occurrence
            or not isinstance(subsidence, list)
            or not subsidence
        ):
            issues.append(EvidenceIssue("UNSUPPORTED_ENTENG_TEMPORAL_ENVELOPE", subject, "A bounded episode requires explicit occurrence and subsidence evidence plus both candidate endpoints."))
    elif start is not None or end is not None:
        issues.append(EvidenceIssue("UNSUPPORTED_ENTENG_TEMPORAL_ENVELOPE", subject, "Point-only or insufficient evidence cannot populate candidate envelope endpoints."))
    if (
        assessment.get("raw_artifact_access_status") != "EXTERNAL_ACCESS_REQUIRED"
        or assessment.get("acquired_artifact_refs") != []
        or assessment.get("indexed_official_content_is_raw_artifact") is not False
    ):
        issues.append(EvidenceIssue("INDEXED_EVIDENCE_PROMOTED_TO_ACQUIRED", subject, "Indexed NDRRMC content cannot mark a raw artifact acquired."))
    if assessment.get("report_issue_time_is_event_onset") is not False:
        issues.append(EvidenceIssue("REPORT_TIME_USED_AS_EVENT_ONSET", subject, "Report issue time cannot establish occurrence."))
    if assessment.get("subsided_time_is_event_onset") is not False:
        issues.append(EvidenceIssue("SUBSIDED_TIME_USED_AS_EVENT_ONSET", subject, "Subsided time cannot establish occurrence."))
    if assessment.get("separate_date_clusters_automatically_merged") is not False:
        issues.append(EvidenceIssue("SEPARATE_ENTENG_CLUSTERS_MERGED", subject, "Separate occurrence-date clusters require human review."))
    if assessment.get("timezone_status") != "TIMEZONE_REQUIRES_HUMAN_REVIEW":
        issues.append(EvidenceIssue("TIMEZONE_UNCERTAINTY_NOT_PRESERVED", subject, "Unstated incident-table timezone must remain unresolved."))
    if assessment.get("prediction_cutoff") is not None:
        issues.append(EvidenceIssue("PREDICTION_CUTOFF_CREATED", subject, "Phase 3B3-C1B cannot create a prediction cutoff."))
    if assessment.get("human_review_required") is not True:
        issues.append(EvidenceIssue("HUMAN_REVIEW_BYPASSED", subject, "Enteng temporal evidence remains subject to human review."))
    records = assessment.get("exact_caloocan_records")
    if not isinstance(records, list) or not records:
        issues.append(EvidenceIssue("MISSING_ENTENG_CALOOCAN_RECORDS", subject, "Official indexed Caloocan occurrence rows are required."))
    else:
        for record in records:
            if not isinstance(record, Mapping):
                issues.append(EvidenceIssue("INVALID_ENTENG_CALOOCAN_RECORD", subject, "Each Caloocan row must be an object."))
                continue
            if record.get("source_access_status") != "INDEXED_OFFICIAL_CONTENT_ONLY":
                issues.append(EvidenceIssue("INVALID_INDEXED_EVIDENCE_STATUS", subject, str(record.get("locality_source_text"))))
            locality = str(record.get("locality_source_text") or "")
            if locality and not locality[:1].isdigit() and record.get("barangay_source_text") is not None:
                issues.append(EvidenceIssue("UNSUPPORTED_BARANGAY_INFERENCE", subject, locality))
    return issues


def _source_map(manifest: Mapping[str, Any]) -> Mapping[str, Mapping[str, Any]]:
    return {str(item.get("source_id")): item for item in manifest.get("sources", []) if isinstance(item, Mapping)}


def _event_map(registry: Mapping[str, Any]) -> Mapping[str, Mapping[str, Any]]:
    return {str(item.get("event_id")): item for item in registry.get("events", []) if isinstance(item, Mapping)}


def validate_review_worksheet(
    worksheet: Mapping[str, Any], source_manifest: Mapping[str, Any], event_registry: Mapping[str, Any], *, repo_root: Path = REPO_ROOT
) -> List[EvidenceIssue]:
    subject = str(worksheet.get("worksheet_id") or "unknown-worksheet")
    issues: List[EvidenceIssue] = []
    missing = sorted(WORKSHEET_FIELDS - set(worksheet))
    extras = sorted(set(worksheet) - WORKSHEET_FIELDS - WORKSHEET_OPTIONAL_FIELDS)
    if missing:
        issues.append(EvidenceIssue("MISSING_WORKSHEET_FIELDS", subject, ", ".join(missing)))
    if extras:
        issues.append(EvidenceIssue("UNEXPECTED_WORKSHEET_FIELDS", subject, ", ".join(extras)))
    expected = {
        "schema_version": "1.0.0", "phase": "PHASE_3B3B_EVIDENCE_ACQUISITION",
        "hazard_type": "FLOOD", "positive_label_supported_by_acquired_dromic": False,
        "negative_evidence_basis": None, "label_status": "UNKNOWN",
        "review_status": "REQUIRES_HUMAN_REVIEW", "reviewed_by": None,
        "human_approved": False, "training_eligible": False,
        "candidate_windows": [], "imerg_acquired_for_candidate": False,
    }
    for field, value in expected.items():
        if worksheet.get(field) != value:
            issues.append(EvidenceIssue("UNSAFE_WORKSHEET_STATE", subject, f"{field} must remain {value!r}."))
    validation = worksheet.get("machine_validation")
    if not isinstance(validation, Mapping) or validation.get("human_review_performed") is not False or validation.get("validated_at_utc") is None:
        issues.append(EvidenceIssue("INVALID_MACHINE_VALIDATION", subject, "Machine validation needs a UTC timestamp and cannot claim human review."))
    sources = _source_map(source_manifest)
    event = _event_map(event_registry).get(str(worksheet.get("event_id")))
    if event is None:
        issues.append(EvidenceIssue("UNKNOWN_WORKSHEET_EVENT", subject, "Worksheet event is not registered."))
        event_source_ids: set[str] = set()
    else:
        event_source_ids = set(event.get("source_ids", []))
        if event.get("label_status") != "UNKNOWN" or event.get("review_status") != "REQUIRES_HUMAN_REVIEW" or event.get("training_eligible") is not False or event.get("candidate_windows") != []:
            issues.append(EvidenceIssue("UNSAFE_EVENT_REGISTRY_STATE", subject, "The linked event must remain unknown, unreviewed, ineligible, and window-free."))
    discovery = worksheet.get("discovery_sources")
    if not isinstance(discovery, list) or not discovery:
        issues.append(EvidenceIssue("MISSING_DISCOVERY_SOURCE", subject, "At least one discovery source is required."))
    else:
        for ref in discovery:
            source_id = ref.get("source_id") if isinstance(ref, Mapping) else None
            source = sources.get(str(source_id))
            if source is None or source.get("status") != "PROPOSED" or source.get("availability_status") != "NOT_ACQUIRED" or source.get("local_file") is not None or source.get("sha256") is not None:
                issues.append(EvidenceIssue("DISCOVERY_SOURCE_NOT_FAIL_CLOSED", subject, str(source_id)))
            if not isinstance(ref, Mapping) or ref.get("access_status") != "EXTERNAL_ACCESS_REQUIRED":
                issues.append(EvidenceIssue("DISCOVERY_ACCESS_STATUS_INVALID", subject, str(source_id)))
            if source_id not in event_source_ids:
                issues.append(EvidenceIssue("DISCOVERY_SOURCE_NOT_LINKED_TO_EVENT", subject, str(source_id)))
    acquired = worksheet.get("acquisition_sources")
    if not isinstance(acquired, list) or not acquired:
        issues.append(EvidenceIssue("MISSING_ACQUISITION_SOURCE", subject, "At least one acquired DROMIC source is required."))
    else:
        for ref in acquired:
            source_id = ref.get("source_id") if isinstance(ref, Mapping) else None
            source = sources.get(str(source_id))
            if source is None or source.get("status") != "ACQUIRED" or source.get("availability_status") != "ACQUIRED" or source.get("review_status") != "REQUIRES_HUMAN_REVIEW" or source.get("license_or_usage_status") != "PENDING_REVIEW":
                issues.append(EvidenceIssue("ACQUISITION_SOURCE_NOT_FAIL_CLOSED", subject, str(source_id)))
                continue
            if source_id not in event_source_ids:
                issues.append(EvidenceIssue("ACQUISITION_SOURCE_NOT_LINKED_TO_EVENT", subject, str(source_id)))
            artifacts = {item.get("artifact_id"): item for item in source.get("artifacts", []) if isinstance(item, Mapping)}
            refs = ref.get("artifact_refs") if isinstance(ref, Mapping) else None
            if not isinstance(refs, list) or not refs:
                issues.append(EvidenceIssue("MISSING_ACQUISITION_ARTIFACT_REFS", subject, str(source_id)))
                continue
            for artifact_ref in refs:
                artifact = artifacts.get(artifact_ref)
                if artifact is None:
                    issues.append(EvidenceIssue("UNKNOWN_ACQUISITION_ARTIFACT", subject, str(artifact_ref)))
                else:
                    media_type = artifact.get("media_type")
                    if media_type == "application/pdf":
                        issues.extend(validate_pdf_artifact(artifact, repo_root))
                    elif media_type == DOCX_MEDIA_TYPE:
                        issues.extend(validate_docx_artifact(artifact, repo_root))
                    else:
                        issues.append(EvidenceIssue("UNSUPPORTED_REPORT_MEDIA_TYPE", subject, str(media_type)))
    for report_time in worksheet.get("report_time_evidence", []):
        if not isinstance(report_time, Mapping) or report_time.get("issue_time_is_event_onset") is not False:
            issues.append(EvidenceIssue("REPORT_TIME_USED_AS_EVENT_ONSET", subject, "Report issue time cannot establish event onset."))
    issues.extend(validate_temporal_assessment(worksheet))
    issues.extend(validate_enteng_temporal_assessment(worksheet))
    return issues


def validate_all_worksheets(
    worksheets: Sequence[Mapping[str, Any]], source_manifest: Mapping[str, Any], event_registry: Mapping[str, Any], *, repo_root: Path = REPO_ROOT
) -> List[EvidenceIssue]:
    issues: List[EvidenceIssue] = []
    seen: set[str] = set()
    for worksheet in worksheets:
        worksheet_id = str(worksheet.get("worksheet_id"))
        if worksheet_id in seen:
            issues.append(EvidenceIssue("DUPLICATE_WORKSHEET_ID", worksheet_id, "worksheet_id must be unique."))
        seen.add(worksheet_id)
        issues.extend(validate_review_worksheet(worksheet, source_manifest, event_registry, repo_root=repo_root))
    return issues
