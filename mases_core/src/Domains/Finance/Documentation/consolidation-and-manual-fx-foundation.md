# Finance consolidation and manual FX foundation

**Status:** implementation foundation only; not production acceptance.

## Consolidated trial balance

- Caller must provide at least two explicit legal-entity IDs. All IDs are resolved inside the authenticated tenant; client-supplied tenant IDs must never become authoritative.
- Each entity keeps its own chart of accounts, journals, periods, and balances. The report is a read-only composition; it never posts to or mutates entity books.
- Every account in every selected entity's trial-balance result must have an approved finance_consolidation_account_mappings record. Pending or rejected mappings are ignored and cause report generation to fail closed rather than silently omitting balances.
- Mapping proposals require a reason and are reviewed by a different actor. Account type must match the source ledger account. An already approved mapping cannot be overwritten through this foundation.
- Amounts are added using decimal-string arithmetic, not PHP floats.
- Output is explicitly **unadjusted**. Intercompany eliminations, foreign-currency translation, tax, and statutory adjustments are not applied. The output flags this and requires accounting review. It must not be presented as a statutory consolidated statement.

## Manual exchange rates

- Rate direction is quote-currency units for one base-currency unit.
- A proposal stores the source, optional source reference, effective date, exact decimal rate, proposer, and rationale. It is inert while pending.
- Only a different actor can approve or reject a pending proposal; a reason is mandatory.
- Resolution returns only the latest approved rate whose effective date is on or before the requested date. Pending and rejected rates are never returned.
- An approved rate for a currency pair/date is immutable through these services. Correcting an approved rate requires a separately governed correction workflow.
- This foundation does not convert journal amounts, enable foreign-currency journal posting, perform revaluation, or calculate FX gains/losses. The journal service's existing foreign-currency rejection remains in force.

## Deliberately excluded

Tax defaults, tax calculations, opening balances, opening-balance imports, and opening-balance migration are not changed. No rates, mappings, balances, tax values, or accounting policy defaults are seeded.

## Integration and acceptance still required

These services are registered with the Finance service provider but no public API routes/controller or authorization policy is added here. Before exposing them, add route-level authentication, tenant middleware, policy authorization, request validation, and audit lifecycle events for proposals and reviews. Add automated regression tests for maker-checker enforcement, tenant isolation, missing mapping fail-closed behavior, exact aggregation, approved-rate resolution, and immutable approved configuration.

No test suite, migration, seeder, dependency installation, CI workflow, deployment, or production database operation was run as part of this change.
