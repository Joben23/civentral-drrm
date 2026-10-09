from __future__ import annotations

import inspect
import json
from pathlib import Path

from app.main import create_app
from app.model_runtime import ModelArtifactManifest, TensorFlowModelRuntime
from app.preprocessing import EXPECTED_FEATURE_ORDER, FeaturePreprocessor
from app.risk_policy import RiskPolicyRuntime, RiskPolicyState
from app.schemas import ModelState
from fastapi.testclient import TestClient
from pydantic import ValidationError
import pytest


def test_risk_policy_has_no_default_thresholds(settings) -> None:
    runtime = RiskPolicyRuntime(None, settings.artifact_root)

    status = runtime.get_status()

    assert status.state is RiskPolicyState.NOT_CONFIGURED
    assert status.policy_version is None


def test_partially_configured_model_bundle_is_invalid(settings) -> None:
    configured = settings.model_copy(
        update={"model_path": settings.artifact_root / "not-present.keras"}
    )
    preprocessor = FeaturePreprocessor(
        configured.feature_schema_path,
        configured.barangay_reference_path,
    )
    runtime = TensorFlowModelRuntime(configured, preprocessor)

    assert runtime.get_status().state is ModelState.MODEL_INVALID


def test_missing_configured_bundle_remains_unavailable(settings) -> None:
    configured = settings.model_copy(
        update={
            "model_path": settings.artifact_root / "not-present.keras",
            "model_manifest_path": settings.artifact_root / "not-present.json",
        }
    )
    preprocessor = FeaturePreprocessor(
        configured.feature_schema_path,
        configured.barangay_reference_path,
    )
    runtime = TensorFlowModelRuntime(configured, preprocessor)

    assert runtime.get_status().state is ModelState.MODEL_NOT_AVAILABLE


def test_authentication_configuration_fails_closed(settings) -> None:
    no_key = settings.model_copy(update={"internal_key": None})
    with TestClient(create_app(no_key)) as client:
        response = client.get("/v1/model/status")

    assert response.status_code == 503
    assert response.json()["code"] == "INTERNAL_AUTH_NOT_CONFIGURED"


def test_future_manifest_contract_is_governed_and_not_preapproved(settings) -> None:
    schema_path = settings.artifact_root.parent / "service" / "schemas" / (
        "model-artifact-manifest.schema.json"
    )
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    assert {
        "model_id",
        "model_version",
        "task_type",
        "input_schema_version",
        "feature_order",
        "artifact_sha256",
        "training_dataset_version",
        "label_protocol_version",
        "forecast_horizon_hours",
        "calibration_status",
        "approved_for_operational_use",
    }.issubset(schema["required"])
    assert schema["properties"]["task_type"]["const"] == "FLOOD_RISK_CLASSIFIER"


def test_manifest_cannot_claim_approval_without_operational_validation() -> None:
    payload = {
        "manifest_schema_version": "1.1",
        "model_id": "contract-test",
        "model_version": "contract-test-v1",
        "task_type": "FLOOD_RISK_CLASSIFIER",
        "model_status": "DEVELOPMENT_NOT_OPERATIONALLY_VALIDATED",
        "input_schema_version": "1.0.0",
        "feature_order": list(EXPECTED_FEATURE_ORDER),
        "training_dataset_version": "test-only",
        "training_dataset_hash": "0" * 64,
        "label_protocol_version": "test-only",
        "created_at": "2026-01-01T00:00:00Z",
        "forecast_horizon_hours": 24,
        "calibration_status": "NOT_VALIDATED",
        "tensorflow_version": "2.21.0",
        "python_version": "3.12.15",
        "artifact_filename": "contract-test.keras",
        "artifact_format": "KERAS_V3",
        "artifact_sha256": "1" * 64,
        "approved_for_operational_use": True,
        "threshold_policy_version": None,
        "input_shape": 10,
        "output_semantics": "FLOOD_PROBABILITY",
        "preprocessing_artifact_format": None,
        "preprocessing_artifact_filename": None,
        "preprocessing_artifact_checksum": None,
        "limitations": ["Contract-only test fixture."],
    }
    with pytest.raises(ValidationError):
        ModelArtifactManifest.model_validate(payload)


def test_flood_loader_uses_keras_safe_mode() -> None:
    assert "safe_mode=True" in inspect.getsource(TensorFlowModelRuntime.initialize)


def test_model_path_outside_artifact_root_is_rejected(settings, tmp_path: Path) -> None:
    configured = settings.model_copy(
        update={
            "model_path": tmp_path / "outside.keras",
            "model_manifest_path": tmp_path / "outside.json",
        }
    )
    preprocessor = FeaturePreprocessor(
        configured.feature_schema_path,
        configured.barangay_reference_path,
    )
    runtime = TensorFlowModelRuntime(configured, preprocessor)

    assert runtime.get_status().state is ModelState.MODEL_INVALID
