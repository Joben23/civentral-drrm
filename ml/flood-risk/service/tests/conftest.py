from __future__ import annotations

from copy import deepcopy
from typing import Any

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.main import create_app


TEST_INTERNAL_KEY = "test-only-internal-key-000000000000000000000000"


@pytest.fixture()
def settings() -> Settings:
    return Settings(
        _env_file=None,
        internal_key=TEST_INTERNAL_KEY,
        require_internal_auth=True,
        enable_docs=False,
    )


@pytest.fixture()
def client(settings: Settings) -> TestClient:
    with TestClient(create_app(settings)) as test_client:
        yield test_client


@pytest.fixture()
def auth_headers() -> dict[str, str]:
    return {"X-CIVENTRAL-AI-Key": TEST_INTERNAL_KEY}


@pytest.fixture()
def valid_request() -> dict[str, Any]:
    # Request-only fixture values; this is not an operational observation or model data.
    return {
        "schema_version": "1.0",
        "request_id": "test-request-001",
        "prediction_type": "FLOOD_WITHIN_24H",
        "valid_from": "2026-01-10T00:00:00+08:00",
        "valid_until": "2026-01-11T00:00:00+08:00",
        "location": {"barangay_id": "1380100001"},
        "features": {
            "forecast_rainfall_24h_mm": 10.0,
            "antecedent_rainfall_24h_mm": 5.0,
            "antecedent_rainfall_72h_mm": 15.0,
            "mgb_flood_susceptibility_code": "HF",
            "month_sin": 0.0,
            "month_cos": 1.0,
        },
        "source_context": {
            "weather_issued_at": "2026-01-09T18:00:00+08:00",
            "feature_schema_version": "1.0.0",
        },
    }


@pytest.fixture()
def copy_request(valid_request: dict[str, Any]):
    def make_copy() -> dict[str, Any]:
        return deepcopy(valid_request)

    return make_copy
