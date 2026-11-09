# ARNA Executive Overview — A4 Security and Isolation Verification

**Stage:** A4 — verification  
**Status:** PARTIAL — targeted A4 suite and full clean-install/MySQL gate PASS; not production-cleared  
**Scope:** API authorization, tenant/legal-entity isolation, Finance permission freshness, response disclosure, and reproducible evidence  
**A3 parent:** `6e8ca660d35caf9fe5e8d66ee34406bf642f2de1`  
**A4 branch:** `verify/executive-overview-a4`

## Governance boundary

This A4 branch is based on the Owner-approved A3 API branch. It does not merge A3 into main, change production role grants, access production data, or deploy. Tests use synthetic fixtures and RefreshDatabase. Merge and deployment remain separate approvals.

## A4 implementation in this branch

1. Finance policy authorization now reads the current permission and role pivots directly for each decision. It checks active grants, trusted tenant context, tenant scope, and Finance application scope. This removes Finance entity authorization's dependence on a potentially stale permission-result cache.
2. Executive Overview's Finance report gate now reads the current database pivots directly. The entry audience remains checked through the uncached `ExecutiveOverviewAccessPolicy`, and each entity is checked through the Finance policy before trial-balance reads.
3. Added regression tests that deliberately warm a stale positive Finance permission cache, revoke the report permission, and verify the Executive Overview no longer returns Finance widgets.
4. Added a cross-tenant legal-entity regression test: a request in tenant A must not return a tenant B entity.

## Verification matrix

| Control | Evidence in branch | Status |
|---|---|---|
| Frozen audience role + tenant-bound entry permission | Existing `ExecutiveOverviewAuthorizationTest` | A4 targeted workflow PASS |
| Direct grants/platform wildcard do not bypass audience | Existing authorization tests | A4 targeted workflow PASS |
| Finance report revocation despite stale cached positive | `ExecutiveOverviewA4FreshAuthorizationTest::finance_report_revocation_is_effective_even_when_a_stale_positive_cache_entry_exists` | Added; PASS on exact A4 HEAD |
| Cross-tenant legal-entity exclusion | `ExecutiveOverviewA4FreshAuthorizationTest::tenant_a_overview_never_includes_a_legal_entity_from_tenant_b` | Added; PASS on exact tested SHA |
| Per-entity Finance authorization | `FinancePolicy::allows` now queries authoritative pivots; existing route fixture | Implemented; PASS in targeted API workflow |
| Generic denial and hidden metadata | Existing route and middleware tests | A4 targeted workflow PASS |
| Private/no-store response | Existing Finance route test | Existing A3 tests; CI pending |
| Full adversarial tenant/entity matrix | Additional mixed-entity, aggregate, direct-endpoint and role mutation cases | PARTIAL / NOT RUN |
| Cache-listener infrastructure failure | Listener still logs and swallows invalidation failure | OPEN platform-level risk; this feature now avoids cached Finance authorization |
| Runtime browser session/concurrency tests | App A4 package | NOT RUN |
| Independent human security review | External reviewer required | MISSING |

## Exact-HEAD evidence

- A4 branch HEAD: `c17a36e9401347f47345319bffa8b4b83d60871a`
- Targeted A4 API workflow: [run #A4 security-tests](https://github.com/akefadaka-git/arna-api/actions/runs/42680656797) — **PASS**, job `129373965875`.
- Exact-head checks completed: PHP lint, clean MySQL migrations, frozen audience/wildcard authorization, route and metadata disclosure/no-store tests, stale-cache revocation, cross-tenant entity isolation, and Finance permission catalogue.
- Full repository clean-install/MySQL workflow: [run #42680980701](https://github.com/akefadaka-git/arna-api/actions/runs/42680980701) — **PASS** on the same tested SHA.

## Known residual risk

The general-purpose `InvalidateAuthorizationCacheListener` still catches and logs cache invalidation failures. That remains a platform-wide authorization-cache reliability issue for other permission consumers. The Executive Overview's sensitive entry-audience and Finance data decisions now use authoritative, uncached database pivots, so the listener's stale Finance permission cache cannot preserve Finance widget access on this endpoint. This change does not claim to solve cache invalidation for unrelated platform routes.

## Required next verification

- Complete the full API clean-install/MySQL workflow against exact A4 HEAD and fix any failures.
- Keep the new stale-cache revocation and cross-tenant tests in the exact-HEAD regression gate.
- Expand the entity matrix to include mixed authorized/unauthorized entities and direct endpoint access.
- Run App browser tests for logout, permission revocation, tenant switching, delayed/out-of-order responses and component unmount.
- Obtain independent human application-security review before production clearance.

## Closure rule

A4 is not closed until exact-HEAD API and App CI pass, the required adversarial isolation matrix passes, browser session/concurrency behavior is evidenced, and independent human review has no unresolved critical/high findings. A4 closure does not authorize merge or deployment.
