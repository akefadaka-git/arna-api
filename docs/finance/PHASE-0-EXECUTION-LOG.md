# Finance Phase 0 — Execution Log and Code Generation Record

**Date:** 2026-10-29  
**Repository:** `akefadaka-git/arna-api`  
**Working branch:** `feat/finance-phase0-baseline-local-test-runner`  
**Code baseline used to start this branch:** `f5359467d131aebbfc11c6806793b1e77cfad97d`  
**Recorded branch head before this log:** `14c7fbcc5b29a499836264892bf849b0a4320734`  
**CI/workflows:** NOT RUN by instruction  
**Tests:** NOT RUN  
**Status:** Code generated; source-level verification only; Phase 0 remains PARTIAL.

## 1. Changes generated

| Path | Change | Status |
|---|---|---|
| `docs/finance/PHASE-0-BASELINE-2026-10-29.md` | Branch/PR evidence, source inventory, controls, gaps, workflow search findings | GENERATED; source evidence only |
| `docs/finance/LOCAL-TESTING.md` | Windows PowerShell usage; no-clone/no-fetch runner contract | GENERATED |
| `tools/finance/Invoke-FinanceTests.ps1` | Local Finance test discovery and PHPUnit launcher; validates destination origin, PHP >= 8.3, dependencies, and SQLite in-memory PHPUnit configuration; never fetches, pulls, clones, installs, switches branches, or resets files | GENERATED; NOT EXECUTED |
| `tests/Feature/FinanceCashFlowReportAcceptanceTest.php` | Cash-flow classification, disabled cash-equivalent account, date-window, evidence-note, and invalid-enum acceptance cases | GENERATED; NOT RUN |
| `tests/Feature/FinanceRouteAndMigrationDiscoveryTest.php` | Report route registration/middleware and canonical Finance migration discovery/order checks | GENERATED; NOT RUN |
| `mases_core/src/Domains/Finance/Services/JournalEntryService.php` | Canonical service now validates cash-flow activity enum, restricts classification to explicitly enabled cash-equivalent accounts, requires a non-empty evidence note for classified movements, validates note type/length | GENERATED; NOT RUN |
| `mases_core/src/Domains/Finance/Http/Requests/StoreJournalRequest.php` | Request contract requires a classification note whenever cash-flow activity is supplied | GENERATED; NOT RUN |
| `mases_core/src/Domains/Finance/Services/CashFlowReportService.php` | Rejects impossible ISO dates and reversed date windows even for direct service callers | GENERATED; NOT RUN |
| `docs/finance/ARNA-CASH-FLOW-CLASSIFICATION-v1.0.0.md` | Documents the enforced evidence-note and service validation contract | UPDATED |

No database migration or schema change was made in this unit. No control-account owner was appointed and no adapter was activated.

## 2. Why the cash-flow hardening was selected

The existing report intentionally refuses to infer operating/investing/financing classification from account codes or descriptions. This change extends that fail-closed principle to the journal write boundary:

- An unknown activity is rejected by the canonical service even if the HTTP FormRequest is bypassed.
- An activity cannot be attached to an account unless that account is explicitly marked as a cash equivalent.
- A classified cash movement must carry a non-empty evidence/rationale note (maximum 1,000 characters).
- The reporting service continues to expose unclassified movements as `undetermined`; it does not silently classify them.

This does **not** complete the IAS 7-style statement. Opening/closing balances, cash-equivalent reconciliation, FX presentation, consolidation, and policy review remain open roadmap requirements.

## 3. Local test execution contract

The runner is intended for an already-existing local checkout. It prints the local branch and SHA, discovers Finance-related test files, uses PHPUnit's isolated SQLite in-memory configuration, and returns PHPUnit's exit code. It does not establish that the local source matches this remote branch; the printed SHA must be checked before interpreting results.

To run it from PowerShell when the runner is already in the local checkout:

```powershell
Set-Location 'D:\MASES\github-current\arna-api'
.\tools\finance\Invoke-FinanceTests.ps1
```

To retrieve only the runner script (not the repository) and use an existing local checkout:

