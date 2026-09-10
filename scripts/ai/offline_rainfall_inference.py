#!/usr/bin/env python3
"""Offline, fail-closed inference for the inactive Softplus rainfall candidate."""

from __future__ import annotations

import hashlib
import json
import math
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Mapping, Sequence

import numpy as np
import tensorflow as tf

from construct_rainfall_regression_windows import feature_names, make_features


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
CANDIDATE_DIR = WORKSPACE / "artifacts" / "rainfall-regression" / "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
MODEL_PATH = CANDIDATE_DIR / "model.keras"
PREPROCESSING_PATH = CANDIDATE_DIR / "preprocessing.json"
CONTRACT_PATH = WORKSPACE / "manifests" / "phase-3e2-rainfall-offline-inference-contract.json"
EXPECTED_MODEL_SHA256 = "51c89c12ac5919599998805c11e620aa0d80eda3bd5a6be50ec1570c1fda2865"
EXPECTED_PREPROCESSING_SHA256 = "e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4"
EXPECTED_FEATURE_COUNT = 57
EXPECTED_INTERVAL = timedelta(minutes=30)


class OfflineInferenceError(ValueError):
    """Stable fail-closed error code for offline inference callers."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def parse_utc(value: Any) -> datetime:
    if not isinstance(value, str):
        raise OfflineInferenceError("INVALID_TIMESTAMP", "timestamp_utc must be an ISO-8601 string.")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise OfflineInferenceError("INVALID_TIMESTAMP", "timestamp_utc is invalid.") from exc
    if parsed.tzinfo is None or parsed.utcoffset() != timedelta(0):
        raise OfflineInferenceError("INVALID_TIMESTAMP", "timestamp_utc must explicitly use UTC.")
    return parsed.astimezone(timezone.utc)


def validate_history(history: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    if len(history) != 48:
        raise OfflineInferenceError("INSUFFICIENT_HISTORY", "Exactly 48 historical half-hour observations are required.")
    normalized: list[dict[str, Any]] = []
    previous: datetime | None = None
    for item in history:
        if not isinstance(item, Mapping):
            raise OfflineInferenceError("INVALID_PRECIPITATION", "Each history observation must be an object.")
        timestamp = parse_utc(item.get("timestamp_utc"))
        if previous is not None:
            if timestamp == previous:
                raise OfflineInferenceError("DUPLICATE_TIMESTAMP", "Duplicate history timestamp.")
            if timestamp - previous != EXPECTED_INTERVAL:
                raise OfflineInferenceError("TIMESTAMP_GAP", "History timestamps must be continuous 30-minute intervals.")
        value = item.get("city_mean_precipitation_mm")
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
            raise OfflineInferenceError("INVALID_PRECIPITATION", "Rainfall must be a finite JSON number.")
        if float(value) < 0:
            raise OfflineInferenceError("NEGATIVE_SOURCE_PRECIPITATION", "Source rainfall cannot be negative.")
        normalized.append({"observed_at_start": timestamp.isoformat().replace("+00:00", "Z"), "observed_at_end": (timestamp + EXPECTED_INTERVAL).isoformat().replace("+00:00", "Z"), "city_mean_precipitation_mm": float(value)})
        previous = timestamp
    return normalized


def load_contracts() -> tuple[dict[str, Any], dict[str, Any]]:
    if not MODEL_PATH.is_file() or not PREPROCESSING_PATH.is_file():
        raise OfflineInferenceError("MODEL_ARTIFACT_MISMATCH", "Selected model or preprocessing artifact is unavailable.")
    if sha256(MODEL_PATH) != EXPECTED_MODEL_SHA256:
        raise OfflineInferenceError("MODEL_ARTIFACT_MISMATCH", "Selected model checksum does not match the frozen candidate.")
    if sha256(PREPROCESSING_PATH) != EXPECTED_PREPROCESSING_SHA256:
        raise OfflineInferenceError("PREPROCESSING_MISMATCH", "Preprocessing checksum does not match the frozen train-derived metadata.")
    preprocessing = json.loads(PREPROCESSING_PATH.read_text(encoding="utf-8"))
    order = preprocessing.get("feature_order")
    if order != feature_names() or len(order) != EXPECTED_FEATURE_COUNT:
        raise OfflineInferenceError("FEATURE_CONTRACT_MISMATCH", "Stored preprocessing feature order does not match Phase 3C.")
    means = preprocessing.get("feature_mean")
    stds = preprocessing.get("feature_std")
    if preprocessing.get("fit_split") != "train" or len(means) != EXPECTED_FEATURE_COUNT or len(stds) != EXPECTED_FEATURE_COUNT or any(float(value) <= 0 for value in stds):
        raise OfflineInferenceError("PREPROCESSING_MISMATCH", "Stored preprocessing is not a valid train-only 57-feature scaler.")
    try:
        model = tf.keras.models.load_model(MODEL_PATH, compile=False)
    except Exception as exc:
        raise OfflineInferenceError("MODEL_LOAD_FAILED", "Selected Softplus model could not be loaded.") from exc
    if model.count_params() != 5825 or model.layers[-1].activation.__name__ != "softplus":
        raise OfflineInferenceError("MODEL_ARTIFACT_MISMATCH", "Selected model architecture does not match the frozen Softplus candidate.")
    return preprocessing, {"model": model, "model_version": "rainfall-regression-dense-57-v0.1.1-softplus-candidate"}


def predict_rainfall(history: Sequence[Mapping[str, Any]]) -> dict[str, Any]:
    records = validate_history(history)
    preprocessing, model_info = load_contracts()
    try:
        values, names = make_features(records, 47)
        if names != feature_names() or len(values) != EXPECTED_FEATURE_COUNT:
            raise OfflineInferenceError("FEATURE_CONTRACT_MISMATCH", "Phase 3C feature construction produced an unexpected contract.")
        vector = (np.asarray(values, dtype=np.float32) - np.asarray(preprocessing["feature_mean"], dtype=np.float32)) / np.asarray(preprocessing["feature_std"], dtype=np.float32)
        raw = float(model_info["model"].predict(vector.reshape(1, -1), verbose=0).reshape(-1)[0])
    except OfflineInferenceError:
        raise
    except Exception as exc:
        raise OfflineInferenceError("PREDICTION_FAILED", "Offline TensorFlow prediction failed.") from exc
    if not math.isfinite(raw) or raw < 0:
        raise OfflineInferenceError("PREDICTION_FAILED", "Softplus prediction was not finite and nonnegative.")
    forecast_origin = records[-1]["observed_at_end"]
    return {"model_problem": "RAINFALL_REGRESSION", "forecast_origin_utc": forecast_origin, "forecast_horizon_hours": 3, "target": "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", "raw_prediction_mm": raw, "final_prediction_mm": raw, "output_policy": "MODEL_NONNEGATIVE_SOFTPLUS", "model_version": model_info["model_version"], "model_status": "VALIDATED_RESEARCH_CANDIDATE", "operational": False}


def main() -> int:
    raise SystemExit("Offline utility module only; no network endpoint or arbitrary-vector CLI is provided.")


if __name__ == "__main__":
    main()