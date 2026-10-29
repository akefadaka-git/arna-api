# ARNA Finance & Accounting — Phase 0 Baseline Assessment

**Document ID:** ARNA-FIN-PHASE0-BASELINE-001  
**Version:** 1.0.0  
**Assessment date:** 2026-10-29  
**Assessment type:** Destination-GitHub, read-only source and branch assessment  
**Repository:** `akefadaka-git/arna-api`  
**Reviewed reporting feature head:** `f5359467d131aebbfc11c6806793b1e77cfad97d`  
**Phase 0 implementation branch:** `feat/finance-phase0-baseline-local-test-runner`  
**Current Phase 0 branch head:** `370c5a1b24c0076dffbefeb0c3ffb47f06d3c22e`  
**Feature base:** `8adb090c70158f8d4b22b1a28050722cffcac447`  
**Current main baseline observed by compare API:** `ed1a937456d2e240802a05ea729c902c0af6b06d`  
**Status:** PARTIAL — evidence-based initial baseline; Phase 0 is not closed.

## 1. Owner direction and safety boundaries

The Owner approved the Finance & Accounting enhancement roadmap and authorized Phase 0 plus code generation. This assessment is read-only. No CI/workflow was started, no local tests were executed, and no database migration, seeder, opening-balance import, production posting, merge, or deployment was performed.

The Owner requires test execution to remain available from Windows PowerShell on an existing local checkout, without cloning or downloading the repository as part of the runner. A local test runner has been added at `tools/finance/Invoke-FinanceTests.ps1`; it must fail closed if the checkout, dependencies, PHP version, or hermetic test database configuration are missing. It must never clone, fetch, pull, install dependencies, reset a branch, or mutate production configuration.

## 2. Branch and pull-request reconciliation

The repository contains several overlapping Finance review branches. GitHub compare results show the following relationships against the observed `main` baseline `ed1a937456d2e240802a05ea729c902c0af6b06d`.

| Branch | Exact head SHA | Commits ahead of main | Commits behind main | Changed files in compare | Assessment |
|---|---|---:|---:|---:|---|
| `feat/finance-accounting-completion` | `8adb090c70158f8d4b22b1a28050722cffcac447` | 112 | 7 | 81 | PARTIAL; broad Finance implementation branch, diverged from main |
| `feat/finance-accounting-completion-reporting` | `f5359467d131aebbfc11c6806793b1e77cfad97d` | 144 | 7 | 88 | PARTIAL; adds statements and cash-movement reporting, diverged from main |
| `feat/finance-control-account-reconciliation-v1` | `d254cd91db3c7ba4d934889f0ffc08efb4003570` | 100 | 7 | 77 | PARTIAL; separate control-account reconciliation branch |
| `feat/finance-production-readiness` | `afc820f799f2105a6c5dbd443c2636d3cf36d9d9` | 101 | 7 | 77 | PARTIAL; overlapping readiness branch |

Compare metadata is not a merge approval. The counts above are relative to the observed main SHA and do not establish that every file is unique to a branch or that the branch is production-ready.

