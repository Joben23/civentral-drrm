# Module 2 Relief Goods Distribution Tracker

Phase 4A provides a deliberately small, server-authorized MVP for relief inventory and distribution tracking. Phase 4B adds household-level beneficiary and assistance monitoring without changing Phase 4A stock semantics.

## Architecture

The page at `pages/drrm/relief-goods-distribution.php` loads data through `api/drrm/relief-goods.php`. The API requires an authenticated CIVENTRAL session and the trusted Module 2 permission resource. Supabase credentials are read only by the PHP service boundary; browser JavaScript receives no database key.

## Database design

The additive migration `20260911000100_module2_relief_goods_foundation.sql` creates `relief_items`, `relief_distributions`, `relief_distribution_items`, and append-only `relief_stock_movements`. Current stock is stored on the item for efficient summaries and every change is recorded as a movement. Existing `barangays` and `evacuation_centers` are referenced rather than duplicated.

## Inventory workflow

Authorized users receive an existing active item through `receive_relief_stock`. The RPC locks the item, rejects non-positive quantities, increases stock, and records `STOCK_IN` with actor and source details.

## Distribution workflow and stock rule

The release form supports one or more items and validates the selected destination against the existing geographic master data. `release_relief_distribution` locks every item, rejects missing items or insufficient stock, inserts the distribution and line items, deducts stock, and records `DISTRIBUTION_RELEASE` movements in one database transaction. A unique client reference makes a retry return the existing release without deducting twice. Stock is deducted when the distribution is released; this phase does not expose a separate prepared/received action.

## Authorization, CSRF, and audit behavior

View and create actions are read from the trusted session permission map; superadmins retain access. State-changing requests require the session-bound `X-CSRF-Token`. Movement history and distribution rows are the Module 2 audit trail. The existing audit UI remains a separate MySQL-owned system, so this phase does not create a second cross-database audit writer.

## Limitations

Item creation is available to CREATE-authorized users; editing, deactivation, and item merge workflows are outside this phase. Distribution history is capped at the latest 100 rows and has no custom pagination. Existing destination records must already exist. Cancellation, amendments, attachments, and warehouse-lot tracking are outside Phase 4A.

## Phase 4B beneficiary monitoring

Phase 4B adds `relief_beneficiaries`, `relief_distribution_beneficiaries`, and `relief_distribution_beneficiary_items` in migration `20260911000200_module2_beneficiary_assistance.sql`. A beneficiary is a minimal household record: household head, existing barangay reference, household size, and optional contact/location/registration notes. No national ID, medical, financial, or unrelated sensitive fields are collected.

`SERVED` is derived from an assistance link containing at least one positive item allocation to an existing `RELEASED` distribution. `NOT YET SERVED` means the household has no completed item allocation. Each child allocation references an existing Phase 4A `relief_distribution_items` row and stores `quantity_received`. The atomic RPC locks the distribution item and rejects cumulative beneficiary allocations above the released quantity. The unique beneficiary/distribution constraint prevents duplicate recording. Recording assistance inserts only the header and allocation rows; it does not call the Phase 4A stock-receiving or distribution-release RPC and therefore cannot deduct central inventory a second time.

The same Module 2 authorization resource and actions apply: `VIEW` reads beneficiary registry, summaries, and history; `CREATE` registers households and records assistance. Mutations use the existing session CSRF token and authoritative `load()` refresh pattern. The Phase 4B UI does not claim Citizen Mobile App support.

## Manual verification

1. Apply `20260911000100_module2_relief_goods_foundation.sql`, then `20260911000200_module2_beneficiary_assistance.sql`, in the governed Supabase Dashboard SQL Editor workflow.
2. Grant the Module 2 resource actions `VIEW` and `CREATE` to a test DRRM role.
3. Open the Relief Goods Distribution Tracker from the sidebar with an empty database and verify the empty states.
4. Seed or create an active relief item, receive stock, refresh, and verify the balance and `STOCK_IN` movement.
5. Release a valid distribution to an existing barangay or evacuation center and verify the exact deduction, history row, and `DISTRIBUTION_RELEASE` movement.
6. Attempt a distribution larger than available stock and verify a rejected request with no balance or movement change.
7. Submit a request without or with an invalid CSRF header and verify HTTP 403.
8. Repeat the same `client_reference` and verify the existing distribution is returned without another deduction.
9. Register a household against an existing barangay and confirm it appears as `NOT YET SERVED`.
10. Record assistance using an existing `RELEASED` distribution, select item-level quantities, and confirm the household becomes `SERVED`, summaries update, actual quantities appear in history, and no stock changes.
11. Attempt an allocation above the remaining released quantity and confirm `Allocated quantity exceeds the remaining released quantity.`.
12. Repeat the same beneficiary/distribution assistance and confirm `Beneficiary already recorded for this distribution.`.

Run `php scripts/test-drrm-relief-goods-mvp.php`, PHP lint on changed PHP files, `git diff --check`, and the existing DRRM regression scripts before deployment.