```powershell
$runner = Join-Path $env:TEMP 'Invoke-FinanceTests.ps1'
Invoke-WebRequest -Uri 'https://raw.githubusercontent.com/akefadaka-git/arna-api/feat/finance-phase0-baseline-local-test-runner/tools/finance/Invoke-FinanceTests.ps1' -OutFile $runner
& $runner -RepoPath 'D:\MASES\github-current\arna-api'
```

If the existing checkout does not contain the generated Finance source/tests, the runner will run the local files it finds; it does not fetch the branch. Therefore its output is not evidence for the remote branch unless the local SHA/source is confirmed.

## 4. Verification evidence and constraints

- GitHub source files were fetched/read for source-level review.
- The branch head `14c7fbcc5b29a499836264892bf849b0a4320734` was queried for workflow runs and commit statuses; both returned empty lists at query time.
- Commit messages include `[skip ci]`; no workflow was manually dispatched or rerun.
- No PHP syntax check, PHPUnit test, migration execution, seeder execution, database operation, production posting, merge, or deployment was performed.
- The branch has not been merged. No production readiness claim is made.

## 5. Remaining Phase 0 blockers

1. Finish exact PR ancestry and file-overlap reconciliation for PRs #39, #40, #42, #43, and #44; do not merge overlapping branches blindly.
2. Complete a repository-wide workflow inventory. Search found 18 workflow files containing `actions/checkout`, but this is not guaranteed to enumerate workflows that do not contain that token.
3. Confirm the exact local checkout SHA before the Owner runs the generated tests.
4. Keep all 36 control-account adapters blocked until each named accountable owner and reconciliation procedure are formally designated/approved.
5. Continue to treat external tax/accounting review as deferred and statutory compliance as unresolved.

**Next step:** complete the read-only PR ancestry/overlap inventory and finish mapping Finance routes, migration dependencies, test discovery, and workflow path filters. Then proceed with the next bounded ledger/reporting enhancement on the isolated branch. Do not run CI until the Owner authorizes it after quota availability returns.


## 6. Follow-up hardening and PR record

After the initial execution log was committed, source-level review identified and corrected two issues before asking the Owner to run tests:

- The PowerShell runner now creates its temporary PHPUnit cache directory only when execution begins, not during `-ListOnly` or discovery/validation failures.
- The disabled cash-equivalent acceptance fixture now leaves the cash-flow activity unclassified; assigning an activity to a disabled account is intentionally rejected by the new canonical-service invariant.

