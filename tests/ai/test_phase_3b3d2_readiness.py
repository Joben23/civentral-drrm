"""Focused Phase 3B3-D2 pre-training adjudication tests."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
ASSESSMENT = WORKSPACE / "manifests/phase-3b3d2-readiness-assessment.json"


class Phase3B3D2ReadinessTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.assessment = json.loads(ASSESSMENT.read_text(encoding="utf-8"))
        cls.events = json.loads((WORKSPACE / "manifests/flood-event-registry.json").read_text(encoding="utf-8"))
        cls.enteng = json.loads((WORKSPACE / "manifests/imerg-enteng-2024-exploratory-acquisition.json").read_text(encoding="utf-8"))

    def test_rainfall_cannot_change_unknown_label(self) -> None:
        event = next(item for item in self.events["events"] if item["event_id"].endswith("2024-09-02-enteng"))
        self.assertEqual("UNKNOWN", event["label_status"])
        self.assertFalse(event["training_eligible"])

    def test_grid_cells_are_not_event_labels(self) -> None:
        self.assertEqual(16, self.assessment["enteng_rainfall_revalidation"]["grid_cell_count"])
        self.assertEqual(1, self.assessment["candidate_inventory"]["events_with_rainfall_acquired"])
        self.assertFalse(self.assessment["spatial_assessment"]["grid_cells_are_independent_events"])

    def test_post_event_rainfall_is_not_a_pre_event_feature(self) -> None:
        features = self.assessment["temporal_assessment"]["feature_windows"]
        self.assertFalse(features["post_event_rainfall_allowed_in_pre_event_features"])
        self.assertIsNone(self.assessment["temporal_assessment"]["prediction_cutoff"])

    def test_absence_of_incident_record_cannot_create_negative(self) -> None:
        labels = self.assessment["label_adjudication"]
        self.assertEqual(0, labels["negative_labels_available"])
        self.assertIn("missing reports", labels["negative_label_rule"])

    def test_training_readiness_requires_governed_labels_and_target(self) -> None:
        sufficiency = self.assessment["training_sufficiency"]
        self.assertFalse(sufficiency["training_ready"])
        self.assertFalse(sufficiency["target_definition_sufficient"])
        self.assertEqual(0, sufficiency["positive_training_rows"])
        self.assertEqual(0, sufficiency["negative_training_rows"])

    def test_spatial_aggregation_status_is_explicit(self) -> None:
        self.assertEqual("UNRESOLVED_REQUIRES_REVIEW", self.assessment["spatial_assessment"]["aggregation_status"])

    def test_training_state_and_artifact_safety(self) -> None:
        safety = self.assessment["safety_state"]
        self.assertFalse(safety["training_ready"])
        self.assertEqual("NOT_APPROVED", safety["training_authorization"])
        self.assertFalse(safety["tensorflow_training_performed"])
        self.assertFalse(safety["model_artifact_created"])
        self.assertFalse(safety["prediction_probability_generated"])
        forbidden = [path for path in (WORKSPACE / "artifacts").rglob("*") if path.is_file() and path.name in {"model.keras", "model.h5", "model.tflite", "saved_model.pb"}]
        self.assertEqual([], forbidden)

    def test_enteng_acquisition_contract_remains_valid(self) -> None:
        self.assertEqual("2024-08-31T00:00:00Z", self.enteng["acquisition_window"]["start_utc"])
        self.assertEqual("2024-09-03T00:00:00Z", self.enteng["acquisition_window"]["end_utc_exclusive"])
        coverage = self.enteng["validated_coverage"]
        self.assertEqual(2304, coverage["actual_observation_count"])
        self.assertEqual(0, coverage["missing_interval_count"])
        self.assertEqual(0, coverage["duplicate_interval_count"])
        self.assertEqual(0, coverage["fill_or_invalid_value_count"])

    def test_enteng_raw_files_remain_ignored(self) -> None:
        raw = "ml/flood-risk/data/raw/precipitation/GPM_3IMERGHH_V07B_Enteng_20240831T000000Z_20240831T060000Z_samples.json"
        self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", raw], cwd=REPO_ROOT).returncode)


if __name__ == "__main__":
    unittest.main()