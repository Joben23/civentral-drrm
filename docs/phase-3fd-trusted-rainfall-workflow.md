# Phase 3F-D trusted server-side rainfall workflow

## Decision

Current source assessment: `NO_APPROVED_LIVE_SOURCE`.

The repository has an official DOST-PAGASA TenDay integration, but it provides forecast issuance and daily forecast records, not a demonstrated history of exactly 48 observed 30-minute rainfall intervals with the Phase 3F-B semantics. The existing PAGASA client is therefore not used as a rainfall inference history provider.

The repository also contains bounded historical NASA GPM IMERG research material used for offline training and contract work. It is not a live application source and is not promoted into production inference by this phase.

No PAGASA endpoint or live rainfall observation is fabricated.

## Architecture

Trusted server-side source boundary:

```text
DrrmRainfallHistoryProviderInterface
        |
        +-- DrrmNoApprovedLiveRainfallHistoryProvider
        |       `-- RAINFALL_HISTORY_UNAVAILABLE
        |
        `-- TEST_ONLY provider doubles
                `-- validated history for contract tests

DrrmRainfallPredictionService
        |
        +-- validates source status and semantics
        +-- validates exactly 48 observations
        +-- builds the exact Phase 3F-C request
        +-- preserves a safe request_id
        `-- calls DrrmRainfallInferenceClient
                    `-- private authenticated FastAPI rainfall endpoint
```

The production default provider returns `NO_APPROVED_LIVE_SOURCE` and no observations. It never inserts zeroes, interpolates, duplicates observations, generates timestamps, or changes units.

## Compatibility contract

A future compatible provider must explicitly declare:

- measurement: `city_mean_precipitation_mm`
- unit: `millimetres`
- accumulation interval: 30 minutes
- geographic scope: `Caloocan City mean`
- timestamp meaning: `observation_interval_start_utc`

The service rejects any incompatible declaration as `RAINFALL_HISTORY_INCOMPATIBLE`.

Accepted history must contain exactly 48 records with:

- `timestamp_utc`
- `city_mean_precipitation_mm`
- UTC timestamps in ascending order
- exactly 1800 seconds between observations
- no duplicates or gaps
- finite, nonnegative rainfall values

Invalid or incomplete source data returns `RAINFALL_HISTORY_INVALID`.

## Results and failure states

The workflow returns one of these states without converting failure into a rainfall or flood conclusion:

- `RAINFALL_HISTORY_AVAILABLE` is represented by a validated provider result before inference.
- `RAINFALL_HISTORY_UNAVAILABLE`
- `RAINFALL_HISTORY_INVALID`
- `RAINFALL_HISTORY_INCOMPATIBLE`
- existing rainfall AI failure codes such as `AI_SERVICE_ERROR` and `AI_SERVICE_TIMEOUT`
- `RAINFALL_PREDICTION_AVAILABLE` after the private client accepts the result

There is no zero-rainfall fallback, flood probability, risk level, MGB fusion, warning, alert, or emergency action.

## Security and privacy

The orchestration service is server-side and accepts history only from its provider abstraction. It does not read browser request bodies, Citizen Mobile input, or model fields. The existing `DrrmRainfallInferenceClient` keeps the FastAPI URL and `CIVENTRAL_AI_INTERNAL_KEY` server-side.

Logs contain only the workflow event, safe request ID, and result code. Full rainfall histories, credentials, keys, and PII are not logged.

## Operational status

Rainfall inference authorization remains `APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY`.

Operational approval, public/citizen use, flood-classifier use, and MGB fusion are not approved. The result remains `operational=false`.

Module 4 warning creation and dissemination are unchanged and are not activated. The global flood `GET /ready` path and `MODEL_NOT_AVAILABLE` behavior are unchanged.

## Testing

`test-drrm-rainfall-workflow.php` uses clearly labelled TEST_ONLY provider and transport doubles. It proves provider use, exact history forwarding, request ID preservation, source unavailability, malformed/incomplete history, timestamp and rainfall validation, semantic incompatibility, preserved prediction failures/timeouts, and absence of flood/MGB/Module 4 behavior.

These tests do not prove live PAGASA integration. The remaining prerequisite for a live workflow is an approved server-side source that supplies compatible 30-minute city-mean observed rainfall history.
