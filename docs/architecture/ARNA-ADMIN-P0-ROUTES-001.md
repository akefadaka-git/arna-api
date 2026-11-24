# ARNA Admin Control Plane — PR #84 / #85 Route Ownership Reconciliation

**Document ID:** ARNA-ADMIN-P0-ROUTES-001  
**Version:** 1.0.0  
**Status:** Owner-approved architecture baseline; implementation pending  
**Repository:** `akefadaka-git/arna-api`  
**Main baseline reviewed:** `40428db0a41d54397dd2aee482c9395f7950e9d0`  
**Related specification:** `docs/architecture/ARNA-ADMIN-P0-RBAC-001.md`

## 1. Executive decision

PR #84 and PR #85 are complementary in business intent but overlap in route ownership. Neither PR should be merged unchanged.

- **PR #84 owns platform-wide, read-only administration**: central identity directory, global RBAC catalogue visibility, tenant registry, and tenant membership visibility across tenants.
- **PR #85 owns tenant-scoped identity and RBAC lifecycle**: tenant member directory, identity profile/onboarding operations, membership state transitions, tenant role lifecycle, user-role assignments, and tenant-role permission synchronization.
- Shared domain services, models, authorization service, audit service, and security-event service remain canonical. There must be one implementation path for each mutation.
- Platform-wide and tenant-scoped APIs must have distinct URL namespaces and distinct permission gates.

This resolves the architectural route ownership conflict. It does not modify either existing PR.

## 2. Source evidence

Both PRs are open drafts and target `main`, but both were created against stale base SHA `48542c59132b50d7405adb827afbb897051deb11`, not the reviewed current-main SHA `40428db0a41d54397dd2aee482c9395f7950e9d0`.

| PR | Head SHA | Scope | Changed files |
|---|---|---|---:|
| #84 | `0f8e3e6ac297a7e3dc43b232ab8de9dce6b7cbb5` | Platform-wide read APIs | 4 |
| #85 | `ef26a85531c5d4df9d6a32df36e368bcbf00e72a` | Tenant identity/RBAC lifecycle | 6 |

### PR #84 evidence

Files:
- `app/Http/Controllers/Admin/AdminControlPlaneController.php`
- `database/seeders/Data/PermissionCatalogue.php`
- `docs/admin-control-plane/A2-CENTRAL-IDENTITY-RBAC-TENANCY.md`
- `routes/api.php`

Routes:
- `GET /api/admin/identity/users` — `platform.identity.read`
- `GET /api/admin/rbac/roles` — `platform.rbac.read`
- `GET /api/admin/rbac/permissions` — `platform.rbac.read`
- `GET /api/admin/tenants` — `platform.tenancy.read`
- `GET /api/admin/tenants/{tenant}/memberships` — `platform.tenancy.read`

PR #84 declares these endpoints platform-wide and intentionally outside tenant membership middleware. Its body states automated tests/CI were not run for that delivery.

### PR #85 evidence

Files:
- `app/Http/Controllers/Admin/AdminFoundationController.php`
- `mases_core/src/Tenancy/Services/TenantMembershipService.php`
- `routes/api.php`
- `tests/Feature/AdminFoundationLifecycleTest.php`
- `tests/Feature/TenantMembershipLifecycleTest.php`
- `.github/workflows/arna-membership-authorization-reconcile.yml`

Routes include:
- `GET /api/admin/tenants` and `GET /api/admin/tenants/{id}`
- Tenant-scoped `GET /api/admin/identity/users`
- Tenant-scoped identity creation/update and membership add/suspend/reactivate/revoke
- Tenant-scoped role create/update and role-permission synchronization
- Tenant-scoped user-role synchronization
- Tenant-scoped permission catalogue read

PR #85 uses `app.admin.access`, tenant context/membership, and tenant-scoped permissions. It intentionally does not expose direct per-user tenant permission grants because the current direct permission pivot does not carry tenant scope.

## 3. Exact route collisions

