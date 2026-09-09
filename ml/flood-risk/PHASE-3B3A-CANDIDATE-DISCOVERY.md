# Phase 3B3-A governed Caloocan flood-candidate discovery

Status: discovery and governance only. No source document was downloaded in
this phase, no precipitation was acquired, no event was labelled, no training
window was created, and the TensorFlow target remains pending evidence review.

## Official sources searched

- DSWD DROMIC incident pages and official report documents, prioritizing
  Caloocan-specific and Metro Manila flood reports.
- NDRRMC/OCD official situation-report PDFs and report families for historical
  tropical cyclones and southwest-monsoon episodes.
- The Caloocan City government site for a stable archival incident publication.
  No city archive item with enough durable event metadata was promoted.
- Other government search results were used only to identify official document
  locators. No media, blog, social post, or copied report is primary evidence.

## Governed result

The existing 24 June 2019 DSWD candidate remains unchanged in label state. Six
additional independent candidates are registered. `candidate-events.json` is
the discovery index; `flood-event-registry.json` remains the event registry;
`source-manifest.json` remains the provenance registry.

| Candidate episode | Official evidence at discovery | Time quality | Location quality | Access | Priority |
|---|---|---|---|---|---|
| 27-30 Sep 2011, Typhoon Pedring | NDRRMC SitRep 12 explicitly includes Caloocan in Metro-wide flooding | Date range only | City within multi-city entry; no barangay | Available | MEDIUM_PRIORITY |
| 7-8 Aug 2012, Gener/Haikui + southwest monsoon | NDRRMC SitReps 2/3 list three Caloocan roads not passable due to flooding | Observation/report dates; no onset | Street/locality; no barangay inferred | Available | HIGH_PRIORITY |
| 12 Nov 2020, Typhoon Ulysses | NDRRMC SitReps list flooding in Barangays 117, 118, 119, 120, 160, and 175 | Calendar date; no onset/cessation time | Multi-barangay identifiers pending PSGC review | Available | HIGH_PRIORITY |
| 23 Aug 2022 context, STS Florita | NDRRMC SitReps list gutter-deep flooding at five Caloocan road locations | Episode-date context; exact time unresolved | Street/locality; no barangay inferred | Available | HIGH_PRIORITY |
| 28 Aug 2024, southwest monsoon | DSWD DROMIC explicitly states Caloocan experienced flooding | Calendar date; no onset/cessation time | City; one affected barangay is unnamed | Available | MEDIUM_PRIORITY |
| 2-5 Sep 2024, Enteng + southwest monsoon | NDRRMC SitReps list eleven Caloocan flooded locations and occurrence times | Occurrence times present; timezone and continuation need review | Multi-location; some numbered barangays | Available | HIGH_PRIORITY |

Priority means evidence-acquisition value, not flood severity and not label
confidence. Every row remains `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`, and
`training_eligible=false`.

## Time and location governance

Event dates and report issue times are stored separately. Report issue time is
never treated as onset. Date-level evidence stays date-level; the event registry
therefore keeps new `event_start` and `event_end` values null. Discovered
occurrence times are not converted to UTC until the document states a timezone
or a human reviewer records a governed assumption.

Official Caloocan street/locality wording is retained without reverse-geocoding
it to a barangay. Numbered barangays are retained as source text and do not gain
a PSGC mapping during discovery. Legacy Barangay 176 remains exactly 176 in the
2019 record and is never expanded to 176-A through 176-F.

## Duplicate and report-family decisions

- DSWD initial, progress, and terminal reports for 24 June 2019 are one event.
- Haikui and Gener references in the August 2012 NDRRMC family are one episode.
- Ulysses SitRep revisions are one 12 November 2020 episode.
- Florita SitRep revisions are one August 2022 episode.
- Enteng SitRep revisions are one September 2024 episode.
- The 28 August 2024 southwest-monsoon report and 2 September Enteng event are
  separate official report families with distinct dates.
- The deterministic validator flags shared cluster IDs, shared document URLs,
  or matching date/hazard/agency/location/title signals. It never silently
  merges ambiguous records.

The prior `candidate-ndrrmc-2023-07-31-caloocan` is rejected. Document hierarchy
places "Caloocan Norte" and "Caloocan Sur" under Binmaley, Pangasinan, not
Caloocan City. This place-name collision is retained in `screened_out` and its
source lifecycle is `REJECTED`.

## Ranking method

The acquisition-priority score is the sum of ten versioned boolean criteria:
official government source, Caloocan explicitly named, flooding explicitly
stated, event date supported, event timing available, barangay/locality detail,
multiple official reports, accessible official documents, independence from
the June 2019 event, and known IMERG historical availability. Scores of 8-10
are `HIGH_PRIORITY`, 5-7 are `MEDIUM_PRIORITY`, and 0-4 are `LOW_PRIORITY`.
The score is deterministic and cannot alter a label or training eligibility.

## Screened but not promoted

- The July 2023 Egay/Falcon record is a wrong-location match and is rejected as
  documented above.
- The July 2024 Carina DROMIC report family names Caloocan in affected-person,
  displacement, or assistance tables, but the inspected official excerpts did
  not explicitly establish a Caloocan flood occurrence. It is therefore not
  promoted merely because the broader report concerns a flooding emergency.
- Results naming a Caloocan barangay/locality outside Caloocan City were not
  promoted. News and social results were not accepted as primary evidence.

## Affirmative no-flood evidence research

No negative candidate was created. Three potential access paths are recorded
only for later governance review:

1. A formal request to the Caloocan CDRRMO Alert and Monitoring Center for a
   location- and time-bounded operational log that explicitly states no
   flooding was observed.
2. OCD NCR/NDRRMC situation or monitoring logs that explicitly cover Caloocan
   and affirm a no-flood condition for a defined observation window.
3. MMDA Flood Control Information Center monitoring logs with explicit
   location, coverage interval, observation method, and no-flood statement.

All three remain `NOT_ACQUIRED`. Silence, a missing report, or the absence of a
citizen incident can never satisfy `NO_FLOOD_CONFIRMED`.

## Phase 3B3-B acquisition set

Acquire the official report families in this order, using the existing raw
evidence/checksum workflow:

1. September 2024 Enteng and southwest-monsoon NDRRMC SitReps 2 and 3.
2. November 2020 Ulysses NDRRMC SitReps 4 and 23.
3. August 2012 southwest-monsoon/Gener/Haikui NDRRMC SitReps 2 and 3.
4. August 2022 Florita NDRRMC SitReps 4 and 7.

The August 2024 DROMIC report and September 2011 Pedring SitRep are the second
acquisition tier because their currently discovered time/location detail is
coarser. Acquisition must preserve raw files, locators, report-family identity,
byte lengths, media types, retrieval timestamps, and SHA-256 values. It must
not confirm labels; a human reviewer must separately decide whether each
document supports an event window and location suitable for a label.

## Safety and readiness

No new precipitation source was downloaded. Each candidate only records that
its date is within the documented IMERG historical period. No target horizon,
prediction cutoff, label, negative example, rainfall feature, or training row
was created. Training authorization remains `NOT_APPROVED`, the final ML target
remains `PENDING_EVIDENCE_REVIEW`, and the AI service must continue reporting
`MODEL_NOT_AVAILABLE` until a separately governed active model exists.
