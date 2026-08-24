#!/usr/bin/env python3
"""Validate canonical CIVENTRAL flood-risk records before training."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import (
    DEFAULT_BARANGAYS,
    DEFAULT_MANIFEST,
    DEFAULT_REVIEWED_DATA,
    REPO_ROOT,
    load_barangay_reference,
    load_manifest,
    load_records,
    summarize_issues,
    validate_manifest,
    validate_records,
    write_json,
)


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--input", type=Path, default=DEFAULT_REVIEWED_DATA)
    result.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--barangays", type=Path, default=DEFAULT_BARANGAYS)
    result.add_argument("--mode", choices=("canonical", "reviewed"), default="reviewed")
    result.add_argument("--report", type=Path, help="Optional JSON validation report path.")
    result.add_argument("--quarantine", type=Path, help="Optional JSON file for invalid rows and reasons.")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        records = load_records(args.input)
        manifest, sources = load_manifest(args.manifest)
        barangays = load_barangay_reference(args.barangays)
    except (OSError, ValueError) as exc:
        print(f"VALIDATION_ERROR: {exc}", file=sys.stderr)
        return 2

    issues = validate_manifest(manifest, repo_root=REPO_ROOT)
    issues.extend(validate_records(records, sources, barangays, mode=args.mode))
    issue_rows = [issue.as_dict() for issue in issues]
    report = {
        "schema_version": "1.0.0",
        "mode": args.mode,
        "input": str(args.input),
        "record_count": len(records),
        "valid": not issues,
        "issue_count": len(issues),
        "issues_by_code": summarize_issues(issues),
        "issues": issue_rows,
        "note": "Counts describe the selected input only. Test fixtures are not real observations.",
    }
    if args.report:
        write_json(args.report, report)
    if args.quarantine:
        bad_locations = {(issue.source_file, issue.row_number) for issue in issues if issue.source_file != "manifest"}
        quarantined = [
            {
                "source_file": loaded.source_file,
                "row_number": loaded.row_number,
                "record": loaded.data,
                "reasons": [
                    issue.as_dict() for issue in issues
                    if issue.source_file == loaded.source_file and issue.row_number == loaded.row_number
                ],
            }
            for loaded in records
            if (loaded.source_file, loaded.row_number) in bad_locations
        ]
        write_json(args.quarantine, {"training_use_prohibited": True, "quarantined": quarantined})

    print(f"RECORDS = {len(records)}")
    print(f"VALID = {str(not issues).lower()}")
    print(f"ISSUES = {len(issues)}")
    for code, count in summarize_issues(issues).items():
        print(f"{code} = {count}")
    return 0 if not issues else 1


if __name__ == "__main__":
    raise SystemExit(main())
