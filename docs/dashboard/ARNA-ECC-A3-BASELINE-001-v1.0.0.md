# ARNA Executive Command Centre — A3.0 Live Baseline and Implementation Evidence Register

**Control ID:** ARNA-ECC-A3-BASELINE-001  
**Version:** 1.0.0  
**Assessment date:** 24 November 2026  
**Owner:** Akefa Ayele Daka  
**Authority:** ARNA-ECC-A3-PLAN-001 v1.0.0 and ARNA-ECC-A3-AUTH-001 v1.0.0  
**Stage:** A3.0 — execution baseline and safeguards  
**Status:** LIVE REPOSITORY RECONCILIATION RECORDED; A3.0 EVIDENCE REGISTER ESTABLISHED  
**Mode:** Read-only inspection plus documentation-only record creation. No application code, migrations, production configuration, permissions, or `main` branch changed.

## 1. Authorization boundary

The Owner authorized P0 implementation work packages A3.0–A3.3 only. This register executes A3.0 and establishes traceability for subsequent authorized P0 work.

- A3.1 KPI governance foundation: authorized, not started by this record.
- A3.2 shared provider contract: authorized, not started by this record.
- A3.3 bounded authorization/security controls: authorized, not started by this record.
- A3.4–A3.8: NOT AUTHORIZED.
- Merge to `main`: NOT AUTHORIZED.
- A4 acceptance and A5 deployment: separate gates; NOT AUTHORIZED by this record.

This document is evidence of repository inspection, not evidence that any P0 application implementation has been completed.

## 2. Authoritative repositories and exact baseline

The destination repositories under `akefadaka-git` were reached through the connected GitHub account. Both repository metadata responses identify `main` as the default branch and show repository write/admin permissions for the connected account.

