# Phase 3F-H live rainfall serving strategy and model alignment

## Decision

Primary strategy: `LIVE_STRATEGY_MORE_SOURCE_RESEARCH_REQUIRED`

The repository does not yet contain a sufficiently verified live rainfall source that can be connected safely to the frozen IMERG Final rainfall model. The current production source remains `NO_APPROVED_LIVE_SOURCE`.

The preferred direction for the next phase is a comparison of two source-aligned model strategies: an IMERG Early model with a forecast formulation redesigned for its latency, and a GSMaP NOW model using its true hourly semantics. No retraining, recalibration, provider activation, or production prediction was performed in Phase 3F-H.

## Existing AI state

The frozen private rainfall research candidate is:

- Model: `rainfall-regression-dense-57-v0.1.1-softplus-candidate`
- Target: `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM`
- Input: 48 chronological UTC observations at 30-minute intervals
- Field: `city_mean_precipitation_mm`
- Spatial meaning: Caloocan City mean
- Feature count: 57
- Status: `VALIDATED_RESEARCH_CANDIDATE`
- Authorization: `APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY`
- Operational: `false`

The frozen model and preprocessing remain unchanged:

```text
model SHA-256:
51c89c12ac5919599998805c11e620aa0d80eda3bd5a6be50ec1570c1fda2865

preprocessing SHA-256:
e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4
```

Phase 3F-C provides private authenticated PHP inference. Phase 3F-D provides the trusted server-side workflow boundary, but its production provider deliberately returns `NO_APPROVED_LIVE_SOURCE`.

## Why global prediction is not ready

The current `/ready` and flood-risk status describe the governed flood-risk model, not the private rainfall research candidate. A reachable TensorFlow service or available rainfall research artifact does not imply approved flood-risk prediction readiness.

The existing status service correctly keeps these concepts separate:

- Runtime health: whether the private service can be reached
- TensorFlow installation: whether the runtime has TensorFlow
- Model status: the configured flood-risk model state
- Risk policy status: the flood-risk policy state
- Prediction ready: approved flood-risk inference only

The private rainfall candidate is research-only and does not make final flood-risk prediction ready.

## Evidence from CMORPH CDR studies

Phase 3F-F compared retrospective NOAA CMORPH V1.0 ADJ 8 km 30-minute CDR with NASA IMERG Final over a bounded two-day period. Phase 3F-G expanded this to four predeclared 72-hour periods and multiple IMERG-only rainfall regimes.

The final Phase 3F-G decision was:

`CMORPH_CDR_MULTI_PERIOD_INSUFFICIENT_EVIDENCE`

The studies found different rainfall distributions, spatial-grid differences, regime-dependent disagreement, feature shift, and model-output sensitivity. CMORPH CDR must not be treated as interchangeable with IMERG Final.

CMORPH CDR is retrospective/reprocessed historical data. It is not CMORPH RT. The CDR result does not establish CMORPH RT serving compatibility.

## Candidate source strategies

### NASA IMERG-based serving

NASA IMERG is the strongest alignment candidate because the existing model was developed from IMERG Final Half-Hourly V07B semantics:

- 30-minute precipitation product family
- precipitation rate converted to 30-minute millimetres
- UTC interval-start semantics
- global coverage including Caloocan
- established Earthdata/GES DISC machine-readable access in the repository research artifacts
- reproducible Caloocan intersecting-cell aggregation already implemented for Final

IMERG Early and Late retrospective processing have substantial upstream historical availability, approximately January 1998 through the present. That archive availability is distinct from having a governed CIVentral source-aligned training corpus, which does not yet exist.

NASA's approximate product latencies are about 4 hours for IMERG Early, 14 hours for IMERG Late, and 3.5 months for IMERG Final. For the unchanged three-hour target, if the newest usable observation is $L$ hours old, nominal remaining future coverage is approximately $3-L$ hours. Therefore:

```text
IMERG Early: 3 - 4 ~= -1 hour
IMERG Late:  3 - 14 ~= -11 hours
IMERG Final: retrospective, not live
```

IMERG Early is not a practical live source for the current unchanged three-hour target. It may become suitable only if the forecast horizon or forecasting formulation is redesigned and a new model contract is trained. IMERG Late is even less suitable for that unchanged target.

