# Phase 3F-F IMERG to CMORPH compatibility validation

## Scope and decision

This phase evaluates whether retrospective NOAA CMORPH CDR rainfall can be considered semantically compatible with the NASA GPM IMERG Final rainfall used by the existing private research model.

Decision: `CMORPH_COMPATIBILITY_INSUFFICIENT_EVIDENCE`

This is a research comparison only. It does not approve CMORPH for production serving, does not retrain TensorFlow, does not modify the frozen model or preprocessing, and does not change the Phase 3F-D production source decision: `NO_APPROVED_LIVE_SOURCE`.

## IMERG training-source audit

The governed IMERG corpus is `ml/flood-risk/data/processed/imerg-caloocan-city-mean-2023-2024.json`, built from NASA GPM IMERG Final Half-Hourly V07B samples. Its source metadata specifies:

- native variable: precipitation rate
- native units: `mm/hr`
- interval: 30 minutes
- timestamp semantics: UTC interval start
- spatial resolution: 0.1 degrees
- spatial meaning: unweighted city mean of three Caloocan-intersecting cells
- normalized serving/training value: `city_mean_precipitation_mm`
- conversion: `precipitation_mm = native_rate_mm_per_hour * 0.5 hours`

The window builder uses 48 historical half-hour records, six future half-hour records for the three-hour target, and purged chronological train/validation/test splits. The stored dataset contains 12,311 train windows, 2,596 validation windows, and 2,490 test windows. The current model feature contract has 57 ordered features: 48 rainfall lags, five antecedent accumulations, and four calendar features.

The stored preprocessing is a train-only standard scaler with the frozen Phase 3F-B preprocessing checksum. No CMORPH values were used to fit or alter it.

## CMORPH CDR versus CMORPH RT

The evaluated product is the retrospective/reprocessed **NOAA CMORPH V1.0 ADJ 8 km 30-minute CDR**. It is not the CMORPH real-time (`CMORPH RT`) serving stream. This Phase 3F-F study evaluates retrospective CMORPH CDR against IMERG Final as a pilot training/serving compatibility study. It does not establish CMORPH RT compatibility.

## CMORPH CDR source review

The authoritative source reviewed was the NOAA/NCEI CMORPH CDR product page and its machine-readable NCEI directory:

- Product: NOAA CMORPH V1.0 adjusted 8 km, 30-minute CDR
- Product page: `https://www.ncei.noaa.gov/products/climate-data-records/precipitation-cmorph`
- Download directory: `https://www.ncei.noaa.gov/data/cmorph-high-resolution-global-precipitation-estimates/access/30min/8km/`
- File format: NetCDF4
- Variable: `cmorph`
- Units: `mm/hr`
- Time: UTC NetCDF `time` and `time_bounds`, with 30-minute resolution
- Coverage: global grid from 60S to 60N, including the Caloocan area
- Native spatial grid: approximately 8 km, with longitude coordinates in 0 to 360 degrees
- Missing/fill handling: masked/fill, non-finite, and negative values are rejected

A bounded two-day overlap was acquired into ignored research data only:

`2023-09-01T00:00:00Z` through `2023-09-03T00:00:00Z`

The configured two-day period is 48 hours and requires 48 hourly NetCDF files containing 96 half-hour slices. The script distinguishes `required_file_count`, `available_file_count`, and `downloaded_file_count`; a rerun may download zero new files when all 48 already exist. No raw CMORPH file is staged or versioned.

## Normalization and geographic alignment

The source conversion is explicit and follows the verified source units:

```text
precipitation_mm = cmorph_mm_per_hour * 0.5 hours
```

CMORPH grid cells were selected when their native cell polygons intersected the Caloocan City boundary. The six selected native cells were averaged without weights. This is not identical to IMERG's governed three-cell 0.1-degree mean, so spatial comparability remains a limitation.

No timestamp generation, interpolation, duplication, or zero-filling was performed.

## Direct rainfall comparison

