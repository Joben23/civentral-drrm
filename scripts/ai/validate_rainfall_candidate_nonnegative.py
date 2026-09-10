#!/usr/bin/env python3
"""Validate the frozen rainfall candidate and select one nonnegative policy."""

from __future__ import annotations

import hashlib
import json
import math
import os
import random
import shutil
from datetime import datetime, timezone
from pathlib import Path

os.environ.setdefault("PYTHONHASHSEED", "20240902")
os.environ.setdefault("TF_DETERMINISTIC_OPS", "1")
os.environ.setdefault("TF_ENABLE_ONEDNN_OPTS", "0")
os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import numpy as np
import tensorflow as tf


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DATASET = WORKSPACE / "data" / "processed" / "rainfall-regression-windows-24h-to-3h.json"
CONTRACT = WORKSPACE / "manifests" / "phase-3c-rainfall-regression-contract.json"
CANDIDATE_DIR = WORKSPACE / "artifacts" / "rainfall-regression" / "rainfall-regression-dense-57-v0.1.0-candidate"
MODEL = CANDIDATE_DIR / "model.keras"
PREPROCESSING = CANDIDATE_DIR / "preprocessing.json"
TRAINING_RESULT = CANDIDATE_DIR / "training-result.json"
CANDIDATE_MANIFEST = CANDIDATE_DIR / "manifest.json"
OUTPUT = WORKSPACE / "manifests" / "phase-3e1-rainfall-candidate-validation.json"
SOFTPLUS_DIR = WORKSPACE / "artifacts" / "rainfall-regression" / "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
SEED = 20240902
EXPECTED_DATASET_SHA = "eb7a53a945c952a2fb129f4ebc9a5b592f2d709c6f7a0548a366e933518f0e96"
EXPECTED_MODEL_SHA = "e729f7ca6f60d702f5ee6c9463ca72ea3c424bb2975cfef274f9319c324d22f6"
EXPECTED_PREPROCESSING_SHA = "e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4"
EXPECTED_TRAINING_RESULT_SHA = None


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def metrics(actual: np.ndarray, predicted: np.ndarray, mask: np.ndarray | None = None) -> dict:
    if mask is not None:
        actual, predicted = actual[mask], predicted[mask]
    error = predicted.astype(np.float64) - actual.astype(np.float64)
    return {"count": int(len(actual)), "mae_mm": float(np.mean(np.abs(error))), "rmse_mm": float(math.sqrt(np.mean(error ** 2)))}


def qa(actual: np.ndarray, raw: np.ndarray, threshold: float) -> dict:
    zero = actual == 0
    nonzero = actual > 0
    tail = actual >= threshold
    return {
        "raw": metrics(actual, raw),
        "negative_prediction_count": int(np.sum(raw < 0)),
        "negative_prediction_percentage": float(np.mean(raw < 0) * 100),
        "minimum_prediction_mm": float(np.min(raw)),
        "zero_target": metrics(actual, raw, zero),
        "nonzero_target": metrics(actual, raw, nonzero),
        "upper_tail": {"threshold_mm": threshold, **metrics(actual, raw, tail)},
    }


def build_model(output_activation: str) -> tf.keras.Model:
    return tf.keras.Sequential([
        tf.keras.layers.Input(shape=(57,), name="rainfall_features"),
        tf.keras.layers.Dense(64, activation="relu", name="dense_64"),
        tf.keras.layers.Dense(32, activation="relu", name="dense_32"),
        tf.keras.layers.Dense(1, activation=output_activation, name="rainfall_mm"),
    ], name=f"civentral_rainfall_regression_dense_{output_activation}")


def train_softplus(x_train, y_train, x_validation, y_validation):
    random.seed(SEED)
    np.random.seed(SEED)
    tf.keras.utils.set_random_seed(SEED)
    tf.config.set_visible_devices([], "GPU")
    tf.config.experimental.enable_op_determinism()
    model = build_model("softplus")
    model.compile(optimizer=tf.keras.optimizers.Adam(learning_rate=0.001), loss="mse", metrics=[tf.keras.metrics.MeanAbsoluteError(name="mae"), tf.keras.metrics.RootMeanSquaredError(name="rmse")])
    history = model.fit(x_train, y_train, validation_data=(x_validation, y_validation), epochs=150, batch_size=128, shuffle=False, callbacks=[tf.keras.callbacks.EarlyStopping(monitor="val_loss", mode="min", patience=12, min_delta=1e-5, restore_best_weights=True)], verbose=0)
    return model, {"epochs_completed": len(history.history["loss"]), "best_epoch": int(np.argmin(history.history["val_loss"]) + 1), "best_validation_loss_mse": float(np.min(history.history["val_loss"]))}


