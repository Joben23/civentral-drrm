# CIVENTRAL flood-risk data foundation

This workspace is the Phase 7B real-data foundation for the future
**TensorFlow-based flood-risk decision-support prototype**. It does not contain
a trained model and is not an operational warning system.

## Provisional downstream target

Phase 7B/3A scaffolded the probability of at least one **verified** flood
occurrence in one validated Caloocan spatial unit during a next-24-hour window.
Phase 3B1 does not finalize that target or horizon. The 24-hour contract remains
non-operational until real label and precipitation resolution support a human
target decision.

The raw labels are:

- `VERIFIED_FLOOD_OBSERVED` (`flood_outcome = 1`)
- `VERIFIED_NO_FLOOD_OBSERVED` (`flood_outcome = 0`)

`UNKNOWN`, `AMBIGUOUS`, and `EXCLUDED` never become negative labels. CIVENTRAL
`LOW`, `MODERATE`, `HIGH`, and `CRITICAL` are downstream decision-support
categories and are not fields in this training schema.

## Dataset grain

The existing provisional downstream record represents:

```text
one Caloocan spatial unit
× one forecast issuance
× one next-24-hour valid window
```

The canonical record format is defined in
`schemas/flood-training-record.schema.json`. JSON, JSON Lines, and CSV are
accepted by the validator. Empty CSV cells are read as explicit missing values;
the tools never impute or silently repair them.

## Workspace layout

- `config/` — versioned governance policy.
- `schemas/` — canonical record, provenance, candidate-event, and feature
  contracts.
- `data/raw/` — untouched approved downloads or manual source exports.
- `data/reviewed/` — human-reviewed canonical records only.
- `data/processed/` — deterministic preprocessing outputs only.
- `manifests/` — source provenance and non-training candidate-event index.
- `scripts/ai/` — validation, GIS resolution, preprocessing, and readiness CLIs.
- `tests/ai/fixtures/` — explicitly fictional software-test fixtures; never
  training data.

The three data directories ignore their contents by default. Do not override
that policy until data licensing, sensitivity, size, and reproducibility have
been reviewed.

## Approved-source ingestion workflow

1. Obtain the file through an approved LGU/PAGASA/government channel and retain
   its original form under `data/raw/` locally.
2. Add or update its entry in `manifests/source-manifest.json`. Record the
   official reference, retrieval date, coverage, time range, verified units and
   accumulation semantics, usage restrictions, version, and SHA-256 checksum.
3. Never place a PAGASA token, Supabase key, password, citizen record, or
   employee credential in a file or manifest.
4. Preserve the raw file. Convert observations to the canonical schema through
   a documented manual or future source-specific importer.
5. A human reviewer must confirm spatial and temporal evidence. Narrative or
   candidate documents remain `REQUIRES_HUMAN_REVIEW` and do not become labels
   automatically.
6. Run the validator before moving canonical records into `data/reviewed/`:

   ```powershell
   python scripts/ai/validate_flood_dataset.py `
     --input ml/flood-risk/data/reviewed `
     --manifest ml/flood-risk/manifests/source-manifest.json
   ```

7. Check the gate. An empty or insufficient real dataset correctly returns a
   non-zero status and `TRAINING_READY = false`:

   ```powershell
   python scripts/ai/check_flood_training_readiness.py
   ```

8. Only after the gate passes may reviewed records be preprocessed:

   ```powershell
   python scripts/ai/prepare_flood_training_data.py `
     --input ml/flood-risk/data/reviewed `
     --output ml/flood-risk/data/processed/flood-training-v1.csv
   ```

The scripts use only Python's standard library. They do not import TensorFlow.

Phase 3B1 source, event, precipitation, mapping, and negative-label governance
is documented in `PILOT-DATA-PROTOCOL.md`. Inspect the real-data pilot state
without generating a dataset:

```powershell
python scripts/ai/report_flood_pilot_quality.py
```

The current result is intentionally empty and `NOT_APPROVED`. Once governed
inputs exist, `build_flood_pilot_dataset.py` can emit a candidate CSV and
companion manifest. An empty build returns non-zero with explicit exclusions;
it never trains TensorFlow or changes `/ready`.

Run the isolated fixture suite with:

```powershell
python -m unittest discover -s tests/ai -p "test_*.py" -v
```

Fixture output is software-verification evidence only and must never be quoted
as real dataset counts or model performance.

## Evidence and negative labels

An official report mentioning flooding is only a candidate until an authorized
reviewer confirms its location, valid window, and meaning. A verified negative
requires explicit authoritative monitoring evidence that the location and time
were observed and no flooding occurred. “No report found” is never sufficient.

`label_confidence` is CIVENTRAL data-governance metadata, not model confidence:

- `HIGH` — explicit spatial and temporal observation in an authoritative log,
  or consistent corroboration by multiple authoritative sources.
- `MEDIUM` — one authoritative source explicitly supports the outcome and its
  space/time mapping, confirmed by human review.
- `LOW` — incomplete or indirect evidence. It cannot accompany a verified
  training label.

## Rainfall governance

All three rainfall features are millimetre accumulations. A source must
explicitly establish both the unit and accumulation window. A similarly named
PAGASA/API field is not sufficient evidence. Unknown units or semantics are
represented as missing/unverified and rejected from training.

Historical training inputs must be the forecast values available at the stated
issuance time. Later observed rainfall cannot be substituted for an archived
forecast. Antecedent totals must cover the periods immediately before
`forecast_issued_at` without using future information.

## MGB and barangay governance

DENR-MGB `LF`, `MF`, `HF`, and `VHF` are static feature values, never outcome
labels. `NONE` is valid only when a validated point is within Caloocan but
outside all imported MGB flood polygons. `resolve_mgb_susceptibility.py` reuses
the existing GeoJSON rather than copying it.

The canonical `barangay_psgc` uses the reconciled current 10-digit PSGC for the
187 unaffected barangays; legacy `PH...` source codes remain provenance only.
The validated local geometry presently covers 187 unaffected barangays. The
legacy Barangay 176 polygon is excluded, while Barangays 176-A through 176-F
lack authoritative local geometry. Historical references to any of these are
`UNKNOWN_SPATIAL_MAPPING` until an authoritative temporal crosswalk exists.

## Prohibited substitutions

Do not create synthetic operational incidents, non-incidents, rainfall,
forecasts, barangay mappings, labels, accuracy results, or model artifacts.
Test fixtures must remain under `tests/ai/fixtures/` and must never be counted
or copied into the real-data workspace.