| Method + current path | PR #84 meaning | PR #85 meaning | Resolution |
|---|---|---|---|
| GET `/api/admin/identity/users` | Global user directory | Current-tenant user directory | Keep tenant route for #85; move #84 to platform namespace |
| GET `/api/admin/tenants` | Global tenant registry | Tenant directory under existing admin gate | Keep platform registry in #84; move #85 tenant-scoped admin directory to explicit tenant-admin namespace or make it a non-duplicate tenant registry endpoint with distinct path |
| GET `/api/admin/tenants/{id}` | No matching #84 detail route | Tenant detail | #85 may own tenant detail under tenant-admin namespace; no duplicate platform detail endpoint is introduced by this reconciliation |

The first two are exact method/path collisions. Middleware differences do not make duplicate route registration safe: route registration order can shadow or replace an endpoint, and clients cannot distinguish the two intended scopes.

## 4. Canonical route ownership and path contract

### 4.1 PR #84 — platform-wide read-only namespace

Move platform-wide endpoints under an explicit `/api/admin/platform` prefix:

| Method | Canonical path | Permission |
|---|---|---|
| GET | `/api/admin/platform/identity/users` | `platform.identity.read` |
| GET | `/api/admin/platform/rbac/roles` | `platform.rbac.read` |
| GET | `/api/admin/platform/rbac/permissions` | `platform.rbac.read` |
| GET | `/api/admin/platform/tenants` | `platform.tenancy.read` |
| GET | `/api/admin/platform/tenants/{tenant}/memberships` | `platform.tenancy.read` |

These routes are authenticated and permission-gated. They are deliberately outside tenant membership middleware because they are platform-wide. They must never trust a caller-supplied tenant header as authority. Tenant-specific membership detail is selected by a validated path tenant and protected by the platform permission.

The three `platform.*.read` permissions remain catalogue entries only. No role receives them implicitly. Owner-approved grants must be explicit and audited.

### 4.2 PR #85 — tenant-scoped administration namespace

PR #85 retains tenant-scoped lifecycle ownership. Use an explicit `/api/admin/tenant` namespace to make scope unambiguous and prevent overlap:

| Method | Canonical path | Authorization |
|---|---|---|
| GET | `/api/admin/tenant/identity/users` | `app.admin.access` + `tenant.membership` + `user.list` |
| POST | `/api/admin/tenant/identity/users` | `app.admin.access` + `tenant.membership` + `user.create` |
| PATCH | `/api/admin/tenant/identity/users/{id}` | `app.admin.access` + `tenant.membership` + `user.update` |
| POST | `/api/admin/tenant/identity/users/{id}/membership` | Tenant membership + `user.create` |
| PATCH | `/api/admin/tenant/identity/users/{id}/membership` | Tenant membership + `user.update` |
| POST | `/api/admin/tenant/identity/users/{id}/membership/revoke` | Tenant membership + `user.delete` |
| GET | `/api/admin/tenant/authorization/roles` | Tenant membership + `role.list` |
| POST | `/api/admin/tenant/authorization/roles` | Tenant membership + `role.create` |
| PATCH | `/api/admin/tenant/authorization/roles/{id}` | Tenant membership + `role.update` |
| PUT | `/api/admin/tenant/authorization/roles/{id}/permissions` | Tenant membership + `role.assign_permissions` |
| PUT | `/api/admin/tenant/identity/users/{id}/roles` | Tenant membership + `user.assign_roles` |
| GET | `/api/admin/tenant/authorization/permissions` | Tenant membership + `permission.list` |

The existing PR #85 tenant directory/detail endpoints must be placed under `/api/admin/platform/tenants` only if they are truly global and use the platform tenancy permission; otherwise move them under a distinct tenant-admin registry path and define the intended caller scope. Do not retain the ambiguous duplicate `GET /api/admin/tenants`.

**Compatibility:** These are target paths for A3. Existing clients and route consumers must be searched before path changes. If a compatibility alias is required, it must be a single explicit route with one documented authorization meaning; never expose the same method/path with two controllers or middleware stacks.

## 5. Controller and service ownership

