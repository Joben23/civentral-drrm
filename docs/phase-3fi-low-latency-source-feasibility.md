# Phase 3F-I low-latency source and forecast-target feasibility

## Decision

`LOW_LATENCY_STRATEGY_MORE_EVIDENCE_REQUIRED`

This phase does not select a production source, train a model, activate a provider, or change the frozen rainfall candidate. It compares two source-aligned research paths:

- Strategy A: NASA IMERG Early with a redesigned forecast horizon/formulation.
- Strategy B: JAXA GSMaP NOW with true hourly rainfall semantics.

Production remains `NO_APPROVED_LIVE_SOURCE`.

## Current state

The existing private research model is `rainfall-regression-dense-57-v0.1.1-softplus-candidate`. It predicts `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM` from 48 UTC half-hour observations of `city_mean_precipitation_mm` representing an unweighted Caloocan City mean. It has 57 canonical features, is `VALIDATED_RESEARCH_CANDIDATE`, is authorized only for private project research, and remains `operational=false`.

The existing IMERG Final model, CMORPH CDR studies, PHP client, and trusted workflow remain unchanged.

## Operational freshness framing

These are Phase 3F-I research labels, not PAGASA or DRRM standards and not operational policy:

- `FRESH`: newest trusted observation is no more than 1 hour old.
- `DEGRADED`: newest trusted observation is more than 1 hour and no more than 3 hours old.
- `STALE_FOR_CURRENT_3H_TARGET`: newest trusted observation is more than 3 hours old.

Observation age is separate from forecast horizon. A product can have a valid historical observation while still being too old to support a useful current forecast.

## Strategy A: IMERG Early

NASA's IMERG product family includes Early, Late, and Final processing. The Early/Late retrospective archive is available approximately from January 1998 through the present, so historical depth exists for a future source-aligned study. CIVentral does not yet have a governed IMERG Early training corpus.

Relevant semantics to verify in the next bounded study:

- Product version and processing release
- Precipitation variable and units
- 30-minute temporal resolution
- UTC interval-start semantics
- 0.1-degree spatial grid
- Caloocan intersecting-cell aggregation
- Missing/fill handling
- Historical version continuity
- Machine-readable Earthdata/GES DISC access

Approximate latencies used for feasibility reasoning:

- IMERG Early: about 4 hours
- IMERG Late: about 14 hours
- IMERG Final: about 3.5 months

For the unchanged target, with latency $L$:

```text
remaining nominal future coverage = 3 - L hours
IMERG Early: 3 - 4 ~= -1 hour
IMERG Late:  3 - 14 ~= -11 hours
IMERG Final: retrospective, not live
```

Therefore IMERG Early is not practically live for the unchanged `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM` target. It remains viable only with a new forecast formulation.

### IMERG Early target options

These are candidates for study, not approved targets:

| Option | Forecast origin | Predicted interval relative to latest observation | Future coverage at data availability | Leakage concern |
|---|---|---|---|---|
| A | Latest observed Early interval | +4h to +7h | Entire interval is future when data arrives, assuming stated latency | Build labels strictly after the observation cutoff |
| B | Latest observed Early interval | +5h to +8h | Entire interval is future with additional latency margin | Same cutoff and version controls |
| C | Expected availability time | Target begins after expected product arrival | Potentially useful, but requires timestamping the publication/availability process | Availability-time alignment must be recorded |
| D | Latest observed Early interval | Next 6h | More useful future coverage after latency, but a different target | Requires a new six-hour target contract |

No option is selected in Phase 3F-I. The frozen three-hour model contract is not changed.

## Strategy B: GSMaP NOW

Authoritative JAXA GSMaP documentation identifies GSMaP NOW as an approximately zero-latency/near-current product with rainfall rate in `mm/hr`, hourly temporal resolution, and updates every 30 minutes. Its archive is available approximately from 2017, with the current v8 period from approximately 2021 onward.

A half-hourly update cadence does not mean independent half-hour rainfall accumulations. The observed value semantics remain hourly rainfall rate/temporal resolution until the source documentation and files prove otherwise.

The next study must verify:

- Exact GSMaP NOW product/version
- Rate versus accumulation semantics
- Timestamp meaning and interval coverage
- Actual publication latency
- Hourly spatial grid and Caloocan coverage
- Historical NOW continuity and version transitions
- Machine-readable access and authentication
- Fill/missing-value behavior
- Polygon-intersection or area-weighted aggregation feasibility

### GSMaP NOW history contracts

| Contract | Context | Observation count | Benefits | Risks |
|---|---:|---:|---|---|
| B1 | Previous 24 hours | 24 hourly observations | Lower feature cost, direct hourly context | Less antecedent context; may miss longer accumulation patterns |
| B2 | Previous 48 hours | 48 hourly observations | More antecedent context and seasonal/event structure | Larger input, more missing-data exposure, higher training cost |

