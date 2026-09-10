from __future__ import annotations

import json
import logging
import math

from app.rainfall_runtime import RainfallRegressionRuntime
from common.rainfall_features import feature_names, make_features, validate_history


FORBIDDEN_OUTPUTS = {
    "flood_probability",
    "flood_occurrence",
    "risk_level",
    "civentral_risk_level",
    "MGB_category",
    "evacuation_recommendation",
    "emergency_action",
}


def test_rainfall_ready_requires_authentication(client) -> None:
    response = client.get("/rainfall/ready")
    assert response.status_code == 401
    assert response.json()["code"] == "UNAUTHORIZED"


def test_rainfall_ready_is_separate_and_research_only(client, auth_headers) -> None:
    response = client.get("/rainfall/ready", headers=auth_headers)
    assert response.status_code == 200
    body = response.json()
    assert body["ready"] is True
    assert body["code"] == "RAINFALL_RESEARCH_READY"
    assert body["research_only"] is True
    assert body["operational"] is False
    assert body["authorization_status"] == (
        "APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY"
    )


def test_global_ready_remains_flood_model_unavailable(client) -> None:
    response = client.get("/ready")
    assert response.status_code == 503
    assert response.json()["code"] == "MODEL_NOT_AVAILABLE"


def test_rainfall_prediction_requires_authentication(client, rainfall_request) -> None:
    response = client.post("/rainfall/predict", json=rainfall_request)
    assert response.status_code == 401
    assert response.json()["code"] == "UNAUTHORIZED"


def test_wrong_key_is_unauthorized(client, rainfall_request) -> None:
    response = client.post(
        "/rainfall/predict",
        headers={"X-CIVENTRAL-AI-Key": "incorrect"},
        json=rainfall_request,
    )
    assert response.status_code == 401
    assert response.json()["code"] == "UNAUTHORIZED"


def test_valid_request_returns_only_rainfall(
    client, auth_headers, rainfall_request
) -> None:
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 200
    body = response.json()
    assert body["model_problem"] == "RAINFALL_REGRESSION"
    assert body["target"] == "NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM"
    assert body["forecast_horizon_hours"] == 3
    assert body["output_policy"] == "MODEL_NONNEGATIVE_SOFTPLUS"
    assert body["operational"] is False
    assert math.isfinite(body["raw_prediction_mm"])
    assert body["raw_prediction_mm"] >= 0
    assert body["final_prediction_mm"] == body["raw_prediction_mm"]
    assert FORBIDDEN_OUTPUTS.isdisjoint(body)


def test_exactly_48_observations_required(
    client, auth_headers, rainfall_request
) -> None:
    rainfall_request["history"].pop()
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "INSUFFICIENT_HISTORY"


def test_timestamp_gap_rejected(client, auth_headers, rainfall_request) -> None:
    rainfall_request["history"][20]["timestamp_utc"] = "2024-01-01T10:30:00Z"
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "TIMESTAMP_GAP"


def test_duplicate_timestamp_rejected(client, auth_headers, rainfall_request) -> None:
    rainfall_request["history"][20]["timestamp_utc"] = rainfall_request["history"][19][
        "timestamp_utc"
    ]
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "DUPLICATE_TIMESTAMP"


def test_non_utc_timestamp_rejected(client, auth_headers, rainfall_request) -> None:
    rainfall_request["history"][0]["timestamp_utc"] = "2024-01-01T08:00:00+08:00"
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "TIMESTAMP_GAP"


def test_negative_rainfall_rejected(client, auth_headers, rainfall_request) -> None:
    rainfall_request["history"][0]["city_mean_precipitation_mm"] = -0.01
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "NEGATIVE_SOURCE_PRECIPITATION"


def test_non_number_rainfall_rejected(client, auth_headers, rainfall_request) -> None:
    rainfall_request["history"][0]["city_mean_precipitation_mm"] = "0"
    response = client.post(
        "/rainfall/predict", headers=auth_headers, json=rainfall_request
    )
    assert response.status_code == 422
    assert response.json()["code"] == "INVALID_PRECIPITATION"


def test_exact_57_features_are_constructed(rainfall_request) -> None:
    records = validate_history(rainfall_request["history"])
    values, names = make_features(records, 47)
    assert len(values) == 57
    assert names == feature_names()


def test_runtime_uses_stored_train_preprocessing(settings) -> None:
    runtime = RainfallRegressionRuntime(settings)
    assert runtime.get_status().ready is True
    assert runtime._preprocessing["fit_split"] == "train"
    assert runtime._preprocessing["feature_order"] == feature_names()


