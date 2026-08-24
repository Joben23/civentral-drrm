#!/usr/bin/env python3
"""Resolve a validated Caloocan point to barangay and DENR-MGB feature code."""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path
from typing import Any, Dict, Iterable, List, Sequence, Tuple

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import DEFAULT_BARANGAYS, REPO_ROOT, read_json


DEFAULT_CITY = REPO_ROOT / "data" / "import" / "caloocan-city-boundary.geojson"
DEFAULT_MGB = REPO_ROOT / "data" / "import" / "caloocan-mgb-flood-susceptibility.geojson"
SUSCEPTIBILITY_PRIORITY = {"NONE": 0, "LF": 1, "MF": 2, "HF": 3, "VHF": 4}


def point_on_segment(point: Tuple[float, float], start: Sequence[float], end: Sequence[float]) -> bool:
    x, y = point
    x1, y1 = float(start[0]), float(start[1])
    x2, y2 = float(end[0]), float(end[1])
    cross = (x - x1) * (y2 - y1) - (y - y1) * (x2 - x1)
    if not math.isclose(cross, 0.0, abs_tol=1e-12):
        return False
    return min(x1, x2) - 1e-12 <= x <= max(x1, x2) + 1e-12 and min(y1, y2) - 1e-12 <= y <= max(y1, y2) + 1e-12


def point_in_ring(point: Tuple[float, float], ring: Sequence[Sequence[float]]) -> bool:
    if len(ring) < 4:
        return False
    inside = False
    x, y = point
    previous = ring[-1]
    for current in ring:
        if point_on_segment(point, previous, current):
            return True
        x1, y1 = float(previous[0]), float(previous[1])
        x2, y2 = float(current[0]), float(current[1])
        intersects = (y1 > y) != (y2 > y)
        if intersects:
            crossing_x = (x2 - x1) * (y - y1) / (y2 - y1) + x1
            if x < crossing_x:
                inside = not inside
        previous = current
    return inside


def point_in_polygon(point: Tuple[float, float], rings: Sequence[Sequence[Sequence[float]]]) -> bool:
    if not rings or not point_in_ring(point, rings[0]):
        return False
    return not any(point_in_ring(point, hole) for hole in rings[1:])


def geometry_contains(geometry: Dict[str, Any], point: Tuple[float, float]) -> bool:
    geometry_type = geometry.get("type")
    coordinates = geometry.get("coordinates")
    if geometry_type == "Polygon" and isinstance(coordinates, list):
        return point_in_polygon(point, coordinates)
    if geometry_type == "MultiPolygon" and isinstance(coordinates, list):
        return any(point_in_polygon(point, polygon) for polygon in coordinates)
    return False


def containing_features(feature_collection: Dict[str, Any], point: Tuple[float, float]) -> List[Dict[str, Any]]:
    features = feature_collection.get("features", [])
    if not isinstance(features, list):
        raise ValueError("Expected a GeoJSON FeatureCollection.")
    return [
        feature for feature in features
        if isinstance(feature, dict) and isinstance(feature.get("geometry"), dict)
        and geometry_contains(feature["geometry"], point)
    ]


def resolve_point(
    longitude: float,
    latitude: float,
    city_path: Path = DEFAULT_CITY,
    barangay_path: Path = DEFAULT_BARANGAYS,
    mgb_path: Path = DEFAULT_MGB,
    expected_barangay_psgc: str | None = None,
) -> Dict[str, Any]:
    if not (-180 <= longitude <= 180 and -90 <= latitude <= 90):
        raise ValueError("Longitude/latitude are outside valid coordinate ranges.")
    point = (longitude, latitude)
    city = read_json(city_path)
    barangays = read_json(barangay_path)
    mgb = read_json(mgb_path)
    if not containing_features(city, point):
        return {
            "status": "OUTSIDE_CALOOCAN",
            "spatial_mapping_status": "UNKNOWN_SPATIAL_MAPPING",
            "mgb_flood_susceptibility_code": None,
        }
    matches = containing_features(barangays, point)
    if not matches:
        return {
            "status": "UNKNOWN_SPATIAL_MAPPING",
            "spatial_mapping_status": "UNKNOWN_SPATIAL_MAPPING",
            "reason": "Point is within the city boundary but not within the validated 187 unaffected barangay geometries; the Barangay 176 split area is intentionally unresolved.",
            "mgb_flood_susceptibility_code": None,
        }
    if len(matches) != 1:
        raise ValueError(f"Point matched {len(matches)} barangay polygons; boundary ambiguity requires human review.")
    properties = matches[0].get("properties", {})
    psgc = properties.get("current_psgc_10_digit")
    name = properties.get("current_barangay_name")
    legacy_source_code = properties.get("adm4_pcode")
    if expected_barangay_psgc and expected_barangay_psgc != psgc:
        raise ValueError(f"Point resolved to {psgc}, not expected barangay {expected_barangay_psgc}.")
    flood_matches = containing_features(mgb, point)
    codes = []
    for feature in flood_matches:
        code = feature.get("properties", {}).get("mgb_flood_code")
        if code not in SUSCEPTIBILITY_PRIORITY:
            raise ValueError(f"MGB feature contains unsupported susceptibility code: {code!r}")
        codes.append(code)
    selected = max(codes, key=SUSCEPTIBILITY_PRIORITY.get) if codes else "NONE"
    return {
        "status": "RESOLVED",
        "spatial_mapping_status": "VALIDATED",
        "barangay_psgc": psgc,
        "barangay_name": name,
        "legacy_geometry_source_code": legacy_source_code,
        "mgb_flood_susceptibility_code": selected,
        "overlapping_mgb_codes": sorted(set(codes), key=SUSCEPTIBILITY_PRIORITY.get),
        "resolution_method": "POINT_IN_POLYGON_HIGHEST_OVERLAPPING_MGB_CLASS",
        "sources": {
            "barangays": str(barangay_path.relative_to(REPO_ROOT)),
            "susceptibility": str(mgb_path.relative_to(REPO_ROOT)),
        },
        "warning": "DENR-MGB susceptibility is a static feature, never the flood outcome label.",
    }


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--longitude", type=float, required=True)
    result.add_argument("--latitude", type=float, required=True)
    result.add_argument("--expected-barangay-psgc")
    result.add_argument("--city", type=Path, default=DEFAULT_CITY)
    result.add_argument("--barangays", type=Path, default=DEFAULT_BARANGAYS)
    result.add_argument("--mgb", type=Path, default=DEFAULT_MGB)
    return result


def main(argv: List[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        result = resolve_point(
            args.longitude,
            args.latitude,
            city_path=args.city,
            barangay_path=args.barangays,
            mgb_path=args.mgb,
            expected_barangay_psgc=args.expected_barangay_psgc,
        )
    except (OSError, ValueError) as exc:
        print(f"RESOLUTION_ERROR: {exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, indent=2, ensure_ascii=False, sort_keys=True))
    return 0 if result["status"] == "RESOLVED" else 1


if __name__ == "__main__":
    raise SystemExit(main())
