# ARNA Loan Product and Financial Policy Governance

## Binding design requirement

Loan products and every financial term are operational configuration, not source-code defaults. No production seeder may create an active lending product or publish an interest, arrears, penalty, fee, collateral, or repayment policy.

The Loan domain already has the Core immutable `versioned_policies` registry and a product reference `loan_products.product_rules_policy_version_id`. Retain that foundation. The configuration workflow must validate Loan payloads before publishing a new immutable `loan_product_rules` version.

## Separation of responsibilities

- **Product identity and envelope:** tenant-owned catalogue data in `loan_products` (code, bilingual name/description, purpose, currency, principal bounds, term bounds, repayment frequency, lifecycle status).
- **Financial terms:** a validated, immutable version in `versioned_policies` with policy type `loan_product_rules`. No rate or penalty values belong in PHP constants, config files, migrations, or seeders.
- **Proposed changes:** stored as draft submissions, separate from the live policy registry. Drafts must not affect loan calculations or product availability.
- **Approval evidence:** records submitter, reviewer/approver, decision, timestamps, rationale, and the exact approved payload digest/version.
- **Loan origination:** at approval, the loan freezes the approved policy version ID. Existing loans continue using that version even after a product is reconfigured.

## Required lifecycle

1. Draft product or policy configuration.
2. Validate the complete payload and cross-field invariants.
3. Submit for review; lock the submitted payload against silent edits.
4. Independent reviewer checks affordability, repayment calculations, fees, arrears behavior, legal/compliance requirements, and disclosure text.
5. Authorized approver approves or rejects the exact submitted revision. The author cannot approve their own submission.
6. On approval, publish a new immutable `loan_product_rules` version through `VersionedPolicyRegistry`.
7. Attach the published version to the product for future originations; record the policy version on each loan at approval.
8. Audit every transition. Corrections create a new submission/version; published policy rows are never edited or deleted.

## Payload contract

The existing Loan payload vocabulary remains the consumer contract: `interest`, `term`, `arrears`, `collateral`, and `fees`. The authoring service must validate every required key, type, supported calculation method, decimal precision, and cross-field constraint before submission and again before publication.

At minimum, financial configuration must capture:

- Interest: rate, flat/reducing-balance method, accrual frequency, day-count convention, rounding rule, and compounding behavior.
- Term/repayment: principal and term limits, repayment frequency, principal/interest grace rules, and schedule conventions.
- Arrears/penalties: enablement, trigger basis, grace days, rate or fixed amount, calculation basis, cap, and whether penalties can compound.
- Collateral: whether required, permitted collateral types, valuation and coverage rules.
- Fees: fee type, amount/rate, calculation basis, timing, cap, tax treatment, and disclosure label.

A missing or invalid required value must fail closed. No fallback rate, grace period, penalty, or fee is permitted.

## Authorization and segregation of duties

Use distinct capabilities for product management, policy drafting, policy submission, policy review, policy approval, activation, and audit viewing. Approval must be independent of authorship. The server derives tenant scope from authenticated tenant membership and the product's tenant; never trust a caller-supplied tenant ID.

The existing Loan RBAC catalogue does not yet provide the full policy-authoring and approval permission set. Add explicit permissions and role grants as part of the management API implementation; do not reuse `loan.approve`, which approves a borrower loan, as authority to approve financial policy.

## Effective dates and historical integrity

A proposed policy has an explicit effective-from timestamp and may be scheduled for the future. It cannot be used before approval or before its effective time. A new policy version applies only to future loan originations. Each loan retains its frozen policy version and all accrual/repayment calculations must resolve through that frozen reference.

Do not mutate an existing `versioned_policies` row to change historical terms. Do not attach an unapproved draft to a product. If policy supersession requires closing a previous effective window, implement it as a separately reviewed registry lifecycle change that preserves the registry's immutable-history contract.

## Seeder behavior

- `LoanProductsSeeder` is retired and fails closed; no fixed product codes, currency, principal limits, or term ranges are inserted.
- `LoanProductRulesPolicySeeder` is retired and fails closed; no 12% rate, five-day grace period, 5% penalty, or other financial values are inserted.
- Production bootstrap may seed Loan permissions and roles only. It must not create products or financial policies.

## Delivery boundary

The current change removes unsafe hard-coded operational seeders. The next implementation slice is the tenant-scoped product/policy administration workflow: draft storage, validated payload service, segregation-of-duties approval, immutable publication, product attachment, audit trail, and API authorization/tests. Until that slice is implemented and verified, products and lending remain unconfigured and loan origination must remain unavailable where no approved policy is attached.
