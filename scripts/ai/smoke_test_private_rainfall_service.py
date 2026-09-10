#!/usr/bin/env python3
"""Local Phase 3F-B API smoke test using governed historical rainfall."""

from __future__ import annotations

import json
import sys
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
SERVICE_ROOT = REPO_ROOT / "ml/flood-risk/service"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from app.config import Settings
from app.main import create_app
from fastapi.testclient import TestClient


CORPUS = (
    REPO_ROOT
    / "ml/flood-risk/data/processed/imerg-caloocan-city-mean-2023-2024.json"
)
TEST_KEY = "phase-3fb-local-smoke-key-000000000000000000"


def main() -> int:
    if not CORPUS.is_file():
        raise SystemExit("Governed historical rainfall corpus is unavailable.")
    records = json.loads(CORPUS.read_text(encoding="utf-8"))["records"][:48]
    if len(records) != 48:
        raise SystemExit("Governed historical rainfall corpus has insufficient history.")
    request = {
        "schema_version": "1.0",
        "request_id": "phase-3fb-real-history-smoke",
        "history": [
            {
                "timestamp_utc": row["observed_at_start"],
                "city_mean_precipitation_mm": row[
                    "city_mean_precipitation_mm"
                ],
            }
            for row in records
        ],
    }
    settings = Settings(
        _env_file=None,
        internal_key=TEST_KEY,
        require_internal_auth=True,
        enable_docs=False,
    )
    headers = {"X-CIVENTRAL-AI-Key": TEST_KEY}
    with TestClient(create_app(settings)) as client:
        health = client.get("/health")
        global_ready = client.get("/ready")
        rainfall_ready = client.get("/rainfall/ready", headers=headers)
        prediction = client.post(
            "/rainfall/predict", headers=headers, json=request
        )
        unauthorized = client.post("/rainfall/predict", json=request)
        invalid = client.post(
            "/rainfall/predict",
            headers=headers,
            json={**request, "history": request["history"][:-1]},
        )

    result = {
        "health": {
            "http_status": health.status_code,
            "service_status": health.json().get("service_status"),
        },
        "global_ready": {
            "http_status": global_ready.status_code,
            "code": global_ready.json().get("code"),
        },
        "rainfall_ready": {
            "http_status": rainfall_ready.status_code,
            "code": rainfall_ready.json().get("code"),
            "research_only": rainfall_ready.json().get("research_only"),
            "operational": rainfall_ready.json().get("operational"),
        },
        "prediction": {
            "http_status": prediction.status_code,
            "request_id": prediction.json().get("request_id"),
            "forecast_origin_utc": prediction.json().get("forecast_origin_utc"),
            "raw_prediction_mm": prediction.json().get("raw_prediction_mm"),
            "final_prediction_mm": prediction.json().get("final_prediction_mm"),
            "output_policy": prediction.json().get("output_policy"),
            "operational": prediction.json().get("operational"),
        },
        "no_key": {
            "http_status": unauthorized.status_code,
            "code": unauthorized.json().get("code"),
        },
        "invalid_history": {
            "http_status": invalid.status_code,
            "code": invalid.json().get("code"),
        },
    }
    print(json.dumps(result, indent=2))
    expected = (
        health.status_code == 200
        and global_ready.status_code == 503
        and global_ready.json().get("code") == "MODEL_NOT_AVAILABLE"
        and rainfall_ready.status_code == 200
        and prediction.status_code == 200
        and unauthorized.status_code == 401
        and invalid.status_code == 422
    )
    return 0 if expected else 1


if __name__ == "__main__":
    raise SystemExit(main())
