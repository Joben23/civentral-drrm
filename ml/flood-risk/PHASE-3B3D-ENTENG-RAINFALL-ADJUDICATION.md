# Phase 3B3-D: Enteng Project/Research Rainfall Acquisition Adjudication

## Decision

`APPROVED_FOR_PROJECT_RESEARCH_ONLY` for `BOUNDED_HISTORICAL_RAINFALL_ACQUISITION`.

This is an evidence-collection authorization under the project research governance policy. It is not official LGU approval, operational authorization, label review, training authorization, prediction-cutoff approval, or model approval. No reviewer or DRRM officer identity is asserted.

## Accepted evidence basis

The temporal basis is official indexed NDRRMC Enteng SitRep content that explicitly records Caloocan flood-location occurrence points on 02 September 2024 and a later `Subsided as of 03 September 8:00am` value. The raw NDRRMC attachment remains `NOT_ACQUIRED` and `EXTERNAL_ACCESS_REQUIRED`; indexed content is accepted only for exploratory temporal alignment.

DSWD DROMIC Report #41 is accepted only as supplemental Caloocan impact corroboration. Its affected-population totals and response-action dates do not establish occurrence, onset, cessation, or the acquisition envelope.

## Primary episode

- Candidate start: `02 September 2024, 08:00 am` (documented occurrence point)
- Candidate end: `03 September 2024, 08:00 am` (`Subsided as of` upper bound)
- End interpretation: upper bound, not exact cessation
- Crispulo Street: excluded as a separate revision-conflicted cluster

The source timestamps remain preserved with their source timezone unstated.

## Project research time basis

- `SOURCE_TIMEZONE = UNSPECIFIED`
- `RESEARCH_ALIGNMENT_TIMEZONE = Asia/Manila`
- `RESEARCH_ALIGNMENT_UTC_OFFSET = +08:00`
- `TIMEZONE_BASIS = PROJECT_RESEARCH_ASSUMPTION`

Asia/Manila is not claimed as explicit NDRRMC metadata and does not correct or overwrite the source text. Formal source or LGU confirmation remains required before operational or training approval.

## Authorized acquisition envelope

The permitted window is classified `EXPLORATORY_SOURCE_ACQUISITION_WINDOW`:

- Local research start: `2024-08-31T08:00:00+08:00`
- Local research end: `2024-09-03T08:00:00+08:00`
- UTC start: `2024-08-31T00:00:00Z`
- UTC end: `2024-09-03T00:00:00Z`
- Antecedent coverage: 48 hours before the primary occurrence point
- Total bounded coverage: 72 hours
- Spatial bounds (WGS84): `[120.8, 14.5, 121.2, 14.9]`
- Spatial contract: the existing 16-cell, 0.1-degree Caloocan-plus-neighbors request from Phase 3B2
- Mapping method: not selected
- Global archive acquisition: not authorized

This window is not a prediction window, training window, validated ML feature definition, or prediction cutoff. The IMERG grid remains too coarse to represent barangay-resolution ground truth.

## Allowed and prohibited use

Allowed after this adjudication:

- retrieve only bounded historical rainfall for the stated envelope;
- checksum and validate the returned observations;
- assess spatial and temporal coverage;
- inspect exploratory rainfall patterns.

Still prohibited:

- training rows or flood/no-flood labels;
- a prediction cutoff or probability;
- TensorFlow training or calibration;
- model activation;
- operational use.

The Enteng event remains `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, `human_approved=false`, and `training_eligible=false`. Global training authorization remains `NOT_APPROVED`, training readiness remains false, and the model remains `MODEL_NOT_AVAILABLE`.
