# Phase 3F-J bounded source-contract verification

## Decision

`SOURCE_SELECTION_MORE_EVIDENCE_REQUIRED`

This phase performed bounded real-data/source-contract checks for NASA IMERG Early and JAXA GSMaP NOW. It did not train TensorFlow, create a provider, modify the frozen model, or approve production serving.

Production remains `NO_APPROVED_LIVE_SOURCE`.

## Bounded acquisition preflight

No bulk corpus was acquired.

| Source | Planned sample | Estimated size | Result |
|---|---:|---:|---|
| NASA IMERG Early V07 | One recent 30-minute HDF5 granule | 8,259,257 bytes from HTTP HEAD | GET returned an HTML Earthdata access page without credentials; no raw HDF5 retained |
| JAXA GSMaP NOW | One point sample at 14.65N, 120.95E | 223-byte HTML response | Successful point sample; no bulk file downloaded |

Both checks were far below the 500 MB cap. No credentials were requested or stored.

## IMERG Early verification

Authoritative NASA Earthdata CMR collection:

- Collection: `GPM_3IMERGHHE`
- Version: `07`
- Title: `GPM IMERG Early Precipitation L3 Half Hourly 0.1 degree x 0.1 degree V07`
- Current granule processing observed: `V07C`
- Historical archive: `1998-01-01` onward in collection metadata
- Temporal semantics: half-hourly granules, interval start/end timestamps
- Spatial resolution: 0.1 degree by 0.1 degree
- Machine-readable access: CMR granule metadata, HTTPS HDF5 links, OPeNDAP links
- Authentication: Earthdata-protected data GET in this environment; metadata discovery is public

Recent CMR discovery returned an IMERG Early granule ending at:

```text
2026-09-10T23:59:59.999Z
```

The verification clock was approximately:

```text
2026-09-11T04:34:44Z
```

Observed discovery age was approximately 4.6 hours, consistent with the documented approximate Early latency but only a single observation. This is classified `STALE_FOR_CURRENT_3H_TARGET` for the unchanged target. It does not establish universal latency.

No actual HDF5 value decoding occurred because the unauthenticated GET returned an HTML access page. Therefore variable attributes, fill values, and a bounded Caloocan mean remain unverified in this phase. The CMR product metadata is sufficient to confirm collection identity and temporal/spatial family semantics, not full serving approval.

## IMERG Early target feasibility

For the unchanged target:

```text
NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM
remaining nominal future coverage = 3 - 4 ~= -1 hour
```

The unchanged target is not practically live at the observed/documented Early latency.

Candidate source-aligned targets remain research options:

- +4h to +7h after the latest observation
- +5h to +8h with additional latency margin
- Availability-time-anchored future three-hour target
- Next-six-hour accumulation

Each requires historical labels strictly after the observation cutoff and a new model contract.

## GSMaP NOW verification

Authoritative JAXA product page and official JavaScript request contract:

- Product family: JAXA GSMaP Global Rainfall Watch
- Product: GSMaP NOW
- Official page: `https://sharaku.eorc.jaxa.jp/GSMaP/`
- Official point endpoint exposed by the page: `/cgi-bin/trmm/GSMaP/tilemap/pick.cgi`
- Request semantics: `prod=rain`, `ymd=YYYYMMDDHH`, latitude, longitude
- Returned value semantics: rainfall rate in `mm/hr`
- Returned timestamp: hourly UTC label
- Documented nominal product latency: approximately `0-hour`
- Temporal resolution: hourly
- Update cadence: half-hourly updates; this does not create independent half-hourly rainfall accumulations
- Historical archive: approximately from `2017-03-29`; algorithm/product versions changed through time, with v8 beginning approximately `2021-12-06`
- Historical page/archive behavior: the official page exposes latest/24-hour views and hourly CSV/subset functionality; this is not evidence that only 24 hours of historical data exist
- Official binary/data acquisition: requires JAXA user registration; no credentials were stored

Bounded real sample:

```text
Requested point: 14.650000N, 120.950000E
Timestamp: 2026-09-10 23:00 UTC
Rain: 0.28 mm/hr
Satellite information: NOAA/CPC Globally Merged IR data
```

