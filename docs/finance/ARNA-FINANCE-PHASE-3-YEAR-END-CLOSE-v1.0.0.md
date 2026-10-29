# ARNA Finance Phase 3 — Year-End Close Readiness v1.0.0

## Phase transition record

- Phase 1 scope is frozen against further expansion.
- Phase 2 scope is frozen against further expansion.
- This is a scope transition, not a claim that every Phase 1/2 acceptance gate passed.
- The ledger foundation PR #29 is merged. The broader Finance foundation/CoA/ownership implementation remains in draft PRs and has not passed automated verification.
- Phase 3 begins with a read-only year-end close readiness assessment.

## Endpoint

`GET /api/finance/year-end-close-readiness`

Required query parameters:

- `legal_entity_id`: legal entity in the resolved tenant.
- `fiscal_year_start`: inclusive ISO date (`YYYY-MM-DD`).
- `fiscal_year_end`: inclusive ISO date (`YYYY-MM-DD`), not before the start date.

Authorization: authenticated tenant member with `finance.report.view`.

## Readiness checks

The endpoint reports, without mutating data:

1. Whether accounting periods cover the full requested fiscal year without gaps.
2. Whether every accounting period intersecting that fiscal year is closed.
3. Whether any draft journals remain with effective dates in the fiscal year.
4. Whether the legal entity's trial balance balances as of fiscal year end.
5. Whether source-to-legal-entity mappings remain pending.
6. Whether every configured control account has a named person and formal designation status.

The result is `ready_for_owner_review` only when every check passes. Missing ownership configuration fails closed.

## Deliberate limitations

- This endpoint does not close periods or post journals.
- It does not create retained-earnings or other closing entries. The closing-entry policy must be separately approved and should not be inferred from account names.
- It does not calculate VAT, withholding, income tax, or certify tax classifications.
- It is not an IFRS compliance statement, Ethiopian statutory filing, or audit opinion.
- It does not independently attest to bank, wallet, loan, payroll, supplier, tax, shareholder or intercompany reconciliation evidence.
- Control-account owners remain pending formal designation in the current ownership register; therefore readiness is expected to remain blocked until the register is updated and approved.

## Verification status

- Static implementation only.
- Unit tests added for complete coverage, a missing date, and no period coverage.
- Tests: NOT RUN, per Owner instruction.
- GitHub Actions/workflows: NOT RUN; commits use `[skip ci]`.
- Migration/seeder execution: NOT APPLICABLE / NOT RUN.
- Production close, posting, tax reporting, adapter activation, merge and deployment: NOT performed.
