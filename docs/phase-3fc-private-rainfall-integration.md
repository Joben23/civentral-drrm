# Phase 3F-C private rainfall integration

CIVENTRAL PHP calls the private `flood-risk-ai` service server-side. Browser and Citizen Mobile clients do not call FastAPI and never receive `CIVENTRAL_AI_INTERNAL_KEY`.

## Contract

PHP uses `DrrmRainfallInferenceClient` with `AiServiceConfig`, the existing cURL transport, and `X-CIVENTRAL-AI-Key`. The base URL and key are server configuration only:

- `CIVENTRAL_AI_BASE_URL`
- `CIVENTRAL_AI_INTERNAL_KEY`
- `CIVENTRAL_AI_CONNECT_TIMEOUT_MS`
- `CIVENTRAL_AI_REQUEST_TIMEOUT_MS`

The client sends `POST /rainfall/predict` with exactly 48 UTC rainfall observations at 30-minute intervals. It rejects gaps, duplicates, non-finite values, negative rainfall, and malformed requests. It never fills missing observations with zero.

The response is rainfall-only: `RAINFALL_REGRESSION`, `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM`, raw/final millimetres, model version/status, and `operational=false`. Flood probability, risk categories, MGB susceptibility, warnings, and emergency actions are not part of this integration.

## Deployment boundary

In Docker/Dokploy, `civentral-web` reaches `http://flood-risk-ai:8098` on the private `dokploy-network`. The AI service uses Compose `expose`, not a host `ports` mapping, and must not receive a public domain. The existing shared-key authentication remains the only internal authentication mechanism.

The current global `GET /ready` remains the flood-model readiness gate and must continue to return HTTP 503 `MODEL_NOT_AVAILABLE`. Rainfall readiness is separate at `GET /rainfall/ready` and remains research-only. Phase 3F-C does not activate either model for operations.

## Failure handling

Transport failures, timeouts, HTTP 401/422/5xx responses, malformed JSON, incompatible model responses, and unavailable runtime states become stable sanitized failure codes. PHP never returns `0 mm` as a fallback: zero is a valid rainfall prediction, so service failure is represented as unavailable.

## Testing

Run the focused PHP contract test from the repository root:

```powershell
php scripts/test-drrm-rainfall-integration.php
```

The test uses an in-memory transport double. It does not call the public internet, expose the service, or use citizen data. Phase 3F-C does not add a UI endpoint or Module 4 warning behavior.
