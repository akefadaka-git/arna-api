# ARNA Admin RBAC — Controlled A1 Addendum

**Document ID:** ARNA-ADMIN-P0-RBAC-ADD-001  
**Version:** 1.0.1  
**Status:** OWNER-AUTHORIZED; IMPLEMENTATION VERIFIED AT `b1eb68d19a07c83b46e9d84bcef527a4c53d296a`; INDEPENDENT REVIEW NOT STARTED  
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
10. Finance settlement execution retains two explicit scope variants:
    - `finance.governance_settlement.execute` remains tenant-scoped and is explicitly granted to the tenant-scoped `cashier` role.
    - `finance.governance_settlement.execute.app` is application-scoped, bound to Finance, and explicitly granted to `finance_manager`.
    - Both variants are excluded from broad scope wildcards. Finance route middleware evaluates the two slugs as alternatives under the same trusted tenant/application context; `FinancePolicy` checks the application-scoped variant before the tenant-scoped variant.

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
- Reconcile Finance role permission catalogue entries so Finance Manager's Finance-domain grants are application-scoped and bound to Finance; preserve the tenant-scoped cashier settlement permission and add the Finance application-scoped execution variant.
- Make alternative permission middleware evaluate each candidate independently without weakening scope checks; update Finance policy resolution for the two settlement execution variants.
- Exclude both settlement execution variants from wildcard role grants and enforce role-permission scope compatibility through `Role::syncPermissions()`.
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

## 6. Exact-head implementation verification

All three required workflows passed at the same exact feature-branch head:

- Verified code SHA: `b1eb68d19a07c83b46e9d84bcef527a4c53d296a`
- [A3 Admin Control Plane — run #222](https://github.com/akefadaka-git/arna-api/actions/runs/45371727664) — SUCCESS.
- [Executive Overview A4 — run #577](https://github.com/akefadaka-git/arna-api/actions/runs/45371727685) — SUCCESS.
- [Clean-install MySQL — run #2520](https://github.com/akefadaka-git/arna-api/actions/runs/45371742818) — SUCCESS.

The clean-install run verified PHP 8.3.37 and MySQL 8.4.13, empty-database migrations, a second migration pass, full fresh migration and seed, no pending migrations, stable migration-record count, domain/security regressions, Executive Overview tenant and Finance widget contracts, fresh-authorization/tenant-isolation tests, Loan concurrency, and the Composer security advisory gate.

### Backup/restore evidence

- Artifact: `arna-clean-install-evidence-45371742818` (artifact ID `14909672548`, retained until 2026-12-08).
- Artifact ZIP SHA-256: `f28873266afd45db59925aa286df2fdd2de3cc33558b5f3ec66e01dc33c15c01`.
- Synthetic backup SQL SHA-256: `c626d521e5256b7a3590e965f77f73ff93445624842814aa105296c30da1c0e1`.
- Source and restored manifests matched: 143 tables, 100 migration records, and 461 foreign-key constraints.
- Both manifests recorded zero journal entries, zero journal lines, zero finance audit records, zero outbox records, and zero inbox records.
- This was a disposable CI synthetic-data rehearsal only. It does not constitute a populated production-ledger restore rehearsal and accessed no production data, hosts, secrets, or backups.

### Remaining operational and independent-review gates

- Production Owner trust-anchor provisioning: NOT PROVISIONED; explicit production Owner/key/reviewer/authority/evidence selection remains required.
- Independent third-party security review: NOT STARTED. The implementation and CI verification above are not independent certification.
- Branch-protection inspection: UNRESOLVED; the connected GitHub API returned HTTP 403 for the branch-protection endpoint. No claim that branch protection is absent is made.
- Populated production-ledger backup/restore rehearsal: NOT RUN.

## 7. Scope and release gates

This addendum authorizes architecture reconciliation and corrective A3 implementation on the existing feature branch. It does **not** authorize:
- merging PR #214;
- converting the PR from draft to ready;
- deployment or production database/secrets changes;
- provisioning production Owner trust anchors;
- representing the assistant's own review as independent security review.

PR #214 remains open, draft, and unmerged. Merge and deployment require separate explicit Owner authorization.

## 8. Closure criteria

<badge color="success">IMPLEMENTATION VERIFIED</badge> A3, A4, and clean-install MySQL passed at the exact SHA recorded in Section 6. This closes the implementation-verification criteria for this addendum. Independent third-party security review, production Owner trust-anchor provisioning, populated production-ledger restore rehearsal, merge, and deployment remain separate gates.

### Revision history

- **1.0.0** — Initial Owner-authorized A1 addendum defining the Finance application-scoped Executive Overview permission and scope-specific audience policy.
- **1.0.1** — Records Finance settlement execution scope variants, alternative permission middleware, wildcard exclusions, model-validated role seeding, and exact-head A3/A4/MySQL verification evidence. Preserves ARNA-ADMIN-P0-RBAC-001 v1.0.0 as the historical A1 baseline.
