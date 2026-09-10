"""Focused Phase 3F-G multi-period CMORPH CDR study tests."""

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_PATH = REPO_ROOT / "scripts/ai/validate_cmorph_imerg_multiperiod.py"
spec = importlib.util.spec_from_file_location("validate_cmorph_imerg_multiperiod", SCRIPT_PATH)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)


class Phase3FGCmorphImergMultiperiodTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.records = module.load_records()
        cls.contract = json.loads(module.CONTRACT_PATH.read_text(encoding="utf-8"))
        cls.selection = json.loads(module.SELECTION_MANIFEST.read_text(encoding="utf-8"))
        cls.report = json.loads(module.REPORT_PATH.read_text(encoding="utf-8"))

    def test_selection_is_deterministic_and_imerG_only(self) -> None:
        selected = module.select_periods(self.records, self.contract)
        self.assertEqual(self.selection, selected)
        self.assertTrue(self.selection["selection_algorithm"]["selection_is_independent_of_cmorph"])
        self.assertEqual(4, len(self.selection["selected_periods"]))
        self.assertEqual({"LOW_RAIN", "MODERATE_RAIN", "HIGH_RAIN", "VERY_HIGH_RAIN"}, {item["rainfall_regime"] for item in self.selection["selected_periods"]})

    def test_periods_are_72_hours_and_inside_split_boundaries(self) -> None:
        ranges = module.split_ranges(self.contract)
        for period in self.selection["selected_periods"]:
            start = module.parse_utc(period["start_utc"])
            end = module.parse_utc(period["end_utc_exclusive"])
            self.assertEqual(timedelta(hours=72), end - start)
            split_start, split_end = ranges[period["split"]]
            self.assertGreaterEqual(start, split_start)
            self.assertLessEqual(end, split_end)

    def test_preflight_stays_below_acquisition_guard(self) -> None:
        plan = module.preflight(self.selection)
        self.assertEqual(290, plan["period_required_file_count"])
        self.assertLessEqual(plan["required_new_file_count"], module.MAX_NEW_FILES)
        self.assertLessEqual(plan["estimated_download_bytes"], module.MAX_NEW_BYTES)
        self.assertTrue(plan["size_guard_passed"])

    def test_window_counts_and_partial_gap_behavior(self) -> None:
        start = datetime(2023, 9, 1, tzinfo=timezone.utc)
        timestamps = [(start + timedelta(minutes=30 * index)).strftime("%Y-%m-%dT%H:%M:%SZ") for index in range(144)]
        imerg = {timestamp: 1.0 for timestamp in timestamps}
        cmorph = dict(imerg)
        sliding, non_overlapping = module.histories_for_period(imerg, cmorph, timestamps[0], (start + timedelta(hours=72)).strftime("%Y-%m-%dT%H:%M:%SZ"))
        self.assertEqual(97, len(sliding))
        self.assertEqual(3, len(non_overlapping))
        del cmorph[timestamps[50]]
        sliding, non_overlapping = module.histories_for_period(imerg, cmorph, timestamps[0], (start + timedelta(hours=72)).strftime("%Y-%m-%dT%H:%M:%SZ"))
        self.assertEqual(49, len(sliding))
        self.assertEqual(2, len(non_overlapping))

    def test_selected_periods_do_not_overlap(self) -> None:
        periods = sorted(self.selection["selected_periods"], key=lambda item: item["start_utc"])
        for current, following in zip(periods, periods[1:]):
            self.assertLessEqual(current["end_utc_exclusive"], following["start_utc"])

    def test_regime_labels_are_research_strata(self) -> None:
        self.assertIn("not operational hazard", self.selection["regime_definition"])
        self.assertEqual("approximately candidate p10", self.selection["selection_algorithm"]["regime_quantile_labels"]["LOW_RAIN"])
        self.assertEqual("approximately candidate p40", self.selection["selection_algorithm"]["regime_quantile_labels"]["MODERATE_RAIN"])
        self.assertEqual("approximately candidate p70", self.selection["selection_algorithm"]["regime_quantile_labels"]["HIGH_RAIN"])
        self.assertEqual("approximately candidate p90", self.selection["selection_algorithm"]["regime_quantile_labels"]["VERY_HIGH_RAIN"])

    def test_report_contains_decision_counts_and_cdr_rt_distinction(self) -> None:
        self.assertEqual("CMORPH_CDR_MULTI_PERIOD_INSUFFICIENT_EVIDENCE", self.report["decision"])
        self.assertEqual(576, self.report["overall_missingness"]["expected_interval_count"])
        self.assertEqual(576, self.report["overall_missingness"]["paired_available_count"])
        self.assertEqual(388, self.report["overall_sliding_window_count"])
        self.assertEqual(12, self.report["overall_non_overlapping_window_count"])
        self.assertIn("not CMORPH RT", self.report["product_identity"]["cdr_vs_rt"])
        self.assertFalse(self.report["cross_period_consistency"]["approval_threshold_applied"])

    def test_frozen_governance_and_no_production_activation(self) -> None:
        self.assertEqual(module._phase_3ff.MODEL_SHA256, module._phase_3ff.sha256(module.MODEL_PATH))
        self.assertEqual(module._phase_3ff.PREPROCESSING_SHA256, module._phase_3ff.sha256(module.PREPROCESSING_PATH))
        provider = (REPO_ROOT / "src/Services/DrrmNoApprovedLiveRainfallHistoryProvider.php").read_text(encoding="utf-8")
        self.assertIn("NO_APPROVED_LIVE_SOURCE", provider)
        self.assertIn("'status' => 'UNAVAILABLE'", provider)
        self.assertIn("'history' => null", provider)
        source = SCRIPT_PATH.read_text(encoding="utf-8").lower()
        for forbidden in ("flood_probability", "risk_level", "mgb", "warning", "evacuation"):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
