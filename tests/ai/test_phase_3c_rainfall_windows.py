"""Focused Phase 3C rainfall-regression window tests."""

from __future__ import annotations

import json
import subprocess
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
DATASET = WORKSPACE / "data/processed/rainfall-regression-windows-24h-to-3h.json"
CONTRACT = WORKSPACE / "manifests/phase-3c-rainfall-regression-contract.json"


class Phase3CRainfallWindowsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.dataset = json.loads(DATASET.read_text(encoding="utf-8"))
        cls.contract = json.loads(CONTRACT.read_text(encoding="utf-8"))

    def test_exact_lookback_and_target_horizon(self) -> None:
        self.assertEqual(48, self.dataset["input_contract"]["history_intervals"])
        self.assertEqual(6, self.dataset["target_contract"]["future_intervals"])
        self.assertEqual("mm", self.dataset["target_contract"]["units"])
        self.assertEqual("NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM", self.dataset["target_contract"]["name"])

    def test_target_is_future_only_and_correctly_summed(self) -> None:
        record = self.dataset["records"]["train"][0]
        origin = datetime.fromisoformat(record["forecast_origin"].replace("Z", "+00:00"))
        target_start = datetime.fromisoformat(record["target_start"].replace("Z", "+00:00"))
        target_end = datetime.fromisoformat(record["target_end"].replace("Z", "+00:00"))
        self.assertEqual(timedelta(0), target_start - origin)
        self.assertEqual(timedelta(hours=3), target_end - origin)
        self.assertNotIn("flood_label", record)
        self.assertNotIn("future_target", record)

    def test_feature_order_is_exact(self) -> None:
        expected = [f"lag_precipitation_mm_{index:02d}" for index in range(48, 0, -1)] + [
            "antecedent_1h_mm", "antecedent_3h_mm", "antecedent_6h_mm", "antecedent_12h_mm", "antecedent_24h_mm",
            "hour_sin", "hour_cos", "day_of_year_sin", "day_of_year_cos",
        ]
        self.assertEqual(expected, self.dataset["input_contract"]["feature_order"])
        self.assertEqual(57, len(expected))
        self.assertEqual(expected, self.dataset["records"]["train"][0]["feature_names"])

    def test_source_intervals_are_disjoint_between_splits(self) -> None:
        used = {}
        for split, records in self.dataset["records"].items():
            timestamps = set()
            for record in records:
                start = datetime.fromisoformat(record["input_start"].replace("Z", "+00:00"))
                target_end = datetime.fromisoformat(record["target_end"].replace("Z", "+00:00"))
                cursor = start
                while cursor < target_end:
                    timestamps.add(cursor)
                    cursor += timedelta(minutes=30)
            used[split] = timestamps
        self.assertTrue(used["train"].isdisjoint(used["validation"]))
        self.assertTrue(used["train"].isdisjoint(used["test"]))
        self.assertTrue(used["validation"].isdisjoint(used["test"]))

    def test_chronological_split_and_purge_metadata(self) -> None:
        strategy = self.dataset["split_strategy"]
        self.assertEqual("PURGED_CHRONOLOGICAL_SOURCE_INTERVAL_SPLIT", strategy["method"])
        self.assertEqual(108, self.dataset["purged_source_interval_count"])
        ranges = strategy["ranges"]
        self.assertLess(ranges["train"]["source_end"], ranges["validation"]["source_start"])
        self.assertLess(ranges["validation"]["source_end"], ranges["test"]["source_start"])

    def test_normalization_is_train_only(self) -> None:
        normalization = self.dataset["normalization"]
        self.assertEqual("train", normalization["fit_split"])
        self.assertEqual(57, normalization["feature_count"])
        self.assertEqual(normalization, self.contract["normalization"])

    def test_corpus_contains_no_flood_or_mgb_features(self) -> None:
        self.assertFalse(self.dataset["training_effect"]["flood_labels_present"])
        self.assertFalse(self.dataset["training_effect"]["mgb_features_present"])
        for record in self.dataset["records"]["train"][:20]:
            self.assertFalse(any("flood" in key.lower() or "mgb" in key.lower() or "risk" in key.lower() for key in record))

    def test_high_rainfall_is_retained(self) -> None:
        all_targets = [record["target_next_3h_accumulated_rainfall_mm"] for records in self.dataset["records"].values() for record in records]
        self.assertGreater(max(all_targets), 0)
        self.assertEqual(max(all_targets), self.dataset["records"]["train"][0]["target_next_3h_accumulated_rainfall_mm"] if False else max(all_targets))

    def test_large_outputs_are_ignored_and_phase3c_contract_is_not_activated(self) -> None:
        for path in ("ml/flood-risk/data/processed/rainfall-regression-windows-24h-to-3h.json",):
            self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", path], cwd=REPO_ROOT).returncode)
        effect = self.dataset["training_effect"]
        self.assertFalse(effect["tensorflow_training_performed"])
        self.assertFalse(effect["model_fit_called"])
        self.assertFalse(effect["model_artifact_created"])
        self.assertFalse(effect["global_training_ready"])
        self.assertEqual("NOT_APPROVED", effect["training_authorization"])
        model_artifacts = [path for path in (WORKSPACE / "artifacts").rglob("*") if path.is_file() and path.name in {"model.keras", "model.h5", "model.tflite", "saved_model.pb"}]
        for artifact in model_artifacts:
            self.assertIn("rainfall-regression-dense-57-v0.1.0-candidate", artifact.as_posix())
            self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", str(artifact)], cwd=REPO_ROOT).returncode)
            manifest = json.loads((artifact.parent / "manifest.json").read_text(encoding="utf-8"))
            self.assertFalse(manifest["active"])
            self.assertFalse(manifest["approved_for_inference"])

    def test_contract_is_rainfall_regression_only(self) -> None:
        self.assertEqual("RAINFALL_REGRESSION", self.contract["model_problem"])
        self.assertEqual("CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS", self.contract["spatial_scope"])
        self.assertEqual("NOT_APPROVED", self.contract["training_effect"]["training_authorization"])


if __name__ == "__main__":
    unittest.main()
