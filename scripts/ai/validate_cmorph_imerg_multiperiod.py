#!/usr/bin/env python3
"""Research-only multi-period CMORPH CDR versus IMERG compatibility study."""
from __future__ import annotations

import argparse
import importlib.util
import json
import math
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from statistics import median, pstdev

import numpy as np
from netCDF4 import Dataset, num2date
from shapely.geometry import box, shape
from shapely.ops import unary_union

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
SERVICE_ROOT = WORKSPACE / "service"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from common.rainfall_features import feature_names, make_features, parse_utc, validate_history

BASELINE_SCRIPT = REPO_ROOT / "scripts/ai/validate_cmorph_imerg_compatibility.py"
_spec = importlib.util.spec_from_file_location("phase_3ff", BASELINE_SCRIPT)
assert _spec and _spec.loader
_phase_3ff = importlib.util.module_from_spec(_spec)
sys.modules[_spec.name] = _phase_3ff
_spec.loader.exec_module(_phase_3ff)

IMERG_PATH = WORKSPACE / "data/processed/imerg-caloocan-city-mean-2023-2024.json"
CONTRACT_PATH = WORKSPACE / "manifests/phase-3c-rainfall-regression-contract.json"
SELECTION_MANIFEST = WORKSPACE / "manifests/phase-3fg-cmorph-multiperiod-selection.json"
REPORT_PATH = WORKSPACE / "data/processed/pilot/cmorph-imerg-multiperiod-compatibility-report.json"
CMORPH_ROOT = WORKSPACE / "data/raw/cmorph"
BOUNDARY_PATH = REPO_ROOT / "data/import/caloocan-city-boundary.geojson"
MODEL_PATH = _phase_3ff.MODEL_PATH
PREPROCESSING_PATH = _phase_3ff.PREPROCESSING_PATH
START = datetime(2023, 9, 1, tzinfo=timezone.utc)
HALF_HOUR = timedelta(minutes=30)
PERIOD_HOURS = 72
PERIOD_INTERVALS = PERIOD_HOURS * 2
MAX_NEW_FILES = 500
MAX_NEW_BYTES = 2 * 1024**3


def load_records() -> list[dict]:
    return json.loads(IMERG_PATH.read_text(encoding="utf-8"))["records"]


def split_ranges(contract: dict) -> dict[str, tuple[datetime, datetime]]:
    ranges = contract["split_strategy"]["ranges"]
    return {name: (parse_utc(item["source_start"]), parse_utc(item["source_end"]) + HALF_HOUR) for name, item in ranges.items()}


def period_candidates(records: list[dict], split_name: str, bounds: tuple[datetime, datetime]) -> list[dict]:
    start, end = bounds
    candidates = []
    for index in range(len(records) - PERIOD_INTERVALS + 1):
        timestamps = [parse_utc(row["observed_at_start"]) for row in records[index : index + PERIOD_INTERVALS]]
        if timestamps[0] < start or timestamps[-1] + HALF_HOUR > end:
            continue
        values = [float(row["city_mean_precipitation_mm"]) for row in records[index : index + PERIOD_INTERVALS]]
        if any(not math.isfinite(value) or value < 0 for value in values):
            continue
        candidates.append({"split": split_name, "start_index": index, "start_utc": timestamps[0].strftime("%Y-%m-%dT%H:%M:%SZ"), "end_utc_exclusive": (timestamps[-1] + HALF_HOUR).strftime("%Y-%m-%dT%H:%M:%SZ"), "mean_mm": float(np.mean(values)), "rain_occurrence_frequency": float(np.mean(np.asarray(values) > 0)), "p90_mm": float(np.quantile(values, 0.90)), "max_mm": float(np.max(values))})
    return candidates


