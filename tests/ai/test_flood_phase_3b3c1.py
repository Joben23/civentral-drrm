"""Focused Phase 3B3-C1 Ulysses temporal-evidence governance tests."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
MANIFESTS = WORKSPACE / "manifests"
sys.path.insert(0, str(REPO_ROOT / "scripts" / "ai"))

from flood_data_common import validate_manifest  # noqa: E402
from flood_evidence_common import (  # noqa: E402
    DOCX_MEDIA_TYPE,
    sha256_file,
    validate_all_worksheets,
    validate_docx_artifact,
    validate_temporal_assessment,
)


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


SOURCE_MANIFEST = read_json(MANIFESTS / "source-manifest.json")
EVENT_REGISTRY = read_json(MANIFESTS / "flood-event-registry.json")
AUTHORIZATION = read_json(MANIFESTS / "training-authorization.json")
WORKSHEET = read_json(MANIFESTS / "phase-3b3b-ulysses-2020-review.json")
WORKSHEETS = [read_json(path) for path in sorted(MANIFESTS.glob("phase-3b3b-*-review.json"))]
SOURCES = {source["source_id"]: source for source in SOURCE_MANIFEST["sources"]}
EVENTS = {event["event_id"]: event for event in EVENT_REGISTRY["events"]}
EARLY_SOURCE = SOURCES["dswd_dromic_ulysses_reports_3_4_2020_11_12"]
ASSESSMENT = WORKSHEET["phase_3b3c1_temporal_assessment"]


def issue_codes(issues):
    return {issue.code for issue in issues}


def docx_text(artifact):
    path = REPO_ROOT / artifact["local_file"]
    with zipfile.ZipFile(path) as package:
        root = ElementTree.fromstring(package.read("word/document.xml"))
    namespace = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
    return "\n".join(
        "".join(node.text or "" for node in paragraph.iter(f"{namespace}t"))
        for paragraph in root.iter(f"{namespace}p")
    )


def test_two_real_dromic_docx_artifacts_pass_integrity_validation():
    artifacts = EARLY_SOURCE["artifacts"]
    assert len(artifacts) == 2
    assert all(artifact["media_type"] == DOCX_MEDIA_TYPE for artifact in artifacts)
    assert all(validate_docx_artifact(artifact) == [] for artifact in artifacts)
    assert "Caloocan City" not in docx_text(artifacts[0])
    assert "Caloocan City" in docx_text(artifacts[1])


def test_html_error_renamed_as_docx_is_rejected(tmp_path):
    relative = Path("ml/flood-risk/data/raw/event-evidence/error.docx")
    path = tmp_path / relative
    path.parent.mkdir(parents=True)
    path.write_bytes(b"<!doctype html><html><title>403</title></html>")
    artifact = {
        "artifact_id": "TEST_ONLY-html-error", "local_file": relative.as_posix(),
        "media_type": DOCX_MEDIA_TYPE, "byte_length": path.stat().st_size,
        "sha256": sha256_file(path), "original_filename": path.name,
        "title": "TEST_ONLY error", "official_url": "https://dromic.dswd.gov.ph/error.docx",
    }
    codes = issue_codes(validate_docx_artifact(artifact, tmp_path))
    assert "HTML_RENAMED_AS_DOCX" in codes
    assert "INVALID_DOCX_SIGNATURE" in codes
    assert "INVALID_DOCX_PACKAGE" in codes


def test_acquired_docx_requires_checksum_and_matching_bytes():
    artifact = copy.deepcopy(EARLY_SOURCE["artifacts"][0])
    artifact["sha256"] = None
    assert "CHECKSUM_REQUIRED" in issue_codes(validate_docx_artifact(artifact))
    artifact["sha256"] = "0" * 64
    assert "CHECKSUM_MISMATCH" in issue_codes(validate_docx_artifact(artifact))


def test_report_issue_time_never_becomes_event_onset():
    assert all(item["issue_time_is_event_onset"] is False for item in WORKSHEET["report_time_evidence"])
    event = EVENTS[WORKSHEET["event_id"]]
    assert event["event_start"] is None and event["event_end"] is None


def test_general_metro_manila_timing_is_not_caloocan_event_timing():
    assert ASSESSMENT["general_metro_manila_timing_is_caloocan_event_timing"] is False
    weather = [item for item in ASSESSMENT["source_evidence"] if item["evidence_type"] == "WEATHER_BULLETIN_TIME"]
    assert weather and all(item["caloocan_specific"] is False for item in weather)


def test_affected_population_alone_cannot_establish_onset():
    assert ASSESSMENT["affected_population_establishes_onset"] is False
    assert WORKSHEET["affected_population_evidence"]
    assert ASSESSMENT["candidate_event_time_start"] is None


def test_timezone_uncertainty_is_preserved():
    assert ASSESSMENT["timezone_status"] == "TIMEZONE_REQUIRES_HUMAN_REVIEW"
    assert all(item["issue_timezone"] is None for item in WORKSHEET["report_time_evidence"])


def test_temporal_envelope_requires_explicit_occurrence_evidence():
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe_assessment = unsafe["phase_3b3c1_temporal_assessment"]
    unsafe_assessment["temporal_quality"] = "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND"
    unsafe_assessment["candidate_event_time_start"] = "12 November 2020"
    unsafe_assessment["candidate_event_time_end"] = "12 November 2020"
    assert "UNSUPPORTED_TEMPORAL_ENVELOPE" in issue_codes(validate_temporal_assessment(unsafe))


def test_date_only_assessment_does_not_create_label_or_prediction_cutoff():
    assert ASSESSMENT["temporal_quality"] == "DATE_ONLY_EVIDENCE"
    assert ASSESSMENT["prediction_cutoff"] is None
    assert WORKSHEET["label_status"] == "UNKNOWN"
    assert WORKSHEET["review_status"] == "REQUIRES_HUMAN_REVIEW"
    assert WORKSHEET["human_approved"] is False
    assert WORKSHEET["training_eligible"] is False
    assert WORKSHEET["candidate_windows"] == []


def test_ndrrmc_sources_remain_external_and_no_imerg_was_acquired():
    ndrrmc = SOURCES["ndrrmc_ulysses_sitreps_2020"]
    assert ndrrmc["status"] == "PROPOSED"
    assert ndrrmc["availability_status"] == "NOT_ACQUIRED"
    assert ndrrmc["local_file"] is None and ndrrmc["sha256"] is None
    assert WORKSHEET["imerg_acquired_for_candidate"] is False
    assert not any("imerg" in source_id.lower() for source_id in EVENTS[WORKSHEET["event_id"]]["source_ids"])


def test_training_readiness_and_authorization_remain_blocked():
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "scripts" / "ai" / "check_flood_training_readiness.py")],
        cwd=REPO_ROOT, check=False, capture_output=True, text=True,
    )
    assert result.returncode == 1
    report = json.loads(result.stdout)
    assert report["training_ready"] is False
    assert AUTHORIZATION["status"] == "NOT_APPROVED"


def test_source_manifest_and_all_worksheets_pass_fail_closed_validation():
    assert validate_manifest(SOURCE_MANIFEST) == []
    assert validate_all_worksheets(WORKSHEETS, SOURCE_MANIFEST, EVENT_REGISTRY) == []


def test_raw_docx_files_are_gitignored():
    for artifact in EARLY_SOURCE["artifacts"]:
        result = subprocess.run(["git", "check-ignore", "-q", "--", artifact["local_file"]], cwd=REPO_ROOT, check=False)
        assert result.returncode == 0


def test_no_flood_training_rows_or_active_flood_model_artifact_exists():
    pilot_files = [
        path for path in (WORKSPACE / "data" / "processed" / "pilot").rglob("*")
        if path.is_file() and path.name not in {"README.md", ".gitignore"}
    ]
    assert pilot_files == []
    excluded = str(WORKSPACE / "service" / ".venv").lower()
    model_files = [
        path for path in WORKSPACE.rglob("*")
        if path.is_file() and excluded not in str(path).lower()
        and (path.name in {"model.keras", "saved_model.pb"} or path.suffix.lower() in {".h5", ".tflite"})
    ]
    expected = WORKSPACE / "artifacts" / "rainfall-regression" / "rainfall-regression-dense-57-v0.1.0-candidate" / "model.keras"
    assert model_files == [expected]
    manifest = json.loads((expected.parent / "manifest.json").read_text(encoding="utf-8"))
    assert manifest["model_problem"] == "RAINFALL_REGRESSION"
    assert manifest["active"] is False
    assert manifest["approved_for_inference"] is False


def test_all_flood_risk_json_files_parse():
    for path in WORKSPACE.rglob("*.json"):
        json.loads(path.read_text(encoding="utf-8"))
