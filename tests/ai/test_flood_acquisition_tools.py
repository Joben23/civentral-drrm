"""Phase 3B2 acquisition tests; generated samples are TEST_ONLY and never training data."""

from __future__ import annotations

import copy
import hashlib
import json
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = REPO_ROOT / "scripts" / "ai"
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
sys.path.insert(0, str(SCRIPT_DIR))

from flood_acquisition_common import (  # noqa: E402
    AcquisitionValidationError,
    analyze_caloocan_imerg_grid,
    normalize_imerg_samples,
    precipitation_coverage_report,
    validate_evidence_extraction,
    validate_imerg_acquisition,
)
from flood_data_common import DEFAULT_REVIEWED_DATA, load_records, read_json, validate_manifest  # noqa: E402
from pilot_data_common import build_pilot_dataset, load_pilot_inputs  # noqa: E402


class FloodAcquisitionToolsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.manifest = read_json(WORKSPACE / "manifests" / "source-manifest.json")
        cls.sources = {item["source_id"]: item for item in cls.manifest["sources"]}
        cls.dromic = cls.sources["dswd_dromic_caloocan_flood_2019-06-24"]
        cls.imerg = cls.sources["nasa_gpm_imerg_final_hh_v07"]
        cls.extraction = read_json(WORKSPACE / "manifests" / "dromic-caloocan-2019-evidence-extraction.json")
        cls.acquisition = read_json(WORKSPACE / "manifests" / "imerg-caloocan-2019-exploratory-acquisition.json")

    @staticmethod
    def codes(issues):
        return {issue.code for issue in issues}

    def make_sample_fixture(self, values=(2.0, 4.0)):
        temporary = tempfile.TemporaryDirectory()
        root = Path(temporary.name)
        relative = Path("ml/flood-risk/data/raw/precipitation/TEST_ONLY_SYNTHETIC_samples.json")
        raw_path = root / relative
        raw_path.parent.mkdir(parents=True)
        start_ms = int(datetime(2019, 6, 23, 16, tzinfo=timezone.utc).timestamp() * 1000)
        samples = []
        for index, value in enumerate(values):
            samples.append({
                "location": {"x": 121.05, "y": 14.75, "spatialReference": {"wkid": 4326}},
                "locationId": 0,
                "value": value,
                "rasterId": index + 1,
                "resolution": 0.1,
                "attributes": {"variable": "precipitation", "stdtime": start_ms + index * 1800000},
            })
        payload = json.dumps({"fixture_classification": "TEST_ONLY_SYNTHETIC_NOT_FOR_TRAINING", "samples": samples}, separators=(",", ":")).encode()
        raw_path.write_bytes(payload)
        checksum = hashlib.sha256(payload).hexdigest()
        source = copy.deepcopy(self.imerg)
        source.update({"local_file": relative.as_posix(), "retrieved_at": "2026-09-09T00:00:00Z", "sha256": checksum})
        source["artifacts"] = [{
            "artifact_id": "test_only_samples", "artifact_role": "IMAGESERVER_SAMPLES",
            "original_filename": raw_path.name,
            "official_url": "https://gis.earthdata.nasa.gov/portal/rest/services/GESDISC/GPM_3IMERGHH/ImageServer/getSamples",
            "retrieved_at": "2026-09-09T00:00:00Z", "media_type": "application/json",
            "byte_length": len(payload), "sha256": checksum, "local_file": relative.as_posix(),
            "source_organization": "NASA Earthdata / GES DISC", "title": "TEST_ONLY synthetic parser fixture",
            "issued_at": None, "issued_at_source_text": None, "issued_timezone": None,
            "status": "ACQUIRED_VALIDATED", "notes": "TEST_ONLY SYNTHETIC NOT_FOR_TRAINING",
        }]
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["acquisition_window"].update({"start_utc": "2019-06-23T16:00:00Z", "end_utc_exclusive": "2019-06-23T17:00:00Z"})
        acquisition["spatial_request"]["requested_grid_centers"] = [{"longitude": 121.05, "latitude": 14.75}]
        acquisition["artifact_ids"] = ["test_only_samples"]
        acquisition["validated_coverage"].update({"expected_interval_count_per_cell": 2, "expected_grid_cell_count": 1, "expected_observation_count": 2, "raw_sample_chunk_count": 1})
        return temporary, root, source, acquisition, raw_path

    def test_dromic_raw_evidence_requires_checksum(self):
        manifest = {"manifest_version": "1.1.0", "sources": [copy.deepcopy(self.dromic)]}
        manifest["sources"][0]["artifacts"][0]["sha256"] = None
        self.assertIn("MISSING_SOURCE_ARTIFACT_CHECKSUM", self.codes(validate_manifest(manifest)))

    def test_dromic_acquisition_does_not_confirm_label(self):
        self.assertEqual([], validate_evidence_extraction(self.extraction, self.dromic))
        self.assertEqual("UNKNOWN", self.extraction["label_status"])
        self.assertFalse(self.extraction["training_eligible"])

    def test_report_issue_time_is_not_onset(self):
        extraction = copy.deepcopy(self.extraction)
        extraction["source_documents"][0]["issue_time_is_event_onset"] = True
        self.assertIn("REPORT_ISSUE_TIME_USED_AS_ONSET", self.codes(validate_evidence_extraction(extraction, self.dromic)))

    def test_secondary_evidence_cannot_auto_confirm(self):
        extraction = copy.deepcopy(self.extraction)
        extraction["secondary_corroboration"] = [{"classification": "SECONDARY_CORROBORATION"}]
        extraction.update({"label_status": "FLOOD_CONFIRMED", "human_approved": True})
        self.assertIn("SECONDARY_EVIDENCE_AUTO_CONFIRMED_LABEL", self.codes(validate_evidence_extraction(extraction, self.dromic)))

    def test_imerg_product_and_version_are_required(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["product"]["processing_id"] = None
        self.assertIn("INVALID_IMERG_PRODUCT_METADATA", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_observed_and_forecast_precipitation_stay_separate(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["normalization"]["observed_forecast_separation"] = False
        self.assertIn("OBSERVED_FORECAST_SEPARATION_DISABLED", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_earthdata_secrets_are_rejected(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["access"]["earthdata_token"] = "TEST_ONLY_DO_NOT_USE"
        self.assertIn("SENSITIVE_ACQUISITION_FIELD", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_raw_checksum_mismatch_fails(self):
        temporary, root, source, acquisition, raw_path = self.make_sample_fixture()
        self.addCleanup(temporary.cleanup)
        raw_path.write_bytes(raw_path.read_bytes() + b" ")
        with self.assertRaises(AcquisitionValidationError) as raised:
            normalize_imerg_samples(acquisition, source, repo_root=root)
        self.assertEqual("INVALID_SOURCE_PROVENANCE", raised.exception.code)

    def test_missing_or_fill_rainfall_is_rejected(self):
        temporary, root, source, acquisition, _ = self.make_sample_fixture(values=(None, 4.0))
        self.addCleanup(temporary.cleanup)
        with self.assertRaises(AcquisitionValidationError) as raised:
            normalize_imerg_samples(acquisition, source, repo_root=root)
        self.assertEqual("MISSING_OR_FILL_PRECIPITATION", raised.exception.code)

    def test_units_must_be_verified_before_normalization(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["normalization"]["source_units_verified"] = False
        self.assertIn("UNVERIFIED_IMERG_UNITS", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_utc_timestamps_are_preserved(self):
        temporary, root, source, acquisition, _ = self.make_sample_fixture()
        self.addCleanup(temporary.cleanup)
        result = normalize_imerg_samples(acquisition, source, repo_root=root)
        self.assertEqual("2019-06-23T16:00:00Z", result["records"][0]["observed_at_start"])
        self.assertEqual("2019-06-23T16:30:00Z", result["records"][0]["observed_at_end"])

    def test_non_utc_window_is_rejected(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["acquisition_window"]["start_utc"] = "2019-06-24T00:00:00+08:00"
        self.assertIn("NON_UTC_TIMESTAMP", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_timezone_conversion_requires_explicit_review(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["acquisition_window"]["timezone_assumption_status"] = "APPROVED"
        self.assertIn("TIMEZONE_CONVERSION_NOT_EXPLICIT", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_acquisition_window_cannot_be_target_window(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["acquisition_window"]["target_window"] = True
        self.assertIn("ACQUISITION_WINDOW_MISCLASSIFIED", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_spatial_grid_metadata_is_preserved(self):
        temporary, root, source, acquisition, _ = self.make_sample_fixture()
        self.addCleanup(temporary.cleanup)
        record = normalize_imerg_samples(acquisition, source, repo_root=root)["records"][0]
        self.assertEqual("GPM_3IMERGHH_V07B:lat=14.75:lon=121.05", record["grid_cell_id"])
        self.assertIn("0.1 degree", record["source_resolution"])

    def test_coarse_spatial_limitation_is_mandatory(self):
        acquisition = copy.deepcopy(self.acquisition)
        acquisition["spatial_request"]["coarse_resolution_limitation_retained"] = False
        self.assertIn("MISSING_COARSE_SPATIAL_LIMITATION", self.codes(validate_imerg_acquisition(acquisition, self.imerg)))

    def test_missing_interval_coverage_is_detected(self):
        temporary, root, source, acquisition, _ = self.make_sample_fixture()
        self.addCleanup(temporary.cleanup)
        normalized = normalize_imerg_samples(acquisition, source, repo_root=root)
        normalized["records"].pop()
        report = precipitation_coverage_report(normalized, acquisition)
        self.assertEqual(1, report["missing_interval_count"])
        self.assertFalse(report["complete"])

    def test_raw_acquisition_does_not_create_negative_label(self):
        event = read_json(WORKSPACE / "manifests" / "flood-event-registry.json")["events"][0]
        self.assertEqual("UNKNOWN", event["label_status"])
        self.assertIsNone(event["negative_evidence_basis"])
        self.assertEqual([], event["candidate_windows"])

    def test_acquisition_alone_does_not_create_training_row(self):
        registry, precipitation, manifest, sources, protocol = load_pilot_inputs()
        rows, report = build_pilot_dataset(registry, precipitation, manifest, sources, protocol, dataset_version="PHASE_3B2_TEST", created_at="2026-09-09T00:00:00Z")
        self.assertEqual([], rows)
        self.assertEqual(0, report["positive_count"])
        self.assertEqual(0, report["negative_count"])
        self.assertFalse(report["training_ready"])
        self.assertEqual("NOT_APPROVED", report["training_authorization_status"])

    def test_canonical_label_loader_does_not_reinterpret_precipitation(self):
        records = load_records(DEFAULT_REVIEWED_DATA)
        self.assertFalse(any("precipitation" in Path(record.source_file).parts for record in records))

    def test_real_local_acquisition_and_spatial_analysis_validate(self):
        first_raw = REPO_ROOT / self.imerg["artifacts"][4]["local_file"]
        if not first_raw.is_file():
            self.skipTest("Ignored real IMERG artifacts are not present in this checkout.")
        self.assertEqual([], validate_imerg_acquisition(self.acquisition, self.imerg))
        precipitation = normalize_imerg_samples(self.acquisition, self.imerg)
        report = precipitation_coverage_report(precipitation, self.acquisition)
        self.assertEqual((768, 0, 0, True), (report["actual_observation_count"], report["missing_interval_count"], report["duplicate_interval_count"], report["complete"]))
        spatial = analyze_caloocan_imerg_grid(self.acquisition)
        self.assertEqual(3, spatial["caloocan_intersecting_cell_count"])
        self.assertFalse(spatial["mapping_method_selected"])


if __name__ == "__main__":
    unittest.main()
