"""TF-B2B governed evidence tests; no labels, training, or database writes."""

from __future__ import annotations

import copy
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
MANIFEST_PATH = WORKSPACE / "manifests/tf-b2b-event-evidence-adjudication.json"
BACKLOG_PATH = WORKSPACE / "manifests/tf-b2b-negative-evidence-acquisition-backlog.json"
sys.path.insert(0, str(REPO_ROOT / "scripts/ai"))

from validate_tf_b2b_event_evidence import (  # noqa: E402
    BACKLOG_SCHEMA,
    EVIDENCE_SCHEMA,
    OBS_KEYS,
    RepositoryPathError,
    resolve_repository_file,
    validate_evidence_manifest,
    validate_negative_backlog,
    validate_schema_instance,
)


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def walk_strings(value):
    if isinstance(value, str):
        yield value
    elif isinstance(value, dict):
        for item in value.values():
            yield from walk_strings(item)
    elif isinstance(value, list):
        for item in value:
            yield from walk_strings(item)


class TfB2BEventEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.manifest = read_json(MANIFEST_PATH)
        cls.backlog = read_json(BACKLOG_PATH)

    @staticmethod
    def codes(issues):
        return {item.code for item in issues}

    def observation(self, observation_id):
        return next(item for item in self.manifest["observations"] if item["observation_id"] == observation_id)

    @staticmethod
    def schema_codes(issues):
        return {item.code for item in issues}

    def mutable_observation(self, payload, observation_id):
        return next(item for item in payload["observations"] if item["observation_id"] == observation_id)

    def first_unreviewed_observation(self, payload):
        return next(item for item in payload["observations"] if item["mapping_status"] == "UNREVIEWED_EXPLICIT_BARANGAY_NUMBER")

    def test_repository_records_pass_fail_closed_validator(self):
        self.assertEqual([], validate_evidence_manifest(self.manifest))
        self.assertEqual([], validate_negative_backlog(self.backlog))

    def test_readiness_counts_are_computed_not_promoted(self):
        summary = self.manifest["summary"]
        expected = {
            "candidate_event_count": 7,
            "normalized_event_count": 7,
            "source_document_count": 24,
            "missing_official_document_count": 14,
            "location_observation_count": 32,
            "confirmed_flood_observation_count": 4,
            "barangay_resolved_positive_observation_count": 3,
            "confirmed_unresolved_positive_observation_count": 1,
            "human_approved_positive_count": 0,
            "training_eligible_positive_count": 0,
            "verified_negative_count": 0,
            "training_eligible_weather_event_count": 0,
        }
        for key, value in expected.items():
            self.assertEqual(value, summary[key], key)

    def test_missing_or_unmentioned_report_does_not_become_negative(self):
        self.assertFalse(self.manifest["governance"]["negative_labels_created"])
        self.assertFalse(any(item["flood_absent"] is True for item in self.manifest["observations"]))
        self.assertIn("Absence", self.backlog["negative_label_rule"])
        self.assertEqual(0, self.backlog["summary"]["verified_negative_count"])
        self.assertFalse(self.backlog["summary"]["training_use_authorized"])

    def test_city_level_evidence_cannot_become_barangay_evidence(self):
        city = [item for item in self.manifest["observations"] if item["location_type"] == "CITY"]
        self.assertGreaterEqual(len(city), 1)
        for item in city:
            self.assertIsNone(item["barangay_psgc"])
            self.assertIsNone(item["barangay_name"])
            self.assertEqual("CITY_LEVEL_ONLY", item["mapping_status"])
            self.assertFalse(item["training_eligible"])

    def test_street_and_locality_text_is_not_automatically_mapped(self):
        locations = [item for item in self.manifest["observations"] if item["location_type"] in {"STREET", "LOCALITY", "FACILITY", "BRIDGE"}]
        self.assertGreaterEqual(len(locations), 1)
        for item in locations:
            self.assertIsNone(item["barangay_psgc"])
            self.assertIsNone(item["barangay_name"])
            self.assertEqual("NO_AUTOMATIC_STREET_OR_LOCALITY_MAPPING", item["mapping_method"])

    def test_historical_barangay_176_stays_unresolved(self):
        legacy = self.observation("caloocan-2019-historical-barangay-176")
        self.assertEqual("Barangay 176", legacy["historical_barangay_name"])
        self.assertEqual("UNRESOLVED_HISTORICAL_BOUNDARY", legacy["mapping_status"])
        self.assertIsNone(legacy["barangay_psgc"])
        successors = {f"Barangay 176-{letter}" for letter in "ABCDEF"}
        self.assertFalse(any(value in successors for value in walk_strings(legacy)))

    def test_source_revisions_remain_under_seven_event_parents(self):
        event_ids = {item["event_id"] for item in self.manifest["events"]}
        self.assertEqual(7, len(event_ids))
        self.assertEqual(24, len(self.manifest["source_documents"]))
        self.assertTrue(all(item["event_id"] in event_ids for item in self.manifest["source_documents"]))
        enteng = next(item for item in self.manifest["events"] if item["event_id"].endswith("enteng"))
        self.assertEqual(10, len(enteng["source_document_ids"]))

    def test_unapproved_records_cannot_be_training_eligible(self):
        for item in self.manifest["events"] + self.manifest["observations"]:
            self.assertEqual("HUMAN_REVIEW_REQUIRED", item["review_status"])
            self.assertIsNone(item["reviewer"])
            self.assertIsNone(item["review_timestamp"])
            self.assertFalse(item["training_eligible"])
        unsafe = copy.deepcopy(self.manifest)
        unsafe["observations"][0]["training_eligible"] = True
        self.assertIn("UNSAFE_OBSERVATION_TRAINING_STATE", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_evidence_cannot_silently_become_human_approved(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["events"][0]["review_status"] = "HUMAN_APPROVED"
        unsafe["events"][0]["reviewer"] = "invented-reviewer"
        unsafe["events"][0]["review_timestamp"] = "2026-10-09T00:00:00+08:00"
        self.assertIn("FALSE_HUMAN_APPROVAL", self.codes(validate_evidence_manifest(unsafe)))

    def test_unknown_stays_unknown_and_no_project_risk_labels_exist(self):
        records = self.manifest["events"] + self.manifest["observations"]
        self.assertEqual({"UNKNOWN"}, {item["label_status"] for item in records})
        self.assertTrue({"LOW", "MODERATE", "HIGH", "CRITICAL"}.isdisjoint(item["label_status"] for item in records))
        self.assertFalse(self.manifest["governance"]["four_class_labels_created"])

    def test_mgb_and_rainfall_are_not_used_as_labels(self):
        self.assertTrue({"LF", "MF", "HF", "VHF"}.isdisjoint(set(walk_strings(self.manifest))))
        self.assertFalse(self.manifest["governance"]["mgb_susceptibility_used_as_target"])
        self.assertFalse(self.manifest["governance"]["rainfall_regression_used_as_flood_evidence"])

    def test_post_event_impacts_are_not_predictor_fields(self):
        forbidden_fields = {"affected_population", "affected_families", "evacuations", "response_actions"}
        self.assertTrue(forbidden_fields.isdisjoint(OBS_KEYS))
        self.assertTrue(all(forbidden_fields.isdisjoint(item) for item in self.manifest["observations"]))
        self.assertTrue(all(item["flood_depth"] is None for item in self.manifest["observations"]))

    def test_temporal_uncertainty_and_target_horizon_remain_unresolved(self):
        self.assertTrue(all(item["timezone"] is None for item in self.manifest["events"]))
        self.assertTrue(all(item["timestamp"] is None and item["source_timezone"] is None for item in self.manifest["observations"]))
        self.assertEqual(7, self.manifest["summary"]["unresolved_temporal_event_count"])
        self.assertFalse(self.manifest["summary"]["target_horizon_approved"])

    def test_enteng_depth_and_revision_conflict_are_evidence_only(self):
        enteng = [item for item in self.manifest["observations"] if item["event_id"].endswith("enteng")]
        self.assertEqual(11, len(enteng))
        self.assertTrue(any(item["flood_depth_source_text"] for item in enteng))
        crispulo = self.observation("enteng-2024-crispulo-street-conflicting-revision")
        self.assertEqual("enteng-caloocan-2024-09-05-crispulo-review", crispulo["episode_cluster_id"])
        self.assertIn("SOURCE_REVISION_CONFLICT", crispulo["training_ineligibility_reasons"])
        self.assertFalse(crispulo["training_eligible"])

    def test_source_provenance_hash_is_validated(self):
        unsafe = copy.deepcopy(self.manifest)
        source = next(item for item in unsafe["source_documents"] if item["source_availability"] == "ACQUIRED")
        source["source_hash"] = "0" * 64
        self.assertIn("SOURCE_HASH_MISMATCH", self.codes(validate_evidence_manifest(unsafe)))

    def test_cross_event_revision_relationship_is_rejected(self):
        unsafe = copy.deepcopy(self.manifest)
        source = next(item for item in unsafe["source_documents"] if item["source_document_id"] == "ndrrmc-enteng-2024-sitrep-03")
        source["related_document_id"] = "dromic-caloocan-2019-report-01"
        self.assertIn("CROSS_EVENT_REVISION", self.codes(validate_evidence_manifest(unsafe)))

    def test_source_access_and_revision_must_match_observation(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["observations"][0]["source_access_status"] = "ACQUIRED"
        unsafe["observations"][0]["source_revision"] = "invented"
        codes = self.codes(validate_evidence_manifest(unsafe))
        self.assertIn("SOURCE_ACCESS_MISMATCH", codes)
        self.assertIn("SOURCE_REVISION_MISMATCH", codes)

    def test_training_eligibility_remains_fail_closed_at_event_level(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["events"][0]["training_eligible"] = True
        self.assertIn("UNSAFE_EVENT_TRAINING_STATE", self.codes(validate_evidence_manifest(unsafe)))

    def test_negative_backlog_cannot_authorize_unacquired_sources(self):
        unsafe = copy.deepcopy(self.backlog)
        unsafe["candidate_sources"][0]["acquisition_status"] = "ACQUIRED"
        unsafe["candidate_sources"][0]["cannot_establish_negative_alone"] = False
        self.assertIn("UNSAFE_NEGATIVE_SOURCE", self.codes(validate_negative_backlog(unsafe)))

    def test_resolved_mapping_rejects_unresolved_confidence(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["mapping_confidence"] = "UNRESOLVED"
        self.assertIn("INCOHERENT_MAPPING_STATE", self.codes(validate_evidence_manifest(unsafe)))

    def test_resolved_mapping_rejects_unreviewed_method(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["mapping_method"] = "EXPLICIT_BARANGAY_NUMBER_MAPPING_NOT_REVIEWED"
        self.assertIn("INCOHERENT_MAPPING_STATE", self.codes(validate_evidence_manifest(unsafe)))

    def test_resolved_mapping_rejects_psgc_absent_from_governed_reference(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["barangay_psgc"] = "1380100999"
        self.assertIn("UNGOVERNED_PSGC_NAME_PAIR", self.codes(validate_evidence_manifest(unsafe)))

    def test_resolved_mapping_rejects_wrong_canonical_name(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["barangay_name"] = "Barangay 177"
        self.assertIn("UNGOVERNED_PSGC_NAME_PAIR", self.codes(validate_evidence_manifest(unsafe)))

    def test_unreviewed_mapping_rejects_current_psgc_and_name(self):
        unsafe = copy.deepcopy(self.manifest)
        unreviewed = self.first_unreviewed_observation(unsafe)
        unreviewed["barangay_psgc"] = "1380100117"
        unreviewed["barangay_name"] = "Barangay 117"
        self.assertIn("UNAPPROVED_BARANGAY_MAPPING", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_176_rejects_successor_assignment(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176")
        legacy.update({
            "barangay_psgc": "1380100189",
            "barangay_name": "Barangay 176-A",
            "mapping_status": "RESOLVED_CURRENT_PSGC",
            "mapping_method": "EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE",
            "mapping_confidence": "HIGH",
        })
        self.assertIn("LEGACY_176_IDENTITY_VIOLATION", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_176_cannot_bypass_by_clearing_historical_name(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176")
        legacy.update({
            "historical_barangay_name": None,
            "barangay_psgc": "1380100190",
            "barangay_name": "Barangay 176-B",
            "mapping_status": "RESOLVED_CURRENT_PSGC",
            "mapping_method": "EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE",
            "mapping_confidence": "HIGH",
        })
        self.assertIn("LEGACY_176_IDENTITY_VIOLATION", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_176_cannot_bypass_by_altering_display_fields(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176")
        legacy.update({
            "historical_barangay_name": "Legacy administrative area",
            "reported_location_text": "Legacy administrative area",
            "barangay_psgc": "1380100191",
            "barangay_name": "Barangay 176-C",
            "mapping_status": "RESOLVED_CURRENT_PSGC",
            "mapping_method": "EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE",
            "mapping_confidence": "HIGH",
        })
        self.assertIn("LEGACY_176_IDENTITY_VIOLATION", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_176_stable_identity_cannot_be_removed(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176")
        legacy["observation_id"] = "caloocan-2019-renamed-location"
        self.assertIn("LEGACY_176_IDENTITY_VIOLATION", self.codes(validate_evidence_manifest(unsafe)))

    def test_historical_176_source_identity_cannot_be_duplicated_as_successor(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = copy.deepcopy(self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176"))
        legacy.update({
            "observation_id": "caloocan-2019-fabricated-176-successor",
            "barangay_psgc": "1380100194",
            "barangay_name": "Barangay 176-F",
            "mapping_status": "RESOLVED_CURRENT_PSGC",
            "mapping_method": "EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE",
            "mapping_confidence": "HIGH",
        })
        unsafe["observations"].append(legacy)
        self.assertIn("LEGACY_176_IDENTITY_VIOLATION", self.codes(validate_evidence_manifest(unsafe)))

    def test_flood_absent_true_is_rejected(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["observations"][0]["flood_absent"] = True
        self.assertIn("FABRICATED_NEGATIVE", self.codes(validate_evidence_manifest(unsafe)))

    def test_flood_absent_false_is_rejected(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["observations"][0]["flood_absent"] = False
        self.assertIn("FABRICATED_NEGATIVE", self.codes(validate_evidence_manifest(unsafe)))

    def test_normalization_basis_rejects_parent_traversal(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["normalization_basis"][0] = "../index.php"
        self.assertIn("UNSAFE_NORMALIZATION_INPUT", self.codes(validate_evidence_manifest(unsafe)))

    def test_normalization_basis_rejects_nested_parent_traversal(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["normalization_basis"][0] = "ml/flood-risk/../../index.php"
        self.assertIn("UNSAFE_NORMALIZATION_INPUT", self.codes(validate_evidence_manifest(unsafe)))

    def test_normalization_basis_rejects_absolute_path(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["normalization_basis"][0] = str(REPO_ROOT / "index.php")
        self.assertIn("UNSAFE_NORMALIZATION_INPUT", self.codes(validate_evidence_manifest(unsafe)))

    def test_acquired_local_file_rejects_parent_traversal(self):
        unsafe = copy.deepcopy(self.manifest)
        source = next(item for item in unsafe["source_documents"] if item["source_availability"] == "ACQUIRED")
        source["local_file"] = "../index.php"
        self.assertIn("UNSAFE_SOURCE_FILE", self.codes(validate_evidence_manifest(unsafe)))

    def test_acquired_local_file_rejects_absolute_path(self):
        unsafe = copy.deepcopy(self.manifest)
        source = next(item for item in unsafe["source_documents"] if item["source_availability"] == "ACQUIRED")
        source["local_file"] = str(REPO_ROOT / "index.php")
        self.assertIn("UNSAFE_SOURCE_FILE", self.codes(validate_evidence_manifest(unsafe)))

    def test_repository_resolver_rejects_symlink_escape(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "repository"
            outside = Path(temporary) / "outside.txt"
            root.mkdir()
            outside.write_text("outside", encoding="utf-8")
            link = root / "escape.txt"
            try:
                os.symlink(outside, link)
            except OSError as exc:
                self.skipTest(f"Platform does not permit symlink creation: {exc}")
            with self.assertRaises(RepositoryPathError):
                resolve_repository_file(root, "escape.txt")

    def test_repository_resolver_rejects_windows_absolute_path_portably(self):
        with self.assertRaises(RepositoryPathError):
            resolve_repository_file(REPO_ROOT, r"C:\Windows\win.ini")

    def test_real_draft_202012_manifests_validate(self):
        self.assertEqual([], validate_schema_instance(self.manifest, EVIDENCE_SCHEMA))
        self.assertEqual([], validate_schema_instance(self.backlog, BACKLOG_SCHEMA))

    def test_schema_rejects_incoherent_mapping_tuple(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["mapping_confidence"] = "UNRESOLVED"
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))

    def test_schema_rejects_historical_176_successor_mapping(self):
        unsafe = copy.deepcopy(self.manifest)
        legacy = self.mutable_observation(unsafe, "caloocan-2019-historical-barangay-176")
        legacy.update({
            "historical_barangay_name": None,
            "reported_location_text": "Legacy administrative area",
            "barangay_psgc": "1380100189",
            "barangay_name": "Barangay 176-A",
            "mapping_status": "RESOLVED_CURRENT_PSGC",
            "mapping_method": "EXPLICIT_BARANGAY_AND_GOVERNED_PSGC_REFERENCE",
            "mapping_confidence": "HIGH",
        })
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))

    def test_schema_rejects_additional_manifest_property(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["unexpected"] = "prohibited"
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))

    def test_schema_rejects_invalid_psgc_format(self):
        unsafe = copy.deepcopy(self.manifest)
        resolved = self.mutable_observation(unsafe, "caloocan-2019-barangay-175")
        resolved["barangay_psgc"] = "not-a-psgc"
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))

    def test_schema_rejects_governed_enum_violation(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["events"][0]["evidence_status"] = "INVENTED_STATUS"
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))

    def test_schema_rejects_review_training_bypass(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["observations"][0]["review_status"] = "HUMAN_APPROVED"
        unsafe["observations"][0]["training_eligible"] = True
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))
        codes = self.codes(validate_evidence_manifest(unsafe))
        self.assertIn("FALSE_OBSERVATION_APPROVAL", codes)
        self.assertIn("UNSAFE_OBSERVATION_TRAINING_STATE", codes)

    def test_unknown_evidence_cannot_be_training_eligible(self):
        unsafe = copy.deepcopy(self.manifest)
        unsafe["events"][0]["evidence_status"] = "UNKNOWN"
        unsafe["events"][0]["training_eligible"] = True
        self.assertIn("JSON_SCHEMA_VIOLATION", self.schema_codes(validate_schema_instance(unsafe, EVIDENCE_SCHEMA)))
        self.assertIn("UNSAFE_EVENT_TRAINING_STATE", self.codes(validate_evidence_manifest(unsafe)))

    def test_duplicate_acquired_source_hash_is_rejected(self):
        unsafe = copy.deepcopy(self.manifest)
        acquired = [item for item in unsafe["source_documents"] if item["source_availability"] == "ACQUIRED"]
        acquired[1]["source_hash"] = acquired[0]["source_hash"]
        self.assertIn("DUPLICATE_SOURCE_HASH", self.codes(validate_evidence_manifest(unsafe)))

    def test_unknown_revision_parent_is_rejected(self):
        unsafe = copy.deepcopy(self.manifest)
        revised = next(item for item in unsafe["source_documents"] if item["revision_relationship"] == "SUPPLEMENTS")
        revised["related_document_id"] = "missing-source-parent"
        self.assertIn("UNKNOWN_REVISION_PARENT", self.codes(validate_evidence_manifest(unsafe)))

    def test_json_schemas_are_strict_at_every_entity_root(self):
        schema_names = [
            "tf-b2b-event-evidence-adjudication.schema.json",
            "tf-b2b-source-document.schema.json",
            "tf-b2b-canonical-event.schema.json",
            "tf-b2b-location-observation.schema.json",
            "tf-b2b-negative-evidence-acquisition-backlog.schema.json",
        ]
        for name in schema_names:
            schema = read_json(WORKSPACE / "schemas" / name)
            self.assertEqual("object", schema["type"], name)
            self.assertFalse(schema["additionalProperties"], name)
            self.assertTrue(schema["required"], name)


if __name__ == "__main__":
    unittest.main()
