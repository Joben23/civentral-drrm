"""Focused Phase 3B3-C2 Enteng IMERG acquisition tests."""

from __future__ import annotations

import copy
import json
import subprocess
import sys
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = REPO_ROOT / "scripts" / "ai"
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
sys.path.insert(0, str(SCRIPT_DIR))

from flood_acquisition_common import (  # noqa: E402
    AcquisitionValidationError,
    normalize_imerg_samples,
    precipitation_coverage_report,
    validate_imerg_acquisition,
)


class EntengImergAcquisitionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.acquisition = json.loads((WORKSPACE / "manifests" / "imerg-enteng-2024-exploratory-acquisition.json").read_text(encoding="utf-8"))
        manifest = json.loads((WORKSPACE / "manifests" / "source-manifest.json").read_text(encoding="utf-8"))
        cls.source = next(item for item in manifest["sources"] if item["source_id"] == "nasa_gpm_imerg_final_hh_v07")
        cls.event_registry = json.loads((WORKSPACE / "manifests" / "flood-event-registry.json").read_text(encoding="utf-8"))

    def test_governed_window_is_exact_and_non_training(self) -> None:
        window = self.acquisition["acquisition_window"]
        self.assertEqual("2024-08-31T00:00:00Z", window["start_utc"])
        self.assertEqual("2024-09-03T00:00:00Z", window["end_utc_exclusive"])
        self.assertFalse(window["target_window"])
        self.assertFalse(window["prediction_window"])
        self.assertFalse(window["training_window"])
        self.assertEqual("APPROVED_FOR_PROJECT_RESEARCH_ONLY", self.acquisition["authorization"])

    def test_spatial_contract_is_exactly_16_centers(self) -> None:
        spatial = self.acquisition["spatial_request"]
        self.assertEqual("EPSG:4326", spatial["crs"])
        self.assertEqual([120.8, 14.5, 121.2, 14.9], spatial["requested_bounds_wgs84"])
        self.assertEqual(16, len(spatial["requested_grid_centers"]))
        self.assertEqual(16, len({(item["longitude"], item["latitude"]) for item in spatial["requested_grid_centers"]}))

    def test_reused_validator_and_normalizer_accept_acquisition(self) -> None:
        self.assertEqual([], validate_imerg_acquisition(self.acquisition, self.source))
        normalized = normalize_imerg_samples(self.acquisition, self.source)
        report = precipitation_coverage_report(normalized, self.acquisition)
        self.assertTrue(report["complete"])
        self.assertEqual(2304, report["actual_observation_count"])

    def test_interval_boundaries_and_counts_are_verified(self) -> None:
        normalized = normalize_imerg_samples(self.acquisition, self.source)
        report = precipitation_coverage_report(normalized, self.acquisition)
        self.assertEqual("2024-08-31T00:00:00Z", report["first_timestamp_utc"])
        self.assertEqual("2024-09-02T23:30:00Z", report["last_interval_start_utc"])
        self.assertEqual("2024-09-03T00:00:00Z", report["last_interval_end_utc"])
        self.assertEqual(144, report["actual_unique_interval_count"])
        self.assertEqual(16, report["spatial_cell_count"])

    def test_missing_and_duplicate_intervals_are_detected(self) -> None:
        normalized = normalize_imerg_samples(self.acquisition, self.source)
        normalized["records"].pop()
        missing = precipitation_coverage_report(normalized, self.acquisition)
        self.assertEqual(1, missing["missing_interval_count"])
        normalized = normalize_imerg_samples(self.acquisition, self.source)
        normalized["records"].append(copy.deepcopy(normalized["records"][0]))
        duplicate = precipitation_coverage_report(normalized, self.acquisition)
        self.assertEqual(1, duplicate["duplicate_interval_count"])

    def test_invalid_values_are_not_silently_validated(self) -> None:
        normalized = normalize_imerg_samples(self.acquisition, self.source)
        normalized["records"][0]["precipitation_mm"] = -1
        report = precipitation_coverage_report(normalized, self.acquisition)
        self.assertEqual(1, report["fill_or_invalid_value_count"])
        self.assertFalse(report["complete"])

    def test_units_and_statistics_are_descriptive_only(self) -> None:
        self.assertEqual("mm/hr", self.acquisition["normalization"]["native_units"])
        self.assertEqual("mm", self.acquisition["normalization"]["normalized_units"])
        qa = self.acquisition["validated_coverage"]["descriptive_qa_only"]
        self.assertEqual("mm/hr", qa["native_units"])
        self.assertEqual("mm", qa["normalized_units"])
        self.assertIn("interpretation_prohibited", qa)

    def test_raw_rainfall_is_ignored(self) -> None:
        raw_file = self.source["artifacts"][-1]["local_file"]
        result = subprocess.run(["git", "check-ignore", "--quiet", "--", raw_file], cwd=REPO_ROOT)
        self.assertEqual(0, result.returncode)

    def test_event_and_readiness_state_remain_fail_closed(self) -> None:
        event = next(item for item in self.event_registry["events"] if item["event_id"] == "caloocan-flood-candidate-2024-09-02-enteng")
        self.assertEqual("UNKNOWN", event["label_status"])
        self.assertFalse(event["training_eligible"])
        self.assertEqual("NOT_APPROVED", self.event_registry["training_authorization_status"])
        self.assertFalse(self.acquisition["training_effect"]["creates_training_row"])
        self.assertFalse(self.acquisition["training_effect"]["sets_event_label"])
        self.assertFalse(self.acquisition["training_effect"]["sets_training_ready"])
        self.assertFalse(self.acquisition["training_effect"]["authorizes_training"])

    def test_no_model_artifact_was_created(self) -> None:
        forbidden = ("model.keras", ".h5", ".tflite", "saved_model.pb")
        found = [path for path in (WORKSPACE / "artifacts").rglob("*") if path.is_file() and path.name in forbidden]
        self.assertEqual([], found)

    def test_fill_value_normalization_remains_rejected(self) -> None:
        self.assertEqual("REJECT", self.acquisition["normalization"]["missing_fill_policy"])
        self.assertEqual("PROVISIONAL_NO_QUALITY_INDEX", self.acquisition["normalization"]["quality_status"])


if __name__ == "__main__":
    unittest.main()