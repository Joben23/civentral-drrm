"""Focused Phase 3B3-D project/research rainfall-acquisition authorization tests."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
MANIFESTS = WORKSPACE / "manifests"
sys.path.insert(0, str(REPO_ROOT / "scripts" / "ai"))

from flood_data_common import validate_manifest  # noqa: E402
from flood_evidence_common import (  # noqa: E402
    validate_all_worksheets,
    validate_enteng_research_adjudication,
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
EVENT = EVENTS[WORKSHEET["event_id"]]
DECISION = WORKSHEET["phase_3b3d_research_adjudication"]
TEMPORAL = WORKSHEET["phase_3b3c1b_temporal_assessment"]
SUPPLEMENTAL = WORKSHEET["phase_3b3c1c_supplemental_evidence"]


def issue_codes(issues):
    return {issue.code for issue in issues}


def test_research_acquisition_authorization_is_separate_from_training_authorization():
    assert DECISION["decision_scope"] == "PROJECT_RESEARCH_ONLY"
    assert DECISION["decision_type"] == "EXPLORATORY_RAINFALL_ACQUISITION_AUTHORIZATION"
    assert DECISION["rainfall_acquisition_authorization"] == "APPROVED_FOR_PROJECT_RESEARCH_ONLY"
    assert AUTHORIZATION["status"] == DECISION["training_authorization_after_decision"] == "NOT_APPROVED"
    assert DECISION["official_lgu_approval"] is False
    assert DECISION["operational_use_approved"] is False
    assert DECISION["training_use_approved"] is False
    assert DECISION["adjudicator_identity"] is None
    assert DECISION["official_lgu_reviewer"] is None


def test_authorization_cannot_change_label_or_training_eligibility():
    assert WORKSHEET["label_status"] == EVENT["label_status"] == DECISION["label_status_after_decision"] == "UNKNOWN"
    assert WORKSHEET["training_eligible"] is EVENT["training_eligible"] is DECISION["training_eligible_after_decision"] is False
    assert WORKSHEET["human_approved"] is DECISION["human_approved_after_decision"] is False
    assert "ASSIGN_FLOOD_CONFIRMED" in DECISION["prohibited_actions"]
    assert "ASSIGN_NO_FLOOD_CONFIRMED" in DECISION["prohibited_actions"]
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3d_research_adjudication"]["training_eligible_after_decision"] = True
    assert "RESEARCH_AUTHORIZATION_SCOPE_BREACH" in issue_codes(validate_enteng_research_adjudication(unsafe))


def test_prediction_cutoff_and_training_windows_remain_absent():
    assert DECISION["prediction_cutoff"] is None
    assert DECISION["exploratory_acquisition_window"]["prediction_cutoff"] is None
    assert WORKSHEET["candidate_windows"] == EVENT["candidate_windows"] == []
    assert DECISION["exploratory_acquisition_window"]["is_prediction_window"] is False
    assert DECISION["exploratory_acquisition_window"]["is_training_window"] is False


def test_ndrrmc_raw_source_remains_external_and_dromic_remains_supplemental():
    ndrrmc = SOURCES["ndrrmc_enteng_sitreps_2024"]
    assert ndrrmc["status"] == "PROPOSED"
    assert ndrrmc["availability_status"] == "NOT_ACQUIRED"
    assert ndrrmc["local_file"] is None and ndrrmc["sha256"] is None
    basis = {item["source_id"]: item for item in DECISION["accepted_evidence_basis"]}
    assert basis["ndrrmc_enteng_sitreps_2024"]["raw_artifact_acquired"] is False
    assert basis["ndrrmc_enteng_sitreps_2024"]["accepted_use"] == "EXPLORATORY_TEMPORAL_ALIGNMENT_ONLY"
    assert basis["dswd_dromic_enteng_2024_report_41"]["evidence_role"] == "SUPPLEMENTAL_CORROBORATION"
    assert basis["dswd_dromic_enteng_2024_report_41"]["accepted_use"] == "CALOOCAN_IMPACT_CORROBORATION_ONLY"
    assert SUPPLEMENTAL["defines_02_september_onset"] is False


def test_primary_episode_is_preserved_and_crispulo_is_excluded():
    episode = DECISION["primary_episode"]
    assert episode["candidate_event_time_start"] == TEMPORAL["candidate_event_time_start"]
    assert episode["candidate_event_time_end"] == TEMPORAL["candidate_event_time_end"]
    assert episode["crispulo_excluded"] is True
    assert episode["crispulo_merged"] is False
    unsafe = copy.deepcopy(WORKSHEET)
    unsafe["phase_3b3d_research_adjudication"]["primary_episode"]["crispulo_excluded"] = False
    assert "CRISPULO_INCLUDED_IN_RESEARCH_EPISODE" in issue_codes(validate_enteng_research_adjudication(unsafe))


def test_timezone_is_explicitly_a_research_assumption_not_source_metadata():
    timing = DECISION["source_time_handling"]
    assert timing["source_timezone"] == "UNSPECIFIED"
    assert timing["source_timestamps_preserved_unchanged"] is True
    assert timing["research_alignment_timezone"] == "Asia/Manila"
    assert timing["research_alignment_utc_offset"] == "+08:00"
    assert timing["timezone_basis"] == "PROJECT_RESEARCH_ASSUMPTION"
    assert timing["claimed_as_explicit_source_metadata"] is False
    assert timing["source_corrected"] is False
    assert timing["formal_confirmation_required_for_training_or_operations"] is True


def test_exploratory_window_is_exact_and_deterministically_converted():
    window = DECISION["exploratory_acquisition_window"]
    local_start = datetime.fromisoformat(window["research_local_start"])
    local_end = datetime.fromisoformat(window["research_local_end"])
    utc_start = datetime.fromisoformat(window["utc_start"].replace("Z", "+00:00"))
    utc_end = datetime.fromisoformat(window["utc_end"].replace("Z", "+00:00"))
    assert window["window_classification"] == "EXPLORATORY_SOURCE_ACQUISITION_WINDOW"
    assert window["acquisition_type"] == "BOUNDED_HISTORICAL_RAINFALL_ACQUISITION"
    assert (local_end - local_start).total_seconds() / 3600 == window["total_window_hours"] == 72
    assert (utc_end - utc_start).total_seconds() / 3600 == 72
    assert local_start.astimezone(utc_start.tzinfo) == utc_start
    assert local_end.astimezone(utc_end.tzinfo) == utc_end
    assert window["antecedent_hours"] == 48
    assert window["ends_at_event_upper_bound"] is True
    assert window["is_validated_ml_feature_definition"] is False
    spatial = window["spatial_scope"]
    assert spatial["requested_bounds_wgs84"] == [120.8, 14.5, 121.2, 14.9]
    assert spatial["source_resolution_degrees"] == 0.1
    assert spatial["requested_grid_center_count"] == 16
    assert spatial["mapping_method_selected"] is False
    assert spatial["coarse_resolution_limitation_retained"] is True
    assert spatial["global_archive_download_authorized"] is False


def test_authorized_actions_are_evidence_collection_only():
    assert set(DECISION["authorized_actions"]) == {
        "RETRIEVE_BOUNDED_HISTORICAL_RAINFALL",
        "CHECKSUM_AND_VALIDATE_RAINFALL_OBSERVATIONS",
        "ASSESS_SPATIAL_AND_TEMPORAL_COVERAGE",
        "INSPECT_EXPLORATORY_RAINFALL_PATTERNS",
    }
    assert {
        "CREATE_TRAINING_ROWS", "TRAIN_TENSORFLOW", "ACTIVATE_MODEL",
        "USE_FOR_OPERATIONS", "CREATE_PREDICTION_CUTOFF",
    }.issubset(set(DECISION["prohibited_actions"]))


def test_no_enteng_rainfall_training_rows_or_model_artifact_exists():
    assert WORKSHEET["imerg_acquired_for_candidate"] is False
    enteng_precip = [
        path for path in (WORKSPACE / "data" / "raw" / "precipitation").rglob("*")
        if path.is_file() and any(token in path.name.lower() for token in ("enteng", "202409", "2024-09"))
    ]
    assert enteng_precip == []
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


def test_training_readiness_remains_false_and_model_unavailable():
    result = subprocess.run(
        [sys.executable, str(REPO_ROOT / "scripts" / "ai" / "check_flood_training_readiness.py")],
        cwd=REPO_ROOT, check=False, capture_output=True, text=True,
    )
    assert result.returncode == 1
    assert json.loads(result.stdout)["training_ready"] is False
    assert DECISION["training_ready_after_decision"] is False
    assert DECISION["model_status_after_decision"] == "MODEL_NOT_AVAILABLE"


def test_manifests_worksheets_json_python_and_diff_validate():
    assert validate_manifest(SOURCE_MANIFEST) == []
    assert validate_all_worksheets(WORKSHEETS, SOURCE_MANIFEST, EVENT_REGISTRY) == []
    for path in WORKSPACE.rglob("*.json"):
        json.loads(path.read_text(encoding="utf-8"))
    for path in [REPO_ROOT / "scripts" / "ai" / "flood_evidence_common.py", Path(__file__)]:
        compile(path.read_text(encoding="utf-8"), str(path), "exec")
    diff = subprocess.run(["git", "diff", "--check"], cwd=REPO_ROOT, check=False, capture_output=True, text=True)
    assert diff.returncode == 0, diff.stdout + diff.stderr
