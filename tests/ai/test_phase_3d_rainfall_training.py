"""Focused Phase 3D TensorFlow rainfall-regression training tests."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

import numpy as np
import tensorflow as tf


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DATASET_PATH = WORKSPACE / "data" / "processed" / "rainfall-regression-windows-24h-to-3h.json"
CONTRACT_PATH = WORKSPACE / "manifests" / "phase-3c-rainfall-regression-contract.json"
AUTH_PATH = WORKSPACE / "manifests" / "phase-3d-rainfall-regression-training-authorization.json"
MANIFEST_PATH = WORKSPACE / "manifests" / "phase-3d-rainfall-regression-candidate.json"
GLOBAL_AUTH_PATH = WORKSPACE / "manifests" / "training-authorization.json"
EVENTS_PATH = WORKSPACE / "manifests" / "flood-event-registry.json"


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


DATASET = read_json(DATASET_PATH)
CONTRACT = read_json(CONTRACT_PATH)
AUTH = read_json(AUTH_PATH)
MANIFEST = read_json(MANIFEST_PATH)
GLOBAL_AUTH = read_json(GLOBAL_AUTH_PATH)
EVENTS = read_json(EVENTS_PATH)
MODEL_PATH = REPO_ROOT / MANIFEST["artifact"]["path"]
PREPROCESSING_PATH = REPO_ROOT / MANIFEST["preprocessing_artifact"]["path"]
PREPROCESSING = read_json(PREPROCESSING_PATH)


def test_exact_57_feature_rainfall_contract_without_mgb_or_flood_labels():
    features = MANIFEST["feature_contract"]
    target = MANIFEST["target_contract"]
    assert features["feature_count"] == len(features["feature_order"]) == 57
    assert features["feature_order"] == CONTRACT["input_contract"]["feature_order"]
    assert features["flood_labels_present"] is False
    assert features["mgb_features_present"] is False
    assert not any(any(token in name.lower() for token in ("mgb", "flood", "risk")) for name in features["feature_order"])
    assert target["name"] == "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM"
    assert target["units"] == "mm"
    assert target["is_flood_classification"] is False


def test_fit_validation_and_test_split_discipline_is_recorded():
    control = MANIFEST["training_control"]
    assert control["fit_split"] == "train"
    assert control["model_selection_split"] == "validation"
    assert control["test_used_during_fit"] is False
    assert MANIFEST["dataset"]["test_evaluation_count"] == 1
    assert MANIFEST["dataset"]["split_counts"] == {"train": 12311, "validation": 2596, "test": 2490}
    trainer = (REPO_ROOT / "scripts" / "ai" / "train_rainfall_regression_baseline.py").read_text(encoding="utf-8")
    fit_position = trainer.index("history = model.fit(")
    test_position = trainer.index('metrics["test"] = evaluate_model')
    assert fit_position < test_position
    assert 'normalized["train"]' in trainer[fit_position:test_position]
    assert 'validation_data=(normalized["validation"]' in trainer[fit_position:test_position]


def test_scaler_is_exact_train_only_preprocessing_artifact():
    assert PREPROCESSING["fit_split"] == "train"
    assert PREPROCESSING["expected_feature_count"] == 57
    assert PREPROCESSING["feature_order"] == CONTRACT["input_contract"]["feature_order"]
    assert PREPROCESSING["feature_mean"] == CONTRACT["normalization"]["feature_mean"]
    assert PREPROCESSING["feature_std"] == CONTRACT["normalization"]["feature_std"]
    assert PREPROCESSING["dataset_sha256"] == MANIFEST["dataset"]["sha256"]
    train = np.asarray([row["features"] for row in DATASET["records"]["train"]], dtype=np.float64)
    assert np.allclose(np.asarray(PREPROCESSING["feature_mean"]), train.mean(axis=0), rtol=0, atol=1e-12)
    assert np.allclose(np.asarray(PREPROCESSING["feature_std"]), train.std(axis=0), rtol=0, atol=1e-12)


def test_baselines_are_train_only_and_persistence_uses_forecast_origin_feature():
    train_mean = MANIFEST["baselines"]["train_mean"]
    persistence = MANIFEST["baselines"]["antecedent_3h_persistence"]
    assert train_mean["fit_split"] == "train"
    train_targets = np.asarray(
        [row["target_next_3h_accumulated_rainfall_mm"] for row in DATASET["records"]["train"]],
        dtype=np.float64,
    )
    assert np.isclose(train_mean["train_target_mean_mm"], train_targets.mean())
    assert persistence["feature_name"] == "antecedent_3h_mm"
    assert persistence["feature_index"] == CONTRACT["input_contract"]["feature_order"].index("antecedent_3h_mm")
    assert persistence["uses_future_data"] is False


def test_saved_tensorflow_artifact_matches_feature_contract_and_checksum():
    assert sha256_file(MODEL_PATH) == MANIFEST["artifact"]["sha256"]
    assert MODEL_PATH.stat().st_size == MANIFEST["artifact"]["byte_length"]
    model = tf.keras.models.load_model(MODEL_PATH, compile=False, safe_mode=True)
    assert model.input_shape[-1] == 57
    assert model.output_shape[-1] == 1
    assert model.count_params() == MANIFEST["model"]["parameter_count"] == 5825
    assert MANIFEST["model"]["architecture"] == [
        "Input(57)", "Dense(64, relu)", "Dense(32, relu)", "Dense(1, linear)"
    ]


def test_preprocessing_checksum_and_candidate_state_are_fail_closed():
    assert sha256_file(PREPROCESSING_PATH) == MANIFEST["preprocessing_artifact"]["sha256"]
    assert PREPROCESSING_PATH.stat().st_size == MANIFEST["preprocessing_artifact"]["byte_length"]
    assert MANIFEST["candidate_status"] == "CANDIDATE_MODEL_TRAINED"
    assert MANIFEST["baseline_comparison_status"] == "BASELINE_COMPARISON_PASS"
    assert MANIFEST["approved_for_inference"] is False
    assert MANIFEST["operational_use_approved"] is False
    assert MANIFEST["active"] is False
    assert AUTH["candidate_model_may_be_active"] is False


def test_tensorflow_beats_both_predeclared_baselines_on_validation_and_test():
    for split in ("validation", "test"):
        for metric in ("mae_mm", "rmse_mm"):
            observed = MANIFEST["metrics"][split][metric]
            assert observed < MANIFEST["baselines"]["train_mean"][split][metric]
            assert observed < MANIFEST["baselines"]["antecedent_3h_persistence"][split][metric]


def test_negative_predictions_are_reported_without_silent_clamping():
    analysis = MANIFEST["negative_prediction_analysis"]
    assert analysis["negative_raw_prediction_count"] == 96
    assert analysis["minimum_raw_prediction_mm"] < 0
    assert analysis["nonnegative_clamp_applied_to_reported_model_metrics"] is False
    assert analysis["raw_test_metrics"]["mae_mm"] != analysis["clamped_test_metrics_qa_only"]["mae_mm"]


def test_error_analysis_is_descriptive_rainfall_qa_not_risk_policy():
    analysis = MANIFEST["test_error_analysis"]
    assert analysis["all_test"]["count"] == 2490
    assert analysis["zero_target"]["count"] + analysis["nonzero_target"]["count"] == 2490
    assert analysis["upper_tail"]["definition"] == "Test targets at or above the TRAIN positive-target 90th percentile."
    keys = set()
    stack = [MANIFEST]
    while stack:
        value = stack.pop()
        if isinstance(value, dict):
            keys.update(value)
            stack.extend(value.values())
        elif isinstance(value, list):
            stack.extend(value)
    assert not {"risk_threshold", "risk_classification", "flood_probability", "mgb_fusion"}.intersection(keys)


def test_flood_events_and_global_training_governance_remain_unchanged():
    assert all(event["label_status"] == "UNKNOWN" for event in EVENTS["events"])
    assert all(event["training_eligible"] is False for event in EVENTS["events"])
    assert all(event["candidate_windows"] == [] for event in EVENTS["events"])
    assert GLOBAL_AUTH["status"] == "NOT_APPROVED"
    assert AUTH["global_training_authorization"] == "NOT_APPROVED"
    assert AUTH["global_training_ready"] is False
    assert AUTH["flood_classifier_training_approved"] is False


def test_ready_remains_model_not_available():
    environment = os.environ.copy()
    environment["PYTHONPATH"] = str(WORKSPACE / "service")
    environment.pop("CIVENTRAL_AI_MODEL_PATH", None)
    environment.pop("CIVENTRAL_AI_MODEL_MANIFEST_PATH", None)
    code = (
        "from fastapi.testclient import TestClient;"
        "from app.main import app;"
        "r=TestClient(app).get('/ready');"
        "assert r.status_code==503;"
        "assert r.json()['code']=='MODEL_NOT_AVAILABLE'"
    )
    result = subprocess.run(
        [sys.executable, "-c", code],
        cwd=REPO_ROOT,
        env=environment,
        check=False,
        capture_output=True,
        text=True,
    )
    assert result.returncode == 0, result.stdout + result.stderr


def test_json_python_diff_and_ignore_contracts():
    for path in WORKSPACE.rglob("*.json"):
        json.loads(path.read_text(encoding="utf-8"))
    for path in [
        REPO_ROOT / "scripts" / "ai" / "train_rainfall_regression_baseline.py",
        Path(__file__),
    ]:
        compile(path.read_text(encoding="utf-8"), str(path), "exec")
    for path in (MODEL_PATH, PREPROCESSING_PATH):
        ignored = subprocess.run(["git", "check-ignore", "-q", "--", str(path)], cwd=REPO_ROOT, check=False)
        assert ignored.returncode == 0
    diff = subprocess.run(["git", "diff", "--check"], cwd=REPO_ROOT, check=False, capture_output=True, text=True)
    assert diff.returncode == 0, diff.stdout + diff.stderr
