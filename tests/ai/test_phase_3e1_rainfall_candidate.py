"""Focused Phase 3E1 rainfall candidate validation tests."""

from __future__ import annotations

import hashlib
import json
import subprocess
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
RESULT = WORKSPACE / "manifests/phase-3e1-rainfall-candidate-validation.json"
LINEAR = WORKSPACE / "artifacts/rainfall-regression/rainfall-regression-dense-57-v0.1.0-candidate"
SOFTPLUS = WORKSPACE / "artifacts/rainfall-regression/rainfall-regression-dense-57-v0.1.1-softplus-candidate"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


class Phase3E1CandidateValidationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.result = json.loads(RESULT.read_text(encoding="utf-8"))
        cls.registry = json.loads((WORKSPACE / "manifests/flood-event-registry.json").read_text(encoding="utf-8"))
        cls.linear_manifest = json.loads((LINEAR / "manifest.json").read_text(encoding="utf-8"))
        cls.softplus_manifest = json.loads((SOFTPLUS / "manifest.json").read_text(encoding="utf-8"))

    def test_frozen_linear_candidate_checksums_are_unchanged(self) -> None:
        frozen = self.result["frozen_phase_3d"]
        self.assertEqual("e729f7ca6f60d702f5ee6c9463ca72ea3c424bb2975cfef274f9319c324d22f6", frozen["model_sha256"])
        self.assertEqual("e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4", frozen["preprocessing_sha256"])
        self.assertEqual(frozen["model_sha256"], sha256(LINEAR / "model.keras"))
        self.assertEqual(frozen["preprocessing_sha256"], sha256(LINEAR / "preprocessing.json"))
        self.assertFalse(self.linear_manifest["active"])
        self.assertFalse(self.linear_manifest["approved_for_inference"])

    def test_selection_is_validation_only(self) -> None:
        selection = self.result["selection"]
        self.assertFalse(self.result["selection_rule"]["test_used_for_selection"])
        self.assertEqual("LOWEST_VALIDATION_RMSE", self.result["selection_rule"]["primary"])
        self.assertEqual("SOFTPLUS", selection["selected_policy"])
        self.assertEqual("SOFTPLUS_COMPARISON_CANDIDATE", selection["selected_model"])

    def test_zero_floor_is_nonnegative_and_raw_is_retained(self) -> None:
        clamp = self.result["validation"]["linear_zero_floor"]
        self.assertEqual(0.0, clamp["minimum_final_prediction_mm"])
        self.assertIn("raw_prediction_mm", self.result["output_policy"])
        self.assertIn("final_prediction_mm", self.result["output_policy"])
        self.assertEqual("MODEL_NONNEGATIVE_SOFTPLUS", self.result["output_policy"]["output_policy"])
        self.assertEqual(0, self.result["final_test_evaluation"]["negative_final_prediction_count"])

    def test_softplus_architecture_is_exact_predefined_variant(self) -> None:
        import tensorflow as tf
        model = tf.keras.models.load_model(SOFTPLUS / "model.keras", compile=False)
        layers = [layer for layer in model.layers if layer.__class__.__name__ != "InputLayer"]
        self.assertEqual(["Dense", "Dense", "Dense"], [layer.__class__.__name__ for layer in layers])
        self.assertEqual([64, 32, 1], [layer.units for layer in layers])
        self.assertEqual(["relu", "relu", "softplus"], [layer.activation.__name__ for layer in layers])
        self.assertEqual(5825, model.count_params())

    def test_train_only_scaler_and_feature_contract_remain(self) -> None:
        contract = json.loads((WORKSPACE / "manifests/phase-3c-rainfall-regression-contract.json").read_text(encoding="utf-8"))
        self.assertEqual("train", self.result["validation"]["softplus_training"].get("fit_split", "train"))
        self.assertEqual(57, self.result["feature_count"])
        self.assertEqual("NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", self.result["target"])
        preprocessing = json.loads((LINEAR / "preprocessing.json").read_text(encoding="utf-8"))
        self.assertEqual("train", contract["normalization"]["fit_split"])
        self.assertEqual(contract["normalization"]["feature_mean"], preprocessing["feature_mean"])
        self.assertEqual(contract["normalization"]["feature_std"], preprocessing["feature_std"])

    def test_upper_tail_threshold_is_train_derived(self) -> None:
        self.assertAlmostEqual(2.487333297729492, self.result["train_derived_upper_tail_threshold_mm"])
        self.assertEqual(397, self.result["validation"]["softplus"]["upper_tail"]["count"])
        self.assertEqual(507, self.result["final_test_evaluation"]["final"]["upper_tail"]["count"])

    def test_candidate_is_research_only_and_inactive(self) -> None:
        self.assertFalse(self.softplus_manifest["active"])
        self.assertFalse(self.softplus_manifest["approved_for_inference"])
        self.assertEqual("VALIDATED_RESEARCH_CANDIDATE", self.softplus_manifest["candidate_status"])
        self.assertFalse(self.result["governance"]["service_activation_changed"])

    def test_flood_governance_remains_unchanged(self) -> None:
        self.assertTrue(all(event["label_status"] == "UNKNOWN" for event in self.registry["events"]))
        self.assertTrue(all(event["training_eligible"] is False for event in self.registry["events"]))
        self.assertFalse(self.result["governance"]["global_training_ready"])
        self.assertEqual("NOT_APPROVED", self.result["governance"]["global_training_authorization"])
        self.assertFalse(self.result["governance"]["mgb_fusion_implemented"])

    def test_no_flood_or_mgb_features_entered(self) -> None:
        self.assertFalse(self.linear_manifest["feature_contract"]["flood_labels_present"])
        self.assertFalse(self.linear_manifest["feature_contract"]["mgb_features_present"])
        self.assertEqual("RAINFALL_REGRESSION", self.linear_manifest["model_problem"])

    def test_model_bundle_and_raw_data_are_ignored(self) -> None:
        for path in [SOFTPLUS / "model.keras", SOFTPLUS / "preprocessing.json", "ml/flood-risk/data/raw/precipitation/GPM_3IMERGHH_V07B_Enteng_20240831T000000Z_20240831T060000Z_samples.json"]:
            self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", str(path)], cwd=REPO_ROOT).returncode)

    def test_no_model_is_active(self) -> None:
        self.assertFalse(self.linear_manifest["active"])
        self.assertFalse(self.softplus_manifest["active"])
        self.assertFalse(self.linear_manifest["approved_for_inference"])
        self.assertFalse(self.softplus_manifest["approved_for_inference"])


if __name__ == "__main__":
    unittest.main()
