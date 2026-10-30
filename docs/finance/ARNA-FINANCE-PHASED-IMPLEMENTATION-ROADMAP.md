# ARNA Finance — Phased Implementation Roadmap

**Document version:** 1.0.0  
**Status:** Owner-governed implementation baseline  
**Repository:** akefadaka-git/arna-api  
**Domain ownership:** mases_core/src/Domains/Finance/  
**Scope authority:** Saved Finance roadmap confirmed by the Owner on 2026-10-30.

## 1. Roadmap authority and phase numbering

This document is the governing macro-roadmap for Finance implementation. Detailed workstream identifiers in the Finance Implementation and Release Register remain traceable engineering IDs; they do not redefine the macro-phase boundaries below.

The repository register's FIN-2.01 through FIN-2.03 are prerequisite CoA governance gates within macro Phase 2. They are not a replacement for the complete Phase 2 scope.

## 2. Macro phases

| Macro phase | Scope | Exit outcome |
|---|---|---|
| Phase 1 — Accounting foundation | CoA baseline, legal entities, fiscal periods, journals, GL, cash/bank foundations, AR/AP foundations, posting integrity and tenant/entity isolation | Immutable, balanced, tenant-scoped accounting ledger with exact-commit verification |
| Phase 2 — Operational finance and controls | CoA/control-account governance, budgeting, expenses, fixed assets, tax, reconciliation, financial controls and approved source-domain integration | Controlled operational finance workflows that post through the Accounting Core and reconcile to the GL |
| Phase 3 — Financial reporting and statutory close | IFRS / IFRS for SMEs policy profiles, statutory reporting, financial statements, disclosures, year-end close, audit support and reproducible reporting packages | Approved, reproducible, policy-governed financial and statutory reporting |

Phase 1 engineering is closed and verified at source SHA 87fdb65612b1b792b4751d3ad6539b123fd079a8, with exact-commit workflow 40999880593:
https://github.com/akefadaka-git/arna-api/actions/runs/40999880593
Production release remains separately gated.

## 3. Architecture invariants applying to every phase

1. The Finance Accounting Core is the sole writer of posted ledger entries. Other domains submit immutable source events through the approved posting contract; they do not write journal or ledger tables directly.
2. Posted journals and lines are immutable. Corrections use linked reversal/correction entries; historical posted records are never rewritten to fit a later configuration.
3. Tenant and legal-entity boundaries apply to every query, mutation, mapping, approval, report and reconciliation.
4. Monetary calculations use exact decimal arithmetic. No binary floating-point arithmetic for financial values.
5. ETB remains the baseline accounting currency. Foreign-currency posting stays disabled until its policy and technical gates are approved.
6. No opening balances are invented, inferred or created by the canonical CoA seeder.
7. Account-level tax categories are routing defaults, not transaction-level tax determinations or legal advice. The undetermined category must fail closed.
8. Shared identity, tenant resolution, RBAC, audit and file services remain Core responsibilities. Finance owns Finance-specific policies, services, persistence, migrations, configuration, seeders and tests.
9. No production database mutation, production seeding, opening-balance import, deployment or merge occurs without the corresponding separate Owner authorization.

## 4. Phase 2 work packages and implementation sequence

### P2-A — CoA and control-account governance

| Workstream | Scope | Acceptance criteria |
|---|---|---|
| FIN-2.01 | Validate all 79 canonical accounts, unique codes, parent hierarchy, account type/normal-balance combinations, contra-account exceptions and posting eligibility | Canonical register passes deterministic structural validation; invalid hierarchy, type, normal balance, duplicate/missing accounts and posting-parent structures are rejected |
| FIN-2.02 | Validate all 36 control-account ownership mappings, evidence requirements, independent reviewer separation and adapter activation gates | Exact control-account set matches the CoA; each mapping is complete; pending personnel designation remains explicit; adapters fail closed |
| FIN-2.03 | Harden opt-in CoA seeder behavior | Seeder remains outside DatabaseSeeder; schema and ownership preflight completes before writes; existing tenant-owned non-system accounts are never overwritten; no opening balances are created |

Dependency: Phase 1 ledger foundation and canonical CoA v1.0.0.  
Current execution: FIN-2.01 COMPLETE / VERIFIED; FIN-2.02 COMPLETE / VERIFIED; FIN-2.03 source implementation and preflight unit coverage are verified, but seeder orchestration/runtime execution remains NOT RUN.

### P2-B — Budget management

Deliver:
- Budget versions by legal entity, fiscal year, department/cost centre and account classification.
- Initial budgets, revisions, transfers, commitments and controlled releases.
- Budget checks at the applicable request/commitment/approval point, not merely at payment.
- Budget-versus-actual, committed-versus-available and variance reporting.
- Approval workflow, effective dates, immutable revision history and audit evidence.

