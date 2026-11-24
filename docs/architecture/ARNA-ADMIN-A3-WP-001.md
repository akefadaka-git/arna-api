# ARNA Admin Control Plane — A3 Implementation Work Package

**Work Package ID:** ARNA-ADMIN-A3-WP-001  
**Version:** 1.0.0  
**Status:** Prepared against Owner-approved P0 specifications  
**Repository:** `akefadaka-git/arna-api`  
**Source of truth:** `main` at the implementation branch creation point  
**Architecture baseline:** `docs/architecture/ARNA-ADMIN-P0-RBAC-001.md`  
**Route baseline:** `docs/architecture/ARNA-ADMIN-P0-ROUTES-001.md`  
**Implementation state:** NOT STARTED

## 1. Objective

Implement the approved current-main RBAC and tenant authorization contract, the fresh-install migration/seeder requirements, and the separated platform/tenant Admin API surfaces.

This work package is intentionally implementation-ready but does not itself authorize bypassing unresolved technical gates. It is not a merge or deployment authorization.

## 2. Baseline and constraints

- Current API `main` reviewed at `40428db0a41d54397dd2aee482c9395f7950e9d0`.
- Current-main RBAC tables remain canonical: `roles`, `permissions`, `role_has_permissions`, `user_has_roles`, `user_has_permissions`.
- Canonical tenant database identity is numeric `tenant_id`.
- `tenant_memberships` remains independent of role assignment.
- Direct user permissions remain present but no direct grants are seeded; routine direct-grant administration remains disabled.
- No historical `custom_*` table family is recreated.
- No live database schema or migration-ledger access is required.
- Fresh-install tests use isolated disposable databases and synthetic data.
- Preserve unrelated main-branch work. Do not blindly cherry-pick stale PR branches.
- No production credentials or real personal data in tests.
- No merge/deployment in this work package.

## 3. Workstream sequence

### A3.0 — Branch and source reconciliation

**Deliverables**
- Implementation branch created from the then-current `main` SHA.
- Exact base SHA and branch recorded.
- Reconcile PR #84 head `0f8e3e6ac297a7e3dc43b232ab8de9dce6b7cbb5` and PR #85 head `ef26a85531c5d4df9d6a32df36e368bcbf00e72a` against current main.
- Port only reviewed changes; do not merge stale bases wholesale.
- Preserve both original PRs as reference until their disposition is separately approved.

**Acceptance**
- File-level source diff reviewed.
- No duplicate route registrations.
- No unrelated changes introduced.

### A3.1 — Platform/tenant route separation

**Scope**
- PR #84 platform-wide read-only routes under `/api/admin/platform/*`.
- PR #85 tenant lifecycle routes under `/api/admin/tenant/*`.
- One controller/authorization stack per method/path.
- Platform endpoints require `platform.identity.read`, `platform.rbac.read`, or `platform.tenancy.read`.
- Tenant endpoints require `app.admin.access`, trusted tenant context, active tenant membership, and their specific tenant permission.
- Keep platform read permissions catalogue-only unless an explicit grant is separately approved.

**Acceptance**
- Route-list duplicate scan is clean.
- Tests prove global permissions cannot be replaced by tenant permissions and vice versa.
- No request-body tenant authority.
- Cross-tenant membership lookup denied without platform permission.

### A3.2 — Canonical tenant and RBAC invariants

**Scope**
- Enforce role scope/binding consistency.
- Enforce permission scope/binding consistency.
- Enforce role-permission scope compatibility.
- Require active tenant membership independently of user-role assignment.
- Enforce tenant-bound role assignment only in its tenant.
- Enforce application-bound role/permission checks using registered application context.
- Preserve global permission semantics without bypassing resource-level policies.
- Keep direct user grants disabled by default.

**Acceptance**
- Cross-tenant and cross-application grants fail closed.
- Inactive/soft-deleted roles and permissions cannot authorize.
- A tenant role does not create membership.
- Application A authorization cannot be used in application B.

### A3.3 — Membership lifecycle and atomic audit

