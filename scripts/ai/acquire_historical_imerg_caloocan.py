#!/usr/bin/env python3
"""Acquire the bounded three-cell historical IMERG research corpus."""

from __future__ import annotations

import hashlib
import json
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
from pathlib import Path
from time import sleep
from urllib.parse import urlencode
from urllib.request import urlopen

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_acquisition_common import analyze_caloocan_imerg_grid  # noqa: E402


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
RAW_DIR = WORKSPACE / "data" / "raw" / "precipitation"
SOURCE_MANIFEST = WORKSPACE / "manifests" / "source-manifest.json"
ENTENG_MANIFEST = WORKSPACE / "manifests" / "imerg-enteng-2024-exploratory-acquisition.json"
LEDGER = WORKSPACE / "manifests" / "imerg-caloocan-historical-2023-2024-acquisition.json"
SERVICE = "https://gis.earthdata.nasa.gov/portal/rest/services/GESDISC/GPM_3IMERGHH/ImageServer/getSamples"
SOURCE_ID = "nasa_gpm_imerg_final_hh_v07"
START = datetime(2023, 9, 1, tzinfo=timezone.utc)
END = datetime(2024, 9, 3, tzinfo=timezone.utc)
BATCH_HOURS = 10
INTERVAL = timedelta(minutes=30)


def iso_z(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def governed_centers() -> tuple[dict, ...]:
    acquisition = json.loads(ENTENG_MANIFEST.read_text(encoding="utf-8"))
    analysis = analyze_caloocan_imerg_grid(acquisition)
    cells = tuple(cell for cell in analysis["cells"] if cell["intersects_caloocan"])
    if len(cells) != 3:
        raise RuntimeError(f"Expected exactly three Caloocan-intersecting cells, found {len(cells)}")
    return tuple({"longitude": cell["center"]["longitude"], "latitude": cell["center"]["latitude"], "grid_cell_id": cell["grid_cell_id"]} for cell in cells)


def query_batch(start: datetime, end_inclusive: datetime, centers: tuple[dict, ...]) -> dict:
    points = [[item["longitude"], item["latitude"]] for item in centers]
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
    url = f"{SERVICE}?{urlencode(params)}"
    last_error = None
    for attempt in range(3):
        try:
            with urlopen(url, timeout=180) as response:
                payload = json.load(response)
            if not isinstance(payload.get("samples"), list):
                raise RuntimeError("ImageServer response has no samples array")
            return payload
        except Exception as exc:  # bounded retry for transient service failures
            last_error = exc
            if attempt < 2:
                sleep(2 ** attempt)
    raise RuntimeError(f"Historical IMERG batch failed after retries: {last_error}")


def expected_samples(start: datetime, end: datetime) -> int:
    return int((end - start).total_seconds() / INTERVAL.total_seconds()) * 3


def validate_batch(path: Path, start: datetime, end: datetime, centers: tuple[dict, ...]) -> tuple[bool, str]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        samples = payload.get("samples")
        if not isinstance(samples, list) or len(samples) != expected_samples(start, end):
            return False, "unexpected_sample_count"
        expected_cells = {item["grid_cell_id"] for item in centers}
        seen = set()
        cursor = start
        while cursor < end:
            timestamp = int(cursor.timestamp() * 1000)
            for center in centers:
                seen.add((center["grid_cell_id"], timestamp))
            cursor += INTERVAL
        actual = set()
        for sample in samples:
            location = sample["location"]
            cell = f"GPM_3IMERGHH_V07B:lat={float(location['y']):.2f}:lon={float(location['x']):.2f}"
            actual.add((cell, int(sample["attributes"]["stdtime"])))
            if sample.get("attributes", {}).get("variable") != "precipitation":
                return False, "wrong_variable"
            value = float(sample["value"])
            if value < 0:
                return False, "invalid_value"
        if actual != seen or not {item[0] for item in actual}.issubset(expected_cells):
            return False, "missing_or_unexpected_interval"
        return True, "validated"
    except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError):
        return False, "corrupt_json"


def artifact(path: Path, retrieved_at: str, start: datetime, end: datetime) -> dict:
    data = path.read_bytes()
    return {
        "artifact_id": f"imerg_v07b_caloocan_{start.strftime('%Y%m%dt%H%M')}_{end.strftime('%Y%m%dt%H%M')}",
        "artifact_role": "IMAGESERVER_SAMPLES",
        "original_filename": path.name,
        "official_url": SERVICE,
        "retrieved_at": retrieved_at,
        "media_type": "application/json",
        "byte_length": len(data),
        "sha256": hashlib.sha256(data).hexdigest(),
        "local_file": path.relative_to(REPO_ROOT).as_posix(),
        "source_organization": "NASA Earthdata / GES DISC",
        "title": "GPM_3IMERGHH V07B bounded three-cell Caloocan historical samples",
        "issued_at": None,
        "issued_at_source_text": None,
        "issued_timezone": None,
        "status": "ACQUIRED_VALIDATED",
        "notes": "Ten-hour bounded batch derived from the governed three Caloocan-intersecting cells; no credentials or HDF5 granules used.",
    }


