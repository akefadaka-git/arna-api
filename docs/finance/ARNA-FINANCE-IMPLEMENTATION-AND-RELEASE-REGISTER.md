# ARNA Finance & Accounting — Implementation and Release Register

**Register version:** 1.1.0  
**Status:** Phase 0 architecture baseline closed; implementation and release remain in progress.  
**Chart of accounts:** v1.0.0; 79 canonical accounts.  
**Owner direction:** continue code generation; external Ethiopian tax/accounting review deferred for now.  
**Canonical architecture:** [ARNA Finance General Ledger Architecture Contract](../architecture/financial-accounting-domain-contract.md), v1.1.0.

## 1. Phase 0 — architecture baseline

**Phase 0: CLOSED — architecture baseline only.**

Phase 0 is closed for architecture and technical-contract purposes. The canonical contract now defines:

- Finance ownership of the authoritative GL and operational source-domain boundaries.
- Tenant + legal-entity isolation and canonical identity/tenant-context rules.
- Separate legal-entity books, ETB baseline, and read-model-only consolidation boundary.
- Exact decimal arithmetic and the current `DECIMAL(20,4)` ledger storage baseline.
- Journal balance, posting, immutability, reversal, idempotency, period, and audit invariants.
- Canonical CoA and control-account governance boundaries.
- No-opening-balance rule and no automatic adapter activation without verified source contracts.
- Reporting limitations and explicit later-phase policy/activation gates.

**Phase 0 closure does not certify runtime implementation, test results, tax compliance, accounting-policy approval, opening balances, production readiness, or deployment.**

## 2. Exact-head review record — PR #43

Reviewed destination repository: `akefadaka-git/arna-api`.

