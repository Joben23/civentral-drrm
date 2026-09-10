#!/usr/bin/env python3
"""Train the governed Phase 3D TensorFlow rainfall-regression baseline.

This script is intentionally separate from the flood-risk inference runtime.
It trains only the Phase 3C rainfall TRAIN split, selects epochs with only the
VALIDATION split, evaluates TEST exactly once after fitting, and writes an
ignored CANDIDATE bundle. It never changes flood labels, risk policies, or
service readiness.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
import platform
import random
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


SEED = 20240902
os.environ.setdefault("PYTHONHASHSEED", str(SEED))
os.environ.setdefault("TF_DETERMINISTIC_OPS", "1")
os.environ.setdefault("TF_ENABLE_ONEDNN_OPTS", "0")
os.environ.setdefault("CUDA_VISIBLE_DEVICES", "-1")
os.environ.setdefault("TF_CPP_MIN_LOG_LEVEL", "2")

import numpy as np  # noqa: E402
import tensorflow as tf  # noqa: E402


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
DATASET_PATH = WORKSPACE / "data" / "processed" / "rainfall-regression-windows-24h-to-3h.json"
CONTRACT_PATH = WORKSPACE / "manifests" / "phase-3c-rainfall-regression-contract.json"
AUTHORIZATION_PATH = WORKSPACE / "manifests" / "phase-3d-rainfall-regression-training-authorization.json"
MODEL_VERSION = "rainfall-regression-dense-57-v0.1.0-candidate"
BUNDLE_DIR = WORKSPACE / "artifacts" / "rainfall-regression" / MODEL_VERSION
MODEL_FILENAME = "model.keras"
PREPROCESSING_FILENAME = "preprocessing.json"
HISTORY_FILENAME = "history.json"
MANIFEST_FILENAME = "manifest.json"
RESULT_FILENAME = "training-result.json"
EXPECTED_DATASET_SHA256 = "eb7a53a945c952a2fb129f4ebc9a5b592f2d709c6f7a0548a366e933518f0e96"
EXPECTED_COUNTS = {"train": 12311, "validation": 2596, "test": 2490}
EXPECTED_FEATURE_COUNT = 57
EXPECTED_TARGET = "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM"
EPOCHS_REQUESTED = 150
BATCH_SIZE = 128
PATIENCE = 12
LEARNING_RATE = 0.001


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_json(path: Path, payload: Any) -> None:
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def parse_time(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(timezone.utc)


def configure_reproducibility() -> None:
    random.seed(SEED)
    np.random.seed(SEED)
    tf.keras.utils.set_random_seed(SEED)
    try:
        tf.config.set_visible_devices([], "GPU")
    except RuntimeError:
        pass
    tf.config.threading.set_intra_op_parallelism_threads(1)
    tf.config.threading.set_inter_op_parallelism_threads(1)
    tf.config.experimental.enable_op_determinism()


def validate_authorization(authorization: dict[str, Any]) -> None:
    expected = {
        "decision_scope": "PROJECT_RESEARCH_ONLY",
        "model_problem": "RAINFALL_REGRESSION",
        "rainfall_regression_training_authorization": "APPROVED_FOR_PROJECT_RESEARCH_ONLY",
        "official_lgu_approval": False,
        "operational_use_approved": False,
        "public_prediction_approved": False,
        "flood_classifier_training_approved": False,
        "global_training_authorization": "NOT_APPROVED",
        "global_training_ready": False,
        "candidate_model_may_be_active": False,
    }
    for field, value in expected.items():
        if authorization.get(field) != value:
            raise ValueError(f"Unsafe or missing rainfall training authorization field: {field}")
    dataset = authorization.get("authorized_dataset")
    if not isinstance(dataset, dict) or dataset.get("sha256") != EXPECTED_DATASET_SHA256:
        raise ValueError("Authorization does not identify the exact Phase 3C dataset")
    policy = authorization.get("baseline_comparison_policy")
    if not isinstance(policy, dict) or policy.get("defined_before_training") is not True or policy.get("test_set_tuning_allowed") is not False:
        raise ValueError("Baseline comparison policy must be fixed before training")


def validate_dataset(dataset: dict[str, Any], contract: dict[str, Any]) -> tuple[dict[str, np.ndarray], dict[str, np.ndarray], list[str]]:
    if sha256_file(DATASET_PATH) != EXPECTED_DATASET_SHA256:
        raise ValueError("Phase 3C dataset checksum mismatch")
    if dataset.get("model_problem") != "RAINFALL_REGRESSION":
        raise ValueError("Dataset is not rainfall regression")
    if dataset.get("target_contract", {}).get("name") != EXPECTED_TARGET or dataset.get("target_contract", {}).get("units") != "mm":
        raise ValueError("Unexpected rainfall target contract")
    if dataset.get("spatial_scope") != "CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS":
        raise ValueError("Unexpected rainfall spatial scope")
    effect = dataset.get("training_effect", {})
    if effect.get("flood_labels_present") is not False or effect.get("mgb_features_present") is not False:
        raise ValueError("Flood labels and MGB features are prohibited")
    feature_order = dataset.get("input_contract", {}).get("feature_order")
    if not isinstance(feature_order, list) or len(feature_order) != EXPECTED_FEATURE_COUNT or len(set(feature_order)) != EXPECTED_FEATURE_COUNT:
        raise ValueError("Expected exactly 57 unique rainfall features")
    if any(any(token in name.lower() for token in ("flood", "mgb", "risk")) for name in feature_order):
        raise ValueError("Flood/MGB/risk features are prohibited")
    if feature_order != contract.get("input_contract", {}).get("feature_order"):
        raise ValueError("Dataset feature order differs from the Phase 3C contract")
    records = dataset.get("records")
    if not isinstance(records, dict) or set(records) != set(EXPECTED_COUNTS):
        raise ValueError("Dataset splits are missing or unexpected")
    features: dict[str, np.ndarray] = {}
    targets: dict[str, np.ndarray] = {}
    split_bounds: dict[str, tuple[datetime, datetime]] = {}
    for split_name, expected_count in EXPECTED_COUNTS.items():
        rows = records[split_name]
        if len(rows) != expected_count:
            raise ValueError(f"Unexpected {split_name} count")
        for row in rows:
            if row.get("split") != split_name or row.get("feature_names") != feature_order:
                raise ValueError(f"{split_name} record feature/split contract mismatch")
            if len(row.get("features", [])) != EXPECTED_FEATURE_COUNT:
                raise ValueError("Feature-vector length mismatch")
            forbidden_keys = {key for key in row if any(token in key.lower() for token in ("flood", "mgb", "risk"))}
            if forbidden_keys:
                raise ValueError(f"Forbidden fields in training row: {sorted(forbidden_keys)}")
        features[split_name] = np.asarray([row["features"] for row in rows], dtype=np.float32)
        targets[split_name] = np.asarray(
            [row["target_next_3h_accumulated_rainfall_mm"] for row in rows],
            dtype=np.float32,
        )
        split_bounds[split_name] = (
            min(parse_time(row["input_start"]) for row in rows),
            max(parse_time(row["target_end"]) for row in rows),
        )
    if not (
        split_bounds["train"][1] < split_bounds["validation"][0]
        and split_bounds["validation"][1] < split_bounds["test"][0]
    ):
        raise ValueError("Purged chronological split boundaries overlap")
    scaler = dataset.get("normalization", {})
    if scaler.get("fit_split") != "train" or scaler.get("feature_count") != EXPECTED_FEATURE_COUNT:
        raise ValueError("Scaler was not declared train-only")
    means = np.asarray(scaler.get("feature_mean"), dtype=np.float64)
    stds = np.asarray(scaler.get("feature_std"), dtype=np.float64)
    train64 = features["train"].astype(np.float64)
    if means.shape != (EXPECTED_FEATURE_COUNT,) or stds.shape != (EXPECTED_FEATURE_COUNT,) or np.any(stds <= 0):
        raise ValueError("Invalid scaler contract")
    if not np.allclose(means, train64.mean(axis=0), rtol=0, atol=1e-7):
        raise ValueError("Scaler means were not fit from the train split")
    if not np.allclose(stds, train64.std(axis=0), rtol=0, atol=1e-7):
        raise ValueError("Scaler standard deviations were not fit from the train split")
    if scaler != contract.get("normalization"):
        raise ValueError("Dataset and committed train-only scaler contracts differ")
    return features, targets, feature_order


def regression_metrics(actual: np.ndarray, predicted: np.ndarray) -> dict[str, float]:
    error = predicted.astype(np.float64) - actual.astype(np.float64)
    return {
        "mae_mm": float(np.mean(np.abs(error))),
        "rmse_mm": float(math.sqrt(np.mean(np.square(error)))),
    }


def subset_metrics(actual: np.ndarray, predicted: np.ndarray, mask: np.ndarray) -> dict[str, Any]:
    count = int(np.sum(mask))
    if count == 0:
        return {"count": 0, "mae_mm": None, "rmse_mm": None}
    return {"count": count, **regression_metrics(actual[mask], predicted[mask])}


def calculate_baselines(features: dict[str, np.ndarray], targets: dict[str, np.ndarray], feature_order: list[str]) -> dict[str, Any]:
    train_mean = float(np.mean(targets["train"], dtype=np.float64))
    persistence_index = feature_order.index("antecedent_3h_mm")
    result: dict[str, Any] = {
        "train_mean": {"train_target_mean_mm": train_mean, "fit_split": "train"},
        "antecedent_3h_persistence": {
            "feature_name": "antecedent_3h_mm",
            "feature_index": persistence_index,
            "uses_future_data": False,
        },
    }
    for split_name in ("validation", "test"):
        result["train_mean"][split_name] = regression_metrics(
            targets[split_name],
            np.full_like(targets[split_name], train_mean),
        )
        result["antecedent_3h_persistence"][split_name] = regression_metrics(
            targets[split_name],
            features[split_name][:, persistence_index],
        )
    return result


def build_model() -> tf.keras.Model:
    return tf.keras.Sequential(
        [
            tf.keras.layers.Input(shape=(EXPECTED_FEATURE_COUNT,), name="rainfall_features"),
            tf.keras.layers.Dense(
                64,
                activation="relu",
                kernel_initializer=tf.keras.initializers.GlorotUniform(seed=SEED),
                name="dense_64",
            ),
            tf.keras.layers.Dense(
                32,
                activation="relu",
                kernel_initializer=tf.keras.initializers.GlorotUniform(seed=SEED + 1),
                name="dense_32",
            ),
            tf.keras.layers.Dense(
                1,
                activation="linear",
                kernel_initializer=tf.keras.initializers.GlorotUniform(seed=SEED + 2),
                name="rainfall_mm",
            ),
        ],
        name="civentral_rainfall_regression_dense_baseline",
    )


def evaluate_model(model: tf.keras.Model, x: np.ndarray, y: np.ndarray) -> dict[str, float]:
    values = model.evaluate(x, y, verbose=0, return_dict=True)
    return {
        "loss_mse": float(values["loss"]),
        "mae_mm": float(values["mae"]),
        "rmse_mm": float(values["rmse"]),
    }


def main() -> int:
    if BUNDLE_DIR.exists():
        raise FileExistsError(f"Candidate bundle already exists; refusing overwrite: {BUNDLE_DIR}")
    authorization = json.loads(AUTHORIZATION_PATH.read_text(encoding="utf-8"))
    validate_authorization(authorization)
    dataset = json.loads(DATASET_PATH.read_text(encoding="utf-8"))
    contract = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    features, targets, feature_order = validate_dataset(dataset, contract)
    baselines = calculate_baselines(features, targets, feature_order)
    scaler = dataset["normalization"]
    means = np.asarray(scaler["feature_mean"], dtype=np.float32)
    stds = np.asarray(scaler["feature_std"], dtype=np.float32)
    normalized = {name: (values - means) / stds for name, values in features.items()}
    del dataset

    configure_reproducibility()
    tf.keras.backend.clear_session()
    model = build_model()
    model.compile(
        optimizer=tf.keras.optimizers.Adam(learning_rate=LEARNING_RATE),
        loss="mse",
        metrics=[
            tf.keras.metrics.MeanAbsoluteError(name="mae"),
            tf.keras.metrics.RootMeanSquaredError(name="rmse"),
        ],
    )
    early_stopping = tf.keras.callbacks.EarlyStopping(
        monitor="val_loss",
        mode="min",
        patience=PATIENCE,
        min_delta=1e-5,
        restore_best_weights=True,
        verbose=1,
    )
    trained_at = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    started = time.perf_counter()
    history = model.fit(
        normalized["train"],
        targets["train"],
        validation_data=(normalized["validation"], targets["validation"]),
        epochs=EPOCHS_REQUESTED,
        batch_size=BATCH_SIZE,
        shuffle=False,
        callbacks=[early_stopping],
        verbose=2,
    )
    duration = time.perf_counter() - started
    epochs_completed = len(history.history["loss"])
    best_epoch = int(np.argmin(history.history["val_loss"])) + 1

    metrics = {
        "train": evaluate_model(model, normalized["train"], targets["train"]),
        "validation": evaluate_model(model, normalized["validation"], targets["validation"]),
    }
    test_evaluation_count = 0
    metrics["test"] = evaluate_model(model, normalized["test"], targets["test"])
    test_evaluation_count += 1
    test_predictions = model.predict(normalized["test"], batch_size=BATCH_SIZE, verbose=0).reshape(-1)
    if test_evaluation_count != 1:
        raise RuntimeError("TEST split must be evaluated exactly once per candidate")

    zero_mask = targets["test"] == 0
    nonzero_mask = targets["test"] > 0
    positive_train = targets["train"][targets["train"] > 0]
    upper_tail_threshold = float(np.quantile(positive_train, 0.90)) if len(positive_train) else None
    tail_mask = targets["test"] >= upper_tail_threshold if upper_tail_threshold is not None else np.zeros_like(zero_mask)
    error_analysis = {
        "all_test": subset_metrics(targets["test"], test_predictions, np.ones_like(zero_mask, dtype=bool)),
        "zero_target": subset_metrics(targets["test"], test_predictions, zero_mask),
        "nonzero_target": subset_metrics(targets["test"], test_predictions, nonzero_mask),
        "upper_tail": {
            "definition": "Test targets at or above the TRAIN positive-target 90th percentile.",
            "threshold_mm": upper_tail_threshold,
            **subset_metrics(targets["test"], test_predictions, tail_mask),
        },
    }
    clamped_predictions = np.maximum(test_predictions, 0)
    negative_analysis = {
        "negative_raw_prediction_count": int(np.sum(test_predictions < 0)),
        "minimum_raw_prediction_mm": float(np.min(test_predictions)),
        "raw_test_metrics": regression_metrics(targets["test"], test_predictions),
        "nonnegative_clamp_applied_to_reported_model_metrics": False,
        "clamped_test_metrics_qa_only": regression_metrics(targets["test"], clamped_predictions),
    }
    comparison_pass = all(
        metrics[split_name][metric] < baselines[baseline][split_name][metric]
        for split_name in ("validation", "test")
        for metric in ("mae_mm", "rmse_mm")
        for baseline in ("train_mean", "antecedent_3h_persistence")
    )
    comparison_status = "BASELINE_COMPARISON_PASS" if comparison_pass else "BASELINE_COMPARISON_NOT_PASSED"

    BUNDLE_DIR.mkdir(parents=True, exist_ok=False)
    model_path = BUNDLE_DIR / MODEL_FILENAME
    preprocessing_path = BUNDLE_DIR / PREPROCESSING_FILENAME
    history_path = BUNDLE_DIR / HISTORY_FILENAME
    model.save(model_path)
    preprocessing = {
        "preprocessing_schema_version": "1.0.0",
        "model_problem": "RAINFALL_REGRESSION",
        "method": "STANDARD_SCALER",
        "fit_split": "train",
        "expected_feature_count": EXPECTED_FEATURE_COUNT,
        "feature_order": feature_order,
        "feature_mean": scaler["feature_mean"],
        "feature_std": scaler["feature_std"],
        "dataset_sha256": EXPECTED_DATASET_SHA256,
        "target_transform": "NONE",
    }
    write_json(preprocessing_path, preprocessing)
    write_json(
        history_path,
        {
            "epochs": list(range(1, epochs_completed + 1)),
            "loss": [float(value) for value in history.history["loss"]],
            "mae": [float(value) for value in history.history["mae"]],
            "rmse": [float(value) for value in history.history["rmse"]],
            "val_loss": [float(value) for value in history.history["val_loss"]],
            "val_mae": [float(value) for value in history.history["val_mae"]],
            "val_rmse": [float(value) for value in history.history["val_rmse"]],
        },
    )
    manifest = {
        "manifest_schema_version": "1.0.0",
        "model_name": "civentral-rainfall-regression-dense-baseline",
        "model_version": MODEL_VERSION,
        "model_problem": "RAINFALL_REGRESSION",
        "candidate_status": "CANDIDATE_MODEL_TRAINED",
        "baseline_comparison_status": comparison_status,
        "approved_for_inference": False,
        "operational_use_approved": False,
        "active": False,
        "trained_at_utc": trained_at,
        "training_duration_seconds": round(duration, 6),
        "environment": {
            "python_version": platform.python_version(),
            "tensorflow_version": tf.__version__,
            "numpy_version": np.__version__,
            "device_policy": "CPU_ONLY",
        },
        "reproducibility": {
            "random_seed": SEED,
            "python_random_seed": SEED,
            "numpy_random_seed": SEED,
            "tensorflow_random_seed": SEED,
            "python_hash_seed": os.environ.get("PYTHONHASHSEED"),
            "deterministic_ops": True,
            "one_dnn_enabled": False,
            "shuffle": False,
        },
        "dataset": {
            "path": DATASET_PATH.relative_to(REPO_ROOT).as_posix(),
            "sha256": EXPECTED_DATASET_SHA256,
            "byte_length": DATASET_PATH.stat().st_size,
            "contract_path": CONTRACT_PATH.relative_to(REPO_ROOT).as_posix(),
            "contract_sha256": sha256_file(CONTRACT_PATH),
            "split_counts": EXPECTED_COUNTS,
            "split_strategy": "PURGED_CHRONOLOGICAL_SOURCE_INTERVAL_SPLIT",
            "test_evaluation_count": test_evaluation_count,
        },
        "feature_contract": {
            "feature_schema_version": "phase-3c-rainfall-features-v1",
            "feature_count": EXPECTED_FEATURE_COUNT,
            "feature_order": feature_order,
            "spatial_scope": "CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS",
            "history_intervals": 48,
            "interval_minutes": 30,
            "flood_labels_present": False,
            "mgb_features_present": False,
        },
        "target_contract": {
            "name": EXPECTED_TARGET,
            "units": "mm",
            "future_intervals": 6,
            "target_transform": "NONE",
            "is_flood_classification": False,
        },
        "model": {
            "architecture": ["Input(57)", "Dense(64, relu)", "Dense(32, relu)", "Dense(1, linear)"],
            "parameter_count": int(model.count_params()),
            "output_activation": "linear",
            "optimizer": "Adam",
            "learning_rate": LEARNING_RATE,
            "loss": "MSE",
        },
        "training_control": {
            "epochs_requested": EPOCHS_REQUESTED,
            "epochs_completed": epochs_completed,
            "best_epoch": best_epoch,
            "best_validation_loss_mse": float(min(history.history["val_loss"])),
            "batch_size": BATCH_SIZE,
            "early_stopping": {
                "monitor": "val_loss",
                "patience": PATIENCE,
                "min_delta": 1e-5,
                "restore_best_weights": True,
            },
            "fit_split": "train",
            "model_selection_split": "validation",
            "test_used_during_fit": False,
        },
        "baselines": baselines,
        "metrics": metrics,
        "test_error_analysis": error_analysis,
        "negative_prediction_analysis": negative_analysis,
        "artifact": {
            "path": model_path.relative_to(REPO_ROOT).as_posix(),
            "format": "KERAS_V3",
            "sha256": sha256_file(model_path),
            "byte_length": model_path.stat().st_size,
        },
        "preprocessing_artifact": {
            "path": preprocessing_path.relative_to(REPO_ROOT).as_posix(),
            "sha256": sha256_file(preprocessing_path),
            "byte_length": preprocessing_path.stat().st_size,
            "fit_split": "train",
        },
        "limitations": [
            "This model predicts rainfall quantity, not flood occurrence, flood probability, flood depth, or flood risk class.",
            "The candidate is approved only for project research and is not connected to the private inference service.",
            "The source is a city-level mean of three coarse IMERG cells and is not barangay-scale ground truth.",
            "The dataset covers approximately one year and may not represent rare or changing rainfall regimes.",
            "No MGB susceptibility, flood-event label, risk threshold, or operational policy is part of this model.",
        ],
    }
    manifest_path = BUNDLE_DIR / MANIFEST_FILENAME
    write_json(manifest_path, manifest)
    result = {
        "success": True,
        "candidate_status": manifest["candidate_status"],
        "baseline_comparison_status": comparison_status,
        "manifest_path": manifest_path.relative_to(REPO_ROOT).as_posix(),
        "model_path": manifest["artifact"]["path"],
        "model_sha256": manifest["artifact"]["sha256"],
        "model_byte_length": manifest["artifact"]["byte_length"],
        "preprocessing_path": manifest["preprocessing_artifact"]["path"],
        "preprocessing_sha256": manifest["preprocessing_artifact"]["sha256"],
        "epochs_completed": epochs_completed,
        "best_epoch": best_epoch,
        "metrics": metrics,
        "baselines": baselines,
        "test_error_analysis": error_analysis,
        "negative_prediction_analysis": negative_analysis,
    }
    write_json(BUNDLE_DIR / RESULT_FILENAME, result)
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
