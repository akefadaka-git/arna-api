# ARNA Admin Control Plane — Current-main RBAC and Tenant Authorization Contract

**Document ID:** ARNA-ADMIN-P0-RBAC-001  
**Version:** 1.0.0  
**Status:** OWNER-APPROVED SPECIFICATION; IMPLEMENTATION NOT STARTED  
**Repository:** `akefadaka-git/arna-api`  
**Baseline branch:** `main`  
**Baseline commit:** `40428db0a41d54397dd2aee482c9395f7950e9d0`  
**Owner decision:** Current API `main` RBAC schema is the authoritative starting point for fresh installation.

> This document freezes the approved architecture and implementation specification. It does not itself implement migrations, seeders, authorization changes, merge, or deployment.

---

## 1. Purpose and scope

Define the canonical RBAC and tenant authorization model for ARNA Admin Control Plane and specify the fresh-install migration and deterministic seeder requirements.

The database is intended to be initialized from the beginning using migrations and seeders. Access to an existing live database schema or migration ledger is **not required**.

The current API `main` schema is the target starting point. The historical RBAC table family is not to be reconstructed as a parallel runtime system.

## 2. Binding Owner decisions

| ID | Decision | Disposition |
|---|---|---|
| D01 | Current API `main` RBAC schema is canonical starting point | OWNER-APPROVED |
| D02 | Do not reconstruct historical `custom_*` RBAC tables as target | OWNER-APPROVED |
| D03 | Use numeric `tenant_id` as canonical database tenant reference | APPROVED CONTRACT |
| D04 | Retain current five RBAC tables and relationships | APPROVED CONTRACT |
| D05 | Tenant membership is a separate prerequisite from role assignment | APPROVED CONTRACT |
| D06 | Use application-scoped roles and permissions for application-specific authorization | APPROVED CONTRACT |
| D07 | Keep direct-permission table, but seed no direct grants and disable routine direct assignment initially | APPROVED CONTRACT |
| D08 | Current schema does not implement explicit user-level deny overrides | SCHEMA LIMITATION |
| D09 | Consequential authority mutations require attributable, transactionally consistent audit evidence | APPROVED CONTRACT |
| D10 | Fresh-install seeders must be deterministic, idempotent, and non-destructive | APPROVED CONTRACT |

## 3. Canonical RBAC schema

Retain these current-main tables:

| Table | Responsibility |
|---|---|
| `roles` | Platform, tenant, and application role definitions |
| `permissions` | Atomic permission definitions |
| `role_has_permissions` | Role-to-permission grants |
| `user_has_roles` | User-to-role assignments |
| `user_has_permissions` | Optional direct user permission grants |

Do not create a parallel `custom_roles`, `custom_permissions`, or historical pivot family.

### 3.1 Roles

The current-main role model includes identity and bilingual display fields, stable `slug`, description, scope, optional `tenant_id`, optional `application_id`, active state, soft deletion, and timestamps.

Allowed role scopes:

- **platform:** platform-wide; no tenant or application binding.
- **tenant:** bound to exactly one tenant.
- **application:** bound to exactly one registered application.

A role with a scope/binding mismatch must be rejected. Role slugs are stable natural keys.

### 3.2 Permissions

The current-main permission model includes stable `slug`, bilingual names and descriptions, category, scope, optional tenant/application bindings, `is_active`, `is_system`, soft deletion, and timestamps.

Permission scopes are `global`, `tenant`, and `application`. Scope describes where the permission can be exercised; it does not replace tenant membership, application context, or resource-level authorization.

Examples include `organization.unit.view`, `organization.unit.manage`, `finance.journal.create`, and `finance.report.view`.

### 3.3 Role-permission grants

`role_has_permissions` is the canonical role grant pivot. Its composite identity is role plus permission; `assigned_at` records assignment time.

Scope compatibility rules:

- Platform role: global permissions only.
- Tenant role: global permissions plus permissions bound to that same tenant.
- Application role: global permissions plus permissions bound to that same application.

Cross-tenant and cross-application grants must fail closed. Validate in the domain service and reinforce with database constraints where the selected database engine supports the required invariant.

### 3.4 User-role assignments

