# Phase 3D: Functional Rainfall Regression Baseline Training

## Scope and authorization

This phase trained a TensorFlow model for `RAINFALL_REGRESSION` under `APPROVED_FOR_PROJECT_RESEARCH_ONLY`. The model predicts `NEXT_3_HOUR_ACCUMULATED_RAINFALL_MM`. It is not a flood/no-flood classifier, flood probability model, flood-depth model, MGB fusion policy, risk classifier, or operational advisory system.

The separate global flood-model authorization remains `NOT_APPROVED`. Historical flood-event labels remain `UNKNOWN`, and no event became training eligible.

## Reproducibility

- Training timestamp UTC: `2026-09-10T11:20:46.472102Z`
- Python: `3.12.10`
- TensorFlow: `2.21.0`
- NumPy: `2.5.2`
- Seed: `20240902`
- Device policy: CPU only
- TensorFlow deterministic operations: enabled
- oneDNN: disabled
- Shuffle: disabled
- Dataset SHA-256: `eb7a53a945c952a2fb129f4ebc9a5b592f2d709c6f7a0548a366e933518f0e96`
- Phase 3C contract SHA-256: `d92296cf9e40bcfb7f1dc712d9c284e335c2da3f32c3f9fdba9dbb2964d5b583`

## Dataset and preprocessing

- Spatial scope: `CITY_LEVEL_MEAN_OF_THREE_CALOOCAN_INTERSECTING_CELLS`
- Input: 48 half-hour city-mean rainfall lags plus five antecedent accumulations and four cyclical time features
- Feature count: 57
- Target: sum of the next six half-hour city-mean rainfall observations
- Target units: mm
- Split: purged chronological
- Train: 12,311
- Validation: 2,596
- Test: 2,490
- Scaler fit split: train only
- Target transform: none

The test split was not supplied to `model.fit` and was evaluated once after validation-based model selection.

## Model and training control

- Architecture: `Input(57) -> Dense(64, ReLU) -> Dense(32, ReLU) -> Dense(1, linear)`
- Parameters: 5,825
- Optimizer: Adam
- Initial learning rate: 0.001
- Loss: MSE
- Metrics: MAE and RMSE
- Batch size: 128
- Epochs requested: 150
- Epochs completed: 25
- Best epoch: 13
- Best validation MSE: 3.5471718311
- Early stopping patience: 12
- `restore_best_weights=true`
- Training duration: 8.314775 seconds

## Metrics

| Evaluation | MAE (mm) | RMSE (mm) |
|---|---:|---:|
| Train | 0.452061 | 1.081776 |
| Validation | 1.008523 | 1.883394 |
| Test, raw predictions | 1.508194 | 2.826146 |

## Leakage-safe baselines

The train-mean baseline uses only the TRAIN target mean: `0.563282 mm`.

| Baseline | Validation MAE | Validation RMSE | Test MAE | Test RMSE |
|---|---:|---:|---:|---:|
| Train mean | 1.245001 | 2.553934 | 1.849186 | 4.145112 |
| Antecedent 3-hour persistence | 1.187273 | 2.259577 | 1.628181 | 3.205977 |

The predeclared comparison rule required raw TensorFlow MAE and RMSE to be lower than both baselines on both validation and test. Result: `BASELINE_COMPARISON_PASS`.

## Test error analysis

| Test subset | Count | MAE (mm) | RMSE (mm) |
|---|---:|---:|---:|
| All | 2,490 | 1.508194 | 2.826146 |
| Zero-rainfall target | 292 | 0.598956 | 0.754995 |
| Nonzero-rainfall target | 2,198 | 1.628984 | 2.995404 |
| Upper tail | 507 | 4.296343 | 5.757136 |

The upper-tail threshold was fixed from TRAIN only as the 90th percentile of positive training targets: `2.487333 mm`. It is descriptive QA, not a flood or risk threshold.

## Negative prediction QA

- Negative raw test predictions: 96
- Minimum raw prediction: `-1.334479 mm`
- Raw test MAE/RMSE: `1.508194 / 2.826146 mm`
- QA-only nonnegative-clamped MAE/RMSE: `1.499782 / 2.824116 mm`

No clamp was applied to the reported model metrics. A future inference-policy review must decide whether to use a nonnegative output architecture or an explicitly governed clamp.

## Candidate artifact

- Status: `CANDIDATE_MODEL_TRAINED`
- Baseline status: `BASELINE_COMPARISON_PASS`
- Model: `ml/flood-risk/artifacts/rainfall-regression/rainfall-regression-dense-57-v0.1.0-candidate/model.keras`
- Model SHA-256: `e729f7ca6f60d702f5ee6c9463ca72ea3c424bb2975cfef274f9319c324d22f6`
- Model bytes: 95,854
- Preprocessing SHA-256: `e0f4234ab583cb52cd2066261d8da717d499c62b54efab423c495a3aa2e95ea4`

The generated bundle is ignored by Git. The compact candidate manifest is stored under `ml/flood-risk/manifests/`.

## Limitations and service boundary

The source is a city-level mean of three coarse IMERG cells and is not barangay-scale ground truth. The dataset spans approximately one year and may not represent rare or changing rainfall regimes. Upper-tail errors are materially larger than overall errors, and the linear output permits physically invalid negative values.

The candidate is not configured in `flood-risk-ai`, is not approved for inference, and is not ACTIVE. `/ready` must remain HTTP 503 `MODEL_NOT_AVAILABLE`.
