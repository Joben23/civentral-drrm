#!/usr/bin/env python3
"""Create reviewed provisional observations from verified immutable IMERG samples."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from flood_acquisition_common import (
    DEFAULT_IMERG_ACQUISITION,
    DEFAULT_REVIEWED_PRECIPITATION,
    DEFAULT_SOURCE_MANIFEST,
    IMERG_SOURCE_ID,
    normalize_imerg_samples,
    precipitation_coverage_report,
    write_json_atomic,
)
from flood_data_common import read_json


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-manifest", type=Path, default=DEFAULT_SOURCE_MANIFEST)
    parser.add_argument("--imerg-acquisition", type=Path, default=DEFAULT_IMERG_ACQUISITION)
    parser.add_argument("--output", type=Path, default=DEFAULT_REVIEWED_PRECIPITATION)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    manifest = read_json(args.source_manifest)
    source = next((item for item in manifest.get("sources", []) if item.get("source_id") == IMERG_SOURCE_ID), None)
    if source is None:
        raise ValueError(f"Missing governed source: {IMERG_SOURCE_ID}")
    acquisition = read_json(args.imerg_acquisition)
    precipitation = normalize_imerg_samples(acquisition, source)
    coverage = precipitation_coverage_report(precipitation, acquisition)
    if not coverage["complete"]:
        raise ValueError("Refusing reviewed output because the bounded exploratory acquisition is incomplete.")
    write_json_atomic(args.output, precipitation)
    print(json.dumps({
        "success": True,
        "output": str(args.output),
        "dataset_classification": precipitation["dataset_classification"],
        "quality_flag": "PROVISIONAL",
        "training_row_created": False,
        "event_label_changed": False,
        "coverage": coverage,
    }, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
