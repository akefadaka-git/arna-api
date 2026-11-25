# ARNA Admin Central Administration — A1/A2 Formal Closure Record

**Record ID:** ARNA-ADM-CP-CLOSE-001  
**Version:** 1.0.0  
**Owner:** Akefa Ayele Daka  
**Decision date:** 10 November 2026  
**Governance decision:** Owner-approved bounded A2 closure  
**Scope:** A1 assessment/specification/freeze and A2 read-only repository/schema/PR reconciliation  
**Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`

## 1. Owner decision and effect

The Owner approved **Option 2 — bounded A2 closure**.

This record formally closes the A1 assessment/specification phase and the A2 documentation and repository-reconciliation phase. Closure means that the assessment baseline and the available repository evidence have been documented and reconciled to the extent possible from accessible sources.

It does **not** mean that the live database has been verified, the historical/current schemas have been proven equivalent, or the unresolved crosswalks have been completed. Those items remain mandatory A3 entry gates.

No A3 implementation is authorized by this record. No source implementation, database migration, data mutation, PR merge, production change, or deployment is authorized.

## 2. A1 — VERIFIED-CLOSED

**Disposition: VERIFIED-CLOSED.**

The A1 baseline includes:
- Four application route inventory: MASES Workspace, Shareholder Portal, Admin, and Public Corporate Website.
- API/domain route inventory covering core routes and Shareholder, Wallet, Loan, Governance, Finance, People, Organization, Files, identity, public content, audit/security and related platform surfaces.
- Application catalogue, role catalogue, central permission inventory and domain permission boundaries.
- Sixteen-area Admin capability assessment and present/partial/missing findings.
- Identity, RBAC, tenant, platform-versus-tenant authority requirements.
- Frozen A2 reconciliation scope and implementation-contract requirements.

A1 is closed as an assessment/specification baseline. Any later material authoritative contradiction must be recorded as a controlled change; it does not silently reopen this baseline.

## 3. A2 — BOUNDED VERIFIED-CLOSED

**Disposition: VERIFIED-CLOSED — DOCUMENTATION AND REPOSITORY RECONCILIATION ONLY.**

Completed A2 work:
- Inspected current API and frontend repository baselines.
- Reconciled available identity/RBAC/tenant implementation PRs against current main.
- Recorded stale PR divergence and route/API collisions.
- Identified current-main Laravel RBAC persistence versus the previously Owner-approved custom RBAC authority.
- Recorded historical custom RBAC schema evidence and the limits of the partial Migration 002 capture.
- Defined the platform-global read-only versus tenant-scoped administration boundary.
- Identified frontend route/navigation preservation requirements.
- Recorded audit atomicity, profile-field ownership, concurrency and verification gaps.
- Published the API reconciliation report and frontend reconciliation report through their respective draft PR branches.

### 3.1 Canonical A2 evidence

- API report: `docs/architecture/ARNA-ADMIN-CENTRAL-CONTROL-PLANE-A2-RECONCILIATION-REPORT.md`
- Frontend report: `docs/architecture/ARNA-ADMIN-CENTRAL-CONTROL-PLANE-A2-FRONTEND-RECONCILIATION.md`
- API documentation branch: `docs/admin-control-plane-a2-contract-v1`
- API draft PR: https://github.com/akefadaka-git/arna-api/pull/105
- Frontend documentation branch: `docs/admin-control-plane-a2-reference-v1`
- Frontend draft PR: https://github.com/akefadaka-git/arna-app/pull/54

These documentation PRs remain draft and unmerged. This closure record is committed to the same documentation branch as the API A2 report and is not a merge instruction.

## 4. Mandatory A3 entry gates — NOT SATISFIED

A3 must not begin until every gate below has objective evidence, a recorded reconciliation result, and explicit Owner acceptance where a policy choice is required.

| Gate | Required evidence / acceptance condition | Current status |
|---|---|---|
| G-A3-01 Live database baseline | Authoritative current schema, migration ledger, active database identity/environment, relevant table counts, keys, indexes and constraints | MISSING |
| G-A3-02 Migration 002 provenance | Complete approved Migration 002 source/DDL and execution provenance; partial transcript is insufficient | MISSING |
| G-A3-03 Tenant identity crosswalk | Deterministic mapping from every historical `tenant_key` to exactly one current tenant ID; duplicates, missing and ambiguous mappings rejected; home-tenant semantics separately preserved | UNRESOLVED |
| G-A3-04 Role crosswalk | Source-backed role mapping including scope, tenant, hierarchy and assignment semantics; no name-only equivalence | UNRESOLVED |
| G-A3-05 Permission crosswalk | Source-backed permission mapping including resource/action/scope and effective grants; preserve historical grants and deny/allow semantics | UNRESOLVED |
| G-A3-06 Application entitlement crosswalk | Reconcile application-to-tenant role/access levels, enablement and explicit per-user allow/deny overrides; preserve expiry/reason/grantor semantics where present | UNRESOLVED |
| G-A3-07 ARNA tool authorization | Reconcile tool-to-permission mappings and tenant-context audit semantics | UNRESOLVED |
| G-A3-08 Canonical authority and compatibility design | Owner-reviewed adapter/read strategy, write authority, no-dual-write policy, consistency, rollback and coexistence with untouched Laravel-standard tables | PENDING |
| G-A3-09 Identity field ownership | Define central versus tenant-owned user profile fields and safe mutation boundaries | PENDING |
| G-A3-10 Audit durability | Approve same-transaction audit or transactional outbox/idempotent retry design; demonstrate failure behavior | PENDING |
| G-A3-11 Authorization safety | Define and test grant ceiling, privilege escalation prevention, concurrency/TOCTOU and cache invalidation | PENDING |
| G-A3-12 Integration baseline | Rebase/port plan from current main; preserve current routes/navigation and resolve API route/permission namespace collisions | PENDING |

### 4.1 Binding architectural constraints for A3 planning

Until superseded by a new explicit Owner decision:
1. Preserve both historical custom RBAC and Laravel-standard table families.
2. The tenant-aware custom RBAC family remains the previously approved MASES/ARNA cross-application/API authorization authority.
3. Do not silently redirect authorization reads to Laravel-standard tables.
4. Do not drop, rename, truncate, reseed, backfill or dual-write either table family without an approved, evidence-backed migration and rollback plan.
5. Preserve tenant assignment dimensions and application entitlement/override semantics.
6. Direct per-user tenant permission management remains deferred; historical records must nevertheless be preserved and accounted for.
7. Keep platform-global read-only administration separate from tenant-scoped lifecycle operations.
8. Preserve current-main Admin routes, including corporate-site, public-content and audit/security surfaces.
9. Make required audit evidence failure-safe with the protected mutation.
10. A3, A4, merge and A5 remain separately gated; this record authorizes none of them.

## 5. Explicit non-claims

This bounded closure does not claim:
- Live production database schema or migration ledger verified.
- Complete Migration 002 source recovered.
- Tenant keys mapped to numeric tenant IDs.
- Historical and current roles/permissions semantically equivalent.
- Application entitlements or per-user overrides reconciled.
- A3 implementation complete or authorized.
- Integrated A4 security/regression acceptance passed.
- Production readiness, merge approval or deployment approval.

## 6. Final phase disposition

| Phase | Closure status |
|---|---|
| A1 assessment/specification/freeze | VERIFIED-CLOSED |
| A2 documentation/repository reconciliation | VERIFIED-CLOSED — BOUNDED |
| A3 implementation | NOT AUTHORIZED — all entry gates mandatory |
| A4 verification | NOT STARTED |
| A5 deployment | NOT AUTHORIZED |

**Owner decision recorded:** Option 2 — bounded A2 closure.  
**Next permitted activity:** Read-only collection and reconciliation of the mandatory A3 entry-gate evidence.  
**Release status:** NOT PRODUCTION-READY.

*End of formal closure record.*
