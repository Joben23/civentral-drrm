#!/usr/bin/env python3
"""Report whether governed flood data may advance to TensorFlow Phase 7C."""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from pathlib import Path
from typing import Any, Dict, List

SCRIPT_DIR = Path(__file__).resolve().parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import (
    DEFAULT_AUTHORIZATION,
    DEFAULT_BARANGAYS,
    DEFAULT_GOVERNANCE,
    DEFAULT_MANIFEST,
    DEFAULT_REVIEWED_DATA,
    REQUIRED_FEATURES,
    REPO_ROOT,
    TRAINING_LABELS,
    load_barangay_reference,
    load_manifest,
    load_records,
    parse_iso_datetime,
    summarize_issues,
    training_eligible,
    validate_manifest,
    validate_records,
    write_json,
    read_json,
)


def build_readiness_report(
    records,
    manifest,
    sources,
    barangays,
    governance,
    authorization,
    counts_are_real_observations=True,
) -> Dict[str, Any]:
    canonical_issues = validate_manifest(manifest, repo_root=REPO_ROOT)
    canonical_issues.extend(validate_records(records, sources, barangays, mode="canonical"))
    training_records = [record for record in records if record.data.get("label_status") in tuple(TRAINING_LABELS)]
    reviewed_issues = validate_records(training_records, sources, barangays, mode="reviewed")
    all_issues = canonical_issues + reviewed_issues
    eligible = [record for record in training_records if training_eligible(record.data)]

    positives = [record for record in eligible if record.data.get("label_status") == "VERIFIED_FLOOD_OBSERVED"]
    negatives = [record for record in eligible if record.data.get("label_status") == "VERIFIED_NO_FLOOD_OBSERVED"]
    events = {record.data.get("event_id") for record in eligible if record.data.get("event_id")}
    positive_events = {record.data.get("event_id") for record in positives if record.data.get("event_id")}
    negative_events = {record.data.get("event_id") for record in negatives if record.data.get("event_id")}
    reviewed_count = sum(1 for record in records if record.data.get("review_status") == "REVIEWED")

    missing_counts = {
        feature: sum(1 for record in training_records if record.data.get(feature) is None)
        for feature in REQUIRED_FEATURES
    }
    valid_times = [
        parsed for parsed in (parse_iso_datetime(record.data.get("valid_from")) for record in eligible)
        if parsed is not None
    ]
    date_range = {
        "start": min(valid_times).isoformat() if valid_times else None,
        "end": max(valid_times).isoformat() if valid_times else None,
    }
    barangay_codes = sorted({
        str(record.data.get("barangay_psgc")) for record in eligible if record.data.get("barangay_psgc")
    })
    source_usage = Counter(
        source_id
        for record in eligible
        for source_id in (
            record.data.get("flood_evidence_source"),
            record.data.get("weather_source"),
            record.data.get("susceptibility_source"),
        )
        if source_id
    )

    policy = governance.get("readiness", {})
    required_events = int(policy.get("minimum_independent_weather_events", 3))
    required_positive_events = int(policy.get("minimum_positive_events", 2))
    required_negative_events = int(policy.get("minimum_negative_events", 2))
    authorization_approved = authorization.get("status") == "APPROVED_FOR_PHASE_7C_DATA_USE"
    gates = {
        "real_records_present": len(records) > 0,
        "both_verified_labels_present": bool(positives) and bool(negatives),
        "multiple_independent_events": len(events) >= required_events,
        "positive_event_representation": len(positive_events) >= required_positive_events,
        "negative_event_representation": len(negative_events) >= required_negative_events,
        "required_features_complete": all(count == 0 for count in missing_counts.values()) and bool(training_records),
        "all_training_labels_reviewed": bool(training_records) and len(eligible) == len(training_records),
        "approved_provenance": bool(eligible) and all(
            sources.get(source_id, {}).get("review_status") == "APPROVED_FOR_GOVERNED_USE"
            for source_id in source_usage
        ),
        "no_validation_or_duplicate_errors": not all_issues,
        "no_unresolved_leakage_issues": bool(eligible) and all(
            record.data.get("leakage_review_status") == "PASSED" for record in eligible
        ),
        "data_governance_authorized_for_phase_7c": authorization_approved,
    }
    training_ready = all(gates.values())
    label_total = len(positives) + len(negatives)
    return {
        "report_version": "1.0.0",
        "prediction_type": "FLOOD_WITHIN_24H",
        "training_ready": training_ready,
        "training_ready_display": f"TRAINING_READY = {str(training_ready).lower()}",
        "counts_are_real_observations": counts_are_real_observations,
        "total_input_observations": len(records),
        "total_reviewed_observations": reviewed_count,
        "training_eligible_observations": len(eligible),
        "verified_positives": len(positives),
        "verified_negatives": len(negatives),
        "unique_weather_events": len(events),
        "positive_weather_events": len(positive_events),
        "negative_weather_events": len(negative_events),
        "date_range": date_range,
        "missing_feature_counts": missing_counts,
        "label_balance": {
            "positive_fraction": len(positives) / label_total if label_total else None,
            "negative_fraction": len(negatives) / label_total if label_total else None,
        },
        "spatial_coverage": {
            "unique_validated_barangays": len(barangay_codes),
            "barangay_identifiers": barangay_codes,
            "validated_reference_size": len(barangays),
        },
        "provenance_source_usage": dict(sorted(source_usage.items())),
        "gates": gates,
        "mechanical_requirements": {
            "minimum_independent_weather_events": required_events,
            "minimum_positive_events": required_positive_events,
            "minimum_negative_events": required_negative_events,
        },
        "authorization": {
            "status": authorization.get("status"),
            "decision_reference": authorization.get("decision_reference"),
        },
        "issue_count": len(all_issues),
        "issues_by_code": summarize_issues(all_issues),
        "issues": [issue.as_dict() for issue in all_issues],
        "caution": "Mechanical gate checks are necessary, not proof of scientific sufficiency, predictive usefulness, or operational safety.",
    }


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--input", type=Path, default=DEFAULT_REVIEWED_DATA)
    result.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--barangays", type=Path, default=DEFAULT_BARANGAYS)
    result.add_argument("--governance", type=Path, default=DEFAULT_GOVERNANCE)
    result.add_argument("--authorization", type=Path, default=DEFAULT_AUTHORIZATION)
    result.add_argument("--report", type=Path, help="Optional JSON report path.")
    return result


def main(argv: List[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        records = load_records(args.input)
        manifest, sources = load_manifest(args.manifest)
        barangays = load_barangay_reference(args.barangays)
        governance = read_json(args.governance)
        authorization = read_json(args.authorization)
    except (OSError, ValueError) as exc:
        print(f"READINESS_ERROR: {exc}", file=sys.stderr)
        return 2
    try:
        args.input.resolve().relative_to(DEFAULT_REVIEWED_DATA.resolve())
        counts_are_real = True
    except ValueError:
        counts_are_real = False
    report = build_readiness_report(
        records,
        manifest,
        sources,
        barangays,
        governance,
        authorization,
        counts_are_real_observations=counts_are_real,
    )
    if args.report:
        write_json(args.report, report)
    print(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=True))
    return 0 if report["training_ready"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
