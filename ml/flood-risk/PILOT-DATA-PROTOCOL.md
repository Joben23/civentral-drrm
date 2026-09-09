# Phase 3B1 real flood dataset pilot protocol

This is a governed data-acquisition and labeling foundation. It does not
authorize TensorFlow training, generate predictions, activate a risk policy, or
alter the GIS flood-reference checks.

## Reused Phase 3A governance

- `source-manifest.json` remains the single source registry and checksum ledger.
- `training-authorization.json` remains the explicit human authorization gate.
- The 187-barangay reference and existing point-in-polygon resolver remain the
  supported Caloocan spatial boundary.
- MGB `LF/MF/HF/VHF/NONE` remains a static covariate only.
- Existing validators still reject missing rainfall, inferred negatives,
  unresolved Barangay 176 mapping, unapproved provenance, and leakage failures.
- The fixed 24-hour training schema remains provisional and is not the raw
  precipitation or event-evidence format.

## Source roles and lifecycle

The extended registry records static GIS, spatial reference, observed
precipitation, forecast precipitation, flood-event evidence, negative-event
evidence, and optional precipitation cross-check roles.

Lifecycle is `PROPOSED`, `ACQUIRED`, `VALIDATED`, `APPROVED_FOR_PILOT`,
`REJECTED`, or `RETIRED`. Acquisition does not imply validation or approval.
Pilot use also requires `availability_status=ACQUIRED` and
`license_or_usage_status=PERMITTED_FOR_PILOT`.

NASA GPM IMERG Final Half Hourly V07 (`GPM_3IMERGHH.07`, current V07B
processing) is the primary
historical precipitation candidate. Its official metadata describes a
30-minute, 0.1-degree satellite precipitation-rate estimate. It is not PAGASA
ground truth. ERA5-Land hourly precipitation is an optional reanalysis
cross-check and must remain a separately named source. Phase 3B2 acquired a
bounded official NASA ImageServer representation of IMERG, but it remains
`ACQUIRED`, provisional, and not `APPROVED_FOR_PILOT`; ERA5-Land remains not
acquired. PAGASA raw climatological data is `PAGASA_DATA_NOT_ACQUIRED`; no
station is assumed. See `PHASE-3B2-EVIDENCE-ACQUISITION.md`.

## Labels and negative evidence

The event registry is separate from raw Module 3 citizen incidents. Its labels
are `FLOOD_CONFIRMED`, `NO_FLOOD_CONFIRMED`, and `UNKNOWN`.

`UNKNOWN` is not a negative. A missing DROMIC, NDRRMC, Module 3, or citizen
report is not evidence that flooding did not occur.

`NO_FLOOD_CONFIRMED` requires affirmative, human-reviewed evidence covering
the exact location and target window: an explicit LGU monitoring no-flood
record, an explicit authoritative situation-log no-flood entry, or equivalent
authoritative monitoring. Reviewer, review time, source document, and leakage
review are mandatory.

## Configurable event windows

Each candidate window records `prediction_cutoff`, `target_window_start`,
`target_window_end`, `label_observed_at`, and `forecast_horizon_hours`.
`prediction_cutoff` must be earlier than the target evaluation period. Horizon
is descriptive per window; Phase 3B1 does not select a production horizon.
Evidence created after cutoff may support a label but may not be an input.

## Raw and reviewed precipitation

Acquired files under `data/raw/precipitation/` are immutable. Reviewed
observations retain source/product version, exact interval timestamps, grid
coordinate and cell, native value/unit, explicit conversion, interval
accumulation in millimetres, quality flag, source resolution, retrieval time,
raw reference, and checksum. No label is stored in a precipitation record.
Missing precipitation is never converted to zero.

`OBSERVED_PRECIPITATION` and `FORECAST_PRECIPITATION` are different data types.
Observed precipitation cannot masquerade as an archived forecast. Forecast
features remain `NOT_AVAILABLE_FOR_TRAINING` until an as-issued forecast archive
is acquired and governed.

## Caloocan grid mapping

No method is a silent default. Every event window records one source-specific
method and version:

1. `POINT_CONTAINING_CELL` for a reliable point in a documented cell.
2. `NEAREST_VALID_CELL` only with recorded distance and reviewed reason.
3. `AREA_WEIGHTED_POLYGON` with explicit cell weights and coverage fraction.

Cells and weights are sorted before aggregation. Source resolution, cells,
distance/coverage, and a limitation note are retained.

Caloocan is narrow and spatially fragmented relative to a 0.1-degree grid.
Cells may cover large areas outside a barangay or city. Point cells can miss
within-city variation; nearest cells introduce distance error; area weighting
needs validated grid geometry and polygon overlay. The choice must be reviewed
per source and label location.

## Leakage-safe temporal aggregation

Candidate antecedent windows are 1, 3, 6, 12, 24, and 72 hours. The builder
requires continuous, non-overlapping observations for every selected grid cell
through the cutoff. Every interval must end at or before cutoff. A gap, overlap,
missing value, mixed product version, or non-validated quality flag excludes
the row. Area-weighted totals are computed only after complete per-cell totals
exist. Raw values remain untouched.