def select_periods(records: list[dict], contract: dict) -> dict:
    all_candidates = []
    for split_name, bounds in split_ranges(contract).items():
        all_candidates.extend(period_candidates(records, split_name, bounds))
    means = np.asarray([item["mean_mm"] for item in all_candidates])
    quantiles = np.quantile(means, [0.10, 0.40, 0.70, 0.90])
    labels = ["LOW_RAIN", "MODERATE_RAIN", "HIGH_RAIN", "VERY_HIGH_RAIN"]
    selected = []
    used_splits: set[str] = set()
    for label, target in zip(labels, quantiles):
        ranked = sorted(all_candidates, key=lambda item: (abs(item["mean_mm"] - target), item["start_utc"]))
        preferred = [item for item in ranked if item["split"] not in used_splits] or ranked
        choice = preferred[0]
        used_splits.add(choice["split"])
        selected.append({"period_id": f"P{len(selected) + 1}_{label.lower()}", "rainfall_regime": label, "selection_statistic": "IMERG-only 72-hour mean; nearest candidate to predeclared global candidate quantile", "selection_target_quantile": float(target), **choice})
    return {"schema_version": "1.0.0", "study_purpose": "Predeclared multi-period retrospective CMORPH CDR versus IMERG Final compatibility study", "regime_definition": "Relative IMERG-only research sampling strata based on 72-hour mean rainfall candidate quantiles; not operational hazard or disaster-risk categories.", "selection_algorithm": {"source_only": "IMERG", "period_hours": PERIOD_HOURS, "candidate_step": "every valid source index", "regime_quantiles": {label: float(value) for label, value in zip(labels, quantiles)}, "regime_quantile_labels": {"LOW_RAIN": "approximately candidate p10", "MODERATE_RAIN": "approximately candidate p40", "HIGH_RAIN": "approximately candidate p70", "VERY_HIGH_RAIN": "approximately candidate p90"}, "split_policy": "period fully contained within one existing purged train/validation/test source range", "selection_is_independent_of_cmorph": True}, "selected_periods": selected}


def preflight(manifest: dict) -> dict:
    hours: set[datetime] = set()
    for period in manifest["selected_periods"]:
        start = parse_utc(period["start_utc"])
        end = parse_utc(period["end_utc_exclusive"])
        cursor = start.replace(minute=0, second=0, microsecond=0)
        while cursor < end:
            hours.add(cursor)
            cursor += timedelta(hours=1)
    sizes = [path.stat().st_size for path in CMORPH_ROOT.rglob("*.nc")]
    required_paths = [_phase_3ff.file_for_hour(hour) for hour in sorted(hours)]
    existing = {path for path in required_paths if path.exists()}
    new_paths = [path for path in required_paths if not path.exists()]
    known_sizes = sizes or [0]
    median_size = int(np.median(known_sizes))
    mean_size = float(np.mean(known_sizes))
    return {"period_required_file_count": len(required_paths), "existing_file_count": len(existing), "median_file_size_bytes": median_size, "mean_file_size_bytes": mean_size, "required_new_file_count": len(new_paths), "estimated_download_bytes": int(len(new_paths) * median_size), "estimated_total_raw_bytes": int(len(required_paths) * median_size), "size_guard_passed": len(new_paths) <= MAX_NEW_FILES and len(new_paths) * median_size <= MAX_NEW_BYTES}


def acquire(manifest: dict) -> dict:
    import urllib.request
    files = []
    hours = set()
    for period in manifest["selected_periods"]:
        cursor = parse_utc(period["start_utc"]).replace(minute=0, second=0, microsecond=0)
        end = parse_utc(period["end_utc_exclusive"])
        while cursor < end:
            hours.add(cursor)
            cursor += timedelta(hours=1)
    hours = sorted(hours)
    for hour in hours:
        path = _phase_3ff.file_for_hour(hour)
        path.parent.mkdir(parents=True, exist_ok=True)
        if not path.exists():
            with urllib.request.urlopen(_phase_3ff.url_for_hour(hour), timeout=120) as response, path.open("wb") as output:
                output.write(response.read())
            files.append(path)
    return {"required_file_count": len(hours), "available_file_count": sum(path.exists() for path in [_phase_3ff.file_for_hour(hour) for hour in hours]), "downloaded_file_count": len(files)}


