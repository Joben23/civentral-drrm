#!/usr/bin/env python3
"""Create deterministic Phase 7B features from reviewed real records.

This script performs no TensorFlow training, scaling fit, sampling, imputation,
thresholding, or synthetic-data generation.
"""

from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path
from typing import Any, Dict, List, Mapping, Sequence, Tuple

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import (
    ALLOWED_SUSCEPTIBILITY,
    DEFAULT_BARANGAYS,
    DEFAULT_MANIFEST,
    DEFAULT_REVIEWED_DATA,
    REPO_ROOT,
    WORKSPACE,
    LoadedRecord,
    ValidationIssue,
    load_barangay_reference,
    load_manifest,
    load_records,
    month_components,
    parse_iso_datetime,
    summarize_issues,
    training_eligible,
    validate_manifest,
    validate_records,
    write_json,
)


OUTPUT_FIELDS = (
    "record_id",
    "event_id",
    "barangay_psgc",
    "barangay_name",
    "forecast_issued_at",
    "valid_from",
    "valid_until",
    "forecast_rainfall_24h_mm",
    "antecedent_rainfall_24h_mm",
    "antecedent_rainfall_72h_mm",
    "mgb_susceptibility_LF",
    "mgb_susceptibility_MF",
    "mgb_susceptibility_HF",
    "mgb_susceptibility_VHF",
    "mgb_susceptibility_NONE",
    "month_sin",
    "month_cos",
    "flood_outcome",
    "label_status",
    "flood_evidence_source",
    "weather_source",
    "susceptibility_source",
    "source_document_reference",
)


def prepare_records(
    records: Sequence[LoadedRecord],
    sources: Mapping[str, Mapping[str, Any]],
    barangays: Mapping[str, str],
) -> Tuple[List[Dict[str, Any]], List[Dict[str, Any]], List[ValidationIssue]]:
    canonical_issues = validate_records(records, sources, barangays, mode="canonical")
    if canonical_issues:
        return [], [], canonical_issues

    eligible = [record for record in records if training_eligible(record.data)]
    excluded = [
        {
            "record_id": record.data.get("record_id"),
            "label_status": record.data.get("label_status"),
            "review_status": record.data.get("review_status"),
            "reason": "NOT_TRAINING_ELIGIBLE",
        }
        for record in records
        if not training_eligible(record.data)
    ]
    eligibility_issues = validate_records(eligible, sources, barangays, mode="reviewed")
    if eligibility_issues:
        return [], excluded, eligibility_issues

    prepared: List[Dict[str, Any]] = []
    for loaded in eligible:
        record = loaded.data
        valid_from = parse_iso_datetime(record["valid_from"])
        if valid_from is None:  # guarded by validation; keeps the function fail-closed
            raise ValueError(f"Invalid valid_from after validation for {record.get('record_id')}")
        month_sin, month_cos = month_components(valid_from)
        code = record["mgb_flood_susceptibility_code"]
        if code not in ALLOWED_SUSCEPTIBILITY:
            raise ValueError(f"Invalid susceptibility after validation for {record.get('record_id')}")
        row: Dict[str, Any] = {
            "record_id": record["record_id"],
            "event_id": record["event_id"],
            "barangay_psgc": record["barangay_psgc"],
            "barangay_name": record["barangay_name"],
            "forecast_issued_at": record["forecast_issued_at"],
            "valid_from": record["valid_from"],
            "valid_until": record["valid_until"],
            "forecast_rainfall_24h_mm": record["forecast_rainfall_24h_mm"],
            "antecedent_rainfall_24h_mm": record["antecedent_rainfall_24h_mm"],
            "antecedent_rainfall_72h_mm": record["antecedent_rainfall_72h_mm"],
            "month_sin": format(month_sin, ".12g"),
            "month_cos": format(month_cos, ".12g"),
            "flood_outcome": record["flood_outcome"],
            "label_status": record["label_status"],
            "flood_evidence_source": record["flood_evidence_source"],
            "weather_source": record["weather_source"],
            "susceptibility_source": record["susceptibility_source"],
            "source_document_reference": record["source_document_reference"],
        }
        for susceptibility in ("LF", "MF", "HF", "VHF", "NONE"):
            row[f"mgb_susceptibility_{susceptibility}"] = 1 if code == susceptibility else 0
        prepared.append(row)

    prepared.sort(key=lambda row: (
        str(row["event_id"]), str(row["valid_from"]), str(row["barangay_psgc"]), str(row["record_id"])
    ))
    return prepared, excluded, []


def write_csv(path: Path, rows: Sequence[Mapping[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--input", type=Path, default=DEFAULT_REVIEWED_DATA)
    result.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--barangays", type=Path, default=DEFAULT_BARANGAYS)
    result.add_argument("--output", type=Path, default=WORKSPACE / "data" / "processed" / "flood-training-v1.csv")
    result.add_argument("--report", type=Path, help="Optional preprocessing report path.")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        records = load_records(args.input)
        manifest, sources = load_manifest(args.manifest)
        barangays = load_barangay_reference(args.barangays)
    except (OSError, ValueError) as exc:
        print(f"PREPROCESSING_ERROR: {exc}", file=sys.stderr)
        return 2

    manifest_issues = validate_manifest(manifest, repo_root=REPO_ROOT)
    prepared, excluded, issues = prepare_records(records, sources, barangays)
    issues = manifest_issues + issues
    report = {
        "schema_version": "1.0.0",
        "real_input_record_count": len(records),
        "prepared_record_count": len(prepared),
        "excluded_record_count": len(excluded),
        "exclusions": excluded,
        "issue_count": len(issues),
        "issues_by_code": summarize_issues(issues),
        "issues": [issue.as_dict() for issue in issues],
        "tensorflow_training_performed": False,
        "synthetic_observations_generated": False,
    }
    if args.report:
        write_json(args.report, report)
    if issues:
        print(f"PREPROCESSING_FAILED: {len(issues)} validation issue(s).", file=sys.stderr)
        return 1
    if not prepared:
        print("PREPROCESSING_NOT_RUN: no eligible reviewed real records.", file=sys.stderr)
        return 3
    write_csv(args.output, prepared)
    print(f"PREPARED_RECORDS = {len(prepared)}")
    print(f"OUTPUT = {args.output}")
    print("TENSORFLOW_TRAINING_PERFORMED = false")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
