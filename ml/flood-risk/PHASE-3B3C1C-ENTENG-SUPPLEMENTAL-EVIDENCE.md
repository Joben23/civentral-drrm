# Phase 3B3-C1C: Enteng Supplemental Raw Evidence Reconciliation

## Scope and safety result

This phase validates and governs the manually acquired DSWD DROMIC Report #41 for the effects of Severe Tropical Storm “Enteng” and the Southwest Monsoon. The report is supplemental corroboration only. It does not replace the existing NDRRMC discovery provenance or alter the Phase 3B3-C1B candidate temporal envelope.

No IMERG data was acquired, no rainfall feature or training row was created, no flood/no-flood label was assigned, and TensorFlow readiness was not changed.

## Acquired official artifact

- Internal title: `DSWD DROMIC Report #41 on the Effects of Severe Tropical Storm “Enteng” and Southwest Monsoon as of 13 October 2024, 6AM`
- Source organization: DSWD Disaster Response Operations Monitoring and Information Center
- Media type: `application/pdf`
- Page count: `46`
- Byte length: `2171872`
- SHA-256: `00f588947b542457a19619df20940346cb105700ce6f194c12db0de822a46aa1`
- Raw path: `ml/flood-risk/data/raw/event-evidence/DSWD-DROMIC-Report-41-on-the-Effects-of-Severe-Tropical-Storm-Enteng-and-Southwest-Monsoon-as-of-13-October-2024-6AM.pdf`
- Governance: `ACQUIRED`, `REQUIRES_HUMAN_REVIEW`, usage/license `PENDING_REVIEW`

The raw file remains immutable and gitignored. Its `%PDF-1.7` signature, EOF marker, strict PDF parse, internal title, page count, byte length, and checksum were verified. The deterministic repository-visible retrieval timestamp is the raw file's last-write timestamp in UTC; it is acquisition metadata, not an event or report time.

## Explicit Caloocan facts

Annex A, page 15, lists `Caloocan City` with:

- 6 affected barangays
- 3,250 affected families
- 12,754 affected persons

These are later cumulative/revised report values. They do not establish conditions on 02 September 2024 and do not establish flood onset.

Page 3 records DSWD FO NCR augmentation of family food packs involving Caloocan on 03, 06, and 07 September 2024. These are response-action dates, not flood occurrence timestamps.

Additional cumulative Caloocan entries are retained as supplemental context: Annex B and Annex E show 8 cumulative evacuation centers, 303 cumulative displaced families, and 966 cumulative displaced persons, with current columns shown as dashes; Annex G lists DSWD assistance of PHP 2,268,255.00. None supplies an onset, cessation, prediction cutoff, or training label.

## Provenance and temporal boundary

The existing NDRRMC source remains the `DISCOVERY_SOURCE` and remains `PROPOSED`, `NOT_ACQUIRED`, and `EXTERNAL_ACCESS_REQUIRED`. Report #41 is recorded separately as an `ACQUISITION_SOURCE`, `OFFICIAL_DSWD_DROMIC`, and `SUPPLEMENTAL_CORROBORATION` artifact.

The existing candidate temporal envelope remains unchanged:

- start: `02 September 2024, 08:00 am (source timezone unstated)`
- end: `03 September 2024, 8:00am (upper bound: Subsided as of; source timezone unstated)`

Report #41 does not define either bound, resolve the NDRRMC timezone, merge the unresolved Crispulo cluster, or create a prediction cutoff. The Enteng event remains `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, `human_approved=false`, and `training_eligible=false`.

## Required adjudication

A human reviewer must decide whether the existing NDRRMC-indexed primary episode is acceptable for bounded precipitation acquisition and whether official raw NDRRMC material must first be manually acquired. Report #41 can corroborate Caloocan impact and response, but it cannot independently authorize temporal alignment or model training.
