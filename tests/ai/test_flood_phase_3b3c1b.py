"""Focused Phase 3B3-C1B Enteng temporal-evidence governance tests."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
MANIFESTS = WORKSPACE / "manifests"
sys.path.insert(0, str(REPO_ROOT / "scripts" / "ai"))

from flood_data_common import validate_manifest  # noqa: E402
from flood_evidence_common import (  # noqa: E402
    validate_all_worksheets,
    validate_enteng_temporal_assessment,
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
ASSESSMENT = WORKSHEET["phase_3b3c1b_temporal_assessment"]
EVENT = EVENTS[WORKSHEET["event_id"]]
NDRRMC_SOURCE = SOURCES["ndrrmc_enteng_sitreps_2024"]


def issue_codes(issues):
    return {issue.code for issue in issues}


def test_occurrence_and_report_issue_times_remain_distinct():
    assert ASSESSMENT["report_issue_time_is_event_onset"] is False
    assert all(item["issue_time_is_event_onset"] is False for item in WORKSHEET["report_time_evidence"])
    report_issue_times = {
        item["report_issue_time_source_text"]
        for item in ASSESSMENT["official_source_searches"]
        if item["report_issue_time_source_text"] is not None
    }
    occurrence_points = {
        f'{item["occurrence_date_source_text"]}, {item["occurrence_time_source_text"]}'
        for item in ASSESSMENT["exact_caloocan_records"]
    }
    assert report_issue_times.isdisjoint(occurrence_points)
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1b_temporal_assessment"]["report_issue_time_is_event_onset"] = True
    assert "REPORT_TIME_USED_AS_EVENT_ONSET" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_subsided_time_cannot_become_onset():
    assert ASSESSMENT["subsided_time_is_event_onset"] is False
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1b_temporal_assessment"]["subsided_time_is_event_onset"] = True
    assert "SUBSIDED_TIME_USED_AS_EVENT_ONSET" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_separate_date_clusters_are_not_automatically_merged():
    assert ASSESSMENT["separate_date_clusters_automatically_merged"] is False
    clusters = ASSESSMENT["episode_clusters"]
    assert {cluster["episode_cluster_id"] for cluster in clusters} == {
        "enteng-caloocan-2024-09-02-primary",
        "enteng-caloocan-2024-09-05-crispulo-review",
    }
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1b_temporal_assessment"]["separate_date_clusters_automatically_merged"] = True
    assert "SEPARATE_ENTENG_CLUSTERS_MERGED" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_indexed_official_content_cannot_mark_raw_artifact_acquired():
    assert ASSESSMENT["indexed_official_content_is_raw_artifact"] is False
    assert ASSESSMENT["raw_artifact_access_status"] == "EXTERNAL_ACCESS_REQUIRED"
    assert ASSESSMENT["acquired_artifact_refs"] == []
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe_assessment = unsafe["phase_3b3c1b_temporal_assessment"]
    unsafe_assessment["raw_artifact_access_status"] = "ACQUIRED"
    unsafe_assessment["acquired_artifact_refs"] = ["not-a-real-artifact"]
    unsafe_assessment["indexed_official_content_is_raw_artifact"] = True
    assert "INDEXED_EVIDENCE_PROMOTED_TO_ACQUIRED" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_ndrrmc_attachments_remain_external_and_unacquired():
    assert NDRRMC_SOURCE["status"] == "PROPOSED"
    assert NDRRMC_SOURCE["availability_status"] == "NOT_ACQUIRED"
    assert NDRRMC_SOURCE["local_file"] is None
    assert NDRRMC_SOURCE["sha256"] is None
    assert all(item["access_status"] == "EXTERNAL_ACCESS_REQUIRED" for item in ASSESSMENT["official_source_searches"])


def test_unsupported_barangays_are_not_inferred():
    unnumbered = [
        record for record in ASSESSMENT["exact_caloocan_records"]
        if not record["locality_source_text"][:1].isdigit()
    ]
    assert unnumbered and all(record["barangay_source_text"] is None for record in unnumbered)
    unsafe = copy.deepcopy(WORKSHEET)
    record = next(item for item in unsafe["phase_3b3c1b_temporal_assessment"]["exact_caloocan_records"] if item["locality_source_text"] == "Crispulo st.")
    record["barangay_source_text"] = "inferred"
    assert "UNSUPPORTED_BARANGAY_INFERENCE" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_timezone_uncertainty_is_preserved():
    assert ASSESSMENT["timezone_status"] == "TIMEZONE_REQUIRES_HUMAN_REVIEW"
    assert all(item["issue_timezone"] is None for item in WORKSHEET["report_time_evidence"])


def test_candidate_envelope_requires_occurrence_and_subsidence_bounds():
    assert ASSESSMENT["temporal_quality"] == "DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND"
    assert ASSESSMENT["candidate_event_time_start"]
    assert ASSESSMENT["candidate_event_time_end"]
    assert ASSESSMENT["occurrence_evidence"] and ASSESSMENT["subsidence_evidence"]
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3c1b_temporal_assessment"]["candidate_event_time_end"] = None
    assert "UNSUPPORTED_ENTENG_TEMPORAL_ENVELOPE" in issue_codes(validate_enteng_temporal_assessment(unsafe))


def test_envelope_does_not_confirm_label_or_create_cutoff():
    assert ASSESSMENT["prediction_cutoff"] is None
    assert ASSESSMENT["human_review_required"] is True
    assert WORKSHEET["label_status"] == "UNKNOWN"
    assert WORKSHEET["review_status"] == "REQUIRES_HUMAN_REVIEW"
    assert WORKSHEET["human_approved"] is False
    assert WORKSHEET["training_eligible"] is False
    assert WORKSHEET["candidate_windows"] == []
    assert EVENT["event_start"] is None and EVENT["event_end"] is None


def test_no_enteng_imerg_or_training_rows_or_model_artifact_exists():
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


def test_training_readiness_remains_blocked():
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "scripts" / "ai" / "check_flood_training_readiness.py")],
        cwd=REPO_ROOT, check=False, capture_output=True, text=True,
    )
    assert result.returncode == 1
    assert json.loads(result.stdout)["training_ready"] is False
    assert AUTHORIZATION["status"] == "NOT_APPROVED"


def test_manifests_and_all_worksheets_pass_fail_closed_validation():
    assert validate_manifest(SOURCE_MANIFEST) == []
    assert validate_all_worksheets(WORKSHEETS, SOURCE_MANIFEST, EVENT_REGISTRY) == []


def test_json_and_python_sources_validate_without_generating_artifacts():
    for path in WORKSPACE.rglob("*.json"):
        json.loads(path.read_text(encoding="utf-8"))
    for path in [REPO_ROOT / "scripts" / "ai" / "flood_evidence_common.py", Path(__file__)]:
        compile(path.read_text(encoding="utf-8"), str(path), "exec")


def test_git_diff_has_no_whitespace_errors():
    result = subprocess.run(["git", "diff", "--check"], cwd=REPO_ROOT, check=False, capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr
