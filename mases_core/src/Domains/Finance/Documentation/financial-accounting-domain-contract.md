# Financial & Accounting Domain — General Ledger Contract

**Status:** Initial implementation contract for Owner review; not production acceptance.
**Target:** ARNA API, tenant-isolated, Amharic-first, PHP 8.3 / Laravel 12.

## Decisions carried forward

- ETB is the default/base currency. Amounts are stored as fixed-precision decimal strings; PHP floating-point arithmetic is prohibited for money.
- Multiple legal entities are supported. Each entity has its own chart of accounts, periods, journals, and ledger balances.
- Consolidated reporting is a read model across explicitly selected entities; it must not merge or mutate entity books.
- No opening balances are created or inferred. Opening balances require verified source records and a separately approved migration.
- Loan and Wallet remain operational subledgers. They may later emit idempotent source events to the GL; neither is itself the general ledger.
- Tenant context is resolved by the existing tenancy middleware. Client-supplied tenant IDs are never authoritative.
- Authentication uses the canonical `MaaleArna\MASES\Core\Auth\Models\User` model only.
- Posted journal entries are immutable. Corrections are made by a separately authorized reversing entry and, when needed, a replacement entry.
- Posting is allowed only in an open accounting period; journals must balance exactly at the database's decimal precision.
- Source-event idempotency must be enforced by a unique key scoped to tenant and legal entity.

## Initial data model

- `legal_entities`: tenant-owned accounting entity and base currency.
- `ledger_accounts`: entity-specific chart of accounts, parent/child hierarchy, account type, normal balance, and posting eligibility.
- `accounting_periods`: entity-specific date windows and open/closed lifecycle.
- `journal_entries`: draft/posted/reversed lifecycle, effective date, source identity, idempotency key, actor attribution, and reversal linkage.
- `journal_lines`: immutable after posting; each line has exactly one positive debit or credit and references an account in the same entity.

## Required acceptance before production

1. Clean disposable MySQL migration and seed succeeds on the exact candidate SHA.
2. Tenant and legal-entity isolation is proven for reads, writes, account references, and source-event idempotency.
3. Unbalanced journals, zero-sided lines, cross-entity account lines, closed-period posting, and duplicate source keys are rejected.
4. Posted journal headers and lines cannot be edited or deleted; reversal preserves the original and creates an equal-and-opposite entry.
5. Concurrent posting of the same idempotency key yields one persisted journal only.
6. Trial balance and general ledger totals reconcile to posted journal lines; consolidated reports preserve entity boundaries and eliminate intercompany entries only under an approved policy.
7. ETB rounding, FX rate sourcing, FX gains/losses, statutory reporting, Ethiopian tax treatment, and fiscal calendar rules receive separate accounting-professional review before claiming compliance.
8. Tests and CI pass against the exact candidate SHA. No production database operation or deployment is included in this contract.

## Deliberately deferred

- Opening balance imports, FX revaluation, tax calculation/filing, intercompany eliminations, bank feeds, budget commitments, payroll postings, procurement/sales posting adapters, and automated Loan/Wallet posting.
- App UI routes are deferred until the API contract and permissions pass acceptance.
