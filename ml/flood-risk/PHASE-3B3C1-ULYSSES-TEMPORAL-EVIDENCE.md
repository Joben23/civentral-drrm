# Phase 3B3-C1: Ulysses Temporal Evidence Strengthening

## Scope and safety result

This phase searched for official Philippine government evidence that could bound the Caloocan City flood-event period associated with Typhoon Ulysses in November 2020. It did not acquire IMERG, create a prediction cutoff or training row, assign a flood/no-flood label, or alter TensorFlow readiness.

Temporal quality: `DATE_ONLY_EVIDENCE`

Final pipeline consequence: the Ulysses candidate remains excluded from rainfall alignment. Exact Caloocan onset and cessation are unresolved, so Phase 3B3-C2 candidate-specific IMERG acquisition is not justified.

## Official sources searched

### DSWD DROMIC

Official incident page:

`https://dromic.dswd.gov.ph/typhoon-ulysses-08-nov-2020/`

Two early official revisions were acquired from direct DROMIC attachment URLs:

- Report #3, as of 12 November 2020, 6AM
- Report #4, as of 12 November 2020, 6PM

Both files are immutable, gitignored raw DOCX artifacts. Validation covered the ZIP signature, required WordprocessingML members, byte length, SHA-256, internal title, and a complete document/table text search. LibreOffice is not installed in this environment, so no rendered-page visual review was claimed.

Report #3 contains no Caloocan reference. Its DOST-PAGASA bulletin and general Metro Manila forecast are weather context, not Caloocan occurrence timing.

Report #4 explicitly lists Caloocan City in its affected-population and evacuation-center tables:

- 15 affected barangays
- 94 affected families
- 436 affected persons
- 15 current evacuation centers
- 94 current families and 436 current persons in those centers

These values are evidence available in the 6PM report snapshot. They do not establish when flooding began or ceased. The report/as-of timezone is not explicit in the document text and remains `TIMEZONE_REQUIRES_HUMAN_REVIEW`.

### NDRRMC/OCD

The official NDRRMC Ulysses report family was searched, including early SitRep #2 and SitRep #4 attachment locators. Official indexed incident-table content lists flooding in Caloocan Barangays 120, 117, 118, 119, 160, and 175 under the date `12 November 2020`, with no clock time.

Direct official attachment requests returned HTTP 403. No unofficial mirror or substitute artifact was used. The NDRRMC source therefore remains `PROPOSED`, `NOT_ACQUIRED`, and `EXTERNAL_ACCESS_REQUIRED`.

### Caloocan City / other Philippine government sources

Searches of currently accessible Caloocan City/CDRRMO and other Philippine government web material did not locate a Caloocan-specific Ulysses operations, evacuation, rescue, road-flooding, or monitoring timestamp suitable for a bounded event envelope. National or Metro Manila weather times were not treated as Caloocan event times.

## Report-time versus event-time finding

The following values remain separate:

- Report #3 as-of time: `12 November 2020, 6AM`
- DOST-PAGASA bulletin embedded in Report #3: `Issued at 11:00 pm, 11 November 2020`
- Report #4 as-of time: `12 November 2020, 6PM`
- NDRRMC Caloocan flooding date in discovery metadata: `12 November 2020` (no clock time)
- Raw-document retrieval times: recorded in the source manifest in UTC

None of the report, weather-bulletin, or retrieval times is an event-onset timestamp. The change from no Caloocan row in Report #3 to a Caloocan row in Report #4 is only a reporting interval; it cannot be treated as an occurrence interval without a source stating that interpretation.

## Revision differences

Report #4 records 15 affected barangays, 94 families, and 436 persons. The later terminal report records 23 affected barangays, 243 families, and 926 persons. These changing snapshots may reflect cumulative reporting and do not reveal the start, peak, or cessation of Caloocan flooding.

## Human-review questions

1. Can an authorized reviewer manually obtain an early official NDRRMC Ulysses SitRep and verify whether the underlying Caloocan incident table contains a clock time omitted from indexed text?
2. Can Caloocan CDRRMO provide an official incident, dispatch, evacuation, rescue, road-flooding, or operations log with an explicit timestamp and timezone context?
3. If no such evidence exists, should Ulysses remain date-only and permanently excluded from sub-daily rainfall alignment?

The worksheet remains `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, `human_approved=false`, `training_eligible=false`, with no candidate windows and no prediction cutoff.

## Recommended next candidate

Investigate the September 2024 Enteng/Southwest Monsoon candidate next, specifically by manually acquiring the official NDRRMC artifact already identified in discovery. Its discovered Caloocan entries contain occurrence times, although timezone and episode-clustering questions still require human review. DROMIC Reports #1-3 alone contain no Caloocan evidence and cannot support that candidate.
