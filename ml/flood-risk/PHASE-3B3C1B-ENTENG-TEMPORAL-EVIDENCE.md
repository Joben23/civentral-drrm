# Phase 3B3-C1B: Enteng Temporal Evidence Strengthening

## Scope and safety result

This phase reviewed official NDRRMC report-family/index metadata and indexed official PDF content for the September 2024 effects of TC Enteng and the Southwest Monsoon. It did not acquire IMERG, create rainfall features, create a prediction cutoff or training row, assign a flood/no-flood label, or alter TensorFlow readiness.

Temporal quality: `DEFENSIBLE_EVENT_TIME_ENVELOPE_FOUND`

This classification applies only to a candidate event envelope for human review. It is not a confirmed label, a prediction window, or an authorization to train. The source timezone is unstated, the NDRRMC raw PDFs remain unacquired, and the reported subsidence value is an upper bound rather than an exact cessation instant.

## Official sources inspected

Official report-family page:

`https://ndrrmc.gov.ph/8-ndrrmc-update/4261-situational-report-for-the-effects-of-tc-enteng-2024-and-southwest-monsoon.html`

The official index lists SitRep No. 16 as of 09 September 2024, 8:00 PM. Direct candidate attachment locators returned HTTP 403. No guessed locator, cached file, or unofficial mirror was treated as a raw artifact.

The focused chronology review used official indexed PDF content from SitRep Nos. 2, 3, 8, 11, and 21. Direct SitRep No. 11 download also returned HTTP 403, so all NDRRMC rows remain `INDEXED_OFFICIAL_CONTENT_ONLY`, and the governed source remains `PROPOSED`, `NOT_ACQUIRED`, and `EXTERNAL_ACCESS_REQUIRED`.

## Caloocan occurrence records

The indexed Caloocan table supports a primary 02-03 September cluster:

| Source locality text | Barangay text | Occurrence | Depth/source description | Later status text |
|---|---:|---|---|---|
| 172, North Olympus | 172 | 02 September 2024, 08:00 am | 4-5 ft | Subsided as of 03 September 8:00am |
| 172, Dona Aurora | 172 | 02 September 2024, 08:00 am | 4-5 ft | Subsided as of 03 September 8:00am |
| 185, Everlasting | 185 | 02 September 2024, 08:00 pm | 2-3 ft | Subsided as of 03 September 8:00am |
| 185, Quirino highway Malaria | 185 | 02 September 2024, 08:00 am | 2-3 ft | Subsided as of 03 September 8:00am |
| 174, 1456 Camarin rd | 174 | 02 September 2024, 08:00 am | Gutter Deep level | Subsided as of 03 September 8:00am |
| 177, Duria st. | 177 | 02 September 2024, 08:00 am | 2-3 ft | Subsided as of 03 September 8:00am |
| Corner Acacia and Santol st. | unknown | 02 September 2024, 08:00 am | Gutter Deep level | Subsided as of 03 September 8:00am |
| Sapang alat bridge | unknown | 02 September 2024, 08:00 am | 4-6 ft | Subsided as of 03 September 8:00am |
| MLQ High School | unknown | 02 September 2024, 08:00 am | 5-6 ft | Subsided as of 03 September 8:00am |
| Sampaguita st. | unknown | 02 September 2024, 08:00 am | 5-6 ft. | Subsided as of 03 September 8:00am |

No barangay is inferred for a locality whose row does not explicitly contain one.

## Episode clustering and revision conflict

The recommended primary candidate envelope is:

- candidate start: `02 September 2024, 08:00 am` (source timezone unstated)
- candidate end: `03 September 2024, 8:00am` (`Subsided as of` upper bound; source timezone unstated)

Crispulo st. is not included in that ten-location envelope. SitRep Nos. 2-3 list it at 02 September 2024, 08:00 am with 4-5 ft. Later revisions list it at 05 September 2024, 07:00 am with 2-3 feet and `Flooded as of 05 September 7:00am`; the status later becomes `Subsided` without a distinct subsidence time. A human reviewer must decide whether this is a correction, continuation, or separate recurrence.

## Time and ML boundaries

Report issue times are not occurrence times. `Subsided as of` is not onset and is only an episode upper bound. No timezone conversion is made; `TIMEZONE_REQUIRES_HUMAN_REVIEW` remains explicit.

The worksheet remains `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, `human_approved=false`, and `training_eligible=false`. `prediction_cutoff` is null, candidate training windows are empty, and Enteng-specific IMERG has not been acquired.

## Human-review questions

1. Does the reviewer accept the ten-location 02-03 September cluster as a bounded candidate episode?
2. Can the source-context timezone be formally governed before UTC conversion?
3. Should Crispulo's 05 September revision remain excluded and be investigated as a separate episode?
4. Must SitRep No. 11 or 16 be manually acquired and checksummed before authorizing a tightly bounded IMERG retrieval?

Only after those decisions should Phase 3B3-C2 consider bounded IMERG acquisition for the primary episode. Acquisition would still not confirm a label or authorize model training.
