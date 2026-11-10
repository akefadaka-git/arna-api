# ARNA Admin Central Control Plane — A2 Read-only Reconciliation Report

**Document ID:** ARNA-ADM-CP-A2-RR-001  
**Version:** 1.0.2  
**Review date:** 10 November 2026  
**Status:** A2 evidence review completed; schema authority and integration decisions remain open  
**Owner:** Akefa Ayele Daka  
**Canonical contract:** [A2 contract v1.1.0](./ARNA-ADMIN-CENTRAL-CONTROL-PLANE-A2-CONTRACT.md)

> This report records repository evidence only. No application source, database, PR branch, or production environment was modified. It does not authorize A3 implementation, migration, merge, or deployment.

---

## 1. Executive result

The current destination branches have moved substantially since the identity/RBAC/tenant PRs were opened. The PRs are stale and must not be merged or used as integration bases without controlled reconciliation.

The most important finding is now more precise than the original A1 summary:

**The September 1 Owner-approved design did not require deleting or replacing Laravel's standard RBAC tables. It required retaining both table families, with the tenant-aware `custom_*` RBAC family serving as the MASES authorization source for cross-application, API and ARNA access, while the Laravel-standard tables remained untouched for compatibility.**

Current `arna-api/main` uses a different RBAC persistence arrangement: `roles`, `permissions`, `role_has_permissions`, `user_has_roles`, and `user_has_permissions`. Repository code search did not find the approved `custom_roles`, `custom_permissions`, `custom_user_roles`, `custom_user_permissions`, or `custom_role_permissions` implementation. This is an authority and persistence mismatch, not merely a naming difference.

A2 cannot close the schema gate from repository source alone. The live database and historical Migration 002 artifact were not available through the connected GitHub repository interface. No database access, migration, or data inspection was attempted.

## 2. Exact repository snapshot

| Repository | Current main SHA | Evidence |
|---|---|---|
| `akefadaka-git/arna-api` | `36c8d42ceb3647eeb3a8859841a8377e9be9dd7c` | Current main commit and API PR base |
| `akefadaka-git/arna-app` | `76fb67a421b1a54e0529a3479e551a1d1e0c0d0f` | Current main commit and App PR base |

The repository default branch is `main` for both repositories. These are the exact A2 comparison bases used in this review.

## 3. Historical Owner-approved architecture — corrected interpretation

