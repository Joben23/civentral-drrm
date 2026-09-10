"""Focused Phase 3F-F CMORPH/IMERG compatibility tests."""

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_PATH = REPO_ROOT / "scripts/ai/validate_cmorph_imerg_compatibility.py"
spec = importlib.util.spec_from_file_location("validate_cmorph_imerg_compatibility", SCRIPT_PATH)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class Phase3FFCmorphImergCompatibilityTest(unittest.TestCase):
    def test_documented_rate_conversion(self) -> None:
        self.assertEqual(1.0, module.cmorph_rate_to_mm(2.0))

    def test_fill_nonfinite_and_negative_values_are_rejected(self) -> None:
        for value in (float("nan"), float("inf"), -0.1, "nodata"):
            with self.assertRaises(ValueError):
                module.cmorph_rate_to_mm(value)

    def test_aligned_48_observation_windows_are_required(self) -> None:
        start = datetime(2023, 9, 1, tzinfo=timezone.utc)
        timestamps = [(start + timedelta(minutes=30 * index)).strftime("%Y-%m-%dT%H:%M:%SZ") for index in range(96)]
        imerg = {timestamp: 1.0 for timestamp in timestamps}
        cmorph = {timestamp: 1.0 for timestamp in timestamps}
        self.assertEqual(49, len(module.histories(imerg, cmorph)))

        gap = {timestamp: cmorph[timestamp] for timestamp in timestamps[:49]}
        del gap[timestamps[24]]
        self.assertEqual([], module.histories(imerg, gap))

    def test_tie_aware_average_ranks(self) -> None:
        ranks = module.average_ranks([0.0, 0.0, 1.0, 2.0])
        self.assertEqual([1.5, 1.5, 3.0, 4.0], ranks.tolist())

    def test_missing_expected_timestamp_is_reported(self) -> None:
        timestamps = module.expected_timestamps()
        imerg = {timestamp: 1.0 for timestamp in timestamps}
        cmorph = dict(imerg)
        del cmorph[timestamps[10]]
        report = module.missingness(imerg, cmorph)
        self.assertEqual(96, report["expected_interval_count"])
        self.assertEqual(1, report["cmorph_missing_count"])
        self.assertEqual(1, report["paired_missing_count"])
        self.assertEqual(0, report["imerg_missing_count"])

    def test_time_metadata_requires_30_minute_bounds(self) -> None:
        class Variable:
            def __init__(self, shape, units=None, calendar=None):
                self.shape = shape
                self.units = units
                self.calendar = calendar

            def __getitem__(self, key):
                return [0, 1800] if len(self.shape) == 1 else [[0, 1800], [1800, 3600]]

        class Dataset:
            variables = {
                "time": Variable((2,), "seconds since 1970-01-01 00:00:00", "standard"),
                "time_bounds": Variable((2, 2)),
            }

        module.validate_time_metadata(Dataset())

        class InvalidDataset(Dataset):
            variables = {
                "time": Variable((2,), "minutes since 1970-01-01 00:00:00", "standard"),
                "time_bounds": Variable((2, 2)),
            }

        with self.assertRaises(RuntimeError):
            module.validate_time_metadata(InvalidDataset())

    def test_canonical_feature_builder_and_frozen_preprocessing_are_declared(self) -> None:
        self.assertEqual(57, len(module.feature_names()))
        self.assertEqual("train", json.loads(module.PREPROCESSING_PATH.read_text(encoding="utf-8"))["fit_split"])
        self.assertEqual(module.MODEL_SHA256, module.sha256(module.MODEL_PATH))
        self.assertEqual(module.PREPROCESSING_SHA256, module.sha256(module.PREPROCESSING_PATH))

    def test_report_decision_and_production_provider_remain_research_only(self) -> None:
        report_path = module.REPORT_PATH
        report = json.loads(report_path.read_text(encoding="utf-8"))
        self.assertEqual("CMORPH_COMPATIBILITY_INSUFFICIENT_EVIDENCE", report["decision"])
        self.assertEqual(96, report["missingness"]["expected_interval_count"])
        self.assertEqual(49, report["feature_and_model_comparison"]["window_count"])
        self.assertEqual(48, report["acquisition"]["required_file_count"])
        self.assertEqual(48, report["acquisition"]["available_file_count"])
        self.assertEqual(0, report["acquisition"]["downloaded_file_count"])
        self.assertIn("CMORPH CDR", report["source_identity"]["cmorph_stream_distinction"])
        self.assertIn("not the CMORPH RT stream", report["source_identity"]["cmorph_stream_distinction"])
        self.assertIn("cmorph_scaled_abs_gt_3_fraction", report["feature_and_model_comparison"])
        provider = (REPO_ROOT / "src/Services/DrrmNoApprovedLiveRainfallHistoryProvider.php").read_text(encoding="utf-8")
        self.assertIn("NO_APPROVED_LIVE_SOURCE", provider)
        self.assertIn("'status' => 'UNAVAILABLE'", provider)
        self.assertIn("'history' => null", provider)

    def test_no_flood_or_module4_semantics_added(self) -> None:
        source = SCRIPT_PATH.read_text(encoding="utf-8").lower()
        for forbidden in ("flood_probability", "risk_level", "mgb", "warning", "evacuation"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
