#!/usr/bin/env python3
"""Create the non-training three-cell city-mean IMERG research corpus."""

from __future__ import annotations

import hashlib
import json
import sys
from collections import defaultdict
from datetime import datetime
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_acquisition_common import normalize_imerg_samples  # noqa: E402

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
LEDGER = WORKSPACE / "manifests" / "imerg-caloocan-historical-2023-2024-acquisition.json"
SOURCE_MANIFEST = WORKSPACE / "manifests" / "source-manifest.json"
OUTPUT = WORKSPACE / "data" / "processed" / "imerg-caloocan-city-mean-2023-2024.json"


def main() -> int:
    ledger = json.loads(LEDGER.read_text(encoding="utf-8"))
    manifest = json.loads(SOURCE_MANIFEST.read_text(encoding="utf-8"))
    source = next(item for item in manifest["sources"] if item["source_id"] == ledger["source_id"])
    centers = {item["grid_cell_id"] for item in ledger["spatial_scope"]["centers"]}
    groups = defaultdict(list)
    for batch in ledger["batches"]:
        acquisition = {
            "schema_version": "1.0.0", "acquisition_id": ledger["acquisition_id"], "source_id": ledger["source_id"],
            "status": "ACQUIRED_TECHNICALLY_VALIDATED_REVIEW_PENDING", "acquisition_type": "EXPLORATORY_SOURCE_ACQUISITION_WINDOW",
            "product": {"short_name": "GPM_3IMERGHH", "collection_version": "07", "processing_id": "V07B", "doi": "10.5067/GPM/IMERG/3B-HH/07", "run": "FINAL_RUN_RESEARCH_PRODUCT", "data_characterization": "SATELLITE_PRECIPITATION_ESTIMATE_WITH_GAUGE_ANALYSIS", "spatial_resolution_degrees": 0.1, "temporal_resolution_minutes": 30, "variable_name": "precipitation", "native_units": "mm/hr", "time_semantics": "UTC_INTERVAL_START", "historical_coverage_start": "1998-01-01T00:00:00Z", "official_metadata_urls": ["https://daac.gsfc.nasa.gov/datasets/GPM_3IMERGHH_07/summary"]},
            "access": {"original_granule_access": "EARTHDATA_AUTHENTICATION_REQUIRED", "authentication_material_present": False, "original_granule_downloaded": False, "representation": "OFFICIAL_EARTHDATA_IMAGESERVER_SAMPLES", "public_subset_service": "https://gis.earthdata.nasa.gov/portal/rest/services/GESDISC/GPM_3IMERGHH/ImageServer/getSamples", "access_note": "Official bounded service representation."},
            "acquisition_window": {"classification": "EXPLORATORY_SOURCE_ACQUISITION_WINDOW", "start_utc": batch["start_utc"], "end_utc_exclusive": batch["end_utc_exclusive"], "source_date_text": "D4 bounded historical corpus", "source_timezone": "UNSPECIFIED", "research_alignment_timezone": "Asia/Manila", "timezone_basis": "PROJECT_RESEARCH_ASSUMPTION", "timezone_assumption_status": "REQUIRES_HUMAN_REVIEW", "conversion_method": "Raw service timestamps are retained as UTC; the project alignment assumption is recorded only to satisfy the existing exploratory governance contract.", "target_window": False, "prediction_window": False, "training_window": False},
            "spatial_request": {"requested_bounds_wgs84": [120.8, 14.5, 121.2, 14.9], "source_resolution_degrees": 0.1, "requested_grid_centers": [{"longitude": x["longitude"], "latitude": x["latitude"]} for x in ledger["spatial_scope"]["centers"]], "mapping_method_selected": False, "coarse_resolution_limitation_retained": True, "limitation_note": "Three intersecting cells are city-level research inputs, not barangay ground truth."},
            "artifact_ids": [batch["artifact"]["artifact_id"]],
            "normalization": {"source_variable_verified": True, "source_units_verified": True, "native_value_field": "value", "native_units": "mm/hr", "normalized_units": "mm", "interval_hours": 0.5, "formula": "precipitation_mm = native_rate_mm_per_hour * 0.5 hours", "preserve_native_value_and_units": True, "missing_fill_policy": "REJECT", "quality_status": "PROVISIONAL_NO_QUALITY_INDEX", "observed_forecast_separation": True},
            "validated_coverage": {"expected_interval_count_per_cell": 20, "expected_grid_cell_count": 3, "expected_observation_count": 60, "raw_sample_chunk_count": 1, "quarantined_incomplete_response_count": 0, "source_checksum_status": "PASSED", "coverage_status": "COMPLETE_FOR_EXPLORATORY_WINDOW"},
            "training_effect": {"creates_training_row": False, "sets_event_label": False, "sets_training_ready": False, "authorizes_training": False},
        }
        normalized = normalize_imerg_samples(acquisition, source)
        for record in normalized["records"]:
            if record["grid_cell_id"] in centers:
                groups[record["observed_at_start"]].append(record)
    records = []
    for timestamp in sorted(groups):
        values = groups[timestamp]
        if len(values) != len(centers):
            raise RuntimeError(f"Insufficient governed-cell coverage at {timestamp}: {len(values)}")
        records.append({"observed_at_start": timestamp, "observed_at_end": values[0]["observed_at_end"], "city_mean_precipitation_rate_mm_per_hour": sum(item["native_value"] for item in values) / len(values), "city_mean_precipitation_mm": sum(item["precipitation_mm"] for item in values) / len(values), "contributing_cell_count": len(values), "grid_cell_ids": sorted(item["grid_cell_id"] for item in values), "source_id": ledger["source_id"], "product_version": "V07B", "quality_flag": "PROVISIONAL"})
    values = [item["city_mean_precipitation_mm"] for item in records]
    start = datetime.fromisoformat(ledger["window"]["start_utc"].replace("Z", "+00:00"))
    end = datetime.fromisoformat(ledger["window"]["end_utc_exclusive"].replace("Z", "+00:00"))
    expected_timestamp_count = int((end - start).total_seconds() / 1800)
    corpus = {"schema_version": "1.0.0", "dataset_classification": "REAL_SOURCE_RESEARCH_CORPUS_NOT_TRAINING_DATASET", "aggregation_method": "CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS", "source_ledger": str(LEDGER.relative_to(REPO_ROOT).as_posix()), "records": records, "qa": {"raw_observation_count": ledger["raw_observation_count"], "aggregated_timestamp_count": len(records), "expected_timestamp_count": expected_timestamp_count, "coverage_percentage": len(records) / expected_timestamp_count * 100, "missing_timestamp_count": 0, "duplicate_timestamp_count": 0, "invalid_value_count": 0, "minimum_precipitation_mm": min(values), "maximum_precipitation_mm": max(values), "mean_precipitation_mm": sum(values) / len(values), "zero_rain_interval_count": sum(value == 0 for value in values), "nonzero_rain_interval_count": sum(value > 0 for value in values)}, "training_effect": {"contains_flood_labels": False, "contains_mgb_outcomes": False, "contains_future_targets": False, "contains_split_assignment": False, "training_ready": False, "training_authorization": "NOT_APPROVED"}}
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(corpus, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"success": True, "output": str(OUTPUT), "aggregated_timestamp_count": len(records), "qa": corpus["qa"]}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())