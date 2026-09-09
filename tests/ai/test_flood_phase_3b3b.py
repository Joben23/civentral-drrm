"""Focused Phase 3B3-B evidence acquisition/governance tests."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
from pathlib import Path

from pypdf import PdfReader


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
MANIFESTS = WORKSPACE / "manifests"
sys.path.insert(0, str(REPO_ROOT / "scripts" / "ai"))

from flood_data_common import validate_manifest  # noqa: E402
from flood_evidence_common import (  # noqa: E402
    sha256_file,
    validate_all_worksheets,
    validate_pdf_artifact,
)


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


SOURCE_MANIFEST = read_json(MANIFESTS / "source-manifest.json")
EVENT_REGISTRY = read_json(MANIFESTS / "flood-event-registry.json")
CANDIDATES = read_json(MANIFESTS / "candidate-events.json")
AUTHORIZATION = read_json(MANIFESTS / "training-authorization.json")
WORKSHEETS = [read_json(path) for path in sorted(MANIFESTS.glob("phase-3b3b-*-review.json"))]
SOURCES = {source["source_id"]: source for source in SOURCE_MANIFEST["sources"]}
EVENTS = {event["event_id"]: event for event in EVENT_REGISTRY["events"]}

DISCOVERY_IDS = {
    "ndrrmc_swm_gener_haikui_sitreps_2012",
    "ndrrmc_ulysses_sitreps_2020",
    "ndrrmc_florita_sitreps_2022",
    "ndrrmc_enteng_sitreps_2024",
}
ACQUISITION_IDS = {
    "dswd_dromic_florita_2022_08_25",
    "dswd_dromic_enteng_2024_reports_1_3",
    "dswd_dromic_ulysses_terminal_2021_11_13",
}


def acquired_artifacts():
    for source_id in sorted(ACQUISITION_IDS):
        yield from SOURCES[source_id]["artifacts"]


def pdf_text(artifact):
    reader = PdfReader(str(REPO_ROOT / artifact["local_file"]), strict=True)
    return "\n".join(page.extract_text() or "" for page in reader.pages)


def issue_codes(issues):
    return {issue.code for issue in issues}


def test_all_five_real_pdf_artifacts_pass_integrity_validation():
    artifacts = list(acquired_artifacts())
    assert len(artifacts) == 5
    for artifact in artifacts:
        assert validate_pdf_artifact(artifact) == []
        reader = PdfReader(str(REPO_ROOT / artifact["local_file"]), strict=True)
        assert not reader.is_encrypted
        assert len(reader.pages) > 0


def test_html_renamed_as_pdf_is_rejected(tmp_path):
    relative = Path("ml/flood-risk/data/raw/event-evidence/error.pdf")
    path = tmp_path / relative
    path.parent.mkdir(parents=True)
    path.write_bytes(b"<!doctype html><html><title>403</title></html>")
    artifact = {
        "artifact_id": "TEST_ONLY-html-error", "local_file": relative.as_posix(),
        "media_type": "application/pdf", "byte_length": path.stat().st_size,
        "sha256": sha256_file(path), "original_filename": path.name,
        "title": "TEST_ONLY error", "official_url": "https://dromic.dswd.gov.ph/error.pdf",
    }
    codes = issue_codes(validate_pdf_artifact(artifact, tmp_path))
    assert "HTML_RENAMED_AS_PDF" in codes
    assert "INVALID_PDF_SIGNATURE" in codes


def test_checksum_is_required_and_mismatch_fails():
    artifact = copy.deepcopy(next(acquired_artifacts()))
    artifact["sha256"] = None
    assert "CHECKSUM_REQUIRED" in issue_codes(validate_pdf_artifact(artifact))
    artifact["sha256"] = "0" * 64
    assert "CHECKSUM_MISMATCH" in issue_codes(validate_pdf_artifact(artifact))


def test_generic_index_filename_does_not_replace_internal_title():
    source = SOURCES["dswd_dromic_ulysses_terminal_2021_11_13"]
    artifact = source["artifacts"][0]
    assert artifact["original_filename"] == "index.pdf"
    assert "Terminal Report on Typhoon" in artifact["title"]
    assert "ULYSSES" in pdf_text(artifact).upper().splitlines()[0]


def test_dromic_acquisition_does_not_erase_ndrrmc_discovery_sources():
    for worksheet in WORKSHEETS:
        discovery = {ref["source_id"] for ref in worksheet["discovery_sources"]}
        acquired = {ref["source_id"] for ref in worksheet["acquisition_sources"]}
        event_sources = set(EVENTS[worksheet["event_id"]]["source_ids"])
        assert discovery <= DISCOVERY_IDS
        assert acquired <= ACQUISITION_IDS
        assert discovery | acquired <= event_sources


def test_stale_ndrrmc_sources_and_links_remain_unacquired():
    for source_id in DISCOVERY_IDS:
        source = SOURCES[source_id]
        assert source["availability_status"] == "NOT_ACQUIRED"
        assert source["status"] == "PROPOSED"
        assert source["local_file"] is None
        assert source["sha256"] is None
    selected = [candidate for candidate in CANDIDATES["candidates"] if candidate["phase_3b3b_acquisition_selected"]]
    for candidate in selected:
        assert candidate["document_access_status"] == "EXTERNAL_ACCESS_REQUIRED"
        assert all(link["access_status"] == "EXTERNAL_ACCESS_REQUIRED" for link in candidate["official_document_links"])


def test_florita_weather_context_cannot_become_caloocan_positive():
    worksheet = read_json(MANIFESTS / "phase-3b3b-florita-2022-review.json")
    source = SOURCES["dswd_dromic_florita_2022_08_25"]
    assert "caloocan" not in pdf_text(source["artifacts"][0]).lower()
    assert worksheet["caloocan_evidence_status"] == "NO_CALOOCAN_REFERENCE_IN_ACQUIRED_DROMIC"
    assert worksheet["explicit_caloocan_facts"] == []
    assert worksheet["positive_label_supported_by_acquired_dromic"] is False


def test_enteng_reports_do_not_turn_general_forecasts_into_event_evidence():
    worksheet = read_json(MANIFESTS / "phase-3b3b-enteng-2024-review.json")
    source = SOURCES["dswd_dromic_enteng_2024_reports_1_3"]
    assert all("caloocan" not in pdf_text(artifact).lower() for artifact in source["artifacts"])
    assert worksheet["caloocan_evidence_status"] == "NO_CALOOCAN_REFERENCE_IN_ACQUIRED_DROMIC"
    assert worksheet["occurrence_time_evidence"] == []
    assert worksheet["positive_label_supported_by_acquired_dromic"] is False


def test_ulysses_population_data_does_not_create_onset_or_flood_depth():
    worksheet = read_json(MANIFESTS / "phase-3b3b-ulysses-2020-review.json")
    event = EVENTS[worksheet["event_id"]]
    assert worksheet["caloocan_evidence_status"] == "EXPLICIT_AFFECTED_POPULATION_ONLY"
    assert "243 affected families" in worksheet["affected_population_evidence"][0]["statement"]
    assert event["event_start"] is None and event["event_end"] is None
    assert worksheet["occurrence_time_evidence"] == []
    assert worksheet["flood_passability_depth_evidence"] == []


def test_report_issue_times_never_become_event_onset():
    for worksheet in WORKSHEETS:
        assert worksheet["report_time_evidence"]
        assert all(item["issue_time_is_event_onset"] is False for item in worksheet["report_time_evidence"])


def test_acquisition_does_not_confirm_labels_or_create_negatives():
    for worksheet in WORKSHEETS:
        assert worksheet["label_status"] == "UNKNOWN"
        assert worksheet["negative_evidence_basis"] is None
        assert worksheet["review_status"] == "REQUIRES_HUMAN_REVIEW"
        assert worksheet["reviewed_by"] is None
        assert worksheet["human_approved"] is False
    assert not any(event["label_status"] == "NO_FLOOD_CONFIRMED" for event in EVENT_REGISTRY["events"])


def test_training_eligibility_and_windows_remain_fail_closed():
    assert AUTHORIZATION["status"] == "NOT_APPROVED"
    for worksheet in WORKSHEETS:
        assert worksheet["training_eligible"] is False
        assert worksheet["candidate_windows"] == []
    for event in EVENT_REGISTRY["events"]:
        assert event["training_eligible"] is False
        assert event["candidate_windows"] == []


def test_no_candidate_imerg_acquisition_was_added():
    assert all(worksheet["imerg_acquired_for_candidate"] is False for worksheet in WORKSHEETS)
    for event in (EVENTS[worksheet["event_id"]] for worksheet in WORKSHEETS):
        assert not any("imerg" in source_id.lower() for source_id in event["source_ids"])


def test_acquired_sources_are_not_approved_or_validated_for_pilot():
    for source_id in ACQUISITION_IDS:
        source = SOURCES[source_id]
        assert source["status"] == "ACQUIRED"
        assert source["availability_status"] == "ACQUIRED"
        assert source["review_status"] == "REQUIRES_HUMAN_REVIEW"
        assert source["license_or_usage_status"] == "PENDING_REVIEW"


def test_worksheets_and_source_manifest_pass_fail_closed_validators():
    assert validate_manifest(SOURCE_MANIFEST) == []
    assert validate_all_worksheets(WORKSHEETS, SOURCE_MANIFEST, EVENT_REGISTRY) == []


def test_all_flood_risk_json_files_parse():
    files = list(WORKSPACE.rglob("*.json"))
    assert files
    for path in files:
        json.loads(path.read_text(encoding="utf-8"))


def test_raw_evidence_files_remain_gitignored():
    for artifact in acquired_artifacts():
        result = subprocess.run(
            ["git", "check-ignore", "-q", "--", artifact["local_file"]],
            cwd=REPO_ROOT, check=False,
        )
        assert result.returncode == 0


def test_no_civentral_model_artifact_exists():
    excluded = str(WORKSPACE / "service" / ".venv").lower()
    artifacts = []
    for path in WORKSPACE.rglob("*"):
        if path.is_file() and excluded not in str(path).lower():
            if path.name in {"model.keras", "saved_model.pb"} or path.suffix.lower() in {".h5", ".tflite"}:
                artifacts.append(path)
    assert artifacts == []