def main() -> int:
    centers = governed_centers()
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    retrieved_at = iso_z(datetime.now(timezone.utc))
    batch_specs = []
    cursor = START
    while cursor < END:
        batch_end = min(cursor + timedelta(hours=BATCH_HOURS), END)
        filename = f"GPM_3IMERGHH_V07B_Caloocan3_{cursor.strftime('%Y%m%dT%H%M%SZ')}_{batch_end.strftime('%Y%m%dT%H%M%SZ')}_samples.json"
        batch_specs.append((cursor, batch_end, RAW_DIR / filename))
        cursor = batch_end

    def process_batch(spec: tuple[datetime, datetime, Path]) -> dict:
        batch_start, batch_end, path = spec
        valid = path.is_file() and validate_batch(path, batch_start, batch_end, centers)[0]
        if not valid:
            payload = query_batch(batch_start, batch_end - INTERVAL, centers)
            path.write_text(json.dumps(payload, indent=1) + "\n", encoding="utf-8")
            valid, reason = validate_batch(path, batch_start, batch_end, centers)
            if not valid:
                raise RuntimeError(f"Batch failed local validation: {path.name}: {reason}")
        return {"start_utc": iso_z(batch_start), "end_utc_exclusive": iso_z(batch_end), "artifact": artifact(path, retrieved_at, batch_start, batch_end)}

    with ThreadPoolExecutor(max_workers=4) as executor:
        batches = list(executor.map(process_batch, batch_specs))
    batches.sort(key=lambda item: item["start_utc"])

    manifest = json.loads(SOURCE_MANIFEST.read_text(encoding="utf-8"))
    source = next(item for item in manifest["sources"] if item["source_id"] == SOURCE_ID)
    prefix = "imerg_v07b_caloocan_"
    source["artifacts"] = [item for item in source.get("artifacts", []) if not str(item.get("artifact_id", "")).startswith(prefix)]
    source["artifacts"].extend(batch["artifact"] for batch in batches)
    SOURCE_MANIFEST.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    ledger = {
        "schema_version": "1.0.0",
        "acquisition_id": "imerg-v07b-caloocan-2023-09-01-through-2024-09-03-three-cell-v1",
        "source_id": SOURCE_ID,
        "status": "ACQUIRED_TECHNICALLY_VALIDATED_REVIEW_PENDING",
        "authorization": "APPROVED_FOR_PROJECT_RESEARCH_ONLY",
        "product": {"short_name": "GPM_3IMERGHH", "collection_version": "07", "processing_id": "V07B", "native_units": "mm/hr", "interval_minutes": 30, "resolution_degrees": 0.1},
        "window": {"start_utc": iso_z(START), "end_utc_exclusive": iso_z(END), "scope_not_expanded": True},
        "spatial_scope": {"crs": "EPSG:4326", "method": "CALOOCAN_INTERSECTING_CELL_GEOMETRY", "boundary_source": "data/import/caloocan-city-boundary.geojson", "boundary_sha256": "9647f3cac1758a07cfdc6a5bb8767fe9e4f1eb70b4e7d2c14a99abf2de1f9d50", "centers": list(centers), "count": 3, "area_weighting": False},
        "batch_strategy": {"batch_hours": BATCH_HOURS, "batch_count": len(batches), "expected_samples_per_full_batch": BATCH_HOURS * 2 * 3, "reason": "The official ImageServer returned only 20 time slices per point for a larger request; ten-hour batches request exactly 20 half-hour intervals per each of the three centers and are validated fail-closed."},
        "batches": batches,
        "raw_observation_count": len(batches) and sum(expected_samples(datetime.fromisoformat(item["start_utc"].replace("Z", "+00:00")), datetime.fromisoformat(item["end_utc_exclusive"].replace("Z", "+00:00"))) for item in batches),
        "training_effect": {"creates_training_rows": False, "creates_flood_labels": False, "training_ready": False, "training_authorization": "NOT_APPROVED"},
    }
    LEDGER.write_text(json.dumps(ledger, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"success": True, "batch_count": len(batches), "raw_observation_count": ledger["raw_observation_count"], "centers": list(centers)}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())