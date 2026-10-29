# ARNA Finance & Accounting — Implementation and Release Register

**Implementation status:** generated on a review branch; not verified as production-ready.  
**Chart of accounts:** v1.0.0; 79 canonical accounts.  
**Owner direction:** proceed with code generation; defer external Ethiopian tax/accounting review for now.

## Implemented source areas

The current implementation branch contains Finance domain source for legal entities, ledger accounts, accounting periods, journal lifecycle, reversals, source-to-legal-entity mappings, subledger posting contracts, trial balance, income statement, balance sheet and explicit cash-movement reporting, HTTP requests/controllers/resources, authorization policies, service-provider bindings, audit lifecycle events, English/Amharic validation resources, migrations, and feature/unit test files.

The canonical chart contains 79 accounts and metadata for hierarchy, labels, type, normal balance, posting eligibility, control-account designation, domain mapping, and default tax-routing category. The opt-in seeder is intentionally not registered in `DatabaseSeeder`.

This is an inventory of source artifacts, not a claim that runtime behavior has been verified.

## Tax defaults — deferred, not approved as legal advice

The Owner has deferred independent tax/accounting review for the current code-generation phase. Continue treating account-level tax categories as routing defaults only. They are not tax rates and are not a final Ethiopian legal classification.

Transaction-level tax treatment must be determined from the applicable rules, supply facts, counterparties, effective date, tax code, and supporting evidence. An `undetermined` category must not silently become zero tax, exempt, or zero-rated treatment.

No code in this branch represents a professional tax opinion. Production tax reporting remains gated on later policy approval.

## Control-account reconciliation accountability

The ownership registry maps all 36 canonical control accounts to a proposed accountable function, contributing domains, reconciliation frequency, evidence requirements, and an independent reviewer role. Named accountable people remain `null`, and each entry remains `PENDING_OWNER_DESIGNATION`.

The registry and seeder fail closed on incomplete ownership coverage. Every control-account adapter remains blocked until the Owner or authorized management formally designates the accountable person and approves the reconciliation procedure. A role label is not a personnel appointment.

## Safety and release boundaries

- No opening balances are created.
- The CoA seeder is not wired into `DatabaseSeeder`.
- The seeder refuses to overwrite an existing matching tenant-owned, non-system account.
- Tenant and legal-entity identity are part of the seeding scope.
- Tax categories do not automatically activate subledger adapters.
- Loan principal movements must not be classified as revenue solely from the ledger account.
- No production database mutation, deployment, merge, or production posting is authorized by this implementation record.

## Reporting extensions added in the reporting completion branch\n\n- `GET /api/finance/income-statement`: period-based posted-ledger income statement.\n- `GET /api/finance/balance-sheet`: as-of ledger balance sheet including current earnings.\n- `GET /api/finance/cash-flow`: period cash-movement report based only on explicit cash-line activity metadata.\n- Cash-equivalent accounts default to disabled; missing cash-flow classification is returned as `undetermined`, never inferred from account codes or descriptions.\n- These reports are read-only and do not calculate tax returns, opening balances, consolidated statements, or a complete statutory IAS 7 cash-flow statement.\n\n## Verification record

| Check | Status |
|---|---|
| Source inventory and static inspection | PARTIAL — source read from GitHub |
| Canonical CoA structure | Contract tests added; NOT RUN |\n| Income statement and balance sheet | Source added; NOT RUN |\n| Cash-flow classification and report | Source/migration added; NOT RUN |
| Control-account policy coverage | Contract tests added; NOT RUN |
| Seeder against a real schema/database | NOT RUN |
| Repository tests | NOT RUN per Owner instruction |
| CI/workflows | NOT RUN |
| Migration execution and rollback | NOT RUN |
| Runtime authorization and tenant-isolation checks | NOT VERIFIED |
| Production readiness | NOT ESTABLISHED |
| Merge/deployment | NOT AUTHORIZED / NOT PERFORMED |

## Next gate

Review the branch diff and exact commit head, then run only the authorized destination GitHub verification workflow when available. Do not merge or deploy until the Owner separately authorizes the relevant gate. External tax review may remain deferred for code generation, but the tax defaults must remain provisional and production tax reporting must remain gated.
