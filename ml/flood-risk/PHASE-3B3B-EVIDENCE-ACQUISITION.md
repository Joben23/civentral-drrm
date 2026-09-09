# Phase 3B3-B governed event-evidence acquisition

Status: partial official evidence acquired; human review and external NDRRMC
access remain required. This phase creates no label, prediction cutoff,
training window, precipitation acquisition, training dataset, or model.

## Interruption recovery

Copilot had partially updated the candidate/source manifests and created an
unsafe one-shot updater. The useful NDRRMC access finding was retained: the
four selected attachment families remain `NOT_ACQUIRED` and
`EXTERNAL_ACCESS_REQUIRED`. The updater was removed because it used a
machine-specific path, generated a new timestamp on each run, appended
duplicate sources, omitted Enteng Reports #2/#3, wrote schema-less ignored
worksheets, and asserted unsupported Caloocan evidence.

## Validated DROMIC artifacts

The timestamps below are local file-placement timestamps in UTC, not claimed
remote-server download times. Acquisition is not source approval.

| Event family | Original filename | Bytes | SHA-256 | Local placement UTC |
|---|---|---:|---|---|
| Florita 2022 | `DSWD-DROMIC-Report-1-on-the-Effects-of-Southwest-Monsoon-enhanced-by-STS-Florita-as-of-25-August-2022-6PM.pdf` | 731642 | `ad2327cc0abce016f70ea4b035c4e51b4f66879d460feb178a0d17a4fa51f656` | 2026-09-09T14:27:34.196Z |
| Enteng 2024 Report #1 | `DSWD-DROMIC-Report-1-on-the-Effects-of-TD-Enteng-and-Southwest-Monsoon-as-of-01-September-2024-6PM.pdf` | 766987 | `0e2fd48f896c171bac85d3dd04b8a2b4242935bf76b887d42cfcbb8a51d5e79e` | 2026-09-09T14:27:34.229Z |
| Enteng 2024 Report #2 | `DSWD-DROMIC-Report-2-on-the-Effects-of-Tropical-Storm-Enteng-and-Southwest-Monsoon-as-of-02-September-2024-6AM.pdf` | 1852064 | `6c8ad00ac3b83d1e976630fa5ce206a13c596740f4a5d22893c6673e9b91d602` | 2026-09-09T14:27:34.252Z |
| Enteng 2024 Report #3 | `DSWD-DROMIC-Report-3-on-the-Effects-of-Tropical-Storm-Enteng-and-Southwest-Monsoon-as-of-02-September-2024-6PM.pdf` | 1898838 | `6238df05985fbea8d82514dbbb784d5e003cc3fc2651a51f5c13198d3305629b` | 2026-09-09T14:27:34.421Z |
| Ulysses terminal report | `index.pdf` | 3311516 | `ff7663f6e638f5b1413ae25139aa1a81ca2dea6b246e8408e2a8cd2ca0e0b43b` | 2026-09-09T14:27:34.475Z |

Every file begins with a PDF signature, has an EOF marker, parses without
encryption, and is not an HTML/error response. The generic `index.pdf` name is
preserved separately from its internal title: **DSWD DROMIC Terminal Report on
Typhoon ULYSSES, 13 November 2021, 6PM**.

## Evidence results

### Enteng 2024

Reports #1-#3 identify the Enteng/southwest-monsoon report family and their
issue/as-of times. A full-document text review found no Caloocan reference.
Metro Manila rainfall forecasts and general statements that flooding may be
likely/expected do not prove an observed Caloocan flood. The acquired reports
do not support affected Caloocan barangays, occurrence times, depth,
passability, affected population, or displacement.

### Florita 2022

The report identifies the Florita/southwest-monsoon family and the issue/as-of
time. It contains no Caloocan reference. Its affected-population table covers
Region VI/Iloilo (334 families, 1,379 persons in three barangays). General Metro
Manila weather and possible-flash-flood language is not Caloocan event evidence.

### Ulysses 2020 family

The terminal report explicitly lists Caloocan City with 23 affected barangays,
243 affected families, and 926 affected persons. Its evacuation table lists 24
cumulative evacuation centers, 243 cumulative families, and 926 cumulative
persons; current-value cells display dashes and are not converted to zero. The
report does not name the Caloocan barangays or state Caloocan flood onset,
cessation, depth, or road passability. Its 13 November 2021 report time is not
the November 2020 event onset. The footer says 43 pages while the local parser
reads 41 PDF page objects, so completeness needs human review.

## Provenance separation

Each event registry entry retains the original NDRRMC `DISCOVERY_SOURCE` and
adds the real DROMIC `ACQUISITION_SOURCE`. NDRRMC sources remain `PROPOSED`,
`NOT_ACQUIRED`, without local files or checksums. DROMIC sources are `ACQUIRED`
with technically validated artifacts, while source review and usage remain
pending. No source is `VALIDATED` or `APPROVED_FOR_PILOT`.

## Human review and Phase 3B3-C

The three worksheets remain `UNKNOWN`, `REQUIRES_HUMAN_REVIEW`,
`training_eligible=false`, and contain no candidate windows. Reviewers must
decide whether the DROMIC material is contextual only and must obtain the
official NDRRMC attachments through an authorized external/manual path before
relying on the discovered Caloocan flood details.

No Phase 3B3-C precipitation acquisition is currently authorized. Ulysses is
the first conditional candidate because it has explicit Caloocan affected-data
corroboration, but a bounded rainfall request should proceed only after a human
reviewer obtains/accepts official event-time evidence and records a defensible
exploratory acquisition envelope. Enteng and Florita should not proceed from
the acquired DROMIC reports alone.
