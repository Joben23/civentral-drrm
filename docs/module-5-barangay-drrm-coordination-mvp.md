# Module 5 Barangay DRRM Coordination Tool

## Documented purpose

The Barangay DRRM Coordination Tool supports the documented Module 5 scope: sharing disaster-related information, coordinating response activities, and monitoring coordination between barangay officials and LGU personnel. Phase 5A remains intentionally narrow and coordination-focused.

## Phase 5A scope

This implementation adds a foundation for:

- Barangay Situation / Status Reports
- Barangay Assistance Requests
- Coordination summary/dashboard cards
- Coordination history and current situation view

The implementation does not add acknowledgment, assignment, response team workflows, scheduling, outbound cross-subsystem APIs, or dispatch automation.

## Difference from Module 3

Module 3 answers "What emergency incident happened?" through incident reporting and response logs. Module 5 answers "What is the barangay's current situation and what assistance or coordination does the barangay need?" without duplicating Module 3 incident creation. Optional future incident references remain integration-ready and nullable, but no incident row is created automatically.

## Difference from Module 2

Module 2 is about stock movement, relief inventory, distribution records, and beneficiary assistance. Module 5 records a barangay need request in the requested category, and the request is kept distinct from any future Module 2 distribution link. No stock movement, reservation, or deduction occurs in Module 5.

## Data model

The conceptual additive schema is represented through the intended tables:

- drrm_barangay_status_reports
  - id UUID primary key
  - barangay_id FK to the authoritative barangay catalog
  - situation_level
  - affected_households
  - evacuees
  - access_condition
  - situation_summary
  - notes
  - reported_at
  - reported_by_reference
  - created_at

- drrm_barangay_assistance_requests
  - id UUID primary key
  - barangay_id FK to the authoritative barangay catalog
  - request_category
  - priority
  - description
  - status
  - requested_at
  - requested_by_reference
  - created_at
  - updated_at

The implementation keeps the tables additive and does not modify existing Module 1-4 tables. The migration file is created but not applied in the current repository run.

## Permissions

The authorization service follows the existing server-side permission map and resource/action model. The resource name used is "barangay drrm coordination tool" and the actions follow the repository convention: VIEW and CREATE.

## Future Phase 5B workflow

Phase 5B may add acknowledgment, assignment, response action planning, and workflow transitions such as PENDING → ACKNOWLEDGED → IN_PROGRESS → COMPLETED, with cancellation later. Current Phase 5A deliberately stops at creating and viewing the request and report records.

## Planned GSMS integration hooks

The scope is intentionally integration-ready but not connected live. Planned hooks include `RELIEF_GOODS` mapping to Module 2 relief workflows, `MEDICAL` and `ROAD_ACCESS` mapping to cross-GSMS services, and `EVACUATION` or `INFORMATION` requests as future operational coordination points. No fake APIs, external URLs, or subsystem dependencies are added.

## Limitations

- No live cross-subsystem API calls
- No automatic incident creation
- No stock deduction or reservation
- No dispatch optimization or chat/social features
- No new write path beyond the requested status-report and assistance-request records
