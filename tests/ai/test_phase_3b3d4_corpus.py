"""Focused Phase 3B3-D4 historical corpus and aggregation tests."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
LEDGER = WORKSPACE / "manifests/imerg-caloocan-historical-2023-2024-acquisition.json"
CORPUS = WORKSPACE / "data/processed/imerg-caloocan-city-mean-2023-2024.json"


class Phase3B3D4CorpusTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.ledger = json.loads(LEDGER.read_text(encoding="utf-8"))
        cls.corpus = json.loads(CORPUS.read_text(encoding="utf-8"))

    def test_exact_authorized_window(self) -> None:
        self.assertEqual("2023-09-01T00:00:00Z", self.ledger["window"]["start_utc"])
        self.assertEqual("2024-09-03T00:00:00Z", self.ledger["window"]["end_utc_exclusive"])
        self.assertTrue(self.ledger["window"]["scope_not_expanded"])

    def test_three_governed_centers_are_geometry_derived(self) -> None:
        scope = self.ledger["spatial_scope"]
        self.assertEqual("EPSG:4326", scope["crs"])
        self.assertEqual(3, scope["count"])
        self.assertEqual(
            {(120.95, 14.65), (121.05, 14.65), (121.05, 14.75)},
            {(item["longitude"], item["latitude"]) for item in scope["centers"]},
        )
        self.assertEqual("CALOOCAN_INTERSECTING_CELL_GEOMETRY", scope["method"])
        self.assertFalse(scope["area_weighting"])

    def test_batch_strategy_and_observation_count(self) -> None:
        strategy = self.ledger["batch_strategy"]
        self.assertEqual(10, strategy["batch_hours"])
        self.assertEqual(884, strategy["batch_count"])
        self.assertEqual(60, strategy["expected_samples_per_full_batch"])
        self.assertEqual(52992, self.ledger["raw_observation_count"])

    def test_complete_interval_and_value_qa(self) -> None:
        qa = self.corpus["qa"]
        self.assertEqual(17664, qa["aggregated_timestamp_count"])
        self.assertEqual(17664, qa["expected_timestamp_count"])
        self.assertEqual(100.0, qa["coverage_percentage"])
        self.assertEqual(0, qa["missing_timestamp_count"])
        self.assertEqual(0, qa["duplicate_timestamp_count"])
        self.assertEqual(0, qa["invalid_value_count"])

    def test_native_conversion_and_city_mean(self) -> None:
        first = self.corpus["records"][0]
        self.assertEqual(3, first["contributing_cell_count"])
        self.assertEqual(3, len(first["grid_cell_ids"]))
        self.assertAlmostEqual(
            first["city_mean_precipitation_mm"],
            first["city_mean_precipitation_rate_mm_per_hour"] * 0.5,
            places=9,
        )

    def test_corpus_has_no_labels_targets_or_splits(self) -> None:
        forbidden = {"flood_label", "mgb_label", "risk_category", "future_target", "split", "prediction"}
        for record in self.corpus["records"][:20]:
            self.assertTrue(forbidden.isdisjoint(record))
        self.assertFalse(self.corpus["training_effect"]["contains_flood_labels"])
        self.assertFalse(self.corpus["training_effect"]["contains_future_targets"])
        self.assertFalse(self.corpus["training_effect"]["contains_split_assignment"])

    def test_raw_and_processed_outputs_are_ignored(self) -> None:
        raw = "ml/flood-risk/data/raw/precipitation/GPM_3IMERGHH_V07B_Caloocan3_20230901T000000Z_20230901T100000Z_samples.json"
        processed = "ml/flood-risk/data/processed/imerg-caloocan-city-mean-2023-2024.json"
        self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", raw], cwd=REPO_ROOT).returncode)
        self.assertEqual(0, subprocess.run(["git", "check-ignore", "--quiet", "--", processed], cwd=REPO_ROOT).returncode)

    def test_training_remains_blocked(self) -> None:
        effect = self.corpus["training_effect"]
        self.assertFalse(effect["training_ready"])
        self.assertEqual("NOT_APPROVED", effect["training_authorization"])
        forbidden = [path for path in (WORKSPACE / "artifacts").rglob("*") if path.is_file() and path.name in {"model.keras", "model.h5", "model.tflite", "saved_model.pb"}]
        self.assertEqual([], forbidden)


if __name__ == "__main__":
    unittest.main()