The current frozen Final-trained model must not be silently reused with IMERG Early/Late. A future source-contract study must verify the exact NRT product and should prefer a new source-aligned model if the serving product differs materially from Final.

Latency matters: if the newest usable observation is `L` hours old and the model forecasts the next three hours after that observation, the nominal remaining horizon is approximately `3 - L` hours. The operational usefulness of a three-hour target must be assessed against actual product latency, retrieval availability, and server processing time.

Assessment: **historically viable candidate for a new source-aligned model, but not suitable for the unchanged three-hour target at its approximate latency**.

### NOAA CMORPH real-time or near-real-time serving

The Phase 3F studies evaluated CMORPH CDR, not CMORPH RT. CMORPH RT must be audited separately for:

- exact RT product identity and version
- precipitation variable and units
- timestamp and accumulation semantics
- update cadence and practical latency
- archive depth and historical training availability
- missing/fill behavior
- Caloocan aggregation reproducibility
- machine-readable access and governance

No repository evidence currently establishes that CMORPH RT has a sufficient historical archive with the exact serving semantics needed for source-aligned training. The CDR multi-period disagreement is a reason to reject silent substitution, not evidence that RT is compatible.

Assessment: **do not use without a separate CMORPH RT metadata, archive, and source-alignment study**.

### JAXA GSMaP NOW and NRT serving

GSMaP is a credible authoritative satellite precipitation family, but the relevant product must be identified precisely before use. The repository currently contains no GSMaP acquisition, normalization, Caloocan aggregation, or validated machine-readable adapter.

GSMaP NOW is documented as an approximately zero-latency product with rainfall rate in `mm/hr`, one-hour temporal resolution, and half-hourly updates. It has historical archive availability from approximately 2017, with the current v8 period from 2021 onward. A product updated every 30 minutes is not thereby an independent 30-minute accumulation product.

GSMaP NRT is a separate candidate with approximately four-hour latency and therefore has the same basic current three-hour-horizon concern as IMERG Early.

The next audit must distinguish whether the selected GSMaP product provides:

- true 30-minute accumulation, or
- hourly precipitation/rate values updated on a 30-minute schedule

It must also verify units, timestamp meaning, latency, historical archive depth, missing/fill semantics, and whether the same geographic aggregation can be reproduced. GSMaP NOW must not be forced into the current 48 x 30-minute contract; it is a candidate for a new source-aligned hourly model contract. No conversion or reinterpretation is approved in this phase.

Assessment: **GSMaP NOW is the best currently identified low-latency candidate, but it requires a new hourly source/model contract; GSMaP NRT has a current-horizon latency concern**.

### PAGASA observed rainfall

The current repository integration is the official PAGASA TenDay forecast service. It provides issuance metadata and daily forecast records, not a demonstrated 48-observation, 30-minute, UTC observed rainfall history with Caloocan-city-mean semantics.

The PAGASA climate-data pages expose request/download-oriented climatological information and station products, but no approved repository-integrated live 30-minute observed rainfall source has been demonstrated.

Result: `PAGASA_COMPATIBLE_HISTORY_SOURCE_NOT_FOUND`

No PAGASA endpoint was fabricated and no forecast field is being used as observed rainfall.

Assessment: **not currently acceptable as the model history source**.

## Strategy matrix

| Source/product | Historical data | Near-real-time | 30-minute semantics | Caloocan aggregation | Latency/target usefulness | Training-serving alignment | Recommendation |
|---|---|---|---|---|---|---|---|
| IMERG Final | Strong repository corpus | No, retrospective | Demonstrated | Demonstrated approximate three-cell mean | Not live | Matches frozen research model | Keep as research baseline |
| IMERG Early | Historical archive approximately Jan 1998-present; CIVentral corpus not governed | Yes | 30-minute family semantics; selected run must be verified | Likely feasible, must be reproduced | ~4 hours; `3 - 4 ~= -1`, so current target is impractical | New source-aligned model required | Study with redesigned horizon/formulation |
| IMERG Late | Historical archive approximately Jan 1998-present; CIVentral corpus not governed | Yes | 30-minute family semantics; selected run must be verified | Likely feasible, must be reproduced | ~14 hours; current target impractical | New source-aligned model required | Lower priority feasibility study |
| CMORPH CDR | Strong historical archive | No, retrospective | Demonstrated | Approximate six-cell mean | Not live | Multi-period evidence insufficient | Do not substitute |
| CMORPH RT | Requires separate archive/metadata audit | Candidate | Not yet verified here | Potentially feasible | Must be measured | Not proven | More research required |
| JAXA GSMaP NOW | Archive approximately 2017-present; current v8 from 2021 | Approximately zero latency; updated half-hourly | Hourly rainfall rate/temporal resolution, not independent 30-minute accumulations | Not implemented | Best low-latency candidate; new hourly target required | New source-aligned model required | Lead Phase 3F-I candidate |
| JAXA GSMaP NRT | Archive/product access requires verification | Yes, approximately 4-hour latency | Product-specific; not assumed | Not implemented | Same current-horizon concern as IMERG Early | New source-aligned model required | Compare in Phase 3F-I |
| PAGASA TenDay | Forecast metadata/daily forecast, not required history | Forecast service | Not compatible with demonstrated history contract | Not demonstrated | Not suitable as observed-history source | Not aligned | Reject for current model history |

