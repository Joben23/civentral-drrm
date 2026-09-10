"""Focused Phase 3B3-C1C Enteng supplemental-evidence tests."""

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
    validate_enteng_supplemental_evidence,
    validate_pdf_artifact,
)


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


SOURCE_MANIFEST = read_json(MANIFESTS / "source-manifest.json")
EVENT_REGISTRY = read_json(MANIFESTS / "flood-event-registry.json")
AUTHORIZATION = read_json(MANIFESTS / "training-authorization.json")
WORKSHEET = read_json(MANIFESTS / "phase-3b3b-enteng-2024-review.json")
WORKSHEETS = [read_json(path) for path in sorted(MANIFESTS.glob("phase-3b3b-*-review.json"))]
SOURCES = {source["source_id"]: source for source in SOURCE_MANIFEST["sources"]}
EVENTS = {event["event_id"]: event for event in EVENT_REGISTRY["events"]}
REPORT_SOURCE = SOURCES["dswd_dromic_enteng_2024_report_41"]
ARTIFACT = REPORT_SOURCE["artifacts"][0]
REPORT_PATH = REPO_ROOT / ARTIFACT["local_file"]
ASSESSMENT = WORKSHEET["phase_3b3c1c_supplemental_evidence"]
TEMPORAL = WORKSHEET["phase_3b3c1b_temporal_assessment"]
EVENT = EVENTS[WORKSHEET["event_id"]]


def issue_codes(issues):
    return {issue.code for issue in issues}


def page_text(page_number: int) -> str:
    return PdfReader(REPORT_PATH, strict=True).pages[page_number - 1].extract_text() or ""


def test_report_41_is_real_parseable_pdf_with_internal_identity():
    data = REPORT_PATH.read_bytes()
    reader = PdfReader(REPORT_PATH, strict=True)
    assert data.startswith(b"%PDF-1.7")
    assert b"%%EOF" in data[-4096:]
    assert not data[:512].lstrip().lower().startswith((b"<!doctype html", b"<html"))
    assert len(reader.pages) == ASSESSMENT["page_count"] == 46
    title_text = page_text(1)
    assert "DSWD DROMIC Report #41" in title_text
    assert "Effects of Severe Tropical Storm" in title_text
    assert "as of 13 October 2024, 6AM" in title_text


def test_report_41_checksum_byte_length_and_filename_are_exact():
    assert ARTIFACT["sha256"] == sha256_file(REPORT_PATH) == "00f588947b542457a19619df20940346cb105700ce6f194c12db0de822a46aa1"
    assert ARTIFACT["byte_length"] == REPORT_PATH.stat().st_size == 2171872
    assert ARTIFACT["original_filename"] == REPORT_PATH.name
    assert ARTIFACT["media_type"] == "application/pdf"


def test_html_error_renamed_as_pdf_is_rejected(tmp_path):
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


def test_report_41_is_acquired_only_with_validated_raw_artifact():
    assert validate_pdf_artifact(ARTIFACT) == []
    assert REPORT_SOURCE["status"] == "ACQUIRED"
    assert REPORT_SOURCE["availability_status"] == "ACQUIRED"
    assert REPORT_SOURCE["review_status"] == "REQUIRES_HUMAN_REVIEW"
    assert REPORT_SOURCE["license_or_usage_status"] == "PENDING_REVIEW"
    assert ARTIFACT["status"] == "ACQUIRED_VALIDATED"


def test_ndrrmc_discovery_provenance_remains_external_and_is_not_replaced():
    ndrrmc = SOURCES["ndrrmc_enteng_sitreps_2024"]
    assert ndrrmc["status"] == "PROPOSED"
    assert ndrrmc["availability_status"] == "NOT_ACQUIRED"
    assert ndrrmc["local_file"] is None and ndrrmc["sha256"] is None
    assert WORKSHEET["discovery_sources"] == [{
        "source_id": "ndrrmc_enteng_sitreps_2024",
        "role": "DISCOVERY_SOURCE",
        "access_status": "EXTERNAL_ACCESS_REQUIRED",
    }]
    assert {"ndrrmc_enteng_sitreps_2024", "dswd_dromic_enteng_2024_report_41"}.issubset(EVENT["source_ids"])


def test_verified_caloocan_affected_population_snapshot_is_preserved():
    snapshot = ASSESSMENT["affected_population_snapshot"]
    assert snapshot == {
        "page_number": 15,
        "location_source_text": "Caloocan City",
        "affected_barangays": 6,
        "affected_families": 3250,
        "affected_persons": 12754,
        "represents_conditions_on_02_september": False,
        "establishes_flood_onset": False,
    }
    annex = " ".join(page_text(15).split())
    assert "Caloocan City 6 3,250 12,754" in annex


