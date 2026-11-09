# ARNA Executive Overview — A4 Security and Isolation Verification

**Stage:** A4 — verification  
**Status:** AUTOMATED API A4 PASS; overall security sign-off remains PARTIAL  
**Scope:** API authorization, tenant/legal-entity isolation, Finance permission freshness, response disclosure, and reproducible evidence  
**A3 parent:** `6e8ca660d35caf9fe5e8d66ee34406bf642f2de1`  
**A4 branch:** `verify/executive-overview-a4`

## Governance boundary

This A4 branch is based on the Owner-approved A3 API branch. It does not merge A3 into main, change production role grants, access production data, or deploy. Tests use synthetic fixtures and RefreshDatabase. Merge and deployment remain separate approvals.

## A4 implementation in this branch

1. Finance policy authorization reads the current permission and role pivots directly for each decision. It checks active grants, trusted tenant context, tenant scope, and Finance application scope. This removes Finance entity authorization's dependence on a potentially stale permission-result cache.
2. Executive Overview's Finance report gate reads current database pivots directly. The entry audience is checked through the uncached `ExecutiveOverviewAccessPolicy`, and each entity is checked through the Finance policy before trial-balance reads.
3. Regression tests deliberately seed a stale positive Finance permission cache entry after revocation and verify the Executive Overview no longer returns Finance widgets.
4. A cross-tenant legal-entity regression test verifies a tenant A request cannot return a tenant B entity.
5. Existing route tests verify configured entities can appear while same-tenant unconfigured entities are omitted, generic denial responses do not disclose permission slugs, and sensitive responses are private/no-store.

## Verification matrix

| Control | Evidence | Status |
|---|---|---|
| Frozen audience role + tenant-bound entry permission | `ExecutiveOverviewAuthorizationTest` | PASS |
| Direct grants/platform wildcard do not bypass audience | Existing authorization tests | PASS |
| Finance report revocation despite stale cached positive | `ExecutiveOverviewA4FreshAuthorizationTest` | PASS |
| Cross-tenant legal-entity exclusion | `ExecutiveOverviewA4FreshAuthorizationTest` | PASS |
| Configured vs unconfigured same-tenant entity | `ExecutiveOverviewRouteTest::finance_manager_receives_only_authorized_entities_with_a_configured_ledger_and_source_lineage` | PASS |
| Per-entity Finance authorization | `FinancePolicy::allows` reads authoritative pivots | PASS |
| Generic denial and hidden metadata | Existing route and middleware tests | PASS |
| Private/no-store response | Existing Finance route test | PASS |
| Broader aggregate/mixed-entity and role-mutation matrix | Additional aggregate and complex multi-entity combinations | PARTIAL / NOT RUN |
| Runtime browser session/concurrency | App A4 authenticated browser suite, 8/8 tests | PASS |
| Independent human security review | External reviewer required | MISSING |

## Exact-HEAD evidence

- Targeted A4 API workflow: [run #42682498522](https://github.com/akefadaka-git/arna-api/actions/runs/42682498522) — **PASS** on API evidence SHA `dfad5e2714f44bfca9944b5d756d24007081bb07`.
- Full clean-install/MySQL workflow: [run #42680980701](https://github.com/akefadaka-git/arna-api/actions/runs/42680980701) — **PASS** on code snapshot `73a20ebb65f8e8e9489e645ae63d2836d461216d`.
- Authenticated App browser suite: [run #42684395141](https://github.com/akefadaka-git/arna-app/actions/runs/42684395141) — **PASS**, 8/8 tests on App evidence SHA `f2366e7c624d60d69bd4bf03b3152a5847458d40`.
- The API full clean-install run covered Composer install/advisory checks, PHP lint, clean MySQL migrations and seeds, and the repository's migration/seed/Finance/Governance regression gates. The targeted A4 run separately covered the new stale-cache and cross-tenant tests.

## Known residual risk

The general-purpose `InvalidateAuthorizationCacheListener` still catches and logs cache invalidation failures. That remains a platform-wide authorization-cache reliability issue for other permission consumers. The Executive Overview's sensitive entry-audience and Finance data decisions now use authoritative, uncached database pivots, so the listener's stale Finance permission cache cannot preserve Finance widget access on this endpoint. This does not claim to solve cache invalidation for unrelated platform routes.

## Remaining closure requirements

- Expand automated API coverage for aggregate and complex mixed-entity combinations where the product contract defines distinct entity grants.
- Obtain an independent human application-security review with findings tied to exact API and App SHAs.
- Review the platform-wide cache listener risk separately; do not treat the Executive Overview's uncached checks as a global cache fix.

## Closure rule

Automated API and frontend A4 verification is PASS. Overall A4 security closure remains PARTIAL until the remaining adversarial matrix is reconciled and independent human review has no unresolved critical/high findings. A4 closure does not authorize merge or deployment.