No numeric score or arbitrary compatibility threshold was used.

## Selected strategy

`LIVE_STRATEGY_MORE_SOURCE_RESEARCH_REQUIRED`

This is more defensible than reusing the frozen IMERG Final model against an unverified NRT product or substituting CMORPH after insufficient compatibility evidence.

## Recommended next model-development phase

The next phase should be **Phase 3F-I: LOW-LATENCY SOURCE / FORECAST-TARGET FEASIBILITY STUDY**. It should compare two concrete strategies before any training:

**Strategy A: IMERG Early source-aligned model**

- Use IMERG Early historical data.
- Redesign the forecast horizon/formulation to account for approximately four-hour latency.
- Define a new model contract rather than reusing the unchanged three-hour target.

**Strategy B: GSMaP NOW source-aligned model**

- Use true hourly GSMaP NOW rainfall semantics.
- Preserve a useful near-future three-hour prediction target where scientifically defensible.
- Define a new hourly-history feature and target contract.

Phase 3F-I should determine required operational freshness, acceptable latency, horizon, archive consistency, Caloocan aggregation, source reliability, feature contract, training-period design, and target definition before any training.

The eventual training study should then:

1. Select one exact live product and its revised target contract.
2. Acquire a small, governed historical sample and confirm field, units, timestamps, missingness, and latency semantics.
3. Reproduce the Caloocan City mean using the same defined geographic method.
4. Define the source-specific history and target using the serving product itself.
5. Preserve the canonical feature concept where appropriate, but refit preprocessing only inside the separately approved new training run.
6. Use purged chronological train/validation/test splits with seasonal and heavy-rain coverage.
7. Compare the new source-aligned model against the frozen IMERG Final candidate as a research baseline.
8. Review checksums, provenance, authorization, failure behavior, and operational status before any deployment decision.

Expected future model naming should identify the serving product and processing version, for example a reviewed product-specific candidate rather than overwriting `rainfall-regression-dense-57-v0.1.1-softplus-candidate`.

No training occurs in Phase 3F-H.

## Relationship to flood classification

Rainfall prediction is only one future stage:

```text
validated live rainfall observations
        -> source-aligned rainfall prediction
        -> validated flood/hazard/susceptibility inputs
        -> governed flood-risk model or policy
        -> risk categories
        -> DRRM officer decision support
```

Phase 3F-H does not implement flood classification, MGB fusion, risk levels, warnings, alerts, evacuation recommendations, or Module 4 activation.

## Dashboard interpretation

The current status architecture is technically correct when it reports a healthy private AI service while prediction readiness remains false. That status refers to the governed final flood-risk path, not the private rainfall research candidate.

A future UI may clarify the distinction with wording such as:

- Rainfall Research Model: Available / Research Only
- Flood Risk Model: Not Available
- Prediction Ready: No

No UI changes were made in Phase 3F-H.

## Production safety state

- Production rainfall source: `NO_APPROVED_LIVE_SOURCE`
- Private rainfall authorization: `APPROVED_FOR_PRIVATE_PROJECT_RESEARCH_ONLY`
- Operational use: not approved
- Public/citizen use: not approved
- Flood-classifier use: not approved
- MGB fusion: not approved
- Global flood readiness: remains unavailable
- No browser/mobile direct AI path exists
- No CMORPH provider or live source adapter exists