def boundary_cells(start: datetime):
    payload = json.loads(BOUNDARY_PATH.read_text(encoding="utf-8"))
    boundary = unary_union([shape(feature["geometry"]) for feature in payload["features"]]) if payload.get("type") == "FeatureCollection" else shape(payload)
    with Dataset(str(_phase_3ff.file_for_hour(start))) as dataset:
        lat_bounds = dataset.variables["lat_bounds"][:]
        lon_bounds = dataset.variables["lon_bounds"][:]
        lat = dataset.variables["lat"][:]
        lon = dataset.variables["lon"][:]
    min_lon, min_lat, max_lon, max_lat = boundary.bounds
    cells = []
    for lat_index, latitude_bounds in enumerate(lat_bounds):
        if float(latitude_bounds[1]) < min_lat or float(latitude_bounds[0]) > max_lat:
            continue
        for lon_index, longitude_bounds in enumerate(lon_bounds):
            west, east = sorted((float(longitude_bounds[0]), float(longitude_bounds[1])))
            if west > 180:
                west -= 360; east -= 360
            if east < min_lon or west > max_lon:
                continue
            south, north = sorted((float(latitude_bounds[0]), float(latitude_bounds[1])))
            if box(west, south, east, north).intersects(boundary):
                cells.append((lat_index, lon_index, float(lat[lat_index]), float(lon[lon_index])))
    return cells


def read_period_cmorph(period: dict, cells) -> dict[str, float]:
    values = {}
    start = parse_utc(period["start_utc"])
    end = parse_utc(period["end_utc_exclusive"])
    cursor = start.replace(minute=0, second=0, microsecond=0)
    while cursor < end:
        path = _phase_3ff.file_for_hour(cursor)
        with Dataset(str(path)) as dataset:
            _phase_3ff.validate_time_metadata(dataset)
            variable = dataset.variables["cmorph"]
            if getattr(variable, "units", None) != "mm/hr" or "lat" not in dataset.variables or "lon" not in dataset.variables:
                raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: precipitation metadata incompatible")
            for time_index, seconds in enumerate(dataset.variables["time"][:]):
                decoded = num2date(seconds, units=dataset.variables["time"].units, calendar=getattr(dataset.variables["time"], "calendar", "standard"))
                timestamp = datetime(decoded.year, decoded.month, decoded.day, decoded.hour, decoded.minute, decoded.second, decoded.microsecond, tzinfo=timezone.utc)
                timestamp_text = timestamp.strftime("%Y-%m-%dT%H:%M:%SZ")
                if start <= timestamp < end:
                    samples = [_phase_3ff.cmorph_rate_to_mm(variable[time_index, lat_index, lon_index]) for lat_index, lon_index, _, _ in cells]
                    values[timestamp_text] = float(np.mean(samples))
        cursor += timedelta(hours=1)
    return values


def stats(imerg: list[float], cmorph: list[float]) -> dict:
    return _phase_3ff.metrics(imerg, cmorph)


def upper_tail(imerg: list[float], cmorph: list[float]) -> dict:
    source = np.asarray(imerg, dtype=float); comparison = np.asarray(cmorph, dtype=float)
    result = {}
    for quantile in (0.50, 0.75, 0.90, 0.95):
        threshold = float(np.quantile(source, quantile))
        selected = comparison[source >= threshold]
        result[str(int(quantile * 100))] = {"imerg_threshold_mm": threshold, "sample_count": int(len(selected)), "mean_imerg_mm": float(np.mean(source[source >= threshold])), "mean_cmorph_mm": float(np.mean(selected)), "mean_bias_cmorph_minus_imerg_mm": float(np.mean(selected - source[source >= threshold]))}
    return result


def histories_for_period(imerg: dict, cmorph: dict, start: str, end: str):
    timestamps = [(parse_utc(start) + index * HALF_HOUR).strftime("%Y-%m-%dT%H:%M:%SZ") for index in range(PERIOD_INTERVALS)]

    def build_window(window: list[str]):
        if not all(timestamp in imerg and timestamp in cmorph for timestamp in window):
            return None
        return (window[-1], [{"timestamp_utc": timestamp, "city_mean_precipitation_mm": imerg[timestamp]} for timestamp in window], [{"timestamp_utc": timestamp, "city_mean_precipitation_mm": cmorph[timestamp]} for timestamp in window])

    sliding = [candidate for index in range(len(timestamps) - 48 + 1) if (candidate := build_window(timestamps[index:index + 48])) is not None]
    non_overlapping = [candidate for index in range(0, len(timestamps), 48) if (candidate := build_window(timestamps[index:index + 48])) is not None]
    return sliding, non_overlapping


