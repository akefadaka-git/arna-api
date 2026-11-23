# Published Loan Policy Contract — Consumer Reconciliation

**Scope:** PR #209 Loan configuration workflow, reviewed against the Loan calculation, origination, disbursement, arrears, collateral, and repayment code paths.

**Decision:** A policy may be published only when every enabled term is consumed by the current implementation. A well-formed JSON payload is not sufficient if a downstream consumer ignores or rejects one of its terms.

## Consumer matrix

| Policy area | Configuration contract | Runtime consumer(s) | Reconciliation |
|---|---|---|---|
| Product principal bounds | Positive decimal strings, max four fractional digits; min <= max | `LoanApplicationService` validates requested principal against `LoanProduct` bounds | **Aligned.** Product bounds are persisted on the tenant-scoped catalogue row and are used at origination. |
| Product term bounds | Positive integer min/max; policy `term.min_months/max_months` must exactly match catalogue bounds | `LoanApplicationService` checks the product bounds; `LoanScheduleService` generates the requested number of installments | **Aligned.** Validation prevents two sources of term bounds from disagreeing. |
| Interest | Annual decimal fraction; frozen method identifiers: declining balance, annual percentage, annuity, monthly, Actual/365 metadata, half-up, precision 4 | `LoanScheduleService` reads the loan's frozen policy `interest.annual_rate` and delegates annuity math to `AmortizationCalculator`; `LoanInterestAccrualService` records the already-scheduled `expected_interest` rather than recalculating it | **Aligned with a boundary.** The rate drives schedule generation. Interest accrual is schedule-derived and intentionally does not re-resolve the policy. The day-count identifier is recorded metadata; the current monthly annuity calculation uses annual_rate / 12. |
| Principal grace | Non-negative integer, less than the maximum term | `LoanScheduleService` prepends zero-principal grace installments and amortizes the remaining term | **Aligned.** Validation also requires at least one amortizing installment. |
| Interest grace | Must be zero | `LoanScheduleService` rejects non-zero interest grace because capitalization/deferral semantics are not implemented | **Fail-closed.** Configuration validation now rejects non-zero values before draft persistence. |
| Arrears enablement, grace, basis, rate, cap | Explicit boolean; non-negative grace days; supported basis `overdue_installment`; decimal-fraction rate; optional decimal cap | `DetectArrearsCommand` reads `arrears.grace_days` to select eligible installments; `LoanArrearsService` reads enabled, basis, penalty_rate, and cap and computes the penalty against the overdue installment | **Aligned.** The command owns the grace threshold; the service owns penalty calculation and idempotent accrual. |
| Collateral permitted types | Explicit required boolean and list of permitted types | `LoanCollateralService` checks `collateral.permitted_types` when collateral is registered, using the loan's frozen policy where available | **Partially supported.** Permitted-type restrictions are consumed, but the current origination workflow does not enforce `collateral.required`. Validation now rejects `required=true` rather than publishing an unenforced mandatory-collateral rule. |
| Fees | Empty list is supported; non-empty entries are structurally validated | `LoanDisbursementService` currently creates the principal obligation and schedule but does not read `fees` or create fee obligations | **Not implemented.** Validation now rejects non-empty fee schedules. Fee-bearing products remain unavailable until the disbursement-to-Wallet fee obligation path and its accounting/idempotency tests are implemented. |

## Version and repayment invariants

1. Approval creates an append-only `loan_product_rules` version through `VersionedPolicyRegistry`, stores the version reference on the product, and leaves the product inactive.
2. Activation is a separate action and is blocked before `effective_from`.
3. A loan freezes the policy version used at approval in `loans.frozen_policy_version_id`. Schedule, arrears, and collateral readers must use that frozen version rather than silently adopting a later product policy.
4. Interest accrual is based on persisted schedule installment values. Repayment allocation consumes the resulting Wallet obligations; it does not recalculate the interest rate.
5. Unsupported financial methods remain fixed technical invariants: declining-balance annuity, monthly schedule, Actual/365 metadata, half-up rounding, and four-decimal monetary precision. The configuration workflow cannot publish alternate calculation methods.

## Changes made by this reconciliation

- Reject non-zero `term.interest_grace_months` at configuration validation, matching the schedule engine's existing hard rejection.
- Reject principal grace that consumes the entire maximum term.
- Reject `collateral.required=true` until loan origination enforces mandatory collateral.
- Reject non-empty `fees` until disbursement actually records the fee obligation. This prevents a product from being approved with charges that would never be collected.

## Explicitly outside this reconciliation

- Fee obligation implementation and associated Wallet/accounting tests.
- Mandatory-collateral enforcement at origination.
- Existing-product policy revision workflow.
- Audit-history read endpoint.
- Owner-approved role-to-permission assignments.

These are not represented as complete by the lifecycle tests. The MySQL lifecycle suite verifies the implemented submission, review, publication, activation, audit-write, immutability, and tenant-isolation paths only.