| Component | Owner | Rule |
|---|---|---|
| `AdminControlPlaneController` | Platform Admin read surface | Read-only queries only; global platform permissions |
| `AdminFoundationController` | Tenant Admin lifecycle surface | Tenant-context-bound queries/mutations only |
| `AuthorizationService` | Shared authorization decision | Sole permission evaluator |
| `TenantContextMiddleware` / `TenantContextService` | Tenant context | Sole request tenant authority |
| `TenantMembershipService` | Membership lifecycle | Sole membership transition service |
| Role/permission domain services | Shared RBAC mutation | One transaction/audit/cache invalidation path |
| `AuditService` and security-event service | Shared evidence | Consequential mutations fail closed if required audit cannot persist |

No controller may duplicate authorization logic or write membership/role pivots directly where a canonical service exists.

## 6. Security and correctness findings

### 6.1 PR #85 audit atomicity defect — blocking

The inspected `TenantMembershipService::attach()` performs the membership insert inside `DB::transaction()`, then emits the security event and audit log after that transaction has committed. Its status transition path likewise performs the state transition before the subsequent audit/security writes.

This does not meet the approved contract requiring mutation and required audit evidence to commit or roll back atomically. A failure in audit persistence can leave an unaudited membership mutation committed.

**Required A3 correction:** include required audit persistence in the same database transaction as the membership state change. Ensure security-event persistence semantics are explicit; if it is a required audit artifact, persist it in the transaction or use a transactional outbox. External delivery/notifications occur after commit.

### 6.2 Other PR #85 review gates

- Validate actor and target tenant membership under lock before every mutation.
- Verify target role belongs to current tenant and is active/not soft-deleted.
- Preserve platform/application role assignments during tenant-role synchronization.
- Enforce grant ceiling and scope compatibility using canonical AuthorizationService/domain rules.
- Revoke/disable behavior must be consistent with the approved membership lifecycle contract.
- Ensure audit actor ID is the actor, not the affected member; inspect all audit payloads.
- Cache invalidation must be targeted and must not leave stale-positive permissions.
- No direct user permission mutation is added to tenant scope.
- No platform role or system permission mutation is exposed through tenant APIs.

### 6.3 PR #84 review gates

- Add focused feature tests for each global read endpoint and its permission boundary.
- Verify platform read permissions are not automatically granted by seeders.
- Verify a tenant-scoped permission cannot access a platform-wide route.
- Verify sensitive authentication fields are excluded from serialization.
- Verify tenant path parameter is validated and membership listing cannot leak across path tenants without the global permission.
- Reconcile controller query behavior against current-main model relationships and schema.

## 7. PR disposition

| PR | Disposition | Action |
|---|---|---|
| #84 | RETAIN AS PLATFORM READ-ONLY WORKSTREAM | Rebase/reconcile against current main; move routes to `/api/admin/platform/*`; add tests; preserve separate global read permissions |
| #85 | RETAIN AS TENANT LIFECYCLE WORKSTREAM | Rebase/reconcile against current main; move tenant routes to `/api/admin/tenant/*`; fix audit atomicity; validate role scope/cache/grant ceiling; run full tests |

Do not merge either PR as currently drafted. Do not close or retarget the existing PRs automatically. First create an A3 implementation branch from current `main`, port only reviewed changes, preserve unrelated commits, and keep the old PRs as historical candidates until Owner-directed cleanup.

## 8. Verification acceptance criteria

- Route:list contains exactly one registration per method/path.
- No platform-wide route is guarded only by tenant-scoped permissions.
- No tenant route bypasses tenant membership and trusted tenant context.
- Platform and tenant route tests prove mutual isolation.
- All mutations use canonical services.
- Required audit writes roll back the business mutation on failure.
- Security event/outbox behavior is tested.
- Cache revocation tests prove no stale-positive access.
- PHP syntax, targeted feature tests, full relevant suite, and clean-install MySQL tests pass.
- Test evidence is tied to the exact candidate head SHA.

## 9. Change-control

This is a route-ownership and architecture reconciliation record. It does not modify PR #84 or #85, their branches, or `main`. It does not implement routes or controllers, run migrations, seed a database, merge, or deploy.

**Route ownership decision:** platform-wide read-only = PR #84; tenant-scoped lifecycle = PR #85.  
**Implementation state:** NOT STARTED.  