Relevant pull requests observed:
- [PR #39 — Finance foundation and canonical CoA](https://github.com/akefadaka-git/arna-api/pull/39): open; overlaps the broad Finance implementation.
- [PR #40 — Finance foundation and canonical CoA reconciliation](https://github.com/akefadaka-git/arna-api/pull/40): open; its description references an older main baseline `e853874ee41130d47be07d2ffc097a41ecb69a9b`; reconcile against current main before treating it as current.
- [PR #42 — year-end close readiness](https://github.com/akefadaka-git/arna-api/pull/42): open; separate read-only close-readiness capability.
- [PR #43 — financial statements and cash-flow reporting](https://github.com/akefadaka-git/arna-api/pull/43): open, draft; base SHA `8adb090c70158f8d4b22b1a28050722cffcac447`, head SHA `f5359467d131aebbfc11c6806793b1e77cfad97d`.
- [PR #44 — approved enhancement roadmap](https://github.com/akefadaka-git/arna-api/pull/44): open, draft; documentation only.

**Integration risk:** multiple branches contain overlapping implementations and are seven commits behind the observed main baseline. Do not merge branches blindly or copy whole branch trees. A controlled PR-chain reconciliation is required to preserve unrelated main changes and avoid duplicate migrations, route registrations, providers, or tests.

## 3. Source inventory and current evidence

The reporting branch contains the following source areas (existence is not equivalent to runtime verification):

- **Contracts:** `FinancialReportContract`, `CashFlowReportContract`, source-reference and source-to-entity mapping contracts, and subledger posting contracts.
- **Core models/enums:** legal entity, ledger account, accounting period, journal entry/line, source-entity mapping, audit entry; period/journal/source/activity enums.
- **Services:** journal validation and lifecycle, period management, ledger account and entity management, trial balance, financial reports, cash-flow movement report, control-account ownership registry, source mapping/resolution, and subledger posting boundary.
- **HTTP/security:** Finance controller, request validation, resources, policies, service provider, route registration, and English/Amharic validation catalogues.
- **Persistence:** ledger foundation migration, journal type/reversal/mapping migrations, canonical CoA metadata, and cash-flow classification metadata.
- **Configuration/docs:** 79-account canonical CoA, control-account ownership register, implementation/release register, CoA specification, and financial-statement/cash-flow documents.
- **Tests present in the branch:** `tests/Feature/FinanceGeneralLedgerTest.php`, `tests/Feature/FinanceChartOfAccountsRegisterTest.php`, `tests/Feature/FinanceSourceEntityMappingTest.php`, `tests/Feature/FinanceSourceEntityMappingResolverTest.php`, `tests/Unit/FinanceMoneyArithmeticTest.php`, plus reporting tests on the reporting branch. Exact test discovery and execution remain NOT RUN.

Key source references:
- [Finance report service](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/mases_core/src/Domains/Finance/Services/FinancialReportService.php)
- [Cash-flow movement service](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/mases_core/src/Domains/Finance/Services/CashFlowReportService.php)
- [Journal lifecycle service](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/mases_core/src/Domains/Finance/Services/JournalEntryService.php)
- [Exact decimal arithmetic](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/mases_core/src/Domains/Finance/Support/MoneyArithmetic.php)
- [Control-account ownership register](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/config/finance/control_account_ownership_v1.php)
- [Ledger foundation migration](https://github.com/akefadaka-git/arna-api/blob/feat/finance-accounting-completion-reporting/database/migrations/2026_10_28_000003_create_financial_ledger_foundation.php)

## 4. Accounting control findings

### 4.1 Chart of accounts and ownership

- **VERIFIED (source-level):** canonical CoA register declares 79 accounts and versioned metadata.
- **VERIFIED (source-level):** the CoA seeder is opt-in and is intentionally not registered in `DatabaseSeeder`.
- **VERIFIED (source-level):** 36 control-account policies exist in the ownership register.
- **VERIFIED (source-level):** named accountable people are null and designation status is `PENDING_OWNER_DESIGNATION`; adapter activation is explicitly blocked until designation and reconciliation procedure approval.
- **UNRESOLVED:** personnel appointments and control procedures have not been supplied/approved in code evidence. Do not infer a real accountable owner from a functional role label.
- **BLOCKER:** do not activate source-domain adapters or post reconciliations to control accounts until the Owner's designation/procedure gate is satisfied.

### 4.2 Ledger and lifecycle

- **VERIFIED (source-level):** journal lifecycle code includes draft creation, validation, posting, cancellation, reversal, idempotency fingerprinting, and source references.
- **VERIFIED (source-level):** migrations define legal entities, ledger accounts, periods, journal entries/lines, and database-level safeguards including single-reversal uniqueness and MySQL immutability/validation triggers.
- **UNRESOLVED:** migrations have not been run against a fresh schema here; driver-specific trigger behavior, migration order, rollback safety, and the full production database constraints remain unverified.
- **NOT RUN:** tests and workflows; no behavior is certified by this document.

### 4.3 Financial reporting

- **VERIFIED (source-level):** trial balance composition uses decimal-string arithmetic and reports debit/credit totals and balance status.
- **VERIFIED (source-level):** PR #43 describes period-based income statement, as-of balance sheet, and tenant-scoped permission-gated endpoints for income statement, balance sheet, and cash-flow movement.
- **PARTIAL:** the cash-flow service explicitly reports period movements using classified cash lines; opening/closing cash balances and a complete IAS 7-style statement are intentionally omitted.
- **UNRESOLVED:** statement totals, reversal semantics, account classification, route authorization, tenant isolation, and query correctness require execution against controlled fixtures. No full statutory compliance claim is supported.

### 4.4 Currency and policy

- **VERIFIED (source-level):** money arithmetic uses decimal strings at four fractional digits and avoids binary floating point.
- **PARTIAL:** four decimal places are a software precision choice, not proof that every transaction type or external currency convention is fully supported.
- **UNRESOLVED:** foreign-exchange rates, revaluation, translation, multi-currency journal policy, consolidated reporting, and framework-specific disclosures need approved contracts and implementation.
- **Owner direction preserved:** external Ethiopian tax/accounting review remains deferred; account tax defaults are routing hints only, not legal conclusions.

## 5. Runtime and test environment

Source-level project configuration declares:
- PHP `^8.3`.
- Laravel `^12.0`.
- PHPUnit `^11.5`.
- PHPUnit suites named `Unit`, `Feature`, and `Domain`.
- Test environment sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, with array cache/session and synchronous queue.

The repository's `composer test` script clears Laravel configuration before invoking the test suite. The added local runner invokes PHPUnit directly so it does not run the cache-clearing script, change branches, fetch code, install packages, or download the repository.

**NOT RUN:** PHP/PHPUnit version checks on the Owner's machine; test discovery; unit/feature/domain tests; migration execution; route execution. The Phase 0 branch head `370c5a1b24c0076dffbefeb0c3ffb47f06d3c22e` currently has no reported workflow runs or commit status checks; no CI/workflow was manually dispatched.

## 6. Workflow evidence and quota constraint

No CI workflow was triggered by this assessment. Read-only code search returned 18 workflow files containing `actions/checkout` on the observed `main` baseline. Representative trigger findings:

- `.github/workflows/arna-clean-install-mysql.yml` targets `main` and `feat/p1-4-people-domain-production` pull requests, with path filters focused on migrations, providers, seeders, and named People/Organization/Governance tests; it also has a manual `workflow_dispatch` input.
- `.github/workflows/arna-phase0-effective-authorization.yml` targets a dedicated audit branch for pushes and `main` pull requests, with route/controller/domain/authorization/tenancy/test path filters.
- Migration-compatibility workflows such as `arna-totp-mfa.yml`, `arna-phone-verification-core.yml`, and wallet database compatibility target `main`, `integration/arna-system-consolidation`, or named feature branches and migration path filters.
- Focused regression workflows are mostly scoped to named branches or specific pull-request base branches. `workflow_dispatch` workflows are manual and were not invoked.

This is a search-derived inventory, not a complete directory listing; workflows that do not contain `actions/checkout` may not appear. Workflow/path-filter coverage therefore remains **PARTIAL / UNKNOWN**, not passing. No CI workflow was manually dispatched. The queried Phase 0 commit `370c5a1b24c0076dffbefeb0c3ffb47f06d3c22e` had no reported workflow runs or commit status checks when queried.

All Phase 0 commits use `[skip ci]`; no workflow was intentionally run. A skip marker is a safeguard, not a guarantee against every repository-specific automation. Do not dispatch or rerun workflows while the quota is unavailable.

## 7. Readiness conclusion

This is a partial Phase 0 baseline, not a production-readiness certification.

| Area | Status | Reason |
|---|---|---|
| Destination source and exact branch SHAs | PARTIAL | Several overlapping branches mapped; full PR ancestry reconciliation remains |
| CoA structure | PARTIAL | 79 accounts present; policy/legal approval and entity mapping are not complete |
| Control-account ownership | BLOCKED | 36 named owner/procedure designations remain pending |
| Ledger lifecycle | PARTIAL | Source code and tests present; execution and fresh-schema behavior not verified |
| Financial statements | PARTIAL | Statement services present in review branch; no execution/reconciliation evidence |
| Cash-flow statement | PARTIAL | Movement-only; no opening/closing cash reconciliation |
| Subledger integrations | BLOCKED | Resolver/contracts exist; activation remains fail-closed |
| Test runner | PARTIAL | Local PowerShell runner and usage guide generated; not executed |
| Workflow/path-filter coverage | UNKNOWN | Not exhaustively inventoried; CI intentionally not run |
| Ethiopian tax/standards compliance | UNRESOLVED | Applicability and independent review deferred by Owner |
| Production release | NOT AUTHORIZED | No merge, database operation, opening balances, posting activation, or deployment |

## 8. Immediate next actions

1. Complete a read-only PR-chain/file-overlap and workflow-path inventory against the exact destination branches; do not run CI.
2. Review the generated local PowerShell runner and run the Finance acceptance tests from an existing local checkout when ready; the runner does not download or update the repository.
3. Complete exact PR-chain/file-overlap reconciliation before selecting a canonical integration branch. Keep CI disabled/skipped and record exact commits; do not merge or deploy.
4. Continue code generation in the isolated Finance branch, prioritizing accounting invariants, route/auth contracts, and migration safety; leave adapters disabled while the 36 control-account designations are pending.

The local Finance test runner (`tools/finance/Invoke-FinanceTests.ps1`), usage guide (`docs/finance/LOCAL-TESTING.md`), cash-flow acceptance tests (`tests/Feature/FinanceCashFlowReportAcceptanceTest.php`), and route/migration discovery acceptance tests (`tests/Feature/FinanceRouteAndMigrationDiscoveryTest.php`) have been generated on `feat/finance-phase0-baseline-local-test-runner`. The generated checks cover unclassified movements, disabled cash-equivalent accounts, inclusive effective-date filtering, report-route auth/tenant/permission middleware, and canonical migration discovery/order. All tests remain **NOT RUN**.