def main() -> int:
    frozen = {"model_sha256": sha256(MODEL), "preprocessing_sha256": sha256(PREPROCESSING), "dataset_sha256": sha256(DATASET), "training_result_sha256": sha256(TRAINING_RESULT), "manifest_sha256": sha256(CANDIDATE_MANIFEST)}
    if frozen["model_sha256"] != EXPECTED_MODEL_SHA or frozen["preprocessing_sha256"] != EXPECTED_PREPROCESSING_SHA or frozen["dataset_sha256"] != EXPECTED_DATASET_SHA:
        raise RuntimeError("Frozen Phase 3D checksum mismatch")
    dataset = json.loads(DATASET.read_text(encoding="utf-8"))
    preprocessing = json.loads(PREPROCESSING.read_text(encoding="utf-8"))
    split_arrays = {}
    for split in ("train", "validation", "test"):
        rows = dataset["records"][split]
        split_arrays[split] = (np.asarray([row["features"] for row in rows], dtype=np.float32), np.asarray([row["target_next_3h_accumulated_rainfall_mm"] for row in rows], dtype=np.float32))
    means = np.asarray(preprocessing["feature_mean"], dtype=np.float32)
    stds = np.asarray(preprocessing["feature_std"], dtype=np.float32)
    x_train, y_train = split_arrays["train"]
    x_validation, y_validation = split_arrays["validation"]
    x_test, y_test = split_arrays["test"]
    normalized = {"train": (x_train - means) / stds, "validation": (x_validation - means) / stds, "test": (x_test - means) / stds}
    positive_train = y_train[y_train > 0]
    tail_threshold = float(np.quantile(positive_train, 0.90))
    linear_model = tf.keras.models.load_model(MODEL, compile=False)
    validation_raw = linear_model.predict(normalized["validation"], verbose=0).reshape(-1)
    validation_clamped = np.maximum(0.0, validation_raw)
    linear_validation = qa(y_validation, validation_raw, tail_threshold)
    linear_clamp_validation = qa(y_validation, validation_clamped, tail_threshold)
    softplus_model, softplus_training = train_softplus(normalized["train"], y_train, normalized["validation"], y_validation)
    validation_softplus = softplus_model.predict(normalized["validation"], verbose=0).reshape(-1)
    softplus_validation = qa(y_validation, validation_softplus, tail_threshold)
    baselines = {"train_mean": metrics(y_validation, np.full_like(y_validation, float(np.mean(y_train)))), "antecedent_3h_persistence": metrics(y_validation, x_validation[:, 49])}
    eligible = {"LINEAR_ZERO_FLOOR": linear_clamp_validation, "SOFTPLUS": softplus_validation}
    selected_policy = min(eligible, key=lambda key: (eligible[key]["raw"]["rmse_mm"], eligible[key]["raw"]["mae_mm"]))
    selected_model = "FROZEN_LINEAR_PHASE_3D_CANDIDATE" if selected_policy == "LINEAR_ZERO_FLOOR" else "SOFTPLUS_COMPARISON_CANDIDATE"
    selected_artifact = None
    if selected_policy == "SOFTPLUS":
        if SOFTPLUS_DIR.exists():
            raise FileExistsError(f"Refusing to overwrite Softplus candidate bundle: {SOFTPLUS_DIR}")
        SOFTPLUS_DIR.mkdir(parents=True)
        softplus_model_path = SOFTPLUS_DIR / "model.keras"
        softplus_preprocessing_path = SOFTPLUS_DIR / "preprocessing.json"
        softplus_model.save(softplus_model_path)
        shutil.copy2(PREPROCESSING, softplus_preprocessing_path)
        selected_artifact = {"model_path": str(softplus_model_path.relative_to(REPO_ROOT)), "model_sha256": sha256(softplus_model_path), "model_byte_length": softplus_model_path.stat().st_size, "preprocessing_path": str(softplus_preprocessing_path.relative_to(REPO_ROOT)), "preprocessing_sha256": sha256(softplus_preprocessing_path), "candidate_version": "rainfall-regression-dense-57-v0.1.1-softplus-candidate", "active": False, "approved_for_inference": False, "output_activation": "softplus"}
        (SOFTPLUS_DIR / "manifest.json").write_text(json.dumps({"schema_version": "1.0.0", "model_problem": "RAINFALL_REGRESSION", "candidate_status": "VALIDATED_RESEARCH_CANDIDATE", **selected_artifact, "dataset_sha256": frozen["dataset_sha256"], "feature_count": 57, "target": "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", "training_contract": {"optimizer": "Adam", "learning_rate": 0.001, "loss": "MSE", "batch_size": 128, "max_epochs": 150, "early_stopping_patience": 12, "shuffle": False}, "limitations": ["Research candidate only.", "Not active or approved for inference.", "Predicts rainfall quantity, not flood occurrence or probability."]}, indent=2) + "\n", encoding="utf-8")
    if selected_policy == "LINEAR_ZERO_FLOOR":
        test_raw = linear_model.predict(normalized["test"], verbose=0).reshape(-1)
        test_final = np.maximum(0.0, test_raw)
    else:
        test_raw = softplus_model.predict(normalized["test"], verbose=0).reshape(-1)
        test_final = test_raw
    selected_test = {"raw": qa(y_test, test_raw, tail_threshold), "final": qa(y_test, test_final, tail_threshold), "negative_final_prediction_count": int(np.sum(test_final < 0))}
    result = {
        "schema_version": "1.0.0", "assessment_id": "phase-3e1-rainfall-candidate-validation", "selection_rule": {"primary": "LOWEST_VALIDATION_RMSE", "secondary": "LOWEST_VALIDATION_MAE", "test_used_for_selection": False, "physical_validity": "Selected final application output must contain no negative rainfall."},
        "frozen_phase_3d": frozen, "frozen_candidate_immutable": True, "feature_count": 57, "target": "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", "train_derived_upper_tail_threshold_mm": tail_threshold,
        "validation": {"linear_raw": linear_validation, "linear_zero_floor": {"final": linear_clamp_validation, "changed_prediction_count": int(np.sum(validation_clamped != validation_raw)), "minimum_final_prediction_mm": float(np.min(validation_clamped))}, "softplus": softplus_validation, "baselines": baselines, "softplus_training": softplus_training},
        "selection": {"selected_policy": selected_policy, "selected_model": selected_model, "selected_artifact": selected_artifact, "status": "VALIDATED_RESEARCH_CANDIDATE", "reason": "Selected by validation RMSE, then validation MAE; test was not used for selection."},
        "final_test_evaluation": selected_test, "upper_tail_definition": "Targets at or above the TRAIN positive-target 90th percentile; descriptive QA only.",
        "output_policy": {"raw_prediction_mm": "TensorFlow model output", "final_prediction_mm": "max(0, raw_prediction_mm)" if selected_policy == "LINEAR_ZERO_FLOOR" else "Softplus model output", "output_policy": "ZERO_FLOOR" if selected_policy == "LINEAR_ZERO_FLOOR" else "MODEL_NONNEGATIVE_SOFTPLUS", "floor_value_mm": 0.0 if selected_policy == "LINEAR_ZERO_FLOOR" else None},
        "governance": {"candidate_status": "VALIDATED_RESEARCH_CANDIDATE", "active": False, "approved_for_inference": False, "operational_use_approved": False, "global_training_ready": False, "global_training_authorization": "NOT_APPROVED", "flood_labels_changed": False, "mgb_fusion_implemented": False, "service_activation_changed": False},
    }
    OUTPUT.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"success": True, "selected_policy": selected_policy, "selected_model": selected_model, "validation_rmse": eligible[selected_policy]["raw"]["rmse_mm"], "test_final": selected_test["final"]}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())