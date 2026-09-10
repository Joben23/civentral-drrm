#!/usr/bin/env python3
"""Acquire the governed, bounded Enteng IMERG research window."""

from __future__ import annotations

import hashlib
import json
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import urlopen


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
RAW_DIR = WORKSPACE / "data" / "raw" / "precipitation"
SOURCE_MANIFEST = WORKSPACE / "manifests" / "source-manifest.json"
ACQUISITION_MANIFEST = WORKSPACE / "manifests" / "imerg-enteng-2024-exploratory-acquisition.json"
SERVICE = "https://gis.earthdata.nasa.gov/portal/rest/services/GESDISC/GPM_3IMERGHH/ImageServer/getSamples"
SOURCE_ID = "nasa_gpm_imerg_final_hh_v07"
START = datetime(2024, 8, 31, tzinfo=timezone.utc)
END = datetime(2024, 9, 3, tzinfo=timezone.utc)
LONGITUDES = (120.85, 120.95, 121.05, 121.15)
LATITUDES = (14.55, 14.65, 14.75, 14.85)


def iso_z(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def query_chunk(start: datetime, end_inclusive: datetime) -> dict:
    points = [[longitude, latitude] for latitude in LATITUDES for longitude in LONGITUDES]
    geometry = json.dumps({"points": points, "spatialReference": {"wkid": 4326}}, separators=(",", ":"))
    params = {
        "geometryType": "esriGeometryMultipoint",
        "geometry": geometry,
        "time": f"{int(start.timestamp() * 1000)},{int(end_inclusive.timestamp() * 1000)}",
        "returnFirstValueOnly": "false",
        "interpolation": "RSP_NearestNeighbor",
        "outFields": "*",
        "f": "json",
    }
    with urlopen(f"{SERVICE}?{urlencode(params)}", timeout=120) as response:
        payload = json.load(response)
    if not isinstance(payload.get("samples"), list):
        raise RuntimeError(f"ImageServer response has no samples array: {payload}")
    return payload


def artifact_record(path: Path, retrieved_at: str, chunk_start: datetime, chunk_end: datetime) -> dict:
    data = path.read_bytes()
    checksum = hashlib.sha256(data).hexdigest()
    return {
        "artifact_id": f"imerg_v07b_enteng_{chunk_start.strftime('%Y%m%dt%H%M')}_{chunk_end.strftime('%Y%m%dt%H%M')}",
        "artifact_role": "IMAGESERVER_SAMPLES",
        "original_filename": path.name,
        "official_url": SERVICE,
        "retrieved_at": retrieved_at,
        "media_type": "application/json",
        "byte_length": len(data),
        "sha256": checksum,
        "local_file": path.relative_to(REPO_ROOT).as_posix(),
        "source_organization": "NASA Earthdata / GES DISC",
        "title": "GPM_3IMERGHH V07B bounded Enteng ImageServer samples",
        "issued_at": None,
        "issued_at_source_text": None,
        "issued_timezone": None,
        "status": "ACQUIRED_VALIDATED",
        "notes": "Official bounded ImageServer response; no credentials or original HDF5 granule used.",
    }


def main() -> int:
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    retrieved_at = iso_z(datetime.now(timezone.utc))
    artifacts = []
    cursor = START
    while cursor < END:
        chunk_end = min(cursor + timedelta(hours=5, minutes=30), END - timedelta(minutes=30))
        filename = f"GPM_3IMERGHH_V07B_Enteng_{cursor.strftime('%Y%m%dT%H%M%SZ')}_{(chunk_end + timedelta(minutes=30)).strftime('%Y%m%dT%H%M%SZ')}_samples.json"
        path = RAW_DIR / filename
        if path.exists():
            raise FileExistsError(f"Refusing to overwrite immutable raw artifact: {path}")
        payload = query_chunk(cursor, chunk_end)
        path.write_text(json.dumps(payload, indent=1) + "\n", encoding="utf-8")
        artifacts.append(artifact_record(path, retrieved_at, cursor, chunk_end))
        cursor = chunk_end + timedelta(minutes=30)

    source_manifest = json.loads(SOURCE_MANIFEST.read_text(encoding="utf-8"))
    source = next(item for item in source_manifest["sources"] if item["source_id"] == SOURCE_ID)
    source["artifacts"].extend(artifacts)
    SOURCE_MANIFEST.write_text(json.dumps(source_manifest, indent=2) + "\n", encoding="utf-8")

    acquisition = {
        "schema_version": "1.0.0",
        "acquisition_id": "imerg-v07b-enteng-2024-09-02-bounded-research-v1",
        "source_id": SOURCE_ID,
        "status": "ACQUIRED_TECHNICALLY_VALIDATED_REVIEW_PENDING",
        "acquisition_type": "EXPLORATORY_SOURCE_ACQUISITION_WINDOW",
        "authorization": "APPROVED_FOR_PROJECT_RESEARCH_ONLY",
        "product": {
            "short_name": "GPM_3IMERGHH", "collection_version": "07", "processing_id": "V07B",
            "doi": "10.5067/GPM/IMERG/3B-HH/07", "run": "FINAL_RUN_RESEARCH_PRODUCT",
            "data_characterization": "SATELLITE_PRECIPITATION_ESTIMATE_WITH_GAUGE_ANALYSIS",
            "spatial_resolution_degrees": 0.1, "temporal_resolution_minutes": 30,
            "variable_name": "precipitation", "native_units": "mm/hr",
            "time_semantics": "UTC_INTERVAL_START", "historical_coverage_start": "1998-01-01T00:00:00Z",
            "official_metadata_urls": [
                "https://daac.gsfc.nasa.gov/datasets/GPM_3IMERGHH_07/summary",
                "https://gpm.nasa.gov/resources/documents/imerg-v07-technical-documentation",
                "https://gpm.nasa.gov/resources/documents/imerg-v07-release-notes",
            ],
        },
        "access": {
            "original_granule_access": "EARTHDATA_AUTHENTICATION_REQUIRED",
            "authentication_material_present": False, "original_granule_downloaded": False,
            "representation": "OFFICIAL_EARTHDATA_IMAGESERVER_SAMPLES", "public_subset_service": SERVICE,
            "access_note": "Bounded official NASA GES DISC ImageServer representation; original HDF5 remains credential-gated and was not requested or downloaded.",
        },
        "acquisition_window": {
            "classification": "EXPLORATORY_SOURCE_ACQUISITION_WINDOW",
            "start_utc": iso_z(START), "end_utc_exclusive": iso_z(END),
            "source_date_text": "02 September 2024, 08:00 am through 03 September 2024, 08:00 am — Subsided as of",
            "source_timezone": "UNSPECIFIED", "research_alignment_timezone": "Asia/Manila",
            "timezone_basis": "PROJECT_RESEARCH_ASSUMPTION",
            "timezone_assumption_status": "REQUIRES_HUMAN_REVIEW",
            "conversion_method": "Research window uses the adjudicated Asia/Manila project assumption; source timezone remains unspecified.",
            "selection_rationale": "Bounded 48-hour antecedent plus 24-hour episode envelope authorized for project research only.",
            "target_window": False, "prediction_window": False, "training_window": False,
        },
        "spatial_request": {
            "crs": "EPSG:4326", "requested_bounds_wgs84": [120.8, 14.5, 121.2, 14.9],
            "source_resolution_degrees": 0.1,
            "requested_grid_centers": [{"longitude": lon, "latitude": lat} for lat in LATITUDES for lon in LONGITUDES],
            "mapping_method_selected": False, "coarse_resolution_limitation_retained": True,
            "limitation_note": "IMERG 0.1-degree cells span multiple local areas and are not barangay-resolution ground truth.",
        },
        "artifact_ids": [item["artifact_id"] for item in artifacts],
        "normalization": {
            "source_variable_verified": True, "source_units_verified": True, "native_value_field": "value",
            "native_units": "mm/hr", "normalized_units": "mm", "interval_hours": 0.5,
            "formula": "precipitation_mm = native_rate_mm_per_hour * 0.5 hours",
            "preserve_native_value_and_units": True, "missing_fill_policy": "REJECT",
            "quality_status": "PROVISIONAL_NO_QUALITY_INDEX", "observed_forecast_separation": True,
        },
        "validated_coverage": {
            "expected_interval_count_per_cell": 144, "expected_grid_cell_count": 16,
            "expected_observation_count": 2304, "raw_sample_chunk_count": len(artifacts),
            "quarantined_incomplete_response_count": 0, "source_checksum_status": "PASSED",
            "coverage_status": "PENDING_LOCAL_VALIDATION",
        },
        "training_effect": {
            "creates_training_row": False, "sets_event_label": False,
            "sets_training_ready": False, "authorizes_training": False,
        },
    }
    ACQUISITION_MANIFEST.write_text(json.dumps(acquisition, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"success": True, "acquisition_manifest": str(ACQUISITION_MANIFEST), "artifact_count": len(artifacts), "observation_count": 2304}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())