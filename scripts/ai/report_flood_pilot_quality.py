#!/usr/bin/env python3
"""Report flood pilot data quality without training or approving a dataset."""

from __future__ import annotations

import argparse
import json
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
    DEFAULT_PRECIPITATION_DATA,
    build_pilot_dataset,
    load_pilot_inputs,
)


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--events", type=Path, default=DEFAULT_EVENT_REGISTRY)
    result.add_argument("--precipitation", type=Path, default=DEFAULT_PRECIPITATION_DATA)
    result.add_argument("--sources", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--protocol", type=Path, default=DEFAULT_LABEL_PROTOCOL)
    result.add_argument("--dataset-version", default="pilot-v1-quality-preview")
    result.add_argument("--created-at", help="ISO-8601 report time; provide explicitly for deterministic output.")
    result.add_argument("--report", type=Path, help="Optional JSON output path.")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    created_at = args.created_at or datetime.now(timezone.utc).isoformat()
    try:
        registry, precipitation, manifest, sources, protocol = load_pilot_inputs(
            args.events, args.precipitation, args.sources, args.protocol
        )
        _, report = build_pilot_dataset(
            registry,
            precipitation,
            manifest,
            sources,
            protocol,
            dataset_version=args.dataset_version,
            created_at=created_at,
        )
    except (OSError, TypeError, ValueError) as exc:
        print(f"PILOT_QUALITY_ERROR: {exc}", file=sys.stderr)
        return 2
    if args.report:
        write_json(args.report, report)
    print(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
