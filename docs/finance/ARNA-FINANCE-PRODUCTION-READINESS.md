# ARNA Finance & Accounting — Production Readiness Register

**Status:** implementation baseline in review; **NOT production-ready**.  
**CoA version:** 1.0.0.  
**Scope:** general ledger foundation, canonical chart of accounts, and subledger integration boundary.

This register is the release control record. A completed code change, an open pull request, or static inspection is not evidence that production readiness has been achieved.

## 1. Current implementation inventory

The Finance domain on the canonical CoA branch includes:

- Legal entities and tenant-scoped ledger accounts.
- Accounting periods and period closure.
- Journal entries and journal lines, balanced-entry validation, posting, reversal, and draft cancellation.
- Finance audit events and append-only audit protection where supported by the migration.
- Trial-balance and financial-report services.
- Source-to-legal-entity mapping and subledger posting contracts.
- HTTP controllers, form requests, resources, policies, provider bindings, English/Amharic validation resources, migrations, and feature/unit tests.
- A versioned 79-account CoA register and an opt-in seeder that is deliberately not registered in DatabaseSeeder.

The inventory above describes present source artifacts, not verified runtime behavior.

## 2. Account-level tax default policy

The canonical CoA register contains a tax-routing category for every account. The supported categories are:

- taxable
- non_taxable
- exempt
- zero_rated
- out_of_scope
- undetermined

These are routing metadata only. They are not tax rates, a final legal determination, or a substitute for transaction-level classification. A transaction's tax treatment must be based on the applicable Ethiopian law, transaction/supply facts, counterparties, supporting evidence, and effective date.

The revenue defaults for sales, services, and procurement/fulfillment remain provisional pending qualified Ethiopian tax review. Loan interest, wallet/payment fees, commissions, supplier costs, inventory and operating expenses must not be assigned a final treatment solely from their ledger account. undetermined must not silently become zero tax or an exemption.

**Release gate:** attach a dated written opinion from a qualified reviewer with verifiable current authorization and relevant Ethiopian tax expertise. Record each proposed change with the affected account code, legal basis, effective date, required evidence, reviewer conclusion, and Owner approval. No reviewer opinion is represented as received by this document.

## 3. Control-account accountability matrix

The role labels below are proposed functional owners. They are **not confirmed individual appointments**. Before enabling an adapter, the Owner or authorized management must record a named accountable person, preparer, independent reviewer, reconciliation frequency, evidence location, variance threshold, aging policy, and escalation path.

| Control-account group | Proposed accountable function | Required evidence |
|---|---|---|
| Cash and bank | Finance/Treasury Lead | Bank statements, cash counts, bank reconciliations, outstanding items |
| Wallet clearing and customer funds | Wallet Operations Lead, with Finance control | Wallet subledger, provider settlement reports, bank movements, customer liability roll-forward |
| Loan principal, interest and credit-loss balances | Loan Operations/Credit Lead, with Finance control | Loan schedules, repayment allocations, interest accruals, impairment support |
| Trade payables and supplier advances | Procurement/Accounts Payable Lead, with Finance control | Purchase orders, invoices, receipt/acceptance records, supplier statements, settlements |
| Payroll payable and employee balances | People/Payroll Lead, with Finance control | Approved payroll register, deductions, payment evidence and reconciliation |
| Share capital and distributions | Shareholder Operations Lead, with Finance control; Governance authorizes relevant actions | Shareholder register, approved resolutions, capital receipts, distribution calculations and payments |
| Tax receivable/payable | Finance/Tax Lead | Tax invoices, tax calculations, filed returns, payment receipts, authority statements |
| Intercompany balances | Group Finance/Consolidation Lead | Reciprocal counterparty balances, confirmations, differences, elimination entries |

Finance remains accountable for the general-ledger balance and financial reporting. A source domain must provide traceable subledger evidence. No person should prepare and independently approve the same reconciliation.

## 4. Mandatory production release gates

| Gate | Acceptance evidence | Current disposition |
|---|---|---|
| Schema and migration compatibility | Review of migration order, existing schema, supported database engines, constraints and rollback behavior | **PARTIAL** — source inspection only |
| CoA register integrity | Automated contract tests for uniqueness, parent order, required fields, supported types and tax categories | **CODE ADDED; NOT RUN** |
| Seeder safety | Tests for idempotency, legal-entity isolation, refusal to overwrite tenant-owned accounts, schema preflight, and no opening balances | **NOT VERIFIED** |
| Ledger accounting invariants | Verified balanced posting, closed-period rejection, immutable posted entries, reversal linkage, idempotency and concurrency behavior | **NOT VERIFIED IN THIS GATE** |
| Authorization and tenant isolation | Exact-head automated evidence for every Finance route and cross-tenant/cross-entity denial | **NOT VERIFIED IN THIS GATE** |
| Reporting | Independent checks that trial balance balances, excludes drafts/cancelled journals, and filters by tenant/entity/period | **NOT VERIFIED IN THIS GATE** |
| Subledger adapters | Domain-specific mapping contracts, idempotency, retries, reconciliation reports and named owners for each enabled adapter | **NOT APPROVED** |
| Tax policy | Qualified written Ethiopian accounting/tax review and explicit Owner decision | **PENDING** |
| Reconciliation governance | Named accountable people, preparers/reviewers, evidence schedules and escalation paths | **PENDING** |
| Runtime verification | Applicable repository CI/workflows pass on the exact reviewed SHA; no local repository tests are implied | **NOT RUN** |
| Data readiness | Verified legal entities, approved opening balances, conversion/reconciliation plan and recovery procedure | **PENDING** |
| Production authorization | Explicit Owner release decision after all gates above are satisfied | **NOT AUTHORIZED** |

## 5. Safe operating boundaries until approval

- Do not wire the CoA seeder into DatabaseSeeder or run it against production.
- Do not create opening balances or historical journals as part of CoA setup.
- Do not activate loan, wallet, procurement, payroll, shareholder, tax, or intercompany posting adapters merely because a control-account code exists.
- Do not treat account defaults as definitive Ethiopian tax policy.
- Do not enable production posting until exact-head verification and formal release approval are recorded.
- Preserve existing tenant-owned accounts and historical ledger entries; never rewrite posted history to match a new CoA version.

## 6. Verification record

- Branch: feat/finance-production-readiness
- Parent reviewed source head: 124755c39bf2a4e524155d6d5be4abe8fd229781
- Static source inspection: performed while preparing this register.
- Automated tests: **NOT RUN**.
- GitHub Actions/workflows: **NOT RUN**.
- Seeder execution: **NOT RUN**.
- External accounting/tax opinion: **NOT RECEIVED / NOT VERIFIED**.
- Named reconciliation-owner appointments: **NOT CONFIRMED**.
- Merge, deployment, and production data operations: **NOT PERFORMED**.

**Next release action:** obtain the qualified written tax/accounting opinion and formal named-owner appointments, then complete exact-head automated verification and close every applicable release gate before requesting production authorization.