**Scope**
- One canonical `TenantMembershipService` for attach, suspend, reactivate, and revoke.
- Lock tenant, user, and membership rows as needed to prevent races.
- Define revoked-membership terminal behavior consistently with the approved contract.
- Persist required audit evidence in the same transaction as the membership mutation.
- Persist required security evidence transactionally or through a transactional outbox with a durable event guarantee.
- External notifications occur after commit.
- No duplicate service implementation in controllers.

**Acceptance**
- Injected audit failure rolls back membership mutation.
- Concurrency tests prevent duplicate membership and illegal state transitions.
- Actor and subject IDs are correct in audit records.
- Membership revocation invalidates affected authorization context.

### A3.4 — Tenant-scoped RBAC lifecycle

**Scope**
- Create/update tenant roles.
- Synchronize tenant role permission grants.
- Synchronize a user's tenant role assignments while preserving platform/application roles.
- Enforce actor grant ceiling.
- Protect platform roles and system permissions.
- Apply targeted authorization cache invalidation.
- Do not expose direct user permission assignment in tenant administration.

**Acceptance**
- Actor cannot grant permissions they do not hold.
- Cross-tenant roles/permissions rejected.
- Existing platform/application assignments preserved.
- Audit failure rolls back pivot mutation.
- Cache tests prove role change/revocation takes effect immediately or fail closed.

### A3.4 implementation note — Owner approval gate and cache safety

The first A3.4 implementation slice adds tenant-bound role/permission scope validation and tenant-targeted cache invalidation. Role-permission synchronization now executes the pivot mutation and synchronous invalidation in one database transaction; invalidation exceptions propagate so the pivot change rolls back. Tenant-role changes invalidate only the affected tenant's tracked permission keys; platform/application role changes retain user-wide invalidation.

**Consequential-grant limitation:** repository inspection did not identify a verified, runtime Owner-approval evidence service or canonical approval record that can bind an approval to the exact tenant, actor, target user, role, permission set, reason, and validity interval. A role slug or ordinary admin permission is not Owner approval. Therefore A3.4 must not expose role assignment, role-permission grant, or direct-permission mutation routes until that approval-verification dependency is implemented and tested. This is a fail-closed implementation gate, not permission to substitute a caller-supplied boolean or free-form reference.

The user_has_permissions pivot remains unchanged and no direct-user grant route is added. The next A3.4 slice is to establish a verifiable Owner approval contract (or integrate an already-authoritative one after evidence is found), then implement audited tenant role assignment/revocation and permission-set changes behind that verifier.

### A3.5 — Platform-wide read-only Admin APIs

**Scope**
- Global user directory.
- Global role and permission catalogue read.
- Global tenant registry.
- Tenant membership listing by explicit tenant ID.
- Minimal operational serialization; exclude passwords, tokens, MFA secrets, recovery codes, and private security attributes.
- Bounded pagination and validated filters.

**Acceptance**
- Every route uses its dedicated global read permission.
- No mutation endpoints in this controller.
- Tenant-scoped permissions cannot access platform-wide read APIs.
- Sensitive serialization tests pass.

### A3.6 — Fresh-install migration verification

**Scope**
- Preserve existing current-main migrations that already satisfy the contract.
- Add migrations only for verified missing constraints or hardening requirements.
- Preserve the flat `database/migrations/` discovery convention.
- Ensure migration dependency order and FK/index/unique constraints.
- Do not recreate historical RBAC tables or duplicate current migrations.
- Use isolated empty test databases; no live database access.

**Acceptance**
- Empty database migrates cleanly.
- All expected tables, indexes, FKs, and unique constraints exist.
- Second migration run reports no pending work.
- Rollback/reapply works in isolated test environment.
- Cross-table scope invariants are covered by service tests if not expressible in SQL.

### A3.7 — Deterministic seeders and bootstrap

**Scope**
- Compose current core and domain-owned permission catalogues.
- Seed platform applications, stable permission slugs, role slugs, and approved role-permission grants.
- Idempotent upsert and duplicate-safe pivot insertion.
- Do not delete omitted catalogue records or detach assignments during ordinary re-seed.
- No direct user permission grants by default.
- Explicit secure Owner bootstrap only; never infer Owner from first user.
- No credentials embedded in source or logs.
- Preserve and review existing catalogue definitions; no silent privilege expansion.