## Fail-closed pilot builder

A row is emitted only when its label is confirmed, human/leakage review passes,
`training_eligible=true`, event and precipitation sources are acquired and
approved for pilot use, terms permit pilot use, mapping validates, and all
antecedent windows are complete.

All failures appear in the companion manifest as exclusions. Every Phase 3B1
manifest says `training_authorization_status=NOT_APPROVED`,
`training_ready=false`, `tensorflow_training_performed=false`, and
`model_artifact_created=false`. CSV rows and exclusions are sorted; a fixed
`--created-at` yields deterministic output.

## 24 June 2019 DROMIC workflow

The registry entry remains `UNKNOWN`, unreviewed, and ineligible.

1. Open the registered DROMIC locator.
2. Acquire linked initial and terminal reports without altering them.
3. Store them locally under `data/raw/event-evidence/`.
4. Record exact filenames, retrieval time, citation, version, terms review, and
   SHA-256 checksums in the source registry.
5. Extract only timing supported by the documents; retain precision/uncertainty.
6. Extract only supported locations; do not infer barangays from city or
   North/South wording.
7. Choose/version a precipitation mapping only after spatial review.
8. Acquire bounded precipitation covering the required antecedent period.
9. Verify UTC alignment and that all input observations end by cutoff.
10. Record reviewer identity/time, evidence, uncertainties, and leakage result.
11. Set `FLOOD_CONFIRMED` only if exact event window/location evidence supports
    it. Otherwise keep `UNKNOWN`.

## IMERG original-granule manual acquisition step

No credentials are included. Phase 3B2 confirmed that original HDF5 access
requires NASA Earthdata authentication. An authorized researcher must:

1. Sign in through NASA Earthdata/GES DISC and open DOI
   `10.5067/GPM/IMERG/3B-HH/07`.
2. Select `GPM_3IMERGHH.07`, the reviewed event interval, and required
   antecedent period only.
3. Bound the request to validated Caloocan extent plus a scientifically needed
   margin.
4. Place original HDF5/NetCDF granules in `data/raw/precipitation/` without
   changing the source portion of filenames.
5. Run `Get-FileHash -Algorithm SHA256 <downloaded-file>`.
6. Record locator, actual retrieval time, filename, checksum, terms review, and
   acquired metadata in the source registry.
7. Validate variable, quality flag, UTC interval, native `mm/hr` unit, and
   rate-to-interval conversion before creating canonical JSON.

Partial or failed acquisition is quarantined and produces no canonical records.

Phase 3B2's credential-free official NASA ImageServer subset is a distinct
derived service representation and must never be called an original HDF5
granule.

## PAGASA acquisition track

PAGASA data may require an external/manual request. Do not scrape protected
sources. Request only the period/resolution justified after event and horizon
review. When received, record station name and ID exactly as supplied,
observation timestamp, rainfall and units, source filename/checksum, retrieval
time, coverage, quality metadata, citation, and terms reference. Do not infer a
Caloocan station or silently map a station to the city.

## External acquisition safety

Any future downloader must use HTTPS, an explicit host/product allowlist,
bounded time/area parameters, deterministic filenames, timeouts/retries,
checksums, atomic completion, and separate raw/processed paths. Credentials
must come from the environment and never enter URLs, logs, manifests, fixtures,
or source control. Failed downloads are never replaced by mock production data.

## Commands

Quality-only report:

```powershell
python scripts/ai/report_flood_pilot_quality.py `
  --created-at 2026-01-01T00:00:00Z
```

Candidate build after governed inputs exist:

```powershell
python scripts/ai/build_flood_pilot_dataset.py `
  --precipitation ml/flood-risk/data/reviewed/precipitation `
  --created-at 2026-01-01T00:00:00Z
```

Neither command imports TensorFlow or changes the AI service.

Phase 3B2 acquisition validation and one-time immutable normalization:

```powershell
python scripts/ai/validate_flood_acquisition.py
python scripts/ai/normalize_imerg_observations.py
```

The normalizer refuses to overwrite an existing reviewed derivative. The
reviewed result remains provisional and ignored; it does not create an event
window or training row.

## Phase 3B3-A multi-event discovery

Phase 3B3-A extends the existing registries; it does not introduce a competing
event or provenance store. The governed discovery index and rationale are in
`manifests/candidate-events.json` and
`PHASE-3B3A-CANDIDATE-DISCOVERY.md`. Six additional official-source Caloocan
flood candidates are registered for evidence acquisition. Every discovered
candidate defaults to `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, and
`training_eligible=false`.

Multiple revisions from one official incident/report family remain one event.
Ambiguous duplicate signals are flagged and never silently merged. A report's
issue or publication time is not an event onset, street names are not inferred
to barangays, and legacy Barangay 176 is not remapped. Discovery does not
authorize source download, IMERG acquisition, a training window, a positive or
negative label, or an ML target. The target remains pending evidence review.