def allow_temporary_governed_path(monkeypatch) -> None:
    monkeypatch.setattr(
        RainfallRegressionRuntime,
        "_resolve_under_root",
        staticmethod(lambda path, _root: path.resolve()),
    )


def test_missing_authorization_disables_runtime(
    settings, tmp_path, monkeypatch
) -> None:
    allow_temporary_governed_path(monkeypatch)
    runtime = RainfallRegressionRuntime(
        settings.model_copy(
            update={"rainfall_authorization_path": tmp_path / "missing.json"}
        )
    )
    status = runtime.get_status()
    assert status.ready is False
    assert status.code == "MODEL_INFERENCE_NOT_APPROVED"


def test_revoked_authorization_disables_runtime(
    settings, tmp_path, monkeypatch
) -> None:
    allow_temporary_governed_path(monkeypatch)
    data = json.loads(
        settings.rainfall_authorization_path.read_text(encoding="utf-8")
    )
    data["status"] = "NOT_APPROVED"
    target = tmp_path / "authorization.json"
    target.write_text(json.dumps(data), encoding="utf-8")
    runtime = RainfallRegressionRuntime(
        settings.model_copy(update={"rainfall_authorization_path": target})
    )
    assert runtime.get_status().code == "MODEL_INFERENCE_NOT_APPROVED"


def test_invalid_model_checksum_disables_runtime(
    settings, tmp_path, monkeypatch
) -> None:
    allow_temporary_governed_path(monkeypatch)
    bundle = tmp_path / "bundle"
    bundle.mkdir()
    for name in ("model.keras", "preprocessing.json", "deployment-manifest.json"):
        (bundle / name).write_bytes((settings.rainfall_bundle_path / name).read_bytes())
    manifest = json.loads((bundle / "deployment-manifest.json").read_text())
    manifest["model"]["sha256"] = "0" * 64
    (bundle / "deployment-manifest.json").write_text(json.dumps(manifest))
    runtime = RainfallRegressionRuntime(
        settings.model_copy(update={"rainfall_bundle_path": bundle})
    )
    assert runtime.get_status().code == "MODEL_ARTIFACT_MISMATCH"


def test_only_approved_candidate_version_can_load(
    settings, tmp_path, monkeypatch
) -> None:
    allow_temporary_governed_path(monkeypatch)
    bundle = tmp_path / "bundle"
    bundle.mkdir()
    for name in ("model.keras", "preprocessing.json", "deployment-manifest.json"):
        (bundle / name).write_bytes((settings.rainfall_bundle_path / name).read_bytes())
    manifest = json.loads((bundle / "deployment-manifest.json").read_text())
    manifest["model_version"] = "rainfall-regression-dense-57-v0.1.0-candidate"
    (bundle / "deployment-manifest.json").write_text(json.dumps(manifest))
    runtime = RainfallRegressionRuntime(
        settings.model_copy(update={"rainfall_bundle_path": bundle})
    )
    assert runtime.get_status().code == "MODEL_ARTIFACT_MISMATCH"


def test_logs_exclude_key_and_history(
    client, auth_headers, rainfall_request, caplog
) -> None:
    secret = auth_headers["X-CIVENTRAL-AI-Key"]
    with caplog.at_level(logging.INFO, logger="civentral.ai.requests"):
        response = client.post(
            "/rainfall/predict", headers=auth_headers, json=rainfall_request
        )
    assert response.status_code == 200
    logged = "\n".join(record.message for record in caplog.records)
    assert secret not in logged
    assert "city_mean_precipitation_mm" not in logged
    assert "rainfall-test-001" in logged
    assert "rainfall-regression-dense-57-v0.1.1-softplus-candidate" in logged
    assert "request_timestamp" in logged


def test_failure_log_uses_stable_code_without_history(
    client, auth_headers, rainfall_request, caplog
) -> None:
    rainfall_request["history"].pop()
    with caplog.at_level(logging.INFO, logger="civentral.ai.requests"):
        response = client.post(
            "/rainfall/predict", headers=auth_headers, json=rainfall_request
        )
    assert response.status_code == 422
    logged = "\n".join(record.message for record in caplog.records)
    assert '"failure_code":"INSUFFICIENT_HISTORY"' in logged
    assert "city_mean_precipitation_mm" not in logged
    assert "model.keras" not in response.text
    assert "deployment" not in response.text