Acceptance:
- Budget scope is tenant/legal-entity isolated.
- Approved budget changes are versioned and attributable.
- Unauthorized, expired or exceeded budgets follow an explicit policy outcome.
- Budget reservations and releases are idempotent and concurrency-safe.
- Budget totals reconcile to their source allocations and accounting dimensions.

### P2-C — Expense management

Deliver:
- Expense request, advance, claim, evidence attachment, review, approval, reimbursement and settlement lifecycle.
- Employee, department, project/cost-centre and legal-entity attribution.
- Duplicate-claim detection and controlled exceptions.
- Maker-checker and prohibition on self-approval.
- Posting through the Accounting Core only after the approved business event reaches its posting state.

Acceptance:
- Each lifecycle transition is authorized and audited.
- Evidence references use the shared Files service.
- Expense, payable, advance and settlement records reconcile to GL control accounts.
- Replays cannot create duplicate liabilities or postings.
- No payment custody or external payment-provider capability is implied without a separate approved integration.

### P2-D — Fixed-asset management

Deliver:
- Asset register, acquisition and capitalization workflow, asset categories and locations.
- Depreciation schedules, accumulated depreciation and carrying value.
- Transfers, maintenance references, impairment, disposal and gain/loss treatment.
- Asset tagging and supporting documents.
- Reconciliation of asset register and depreciation subledger to GL.

Policy decisions required before financial calculations:
- Capitalization threshold and effective date.
- Useful-life and residual-value rules by asset class.
- Depreciation method and start/convention rules.
- Impairment and revaluation treatment.
- Disposal authority and evidence requirements.

Acceptance:
- No invented capitalization or depreciation policy.
- Posted acquisition/depreciation/disposal events are immutable and reversible through controlled accounting entries.
- Asset register and GL reconcile by legal entity and period.

### P2-E — Tax management

Deliver:
- Effective-dated transaction tax codes and tax determination inputs.
- VAT/output tax, recoverable input tax, withholding and income-tax reporting foundations as approved.
- Counterparty, supply type, place/time of supply, tax registration and evidence context where applicable.
- Tax return workpapers, exception register and reconciliation to GL tax control accounts.

Mandatory governance:
- Ethiopian tax rates, thresholds, exemptions, zero-rating, withholding categories and filing rules require current authoritative legal sources and qualified tax/accounting review.
- Owner approval is required for the implemented policy profile.
- Account-level default categories never determine transaction tax by themselves.
- Missing or ambiguous facts must not silently resolve to zero, exempt or zero-rated treatment.

Acceptance:
- Effective-date behavior and reproducible tax calculations are covered by tests.
- Each tax result retains its rule/version and supporting evidence.
- Tax reports reconcile to the tax subledger and GL.
- No statutory-compliance claim until reviewed and approved.

### P2-F — Reconciliation

Deliver:
- Bank statement ingestion/import contract and controlled matching.
- Cash and bank reconciliation, unmatched/duplicate/partial match handling.
- AR/AP, Wallet, Loan and other approved subledger-to-GL reconciliations.
- Control-account reconciliation schedule, accountable preparer, independent reviewer and evidence trail.
- Reconciliation exceptions, aging, resolution and period sign-off.

Acceptance:
- Reconciliation is tenant/legal-entity scoped and reproducible.
- No unexplained difference is silently written off or auto-posted.
- Every adjustment posts through the Accounting Core with authorization and audit.
- Reconciliation status and evidence are preserved for period close.

### P2-G — Financial controls

Deliver:
- Maker-checker and segregation-of-duties matrix.
- Delegated approval limits and authority lookup through Governance/Core policy services.
- Self-approval prevention, high-risk exception escalation and controlled override reasons.
- Period close checklist, close snapshot, reopen authority/reason and audit trail.
- Monitoring and exception register for failed, reversed, blocked or unreconciled financial operations.

Acceptance:
- Role titles alone never grant transactional authority.
- Approval is checked against active delegated authority and tenant/legal-entity scope.
- Conflicting duties are rejected or routed to an independently authorized reviewer.
- Period close cannot complete with policy-defined unresolved items.
- Every override and reopening is attributable and auditable.

### P2-H — Phase 2 integrated verification and closure

Required evidence:
- Exact source SHA and remote GitHub Actions run linked to that SHA.
- Finance unit/feature regression results, migration/rollback evidence and supported-MySQL verification where database-specific behavior is involved.
- Tenant/entity isolation, authorization, audit, idempotency, concurrency and immutability tests.
- CoA, control-account, budget, expense, asset, tax and reconciliation-to-GL consistency checks.
- Source-domain contract and integration evidence for every activated adapter.
- Independent source review and Owner closure decision.

