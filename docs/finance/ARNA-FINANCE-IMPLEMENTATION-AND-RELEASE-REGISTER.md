# ARNA Finance & Accounting — Implementation and Release Register

**Register version:** 1.1.0  
**Status:** Phase 0 architecture baseline CLOSED; Finance Phase 1 engineering implementation and exact-commit verification COMPLETE; FIN-2.01, FIN-2.02 and FIN-2.03 COMPLETE / VERIFIED; production release remains gated. See [ARNA Finance Phased Implementation Roadmap](ARNA-FINANCE-PHASED-IMPLEMENTATION-ROADMAP.md).  
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
| FIN-1.01 | 1 — GL integrity | Enforce source-event uniqueness by tenant + legal entity + source domain + immutable event ID + version, with canonical payload hash | VERIFIED — exact-commit Finance suite passed; source-event uniqueness, matching replay, changed-payload conflict, and MySQL uniqueness behavior covered. |
| FIN-1.02 | 1 — GL integrity | Prove atomic posting, audit persistence, posted-header/line immutability, and reversal linkage | VERIFIED — Finance tests and clean MySQL migration passed; audit transaction and database immutability guards included in exact-commit verification. |
| FIN-1.03 | 1 — GL integrity | Verify concurrent duplicate requests, period close/post, and direct line insert/post races on MySQL | VERIFIED — 60/60 controlled independent-connection MySQL races passed on MySQL 8.0.46 at REPEATABLE-READ across all three scenarios and both arrival orders. |
| FIN-1.04 | 1 — GL integrity | Verify tenant/entity isolation for all reads, writes, account references, periods, reversals, and reports | VERIFIED — adversarial tenant/entity regressions passed; all 20 Finance API routes verified for authentication, tenant membership, and explicit Finance permission middleware. |
| FIN-1.05 | 1 — GL integrity | Confirm journal numbering uniqueness, date/period validation, and database precision boundaries | VERIFIED — date, period, journal number, description, decimal precision, and tenant/entity uniqueness regressions passed in the exact-commit Finance suite. |
| FIN-1.06 | 1 — GL integrity | Revalidate legal-entity active status and every journal-line account's active/postable status inside the locked posting transaction, not only when the draft is created | VERIFIED — posting-time entity, account, cash-equivalent, and external source-mapping revalidation regressions passed. |
| FIN-2.01 | 2 — CoA | Verify all 79 accounts, parent hierarchy, type/normal-balance consistency, and posting eligibility | COMPLETE / VERIFIED — exact head `6abddc1e5cb69c7bc9194036248dc3c84c2545c2`; [GitHub Actions #41011399270](https://github.com/akefadaka-git/arna-api/actions/runs/41011399270) SUCCESS; 74 Finance tests passed (1,855 assertions); clean MySQL migrations passed; Composer audit clear; 60/60 MySQL concurrency races passed on 8.0.46 / REPEATABLE-READ; artifact ID `12958285333`. |
| FIN-2.02 | 2 — CoA | Verify all 36 control-account ownership mappings and fail-closed seeder preflight | COMPLETE / VERIFIED — exact source SHA `59d83659c95d0a8f43353705abbe46753bf6539d`; all 36 codes match the canonical chart one-to-one; names/types match; personnel remain pending with adapters blocked; accountable/reviewer roles differ; workflow [#41012012049](https://github.com/akefadaka-git/arna-api/actions/runs/41012012049) SUCCESS; 83 Finance tests passed (1,864 assertions). |
| FIN-2.03 | 2 — CoA | Preserve opt-in seeder, no overwrite of non-system accounts, and no opening-balance creation | COMPLETE / VERIFIED — exact head `093a86873a1afe9c2b769d05940ee34280062018`; [workflow #41017940857](https://github.com/akefadaka-git/arna-api/actions/runs/41017940857) SUCCESS; 87 Finance tests passed (1,926 assertions), clean MySQL migrations passed, Composer audit clear, and 60/60 controlled MySQL races passed. The isolated MySQL integration tests executed the opt-in seeder for two active legal entities, verified all 79 canonical accounts per entity, stable IDs on repeat run, all-entity preflight rejection before writes, and zero opening journals. The seeder remains excluded from `DatabaseSeeder`; no production seeding or opening balances were performed. |
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

## 6A. Phase 1 closure — exact-commit evidence

**Phase 1 engineering status: COMPLETE / VERIFIED.** The verified source candidate is:

- Repository: `akefadaka-git/arna-api`
- Branch: `feat/finance-gl-integrity-phase1`
- Verified source SHA: `87fdb65612b1b792b4751d3ad6539b123fd079a8`
- Exact-commit workflow: [#40999880593](https://github.com/akefadaka-git/arna-api/actions/runs/40999880593) — completed / success.
- Evidence artifact: `finance-phase1-mysql-evidence-40999880593` (artifact ID `12953315469`).

### Exact-commit verification results

- Finance domain tests: **66 passed; 1,847 assertions; zero failures**.
- Controlled MySQL concurrency: **60/60 passed** across duplicate idempotency, period-close/post, and direct-line-insert/post races, using two independent connections, both arrival orders, ten repeats per permutation.
- MySQL: **8.0.46**, transaction isolation **REPEATABLE-READ**.
- Clean MySQL migrations passed; Finance InnoDB tables **6/6**; integrity triggers **13/13**; journal uniqueness indexes **3/3**.
- Finance API routes with authentication, tenant membership, and explicit Finance permission: **20/20**.
- Composer audit: **no security vulnerability advisories found**.

### Controlled PR #43 reconciliation

- PR #43 head: `398c032e3c7bfcb1678e447c7b873d7fc741e3d9`.
- Phase 1 branch compared with PR #43 head: **76 commits ahead, 0 behind**; merge base equals the PR #43 head.
- All 18 PR changed paths were accounted for: identical content retained, Phase 1 enhancements retained, the general-ledger tests consolidated under the Finance domain, and the cash-flow migration retained under the Finance domain.
- PR #43 remains **OPEN / DRAFT / UNMERGED**. No merge or PR modification was performed.

### Production release remains a separate gate

Phase 1 engineering verification does not certify a production environment. Production-like migration rehearsal, verified backup/restore, approved chart and control-account ownership, opening-balance disposition, monitoring/segregation-of-duties/incident controls, independent review, and explicit Owner release approval remain outstanding. No production database, seeding, opening-balance import, deployment, or merge was performed.

The documentation closure update is separate from the verified source SHA above; it does not change application code or retroactively alter the exact-commit test evidence.

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
| Phase 0 architecture baseline | CLOSED — architecture and technical contract |
| Finance Phase 1 implementation | COMPLETE — FIN-1.01 through FIN-1.06 |
| Exact-commit Finance test suite | VERIFIED — 66 passed; 1,847 assertions; zero failures |
| Controlled MySQL concurrency | VERIFIED — 60/60 passed on MySQL 8.0.46 / REPEATABLE-READ |
| Clean MySQL migrations | VERIFIED |
| Finance InnoDB tables | VERIFIED — 6/6 |
| Integrity triggers | VERIFIED — 13/13 |
| Journal uniqueness indexes | VERIFIED — 3/3 |
| Finance API authorization middleware | VERIFIED — 20/20 routes |
| Composer security audit | VERIFIED — no advisories found |
| Controlled PR #43 reconciliation | VERIFIED — Phase 1 is 76 commits ahead of PR head; all 18 changed paths accounted for |
| PR #43 | OPEN / DRAFT / UNMERGED |
| Production-like migration rehearsal and backup restore | NOT RUN |
| CoA/control-account operational approval and opening-balance disposition | PENDING |
| Production readiness | NOT ESTABLISHED — release gates remain |
| Merge/deployment | NOT AUTHORIZED / NOT PERFORMED |

## 9. Next gate

Phase 1 engineering is closed. Owner-confirmed macro Phase 2 is governed by [ARNA-FINANCE-PHASED-IMPLEMENTATION-ROADMAP.md](ARNA-FINANCE-PHASED-IMPLEMENTATION-ROADMAP.md): CoA/control-account governance, budgeting, expenses, fixed assets, tax, reconciliation and financial controls. FIN-2.01 through FIN-2.03 are prerequisite CoA engineering gates within that macro phase, not a replacement for its functional scope. Implementation is proceeding on feat/finance-phase2-coa-integrity from the Phase 1 closure baseline. FIN-2.01 is verified on exact source SHA `6abddc1e5cb69c7bc9194036248dc3c84c2545c2` with workflow #41011399270; FIN-2.02 is verified on exact source SHA `59d83659c95d0a8f43353705abbe46753bf6539d` with workflow #41012012049. The latter run reports 83 Finance tests / 1,864 assertions, clean MySQL migrations, no Composer advisories and 60/60 MySQL concurrency races. FIN-2.03 is COMPLETE / VERIFIED at exact head `093a86873a1afe9c2b769d05940ee34280062018` with workflow #41017940857: 87 Finance tests / 1,926 assertions, clean MySQL migrations, Composer audit, and 60/60 controlled races; the opt-in seeder was exercised only in disposable MySQL CI. Preserve the no-production-DB, no-production-seeding, no-opening-balance, no-merge and no-deployment boundaries.