The page's latest-time script reported a latest NOW product around 23:00 UTC while the verification clock was approximately 04:34 UTC, an observed age of about 5.6 hours. The documented nominal latency remains approximately 0 hours. The bounded observation was stale relative to the research freshness criterion. This does not establish normal GSMaP NOW latency and may reflect temporary source/service availability; authoritative JAXA Rainfall Watch material includes system-trouble/update-stop notices, but no outage was attributed to this exact sample time.

The point sample does not prove a Caloocan area aggregate. A future bounded corpus must identify the GSMaP native grid cells covering/intersecting Caloocan and document unweighted versus area-weighted aggregation.

No independent 30-minute values were fabricated from the half-hourly update cadence.

## GSMaP history contracts

Candidate source-aligned contracts:

- B1: 24 hourly observations representing the prior 24 hours
- B2: 48 hourly observations representing the prior 48 hours

B1 is lower-cost and sufficient for short antecedent context. B2 offers more context but increases missingness and feature cost. Neither reuses the frozen 48 x 30-minute contract.

Candidate targets:

- `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM`, using the next three hourly records
- `NEXT_6_HOUR_ACCUMULATED_RAINFALL_MM`, using the next six hourly records

Historical construction must exclude future records from features and use chronological splits.

## Comparison

| Field | IMERG Early | GSMaP NOW |
|---|---|---|
| Exact product | GPM IMERG Early L3 Half Hourly V07, `GPM_3IMERGHHE`, current sample V07C | JAXA GSMaP NOW |
| Bounded sample | CMR recent granule metadata; data GET requires Earthdata access | Real point sample returned |
| Semantics | Half-hourly 0.1-degree precipitation product family | Hourly rainfall rate in mm/hr |
| Documented nominal latency | ~4 hours | ~0 hours |
| Observed sample/service age | ~4.6 hours in this check | ~5.6 hours in this check |
| Current 3h target | Impractical at ~4h latency | Requires new hourly contract; sample was stale in this check |
| History contract | New half-hour latency-aware contract | B1 or B2 hourly contract |
| Caloocan aggregation | Likely grid intersection, not decoded here | Not yet verified from native grid |
| Training archive | 1998-present metadata | Approximately 2017-03-29 onward; v8 approximately from 2021-12-06; version boundaries require governance |
| Training-serving consistency | Product family exists but version/attributes/corpus remain unverified | Product semantics differ from frozen model and require new training |
| Access | Earthdata/PPS registration or authentication required for actual data | Public point/web query exists; official binary/data acquisition requires JAXA user registration |
| Main limitation | Protected sample data and latency | Point sample, hourly semantics, version continuity, registration, and temporary service state require broader validation |

## Selection

`SOURCE_SELECTION_MORE_EVIDENCE_REQUIRED`

Neither source can be selected for source-aligned model research from one bounded check:

- IMERG Early has strong historical metadata and product-family continuity, but its approximate latency conflicts with the unchanged three-hour target and actual value decoding was blocked by Earthdata authentication.
- GSMaP NOW has the better documented nominal low-latency design and a successful machine-readable point sample, but the observed sample was stale during this check, may reflect temporary service/update state, and a reproducible Caloocan area aggregate was not established.

## Next phase

**Phase 3F-K: CREDENTIALED SPATIAL SAMPLE & FINAL SOURCE SELECTION** should use credentials only through approved local secure processes. It should:

- authenticate locally with the user's Earthdata account and decode one or a few IMERG Early HDF5 granules;
- verify precipitation variables, fill/quality fields, and a bounded Caloocan aggregate;
- use authorized JAXA registered data access for a small native GSMaP NOW sample;
- verify GSMaP grid geometry, Caloocan aggregation, archive/version transitions, and freshness at multiple times where feasible.

Passwords and tokens must not be requested, printed, stored, or committed.

Training begins only after Phase 3F-K makes one final source selection.

Recommended order after that gate:

1. Phase 3F-K: credentialed bounded spatial verification and final source selection
2. Phase 3F-L: source-aligned historical corpus construction
3. Phase 3F-M: baseline and TensorFlow model training/evaluation

## Safety state

- Frozen model unchanged.
- Frozen preprocessing unchanged.
- No live provider created.
- No PHP, FastAPI, Docker, or UI changes.
- No flood probability, risk level, MGB fusion, warning, or Module 4 behavior.
- Production remains `NO_APPROVED_LIVE_SOURCE`.
