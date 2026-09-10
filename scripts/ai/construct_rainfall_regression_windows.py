#!/usr/bin/env python3
"""Construct leakage-safe rainfall regression windows without training."""

from __future__ import annotations

import json
import math
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SERVICE_ROOT = REPO_ROOT / "ml" / "flood-risk" / "service"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from common.rainfall_features import feature_names, iso_z, make_features, parse_utc


WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
CORPUS = WORKSPACE / "data" / "processed" / "imerg-caloocan-city-mean-2023-2024.json"
OUTPUT = WORKSPACE / "data" / "processed" / "rainfall-regression-windows-24h-to-3h.json"
CONTRACT = WORKSPACE / "manifests" / "phase-3c-rainfall-regression-contract.json"
HALF_HOUR = timedelta(minutes=30)
LOOKBACK = 48
HORIZON = 6
PURGE = LOOKBACK + HORIZON


def validate_corpus(records: list[dict]) -> None:
    if len(records) != 17664:
        raise ValueError(f"Expected 17664 corpus records, found {len(records)}")
    previous = None
    for record in records:
        if record.get("contributing_cell_count") != 3:
            raise ValueError("Every corpus row must have three contributing cells")
        start = parse_utc(record["observed_at_start"])
        end = parse_utc(record["observed_at_end"])
        value = record.get("city_mean_precipitation_mm")
        if previous is not None and start - previous != HALF_HOUR:
            raise ValueError("Corpus timestamps are not continuous")
        if end - start != HALF_HOUR or not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0:
            raise ValueError("Corpus contains invalid interval or rainfall value")
        previous = start


def split_ranges(record_count: int) -> dict[str, tuple[int, int]]:
    train_end = int(record_count * 0.70) - 1
    validation_start = train_end + 1 + PURGE
    validation_end = validation_start + int(record_count * 0.15) - 1
    test_start = validation_end + 1 + PURGE
    return {"train": (0, train_end), "validation": (validation_start, validation_end), "test": (test_start, record_count - 1)}


def build_window(records: list[dict], origin_index: int, split_name: str, allowed_start: int, allowed_end: int) -> dict | None:
    input_start = origin_index - LOOKBACK + 1
    target_end = origin_index + HORIZON
    if input_start < allowed_start or target_end > allowed_end:
        return None
    timestamps = [parse_utc(record["observed_at_start"]) for record in records[input_start : target_end + 1]]
    if any(right - left != HALF_HOUR for left, right in zip(timestamps, timestamps[1:])):
        return None
    features, names = make_features(records, origin_index)
    target = sum(float(item["city_mean_precipitation_mm"]) for item in records[origin_index + 1 : target_end + 1])
    return {
        "forecast_origin": iso_z(timestamps[LOOKBACK - 1] + HALF_HOUR),
        "input_start": iso_z(timestamps[0]),
        "input_end": iso_z(timestamps[LOOKBACK - 1] + HALF_HOUR),
        "target_start": iso_z(timestamps[LOOKBACK]),
        "target_end": iso_z(timestamps[-1] + HALF_HOUR),
        "features": features,
        "feature_names": names,
        "target_next_3h_accumulated_rainfall_mm": target,
        "split": split_name,
    }


def scaler(windows: list[dict], feature_count: int) -> dict:
    columns = [[window["features"][index] for window in windows] for index in range(feature_count)]
    means = [sum(column) / len(column) for column in columns]
    stds = []
    for column, mean in zip(columns, means):
        variance = sum((value - mean) ** 2 for value in column) / len(column)
        stds.append(math.sqrt(variance) if variance else 1.0)
    targets = [window["target_next_3h_accumulated_rainfall_mm"] for window in windows]
    target_mean = sum(targets) / len(targets)
    target_variance = sum((value - target_mean) ** 2 for value in targets) / len(targets)
    return {"fit_split": "train", "feature_mean": means, "feature_std": stds, "target_transform": "NONE", "target_mean_qa_only": target_mean, "target_std_qa_only": math.sqrt(target_variance), "feature_count": feature_count}


def main() -> int:
    corpus = json.loads(CORPUS.read_text(encoding="utf-8"))
    records = corpus["records"]
    validate_corpus(records)
    ranges = split_ranges(len(records))
    windows = {name: [] for name in ranges}
    for split_name, (start, end) in ranges.items():
        for origin_index in range(start, end + 1):
            window = build_window(records, origin_index, split_name, start, end)
            if window is not None:
                windows[split_name].append(window)
    train_windows = windows["train"]
    if not train_windows:
        raise ValueError("No train windows were constructed")
    metadata = {
        "schema_version": "1.0.0",
        "dataset_classification": "REAL_SOURCE_RAINFALL_REGRESSION_WINDOWS_NOT_TRAINED",
        "model_problem": "RAINFALL_REGRESSION",
        "input_contract": {"lookback_hours": 24, "interval_minutes": 30, "history_intervals": LOOKBACK, "feature_order": feature_names()},
        "target_contract": {"name": "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", "units": "mm", "future_intervals": HORIZON, "semantics": "Sum of the six immediately following city-mean half-hour precipitation_mm observations."},
        "spatial_scope": "CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS",
        "split_strategy": {"method": "PURGED_CHRONOLOGICAL_SOURCE_INTERVAL_SPLIT", "proportions_requested": {"train": 0.70, "validation": 0.15, "test": 0.15}, "purge_intervals_between_splits": PURGE, "ranges": {name: {"source_start_index": start, "source_end_index": end, "source_start": records[start]["observed_at_start"], "source_end": records[end]["observed_at_start"]} for name, (start, end) in ranges.items()}, "overlap_policy": "No raw source interval may be used by more than one split."},
        "source_corpus": {"path": "ml/flood-risk/data/processed/imerg-caloocan-city-mean-2023-2024.json", "record_count": len(records), "start": records[0]["observed_at_start"], "end_exclusive": records[-1]["observed_at_end"], "native_units": "mm/hr", "normalized_units": "mm"},
        "normalization": scaler(train_windows, len(feature_names())),
        "split_counts": {name: len(items) for name, items in windows.items()},
        "purged_source_interval_count": PURGE * 2,
        "records": windows,
        "training_effect": {"tensorflow_training_performed": False, "model_fit_called": False, "model_artifact_created": False, "flood_labels_present": False, "mgb_features_present": False, "training_authorization": "NOT_APPROVED", "global_training_ready": False, "rainfall_regression_dataset_status": "READY_FOR_MODEL_TRAINING_REVIEW"},
    }
    OUTPUT.write_text(json.dumps(metadata, indent=2) + "\n", encoding="utf-8")
    CONTRACT.write_text(json.dumps({key: metadata[key] for key in ("schema_version", "model_problem", "input_contract", "target_contract", "spatial_scope", "split_strategy", "normalization", "training_effect")}, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"success": True, "output": str(OUTPUT), "split_counts": metadata["split_counts"], "purged_source_interval_count": metadata["purged_source_interval_count"]}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