Neither contract reuses the existing 48 x 30-minute contract. A new hourly feature/preprocessing contract is required.

### GSMaP NOW target options

Candidate targets:

- `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM`: sum of the next three hourly rainfall values after the history cutoff.
- `NEXT_6_HOUR_ACCUMULATED_RAINFALL_MM`: sum of the next six hourly values, potentially more stable for hourly input.

Both require historical future labels strictly after the final history timestamp, no leakage, and a source archive with complete hourly coverage. No target is approved yet.

## Candidate feature contracts

IMERG Early would retain the general rainfall-regression structure only after source validation:

- Source-specific half-hour rainfall lags
- 1h, 3h, 6h, 12h, and 24h accumulations
- Calendar/seasonal features
- New train-only preprocessing fitted from IMERG Early data

GSMaP NOW requires a new hourly contract:

- Hourly rainfall lags for B1 or B2
- 3h, 6h, 12h, and 24h accumulations
- Rain-occurrence indicators
- Hour-of-day and day-of-year seasonal representations
- New train-only preprocessing

The existing 57-feature vector must not be copied blindly, and no flood/MGB features are included.

## Latency and forecast coverage

| Strategy | Source latency | Observation semantics | Current 3h target | Feasibility |
|---|---:|---|---|---|
| IMERG Final baseline | ~3.5 months | 30-minute Final | Not live | Research baseline only |
| IMERG Early | ~4 hours | 30-minute family; verify selected run | `3 - 4 ~= -1h` nominal remaining coverage | Requires redesigned target |
| IMERG Late | ~14 hours | 30-minute family; verify selected run | `3 - 14 ~= -11h` | Unsuitable for current target |
| GSMaP NOW | ~0 hours | Hourly rainfall rate, updated every 30 minutes | New hourly contract required | Strong freshness candidate |
| GSMaP NRT | ~4 hours | Product-specific | Same current-horizon concern as Early | Further research |

## Geographic aggregation

IMERG Early can likely reuse the established design pattern of intersecting Caloocan cells on its 0.1-degree grid, but a new source-specific corpus must verify the exact product version and cell set.

GSMaP NOW uses a different grid. A future corpus must document native grid resolution, all cells intersecting the Caloocan boundary, whether cell-center or polygon intersection is used, and whether unweighted or area-weighted aggregation is scientifically justified. The existing IMERG Final aggregation must not be silently reused.

## Engineering and access

IMERG Early has established Earthdata/GES DISC machine-readable access in the repository's research lineage, but authentication, product versioning, latency, and publication completeness require a bounded ingestion feasibility study.

GSMaP NOW is authoritative and low-latency, but the repository has no adapter or governed local archive. Machine-readable access, licensing/usage terms, directory/API stability, expected file sizes, and missing-data handling require verification before ingestion.

No live source, browser path, PHP route, or FastAPI route was added.

## Strategy decision

`LOW_LATENCY_STRATEGY_MORE_EVIDENCE_REQUIRED`

No source wins yet. GSMaP NOW has the strongest freshness profile but requires an hourly source/model contract. IMERG Early has stronger continuity with the current training family and a substantial historical archive, but its approximately four-hour latency makes the unchanged three-hour target impractical.

## Proposed Phase 3F-J

Phase 3F-J should perform bounded source-aligned corpus design and then, only after separate approval, training research:

1. Compare IMERG Early Strategy A against GSMaP NOW Strategy B.
2. Verify exact product versions, publication latency, archive continuity, and missingness.
3. Select the history length and target using source semantics, not the old 48-step contract.
4. Build a small Caloocan aggregate with explicit grid and weighting documentation.
5. Define leakage-safe chronological train/validation/test periods.
6. Fit new preprocessing only inside the approved source-aligned training run.
7. Compare simple persistence/mean baselines before TensorFlow.
8. Evaluate MAE, RMSE, bias, correlation, upper-tail behavior, and latency usefulness.
9. Require provenance, checksum, authorization, failure-state, and production-boundary gates.

No TensorFlow training occurs in Phase 3F-I.

## Dashboard and production state

The dashboard remains conceptually:

- AI service: healthy when the private service is reachable
- TensorFlow runtime: available when installed
- Rainfall research model: available / research only
- Flood-risk model: not available
- Risk policy: not configured
- Prediction ready: no

No UI changes were made. Production remains `NO_APPROVED_LIVE_SOURCE`, global flood readiness remains `MODEL_NOT_AVAILABLE`, and the frozen candidate remains non-operational.
