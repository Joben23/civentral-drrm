"""Focused Phase 3B3-A discovery tests; no data acquisition or training."""

from __future__ import annotations

import copy
import json
import sys
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml" / "flood-risk"
sys.path.insert(0, str(REPO_ROOT / "scripts" / "ai"))

from flood_candidate_discovery import (  # noqa: E402
    acquisition_priority,
    cluster_report_records,
    find_possible_duplicates,
    reprioritize,
    validate_candidate_index,
)
from flood_data_common import validate_manifest  # noqa: E402


def read_json(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


class FloodCandidateDiscoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.index = read_json(WORKSPACE / "manifests" / "candidate-events.json")
        cls.registry = read_json(WORKSPACE / "manifests" / "flood-event-registry.json")
        cls.sources = read_json(WORKSPACE / "manifests" / "source-manifest.json")
        cls.authorization = read_json(WORKSPACE / "manifests" / "training-authorization.json")

    @staticmethod
    def codes(issues):
        return {issue.code for issue in issues}

    def candidate(self, candidate_id):
        return copy.deepcopy(next(item for item in self.index["candidates"] if item["candidate_id"] == candidate_id))

    def test_repository_candidate_index_is_fail_closed(self):
        self.assertEqual([], validate_candidate_index(self.index))
        self.assertEqual(6, self.index["additional_candidate_count"])
        self.assertEqual([], find_possible_duplicates(self.index["candidates"]))

    def test_duplicate_reports_form_one_event_cluster(self):
        records = [
            {"event_cluster_id": "TEST_ONLY-episode", "report": {"url": "https://example.gov/report-1.pdf"}},
            {"event_cluster_id": "TEST_ONLY-episode", "report": {"url": "https://example.gov/report-2.pdf"}},
            {"event_cluster_id": "TEST_ONLY-episode", "report": {"url": "https://example.gov/report-1.pdf"}},
        ]
        clusters = cluster_report_records(records)
        self.assertEqual(1, len(clusters))
        self.assertEqual(2, len(clusters[0]["reports"]))

    def test_duplicate_candidates_are_flagged_not_silently_merged(self):
        candidate = self.candidate("candidate-ndrrmc-2020-11-12-ulysses-caloocan")
        duplicate = copy.deepcopy(candidate)
        duplicate["candidate_id"] = "TEST_ONLY-duplicate"
        flags = find_possible_duplicates([candidate, duplicate])
        self.assertEqual(1, len(flags))
        self.assertEqual("FLAG_FOR_REVIEW_NO_AUTOMATIC_MERGE", flags[0]["action"])

    def test_report_issue_time_is_not_event_onset(self):
        index = copy.deepcopy(self.index)
        index["candidates"][1]["event_window"]["report_issue_used_as_event_time"] = True
        self.assertIn("REPORT_ISSUE_TIME_USED_AS_ONSET", self.codes(validate_candidate_index(index)))

    def test_all_candidates_default_to_unknown_and_ineligible(self):
        for candidate in self.index["candidates"]:
            self.assertEqual("UNKNOWN", candidate["label_status"])
            self.assertEqual("REQUIRES_HUMAN_REVIEW", candidate["review_status"])
            self.assertFalse(candidate["training_eligible"])
            self.assertIsNone(candidate["negative_evidence_basis"])

    def test_secondary_evidence_cannot_replace_primary_official_evidence(self):
        index = copy.deepcopy(self.index)
        candidate = index["candidates"][1]
        candidate["source_classification"] = "SECONDARY_CORROBORATION"
        candidate["secondary_corroboration"] = [{"url": "https://example.test/media"}]
        codes = self.codes(validate_candidate_index(index))
        self.assertIn("MISSING_PRIMARY_OFFICIAL_EVIDENCE", codes)
        self.assertIn("SECONDARY_EVIDENCE_USED_AS_PRIMARY", codes)

    def test_unsupported_barangay_is_not_inferred(self):
        index = copy.deepcopy(self.index)
        index["candidates"][1]["supported_barangays"] = ["Barangay 999"]
        self.assertIn("UNSUPPORTED_BARANGAY_INFERENCE", self.codes(validate_candidate_index(index)))

    def test_legacy_barangay_176_is_not_silently_remapped(self):
        index = copy.deepcopy(self.index)
        index["candidates"][0]["supported_barangays"] = ["Barangay 176-A"]
        self.assertIn("LEGACY_BARANGAY_176_REMAPPED", self.codes(validate_candidate_index(index)))

    def test_priority_ranking_is_deterministic(self):
        for candidate in self.index["candidates"]:
            first = acquisition_priority(candidate["priority_criteria"])
            second = acquisition_priority(dict(reversed(list(candidate["priority_criteria"].items()))))
            self.assertEqual(first, second)
            self.assertEqual((candidate["priority_score"], candidate["acquisition_priority"]), first)

    def test_priority_does_not_change_label_state(self):
        candidate = self.candidate("candidate-ndrrmc-2024-09-02-enteng-caloocan")
        ranked = reprioritize(candidate)
        self.assertEqual("UNKNOWN", ranked["label_status"])
        self.assertFalse(ranked["training_eligible"])
        self.assertEqual("UNKNOWN", candidate["label_status"])

    def test_missing_official_evidence_fails_closed(self):
        index = copy.deepcopy(self.index)
        index["candidates"][1]["official_document_links"] = []
        self.assertIn("MISSING_OFFICIAL_EVIDENCE", self.codes(validate_candidate_index(index)))

    def test_rejected_source_cannot_remain_an_active_candidate(self):
        index = copy.deepcopy(self.index)
        index["candidates"][1]["source_status"] = "REJECTED"
        self.assertIn("INVALID_SOURCE_STATUS", self.codes(validate_candidate_index(index)))

    def test_absence_of_report_cannot_create_no_flood(self):
        index = copy.deepcopy(self.index)
        candidate = index["candidates"][1]
        candidate["label_status"] = "NO_FLOOD_CONFIRMED"
        candidate["negative_evidence_basis"] = "ABSENCE_OF_REPORT"
        codes = self.codes(validate_candidate_index(index))
        self.assertIn("DISCOVERY_LABEL_NOT_UNKNOWN", codes)
        self.assertIn("NEGATIVE_INFERRED_DURING_DISCOVERY", codes)

    def test_wrong_location_name_collision_is_rejected(self):
        rejected = self.index["screened_out"]
        self.assertEqual(1, len(rejected))
        self.assertEqual("candidate-ndrrmc-2023-07-31-caloocan", rejected[0]["candidate_id"])
        self.assertEqual("REJECTED_WRONG_LOCATION", rejected[0]["status"])
        self.assertIn("Binmaley, Pangasinan", rejected[0]["reason"])

    def test_registry_contains_only_unreviewed_candidate_events(self):
        candidate_ids = {candidate["registry_event_id"] for candidate in self.index["candidates"]}
        registry_events = {event["event_id"]: event for event in self.registry["events"]}
        self.assertTrue(candidate_ids.issubset(registry_events))
        for event_id in candidate_ids:
            event = registry_events[event_id]
            self.assertEqual("UNKNOWN", event["label_status"])
            self.assertFalse(event["training_eligible"])
            self.assertEqual([], event["candidate_windows"])

    def test_discovery_sources_are_registered_but_not_acquired_or_approved(self):
        source_map = {source["source_id"]: source for source in self.sources["sources"]}
        self.assertEqual([], validate_manifest(self.sources))
        for candidate in self.index["candidates"][1:]:
            source = source_map[candidate["source_id"]]
            self.assertEqual("PROPOSED", source["status"])
            self.assertEqual("NOT_ACQUIRED", source["availability_status"])
            self.assertEqual("PENDING_REVIEW", source["license_or_usage_status"])
            self.assertIsNone(source["local_file"])
            self.assertIsNone(source["sha256"])

    def test_no_training_ready_state_is_produced(self):
        self.assertEqual("NOT_APPROVED", self.authorization["status"])
        self.assertEqual("PENDING", self.authorization["target_definition_status"])
        self.assertTrue(self.index["training_use_prohibited"])
        self.assertFalse(any(candidate["training_eligible"] for candidate in self.index["candidates"]))

    def test_no_model_artifact_exists(self):
        suffixes = {".keras", ".h5", ".tflite"}
        artifacts = []
        for path in (WORKSPACE / "models").rglob("*"):
            if not path.is_file() or ".venv" in path.parts:
                continue
            if path.suffix.lower() in suffixes or path.name == "saved_model.pb":
                artifacts.append(path)
        self.assertEqual([], artifacts)

    def test_negative_research_is_source_discovery_not_label_creation(self):
        tracks = self.index["negative_label_research"]
        self.assertGreaterEqual(len(tracks), 1)
        self.assertTrue(all(track["current_status"] == "NOT_ACQUIRED" for track in tracks))
        self.assertTrue(all(track["affirmative_evidence_required"] is True for track in tracks))


if __name__ == "__main__":
    unittest.main()
