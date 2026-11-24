# ARNA Admin A3.0 Source Reconciliation and A3.1 Route Separation

**Work package:** ARNA-ADMIN-A3-WP-001 v1.0.0  
**Repository:** `akefadaka-git/arna-api`  
**Implementation branch:** `feat/admin-a3-route-separation`  
**Base branch:** `main`  
**Base SHA:** `40428db0a41d54397dd2aee482c9395f7950e9d0`  
**Status:** A3.0 VERIFIED; A3.1 PARTIAL — platform read routes implemented, tenant lifecycle routes deferred pending service work

## 1. Source reconciliation

The current `main` head was re-read before branch creation and remained `40428db0a41d54397dd2aee482c9395f7950e9d0`. The implementation branch was created directly from that SHA. No stale PR branch was merged or cherry-picked wholesale.

### PR #84 — platform read-only candidate

- Head: `0f8e3e6ac297a7e3dc43b232ab8de9dce6b7cbb5`
- Original base: `48542c59132b50d7405adb827afbb897051deb11`
- Compare to current main: diverged; 6 commits ahead, 602 behind; merge base `48542c59132b50d7405adb827afbb897051deb11`.
- Changed files: platform read controller, permission catalogue, A2 docs, and API routes.

**Selected:** the read-only `AdminControlPlaneController` implementation and the three dedicated global read permission catalogue rows.

**Modified from source:** routes were not copied verbatim. They were relocated under `/api/admin/platform/*` and use unique route names so that platform-wide reads cannot collide with tenant-scoped identity/RBAC routes.

**Not selected:** PR's original route paths and source branch history; its original route patch is not suitable unchanged because it shares path families with PR #85.

### PR #85 — tenant lifecycle candidate

- Head: `ef26a85531c5d4df9d6a32df36e368bcbf00e72a`
- Original base: `48542c59132b50d7405adb827afbb897051deb11`
- Compare to current main: diverged; 29 commits ahead, 602 behind; merge base `48542c59132b50d7405adb827afbb897051deb11`.
- Changed files: tenant Admin controller, membership service, routes, feature tests, membership tests, and a workflow.

**Not ported in A3.0/A3.1:** the PR's tenant lifecycle controller, membership service changes, route block, tests, and workflow. They are retained as review sources for later workstreams, not discarded.

Reasons:
1. Its route block duplicates the platform PR's `GET /api/admin/tenants` and `GET /api/admin/identity/users` method/path pairs.
2. Its controller includes tenant lifecycle mutations whose correctness depends on a membership service implementation that is not present on current main.
3. Its `attach()` implementation writes the membership inside a transaction but emits audit and security records after the transaction commits. A failure in required evidence persistence can leave an unaudited membership committed.
4. Its transition audit/security writes occur after the membership state transaction, unlike the current-main transition service's in-transaction security-event behavior.
5. Porting the controller without its dependent service contract would create runtime incompatibility; porting the service as-is would violate the approved atomicity requirement.

No PR #84 or PR #85 branch, metadata, review, or PR state was changed.

## 2. A3.1 implementation delivered

### Platform-wide read routes

Mounted under distinct namespaces:

| Method | Path | Permission |
|---|---|---|
| GET | `/api/admin/platform/identity/users` | `platform.identity.read` |
| GET | `/api/admin/platform/rbac/roles` | `platform.rbac.read` |
| GET | `/api/admin/platform/rbac/permissions` | `platform.rbac.read` |
| GET | `/api/admin/platform/tenants` | `platform.tenancy.read` |
| GET | `/api/admin/platform/tenants/{tenant}/memberships` | `platform.tenancy.read` |

Each endpoint requires Sanctum authentication and its dedicated global permission. These platform-wide read routes deliberately do not require tenant membership. Tenant-scoped `user.list`, `role.list`, and `permission.list` are not substituted for platform permissions.

### Permission catalogue

Added three global catalogue rows:
- `platform.identity.read`
- `platform.rbac.read`
- `platform.tenancy.read`

They are catalogue entries only. This change does not assign them to any role or user and does not create direct user grants.

### Tenant lifecycle route reservation

The approved tenant lifecycle namespace remains `/api/admin/tenant/*`. Its routes are not mounted in this increment because the tenant lifecycle controller and its audit-atomic membership/RBAC services are intentionally deferred to the corresponding A3 workstreams. The platform route implementation does not retain any ambiguous legacy route aliases.

## 3. Files changed on implementation branch

- Added `app/Http/Controllers/Admin/AdminControlPlaneController.php` (selected PR #84 read-only controller).
- Updated `routes/api.php` (new platform route namespaces; no PR #85 route block imported).
- Updated `database/seeders/Data/PermissionCatalogue.php` (three global read permission rows).
- Added `tests/Feature/AdminPlatformRouteOwnershipTest.php` (route-name and middleware-boundary assertions).

No migration, seeder execution, live database, deployment, merge, or PR closure occurred.

## 4. Verification status

- Branch base SHA verified: `40428db0a41d54397dd2aee482c9395f7950e9d0`.
- Source PR head SHAs and changed-file inventories reviewed.
- Route and permission code changes committed to the implementation branch.
- Feature test authored; execution: NOT RUN.
- PHP syntax checks: NOT RUN.
- Laravel route:list / duplicate-route inspection: NOT RUN.
- MySQL migration/seed verification: NOT RUN.
- CI workflow evidence: PASS — GitHub Actions run [45318150111](https://github.com/akefadaka-git/arna-api/actions/runs/45318150111), workflow `ARNA Executive Overview A4 API security verification`, completed successfully on intermediate branch commit `f5b9af864126d1ed16ae5681804cbf22022d723c`. This run was triggered by the permission-catalogue commit and is not the Admin route-ownership test; it predates the route test and final documentation commits. The Admin route test remains NOT RUN.

## 5. Next work

1. Execute PHP syntax and focused route ownership tests.
2. Inspect route:list for duplicate method/path registration and confirm the new route names/middleware.
3. Continue A3.2/A3.3: tenant/RBAC scope invariants, membership attach/transition atomic audit, and transactional security evidence.
4. Only then mount the tenant lifecycle routes under `/api/admin/tenant/*` with a controller compatible with current main.
5. Keep merge and deployment as separate Owner authorization gates.

**A3.0:** VERIFIED — selective source reconciliation complete.  
**A3.1:** PARTIAL — platform read-only route namespace implemented; tenant lifecycle namespace is defined but not yet mounted.  
**Merge/deployment:** NOT AUTHORIZED.
