"""Focused Phase 3F-A service/deployment contract review tests."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
WORKSPACE = REPO_ROOT / "ml/flood-risk"
REVIEW = WORKSPACE / "manifests/phase-3fa-private-rainfall-service-contract-review.json"


class Phase3FAServiceContractReviewTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.review = json.loads(REVIEW.read_text(encoding="utf-8"))

    def test_review_is_contract_only(self) -> None:
        self.assertEqual("PRIVATE_RAINFALL_SERVICE_CONTRACT_READY", self.review["status"])
        self.assertFalse(self.review["current_service"]["rainfall_route_present"])
        self.assertFalse(self.review["deployment"]["host_ports_mapping"])
        self.assertFalse(self.review["safety_state"]["service_files_changed"])
        self.assertFalse(self.review["safety_state"]["docker_files_changed"])

    def test_selected_artifact_and_loader_policy_are_explicit(self) -> None:
        candidate = self.review["artifact_deployment_options"]["recommended"]
        self.assertEqual("A_INTENTIONALLY_VERSIONED_VALIDATED_BUNDLE", candidate["option"])
        checks = self.review["model_loading_policy"]["startup_checks"]
        self.assertTrue(any("Feature count equals 57" in item for item in checks))
        self.assertTrue(any("Softplus" in item for item in checks))
        self.assertIn("MODEL_ARTIFACT_MISMATCH", self.review["model_loading_policy"]["failure_states"])

    def test_existing_internal_auth_is_reused(self) -> None:
        auth = self.review["authentication"]
        self.assertIn("X-CIVENTRAL-AI-Key", auth["current_mechanism"])
        self.assertIn("InternalAuthenticator", auth["current_mechanism"])
        self.assertTrue(auth["caller_scope"].startswith("Server-to-server"))

    def test_request_and_response_exclude_flood_and_mgb_outputs(self) -> None:
        request = self.review["request_contract"]
        response = self.review["response_contract"]
        self.assertEqual(48, request["history_count"])
        self.assertFalse(request["arbitrary_57_number_vector_allowed"])
        self.assertIn("flood_probability", response["forbidden_fields"])
        self.assertIn("MGB_category", response["forbidden_fields"])
        self.assertEqual(3, response["forecast_horizon_hours"])

    def test_global_readiness_stays_separate(self) -> None:
        readiness = self.review["readiness_design"]
        self.assertFalse(readiness["phase_3fa_change"])
        self.assertIn("global GET /ready unchanged", readiness["recommendation"])
        self.assertFalse(self.review["safety_state"]["model_activation_changed"])
        self.assertEqual("NOT_APPROVED", self.review["safety_state"]["global_training_authorization"])

    def test_3fa_record_remains_review_only_and_no_files_are_staged(self) -> None:
        self.assertFalse(self.review["safety_state"]["service_files_changed"])
        self.assertFalse(self.review["safety_state"]["docker_files_changed"])
        self.assertEqual([], subprocess.run(["git", "diff", "--cached", "--name-only"], cwd=REPO_ROOT, capture_output=True, text=True).stdout.splitlines())

    def test_flood_governance_and_candidate_status_remain_non_operational(self) -> None:
        self.assertFalse(self.review["safety_state"]["flood_labels_changed"])
        self.assertFalse(self.review["safety_state"]["mgb_fusion_approved"])
        self.assertFalse(self.review["safety_state"]["global_training_ready"])
        self.assertEqual("NOT_APPROVED", self.review["safety_state"]["global_training_authorization"])


if __name__ == "__main__":
    unittest.main()