`user_has_roles` is the canonical user-role pivot, with `assigned_at` and `assigned_by`. Duplicate user/role assignments are prohibited.

The pivot has no tenant column. Tenant applicability is derived from the role's scope and tenant binding, together with the resolved request tenant and independently verified membership. A tenant-scoped role assignment never grants membership in that tenant.

### 3.5 Direct user permissions

Keep `user_has_permissions` because it is part of the selected current-main schema and is consulted by the current authorization service.

Current API documentation records that this pivot is deliberately empty in the existing MASES deployment and that role-derived grants are the active grant path.

Fresh-install policy:

- Create the table.
- Seed no direct user grants.
- Disable routine direct-grant administration initially.
- Permit future use only through a separately controlled, Owner-approved policy and audited workflow.
- Do not treat a direct grant as a deny override.

The current schema does not represent explicit deny entries. Do not simulate deny semantics through conflicting role grants. Any deny/exception mechanism requires a separately approved schema and authorization design.

## 4. Tenant authorization contract

### 4.1 Canonical tenant identity

Numeric `tenant_id` is the canonical database identity. A string `tenant_key` may exist only as an external identifier/slug if required; it must resolve to the canonical tenant row and must not become a competing authorization foreign key.

The tenant record is the source of truth for tenant identity.

### 4.2 Tenant membership

`tenant_memberships` is the authoritative user-to-tenant membership relation. Current-main schema evidence includes `tenant_id`, `user_id`, `joined_at`, `settings`, timestamps, and composite uniqueness on tenant/user. The current-main membership status evolution must be retained in the fresh-install migration sequence.

Membership is independent of RBAC. A role assignment never substitutes for membership.

### 4.3 Authorization sequence

For tenant-required operations:

1. Authenticate the user.
2. Resolve tenant context from a trusted source.
3. Require a valid, active, non-deleted tenant.
4. Verify active membership for the authenticated user.
5. Evaluate the requested permission in the resolved tenant and application context.
6. Apply resource/domain policy checks.
7. Enforce governance authority or maker-checker requirements for consequential actions.
8. Persist required audit/security evidence.

Any missing, invalid, ambiguous, or indeterminate prerequisite results in denial.

### 4.4 Tenant-context resolution

Use the existing `TenantContextMiddleware`, `TenantContextService`, and resolver architecture as the source reference.

Accepted request context must be validated and resolved to the canonical tenant row, including a validated `X-Tenant-ID` reference or a validated tenant subdomain where configured.

The request body must not establish authoritative tenant context.

Clear any prior tenant context before resolving a new request, especially in long-lived workers. Tenant-required routes must reject missing or invalid tenant context. Resolver errors must not silently permit execution.

Queued jobs, console commands, and other non-HTTP execution must receive explicit trusted tenant context or an explicitly configured trusted default. Never infer authority from untrusted payload data.

### 4.5 Membership state

Use the current-main membership status contract and its status migration. Inactive, revoked, suspended, or otherwise invalid membership cannot authorize tenant access. If membership status cannot be established, deny.

### 4.6 Permission scope evaluation

Permission evaluation must receive the permission slug, user, tenant context, and application context where relevant.

- Global permissions are not tenant-bound.
- Tenant permissions apply only to their designated tenant.
- Application permissions apply only to their designated application.
- A global permission does not waive record ownership, tenant policy, domain policy, or governance authority.

### 4.7 Application access

Use the current `platform_applications` registry and application-scoped roles/permissions. Application-specific access is not implied merely by platform authentication.

Where access levels such as viewer, operator, manager, or administrator are needed, express them through documented application roles and permission sets. Do not use undocumented numeric levels or display-name inference. Do not introduce a parallel access table unless an evidenced requirement cannot be represented by the current model.

## 5. Authorization precedence and failure behavior

The target authorization policy is:

1. Authentication is required for protected operations.
2. Trusted tenant context is required for tenant-required operations.
3. Active tenant membership is required for tenant-scoped operations.
4. The permission must exist, be active, and match the requested scope.
5. A valid role-derived grant may authorize the permission.
6. A direct user grant may authorize only when the separately controlled direct-grant feature is enabled.
7. Resource-level and domain-specific policies remain mandatory.
8. Formal Governance authority and maker-checker checks remain mandatory where applicable.
9. Failure or uncertainty at any required step denies access.