- PR: [#43 — feat(finance): complete financial statements and cash-flow reporting](https://github.com/akefadaka-git/arna-api/pull/43)
- PR state at review: OPEN, DRAFT, UNMERGED.
- Base branch: `feat/finance-accounting-completion`.
- Base SHA at review: `8adb090c70158f8d4b22b1a28050722cffcac447`.
- Head branch: `feat/finance-accounting-completion-reporting`.
- Exact head reviewed: `f5359467d131aebbfc11c6806793b1e77cfad97d`.
- Diff: 17 files, 748 additions, 3 deletions; 32 commits.
- Review type: bounded source/diff inspection through destination GitHub. Local tests and CI were not run.

### Review disposition

- **VERIFIED (source presence):** income statement, balance sheet, cash-movement service, tenant-scoped routes, permission middleware, explicit cash-flow classification, and feature-test additions are present.
- **VERIFIED (safety boundary):** cash-equivalent accounts default false; cash-flow activity is explicit; missing/invalid classification is exposed as undetermined; no code/name inference is used.
- **PARTIAL:** report services and controller authorization were inspected at source level; runtime query behavior, database compatibility, and full integration are not established.
- **DEFECT FOUND:** the register's reporting section and some verification rows contained literal `\\n` sequences rather than real Markdown line breaks. Corrected in this register.
- **NOT RUN:** local tests, CI/workflows, migration execution, seeder execution, production posting, database mutation, and deployment.
- **UNRESOLVED:** full IAS 7 cash-flow statement, opening/closing cash reconciliation, FX presentation, consolidated reporting, and statutory compliance remain outside the reporting extension's current claim.

## 3. Implemented source inventory

The Finance implementation branch contains source for legal entities, ledger accounts, accounting periods, journal lifecycle, reversals, source-to-legal-entity mappings, subledger posting contracts, trial balance, income statement, balance sheet, explicit cash-movement reporting, HTTP requests/controllers/resources, authorization policies, service-provider bindings, audit lifecycle events, English/Amharic validation resources, migrations, and feature/unit tests.

The canonical chart contains 79 accounts with hierarchy, bilingual labels, type, normal balance, posting eligibility, control-account designation, domain mapping, and provisional tax-routing category. The opt-in seeder is intentionally not registered in `DatabaseSeeder`.

This is an inventory of source artifacts, not a claim that runtime behavior has been verified.

## 4. Control-account accountability

The ownership registry maps 36 canonical control accounts to a proposed accountable function, contributing domains, reconciliation frequency, evidence requirements, and independent reviewer role.

- Named accountable people remain `null`.
- Each entry remains `PENDING_OWNER_DESIGNATION`.
- Every control-account adapter remains blocked until an accountable person is formally designated and the reconciliation procedure is approved.
- A role label is not a personnel appointment.
- Control ownership must retain preparer/reviewer separation.

## 5. Tax defaults — deferred, not approved as legal advice

The Owner has deferred independent tax/accounting review for the current code-generation phase. Account-level tax categories remain routing defaults only. They are not tax rates and are not final Ethiopian legal classifications.

Transaction-level tax treatment must be determined from applicable rules, supply facts, counterparties, effective date, tax code, and supporting evidence. An `undetermined` category must not silently become zero tax, exempt, or zero-rated treatment.

No code in this branch represents a professional tax opinion. Production tax calculations and statutory reporting remain gated on later policy approval.

## 6. Remaining implementation register

| ID | Phase | Required work | Status / acceptance evidence |
|---|---|---|---|
| FIN-1.01 | 1 — GL integrity | Enforce source-event uniqueness by tenant + legal entity + source domain + immutable event ID + version, with canonical payload hash | IMPLEMENTED IN SOURCE — nullable legacy-compatible `source_event_version`, required for external sources; unique tenant/entity/type/source/version index; canonical `source_event_hash` excludes request idempotency key; same event/payload returns persisted journal; changed payload conflicts. Tests added; NOT RUN |
| FIN-1.02 | 1 — GL integrity | Prove atomic posting, audit persistence, posted-header/line immutability, and reversal linkage | PARTIAL — transaction/service source exists; exact runtime and model-guard behavior unverified |
| FIN-1.03 | 1 — GL integrity | Verify concurrent duplicate requests and period close/post race behavior on MySQL | NOT RUN |
| FIN-1.04 | 1 — GL integrity | Verify tenant/entity isolation for all reads, writes, account references, periods, reversals, and reports | PARTIAL — composite constraints and scoped queries exist; adversarial tests not run |
| FIN-1.05 | 1 — GL integrity | Confirm journal numbering uniqueness, date/period validation, and database precision boundaries | PARTIAL — source and constraints exist; full boundary verification pending |
| FIN-1.06 | 1 — GL integrity | Revalidate legal-entity active status and every journal-line account's active/postable status inside the locked posting transaction, not only when the draft is created | IMPLEMENTED IN SOURCE — posting locks and rechecks the tenant-scoped legal entity, period, and sorted same-entity account rows; rejects inactive/non-postable accounts and cash-flow classification after cash-equivalent status changes. Tests added; NOT RUN |
| FIN-2.01 | 2 — CoA | Verify all 79 accounts, parent hierarchy, type/normal-balance consistency, and posting eligibility | SOURCE PRESENT — tests added, NOT RUN |
| FIN-2.02 | 2 — CoA | Verify all 36 control-account ownership mappings and fail-closed seeder preflight | SOURCE PRESENT — tests added, NOT RUN |
| FIN-2.03 | 2 — CoA | Preserve opt-in seeder, no overwrite of non-system accounts, and no opening-balance creation | SOURCE PRESENT — execution NOT RUN |
| FIN-3.01 | 3 — Reporting | Verify trial balance and account activity against posted journal-line totals | NOT VERIFIED |
| FIN-3.02 | 3 — Reporting | Verify income statement and balance sheet totals, date boundaries, reversed journals, and negative balances | SOURCE/TESTS PRESENT — NOT RUN |
| FIN-3.03 | 3 — Reporting | Verify cash-flow classification, undetermined movements, reversal netting, and report permissions | SOURCE/TESTS PRESENT — NOT RUN |
| FIN-3.04 | 3 — Reporting | Add opening/closing cash reconciliation and full statement only under a separately approved contract | NOT IMPLEMENTED |
| FIN-4.01 | 4 — Integrations | Read-only audit of Wallet source events, identity, transaction boundary, reversals, currency, and reconciliation extract | NOT COMPLETE |
| FIN-4.02 | 4 — Integrations | Read-only audit of Loan principal, interest, fees, repayments, allocations, write-offs, and reversals | NOT COMPLETE |
| FIN-4.03 | 4 — Integrations | Read-only audit of Shareholder capital, paid-in events, distributions, and non-accounting holding transfers | NOT COMPLETE |
| FIN-4.04 | 4 — Integrations | Read-only audit of Procurement and People/Payroll source events and entity ownership | NOT COMPLETE |
| FIN-4.05 | 4 — Integrations | Approve event-to-GL mappings, exception handling, and reconciliation ownership before adapter activation | BLOCKED |
| FIN-5.01 | 5 — Period close | Implement close checklist, close snapshot, transactional close lock, reopen authority/reason, and audit trail | PARTIAL / NOT VERIFIED |
| FIN-5.02 | 5 — Consolidation | Define entity group, intercompany mapping, elimination policy, and reproducible consolidation snapshot | POLICY BLOCKED |
| FIN-6.01 | 6 — FX | Define currency precision, FX source, effective time, rounding, revaluation, and realized/unrealized treatment | POLICY BLOCKED; foreign-currency posting disabled |
| FIN-6.02 | 6 — Tax/statutory | Implement transaction-level tax only after approved tax codes and policy | DEFERRED per Owner direction |
| FIN-7.01 | 7 — Verification | Exact-head automated tests, migrations/rollback, MySQL concurrency, authorization, isolation, and report reconciliation | NOT RUN / NOT VERIFIED |
| FIN-7.02 | 7 — Release | Pilot, cutover balances, operational acceptance, production migration, and deployment | NOT STARTED; separate authorization required |

## 6A. Phase 1 implementation log — FIN-1.01 and FIN-1.06

Implementation branch: `feat/finance-gl-integrity-phase1`, based on the reviewed PR #43 head before Phase 1 changes.

### FIN-1.01 — versioned source-event idempotency

- Added `source_event_version` and `source_event_hash` to `journal_entries`.
- Added database uniqueness on `tenant_id + legal_entity_id + source_type + source_id + source_event_version`. Nullable versions preserve legacy/manual rows; new external-source journals must supply a non-empty version.
- The canonical source-event fingerprint excludes the request idempotency key, so a retry of the same source event and payload can return the original journal even if the caller uses a different request key.
- A repeated source identity with a changed canonical payload returns HTTP 409.
- Unique-index races are reconciled after transaction rollback by re-reading the idempotency key and source identity; matching hashes return the persisted journal, changed hashes conflict.
- `SubledgerPostingInstruction` now requires and forwards the source event version.
- MySQL posted-journal immutability trigger now includes source event version and hash.
- Existing legacy rows are not assigned invented event versions; their nullable version is preserved. External event replay for historical unversioned rows remains a migration/reconciliation concern and must not be guessed.

### FIN-1.06 — posting-time entity/account revalidation

- Posting now locks and revalidates the tenant-scoped legal entity and confirms active status and base-currency consistency.
- Posting now loads all journal lines, locks referenced account rows in deterministic ID order, and verifies same tenant/entity ownership, active status, and posting eligibility.
- Cash-flow classified lines are rejected if the account is no longer designated as a cash equivalent.
- Validation occurs inside the same posting transaction before the journal status transition and synchronous audit event.

### Evidence boundary

The source changes and regression tests are committed on the Phase 1 branch. No local tests, CI, migration execution, MySQL trigger execution, or database concurrency tests were run. Therefore both items are **IMPLEMENTED IN SOURCE / NOT RUNTIME VERIFIED**, not closed for production acceptance.

## 7. Safety and release boundaries

- No opening balances are created or inferred.
- The CoA seeder is not wired into `DatabaseSeeder`.
- The seeder must not overwrite existing matching tenant-owned non-system accounts.
- Tax categories do not activate subledger adapters.
- Loan principal is not revenue merely because of an account mapping.
- No production database mutation, production posting, merge, or deployment is authorized by this register.
- PR #43 remains open and in draft status until exact-head source review, verification, and separate Owner approval.

## 8. Verification record

| Check | Status |
|---|---|
| Phase 0 architecture baseline | CLOSED — documentation and technical contract |
| PR #43 exact-head diff review | PARTIAL — source/diff review; no runtime verification |
| Canonical CoA structure | Contract tests added; NOT RUN |
| Control-account ownership coverage | Contract tests added; NOT RUN |
| Journal invariants and concurrency | Source present; NOT VERIFIED |
| Income statement and balance sheet | Source/tests added; NOT RUN |
| Cash-flow classification/report | Source/migration/tests added; NOT RUN |
| Seeder against a real schema/database | NOT RUN |
| Repository tests | NOT RUN per Owner instruction |
| CI/workflows | NOT RUN |
| Migration execution and rollback | NOT RUN |
| Runtime authorization and tenant isolation | NOT VERIFIED |
| Production readiness | NOT ESTABLISHED |
| Merge/deployment | NOT AUTHORIZED / NOT PERFORMED |

## 9. Next gate

Proceed with Phase 1 core GL integrity, beginning with source-event idempotency identity and database uniqueness, then posting/audit atomicity, immutable posted records, reversal correctness, and period concurrency. Keep all adapters disabled until source audits and ownership controls are complete. Run only destination GitHub verification that is separately within the approved verification gate; do not run local repository tests. Do not merge or deploy until the Owner separately authorizes those actions.