| Field | API | App |
|---|---|---|
| Repository | `akefadaka-git/arna-api` | `akefadaka-git/arna-app` |
| Visibility | Public | Public |
| Default branch | `main` | `main` |
| Live `main` SHA | `6e274c25ecc55b84e026e879d735b77f0e51592f` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` |
| Commit date | 2026-11-24 05:07:23 UTC | 2026-11-23 11:34:32 UTC |
| Commit subject | `docs(progress): nightly ARNA repository assessment` | `Fix strict workflow handoff summary reporting` |
| Commit signature | Unverified / unsigned | Unverified / unsigned |
| Branch protected | No | No |
| Required status checks | None configured | None configured |

These exact SHAs are the A3.0 baseline anchors. Any implementation branch must declare these as its base or explicitly document a newer live base and repeat reconciliation before coding. Historical evidence from other SHAs must not be represented as current-main verification.

## 3. Repository structure and technology baseline

### 3.1 API

Observed root structure includes `app/`, `bootstrap/`, `config/`, `database/`, `docs/`, `mases_core/`, `routes/`, `scripts/`, `tests/`, `composer.json`, `composer.lock`, `package.json`, and `phpunit.xml`.

Manifest evidence at the API baseline:
- PHP constraint: `^8.3`.
- Laravel Framework: `^12.0`.
- Laravel Sanctum: `^4.0`.
- PHPUnit: `^11.5`.
- Frontend asset toolchain includes Vite 8 and Tailwind CSS 4.
- Composer test script clears configuration cache and invokes `php artisan test`.

Executive Overview implementation anchors observed:
- `app/Http/Controllers/ExecutiveOverviewController.php`
- `mases_core/src/Authorization/ExecutiveOverviewAccessPolicy.php`
- `mases_core/src/Listeners/InvalidateAuthorizationCacheListener.php`
- `tests/Feature/ExecutiveOverviewAuthorizationTest.php`
- `tests/Feature/ExecutiveOverviewA4FreshAuthorizationTest.php`
- `tests/Feature/ExecutiveOverviewRouteTest.php`
- `.github/workflows/executive-overview-a4-api.yml`

The controller exposes the existing `GET /api/dashboard/v1/overview` contract. The observed provider keys are:
- `finance.trial_balance`
- `people.workforce.active`

The controller performs tenant-context checks, an Executive Overview audience check, current-pivot permission checks for Finance and People, and legal-entity-scoped Finance reads. This confirms an existing foundation, not a complete enterprise provider catalogue.

### 3.2 App

Observed root structure includes four separate entry points: `workspace-entry/`, `portal-entry/`, `admin-entry/`, and `public-entry/`, with shared `src/`, `tests/`, `scripts/`, `package.json`, and `vite.config.ts`.

Manifest evidence at the App baseline:
- Node engine: `>=20.0.0`.
- npm engine: `>=10.0.0`.
- Vue/TypeScript/Vite application.
- `type-check` uses `vue-tsc --noEmit`.
- Four static build targets are defined: workspace, portal, admin and public.
- Aggregate build uses `build:all`; production outputs are static assets.

Executive Overview implementation anchors observed:
- `src/features/executive-overview/ExecutiveOverviewView.vue`
- `src/features/executive-overview/api.ts`
- `src/features/executive-overview/service.ts`
- `src/features/dashboard/DashboardView.vue`
- `tests/executive-overview-route-contract.test.mjs`
- `tests/executive-overview-security.spec.mjs`
- `.github/workflows/executive-overview-a4-app.yml`

The Executive Overview is a distinct feature from the generic `/dashboard` feature launcher. The frontend currently models the Finance trial-balance and People active-workforce widgets and clears/reloads sensitive view state when identity or tenant context changes.

## 4. CI and verification evidence at the exact baseline

### 4.1 API

- Combined commit status endpoint for `6e274c25ecc55b84e026e879d735b77f0e51592f`: no status entries.
- Actions query filtered to the exact `main` SHA: zero workflow runs.
- Latest nearby successful API workflow evidence is attached to parent commit `40428db0a41d54397dd2aee482c9395f7950e9d0`, not the live A3.0 SHA:
  - [Nightly Repository Assessment — run 45331003675](https://github.com/akefadaka-git/arna-api/actions/runs/45331003675)
  - [Clean-install MySQL verification — run 45158067784](https://github.com/akefadaka-git/arna-api/actions/runs/45158067784)
- Those runs are historical/parent-SHA evidence only and do not certify the current API baseline.

**API current-main verification status: NOT RUN / NO EXACT-SHA WORKFLOW EVIDENCE.**

### 4.2 App

Two completed successful Actions runs are attached to the exact live App `main` SHA:
- [Frontend verification — run 45166431549](https://github.com/akefadaka-git/arna-app/actions/runs/45166431549)
- [Prepare public website package for Plesk — run 45166431331](https://github.com/akefadaka-git/arna-app/actions/runs/45166431331)

The combined commit status endpoint returned no status entries, although the Actions API reports these two successful runs. The workflow evidence is exact-SHA scoped to the App baseline.

**App exact-main workflow status: PASS for the two listed workflows only.** This is not an app-wide accessibility or A4 certification.

### 4.3 Migrations and production

- No migration was run as part of A3.0.
- No production database, production grant, production configuration or live customer data was accessed.
- Migration status for A3.0: NOT RUN / NOT APPLICABLE.
- Production readiness: NOT ASSESSED.

## 5. Open PR and branch collision inventory

The open-PR inventory was read from GitHub's first API page (`per_page=100`) for each repository. The API returned 100 records on that page, so its total open-PR count is at least 100 and the page is not an exhaustive count. The App returned 76 open PRs in the response. Branch-list requests returned 100 branches for each repository; branch totals may exceed 100. This register does not claim exhaustive enumeration beyond the returned pages.

### 5.1 API PRs material to Executive Command Centre / P0

| PR | Head branch / SHA | Base | Baseline observation |
|---|---|---|---|
| [#217](https://github.com/akefadaka-git/arna-api/pull/217) | `docs/arna-ecc-a3-plan-001` / `a7ed9cce064e859f9c10d4e26966f7fdfcc71d8f` | `main` | Documentation-only A3 plan and P0 authorization; remains open. |
| [#201](https://github.com/akefadaka-git/arna-api/pull/201) | `feat/a3-executive-overview-trial-balance` / `e54c7cca8979016308c74b4a070d85daf71ebef1` | `main` at `630405b097daa5d66ab1b76a3ac8a1841e5000ab` | Older A3.1 contract candidate; GitHub reports `mergeable_state=dirty`; stale base. |
| [#202](https://github.com/akefadaka-git/arna-api/pull/202) | `feat/a3-2-workforce-aggregate` / `bc950b8d1bd5a7e93af82141151650116eb437a2` | PR #201 feature branch | Dependent older workforce candidate; not based on live `main`. |
| [#214](https://github.com/akefadaka-git/arna-api/pull/214) | `feat/admin-a3-route-separation` / `d6eb1b5244fc7933472359403ae1a86853491a52` | live `main` SHA | Separate Owner-approved Admin A3 tenant-RBAC/audit lifecycle work; preserve and avoid overlap. |
| [#216](https://github.com/akefadaka-git/arna-api/pull/216) | `feat/communication-people-contracts-a3` / `5d1fe749f15bf3d7aa3b103897b651228ccd644d` | PR #214 feature branch | Separate tenant-scoped Communication/People contracts; preserve. |

Other open API work includes migration lifecycle, identity/session, security, Finance and multiple domain branches. Do not close, rebase, merge, rewrite, or delete any of these as part of ECC A3.0.

### 5.2 App PRs material to Executive Command Centre / P0

| PR | Head branch / SHA | Base | Baseline observation |
|---|---|---|---|
| [#45](https://github.com/akefadaka-git/arna-app/pull/45) | `feat/executive-overview-a3` / `a5aea3a25f2412afa06d9d397a4ac08804eb6b31` | `main` at `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f` | Older Executive Overview candidate; stale base and `mergeable_state=dirty`. |
| [#104](https://github.com/akefadaka-git/arna-app/pull/104) | `feat/a3-executive-overview-trial-balance` / `1d72752722953381eb3de27a69280e78f670ce57` | `main` at `2b210babedfe088b71cb9e11dbb87b33446d6903` | Older A3.1 frontend contract candidate; stale base. |
| [#105](https://github.com/akefadaka-git/arna-app/pull/105) | `feat/a3-2-workforce-aggregate` / `3a38438f0cfe7436cc2bae6a02b9eb7c31754146` | PR #104 feature branch | Dependent older workforce UI candidate. |
| [#107](https://github.com/akefadaka-git/arna-app/pull/107) | `reconcile/executive-overview-current-main-2026-11-23` / `f042b5f63bb0517ec6f1954c44b294724aa631ad` | `main` at `2b210babedfe088b71cb9e11dbb87b33446d6903` | Reconciliation candidate, but base predates live App main. |

Other open App work includes Admin RBAC, UI design-system, accessibility, Finance, Calendar, Android and public-site work. Preserve all unrelated branches and PRs.

## 6. Existing exact-SHA verification records and limitations

The API A4 security verification document on the live API tree records a feature-branch A4 baseline `9f5ea1ee0e221bca5007890178f249cdba20c992` and a separate A3 parent `6e8ca660d35caf9fe5e8d66ee34406bf642f2de1`. The App A4 frontend verification document records A4 baseline `ea2c432ecf1d8d0f02ee7f58984bf8341f8a2bf7` and A3 parent `fc5d87fd4ed97a2b5102ffefbad894c261f0d6f2`.

Those A4 closure statements are limited to their named feature branches and exact SHAs. They do not certify the current `main` heads above, do not close `RISK-A4-01` platform-wide, and do not authorize merge or deployment.

The API cache invalidation listener explicitly documents a 300-second authorization cache and catches/logs invalidation exceptions. Its documented failure mode can retain stale cached positive authorization until TTL expiry. The Executive Overview controller has scoped fresh-pivot checks for its own audience and Finance/People providers. These scoped mitigations do not establish a platform-wide cache fix. Preserve `RISK-A4-01` as OPEN pending bounded A3.3 assessment and exact regression evidence.

## 7. Controlled implementation boundaries

The following controls are binding for P0 implementation planning:

1. Use a dedicated ECC work branch based on the live API/App baseline or record an explicit refreshed base SHA.
2. Do not implement ECC work on PR #201/#202/#214/#216 or App #45/#104/#105/#107 branches without a separately documented reconciliation and conflict analysis.
3. Preserve PR #217 and its documentation-only purpose; this A3.0 evidence register is maintained on its own branch and PR.
4. Do not change either `main` branch, branch protection, Actions settings, repository permissions, secrets, environments or production configuration under this baseline task.
5. Preserve canonical RBAC tables: `roles`, `permissions`, `user_has_roles`, `user_has_permissions`, `role_has_permissions`.
6. Keep dashboard audience authorization distinct from individual widget permissions. Keep `people.workforce.view` independently gated.
7. Keep Finance tenant and legal-entity scoping. No consolidated Finance KPI without an approved accounting/consolidation policy.
8. No wildcard grants, unsupported KPI definitions, production data reads, migrations, merge, deployment or paid-service commitments.
9. Keep unrelated changes intact. No branch deletion, PR closure, rebase, merge or force-push is included.

## 8. A3.0 evidence register and status

| ID | Required evidence / control | Status | Evidence / disposition |
|---|---|---|---|
| A30-01 | Confirm authoritative API repository and default branch | PASS | Repository metadata; `main` |
| A30-02 | Confirm authoritative App repository and default branch | PASS | Repository metadata; `main` |
| A30-03 | Record exact live API `main` SHA | PASS | `6e274c25ecc55b84e026e879d735b77f0e51592f` |
| A30-04 | Record exact live App `main` SHA | PASS | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` |
| A30-05 | Record API/App technology and root structure | PASS | Live manifests and recursive trees |
| A30-06 | Inventory material open ECC/P0 PRs | PASS | PRs #201/#202/#214/#216/#217 and App #45/#104/#105/#107 |
| A30-07 | Preserve unrelated work and frozen A1/A2 baselines | PASS | Read-only inspection; no existing work altered |
| A30-08 | Capture exact-SHA Actions and status evidence | PASS | API: zero runs at exact SHA; App: two successful exact-SHA runs |
| A30-09 | Verify branch protection and required checks | PASS — CONTROL GAP FOUND | Both `main` branches unprotected; no required checks |
| A30-10 | Establish controlled implementation evidence register | PASS | This record |
| A30-11 | Current-main API test suite | NOT RUN | No exact-SHA API workflow run |
| A30-12 | Current-main App test suite beyond recorded workflows | NOT RUN | No additional test execution in this task |
| A30-13 | Migration execution | NOT RUN / NOT APPLICABLE | No migration in A3.0 |
| A30-14 | Production verification | NOT RUN / OUT OF SCOPE | No production access or changes |
| A30-15 | A3.0 implementation branch and code change | NOT STARTED | This task is baseline/documentation only |