Revocation must remove the effective grant. A revoked role or permission must not remain effective because of stale cache state.

## 6. Authorization cache requirements

The current `AuthorizationService` caches permission results using user, permission, tenant, and application context. The source documents a 300-second permission-result cache and a per-user cache-key registry.

Every role, permission, direct-grant, or relevant membership mutation must:

- Run inside a database transaction.
- Persist required audit evidence.
- Invalidate affected authorization cache entries.
- Invalidate related lookup caches using targeted invalidation.
- Ensure a failed invalidation cannot preserve stale-positive/elevated access.

The current implementation documents a hazard in `clearApplicationCache()` because it flushes the whole application cache. Do not use that as the normal targeted mutation invalidation mechanism. A3 must verify revocation behavior and stale-positive cache failure modes.

## 7. Governance and audit atomicity

The following are consequential mutations: role/permission creation or deactivation; role-permission grants; user-role assignment/revocation; direct grant assignment/revocation; scope changes; membership state changes; and application access changes.

Required transaction pattern:

1. Begin database transaction.
2. Verify actor authority and target state.
3. Validate tenant and application scope.
4. Apply the mutation.
5. Persist an audit record containing actor, action, target, scope, timestamp, correlation/request identifier, and reason.
6. Commit mutation and audit evidence together.
7. Invalidate affected authorization caches.
8. Dispatch external notifications/events after commit.

If required audit persistence fails, the authority mutation must roll back. Audit data must not contain passwords, tokens, secrets, or authentication factors.

Maker-checker applies where required. A person must not automatically approve their own consequential action. Owner authority remains separately governed; ordinary administrator seeding must not implicitly grant Owner authority.

## 8. Fresh-install migration specification

### 8.1 Principles

- Use Laravel's flat `database/migrations/` directory and normal migration ledger.
- Retain current-main migrations that already satisfy the contract; do not duplicate them.
- Add only migrations for verified gaps or explicit approved hardening.
- Order migrations by foreign-key dependency.
- Define primary keys, unique constraints, foreign keys, and indexes explicitly.
- Enforce cross-table scope invariants in services and tests where database constraints cannot express them.
- Each `down()` reverses only its own migration.
- Never drop unrelated data or silently replace current-main tables.
- Live database schema/ledger access is not a prerequisite.

### 8.2 Existing current-main RBAC migration references

- `2026_09_07_000005_create_roles_table.php`
- `2026_09_07_000006_create_permissions_table.php`
- `2026_09_07_000007_create_role_has_permissions_table.php`
- `2026_09_07_000008_create_user_has_roles_table.php`
- `2026_09_07_000009_create_user_has_permissions_table.php`

These are the baseline migration references, not instructions to create duplicate migrations.

### 8.3 Fresh-install dependency sequence

| Order | Responsibility | Dependencies |
|---:|---|---|
| M01 | Tenants | None |
| M02 | Users and canonical identity | Tenant only if current FK requires it |
| M03 | Tenant memberships | Tenants, users |
| M04 | Platform applications | None |
| M05 | Roles | Tenants, applications |
| M06 | Permissions | Tenants, applications |
| M07 | Role-permission pivot | Roles, permissions |
| M08 | User-role pivot | Users, roles |
| M09 | User-permission pivot | Users, permissions |
| M10 | General audit logs | Users and relevant references |
| M11 | Security events | Users and relevant references |
| M12 | File metadata | Tenants/users as required |
| M13+ | Membership status, security, and authorization hardening | Relevant core tables |

The actual current-main migration filenames and dependency graph must be preserved and checked before any A3 changes. This table describes logical dependency order, not a request to rename or duplicate existing migrations.

### 8.4 Integrity requirements

Verify:

- Unique role slug and permission slug.
- Unique tenant/user membership.
- Unique user/role assignment.
- Unique role/permission assignment.
- Unique user/direct-permission assignment.
- All foreign keys resolve.
- Role scope/binding consistency.
- Permission scope/binding consistency.
- No cross-tenant or cross-application role-permission grants.
- No assignment to inactive or soft-deleted roles.
- No effective grant through inactive or soft-deleted permissions.
- No orphan tenant/application references.

