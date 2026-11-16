# P0 People Domain Consolidation Report

- Report: PD-P0-RPT-001
- Version: 1.0.0
- Date: 2026-10-30
- Status: IN PROGRESS — NOT CLOSED
- Mode: Read-only repository assessment and documentation-only branch preparation.

## Executive result

The canonical ownership rule is established: People-specific code, migrations, seeders, tests, scripts, configuration, localization and documentation belong under the People root in each repository. Initial strategy, ownership register and reporting template have been created under the API People root on an isolated documentation branch.

P0 is not closed. The complete tracked-file/reference audit, migration and test discovery review, full requirements catalogue, security/privacy matrix and Owner closure review remain outstanding.

## Exact baselines

| Item | SHA / state |
|---|---|
| API main | `2915c10432208f0535ede2b7c3ea5a74d2dc7e99` |
| API PR #31 head | `536335d07294a2f373c047702dd886a0006cbac2` |
| API PR #31 vs current main | 183 ahead, 4 behind; diverged |
| API PR #31 | Open, unmerged; 77 files; +3,437 / -641; mergeable=false at inspection |
| App main | `7b04286d4a20b3587249eed8b90281699492b4c7` |
| App PR #5 head | `faf16e769756dcb1bc01afab69c418b881c8d980` |
| App PR #5 vs current main | 13 ahead, 0 behind |
| App PR #5 | Open, unmerged; 4 files; +731 / -7 |

PRs: https://github.com/akefadaka-git/arna-api/pull/31 and https://github.com/akefadaka-git/arna-app/pull/5

## Confirmed ownership gaps

API main contains People-specific artifacts outside `mases_core/src/Domains/People/`:
- `app/Http/Controllers/PersonController.php`
- People migrations under `database/migrations/`
- Person feature and MySQL concurrency tests under `tests/Feature/`
- `docs/phase-1/P1.2-UNIFIED-PEOPLE-PROFILE-COMPLETION-EVIDENCE.md`

API PR #31 additionally places these People-specific artifacts outside the root:
- `docs/phase-2/PEOPLE-DOMAIN-IMPLEMENTATION-REGISTER.md`
- `scripts/people/Invoke-ArnaPeopleLocalTests.ps1`
- `scripts/people/Sync-ArnaPeopleLocalRunner.ps1`

App PR #5 places `docs/phase-2/PEOPLE-APPLICATION-IMPLEMENTATION-REGISTER.md` outside `src/features/people/`.

These are classified for planned relocation, not moved. Before relocation, inspect imports, autoloading, route/provider discovery, migration history, test discovery, workflow paths and script references.

## Cross-domain changes in API PR #31

The PR includes Files controller/service changes, Organization files, shared authorization middleware, global permission catalogue and role grants, bootstrap provider registration and root API route mounting. Files and Organization changes are not People-owned. Bootstrap and root route files are minimal framework integration points only. These changes require separate ownership and regression disposition.

## Critical source-level path finding

The API PR #31 tree places Person identity/API/concurrency tests under `mases_core/src/Domains/People/Tests/Feature/`, while the inspected clean-install workflow still references legacy `tests/Feature/...` paths. Those legacy paths are absent from the candidate tree. This is a source-level discovery mismatch; no workflow was executed.

Previously inspected exact-head App PR #5 type-check/build runs were failed. No rerun was performed.

## P0 deliverables on the documentation branch

Branch: `docs/people-domain-p0-governance`

1. `mases_core/src/Domains/People/Docs/Architecture/PEOPLE-DOMAIN-MASTER-STRATEGY.md`
2. `mases_core/src/Domains/People/Docs/Architecture/PEOPLE-FILE-OWNERSHIP-REGISTER.md`
3. `mases_core/src/Domains/People/Docs/Reports/PEOPLE-PHASE-REPORT-TEMPLATE.md`

## P0 exit status

| Requirement | Status |
|---|---|
| Canonical API/App roots defined | VERIFIED |
| Eleven-phase roadmap and exit criteria defined | VERIFIED |
| Standard phase report defined | VERIFIED |
| Initial out-of-root inventory | PARTIAL |
| Exact repository baselines | VERIFIED |
| Full imports/autoload/routes/dependency audit | NOT RUN |
| Complete migration ledger/discovery audit | NOT RUN |
| Full workflow and test path audit | PARTIAL |
| Detailed requirements IDs and acceptance matrix | PARTIAL |
| Security/privacy/retention permission matrix | PARTIAL |
| Independent review and Owner closure | NOT RUN |

## Risk register

- Critical: API clean-install workflow references People test paths absent from PR #31 candidate.
- High: API PR #31 diverges from current main and includes cross-domain changes.
- High: People migrations, controller and tests remain outside the API People root on main.
- High: People documentation and scripts remain outside the API People root on PR #31.
- High: App People implementation register remains outside the feature root.
- High: App PR #5 previously failed exact-head frontend checks.
- High: contact verification, sensitive relationship/minor controls and retention require complete specifications.

## Actions performed / not performed

Repository reads and commit comparisons were performed. Three documentation files were created on a separate documentation branch. No application or backend source implementation was changed. No existing files were moved or deleted. No migrations, seeders, tests, workflows, database operations or deployments were run. Existing PR #31 and PR #5 were not modified, merged or closed.

## Disposition

P0 remains IN PROGRESS / PARTIAL. Complete the tracked-file and dependency/discovery audit, finalize the requirements and security matrices, and obtain Owner closure approval before starting P1 implementation. File relocation and all implementation changes remain separate authorized work.
