#!/usr/bin/env python3
"""Build a fail-closed, non-authorized flood pilot candidate dataset."""

from __future__ import annotations

import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import write_json
from pilot_data_common import (
    DEFAULT_EVENT_REGISTRY,
    DEFAULT_LABEL_PROTOCOL,
    DEFAULT_MANIFEST,
    DEFAULT_PILOT_MANIFEST,
    DEFAULT_PILOT_OUTPUT,
    DEFAULT_PRECIPITATION_DATA,
    build_pilot_dataset,
    load_pilot_inputs,
    write_pilot_csv,
)


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--events", type=Path, default=DEFAULT_EVENT_REGISTRY)
    result.add_argument("--precipitation", type=Path, default=DEFAULT_PRECIPITATION_DATA)
    result.add_argument("--sources", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--protocol", type=Path, default=DEFAULT_LABEL_PROTOCOL)
    result.add_argument("--output", type=Path, default=DEFAULT_PILOT_OUTPUT)
    result.add_argument("--dataset-manifest", type=Path, default=DEFAULT_PILOT_MANIFEST)
    result.add_argument("--dataset-version", default="pilot-v1")
    result.add_argument("--created-at", help="ISO-8601 creation time; provide explicitly for byte-for-byte reproducibility.")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    created_at = args.created_at or datetime.now(timezone.utc).isoformat()
    try:
        registry, precipitation, manifest, sources, protocol = load_pilot_inputs(
            args.events, args.precipitation, args.sources, args.protocol
        )
        rows, report = build_pilot_dataset(
            registry,
            precipitation,
            manifest,
            sources,
            protocol,
            dataset_version=args.dataset_version,
            created_at=created_at,
        )
        write_pilot_csv(args.output, rows)
        write_json(args.dataset_manifest, report)
    except (OSError, TypeError, ValueError) as exc:
        print(f"PILOT_BUILD_ERROR: {exc}", file=sys.stderr)
        return 2
    print(f"PILOT_RECORDS = {report['record_count']}")
    print(f"EXCLUDED_RECORDS = {report['excluded_record_count']}")
    print(f"TRAINING_AUTHORIZATION = {report['training_authorization_status']}")
    print(f"OUTPUT = {args.output}")
    print(f"MANIFEST = {args.dataset_manifest}")
    print("TENSORFLOW_TRAINING_PERFORMED = false")
    return 0 if rows else 3


if __name__ == "__main__":
    raise SystemExit(main())
