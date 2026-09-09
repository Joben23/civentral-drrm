# Phase 3B2 first real evidence and IMERG acquisition

This phase acquired source evidence and precipitation samples only. It did not
approve an event label, select a prediction cutoff or horizon, create a
training row, train TensorFlow, create a model artifact, or change `/ready`.

## DSWD DROMIC result

The official DROMIC event page supplied two DOCX reports. Both files are kept
unchanged and ignored under `data/raw/event-evidence/`; the source registry
records their official URLs, retrieval timestamps, media type, byte length,
SHA-256, issue-time source text, and repository-relative paths.

The machine-assisted worksheet in
`manifests/dromic-caloocan-2019-evidence-extraction.json` records only explicit
report facts. Report #1 names Barangays 177 and 178 and reports 473 families or
1,892 persons affected. The terminal report states that flooding occurred over
portions of Caloocan on 24 June 2019, names Barangays 175 through 178, and
reports 2,013 families or 8,052 persons affected. Both reports record 22
families or 88 persons sheltered at the Barangay 177 hall and returned home.

Neither document supplies a defensible exact onset or cessation time. The
issue timestamps are preserved as source text with timezone unset; they are
not event times. No event coordinate or street is stated, and legacy Barangay
176 cannot be silently mapped to current 176-A through 176-F geometry.

Therefore the event remains:

- `label_status=UNKNOWN`
- `review_status=REQUIRES_HUMAN_REVIEW`
- `training_eligible=false`
- no candidate or negative window

## IMERG product and access result

Official NASA metadata establishes `GPM_3IMERGHH`, collection `07`, current
V07B reprocessing, DOI `10.5067/GPM/IMERG/3B-HH/07`, Final Run research
product, 0.1-degree grid, 30-minute resolution, and the `precipitation`
variable in `mm/hr`. Raw timestamps are UTC. The archive begins in 1998 and
therefore covers June 2019. IMERG is a satellite precipitation estimate with
gauge analysis; it is not PAGASA ground truth.

An original HDF5 request returned HTTP 401 through the Earthdata login flow, so
the registry records `EARTHDATA_AUTHENTICATION_REQUIRED`, no authentication
material, and no original granule. No bypass or unofficial mirror was used.

The acquired real values are a bounded official NASA Earthdata/GES DISC
ImageServer representation. Source metadata, CMR collection/granule metadata,
the raster catalog, and four six-hour sample responses remain immutable and
ignored under `data/raw/precipitation/`. A full-window request that silently
returned only 20 intervals is checksummed and isolated under `quarantine/`; it
is never normalized.

Credential-safe original-granule follow-up requires the human operator to
authorize NASA GES DISC in an Earthdata session, keep the `_netrc` outside the
repository, and run this example for the first catalogued granule:

```powershell
curl.exe --fail --location `
  --netrc-file "$env:USERPROFILE\_netrc" `
  --output "ml/flood-risk/data/raw/precipitation/3B-HHR.MS.MRG.3IMERG.20190623-S160000-E162959.0960.V07B.HDF5" `
  "https://data.gesdisc.earthdata.nasa.gov/data/GPM_L3/GPM_3IMERGHH.07/2019/174/3B-HHR.MS.MRG.3IMERG.20190623-S160000-E162959.0960.V07B.HDF5"
```

The operator must verify the returned media type, HDF5 signature, byte length,
checksum, and Earthdata/GES DISC terms before any original-granule status
change. Authentication files must never be placed in the repository.

## Exploratory temporal and spatial envelope

The reports support only the date 24 June 2019. For acquisition testing, the
complete assumed Philippine civil date was explicitly converted from UTC+08:00
to the end-exclusive UTC interval `2019-06-23T16:00:00Z` through
`2019-06-24T16:00:00Z`. Because the reports do not state timezone or onset,
this assumption requires human review. The envelope is classified only as
`EXPLORATORY_SOURCE_ACQUISITION_WINDOW`; it is not a target, prediction,
training, or forecast-horizon decision.

The request uses 16 cell centers (longitudes 120.85, 120.95, 121.05, 121.15;
latitudes 14.55, 14.65, 14.75, 14.85) within bounds
`[120.8, 14.5, 121.2, 14.9]`. This covers the governed Caloocan bounding box
plus an immediate neighbor ring. Deterministic polygon/cell analysis finds
three cells intersecting the two-part city geometry. Representative geometry-
derived points for current Barangays 175, 177, and 178 fall in the cell centered
at 121.05E, 14.75N; legacy Barangay 176 remains unresolved.

Area weighting is technically possible because city polygons and cell
footprints exist, but it was not performed or selected. A reviewed geodesic
overlay implementation is still required. IMERG's 0.1-degree cells cover
multiple local areas and are not barangay-resolution ground truth.

## Validation and normalization result

Four bounded chunks supplied 768 real observations: 48 expected half-hour
intervals for each of 16 cells, with zero missing intervals, zero duplicate
intervals, and zero fill/invalid values. Interval starts range from
`2019-06-23T16:00:00Z` through `2019-06-24T15:30:00Z`; the final interval ends
at `2019-06-24T16:00:00Z`. Observed half-hour accumulations range from 0 to
7.7049999235 mm.

The provisional reviewed derivative preserves native `mm/hr` values and raw
checksums and records `precipitation_mm = native_rate_mm_per_hour * 0.5 hours`.
This formula is permitted only because the official variable units and
30-minute interval were verified. The ImageServer response does not expose the
IMERG precipitation quality index, so every record is `PROVISIONAL`, not
`VALIDATED`. No missing value is replaced with zero; observed zero rates remain
valid source values.

No exploratory antecedent summary is produced because there is no human-
reviewed event cutoff: `EVENT_TIME_REVIEW_REQUIRED`.

## Reproducible checks

```powershell
python scripts/ai/validate_flood_acquisition.py
python scripts/ai/report_flood_pilot_quality.py
python scripts/ai/check_flood_training_readiness.py
```

Expected safe state remains zero training records, zero positives, zero
negatives, one unknown event, `training_ready=false`, and
`training_authorization_status=NOT_APPROVED`.
