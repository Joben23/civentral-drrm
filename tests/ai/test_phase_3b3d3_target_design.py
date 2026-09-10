"""Focused Phase 3B3-D3 architecture and target-design safety tests."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
DESIGN = WORKSPACE / "manifests/phase-3b3d3-target-design-preparation.json"


class Phase3B3D3TargetDesignTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.design = json.loads(DESIGN.read_text(encoding="utf-8"))
        cls.registry = json.loads((WORKSPACE / "manifests/flood-event-registry.json").read_text(encoding="utf-8"))

    def test_direct_classifier_remains_blocked(self) -> None:
        direct = self.design["direct_flood_classifier_assessment"]
        self.assertEqual("BLOCKED", direct["status"])
        self.assertEqual(0, direct["positive_label_count"])
        self.assertEqual(0, direct["affirmative_negative_label_count"])

    def test_hybrid_model_predicts_rainfall_not_flood_outcomes(self) -> None:
        hybrid = self.design["hybrid_architecture_assessment"]
        self.assertIn("rainfall quantity", hybrid["model_claim"])
        self.assertIn("not learned flood occurrence", hybrid["model_claim"])
        self.assertEqual("NOT_APPROVED", hybrid["current_authorization"])

    def test_rainfall_target_is_not_a_flood_label(self) -> None:
        target = self.design["recommended_rainfall_target"]
        self.assertEqual("precipitation_mm", target["target_variable"])
        self.assertEqual("mm", target["target_units"])
        self.assertTrue(target["not_a_flood_label"])

    def test_inputs_are_past_only_and_cutoff_is_separate(self) -> None:
        inputs = self.design["input_design"]
        self.assertEqual(24, inputs["lookback_window_hours"])
        self.assertEqual(3, inputs["forecast_horizon_hours"])
        self.assertIn("at or before", inputs["feature_timestamps"])
        self.assertIsNone(self.design["safety_state"]["prediction_cutoff"])

    def test_spatial_design_does_not_inflate_event_count(self) -> None:
        spatial = self.design["spatial_design"]
        self.assertEqual("CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS", spatial["recommended_primary_approach"])
        self.assertFalse(spatial["available_contract"]["grid_cells_are_independent_events"])
        self.assertIn("16 cells", spatial["cell_level_option"])

    def test_corpus_is_recommended_but_not_acquired(self) -> None:
        corpus = self.design["historical_corpus_requirement"]
        self.assertEqual("2023-09-01T00:00:00Z", corpus["recommended_next_phase_range"]["start_utc"])
        self.assertEqual("2024-09-03T00:00:00Z", corpus["recommended_next_phase_range"]["end_utc_exclusive"])
        self.assertTrue(corpus["not_acquired_in_d3"])

    def test_mgb_is_static_context_not_outcome_truth(self) -> None:
        mgb = self.design["mgb_risk_fusion"]
        self.assertTrue(mgb["not_outcome_truth"])
        self.assertFalse(mgb["thresholds_finalized"])
        self.assertIn("not map LF/MF/HF/VHF directly", mgb["not_automatic_mapping"])

    def test_existing_unknown_events_and_training_state_are_unchanged(self) -> None:
        self.assertTrue(self.design["safety_state"]["existing_event_labels_unchanged"])
        self.assertTrue(all(event["label_status"] == "UNKNOWN" for event in self.registry["events"]))
        self.assertTrue(all(event["training_eligible"] is False for event in self.registry["events"]))
        self.assertFalse(self.design["safety_state"]["training_ready"])
        self.assertEqual("NOT_APPROVED", self.design["safety_state"]["training_authorization"])

    def test_no_training_or_expanded_acquisition_occurred(self) -> None:
        safety = self.design["safety_state"]
        self.assertFalse(safety["tensorflow_training_performed"])
        self.assertFalse(safety["model_artifact_created"])
        self.assertFalse(safety["expanded_rainfall_acquired"])
        model_artifacts = [path for path in (WORKSPACE / "artifacts").rglob("*") if path.is_file() and path.name in {"model.keras", "model.h5", "model.tflite", "saved_model.pb"}]
        for artifact in model_artifacts:
            self.assertIn(artifact.parent.name, {"rainfall-regression-dense-57-v0.1.0-candidate", "rainfall-regression-dense-57-v0.1.1-softplus-candidate"})
            self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", str(artifact)], cwd=REPO_ROOT).returncode)
            manifest = json.loads((artifact.parent / "manifest.json").read_text(encoding="utf-8"))
            self.assertFalse(manifest["active"])
            self.assertFalse(manifest["approved_for_inference"])

    def test_capstone_compatibility_is_conditional(self) -> None:
        compatibility = self.design["capstone_compatibility"]
        self.assertEqual("CONDITIONALLY_COMPATIBLE", compatibility["compatibility"])
        self.assertFalse(compatibility["chapter_edits_in_d3"])
        self.assertIn("deterministic", compatibility["path_b_interpretation"])

    def test_raw_enteng_remains_ignored(self) -> None:
        raw = "ml/flood-risk/data/raw/precipitation/GPM_3IMERGHH_V07B_Enteng_20240831T000000Z_20240831T060000Z_samples.json"
        self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", raw], cwd=REPO_ROOT).returncode)


if __name__ == "__main__":
    unittest.main()