The change set is available in [draft PR #45](https://github.com/akefadaka-git/arna-api/pull/45), stacked on `feat/finance-accounting-completion-reporting`. PR #45 head at creation: `3d8731333f853267bdb081923e2a91903f3d07d0`; base: `f5359467d131aebbfc11c6806793b1e77cfad97d`. GitHub currently reports the PR as open/draft and `mergeable: false`; do not merge. The exact head was queried after PR creation and returned zero workflow runs and zero commit status checks. CI remains NOT RUN.

This follow-up does not close Phase 0. The full PR ancestry/file-overlap reconciliation and exhaustive workflow inventory remain open. All generated acceptance tests are still NOT RUN.


## 7. PR changed-file overlap evidence

A read-only changed-filename comparison was completed for PRs #39, #40, #42, #43, #44, and #45:

| PR | Changed files | Overlap finding |
|---|---:|---|
| #39 | 81 | Finance foundation and canonical CoA |
| #40 | 80 | **80 of 80 changed paths overlap PR #39**; the two PRs are near-duplicate change sets and must not both be merged blindly |
| #42 | 6 | Shares the Finance service-provider file with #39, #40, and #43 |
| #43 | 17 | Shares 10 paths with #39 and 9 with #40, including the controller, routes, report contract/service, journal service/request, provider, and Finance tests |
| #44 | 1 | Roadmap documentation only |
| #45 | 9 | Stacked on #43's reporting branch; overlaps #39/#40 on the journal service and request contract, and #43 on cash-flow docs plus those same source files |

This establishes file-level overlap, not identical content or merge safety. The complete reconciliation must still inspect the exact base/head SHAs and diffs for each PR, distinguish already-integrated versus unmerged commits, and determine the canonical branch sequence. PR #45 is an open draft. At creation GitHub reported `mergeable: false`; compare metadata shows the feature head descends from its feature base with zero commits behind, so refresh/reconcile the PR state before any future merge. Do not merge any of these PRs as part of Phase 0.

Workflow inventory remains search-derived: 18 files containing `actions/checkout` and 15 files with a `pull_request:` trigger were found on the observed main baseline. The examined workflows use named branches and/or path filters; none of the reviewed trigger declarations targeted the new PR's feature-branch base. This does not guarantee every repository automation has been enumerated. Latest reviewed head before this log update: `3d8731333f853267bdb081923e2a91903f3d07d0`; workflow-run and commit-status queries returned zero items. CI remains NOT RUN.


## 8. Additional acceptance coverage and private-repository runner retrieval

- Added two more cash-flow acceptance cases: reject classification on an account not enabled as a cash equivalent, and prove a report for one tenant does not include another tenant's cash movement.
- Updated the runner retrieval instructions to use authenticated `gh api` and decode only the PowerShell script file. This is necessary because the destination repository is private; the repository itself is never cloned or downloaded by the runner.
- Latest reviewed branch head before this log update: `329c5d9f22caf51d7be6f738116132f3183bebd2`. Workflow-run and commit-status queries returned zero items. Tests remain NOT RUN; no CI was invoked.


## 9. Exact branch graph update and reporting-service hardening

The PR ancestry comparison now identifies the principal branch heads:

| PR | Head branch / SHA | Base relationship observed | Status |
|---|---|---|---|
| #39 | `feat/finance-accounting-completion` / `8adb090c70158f8d4b22b1a28050722cffcac447` | Compared with current main `ed1a937456d2e240802a05ea729c902c0af6b06d`: 112 ahead, 7 behind; 81 files | Open |
| #40 | `feat/finance-accounting-reconciled-v1` / `179581588160b083812c7715953bb1a477f6074b` | Based on `e853874ee41130d47be07d2ffc097a41ecb69a9b`; 80 changed paths. Compared with current main: 3 ahead, 2 behind | Open |
| #42 | `feat/finance-phase3-year-end-close-readiness` / `5be4152e1a917aeb1e6b2ece1b056a89743c9746` | Directly based on #39 base/head `8adb090c70158f8d4b22b1a28050722cffcac447`; 14 commits ahead, 6 files | Open |
| #43 | `feat/finance-accounting-completion-reporting` / `f5359467d131aebbfc11c6806793b1e77cfad97d` | Directly based on `8adb090c70158f8d4b22b1a28050722cffcac447`; 32 commits ahead, 17 files | Open, draft |
| #44 | `docs/finance-accounting-enhancement-roadmap` / `3499624ff1bd5e12c918d11d5a88d2fbc67698f7` | Documentation-only branch based on `8adb090c70158f8d4b22b1a28050722cffcac447` | Open, draft |
| #45 | `feat/finance-phase0-baseline-local-test-runner` / current head `ef438e70578315da6da87c03950fbf2a36b8b960` | Based on #43 head `f5359467d131aebbfc11c6806793b1e77cfad97d`; 27 commits ahead, 0 behind, 10 changed files | Open, draft |

The #39/#40 overlap remains the main integration hazard: #40 has 80 changed paths, all 80 also occur in #39. #39's branch has 7 commits of divergence from current main, while #40 is 2 commits behind current main and 3 commits ahead of its recorded base. This evidence helps select an integration path but does not authorize merging either branch.

A further fail-closed reporting enhancement was generated:
- `CashFlowReportService` now rejects impossible `YYYY-MM-DD` dates and a start date later than the end date, even when invoked directly outside request validation.
- Two acceptance tests cover invalid calendar dates and reversed date windows.
- The cash-flow acceptance test file now contains nine cases; route/migration discovery has two cases. They remain NOT RUN.

Latest reviewed branch head: `ef438e70578315da6da87c03950fbf2a36b8b960`; workflow-run and commit-status queries returned zero items. CI/workflows remain NOT RUN.
