# ARNA Admin RBAC — Controlled A1 Addendum

**Document ID:** ARNA-ADMIN-P0-RBAC-ADD-001  
**Version:** 1.0.0  
**Status:** OWNER-AUTHORIZED; A3 IMPLEMENTATION IN PROGRESS  
**Supersedes:** None  
**Amends:** ARNA-ADMIN-P0-RBAC-001 v1.0.0 (historical baseline preserved)  
**Repository:** `akefadaka-git/arna-api`  
**Implementation branch:** `feat/admin-a3-route-separation`

## 1. Purpose

Resolve the identified conflict between the frozen Executive Overview audience contract and the approved RBAC role-permission scope matrix without weakening scope isolation.

This addendum is a controlled amendment to the A1 architecture. It does not replace or rewrite ARNA-ADMIN-P0-RBAC-001 v1.0.0.

## 2. Binding decisions

1. The `finance_manager` role remains **application-scoped**, bound to the registered Finance application and the relevant tenant context. It must not be converted to a tenant role merely to accommodate a dashboard route.
2. The existing `dashboard.executive.view` permission remains **tenant-scoped** and is the Executive Overview entry permission for the tenant-scoped `ceo` and `president` roles.
3. Introduce `dashboard.finance.executive.view` as a distinct **application-scoped** permission bound to the Finance application. It is the Executive Overview entry permission for `finance_manager`.
4. Finance Manager's Finance-domain permissions remain application-scoped and must be bound to the Finance application. The permission checks must also enforce the current tenant boundary; an application permission alone never waives tenant membership or resource-level authorization.
5. The Executive Overview authorization policy must evaluate role and permission pairs by scope:
   - CEO / President: tenant role bound to the resolved tenant + active tenant-scoped `dashboard.executive.view` permission (shared catalogue definition or explicitly same-tenant bound).
   - Finance Manager: Finance application role bound to the Finance application and resolved tenant + active Finance application-scoped `dashboard.finance.executive.view` permission.
6. The route must not rely on a single static tenant permission middleware that excludes the Finance Manager's application-scoped path. The controller's uncached audience policy is authoritative and must fail closed.
7. Finance widget access must check active Finance application-scoped permissions and the current tenant context for Finance Manager. The policy must not accept a permission from another application or a role from another tenant.
8. Seed catalogues and role grants must encode these scope distinctions explicitly. Wildcard/scope grants must not accidentally grant the Finance-specific Executive Overview permission to other roles.
9. No direct pivot writes, fixture bypasses, or relaxation of `Role::assertPermissionScopeCompatible()` are permitted to conceal an incompatible grant.

## 3. Security invariants

- Platform roles receive global permissions only.
- Tenant roles receive global permissions and shared or same-tenant tenant permissions only.
- Application roles receive global permissions and permissions bound to the same application (with tenant binding either shared/null or matching the role tenant) only.
- Cross-tenant and cross-application grants remain denied.
- Tenant membership remains an independent prerequisite.
- Executive Overview audience membership is not widened beyond CEO, President, and Finance Manager.
- Finance data remains independently authorized at widget and resource level.
- Revocation must be checked against current authoritative pivots for the Finance widget path; stale positive cache entries must not retain financial access.
- Inactive roles, applications, or permissions fail closed.

## 4. Required A3 implementation

- Add the Finance application-scoped permission to the canonical permission catalogue and ensure its `application_id` resolves to the registered Finance application.
- Change the Finance Manager role grant from `dashboard.executive.view` to `dashboard.finance.executive.view`.
- Preserve tenant-scoped grants for CEO and President.
- Reconcile Finance role permission catalogue entries so Finance Manager's Finance-domain grants are application-scoped and bound to Finance.
- Update `ExecutiveOverviewAccessPolicy`, route middleware, and controller widget checks to enforce the role/permission scope pairs above.
- Update the seeder's application binding resolution and validation so the new permission cannot be inserted as an unbound or wrongly bound application permission.
- Update tests to cover positive access for each intended audience and negative access for wrong tenant, wrong application, wrong permission scope, inactive app/permission/role, and roles outside the frozen audience.
- Preserve all unrelated changes and existing corrective work.

## 5. Required A4 verification

At one exact branch head SHA, all of the following must pass:
- A3 Admin Control Plane workflow.
- Executive Overview A4 authorization, route, metadata disclosure, and no-store workflow.
- Clean-install MySQL workflow including migration, full seed, Finance/People integrations, post-migration checks, loan concurrency regression, security advisory gate, and backup/restore stages.
- Tests must prove no cross-tenant or cross-application privilege path and must not bypass model-level scope validation.

## 6. Scope and release gates

This addendum authorizes architecture reconciliation and corrective A3 implementation on the existing feature branch. It does **not** authorize:
- merging PR #214;
- converting the PR from draft to ready;
- deployment or production database/secrets changes;
- provisioning production Owner trust anchors;
- representing the assistant's own review as independent security review.

PR #214 remains open, draft, and unmerged. Merge and deployment require separate explicit Owner authorization.

## 7. Closure criteria

This addendum may be marked implementation-verified only after the exact-head A3, A4, and clean-install MySQL workflows pass and the relevant diff/test evidence is recorded. Independent third-party security review remains a separate pre-deployment requirement.
