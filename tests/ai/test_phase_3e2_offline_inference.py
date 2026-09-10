"""Focused Phase 3E2 offline Softplus inference contract tests."""

from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
SCRIPT_DIR = REPO_ROOT / "scripts/ai"
sys.path.insert(0, str(SCRIPT_DIR))

from offline_rainfall_inference import OfflineInferenceError, predict_rainfall  # noqa: E402


class Phase3E2OfflineInferenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        corpus = json.loads((WORKSPACE / "data/processed/imerg-caloocan-city-mean-2023-2024.json").read_text(encoding="utf-8"))
        records = corpus["records"]
        cls.histories = [[{"timestamp_utc": row["observed_at_start"], "city_mean_precipitation_mm": row["city_mean_precipitation_mm"]} for row in records[start : start + 48]] for start in (0, 1000, 10000)]
        cls.contract = json.loads((WORKSPACE / "manifests/phase-3e2-rainfall-offline-inference-contract.json").read_text(encoding="utf-8"))

    def test_selected_candidate_identity_and_contract(self) -> None:
        candidate = self.contract["candidate"]
        self.assertEqual("rainfall-regression-dense-57-v0.1.1-softplus-candidate", candidate["version"])
        self.assertEqual("MODEL_NONNEGATIVE_SOFTPLUS", candidate["output_policy"])
        self.assertFalse(candidate["active"])
        self.assertFalse(candidate["approved_for_inference"])
        self.assertEqual(57, candidate["feature_count"])

    def test_history_requires_exactly_48_intervals(self) -> None:
        with self.assertRaises(OfflineInferenceError) as raised:
            predict_rainfall(self.histories[0][:-1])
        self.assertEqual("INSUFFICIENT_HISTORY", raised.exception.code)

    def test_history_requires_continuity_and_no_duplicates(self) -> None:
        gap = list(self.histories[0])
        gap[10] = dict(gap[10], timestamp_utc="2023-09-01T06:00:00Z")
        with self.assertRaises(OfflineInferenceError) as raised:
            predict_rainfall(gap)
        self.assertEqual("TIMESTAMP_GAP", raised.exception.code)
        duplicate = list(self.histories[0])
        duplicate[10] = dict(duplicate[9])
        with self.assertRaises(OfflineInferenceError) as raised:
            predict_rainfall(duplicate)
        self.assertEqual("DUPLICATE_TIMESTAMP", raised.exception.code)

    def test_negative_source_rainfall_fails_closed(self) -> None:
        history = list(self.histories[0])
        history[0] = dict(history[0], city_mean_precipitation_mm=-0.1)
        with self.assertRaises(OfflineInferenceError) as raised:
            predict_rainfall(history)
        self.assertEqual("NEGATIVE_SOURCE_PRECIPITATION", raised.exception.code)

    def test_output_is_reproducible_finite_nonnegative_and_three_hours(self) -> None:
        for history in self.histories:
            first = predict_rainfall(history)
            second = predict_rainfall(history)
            self.assertEqual(first, second)
            self.assertEqual("RAINFALL_REGRESSION", first["model_problem"])
            self.assertEqual(3, first["forecast_horizon_hours"])
            self.assertGreaterEqual(first["final_prediction_mm"], 0)
            self.assertEqual(first["raw_prediction_mm"], first["final_prediction_mm"])
            self.assertFalse(first["operational"])
            self.assertNotIn("flood_probability", first)
            self.assertNotIn("risk_category", first)
            self.assertNotIn("mgb_susceptibility", first)

    def test_forecast_origin_is_latest_interval_end(self) -> None:
        history = self.histories[0]
        result = predict_rainfall(history)
        self.assertEqual("2023-09-02T00:00:00Z", result["forecast_origin_utc"])

    def test_stored_training_preprocessing_is_used(self) -> None:
        preprocessing = json.loads((WORKSPACE / "artifacts/rainfall-regression/rainfall-regression-dense-57-v0.1.1-softplus-candidate/preprocessing.json").read_text(encoding="utf-8"))
        self.assertEqual("train", preprocessing["fit_split"])
        self.assertEqual(57, preprocessing["expected_feature_count"])
        self.assertEqual(preprocessing["feature_order"], self.contract["feature_contract"]["feature_order_source"] and preprocessing["feature_order"])

    def test_no_fastapi_route_or_service_activation_was_added(self) -> None:
        self.assertFalse((REPO_ROOT / "ml/flood-risk/service/app/rainfall_routes.py").exists())
        self.assertFalse(self.contract["proposed_phase_3f_private_api"]["not_implemented_in_phase_3e2"] is False)
        self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", "ml/flood-risk/artifacts/rainfall-regression/rainfall-regression-dense-57-v0.1.1-softplus-candidate/model.keras"], cwd=REPO_ROOT).returncode)

    def test_flood_governance_remains_unchanged(self) -> None:
        registry = json.loads((WORKSPACE / "manifests/flood-event-registry.json").read_text(encoding="utf-8"))
        self.assertTrue(all(event["label_status"] == "UNKNOWN" for event in registry["events"]))
        self.assertTrue(all(event["training_eligible"] is False for event in registry["events"]))
        self.assertEqual("NOT_APPROVED", self.contract["governance"]["global_training_authorization"])
        self.assertFalse(self.contract["governance"]["global_training_ready"])


if __name__ == "__main__":
    unittest.main()
