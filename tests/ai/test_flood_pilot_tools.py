"""Phase 3B1 pilot tooling tests using prohibited synthetic fixtures only."""

from __future__ import annotations

import copy
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = REPO_ROOT / "scripts" / "ai"
FIXTURES = Path(__file__).resolve().parent / "fixtures"
sys.path.insert(0, str(SCRIPT_DIR))

from flood_data_common import load_manifest, read_json, validate_manifest  # noqa: E402
from pilot_data_common import (  # noqa: E402
    AggregationError,
    build_pilot_dataset,
    aggregate_antecedent_precipitation,
    validate_event_registry,
    validate_mapping,
    validate_precipitation_data,
)


class FloodPilotToolsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        fixture = read_json(FIXTURES / "pilot-governance.fixture.json")
        cls.fixture = fixture
        cls.manifest = fixture["source_manifest"]
        cls.sources = {source["source_id"]: source for source in cls.manifest["sources"]}
        cls.registry = fixture["event_registry"]
        cls.protocol = read_json(REPO_ROOT / "ml" / "flood-risk" / "config" / "label-protocol-v1.json")
        cls.cutoff = datetime(2025, 1, 1, 23, 0, tzinfo=timezone.utc)

    def codes(self, issues):
        return {issue.code for issue in issues}

    def precipitation_records(self, *, cells=None, hours=72, value_by_cell=None):
        cells = cells or ["TEST_CELL_A"]
        value_by_cell = value_by_cell or {cell: 1.0 for cell in cells}
        records = []
        for cell_index, cell_id in enumerate(cells):
            start = self.cutoff - timedelta(hours=hours)
            index = 0
            while start < self.cutoff:
                end = start + timedelta(minutes=30)
                value = float(value_by_cell[cell_id])
                records.append({
                    "observation_id": f"TEST_ONLY_{cell_index}_{index:04d}",
                    "data_type": "OBSERVED_PRECIPITATION",
                    "source_id": "fixture_observed_precipitation",
                    "product_version": "fixture-v1",
                    "observed_at_start": start.isoformat().replace("+00:00", "Z"),
                    "observed_at_end": end.isoformat().replace("+00:00", "Z"),
                    "latitude": 14.75,
                    "longitude": 121.05,
                    "grid_cell_id": cell_id,
                    "native_value": value,
                    "native_units": "mm",
                    "precipitation_mm": value,
                    "normalization_method": "NO_CONVERSION_NATIVE_MM_ACCUMULATION",
                    "quality_flag": "VALIDATED",
                    "retrieved_at": "2025-01-03T00:00:00Z",
                    "raw_file_reference": "tests/ai/fixtures/README.md",
                    "raw_file_checksum": "4f05df65f83d09ee8dbd36ae60542d865bf8cae8ebdbfc934ae746a08e689860",
                    "source_resolution": "TEST_ONLY 0.1 degree grid",
                })
                index += 1
                start = end
        return records

    def precipitation_payload(self, records):
        return {
            "schema_version": "1.0.0",
            "dataset_classification": "TEST_ONLY_SYNTHETIC_NOT_FOR_TRAINING",
            "records": records,
        }

    def mapping(self):
        return copy.deepcopy(self.registry["events"][0]["candidate_windows"][0]["precipitation_mapping"])

    def build(self, registry=None, precipitation=None):
        return build_pilot_dataset(
            registry or copy.deepcopy(self.registry),
            precipitation or self.precipitation_payload(self.precipitation_records()),
            self.manifest,
            self.sources,
            self.protocol,
            dataset_version="TEST_ONLY_PILOT_V1",
            created_at="2025-01-04T00:00:00Z",
            validate_city=False,
        )

    def test_extended_source_registry_is_valid(self):
        self.assertEqual([], validate_manifest(self.manifest, repo_root=REPO_ROOT))

    def test_real_registry_keeps_imerg_and_era5_unapproved(self):
        manifest, sources = load_manifest()
        self.assertEqual("1.1.0", manifest["manifest_version"])
        imerg = sources["nasa_gpm_imerg_final_hh_v07"]
        era5 = sources["ecmwf_era5_land_hourly"]
        self.assertEqual(("V07", "30 minutes", "PROPOSED", "NOT_ACQUIRED"), (
            imerg["product_version"], imerg["temporal_resolution"], imerg["status"], imerg["availability_status"]
        ))
        self.assertEqual("OPTIONAL_PRECIPITATION_CROSS_CHECK", era5["source_role"])
        self.assertNotEqual("APPROVED_FOR_PILOT", era5["status"])

    def test_pagasa_data_is_explicitly_not_acquired(self):
        _, sources = load_manifest()
        self.assertEqual("PAGASA_DATA_NOT_ACQUIRED", sources["pagasa_climate_data_request_pending"]["availability_status"])

    def test_unknown_cannot_become_no_flood(self):
        registry = copy.deepcopy(self.registry)
        event = registry["events"][0]
        event["label_status"] = "UNKNOWN"
        event["training_eligible"] = True
        issues = validate_event_registry(registry, self.sources, validate_city=False)
        self.assertIn("UNKNOWN_CANNOT_BE_TRAINING_ELIGIBLE", self.codes(issues))
        rows, report = self.build(registry=registry)
        self.assertEqual([], rows)
        self.assertEqual(0, report["negative_count"])

    def test_missing_report_does_not_create_negative(self):
        registry = copy.deepcopy(self.registry)
        event = registry["events"][0]
        event.update({
            "label_status": "NO_FLOOD_CONFIRMED",
            "source_ids": ["fixture_pilot_negative_evidence"],
            "negative_evidence_basis": "EXPLICIT_LGU_MONITORING_NO_FLOOD",
            "evidence_summary": "No report found in the synthetic test archive.",
        })
        issues = validate_event_registry(registry, self.sources, validate_city=False)
        self.assertIn("PROHIBITED_MISSING_REPORT_NEGATIVE", self.codes(issues))

    def test_unreviewed_positive_event_is_excluded(self):
        registry = copy.deepcopy(self.registry)
        registry["events"][0].update({
            "review_status": "REQUIRES_HUMAN_REVIEW",
            "reviewed_by": None,
            "reviewed_at": None,
            "training_eligible": False,
        })
        rows, report = self.build(registry=registry)
        self.assertEqual([], rows)
        self.assertIn("EVENT_NOT_REVIEWED", report["exclusions"][0]["reasons"])

    def test_no_rainfall_row_is_excluded(self):
        rows, report = self.build(precipitation=self.precipitation_payload([]))
        self.assertEqual([], rows)
        self.assertIn("RAINFALL_COVERAGE_INCOMPLETE", report["exclusions"][0]["reasons"])

    def test_missing_rainfall_is_never_filled_with_zero(self):
        records = self.precipitation_records(hours=1)
        records[0]["precipitation_mm"] = None
        issues = validate_precipitation_data(self.precipitation_payload(records), self.sources)
        self.assertIn("MISSING_OR_INVALID_PRECIPITATION", self.codes(issues))
        with self.assertRaises(AggregationError) as raised:
            aggregate_antecedent_precipitation(records, self.mapping(), self.cutoff, 1)
        self.assertEqual("MISSING_RAINFALL_NOT_IMPUTED", raised.exception.code)

    def test_post_cutoff_rainfall_is_not_an_antecedent_feature(self):
        records = self.precipitation_records(hours=1)
        future = copy.deepcopy(records[-1])
        future.update({
            "observation_id": "TEST_ONLY_FUTURE",
            "observed_at_start": self.cutoff.isoformat().replace("+00:00", "Z"),
            "observed_at_end": (self.cutoff + timedelta(minutes=30)).isoformat().replace("+00:00", "Z"),
            "native_value": 1000.0,
            "precipitation_mm": 1000.0,
        })
        result = aggregate_antecedent_precipitation(records + [future], self.mapping(), self.cutoff, 1)
        self.assertEqual(2.0, result["precipitation_mm"])
        self.assertNotIn("TEST_ONLY_FUTURE", result["observation_ids"])

    def test_observed_and_forecast_rainfall_remain_separate(self):
        records = self.precipitation_records(hours=1)
        records[0]["data_type"] = "FORECAST_PRECIPITATION"
        issues = validate_precipitation_data(self.precipitation_payload(records), self.sources)
        self.assertIn("FORECAST_RECORD_IN_OBSERVED_DATASET", self.codes(issues))

    def test_source_provenance_is_required(self):
        registry = copy.deepcopy(self.registry)
        registry["events"][0]["source_ids"] = []
        issues = validate_event_registry(registry, self.sources, validate_city=False)
        self.assertIn("MISSING_EVENT_PROVENANCE", self.codes(issues))

    def test_source_version_metadata_is_preserved(self):
        records = self.precipitation_records(hours=1)
        self.assertEqual([], validate_precipitation_data(self.precipitation_payload(records), self.sources))
        result = aggregate_antecedent_precipitation(records, self.mapping(), self.cutoff, 1)
        self.assertEqual("fixture-v1", result["product_version"])

    def test_unsupported_source_is_rejected(self):
        records = self.precipitation_records(hours=1)
        records[0]["source_id"] = "unsupported_source"
        issues = validate_precipitation_data(self.precipitation_payload(records), self.sources)
        self.assertIn("UNSUPPORTED_PRECIPITATION_SOURCE", self.codes(issues))

    def test_invalid_coordinates_are_rejected(self):
        registry = copy.deepcopy(self.registry)
        registry["events"][0]["latitude"] = 999
        issues = validate_event_registry(registry, self.sources, validate_city=False)
        self.assertIn("INVALID_EVENT_COORDINATES", self.codes(issues))

    def test_spatial_mapping_is_deterministic(self):
        records = self.precipitation_records(cells=["TEST_CELL_A", "TEST_CELL_B"], hours=1, value_by_cell={"TEST_CELL_A": 1, "TEST_CELL_B": 3})
        mapping = self.mapping()
        mapping["mapping_method"] = "AREA_WEIGHTED_POLYGON"
        mapping["coverage_fraction"] = 1
        mapping["source_grid_cells"] = [
            {"grid_cell_id": "TEST_CELL_B", "weight": 0.75},
            {"grid_cell_id": "TEST_CELL_A", "weight": 0.25},
        ]
        self.assertEqual([], validate_mapping(mapping, "TEST_ONLY_MAPPING"))
        first = aggregate_antecedent_precipitation(records, mapping, self.cutoff, 1)
        mapping["source_grid_cells"].reverse()
        second = aggregate_antecedent_precipitation(records, mapping, self.cutoff, 1)
        self.assertEqual(first, second)
        self.assertEqual(5.0, first["precipitation_mm"])

    def test_aggregations_use_complete_pre_cutoff_intervals(self):
        records = self.precipitation_records(hours=3)
        result = aggregate_antecedent_precipitation(records, self.mapping(), self.cutoff, 3)
        self.assertEqual(6.0, result["precipitation_mm"])
        del records[1]
        with self.assertRaises(AggregationError) as raised:
            aggregate_antecedent_precipitation(records, self.mapping(), self.cutoff, 3)
        self.assertEqual("RAINFALL_COVERAGE_INCOMPLETE", raised.exception.code)

    def test_source_resolution_metadata_is_retained(self):
        result = aggregate_antecedent_precipitation(
            self.precipitation_records(hours=1), self.mapping(), self.cutoff, 1
        )
        self.assertEqual("TEST_ONLY 0.1 degree grid", result["source_resolution"])
        mapping = self.mapping()
        self.assertIn("limitation_note", mapping)
        self.assertIn("distance_m", mapping)
        self.assertIn("coverage_fraction", mapping)

    def test_test_fixtures_cannot_produce_training_rows(self):
        rows, report = self.build()
        self.assertEqual([], rows)
        self.assertEqual("TEST_ONLY_SYNTHETIC_NOT_FOR_TRAINING", report["dataset_classification"])
        self.assertEqual("NOT_APPROVED", report["training_authorization_status"])
        self.assertFalse(report["training_ready"])

    def test_pilot_manifest_is_deterministic_with_fixed_timestamp(self):
        _, first = self.build()
        _, second = self.build()
        self.assertEqual(first, second)
        self.assertEqual(first["dataset_sha256"], second["dataset_sha256"])

    def test_tiny_pilot_never_becomes_training_ready(self):
        _, report = self.build()
        self.assertFalse(report["training_ready"])
        self.assertEqual("NOT_APPROVED", report["training_authorization_status"])
        self.assertFalse(report["tensorflow_training_performed"])
        self.assertFalse(report["model_artifact_created"])

    def test_june_2019_event_remains_unknown_and_ineligible(self):
        registry = read_json(REPO_ROOT / "ml" / "flood-risk" / "manifests" / "flood-event-registry.json")
        event = registry["events"][0]
        self.assertEqual("UNKNOWN", event["label_status"])
        self.assertEqual("REQUIRES_HUMAN_REVIEW", event["review_status"])
        self.assertFalse(event["training_eligible"])
        self.assertEqual([], event["candidate_windows"])


if __name__ == "__main__":
    unittest.main()