Phase 2 is not closed by source presence or a green isolated test suite alone. All required work packages and cross-domain acceptance criteria must be verified.

## 5. Phase 3 — reporting, statutory close and audit

Work packages:
1. Reporting policy profiles: IFRS and IFRS for SMEs applicability and approved accounting-policy configuration.
2. Financial statements: trial balance, balance sheet, income statement, cash-flow statement, statement of changes in equity and supporting notes.
3. Disclosures: reproducible schedules, classifications, comparative periods and evidence references.
4. Year-end close: controlled adjustments, retained-earnings treatment, carry-forward, lock and reopening controls.
5. Statutory outputs: jurisdiction-specific filing formats only after qualified review and Owner approval.
6. Audit support: read-only evidence packages, journal lineage, reconciliation status, approvals, exceptions and immutable history.

Acceptance:
- Every report declares entity, period, currency, accounting basis, source, permissions and reconciliation expectation.
- Totals are reproducible from authoritative records, not manually entered dashboard figures.
- Financial statements reconcile to the trial balance and supporting subledgers.
- Policy changes are versioned and do not rewrite posted history.
- Statutory compliance is claimed only for approved and verified policy profiles.

## 6. Standard delivery gates and phase closure

Every work package follows the same gates:

| Gate | Required work | Closure evidence |
|---|---|---|
| A1 — Assess/specify | Source inventory, domain boundary, dependencies, business rules, risks and acceptance tests | Reviewed work-package specification and unresolved-decision register |
| A2 — Read-only reconciliation | Compare current source, schema, workflows, policies and tests against the specification | Evidence-backed gap matrix; exact paths and line references |
| A3 — Implement | Narrow branch-scoped code, migrations, config, seeders, tests and domain documentation | Commit SHA and complete changed-path inventory |
| A4 — Verify | Remote exact-commit CI, regression, database/concurrency/security evidence and independent source review | Workflow URL/results, test counts, artifacts, unresolved findings |
| A5 — Release | Operational rehearsal, backup/restore, approvals, cutover plan and Owner authorization | Separate release approval; deployment evidence |

A work package is COMPLETE / VERIFIED only when its acceptance criteria have evidence. Otherwise use PARTIAL, MISSING, UNRESOLVED, UNKNOWN or NOT RUN.

## 7. Current execution record — 2026-10-30

- Phase 1: COMPLETE / VERIFIED — exact source SHA 87fdb65612b1b792b4751d3ad6539b123fd079a8; workflow 40999880593.
- Phase 2: IN PROGRESS — CoA governance prerequisites FIN-2.01 and FIN-2.02 are COMPLETE / VERIFIED; FIN-2.03 implementation is present with exact-commit CI coverage, while seeder orchestration/runtime execution remains NOT RUN.
- FIN-2.01: COMPLETE / VERIFIED — exact source SHA `6abddc1e5cb69c7bc9194036248dc3c84c2545c2`; workflow `41011399270` passed 74 Finance tests (1,855 assertions), clean MySQL migrations, Composer audit and 60/60 concurrency races.
- FIN-2.02: COMPLETE / VERIFIED — exact source SHA `59d83659c95d0a8f43353705abbe46753bf6539d`; all 36 canonical control-account codes match one-to-one with the registry; names/types match; pending personnel designation and blocked adapters are enforced; reviewer roles are distinct; workflow `41012012049` passed 83 Finance tests (1,864 assertions).
- FIN-2.03: IMPLEMENTED / PARTIAL — seeder now locks all active legal entities and existing ledger rows, validates every entity before any write, and writes all entities in one transaction; preflight unit tests pass in workflow `41012012049`. The seeder remains opt-in and was NOT executed; production/real database seeding and opening-balance creation were NOT performed. Full seeder orchestration integration evidence remains outstanding.
- Budget, expenses, fixed assets, tax, reconciliation and financial controls: NOT STARTED.
- Production DB, seeder execution, opening balances, merge and deployment: NOT AUTHORIZED / NOT PERFORMED.

## 8. Immediate next actions

1. Obtain independent source review of PR #58, including the FIN-2.02 ownership registry and FIN-2.03 all-entity seeder preflight/transaction boundary.
2. Add or obtain isolated seeder-orchestration verification without running the seeder against any real or production database.
3. Keep the opt-in seeder disabled and preserve the no-opening-balance invariant.
4. Start each functional Phase 2 work package with its own A1/A2 assessment and decision register before broad implementation.
