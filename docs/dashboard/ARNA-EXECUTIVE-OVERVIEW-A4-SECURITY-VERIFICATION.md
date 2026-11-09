# ARNA Executive Overview — A4 Security and Isolation Verification

**Stage:** A4 — verification  
**Current status:** R3 IMPLEMENTED; R4 exact-HEAD CI and R5 technical retest PENDING  
**Scope:** Frozen audience authorization, Finance authorization freshness, tenant/legal-entity isolation, response disclosure, and reproducible evidence  
**A3 API parent:** `6e8ca660d35caf9fe5e8d66ee34406bf642f2de1`  
**A4 API baseline:** `9f5ea1ee0e221bca5007890178f249cdba20c992`  
**R3 branch:** `remediate/executive-overview-a4-r3`

## Governance boundary

R3 is a child of the exact A4 baseline. It preserves the original A4 verification branch and PR #101. It uses synthetic fixtures and RefreshDatabase. No production role grants, production data access, merge, or deployment are included. A5 remains separate.

## R3 remediation

The API test suite adds adversarial coverage for:

- Two authorized legal entities in tenant A alongside a foreign-tenant entity. The test verifies that only tenant A entities are queried, each entity retains its own summary, and the foreign entity is absent.
- Consolidation rejects a selected foreign-tenant entity before report generation or aggregate output.
- A direct Finance report grant remains effective when the equivalent role grant is absent, while entity permission remains separately required.
- An inactive Finance report permission fails closed even while its role pivot remains.
- An inactive Finance Manager role fails the frozen audience gate.
- A Finance Manager role bound to another application or another tenant fails the frozen audience gate.
- An inactive Finance application and a Finance report permission scoped to another tenant both fail closed.

The Executive Overview API currently returns separate per-entity widgets; it does not expose a consolidated/aggregate widget or an entity-specific grant model. R3 verifies the implemented tenant and permission boundaries and does not invent additional product capabilities.

## Verification matrix

| Control | Evidence | Status |
|---|---|---|
| Frozen audience role + tenant-bound entry permission | Existing authorization tests | PASS on A4 baseline |
| Direct grants/platform wildcard do not bypass frozen audience | Existing authorization tests | PASS on A4 baseline |
| Finance report revocation despite stale positive cache | Existing A4 regression | PASS on A4 baseline |
| Entry permission revocation despite stale middleware cache | Existing A4 regression | PASS on A4 baseline |
| Cross-tenant entity exclusion | Existing A4 regression | PASS on A4 baseline |
| Mixed authorized/foreign-tenant entities and separate summaries | `mixed_entity_overview_returns_only_current_tenant_entities_and_keeps_each_summary_separate` | R3 implemented; CI pending |
| Consolidation rejects foreign-tenant member | `consolidation_rejects_a_foreign_tenant_entity_before_any_aggregate_is_returned` | R3 implemented; CI pending |
| Direct Finance report grant without role grant | `direct_finance_report_grant_is_honoured_when_the_role_grant_is_absent` | R3 implemented; CI pending |
| Inactive Finance permission / wrong permission tenant | New R3 authorization regressions | R3 implemented; CI pending |
| Inactive audience role / inactive Finance application | New R3 authorization regressions | R3 implemented; CI pending |
| Wrong application assignment / wrong tenant assignment | New R3 authorization regressions | R3 implemented; CI pending |
| Generic denial, hidden metadata, private/no-store | Existing route and middleware tests | PASS on A4 baseline |
| Frontend session and tenant race controls | App A4 baseline browser suite | PASS on A4 baseline; R3 browser retest pending |
| Fresh technical retest | GPT-6 source/test/evidence review of exact R4 heads | PENDING — R5 |

## Baseline evidence — not R3 evidence

- API targeted A4 workflow [#42685935427](https://github.com/akefadaka-git/arna-api/actions/runs/42685935427): PASS on baseline API SHA `9f5ea1ee0e221bca5007890178f249cdba20c992`.
- App targeted A4 workflow [#42685209063](https://github.com/akefadaka-git/arna-app/actions/runs/42685209063): PASS on baseline App SHA `ea2c432ecf1d8d0f02ee7f58984bf8341f8a2bf7`, authenticated browser suite 8/8.
- Earlier full clean-install/MySQL API workflow [#42680980701](https://github.com/akefadaka-git/arna-api/actions/runs/42680980701): PASS on earlier code snapshot `73a20ebb65f8e8e9489e645ae63d2836d461216d`. It is not exact-head R3 evidence.

R4 must replace these baseline-only references with workflow evidence tied to the final remediation SHAs.

## Residual risk — RISK-A4-01

The general-purpose `InvalidateAuthorizationCacheListener` still catches and logs invalidation failures. A failed invalidation can leave stale positive cache entries until their TTL expires for other platform permission consumers. This is a platform-wide residual risk and remains OPEN.

The Executive Overview's sensitive audience and Finance data gates re-read authoritative database pivots; stale cached positive permission answers cannot preserve access through this endpoint. R3 does not claim a global cache fix. A cache-generation/version or durable invalidation redesign is outside the frozen Executive Overview A4 scope and must be handled as a separate governed work item. R5 must specifically retest the endpoint's uncached authorization behavior under the stale-cache condition.

## Independent technical retest and closure

R5 is a fresh ChatGPT-led technical retest of the exact R4-verified API and App heads. It will independently re-read the changed implementation, challenge the new tests and test harness, inspect exact-head CI, and record unresolved findings. The retest will identify itself accurately as a GPT-6 technical assessment, not an external human audit or certification.

An external human reviewer is required only if applicable law, contract, third-party assurance, or an explicit Owner decision mandates one. It is not an automatic prerequisite for this technical A4 closure.

If R4 is PASS and R5 finds no unresolved A4-scoped critical/high issue, the A4 implementation may be marked VERIFIED-CLOSED while RISK-A4-01 remains explicitly OPEN and outside this endpoint's closure scope. Closure does not authorize merge, production role changes, or deployment.
