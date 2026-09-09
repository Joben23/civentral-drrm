#!/usr/bin/env python3
"""Validate Phase 3B2 evidence and bounded real IMERG acquisition."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from flood_acquisition_common import (
    DEFAULT_EVIDENCE_EXTRACTION,
    DEFAULT_IMERG_ACQUISITION,
    DEFAULT_SOURCE_MANIFEST,
    DROMIC_SOURCE_ID,
    IMERG_SOURCE_ID,
    analyze_caloocan_imerg_grid,
    normalize_imerg_samples,
    precipitation_coverage_report,
    validate_evidence_extraction,
    validate_imerg_acquisition,
)
from flood_data_common import read_json, validate_manifest


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-manifest", type=Path, default=DEFAULT_SOURCE_MANIFEST)
    parser.add_argument("--evidence-extraction", type=Path, default=DEFAULT_EVIDENCE_EXTRACTION)
    parser.add_argument("--imerg-acquisition", type=Path, default=DEFAULT_IMERG_ACQUISITION)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    manifest = read_json(args.source_manifest)
    sources = {source["source_id"]: source for source in manifest.get("sources", [])}
    issues = [
        {"code": issue.code, "subject_id": issue.record_id, "message": issue.message}
        for issue in validate_manifest(manifest)
    ]
    extraction = read_json(args.evidence_extraction)
    acquisition = read_json(args.imerg_acquisition)
    issues.extend(issue.__dict__ for issue in validate_evidence_extraction(extraction, sources.get(DROMIC_SOURCE_ID, {})))
    issues.extend(issue.__dict__ for issue in validate_imerg_acquisition(acquisition, sources.get(IMERG_SOURCE_ID, {})))
    coverage = None
    spatial = None
    if not issues:
        precipitation = normalize_imerg_samples(acquisition, sources[IMERG_SOURCE_ID])
        coverage = precipitation_coverage_report(precipitation, acquisition)
        spatial = analyze_caloocan_imerg_grid(acquisition)
        expected = acquisition["validated_coverage"]
        comparisons = {
            "expected_interval_count_per_cell": coverage["expected_interval_count_per_cell"],
            "expected_grid_cell_count": coverage["spatial_cell_count"],
            "expected_observation_count": coverage["actual_observation_count"],
        }
        for field, actual in comparisons.items():
            if expected.get(field) != actual:
                issues.append({"code": "COVERAGE_MANIFEST_MISMATCH", "subject_id": acquisition["acquisition_id"], "message": f"{field}: manifest={expected.get(field)!r}, actual={actual!r}"})
        if not coverage["complete"]:
            issues.append({"code": "INCOMPLETE_IMERG_COVERAGE", "subject_id": acquisition["acquisition_id"], "message": "Real sample coverage is incomplete or invalid."})
    result = {
        "success": not issues,
        "phase": "TENSORFLOW_PHASE_3B2_ACQUISITION_VALIDATION",
        "issues": issues,
        "dromic": {
            "source_status": sources.get(DROMIC_SOURCE_ID, {}).get("status"),
            "label_status": extraction.get("label_status"),
            "review_status": extraction.get("review_status"),
            "training_eligible": extraction.get("training_eligible"),
        },
        "imerg": {
            "source_status": sources.get(IMERG_SOURCE_ID, {}).get("status"),
            "source_approval": sources.get(IMERG_SOURCE_ID, {}).get("license_or_usage_status"),
            "acquisition_type": acquisition.get("acquisition_type"),
            "coverage": coverage,
            "spatial_analysis": spatial,
        },
        "training_effect": acquisition.get("training_effect"),
        "tensorflow_training_performed": False,
        "model_artifact_created": False,
    }
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if not issues else 1


if __name__ == "__main__":
    sys.exit(main())
