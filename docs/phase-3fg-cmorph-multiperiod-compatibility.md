# Phase 3F-G multi-period CMORPH CDR to IMERG compatibility study

## Decision

`CMORPH_CDR_MULTI_PERIOD_INSUFFICIENT_EVIDENCE`

This is a retrospective research study of NOAA CMORPH CDR against NASA GPM IMERG Final. It does not establish CMORPH RT compatibility, production serving compatibility, or operational approval.

Production rainfall source remains `NO_APPROVED_LIVE_SOURCE`.

## Predeclared selection

Four equal 72-hour periods were selected from the existing IMERG corpus before CMORPH comparison metrics were calculated. Selection used only IMERG statistics and existing purged train/validation/test source boundaries.

`LOW_RAIN`, `MODERATE_RAIN`, `HIGH_RAIN`, and `VERY_HIGH_RAIN` are relative IMERG-only research sampling strata based on candidate 72-hour mean rainfall quantiles. They are not disaster-risk levels, flood-risk categories, PAGASA warning levels, operational thresholds, or TensorFlow flood-classification outputs. The labels correspond approximately to candidate p10, p40, p70, and p90 respectively, not official rainfall thresholds.

| Period | Regime | Split | Start UTC | End UTC exclusive | IMERG mean |
| --- | --- | --- | --- | --- | ---: |
| P1 | LOW_RAIN | train | 2024-04-27T02:00:00Z | 2024-04-30T02:00:00Z | 0.01336 mm |
| P2 | MODERATE_RAIN | test | 2024-08-10T03:00:00Z | 2024-08-13T03:00:00Z | 0.04691 mm |
| P3 | HIGH_RAIN | validation | 2024-07-05T13:30:00Z | 2024-07-08T13:30:00Z | 0.16067 mm |
| P4 | VERY_HIGH_RAIN | train | 2023-10-16T14:30:00Z | 2023-10-19T14:30:00Z | 0.30855 mm |

The fixed selection manifest is `ml/flood-risk/manifests/phase-3fg-cmorph-multiperiod-selection.json`. It contains no CMORPH outcomes.

The four selected periods are non-overlapping: after sorting by start time, each period ends at or before the next period begins.

## Acquisition preflight and acquisition

The four periods required 290 hourly CMORPH files. The preflight estimated approximately 528.8 MB of total selected raw data and remained below the 500-file and 2 GiB guards. Raw data remains ignored under `ml/flood-risk/data/raw/cmorph/`.

The final report records 290 required and 290 available files. Newly downloaded count reflects the final acquisition run and is distinct from the total available file count.

## Product identity

The evaluated product is NOAA CMORPH V1.0 ADJ 8 km 30-minute CDR, a retrospective/reprocessed historical product. It is not CMORPH RT. A successful CDR comparison would not establish CMORPH RT compatibility.

CMORPH metadata validation requires seconds-based UTC-compatible time units, supported calendar metadata, `time_bounds`, and inclusive 30-minute bounds. The precipitation variable must be `mm/hr`; values are converted with:

```text
30-minute precipitation_mm = precipitation_rate_mm_per_hour * 0.5 hours
```

Masked/fill, non-finite, negative, malformed, and missing values fail closed. No timestamps are fabricated, no values are interpolated, and no missing values are zero-filled.

## Spatial alignment

IMERG uses the governed unweighted mean of three Caloocan-intersecting 0.1-degree cells. CMORPH uses the unweighted mean of native approximately 8 km cells whose polygons intersect the Caloocan boundary. The grids and aggregation footprints are not identical. No spatial calibration was introduced.

## Coverage and windows

Across four complete periods:

- Expected observations: 576
- IMERG available: 576
- CMORPH available: 576
- Paired available: 576
- Missingness: zero for both sources in this acquired study
- Sliding 48-observation windows: 388 total, 97 per period
- Non-overlapping 48-observation windows: 12 total, 3 per period

Sliding windows overlap heavily. Adjacent windows share 47 of 48 observations and are not statistically independent rainfall events.

Window handling uses the complete expected period grid. Each candidate sliding window is validated independently; a missing timestamp rejects only windows containing that timestamp. Non-overlapping windows are built directly from fixed blocks `0..47`, `48..95`, and `96..143`, never by filtering the sliding-window list.

## Pooled rainfall metrics

Across 576 paired observations:

- IMERG mean: `0.13237 mm`
- CMORPH mean: `0.10794 mm`
- Mean bias, CMORPH minus IMERG: `-0.02443 mm`
- MAE: `0.10908 mm`
- RMSE: `0.31722 mm`
- Pearson correlation: `0.68224`
- Tie-aware Spearman correlation: `0.49906`
- IMERG rain occurrence: `44.79%`
- CMORPH rain occurrence: `18.06%`
- IMERG zero-rain frequency: `55.21%`
- CMORPH zero-rain frequency: `81.94%`

Per-period metrics are retained in the ignored JSON report. They vary materially by selected rainfall regime, so pooled metrics are not treated as evidence of uniform agreement. Pooled observations are temporally correlated.

The report also contains IMERG-defined 50th, 75th, 90th, and 95th percentile upper-tail comparisons per period. These are descriptive rainfall comparisons, not operational flood thresholds.

## Feature and model sensitivity

The analysis reused the canonical 57-feature builder and the stored train-only preprocessing artifact. The scaler was not refit. Frozen model and preprocessing checksums were verified before offline inference.

For each period and for sliding/non-overlapping windows, the report includes:

- Mean absolute scaled-feature difference
- Maximum absolute scaled-feature difference
- `cmorph_scaled_abs_gt_3_fraction`
- Mean IMERG prediction
- Mean CMORPH prediction
- Mean absolute prediction difference
- Median absolute prediction difference
- Maximum absolute prediction difference

The predictions are offline research sensitivity results, not operational truth.

## Limitations

- Only four 72-hour periods were evaluated.
- Periods were selected from IMERG-only statistics and do not represent all seasons or events.
- CMORPH CDR is not CMORPH RT.
- IMERG and CMORPH algorithms, bias corrections, grids, and spatial aggregation differ.
- Unweighted intersecting-cell means are an approximation.
- Sliding-window observations are highly correlated.
- No compatibility threshold has been approved.
- No calibration or retraining was performed.
- No production approval or live adapter exists.
- Broader multi-period and multi-season validation remains required.

## Safety state

The study did not modify the frozen model, preprocessing, FastAPI, Docker, production provider, MGB logic, flood classification, risk categories, warnings, alerts, or Module 4. Production remains `NO_APPROVED_LIVE_SOURCE` with the provider status `UNAVAILABLE` and null history.
