from __future__ import annotations

from fastapi.middleware.cors import CORSMiddleware


def test_health_reports_process_health_without_claiming_model_ready(client) -> None:
    response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["service_status"] == "HEALTHY"
    assert body["model_status"] == "MODEL_NOT_AVAILABLE"
    assert body["risk_policy_status"] == "NOT_CONFIGURED"


def test_readiness_fails_closed_without_model(client) -> None:
    response = client.get("/ready")

    assert response.status_code == 503
    body = response.json()
    assert body == {
        "success": False,
        "ready": False,
        "code": "MODEL_NOT_AVAILABLE",
        "message": "No approved TensorFlow flood-risk model is available for inference.",
        "model_status": "MODEL_NOT_AVAILABLE",
        "risk_policy_status": "NOT_CONFIGURED",
    }


def test_model_status_is_sanitized_and_truthful(client, auth_headers) -> None:
    response = client.get("/v1/model/status", headers=auth_headers)

    assert response.status_code == 200
    body = response.json()
    assert body["model_status"] == "MODEL_NOT_AVAILABLE"
    assert body["model_available"] is False
    assert body["approved_for_inference"] is False
    assert body["model_version"] is None
    assert "artifact" not in body


def test_prediction_without_model_returns_no_prediction_fields(
    client, auth_headers, valid_request
) -> None:
    response = client.post(
        "/v1/predictions/flood-risk",
        headers=auth_headers,
        json=valid_request,
    )

    assert response.status_code == 503
    body = response.json()
    assert body == {
        "success": False,
        "code": "MODEL_NOT_AVAILABLE",
        "message": "No approved TensorFlow flood-risk model is available for inference.",
        "model_status": "MODEL_NOT_AVAILABLE",
    }
    assert "probability" not in body
    assert "civentral_risk_level" not in body
    assert "predicted_outcome" not in body


def test_missing_rainfall_is_rejected(client, auth_headers, copy_request) -> None:
    payload = copy_request()
    del payload["features"]["forecast_rainfall_24h_mm"]

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422
    assert response.json()["code"] == "INVALID_REQUEST"


def test_invalid_rainfall_is_rejected(client, auth_headers, copy_request) -> None:
    payload = copy_request()
    payload["features"]["antecedent_rainfall_24h_mm"] = -0.01

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422


def test_invalid_mgb_class_is_rejected(client, auth_headers, copy_request) -> None:
    payload = copy_request()
    payload["features"]["mgb_flood_susceptibility_code"] = "VERY_HIGH"

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422


def test_invalid_valid_window_is_rejected(client, auth_headers, copy_request) -> None:
    payload = copy_request()
    payload["valid_until"] = "2026-01-10T12:00:00+08:00"

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422


def test_client_supplied_warning_level_is_forbidden(
    client, auth_headers, copy_request
) -> None:
    payload = copy_request()
    payload["warning_level"] = "CRITICAL"

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422


def test_client_supplied_probability_is_forbidden(
    client, auth_headers, copy_request
) -> None:
    payload = copy_request()
    payload["features"]["probability"] = 0.5

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 422


def test_invalid_internal_key_is_rejected(client) -> None:
    response = client.get(
        "/v1/model/status",
        headers={"X-CIVENTRAL-AI-Key": "not-the-configured-key"},
    )

    assert response.status_code == 401
    assert response.json()["code"] == "UNAUTHORIZED"


def test_no_permissive_cors_middleware_or_response(client) -> None:
    assert all(
        middleware.cls is not CORSMiddleware for middleware in client.app.user_middleware
    )
    response = client.get(
        "/health",
        headers={"Origin": "https://untrusted.example"},
    )
    assert "access-control-allow-origin" not in response.headers


def test_request_size_limit(client, auth_headers, copy_request) -> None:
    payload = copy_request()
    payload["unexpected_padding"] = "x" * 20_000

    response = client.post(
        "/v1/predictions/flood-risk", headers=auth_headers, json=payload
    )

    assert response.status_code == 413
    assert response.json()["code"] == "REQUEST_TOO_LARGE"