## 9. Deterministic seeder specification

### 9.1 Objectives

Seeders must be deterministic, idempotent, ordered, non-destructive, scope-aware, auditable, Amharic-first, safe to rerun, and free of embedded production secrets.

Ordinary re-seeding must not delete users, reset credentials, or detach existing assignments.

### 9.2 Seeder sequence

| Order | Seeder/catalogue | Responsibility |
|---:|---|---|
| S01 | TenantSeeder / TenantProfile | Create or verify canonical initial tenant |
| S02 | ApplicationSeeder / ApplicationRegistry | Register platform applications |
| S03 | PermissionSeeder / PermissionCatalogue | Seed core permission definitions |
| S04 | Domain permission catalogues | Append Finance, Documents, Governance, and other domain-owned permissions |
| S05 | RoleSeeder / RoleCatalogue | Seed canonical role definitions |
| S06 | RolePermissionSeeder / RolePermissionGrants | Apply approved role-permission grants |
| S07 | Explicit bootstrap identity process | Create/verify separately authorized Owner identity |
| S08 | Explicit bootstrap role assignment | Assign Owner authority only to verified identity |
| S09 | Seeder verification | Validate expected catalogues, scope, and absence of unintended grants |

The current `MasesCoreSeeder` and its extracted catalogue/seeders are source references. Its destructive full-flow behavior must not be reused as the ordinary repeatable seeding contract.

### 9.3 Catalogue rules

Each permission definition includes stable slug, Amharic and English names, descriptions, category, scope, and system-critical flag.

Each role definition includes stable slug, bilingual names/descriptions, scope, and tenant/application binding directive.

Each role-permission grant references role and permission slugs. Validate all references and scope compatibility before writing grants.

Unknown slugs, incompatible scopes, missing required applications, or invalid mandatory grants must fail visibly. Security-critical grants must never be silently skipped.

Domain teams own their permission catalogues; central seeding composes them without duplicating definitions.

### 9.4 Idempotency and non-destructive behavior

- Use stable slugs as natural keys.
- Use safe upsert behavior for catalogue records.
- Use duplicate-safe pivot insertion.
- Do not depend on auto-increment insertion order.
- Do not delete roles or permissions merely because a newer catalogue omits them.
- Do not detach all assignments during ordinary seeding.
- Do not overwrite passwords, MFA factors, or credentials during re-seeding.
- Do not seed direct user permissions by default.
- Do not seed privileged access for an unverified identity.
- Do not log or print bootstrap credentials.
- Validate the complete grant graph before success.
- Catalogue retirement/removal requires a separately reviewed reconciliation process.

### 9.5 Owner bootstrap

The initial Owner identity must be established through an explicit environment-specific bootstrap procedure:

1. Verify the intended identity.
2. Generate or supply initial credentials through a secure environment-specific mechanism.
3. Persist only credential hashes.
4. Never place plaintext credentials in source, fixtures, logs, or CI output.
5. Record the initial Owner assignment with attributable audit evidence.
6. Require password-reset/credential-establishment flow before ordinary administration.
7. Never infer Owner authority from first registration or arbitrary user creation.

Ordinary administrator seed data must not automatically receive Owner authority.

### 9.6 Seed completion assertions

A successful seed run must verify:

- Expected initial tenant count and identity.
- Expected application registry entries.
- All required permission slugs.
- All required role slugs.
- All required role-permission grants.
- No scope-incompatible assignments.
- No unexpected direct grants.
- No duplicate pivot rows.
- Verified Owner identity and assignment only if explicit bootstrap is invoked.
- Audit/security foundation exists.
- A second seed run produces no duplicates or destructive side effects.

## 10. Verification plan

### 10.1 Migration tests

1. Start with an empty isolated test database.
2. Run the complete migration sequence.
3. Verify expected tables, foreign keys, indexes, and composite keys.
4. Run migrations again; Laravel must report no pending migrations.
5. Roll back/reapply the relevant batch in an isolated test database.
6. Verify Laravel discovers all required migrations in the flat migration directory.

### 10.2 Seeder tests

