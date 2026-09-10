#!/usr/bin/env python3
"""Research-only CMORPH versus IMERG compatibility validation.

This script never changes production providers, model artifacts, or training data.
Raw CMORPH files are downloaded only with --acquire into ignored research data.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from statistics import median, pstdev
from urllib.request import urlopen

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

CMORPH_ROOT = WORKSPACE / "data" / "raw" / "cmorph"
REPORT_PATH = WORKSPACE / "data" / "processed" / "pilot" / "cmorph-imerg-compatibility-report.json"
IMERG_PATH = WORKSPACE / "data" / "processed" / "imerg-caloocan-city-mean-2023-2024.json"
BOUNDARY_PATH = REPO_ROOT / "data" / "import" / "caloocan-city-boundary.geojson"
MODEL_DIR = WORKSPACE / "artifacts" / "rainfall-regression" / "rainfall-regression-dense-57-v0.1.1-softplus-candidate"
MODEL_PATH = MODEL_DIR / "model.keras"
PREPROCESSING_PATH = MODEL_DIR / "preprocessing.json"
MODEL_SHA256 = "51c89c12ac5919599998805c11e620aa0d80eda3bd5a6be50ec1570c1fda2865"
PREPROCESSING_SHA256 = "e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4"
BASE_URL = "https://www.ncei.noaa.gov/data/cmorph-high-resolution-global-precipitation-estimates/access/30min/8km"
START = datetime(2023, 9, 1, tzinfo=timezone.utc)
END = datetime(2023, 9, 3, tzinfo=timezone.utc)
HALF_HOUR = timedelta(minutes=30)
REQUIRED_FILE_COUNT = int((END - START).total_seconds() // 3600)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def file_for_hour(hour: datetime) -> Path:
    return CMORPH_ROOT / hour.strftime("%Y/%m/%d") / f"CMORPH_V1.0_ADJ_8km-30min_{hour:%Y%m%d%H}.nc"


def url_for_hour(hour: datetime) -> str:
    return f"{BASE_URL}/{hour:%Y/%m/%d}/CMORPH_V1.0_ADJ_8km-30min_{hour:%Y%m%d%H}.nc"


def cmorph_rate_to_mm(value: object) -> float:
    if np.ma.is_masked(value):
        raise ValueError("CMORPH fill/masked precipitation is rejected")
    numeric = float(value)
    if not math.isfinite(numeric) or numeric < 0:
        raise ValueError("CMORPH precipitation must be finite and nonnegative")
    return numeric * 0.5


def acquire() -> dict[str, object]:
    downloaded = 0
    for hour_index in range(int((END - START).total_seconds() // 3600)):
        hour = START + timedelta(hours=hour_index)
        target = file_for_hour(hour)
        target.parent.mkdir(parents=True, exist_ok=True)
        if not target.exists():
            try:
                with urlopen(url_for_hour(hour), timeout=120) as response, target.open("wb") as output:
                    output.write(response.read())
                downloaded += 1
            except Exception as exc:
                raise RuntimeError(f"CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: {exc}") from exc
    available = sum(file_for_hour(START + timedelta(hours=index)).exists() for index in range(REQUIRED_FILE_COUNT))
    return {"status": "ACQUIRED", "required_file_count": REQUIRED_FILE_COUNT, "available_file_count": available, "downloaded_file_count": downloaded, "raw_data_path": str(CMORPH_ROOT.relative_to(REPO_ROOT))}


def load_boundary():
    payload = json.loads(BOUNDARY_PATH.read_text(encoding="utf-8"))
    if payload.get("type") == "FeatureCollection":
        return unary_union([shape(feature["geometry"]) for feature in payload.get("features", [])])
    return shape(payload)


def cmorph_cells(boundary) -> list[tuple[int, int]]:
    with Dataset(str(file_for_hour(START))) as dataset:
        lat = dataset.variables["lat"][:]
        lon = dataset.variables["lon"][:]
        lat_bounds = dataset.variables["lat_bounds"][:]
        lon_bounds = dataset.variables["lon_bounds"][:]
    min_lon, min_lat, max_lon, max_lat = boundary.bounds
    selected = []
    for lat_index, latitude_bounds in enumerate(lat_bounds):
        if float(latitude_bounds[1]) < min_lat or float(latitude_bounds[0]) > max_lat:
            continue
        for lon_index, longitude_bounds in enumerate(lon_bounds):
            west, east = float(longitude_bounds[0]), float(longitude_bounds[1])
            if east < west:
                west, east = east, west
            if west > 180:
                west -= 360
                east -= 360
            if east < min_lon or west > max_lon:
                continue
            south, north = sorted((float(latitude_bounds[0]), float(latitude_bounds[1])))
            if box(west, south, east, north).intersects(boundary):
                selected.append((lat_index, lon_index))
    if not selected:
        raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: no Caloocan-intersecting cells found")
    return selected


def validate_time_metadata(dataset) -> None:
    if "time" not in dataset.variables or "time_bounds" not in dataset.variables:
        raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: time metadata is incomplete")
    time_variable = dataset.variables["time"]
    bounds_variable = dataset.variables["time_bounds"]
    units = getattr(time_variable, "units", None)
    calendar = getattr(time_variable, "calendar", "standard")
    if not isinstance(units, str) or not units.lower().startswith("seconds since ") or calendar not in {"standard", "gregorian", "proleptic_gregorian"}:
        raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: time metadata is incompatible")
    if bounds_variable.shape[0] != time_variable.shape[0] or bounds_variable.shape[1] != 2:
        raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: time bounds are incompatible")
    decoded_times = num2date(time_variable[:], units=units, calendar=calendar)
    decoded_bounds = num2date(bounds_variable[:], units=units, calendar=calendar)
    for timestamp, bounds in zip(decoded_times, decoded_bounds):
        start = datetime(timestamp.year, timestamp.month, timestamp.day, timestamp.hour, timestamp.minute, timestamp.second, timestamp.microsecond, tzinfo=timezone.utc)
        bound_start = datetime(bounds[0].year, bounds[0].month, bounds[0].day, bounds[0].hour, bounds[0].minute, bounds[0].second, bounds[0].microsecond, tzinfo=timezone.utc)
        bound_end = datetime(bounds[1].year, bounds[1].month, bounds[1].day, bounds[1].hour, bounds[1].minute, bounds[1].second, bounds[1].microsecond, tzinfo=timezone.utc)
        if start != bound_start or bound_end - bound_start not in {HALF_HOUR, HALF_HOUR - timedelta(seconds=1)}:
            raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: time bounds are not 30-minute UTC intervals")


def read_cmorph(boundary) -> tuple[dict[str, float], dict[str, object]]:
    cells = cmorph_cells(boundary)
    values: dict[str, float] = {}
    fill_count = 0
    invalid_count = 0
    file_count = 0
    cell_ids: list[str] = []
    for hour_index in range(int((END - START).total_seconds() // 3600)):
        hour = START + timedelta(hours=hour_index)
        path = file_for_hour(hour)
        if not path.exists():
            raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: required raw file is missing")
        file_count += 1
        with Dataset(str(path)) as dataset:
            validate_time_metadata(dataset)
            variable = dataset.variables["cmorph"]
            lat = dataset.variables["lat"][:]
            lon = dataset.variables["lon"][:]
            for time_index, seconds in enumerate(dataset.variables["time"][:]):
                decoded_timestamp = num2date(
                    seconds,
                    units=dataset.variables["time"].units,
                    calendar=getattr(dataset.variables["time"], "calendar", "standard"),
                )
                timestamp = datetime(decoded_timestamp.year, decoded_timestamp.month, decoded_timestamp.day, decoded_timestamp.hour, decoded_timestamp.minute, decoded_timestamp.second, decoded_timestamp.microsecond, tzinfo=timezone.utc)
                sample_values = []
                for lat_index, lon_index in cells:
                    value = variable[time_index, lat_index, lon_index]
                    try:
                        converted = cmorph_rate_to_mm(value)
                    except ValueError:
                        invalid_count += 1
                        continue
                    sample_values.append(converted)
                    if len(cell_ids) < len(cells):
                        cell_ids.append(f"CMORPH_V1.0_ADJ_8km-30min:lat={float(lat[lat_index]):.4f}:lon={float(lon[lon_index]):.4f}")
                if len(sample_values) != len(cells):
                    raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: missing or invalid cell value")
                values[timestamp.strftime("%Y-%m-%dT%H:%M:%SZ")] = float(np.mean(sample_values))
    return values, {"file_count": file_count, "intersecting_cell_count": len(cells), "intersecting_cell_ids": cell_ids, "fill_or_missing_count": fill_count, "invalid_value_count": invalid_count, "aggregation": "unweighted mean of all CMORPH grid cells whose native cell polygons intersect Caloocan boundary"}


def load_imerg() -> dict[str, float]:
    records = json.loads(IMERG_PATH.read_text(encoding="utf-8"))["records"]
    return {record["observed_at_start"]: float(record["city_mean_precipitation_mm"]) for record in records}


def average_ranks(values: list[float]) -> np.ndarray:
    order = np.argsort(np.asarray(values, dtype=float), kind="mergesort")
    ranks = np.empty(len(values), dtype=float)
    sorted_values = np.asarray(values, dtype=float)[order]
    start = 0
    while start < len(sorted_values):
        end = start + 1
        while end < len(sorted_values) and sorted_values[end] == sorted_values[start]:
            end += 1
        ranks[order[start:end]] = (start + 1 + end) / 2
        start = end
    return ranks


def metrics(imerg: list[float], cmorph: list[float]) -> dict[str, float | int]:
    a, b = np.asarray(imerg, dtype=float), np.asarray(cmorph, dtype=float)
    ranks_a = average_ranks(list(a))
    ranks_b = average_ranks(list(b))
    return {"sample_count": int(len(a)), "mean_imerg_mm": float(np.mean(a)), "mean_cmorph_mm": float(np.mean(b)), "median_imerg_mm": float(median(a)), "median_cmorph_mm": float(median(b)), "std_imerg_mm": float(pstdev(a)), "std_cmorph_mm": float(pstdev(b)), "minimum_imerg_mm": float(np.min(a)), "minimum_cmorph_mm": float(np.min(b)), "maximum_imerg_mm": float(np.max(a)), "maximum_cmorph_mm": float(np.max(b)), "rain_occurrence_frequency_imerg": float(np.mean(a > 0)), "rain_occurrence_frequency_cmorph": float(np.mean(b > 0)), "zero_rain_frequency_imerg": float(np.mean(a == 0)), "zero_rain_frequency_cmorph": float(np.mean(b == 0)), "mean_bias_cmorph_minus_imerg_mm": float(np.mean(b - a)), "mean_absolute_error_mm": float(np.mean(np.abs(b - a))), "root_mean_squared_error_mm": float(np.sqrt(np.mean((b - a) ** 2))), "pearson_correlation": float(np.corrcoef(a, b)[0, 1]) if len(a) > 1 and np.std(a) and np.std(b) else None, "spearman_correlation": float(np.corrcoef(ranks_a, ranks_b)[0, 1]) if len(a) > 1 and np.std(ranks_a) and np.std(ranks_b) else None}


def expected_timestamps() -> list[str]:
    return [(START + index * HALF_HOUR).strftime("%Y-%m-%dT%H:%M:%SZ") for index in range(int((END - START) // HALF_HOUR))]


def missingness(imerg: dict[str, float], cmorph: dict[str, float]) -> dict[str, int | float]:
    expected = set(expected_timestamps())
    imerg_available = expected & set(imerg)
    cmorph_available = expected & set(cmorph)
    paired_available = imerg_available & cmorph_available
    expected_count = len(expected)
    return {"expected_interval_count": expected_count, "imerg_available_count": len(imerg_available), "cmorph_available_count": len(cmorph_available), "paired_available_count": len(paired_available), "imerg_missing_count": expected_count - len(imerg_available), "cmorph_missing_count": expected_count - len(cmorph_available), "paired_missing_count": expected_count - len(paired_available), "paired_missing_rate": (expected_count - len(paired_available)) / expected_count}


def histories(imerg: dict[str, float], cmorph: dict[str, float]) -> list[tuple[str, list[dict[str, object]], list[dict[str, object]]]]:
    timestamps = sorted(set(imerg) & set(cmorph))
    output = []
    for index in range(48, len(timestamps) + 1):
        window = timestamps[index - 48 : index]
        if len(window) != 48 or any(parse_utc(right) - parse_utc(left) != HALF_HOUR for left, right in zip(window, window[1:])):
            continue
        output.append((window[-1], [{"timestamp_utc": timestamp, "city_mean_precipitation_mm": imerg[timestamp]} for timestamp in window], [{"timestamp_utc": timestamp, "city_mean_precipitation_mm": cmorph[timestamp]} for timestamp in window]))
    return output


def model_comparison(windows) -> dict[str, object]:
    import tensorflow as tf
    if sha256(MODEL_PATH) != MODEL_SHA256 or sha256(PREPROCESSING_PATH) != PREPROCESSING_SHA256:
        raise RuntimeError("Frozen model or preprocessing checksum mismatch")
    preprocessing = json.loads(PREPROCESSING_PATH.read_text(encoding="utf-8"))
    model = tf.keras.models.load_model(MODEL_PATH, compile=False)
    imerg_features, cmorph_features = [], []
    for _, imerg_history, cmorph_history in windows:
        imerg_records = validate_history(imerg_history)
        cmorph_records = validate_history(cmorph_history)
        imerg_features.append(make_features(imerg_records, 47)[0])
        cmorph_features.append(make_features(cmorph_records, 47)[0])
    means = np.asarray(preprocessing["feature_mean"], dtype=np.float32)
    stds = np.asarray(preprocessing["feature_std"], dtype=np.float32)
    imerg_scaled = (np.asarray(imerg_features, dtype=np.float32) - means) / stds
    cmorph_scaled = (np.asarray(cmorph_features, dtype=np.float32) - means) / stds
    prediction_a = model.predict(imerg_scaled, verbose=0).reshape(-1)
    prediction_b = model.predict(cmorph_scaled, verbose=0).reshape(-1)
    feature_delta = np.abs(imerg_scaled - cmorph_scaled)
    prediction_delta = np.abs(prediction_b - prediction_a)
    return {"window_count": len(windows), "feature_count": 57, "feature_order_matches_canonical": preprocessing["feature_order"] == feature_names(), "stored_preprocessing_fit_split": preprocessing["fit_split"], "scaled_feature_abs_mean": float(np.mean(feature_delta)), "scaled_feature_abs_max": float(np.max(feature_delta)), "cmorph_scaled_abs_gt_3_fraction": float(np.mean((cmorph_scaled < -3) | (cmorph_scaled > 3))), "prediction_mean_imerg_mm": float(np.mean(prediction_a)), "prediction_mean_cmorph_mm": float(np.mean(prediction_b)), "prediction_mean_absolute_difference_mm": float(np.mean(prediction_delta)), "prediction_max_absolute_difference_mm": float(np.max(prediction_delta)), "prediction_relative_difference_not_reported": "Relative differences are unstable near zero and were not used as a decision rule."}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--acquire", action="store_true")
    args = parser.parse_args()
    available_file_count = sum(file_for_hour(START + timedelta(hours=index)).exists() for index in range(REQUIRED_FILE_COUNT))
    acquisition = {"status": "LOCAL_FILES_ONLY", "required_file_count": REQUIRED_FILE_COUNT, "available_file_count": available_file_count, "downloaded_file_count": 0, "raw_data_path": str(CMORPH_ROOT.relative_to(REPO_ROOT))}
    try:
        if args.acquire:
            acquisition = acquire()
        boundary = load_boundary()
        cmorph, spatial = read_cmorph(boundary)
        imerg = load_imerg()
        expected = set(expected_timestamps())
        aligned = sorted(expected & set(imerg) & set(cmorph))
        if not aligned:
            raise RuntimeError("CMORPH_DATA_ACQUISITION_NOT_AVAILABLE: no overlapping timestamps")
        aligned_imerg = [imerg[timestamp] for timestamp in aligned]
        aligned_cmorph = [cmorph[timestamp] for timestamp in aligned]
        windows = histories(imerg, cmorph)
        report = {"schema_version": "1.0.0", "source_identity": {"imerg": "NASA GPM IMERG Final Half-Hourly V07B", "cmorph": "NOAA CMORPH V1.0 ADJ 8km-30min CDR", "cmorph_stream_distinction": "This study evaluates retrospective/reprocessed CMORPH CDR, not the CMORPH RT stream, and does not establish CMORPH RT compatibility."}, "acquisition": acquisition, "overlap_period": {"start_utc": START.strftime("%Y-%m-%dT%H:%M:%SZ"), "end_utc_exclusive": END.strftime("%Y-%m-%dT%H:%M:%SZ"), "aligned_sample_count": len(aligned), "window_period_is_training_split": True}, "missingness": missingness(imerg, cmorph), "cmorph_semantics": {"native_variable": "cmorph", "native_units": "mm/hr", "conversion": "precipitation_mm = cmorph_mm_per_hour * 0.5 hours", "timestamp_semantics": "NetCDF time/time_bounds UTC interval start", "spatial_resolution": "8km grid", "quality_policy": "masked/fill/non-finite/negative values rejected"}, "spatial_alignment": spatial, "rainfall_metrics": metrics(aligned_imerg, aligned_cmorph), "feature_and_model_comparison": model_comparison(windows), "limitations": ["Only two days were analyzed: the bounded 2023-09-01 through 2023-09-03 overlap.", "This is retrospective/reprocessed CMORPH CDR, not CMORPH RT; CMORPH RT compatibility is not established.", "The 49 one-step sliding windows overlap heavily; adjacent windows share 47 of 48 observations and are not statistically independent rainfall events.", "CMORPH and IMERG spatial grids differ; unweighted intersecting-cell means are only an approximation.", "Rainfall algorithms and bias corrections differ.", "No compatibility threshold has been approved.", "No production approval is granted and no live-source adapter is implemented.", "Broader multi-period validation is required."], "decision": "CMORPH_COMPATIBILITY_INSUFFICIENT_EVIDENCE"}
        REPORT_PATH.parent.mkdir(parents=True, exist_ok=True)
        REPORT_PATH.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        print(json.dumps({"success": True, "report": str(REPORT_PATH), "decision": report["decision"], "sample_count": len(aligned), "window_count": len(windows)}, indent=2))
        return 0
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