The September 1, 2026 database inspection recorded the coexistence of:
- One central `users` table and one `tenants` table.
- Laravel-standard `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, and `role_has_permissions`.
- MASES custom RBAC tables `custom_roles`, `custom_permissions`, `custom_user_roles`, `custom_user_permissions`, and `custom_role_permissions`.
- Application/ARNA integration tables including `platform_application_tenants` and `arna_tool_permissions`.

The September 1 Owner decision was to **retain both systems for compatibility** and designate the tenant-aware custom RBAC family as the MASES authorization source for cross-application/API/ARNA access. The Laravel-standard tables were to remain untouched.

The historical `custom_user_roles` assignment shape included:
- `user_id`
- `role_id`
- `tenant_key`
- `assigned_at`
- `assigned_by`

Existing assignments were associated with the `default` tenant key. The earlier tenant identity design also treated `users.tenant_key` as the primary/home-tenant context. These semantics must not be collapsed into the separate many-to-many tenant membership relation.

Historical migration evidence recorded:
- Migration 001 succeeded with `platform_applications` = 6, `platform_application_tenants` = 6, `arna_tools` = 13, `arna_tool_permissions` = 8, guest tables = 0, and `schema_migrations` = 1.
- A later Migration 002 outcome was reported with `tenant_memberships` = 42, `platform_application_roles` = 68, `platform_application_users` = 0, `tenant_context_audit` = 0, `arna_tool_permission_map` = 12, and `custom_permissions` = 67.

These are historical reported counts, not live database evidence for this review. The exact Migration 002 DDL and current database rows still require retrieval and verification before any migration decision.

## 4. Current API main schema and model evidence

The current main migration sequence contains:

| Migration | Current main table / purpose |
|---|---|
| `2026_09_07_000002_create_users_table.php` | Creates `users` with `default_tenant_id` |
| `2026_09_07_000003_create_tenant_memberships_table.php` | Creates `tenant_memberships`, composite primary key `(tenant_id, user_id)` |
| `2026_09_07_000004_create_platform_applications_table.php` | Creates `platform_applications` |
| `2026_09_07_000005_create_roles_table.php` | Creates `roles` with `platform`, `tenant`, and `application` scopes |
| `2026_09_07_000006_create_permissions_table.php` | Creates `permissions` with `global`, `tenant`, and `application` scopes |
| `2026_09_07_000007_create_role_has_permissions_table.php` | Creates `role_has_permissions` |
| `2026_09_07_000008_create_user_has_roles_table.php` | Creates `user_has_roles` |
| `2026_09_07_000009_create_user_has_permissions_table.php` | Creates `user_has_permissions` |

Current model bindings:
- `User` uses `users.default_tenant_id`, the `tenant_memberships` relation, and `user_has_roles` / `user_has_permissions`.
- `Role` maps to `roles`, with `scope`, `tenant_id`, and `application_id`; role permissions use `role_has_permissions`.
- `Permission` maps to `permissions`, with `scope`, `tenant_id`, and `application_id`.
- `AuthorizationService` evaluates direct user permissions through `user_has_permissions` and role-derived permissions through `user_has_roles` → `role_has_permissions`.

Repository code search on current main did not find the historical custom RBAC table names or the historical `platform_application_roles`, `platform_application_users`, `tenant_context_audit`, and `arna_tool_permission_map` tables. The current source has `platform_applications` and application-scoped roles through `roles.application_id`, but that is not evidence of semantic equivalence to the historical application mapping tables.

### 4.1 Tenant context behavior

Current `RequestTenantResolver` checks `X-Tenant-ID` as a tenant selector before hostname resolution. This selector is not, by itself, authorization: `TenantMembershipMiddleware` resolves the tenant and verifies the authenticated user has an active membership in it before allowing tenant-scoped requests.

Current source therefore distinguishes request tenant selection from membership authorization. The outstanding conflict is the historical home-tenant field `users.tenant_key` versus the current `users.default_tenant_id`; these must not be assumed equivalent without the original migration/schema evidence and a defined compatibility mapping.

### 4.2 A2 schema conclusion

- **Historical authority:** custom RBAC remains the previously Owner-approved MASES cross-application/API/ARNA authorization source; Laravel-standard tables remain retained and untouched.
- **Current implementation:** current main's active model/service path is based on `roles` and `permissions` with project-specific pivots.
- **Disposition:** **UNRESOLVED / BLOCKING** until the historical custom schema and current database are reconciled. Do not dual-write, reseed, backfill, drop, rename, or silently redirect authorization reads.
- **Required next evidence:** exact Migration 002 DDL, current database schema and migration ledger, table counts, keys/indexes, orphan/duplicate analysis, existing role/grant mappings, and the actual active production connection's schema version. No such live DB evidence is available in this read-only GitHub review.

## 5. API PR reconciliation

### 5.1 Current PR state and divergence

| PR | Head SHA | Base at PR creation | Ahead of current main | Behind current main | State |
|---|---|---|---:|---:|---|
| API #84 | `0f8e3e6ac297a7e3dc43b232ab8de9dce6b7cbb5` | `48542c59132b50d7405adb827afbb897051deb11` | 6 | 80 | Open / draft |
| API #85 | `ef26a85531c5d4df9d6a32df36e368bcbf00e72a` | `48542c59132b50d7405adb827afbb897051deb11` | 29 | 80 | Open / draft |

Both branches diverge from current main at `48542c59132b50d7405adb827afbb897051deb11`. Neither branch is a safe current-main integration base. Do not merge either independently.

### 5.2 API #84 — platform read-only proposal

PR #84 adds read-only platform directory APIs and three explicit permissions:
- `platform.identity.read`
- `platform.rbac.read`
- `platform.tenancy.read`

Its controller uses current `User`, `Role`, `Permission`, `Tenant`, and `TenantMembership` models. The endpoint paths include:
- `GET /api/admin/identity/users`
- `GET /api/admin/rbac/roles`
- `GET /api/admin/rbac/permissions`
- `GET /api/admin/tenants`
- `GET /api/admin/tenants/{tenant}/memberships`

The explicit permission split is directionally useful. However:
1. It uses current Laravel-standard persistence models, not the historical custom RBAC authority.
2. Its paths do not follow the frozen v1.1.0 platform namespace.
3. `GET /admin/identity/users` and `GET /admin/tenants` collide with tenant/admin routes proposed in PR #85.
4. Its PR description records automated tests/CI as not run at delivery.

**Disposition:** preserve as a read-only design reference; port only after schema and route contract reconciliation. Platform-wide directory endpoints must use the explicit `/admin/platform/*` namespace in the frozen contract.

### 5.3 API #85 — tenant identity and RBAC lifecycle proposal

PR #85 adds tenant-scoped identity/membership/RBAC lifecycle endpoints and tests. It uses:
- `AdminFoundationController`
- Current `User`, `Role`, `Permission`, and `AuthorizationService`.
- `tenant_memberships`, `user_has_roles`, and `role_has_permissions`.
- Tenant membership middleware and tenant-scoped permissions.
- Auditing, security events, and cache invalidation.

Positive behaviors present in the branch:
- Identity creation and initial membership are wrapped in a transaction.
- Tenant membership is checked for target users.
- Revoked membership is treated as terminal.
- Role assignment is restricted to active tenant roles.
- A privilege ceiling is checked against permissions held by the actor.
- Platform roles/system permissions are excluded from tenant mutation.
- Direct per-user tenant permission endpoints and account-wide suspension are not exposed.
- API PR #85 head workflow runs were retrieved and all ten returned runs were completed successfully, including MySQL clean-install, tenant isolation, effective authorization audit, membership reconciliation, MFA, phone verification, and related regression gates.

Blocking findings:
1. It mutates the current `roles` / `permissions` model family and `user_has_roles` / `role_has_permissions` pivots, not the previously approved custom RBAC authority.
2. Its `GET /admin/identity/users` route collides with PR #84's platform-global read endpoint. Its `GET /admin/tenants` also collides with PR #84's platform directory endpoint.
3. Its tenant-scoped profile update mutates the shared central `users` row. The exact field ownership and cross-tenant impact of email/phone/name changes need explicit policy.
4. `TenantMembershipService::attach()` commits the membership insertion in its transaction, then emits audit/security records afterward. For the standalone add-membership path, a logging failure can leave a persisted membership without its required audit/security evidence. Identity creation wraps the service call in an outer transaction, but the standalone membership operation does not. The membership write and required audit evidence must share a failure-safe transaction/outbox design.
5. The privilege-ceiling check and subsequent role mutation need a concurrency/TOCTOU test and a defined locking or grant-version strategy.
6. The successful PR-head CI runs do not validate compatibility with the historical custom RBAC tables or the live production schema.

**Disposition:** strongest tenant-lifecycle implementation reference, but **NOT ACCEPTED** as canonical A2 implementation. Reconcile its persistence layer, endpoint collision, shared-identity profile policy, audit atomicity, and concurrency controls first.

## 6. API route contract reconciliation

The frozen A2 contract intentionally separates:
- Platform-global read-only directory APIs under `/api/admin/platform/*`, guarded by explicit `platform.*.read` permissions.
- Tenant-scoped identity and RBAC lifecycle APIs under `/api/admin/identity/*` and `/api/admin/authorization/*`, guarded by tenant context and existing tenant-scoped permissions.
- Existing global tenant directory semantics under `tenant.list`; do not silently replace it with `platform.tenancy.read`.

Required route disposition:

| Capability | Required A2 path | Permission / scope |
|---|---|---|
| Platform identity directory | `GET /api/admin/platform/identity/users` | `platform.identity.read`, platform |
| Platform role catalogue | `GET /api/admin/platform/rbac/roles` | `platform.rbac.read`, platform |
| Platform permission catalogue | `GET /api/admin/platform/rbac/permissions` | `platform.rbac.read`, platform |
| Existing tenant directory | `GET /api/admin/tenants` | `tenant.list`, global as already defined |
| Platform membership directory | `GET /api/admin/platform/tenants/{tenant}/memberships` | `platform.tenancy.read`, platform |
| Tenant identity lifecycle | `/api/admin/identity/users` | `user.*`, resolved tenant |
| Tenant RBAC lifecycle | `/api/admin/authorization/roles` and permissions | `role.*` / `permission.list`, resolved tenant |

The endpoint matrix must be refreshed against current main before any implementation. Route grouping and API prefixes must follow current Laravel conventions.

## 7. Frontend PR reconciliation

| PR | Head SHA | Base at PR creation | Ahead of current main | Behind current main | State |
|---|---|---|---:|---:|---|
| App #36 | `ff05ecff36004914a30cbca9b68d7efed5aaea49` | `ca97c1ac0152c9b2a5dcf562b52575c0070b1d30` | 4 | 72 | Open / draft |
| App #37 | `e3a92a7ec256bc27c9895776d33d21535940295f` | `ca97c1ac0152c9b2a5dcf562b52575c0070b1d30` | 11 | 72 | Open / draft |

Both branches are based on a stale App main commit and diverge from current main.

### 7.1 App #36

PR #36 is a read-only Admin UI proposal for platform identity/RBAC/tenant directories. It uses the global platform permissions and the #84 endpoint paths. It has no delivered frontend build/test evidence in its PR description and does not provide the later tenant-scoped lifecycle controls.

**Disposition:** use only as a read-only UI reference; update its API paths to the frozen `/admin/platform/*` contract and reconcile it against current main.

### 7.2 App #37

PR #37 provides the more complete tenant-scoped Admin UI, including tenant/user/role/permission views and membership/RBAC mutations. Its PR-head frontend verification workflow completed successfully.

The branch does not preserve the current main `/admin/audit-security` route and audit/security sidebar entry. It does contain the corporate-site route; current main also contains that route. Current main's sidebar has audit/security and public-content entries; it does not have a separate corporate-site sidebar entry. Therefore the exact preservation requirement is:
- Preserve current main's corporate-site route.
- Preserve current main's audit/security route **and** audit/security sidebar entry.
- Preserve public-content review route and sidebar entry.
- Add the new tenant-scoped Admin navigation without replacing those current entries.

PR #37's guard change to inspect every matched route record is a useful candidate because the Admin parent and child may both carry permission metadata. Retain it only after tests prove parent `app.admin.access` and each child permission are both enforced.

**Disposition:** preferred tenant-scoped UI reference, not merge-ready. Rebase/port only after API route/schema contract reconciliation and current-main preservation checks.

## 8. Review and CI evidence

- No submitted PR reviews, inline review threads, or PR comments were returned for API #84/#85 or App #36/#37 in this read-only check.
- API #85: all ten workflow runs returned for its exact head completed successfully. Historical run links include:
  - [MySQL clean-install](https://github.com/akefadaka-git/arna-api/actions/runs/41728884242)
  - [Tenant isolation](https://github.com/akefadaka-git/arna-api/actions/runs/41728883869)
  - [Effective authorization audit](https://github.com/akefadaka-git/arna-api/actions/runs/41728884204)
  - [Membership/authorization reconciliation](https://github.com/akefadaka-git/arna-api/actions/runs/41728884051)
- App #37: [Frontend verification](https://github.com/akefadaka-git/arna-app/actions/runs/41728377171) completed successfully.
- API #84 and App #36 PR descriptions state their automated tests/builds were not run at delivery.
- These checks are attached to old PR heads. They do not prove compatibility with current main, the Owner-approved custom RBAC schema, or production database state.

## 9. A2 package disposition

| Package | Result | Evidence / remaining condition |
|---|---|---|
| A2.1 Exact repository and PR snapshot | PASS | Exact current main SHAs and PR refs recorded |
| A2.2 Historical/current schema reconciliation | PARTIAL / BLOCKING | Current source mismatch identified; live DB and Migration 002 DDL unavailable |
| A2.3 Tenant context and authorization flow | PARTIAL | Header is a selector; active membership middleware validates tenant access; custom RBAC authority still unresolved |
| A2.4 Route and permission collision reconciliation | PASS AS DESIGN / NOT IMPLEMENTED | Required platform namespace split defined; competing PR paths conflict |
| A2.5 Membership lifecycle and audit | PARTIAL | Terminal revocation and tenant-scoped lifecycle exist in #85; standalone attach audit atomicity needs correction |
| A2.6 Frontend routes/navigation | PARTIAL | #37 is stale and omits current audit/security route/navigation |
| A2.7 Threat model and adversarial tests | PARTIAL | Existing checks pass on old head; concurrency, audit-failure, schema compatibility and current-main regressions remain |
| A2.8 A2 closure package | BLOCKED | Schema authority and live DB evidence gates remain open |

## 10. Binding recommendations for the next stage

1. Preserve the September 1 Owner decision: custom tenant-aware RBAC is the MASES cross-application/API/ARNA authorization source; retain Laravel-standard tables untouched for compatibility.
2. Do not treat current main's `roles` / `permissions` model as implicit supersession. Design a compatibility/reconciliation adapter only after the exact historical schema and live database are verified.
3. Resolve `users.tenant_key` versus `users.default_tenant_id` without conflating home tenant, membership, and active request tenant.
4. Map historical application/ARNA authority tables to current `platform_applications` and `application_id` role design; do not assume equivalence.
5. Move platform-global read APIs to `/admin/platform/*`; preserve tenant-scoped mutation paths and the existing `tenant.list` directory semantics.
6. Correct membership audit atomicity and add failure-injection tests.
7. Define shared central identity profile field ownership and cross-tenant effects.
8. Add concurrency tests for grant-ceiling validation and role mutation.
9. Reconcile App #37 against current main, preserving the corporate-site route and audit/security route plus navigation.
10. Keep all four implementation PRs open and unmerged until separately dispositioned. No migration, source implementation, merge, or deployment is authorized by this report.

## 11. Final A2 status

- Repository-level A2 evidence review: **COMPLETE**
- Historical custom-RBAC authority: **CONFIRMED AS PRIOR OWNER DECISION**
- Current-source compatibility with that authority: **UNRESOLVED / BLOCKING**
- Home-tenant field mapping: **UNRESOLVED / BLOCKING**
- Platform/application role mapping: **UNRESOLVED / BLOCKING**
- Live database schema and migration ledger: **NOT VERIFIED**
- API route collision disposition: **DEFINED, NOT IMPLEMENTED**
- Frontend route/navigation integration: **DEFINED, NOT IMPLEMENTED**
- A2 formal closure: **NOT CLOSED**
- A3 implementation: **NOT AUTHORIZED**
- A4: **NOT STARTED**
- A5: **NOT AUTHORIZED**

**Next permitted action:** obtain the exact historical Migration 002 DDL and a read-only current database schema/migration-ledger export, then complete the canonical schema mapping and return the unresolved home-tenant/application mapping decision for Owner review. Until that evidence exists, stop before code or database changes.

*End of A2 reconciliation report.*


---

## 12. Supplemental historical artifact review — v1.0.1 correction

A further read-only search of the user's Library located two historical artifacts:
- `Pasted text(7).txt`: a captured Migration 002 SQL execution transcript/script, `002_identity_tenant_context`, version 1.0.0.
- `maale_arna_smart_system(1).sql`: a historical database dump containing the pre-existing custom RBAC schema and data.

This corrects the earlier statement that no Migration 002 artifact was available: a **partial captured Migration 002 script is available in the Library**, but its extracted content contains omitted sections (`[...]`) and is not a complete executable DDL source. The SQL dump is historical schema evidence, not a current production database export. Neither artifact proves the current live database state.

### 12.1 Confirmed historical custom RBAC structures

The historical SQL dump confirms:
- `custom_roles`: `id`, `name`, `description`, `hierarchy_level`, `created_at`, `updated_at`.
- `custom_permissions`: `id`, `slug`, `description`, timestamps.
- `custom_user_roles`: `user_id`, `role_id`, `tenant_key` (default `default`), `assigned_at`, `assigned_by`; composite primary key `(user_id, role_id, tenant_key)`.
- `custom_user_permissions`: `user_id`, `permission_id`, `tenant_key` (default `default`), `granted_at`, `granted_by`; composite primary key `(user_id, permission_id, tenant_key)`.
- `custom_role_permissions`: `role_id`, `permission_id`, `tenant_key` (default `default`), `granted_at`; composite primary key `(role_id, permission_id, tenant_key)`.

The same historical dump contains the Laravel-standard `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, and `role_has_permissions` tables. This directly supports the September 1 Owner decision to preserve both table families and keep the custom tenant-aware family authoritative for MASES cross-application/API/ARNA authorization.

### 12.2 Confirmed Migration 002 structural intent

The captured Migration 002 script states that `users.tenant_key` remains the primary/home tenant and adds a separate membership relation so one identity can belong to multiple tenants. Its visible DDL specifies:
- `tenant_memberships` with string `tenant_key`, `user_id`, membership type (`owner`, `admin`, `staff`, `shareholder`, `customer`, `member`, `guest`), and membership status (`invited`, `active`, `suspended`, `revoked`), plus primary-membership and joined-at fields in the visible seed contract.
- `platform_application_roles` with `tenant_key`, `application_id`, `role_id`, access level (`deny`, `read`, `standard`, `manager`, `admin`), and `is_enabled`.
- `platform_application_users` for explicit per-user application overrides, with tenant/application/user identifiers, allow/deny type, access level, reason, expiry, and grantor.
- `arna_tool_permission_map` linking ARNA tool IDs to MASES custom permission IDs per tenant.
- `tenant_context_audit` recording request ID, tenant key, user/application context, context type, allow/deny outcome, reason, IP address, and timestamp.

The visible Migration 002 script uses `custom_roles` and `custom_permissions` for application access permissions and role-to-application mapping. It distinguishes role-derived application access from explicit per-user application overrides. The script's omitted portions prevent treating this captured transcript as a complete migration source; obtain the complete approved SQL/DDL before any implementation or replay.

### 12.3 Stronger comparison against current API main

The historical schema evidence adds two material differences beyond the earlier summary:

1. **Tenant key versus numeric tenant ID:** historical `users.tenant_key`, `custom_user_roles.tenant_key`, `custom_user_permissions.tenant_key`, `custom_role_permissions.tenant_key`, and Migration 002 `tenant_memberships.tenant_key` use string tenant keys. Current API main uses numeric `tenant_id` foreign keys in `tenant_memberships`, `roles`, and `permissions`.
2. **Application access semantics:** historical `platform_application_roles` stores tenant-specific role-to-application access levels and enablement; `platform_application_users` stores explicit allow/deny exceptions with expiry and reason. Current API main has `platform_applications` and application-scoped roles via `roles.application_id`, but no source evidence of equivalent application-role access levels or per-user allow/deny override behavior.

The earlier A2 schema disposition remains **UNRESOLVED / BLOCKING**, but the historical design intent is now better evidenced. The correct next step is a mapping/compatibility decision, not a wholesale replacement or silent numeric-ID conversion.

### 12.4 Updated A2 evidence disposition

- Historical custom RBAC table structures: **EVIDENCE FOUND** in Library SQL dump.
- Historical Migration 002 design intent: **PARTIALLY VERIFIED** from captured script.
- Complete approved Migration 002 DDL: **MISSING / INCOMPLETE CAPTURE**.
- Current API main schema: **VERIFIED FROM REPOSITORY MIGRATIONS/MODELS**.
- Current live database schema and migration ledger: **NOT VERIFIED**.
- Mapping from historical string tenant keys to current numeric tenant IDs: **UNRESOLVED**.
- Application access-level and per-user override mapping: **UNRESOLVED**.
- Production migration or data mutation: **NOT PERFORMED**.

*End of supplemental v1.0.1 correction.*


---

## 13. Non-destructive schema mapping assessment — v1.0.2

The historical SQL dump and Migration 002 capture establish that the mismatch is structural, not just a difference between `tenant_key` and `tenant_id`.

| Historical approved structure | Current API main structure | Material difference |
|---|---|---|
| `custom_roles`: role name and hierarchy level | `roles`: slug, scope, tenant_id, application_id, active state | Tenant/application scope moved from assignment/application maps into role rows; role hierarchy is not represented equivalently |
| `custom_permissions`: slug and description | `permissions`: slug, scope, tenant_id, application_id, system/category metadata | Scope and tenant/application ownership are modelled differently |
| `custom_user_roles`: user_id + role_id + tenant_key | `user_has_roles`: user_id + role_id; tenant scope is on the Role row | Tenant dimension moved out of the assignment key |
| `custom_role_permissions`: role_id + permission_id + tenant_key | `role_has_permissions`: role_id + permission_id | Tenant dimension moved out of the permission assignment pivot |
| `custom_user_permissions`: user_id + permission_id + tenant_key | `user_has_permissions`: user_id + permission_id | Tenant dimension is absent from the current direct-permission pivot |
| `users.tenant_key` | `users.default_tenant_id` | String home-tenant key versus numeric FK |
| `tenant_memberships.tenant_key` | `tenant_memberships.tenant_id` | String tenant key versus numeric FK; historical membership type/primary flag differ |
| `platform_application_roles` | `roles.scope='application'` and `roles.application_id` | Current model does not demonstrate historical tenant-specific access_level/enablement semantics |
| `platform_application_users` | No equivalent current source path found | Explicit per-user application allow/deny, access level, expiry, reason and grantor are not represented |
| `arna_tool_permission_map` | No equivalent current source path found | ARNA tool-to-MASES permission authorization mapping is not represented |
| `tenant_context_audit` | Generic audit/security event services | No verified field-for-field or retention-equivalent mapping |

### 13.1 Required mapping rules

1. Resolve each historical `tenant_key` to exactly one canonical tenant record. Reject missing, duplicate, ambiguous, or inactive mappings; never infer a mapping from display names.
2. Do not map role or permission records by numeric ID across the two schema families. IDs are independently allocated. Use reviewed stable identifiers (permission slug; role name-to-canonical-slug mapping) and record every ambiguous alias.
3. Preserve the tenant dimension for role assignments and role-permission grants. A mapping that drops `tenant_key` can broaden grants across tenants and is unacceptable.
4. Preserve the historical application access level, enabled/disabled state, explicit user allow/deny override, expiry, reason, and grantor semantics where those records exist.
5. Preserve ARNA tool-to-permission mapping and tenant-context audit semantics; generic audit logs are not automatically equivalent.
6. Keep `custom_user_permissions` records intact. The later Owner deferral of direct per-user tenant permission operations remains binding: this A2 Admin slice must not expose a new mutation endpoint for them.
7. Keep Laravel-standard `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, and `role_has_permissions` untouched as the historical compatibility requirement specifies.
8. Do not dual-write or mirror grants between the custom and current model families until a single canonical write authority, consistency/repair protocol, cache behavior, and rollback boundary are approved.

### 13.2 Recommended architecture disposition

The previously approved design remains the authority: MASES/ARNA cross-application and API authorization reads must resolve from the tenant-aware `custom_*` family. The current `AuthorizationService` and the #84/#85 controller proposals currently resolve through the newer `roles`/`permissions` model family and therefore cannot be accepted unchanged.

The preferred non-destructive implementation direction, subject to a later A3 authorization, is a single authorization service backed by the approved custom RBAC tables, with explicit tenant-key resolution and narrowly scoped adapters for any existing application code that still reads the current Laravel-style tables. This is not permission to start that refactor. First obtain the complete approved Migration 002 source and a read-only live schema/data inventory; then produce a row-level mapping and parity test plan for Owner acceptance.

### 13.3 Additional A2 gate status

- Historical/current RBAC structural comparison: **COMPLETED**
- Canonical authorization authority: **CONFIRMED — historical Owner decision**
- Tenant-key-to-tenant-ID mapping: **UNRESOLVED**
- Role and permission crosswalk: **UNRESOLVED**
- Application access-level and user-override crosswalk: **UNRESOLVED**
- ARNA tool-permission mapping: **UNRESOLVED**
- Tenant-context audit equivalence: **UNRESOLVED**
- Current live database state and Migration 002 execution status: **NOT VERIFIED**

*End of schema mapping assessment.*
