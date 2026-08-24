from __future__ import annotations

from app.main import create_app
from app.model_runtime import TensorFlowModelRuntime
from app.preprocessing import FeaturePreprocessor
from app.risk_policy import RiskPolicyRuntime, RiskPolicyState
from app.schemas import ModelState
from fastapi.testclient import TestClient


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
