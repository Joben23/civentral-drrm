# Module 2 Relief Goods Distribution Tracker

Phase 4A provides a deliberately small, server-authorized MVP for relief inventory and distribution tracking.

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

## Manual verification

1. Apply the migration in the governed Supabase migration workflow.
2. Grant the Module 2 resource actions `VIEW` and `CREATE` to a test DRRM role.
3. Open the Relief Goods Distribution Tracker from the sidebar with an empty database and verify the empty states.
4. Seed or create an active relief item, receive stock, refresh, and verify the balance and `STOCK_IN` movement.
5. Release a valid distribution to an existing barangay or evacuation center and verify the exact deduction, history row, and `DISTRIBUTION_RELEASE` movement.
6. Attempt a distribution larger than available stock and verify a rejected request with no balance or movement change.
7. Submit a request without or with an invalid CSRF header and verify HTTP 403.
8. Repeat the same `client_reference` and verify the existing distribution is returned without another deduction.

Run `php scripts/test-drrm-relief-goods-mvp.php`, PHP lint on changed PHP files, `git diff --check`, and the existing DRRM regression scripts before deployment.