### Overall A3.0 assessment

- Live repository identity and exact mainline SHAs: VERIFIED.
- Material ECC/P0 branch and PR collision inventory: RECORDED; API listing is page-limited.
- App exact-main CI: two recorded workflow successes.
- API exact-main CI: missing.
- Repository protection: control gap on both repositories.
- Code, migration and production changes: none.

**A3.0 status: PARTIAL — baseline and evidence register established; exact-main API verification and exhaustive pagination remain open.**

A3.0 is not marked VERIFIED-CLOSED until the API current-main test evidence is obtained or explicitly dispositioned, pagination limitations are resolved or accepted, and the implementation branch boundary is recorded before first code change.

## 9. Immediate next actions

1. Reconcile the API exact-main workflow gap using an available no-cost GitHub Actions route; do not assume billing availability or rerun paid services.
2. Complete paginated branch/PR inventory only to the extent needed to identify conflicts with the dedicated ECC workstream; retain the current page-limit caveat until then.
3. Record the dedicated implementation branch and exact base SHAs before any A3.1–A3.3 code changes.
4. Proceed with A3.1 KPI ownership/readiness register only within the approved P0 scope; no P1/P2 dashboard expansion.

## 10. Change record

| Version | Date | Change |
|---|---|---|
| 1.0.0 | 24 November 2026 | Initial live API/App baseline, exact-SHA CI inventory, material PR collision register, governance safeguards and A3.0 evidence status. |

**Governance statement:** This record does not alter `main`, merge PRs, close or rewrite existing work, execute migrations, change production systems, authorize A3.4–A3.8, or grant A4/A5 authority.
