# Published Loan Policy Contract — Consumer Reconciliation

**Scope:** PR #209 Loan configuration workflow, reviewed against the Loan calculation, origination, disbursement, arrears, collateral, and repayment code paths.

**Decision:** A policy may be published only when every enabled term is consumed by the current implementation. A well-formed JSON payload is not sufficient if a downstream consumer ignores or rejects one of its terms.

## Consumer matrix

| Policy area | Configuration contract | Runtime consumer(s) | Reconciliation |
|---|---|---|---|
| Product principal bounds | Positive decimal strings, max four fractional digits; min <= max | `LoanApplicationService` validates requested principal against `LoanProduct` bounds | **Aligned.** Product bounds are persisted on the tenant-scoped catalogue row and are used at origination. |
| Product term bounds | Positive integer min/max; policy `term.min_months/max_months` must exactly match catalogue bounds | `LoanApplicationService` checks the product bounds; `LoanScheduleService` generates the requested number of installments | **Aligned.** Validation prevents two sources of term bounds from disagreeing. |
| Interest | Annual decimal fraction; frozen method identifiers: declining balance, annual percentage, annuity, monthly, Actual/365 metadata, half-up, precision 4 | `LoanScheduleService` reads the loan's frozen policy `interest.annual_rate` and delegates annuity math to `AmortizationCalculator`; `LoanInterestAccrualService` records the already-scheduled `expected_interest` rather than recalculating it | **Aligned with a boundary.** The rate drives schedule generation. Interest accrual is schedule-derived and intentionally does not re-resolve the policy. The day-count identifier is recorded metadata; the current monthly annuity calculation uses annual_rate / 12. |
| Principal grace | Non-negative integer, strictly less than the minimum permitted term | `LoanScheduleService` requires `principal_grace_months < loan.term_months` for every disbursed loan | **Aligned after follow-up correction.** Validation rejects grace greater than or equal to the product's minimum term; checking only the maximum term allowed a valid shortest-term application to fail later at disbursement. |
| Interest grace | Must be zero | `LoanScheduleService` rejects non-zero interest grace because capitalization/deferral semantics are not implemented | **Fail-closed.** Configuration validation now rejects non-zero values before draft persistence. |
| Arrears enablement, grace, basis, rate, cap | Explicit boolean; non-negative grace days; supported basis `overdue_installment`; decimal-fraction rate; optional decimal cap | `DetectArrearsCommand` reads `arrears.grace_days` to select eligible installments; `LoanArrearsService` reads enabled, basis, penalty_rate, and cap and computes the penalty against the overdue installment | **Aligned.** The command owns the grace threshold; the service owns penalty calculation and idempotent accrual. |
| Collateral permitted types / required / coverage | List of implemented `CollateralType` values; required flag and coverage ratio must remain disabled | `LoanCollateralService` checks `collateral.permitted_types` when collateral is registered, using the loan's frozen policy where available | **Partially supported.** The reader enforces the permitted-type list only. It does not enforce mandatory collateral or the configured coverage ratio. Validation rejects `required=true`, non-null `coverage_ratio`, and unsupported type names rather than publishing ignored controls. |
| Fees | Empty list is supported; non-empty entries are structurally validated | `LoanDisbursementService` currently creates the principal obligation and schedule but does not read `fees` or create fee obligations | **Not implemented.** Validation now rejects non-empty fee schedules. Fee-bearing products remain unavailable until the disbursement-to-Wallet fee obligation path and its accounting/idempotency tests are implemented. |

## Version and repayment invariants

1. Approval creates an append-only `loan_product_rules` version through `VersionedPolicyRegistry`, stores the version reference on the product, and leaves the product inactive.
2. Activation is a separate action and is blocked before `effective_from`.
3. A loan freezes the policy version used at approval in `loans.frozen_policy_version_id`. Schedule, arrears, and collateral readers must use that frozen version rather than silently adopting a later product policy.
4. Interest accrual is based on persisted schedule installment values. Repayment allocation consumes the resulting Wallet obligations; it does not recalculate the interest rate.
5. Unsupported financial methods remain fixed technical invariants: declining-balance annuity, monthly schedule, Actual/365 metadata, half-up rounding, and four-decimal monetary precision. The configuration workflow cannot publish alternate calculation methods.

## Changes made by this reconciliation

- Reject non-zero `term.interest_grace_months` at configuration validation, matching the schedule engine's existing hard rejection.
- Reject principal grace greater than or equal to the minimum permitted term, ensuring every valid requested term retains at least one amortizing installment.
- Reject `collateral.required=true` and non-null `collateral.coverage_ratio` until the Loan workflow enforces both controls.
- Restrict `collateral.permitted_types` to the two implemented enum values (`external`, `shareholding`).
- Reject non-empty `fees` until disbursement actually records the fee obligation. This prevents a product from being approved with charges that would never be collected.

## Explicitly outside this reconciliation

- Fee obligation implementation and associated Wallet/accounting tests.
- Mandatory-collateral enforcement at origination.
- Owner-approved role-to-permission assignments. The dedicated audit permission is catalogued but is not assigned to any role by this change.

These remain outside the implemented workflow and are not represented as complete by the lifecycle tests. The MySQL lifecycle suite verifies submission, review, publication, activation, audit-write, immutability, tenant isolation, and the existing-product revision lifecycle. The follow-up minimum-term grace and stale-revision regression additions are awaiting exact-head A4 verification.


## Existing-product revision and audit-history lifecycle (PR #209 follow-up)

- A revision draft identifies its existing product with `product.id`. The submission stores `revision_product_id` and snapshots `previous_policy_version_id` as its revision lineage.
- Review publishes a new immutable policy version but does not change the existing product's active policy pointer or product metadata.
- Activation is blocked before `effective_from`. At activation, the product is locked and its current policy pointer is compared with the captured previous version. A stale revision fails closed rather than overwriting a newer revision.
- Successful activation switches the product pointer and applies the approved catalogue metadata in the same transaction. Existing loans retain their own `frozen_policy_version_id`; no loan is repointed.
- New-product creation retains its existing inactive-publication / separate-activation lifecycle.
- `GET /api/loans/configuration/audit` returns chronological tenant-scoped audit entries, optionally filtered by `submission_id`, and requires the dedicated `loan.configuration.audit.view` permission. The permission is added to the Loan permission catalogue but is not assigned to roles pending Owner governance.
- MySQL lifecycle coverage includes revision-pointer preservation before effective activation, revision metadata/policy switch, chronological audit reads, missing-permission denial, and cross-tenant isolation. The previously completed exact-head MySQL workflow passed these tests; additional stale-revision and minimum-term grace regressions are now added and await a new exact-head run.