Test first execution, second execution, partial recovery, missing tenant, missing application, unknown permission/role slug, duplicate catalogue entry, cross-tenant grant, cross-application grant, inactive permission, soft-deleted role, absence of direct grants, unverified bootstrap identity, credential leakage, and preservation of existing credentials on ordinary reseed.

### 10.3 Authorization tests

Prove:

- Unauthenticated access is denied.
- Missing/invalid tenant context is denied on tenant-required routes.
- Inactive tenant is denied.
- Non-member is denied.
- Member without permission is denied.
- Correct tenant role/permission allows only the intended operation.
- Tenant A grants do not authorize tenant B.
- Application A grants do not authorize application B.
- Global permission semantics match the contract.
- Direct grants remain inactive until explicitly enabled.
- Revoked role/direct grant ceases to authorize.
- Cache invalidation does not preserve stale-positive access.
- Resource policies remain enforced after permission checks.
- Governance authority is required for formally controlled actions.
- Authorization denial emits appropriate security evidence.
- Audit persistence failure rolls back consequential mutations.

### 10.4 Concurrency and failure tests

Test concurrent assignments/revocations, assignment-versus-revocation races, duplicate grants, audit persistence failure, cache invalidation failure, deadlock/retry behavior, and interrupted seeder recovery. Use synthetic identities and credentials only.

## 11. Historical contract disposition

| Historical element | Disposition |
|---|---|
| `custom_roles` | Superseded by current `roles` |
| `custom_permissions` | Superseded by current `permissions` |
| Historical assignment pivot names | Superseded by current-main pivots |
| String `tenant_key` as database authorization identity | Superseded by numeric `tenant_id` |
| Application access levels | Express through application-scoped roles/permissions |
| Per-user allow overrides | Current direct-grant pivot exists; disabled by default pending controlled policy |
| Per-user deny overrides | Not represented by current schema; requires separate approval |

Historical contracts remain evidence of prior design, not a parallel runtime authorization system.

## 12. PR #84 and PR #85 route ownership gate

The current RBAC schema selection does not resolve the overlapping Admin API route/capability proposals in PR #84 and PR #85.

Before implementation of overlapping administrative surfaces:

- Assign one canonical owner to each route/capability family.
- Reconcile both proposals against this contract.
- Ensure membership and role mutations use one service-level authorization contract.
- Apply identical audit atomicity requirements to every mutation path.
- Do not merge either PR merely because this specification is approved.

**P0-09 remains an explicit implementation-entry dependency until route ownership is reconciled.**

## 13. P0 closure and A3 entry

| Gate | Required evidence | Status |
|---|---|---|
| P0-01 | Current-main RBAC selected | OWNER-APPROVED |
| P0-02 | Tenant identity/membership contract | OWNER-APPROVED |
| P0-03 | Role/permission scope invariants | OWNER-APPROVED |
| P0-04 | Application access model | OWNER-APPROVED |
| P0-05 | Direct-grant policy | OWNER-APPROVED |
| P0-06 | Audit atomicity contract | OWNER-APPROVED |
| P0-07 | Migration sequence | OWNER-APPROVED SPECIFICATION |
| P0-08 | Deterministic seeder contract | OWNER-APPROVED SPECIFICATION |
| P0-09 | PR #84/#85 route ownership | UNRESOLVED |
| P0-10 | Authorization/cache failure tests | A3 IMPLEMENTATION EVIDENCE REQUIRED |
| P0-11 | Fresh-install migration and seed execution | NOT RUN |

A3 implementation is authorized conditionally, but the unresolved route ownership gate must be reconciled before implementation of the overlapping Admin surfaces. A3 must record its exact branch and base SHA, preserve unrelated changes, and implement only approved scope.

Merge and deployment remain separate authorization gates.

## 14. Change-control record

- This document records the Owner-approved current-main RBAC and tenant authorization contract.
- No live database access is required.
- This document alone does not implement schema, seeders, or runtime authorization.
- No migration or seeder has been executed.
- No merge or deployment is authorized.
- Existing A1 and A2 baselines remain preserved.

**Owner approval:** Approved in conversation before repository persistence.  
**Specification version:** 1.0.0  
**Implementation status:** NOT STARTED  