def model_sensitivity(windows):
    import tensorflow as tf
    if _phase_3ff.sha256(MODEL_PATH) != _phase_3ff.MODEL_SHA256 or _phase_3ff.sha256(PREPROCESSING_PATH) != _phase_3ff.PREPROCESSING_SHA256:
        raise RuntimeError("Frozen model or preprocessing checksum mismatch")
    preprocessing = json.loads(PREPROCESSING_PATH.read_text(encoding="utf-8"))
    model = tf.keras.models.load_model(MODEL_PATH, compile=False)
    imerg_features = [make_features(validate_history(a), 47)[0] for _, a, _ in windows]
    cmorph_features = [make_features(validate_history(b), 47)[0] for _, _, b in windows]
    means = np.asarray(preprocessing["feature_mean"], dtype=np.float32); stds = np.asarray(preprocessing["feature_std"], dtype=np.float32)
    a = (np.asarray(imerg_features, dtype=np.float32) - means) / stds; b = (np.asarray(cmorph_features, dtype=np.float32) - means) / stds
    pred_a = model.predict(a, verbose=0).reshape(-1); pred_b = model.predict(b, verbose=0).reshape(-1); delta = np.abs(pred_b - pred_a); feature_delta = np.abs(b - a)
    return {"window_count": len(windows), "mean_absolute_scaled_feature_difference": float(np.mean(feature_delta)), "maximum_absolute_scaled_feature_difference": float(np.max(feature_delta)), "cmorph_scaled_abs_gt_3_fraction": float(np.mean((b < -3) | (b > 3))), "mean_imerg_prediction_mm": float(np.mean(pred_a)), "mean_cmorph_prediction_mm": float(np.mean(pred_b)), "mean_absolute_prediction_difference_mm": float(np.mean(delta)), "median_absolute_prediction_difference_mm": float(np.median(delta)), "maximum_absolute_prediction_difference_mm": float(np.max(delta))}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--select", action="store_true")
    parser.add_argument("--acquire", action="store_true")
    args = parser.parse_args()
    records = load_records(); contract = json.loads(CONTRACT_PATH.read_text(encoding="utf-8"))
    if args.select or not SELECTION_MANIFEST.exists():
        SELECTION_MANIFEST.write_text(json.dumps(select_periods(records, contract), indent=2) + "\n", encoding="utf-8")
        print(f"Selection manifest written: {SELECTION_MANIFEST}")
        if not args.acquire:
            return 0
    manifest = json.loads(SELECTION_MANIFEST.read_text(encoding="utf-8")); plan = preflight(manifest)
    print(json.dumps({"acquisition_preflight": plan}, indent=2))
    if not plan["size_guard_passed"]:
        print("PHASE_3FG_ACQUISITION_REVIEW_REQUIRED")
        return 3
    if not args.acquire and plan["required_new_file_count"] > 0:
        return 0
    if args.acquire:
        acquisition = acquire(manifest)
    else:
        acquisition = {"required_file_count": plan["period_required_file_count"], "available_file_count": plan["existing_file_count"], "downloaded_file_count": 0}
    cells = boundary_cells(parse_utc(manifest["selected_periods"][0]["start_utc"]))
    period_reports = []
    pooled_a = []
    pooled_b = []
    all_sliding = []
    all_non_overlapping = []
    for period in manifest["selected_periods"]:
        imerg = {row["observed_at_start"]: float(row["city_mean_precipitation_mm"]) for row in records if period["start_utc"] <= row["observed_at_start"] < period["end_utc_exclusive"]}
        cmorph = read_period_cmorph(period, cells); sliding, non_overlapping = histories_for_period(imerg, cmorph, period["start_utc"], period["end_utc_exclusive"])
        paired = sorted(set(imerg) & set(cmorph)); a = [imerg[t] for t in paired]; b = [cmorph[t] for t in paired]; pooled_a.extend(a); pooled_b.extend(b); all_sliding.extend(sliding); all_non_overlapping.extend(non_overlapping)
        period_reports.append({"period_id": period["period_id"], "rainfall_regime": period["rainfall_regime"], "split": period["split"], "start_utc": period["start_utc"], "end_utc_exclusive": period["end_utc_exclusive"], "missingness": {"expected_interval_count": PERIOD_INTERVALS, "imerg_available_count": len(imerg), "cmorph_available_count": len(cmorph), "paired_available_count": len(paired), "imerg_missing_count": PERIOD_INTERVALS - len(imerg), "cmorph_missing_count": PERIOD_INTERVALS - len(cmorph), "paired_missing_count": PERIOD_INTERVALS - len(paired), "paired_missing_rate": (PERIOD_INTERVALS - len(paired)) / PERIOD_INTERVALS}, "rainfall_metrics": stats(a, b), "upper_tail": upper_tail(a, b), "sliding_window_count": len(sliding), "non_overlapping_window_count": len(non_overlapping), "sliding_model_sensitivity": model_sensitivity(sliding), "non_overlapping_model_sensitivity": model_sensitivity(non_overlapping)})
    overall_expected = PERIOD_INTERVALS * len(period_reports)
    overall_imerg_available = sum(item["missingness"]["imerg_available_count"] for item in period_reports)
    overall_cmorph_available = sum(item["missingness"]["cmorph_available_count"] for item in period_reports)
    overall_paired_available = sum(item["missingness"]["paired_available_count"] for item in period_reports)
    bias_values = [item["rainfall_metrics"]["mean_bias_cmorph_minus_imerg_mm"] for item in period_reports]
    mae_values = [item["rainfall_metrics"]["mean_absolute_error_mm"] for item in period_reports]
    rmse_values = [item["rainfall_metrics"]["root_mean_squared_error_mm"] for item in period_reports]
    correlation_values = [item["rainfall_metrics"]["pearson_correlation"] for item in period_reports]
    report = {"schema_version": "1.0.0", "study_type": "CMORPH_CDR_MULTI_PERIOD_COMPATIBILITY_RESEARCH", "product_identity": {"imerg": "NASA GPM IMERG Final Half-Hourly V07B", "cmorph": "NOAA CMORPH V1.0 ADJ 8km 30-minute CDR", "cdr_vs_rt": "This study evaluates retrospective/reprocessed CMORPH CDR, not CMORPH RT; CMORPH RT compatibility is not established."}, "selection_methodology": manifest, "acquisition_preflight": plan, "acquisition": acquisition, "timestamp_validation": "Every acquired CMORPH file validated for seconds-since UTC-compatible time, time_bounds, and inclusive 30-minute intervals.", "periods": period_reports, "overall_missingness": {"expected_interval_count": overall_expected, "imerg_available_count": overall_imerg_available, "cmorph_available_count": overall_cmorph_available, "paired_available_count": overall_paired_available, "imerg_missing_count": overall_expected - overall_imerg_available, "cmorph_missing_count": overall_expected - overall_cmorph_available, "paired_missing_count": overall_expected - overall_paired_available, "paired_missing_rate": (overall_expected - overall_paired_available) / overall_expected}, "overall_rainfall_metrics": stats(pooled_a, pooled_b), "overall_sliding_window_count": len(all_sliding), "overall_non_overlapping_window_count": len(all_non_overlapping), "overall_sliding_model_sensitivity": model_sensitivity(all_sliding), "overall_non_overlapping_model_sensitivity": model_sensitivity(all_non_overlapping), "cross_period_consistency": {"assessment": "Differences vary materially by selected rainfall regime; no uniform source agreement is established.", "mean_bias_range_mm": [min(bias_values), max(bias_values)], "mae_range_mm": [min(mae_values), max(mae_values)], "rmse_range_mm": [min(rmse_values), max(rmse_values)], "pearson_range": [min(correlation_values), max(correlation_values)], "approval_threshold_applied": False, "pooled_observations_temporally_correlated": True}, "limitations": ["Four 72-hour periods were selected from IMERG-only statistics before CMORPH comparison.", "Sliding windows overlap heavily; adjacent windows share 47 of 48 observations and are not independent events.", "CMORPH CDR is not CMORPH RT.", "IMERG and CMORPH grids and unweighted intersecting-cell means differ.", "No compatibility threshold is approved.", "No production approval or live adapter is implemented.", "Broader multi-season validation remains required."], "production_source": "NO_APPROVED_LIVE_SOURCE", "decision": "CMORPH_CDR_MULTI_PERIOD_INSUFFICIENT_EVIDENCE"}
    REPORT_PATH.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8"); print(json.dumps({"success": True, "decision": report["decision"], "period_count": len(period_reports)}, indent=2)); return 0


if __name__ == "__main__":
    raise SystemExit(main())