**Acceptance**
- First and second seed executions produce same canonical state.
- Interrupted seeding recovers safely.
- Unknown slug, invalid scope, missing app, or required grant failure stops with clear diagnostics.
- Existing credentials/assignments are not destructively reset.
- Owner bootstrap requires verified identity and secure environment-provided credential flow.

### A3.8 — Verification and evidence package

**Required tests**
- Route/middleware scope and duplicate-route tests.
- Tenant membership and RBAC lifecycle feature tests.
- Role/permission scope compatibility tests.
- Maker-checker/grant-ceiling tests.
- Audit failure rollback tests.
- Security-event/outbox tests.
- Cache revocation/stale-positive tests.
- Fresh-install migration and deterministic seed tests.
- Concurrency/idempotency/failure-recovery tests.
- Relevant existing regression suite.

**Evidence**
- Exact candidate branch and head SHA.
- Changed-file inventory.
- Test command/results and CI run links.
- Migration and seed test evidence.
- Security invariants and known limitations.
- No claim of production readiness based only on unit/feature tests.

## 4. Required file ownership plan

Expected file families (final paths depend on current-main reconciliation):

- `routes/api.php`
- `app/Http/Controllers/Admin/AdminControlPlaneController.php`
- `app/Http/Controllers/Admin/AdminFoundationController.php`
- `mases_core/src/Tenancy/Services/TenantMembershipService.php`
- Canonical RBAC services/models/events/listeners
- `database/migrations/*` only for evidenced gaps
- `database/seeders/*` and domain permission catalogues
- `tests/Feature/Admin*`
- `tests/Feature/TenantMembership*`
- migration/seeder/authorization test suites
- focused architecture and operational docs

No parallel identity, tenancy, or RBAC model is permitted.

## 5. Explicit non-goals

- No production database changes.
- No deployment or hosting configuration changes.
- No migration execution against a live database.
- No production Owner credentials or secret generation in GitHub.
- No direct-permission administration enablement without separate approval.
- No historical RBAC table reconstruction.
- No broad refactor of unrelated domains.
- No PR merge or closure.
- No Admin frontend work in this API-only package unless separately scoped.

## 6. Blocking conditions and decision gates

| Gate | Condition | Status |
|---|---|---|
| P0-01 | Current-main RBAC selected | OWNER-APPROVED |
| P0-02 | Tenant identity/membership contract | OWNER-APPROVED |
| P0-03 | Role/permission scope contract | OWNER-APPROVED |
| P0-04 | Application access contract | OWNER-APPROVED |
| P0-05 | Direct grant policy | OWNER-APPROVED |
| P0-06 | Audit atomicity contract | OWNER-APPROVED |
| P0-07 | Fresh-install migration specification | OWNER-APPROVED |
| P0-08 | Deterministic seeder specification | OWNER-APPROVED |
| P0-09 | PR #84/#85 route ownership | ARCHITECTURALLY RECONCILED; CODE NOT YET RECONCILED |
| P0-10 | Audit/cache failure behavior | A3 IMPLEMENTATION AND TEST EVIDENCE REQUIRED |
| P0-11 | Clean-install migration and seeding | A3 TEST EVIDENCE REQUIRED |

A3 is conditionally authorized under the Owner-approved architecture. The route plan is now explicit, but the candidate code must implement it and pass verification. Any material contract deviation returns for Owner decision. Merge and deployment remain separate gates.

## 7. Progress and reporting requirements

Report each workstream as PASS, VERIFIED-CLOSED, PARTIAL, MISSING, UNRESOLVED, UNKNOWN, or NOT RUN. Include exact SHAs, PRs, changed files, test/workflow evidence, migration/seed status, completion percentages, readiness assessment, blockers, and immediate next step.

**Prepared against:** ARNA-ADMIN-P0-RBAC-001 v1.0.0 and ARNA-ADMIN-P0-ROUTES-001 v1.0.0.  
**Implementation:** NOT STARTED.  
**Merge/deployment:** NOT AUTHORIZED.
