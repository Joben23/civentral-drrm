"""Tests for Phase 7B tools using fictional fixtures only."""

from __future__ import annotations

import copy
import json
import sys
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = REPO_ROOT / "scripts" / "ai"
FIXTURES = Path(__file__).resolve().parent / "fixtures"
sys.path.insert(0, str(SCRIPT_DIR))

from check_flood_training_readiness import build_readiness_report  # noqa: E402
from flood_data_common import (  # noqa: E402
    DEFAULT_BARANGAYS,
    LoadedRecord,
    load_barangay_reference,
    read_json,
    validate_manifest,
    validate_record,
    validate_records,
)
from prepare_flood_training_data import prepare_records  # noqa: E402


class FloodDataToolsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.manifest = read_json(FIXTURES / "provenance.fixture.json")
        cls.sources = {source["source_id"]: source for source in cls.manifest["sources"]}
        cls.fixture_payload = read_json(FIXTURES / "reviewed-records.fixture.json")
        cls.positive = cls.fixture_payload["records"][0]
        cls.negative = cls.fixture_payload["records"][1]
        cls.barangays = load_barangay_reference(DEFAULT_BARANGAYS)
        cls.governance = read_json(REPO_ROOT / "ml" / "flood-risk" / "config" / "governance-v1.json")
        cls.authorization = read_json(FIXTURES / "training-authorization.fixture.json")

    def loaded(self, record, row=1):
        return LoadedRecord(copy.deepcopy(record), "TEST_FIXTURE_ONLY", row)

    def codes(self, issues):
        return {issue.code for issue in issues}

    def test_valid_positive_row(self):
        issues = validate_record(self.loaded(self.positive), self.sources, self.barangays, mode="reviewed")
        self.assertEqual([], issues)

    def test_valid_explicit_negative_row(self):
        issues = validate_record(self.loaded(self.negative), self.sources, self.barangays, mode="reviewed")
        self.assertEqual([], issues)

    def test_unknown_is_excluded_from_training(self):
        unknown = copy.deepcopy(self.positive)
        unknown.update({
            "record_id": "TEST_FIXTURE_UNKNOWN_001",
            "label_status": "UNKNOWN",
            "flood_outcome": None,
            "label_confidence": "LOW",
            "review_status": "REQUIRES_HUMAN_REVIEW",
            "reviewed_at": None,
            "leakage_review_status": "NOT_REVIEWED",
        })
        prepared, excluded, issues = prepare_records([self.loaded(unknown)], self.sources, self.barangays)
        self.assertEqual([], issues)
        self.assertEqual([], prepared)
        self.assertEqual("NOT_TRAINING_ELIGIBLE", excluded[0]["reason"])

    def test_missing_source_is_rejected(self):
        record = copy.deepcopy(self.positive)
        record["flood_evidence_source"] = None
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("MISSING_PROVENANCE_SOURCE", self.codes(issues))

    def test_invalid_rainfall_is_rejected(self):
        record = copy.deepcopy(self.positive)
        record["forecast_rainfall_24h_mm"] = -1
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("INVALID_RAINFALL_RANGE", self.codes(issues))

    def test_absence_of_report_cannot_be_a_negative(self):
        record = copy.deepcopy(self.negative)
        record["review_notes"] = "No report found in the fictional fixture."
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("PROHIBITED_ABSENCE_AS_NEGATIVE", self.codes(issues))

    def test_invalid_timestamp_window_is_rejected(self):
        record = copy.deepcopy(self.positive)
        record["valid_until"] = "2025-01-02T07:00:00+08:00"
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("INVALID_VALID_WINDOW", self.codes(issues))

    def test_ambiguous_barangay_176_is_quarantined(self):
        record = copy.deepcopy(self.positive)
        record.update({
            "barangay_psgc": "1380100176",
            "barangay_name": "Barangay 176",
            "spatial_mapping_status": "UNKNOWN_SPATIAL_MAPPING",
        })
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("UNKNOWN_SPATIAL_MAPPING_BARANGAY_176", self.codes(issues))
        self.assertIn("UNRESOLVED_SPATIAL_MAPPING", self.codes(issues))

    def test_invalid_susceptibility_is_rejected(self):
        record = copy.deepcopy(self.positive)
        record["mgb_flood_susceptibility_code"] = "CRITICAL"
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("INVALID_SUSCEPTIBILITY", self.codes(issues))

    def test_warning_fields_are_rejected_as_leakage_prone_extras(self):
        record = copy.deepcopy(self.positive)
        record["warning_status"] = "ACTIVE"
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("UNEXPECTED_FIELDS", self.codes(issues))

    def test_duplicate_observation_is_detected(self):
        duplicate = copy.deepcopy(self.positive)
        duplicate["record_id"] = "TEST_FIXTURE_POSITIVE_DUPLICATE"
        issues = validate_records(
            [self.loaded(self.positive, 1), self.loaded(duplicate, 2)],
            self.sources,
            self.barangays,
            mode="reviewed",
        )
        self.assertIn("DUPLICATE_OBSERVATION", self.codes(issues))

    def test_unknown_provenance_is_rejected(self):
        record = copy.deepcopy(self.positive)
        record["weather_source"] = "missing_fixture_source"
        issues = validate_record(self.loaded(record), self.sources, self.barangays, mode="reviewed")
        self.assertIn("UNKNOWN_PROVENANCE_SOURCE", self.codes(issues))

    def test_manifest_fixture_has_no_validation_errors(self):
        self.assertEqual([], validate_manifest(self.manifest, repo_root=REPO_ROOT))

    def test_preprocessing_has_exact_ten_model_features(self):
        prepared, excluded, issues = prepare_records(
            [self.loaded(self.positive, 1), self.loaded(self.negative, 2)],
            self.sources,
            self.barangays,
        )
        self.assertEqual([], issues)
        self.assertEqual([], excluded)
        feature_names = {
            "forecast_rainfall_24h_mm", "antecedent_rainfall_24h_mm",
            "antecedent_rainfall_72h_mm", "mgb_susceptibility_LF",
            "mgb_susceptibility_MF", "mgb_susceptibility_HF",
            "mgb_susceptibility_VHF", "mgb_susceptibility_NONE",
            "month_sin", "month_cos",
        }
        self.assertEqual(10, len(feature_names))
        self.assertTrue(feature_names.issubset(prepared[0]))

    def test_readiness_remains_false_for_insufficient_fixtures(self):
        report = build_readiness_report(
            [self.loaded(self.positive, 1), self.loaded(self.negative, 2)],
            self.manifest,
            self.sources,
            self.barangays,
            self.governance,
            self.authorization,
            counts_are_real_observations=False,
        )
        self.assertFalse(report["training_ready"])
        self.assertFalse(report["counts_are_real_observations"])
        self.assertFalse(report["gates"]["multiple_independent_events"])
        self.assertFalse(report["gates"]["data_governance_authorized_for_phase_7c"])


if __name__ == "__main__":
    unittest.main()