The bounded overlap produced 96 aligned half-hour samples:

- IMERG mean: `0.62325 mm`
- CMORPH mean: `0.75619 mm`
- mean bias, CMORPH minus IMERG: `+0.13294 mm`
- IMERG median: `0.40333 mm`
- CMORPH median: `0.36208 mm`
- IMERG standard deviation: `0.66520 mm`
- CMORPH standard deviation: `0.99947 mm`
- IMERG range: `0.00000` to `2.95500 mm`
- CMORPH range: `0.00000` to `3.81000 mm`
- mean absolute error: `0.45105 mm`
- root mean squared error: `0.69928 mm`
- Pearson correlation: `0.72958`
- Spearman correlation, tie-aware average ranks: `0.52022`
- IMERG rain occurrence: `95.83%`
- CMORPH rain occurrence: `77.08%`
- IMERG zero-rain frequency: `4.17%`
- CMORPH zero-rain frequency: `22.92%`

These metrics are descriptive only. No universal acceptance threshold was invented after observing them.

## Missingness and sliding windows

Missingness is calculated against the expected UTC grid from `START` inclusive through `END` exclusive, not only against the paired intersection. Direct metrics use paired valid observations, while the report separately exposes expected, available, paired, and missing counts for IMERG and CMORPH.

The 96 aligned observations produce 49 one-step 48-observation sliding windows (`96 - 48 + 1`). Adjacent windows share 47 of 48 observations. These 49 windows overlap heavily and are not 49 statistically independent rainfall events.

## Feature and model sensitivity

The analysis formed 49 paired 48-observation windows from the same timestamps, applied the existing canonical `common.rainfall_features` builder, and used the stored train-only preprocessing artifact without refitting.

- paired windows: `49`
- feature count: `57`
- canonical feature order: confirmed
- preprocessing fit split: `train`
- mean absolute scaled-feature difference: `1.14508`
- maximum absolute scaled-feature difference: `8.05929`
- CMORPH scaled absolute values greater than 3: `16.15%`
- mean frozen-model prediction, IMERG: `2.77600 mm`
- mean frozen-model prediction, CMORPH: `2.43144 mm`
- mean absolute prediction difference: `1.10321 mm`
- maximum absolute prediction difference: `3.68864 mm`

The frozen model was loaded only for offline research sensitivity analysis. The model and preprocessing checksums were verified before use. No model artifact was changed.

## Limitations and interpretation

The sample covers only two days and is deliberately bounded. CMORPH CDR is not CMORPH RT. The CMORPH and IMERG grids, retrieval algorithms, bias corrections, and spatial aggregation are not identical. Unweighted intersecting-cell means are only an approximation. The 49 sliding windows overlap heavily and are not independent events. The comparison does not establish behavior across validation/test periods, seasons, storms, missingness regimes, or the full historical corpus. No compatibility threshold has been approved.

The result is therefore insufficient evidence for interchangeability. It is not a declaration that CMORPH is universally incompatible, and it is not operational approval.

## Safety and production state

- `DrrmNoApprovedLiveRainfallHistoryProvider` was not changed.
- Production remains `NO_APPROVED_LIVE_SOURCE`.
- No CMORPH provider or live-source adapter was added.
- No flood probability, risk category, MGB fusion, warning, alert, or Module 4 behavior was added.
- Global flood readiness and FastAPI routes were not changed.
- Operational/public/citizen use remains unapproved.

## Reproduction

From the repository root, with the analysis environment containing `netCDF4`, `shapely`, `numpy`, and the existing TensorFlow runtime:

```powershell
ml/flood-risk/service/.venv/Scripts/python.exe scripts/ai/validate_cmorph_imerg_compatibility.py --acquire
ml/flood-risk/service/.venv/Scripts/python.exe -m unittest tests.ai.test_phase_3ff_cmorph_imerg_compatibility
```

The generated report is intentionally under ignored research data:

`ml/flood-risk/data/processed/pilot/cmorph-imerg-compatibility-report.json`