def test_relief_action_dates_are_explicit_but_never_flood_times():
    actions = ASSESSMENT["response_actions"]
    assert {action["date_source_text"] for action in actions} == {
        "03 September 2024", "06 September 2024", "07 September 2024",
    }
    assert all(action["is_flood_occurrence_time"] is False for action in actions)
    action_page = " ".join(page_text(3).split())
    assert all(date in action_page for date in ("03 September 2024", "06 September 2024", "07 September 2024"))
    assert "Caloocan" in action_page
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1c_supplemental_evidence"]["response_actions"][0]["is_flood_occurrence_time"] = True
    assert "RELIEF_ACTION_USED_AS_FLOOD_TIME" in issue_codes(validate_enteng_supplemental_evidence(unsafe))


def test_report_issue_and_cumulative_snapshot_do_not_become_onset():
    assert ASSESSMENT["report_issue_time_is_event_onset"] is False
    assert ASSESSMENT["affected_population_snapshot"]["establishes_flood_onset"] is False
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1c_supplemental_evidence"]["report_issue_time_is_event_onset"] = True
    assert "REPORT_41_TEMPORAL_BOUNDARY_VIOLATION" in issue_codes(validate_enteng_supplemental_evidence(unsafe))


def test_existing_temporal_envelope_and_crispulo_cluster_are_unchanged():
    assert ASSESSMENT["preserved_candidate_event_time_start"] == TEMPORAL["candidate_event_time_start"]
    assert ASSESSMENT["preserved_candidate_event_time_end"] == TEMPORAL["candidate_event_time_end"]
    assert ASSESSMENT["defines_02_september_onset"] is False
    assert ASSESSMENT["defines_03_september_upper_bound"] is False
    assert ASSESSMENT["merges_crispulo_cluster"] is False
    crispulo = next(cluster for cluster in TEMPORAL["episode_clusters"] if "crispulo" in cluster["episode_cluster_id"])
    assert crispulo["assessment"] == "SEPARATE_LATER_OCCURRENCE_REQUIRES_REVIEW"
    assert crispulo["candidate_event_time_end"] is None


def test_timezone_cutoff_label_and_training_state_remain_blocked():
    assert ASSESSMENT["resolves_ndrrmc_timezone"] is False
    assert ASSESSMENT["timezone_status"] == "TIMEZONE_REQUIRES_HUMAN_REVIEW"
    assert ASSESSMENT["prediction_cutoff"] is None
    assert WORKSHEET["label_status"] == "UNKNOWN"
    assert WORKSHEET["review_status"] == "REQUIRES_HUMAN_REVIEW"
    assert WORKSHEET["human_approved"] is False
    assert WORKSHEET["training_eligible"] is False
    assert WORKSHEET["candidate_windows"] == []
    assert AUTHORIZATION["status"] == "NOT_APPROVED"


def test_no_enteng_imerg_training_rows_or_model_artifact_exists():
    assert WORKSHEET["imerg_acquired_for_candidate"] is False
    assert not any("imerg" in source_id.lower() for source_id in EVENT["source_ids"])
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
    assert model_files == []


def test_training_readiness_remains_false():
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "scripts" / "ai" / "check_flood_training_readiness.py")],
        cwd=REPO_ROOT, check=False, capture_output=True, text=True,
    )
    assert result.returncode == 1
    assert json.loads(result.stdout)["training_ready"] is False


def test_manifest_worksheets_json_python_and_diff_validate():
    assert validate_manifest(SOURCE_MANIFEST) == []
    assert validate_all_worksheets(WORKSHEETS, SOURCE_MANIFEST, EVENT_REGISTRY) == []
    for path in WORKSPACE.rglob("*.json"):
        json.loads(path.read_text(encoding="utf-8"))
    for path in [REPO_ROOT / "scripts" / "ai" / "flood_evidence_common.py", Path(__file__)]:
        compile(path.read_text(encoding="utf-8"), str(path), "exec")
    diff = subprocess.run(["git", "diff", "--check"], cwd=REPO_ROOT, check=False, capture_output=True, text=True)
    assert diff.returncode == 0, diff.stdout + diff.stderr


def test_report_41_raw_pdf_is_gitignored():
    result = subprocess.run(["git", "check-ignore", "-q", "--", ARTIFACT["local_file"]], cwd=REPO_ROOT, check=False)
    assert result.returncode == 0
