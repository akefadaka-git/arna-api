# ARNA Executive Overview — A4 R5 Technical Retest and Closure Record

**Record date:** 2026-11-09  
**Stage:** A4 — verification  
**Disposition:** VERIFIED-CLOSED — Executive Overview scope  
**R5 result:** PASS — no unresolved A4-scoped critical or high finding  
**Deployment status:** NOT DEPLOYED  
**Merge status:** NOT MERGED

## 1. Authorization and closure scope

The Owner requested R3 remediation, a fresh R5 technical retest, and A4 closure. This record closes A4 for the ARNA Executive Overview candidate only. It does not close the separate platform-wide authorization-cache risk, approve a merge, grant production roles, or authorize A5 deployment.

The review used synthetic test data and repository/CI evidence. It was performed as a GPT-6 technical assessment; it is not an external human audit, penetration test, or certification. An external human reviewer is required only if applicable law, contract, third-party assurance, or a separate explicit Owner decision requires one.

## 2. Exact code heads and R4 evidence

The remediation branches are direct descendants of the A4 baseline commits, with no behind commits.

| Repository | A4 baseline parent | R4-verified code SHA | Draft PR | R4 exact-HEAD run |
|---|---|---|---|---|
| API — `akefadaka-git/arna-api` | `9f5ea1ee0e221bca5007890178f249cdba20c992` | `2cac08b952f5e9b1ab3c93128aa4526158dc967f` | [#102](https://github.com/akefadaka-git/arna-api/pull/102) | [#42696881008 — PASS](https://github.com/akefadaka-git/arna-api/actions/runs/42696881008) |
| App — `akefadaka-git/arna-app` | `ea2c432ecf1d8d0f02ee7f58984bf8341f8a2bf7` | `c35c2dae084a5c7decda32069e7c91d09b1ecc06` | [#51](https://github.com/akefadaka-git/arna-app/pull/51) | [#42696899879 — PASS](https://github.com/akefadaka-git/arna-app/actions/runs/42696899879) |

### API workflow evidence

All exact-head API gates passed:

- Exact checkout verification, locked Composer dependencies, PHP lint, and clean MySQL migrations.
- Frozen audience and wildcard authorization: **9 passed, 66 assertions**.
- Route, metadata disclosure, and no-store: **4 passed, 25 assertions**.
- A4 fresh authorization, stale-cache revocation, mixed-entity and tenant-isolation suite: **14 passed, 36 assertions**.
- Finance permission catalogue: **2 passed, 46 assertions**.

The workflow now creates an empty local `.env` file in CI before dependency/test bootstrap, eliminating the prior missing-file warning noise. This does not add or expose environment secrets.

### App workflow evidence

All exact-head App gates passed:

- Node **22.12.0** is used to satisfy the declared `@axe-core/playwright` engine requirement.
- Type-check and all production builds passed.
- Executive Overview route/permission/metadata contracts passed.
- Workspace dashboard route integration passed.
- Chromium installation and public browser/WCAG 2.2 contracts passed.
- Public browser suite: **9 passed**.
- Authenticated Executive Overview suite: **9 passed**, including the R3 non-cancellable logout and tenant-race cases.

The npm audit summary still reports **3 moderate dependency advisories**. No forced dependency upgrade was applied; this remains a separate dependency-maintenance task and is not represented as fixed by A4.

## 3. R3 remediation and finding disposition

| R1 finding | R3 implementation and R5 result | Disposition |
|---|---|---|
| A4-QA-01 — logout test did not establish loaded financial figures before logout | Test first renders and asserts synthetic financial figures, starts a second deferred request, logs out, then resolves the deliberately non-cancellable operation. Old and late figures remain absent. | VERIFIED-CLOSED |
| A4-QA-02 — tenant test relied on request cancellation | Deferred service ignores AbortSignal; tenant B loads before the old tenant A operation resolves. Old A data cannot enter B's view. | VERIFIED-CLOSED |
| A4-QA-03 — missing A → B → A transition | Three requests record identities `[101, 202, 101]`; B intermediate and first-A late responses cannot overwrite the final A session. | VERIFIED-CLOSED |
| A4-QA-04 — incomplete mixed-entity matrix | API test includes two authorized tenant-A entities and a tenant-B entity, verifies only the two tenant-A report calls occur, and checks distinct per-entity summaries. Consolidation rejects a foreign-tenant member before returning any aggregate. | VERIFIED-CLOSED |
| A4-QA-05 — incomplete direct/role grant and scope matrix | Tests cover direct Finance report grant without the role grant, inactive permission, inactive role, inactive Finance application, wrong application assignment, wrong role tenant, wrong permission tenant, and application-scoped report/entity permissions. | VERIFIED-CLOSED |

### Additional authorization hardening

- Executive Overview Finance report access now requires an active **tenant-scoped** Finance permission from authoritative database pivots.
- Finance entity policies also require canonical tenant-scoped Finance permissions.
- Finance report composition is injected through the existing `FinancialReportContract`; the final `FinancialReportService` remains final and implements that contract.
- The controller queries legal entities within the trusted tenant and checks the Finance entity policy before each report read.
- Sensitive responses retain `Cache-Control: private, no-store`; denial responses do not disclose hidden widget names or permission metadata.
- Frontend request sequence identity remains authoritative even when a service promise completes after AbortSignal cancellation. Production defaults to the real API client; deferred injection is confined to the test harness.

## 4. R5 technical retest

R5 re-read the final API and App source, the authorization policies, Finance permission catalogue, report contract/service binding, tenant-scoped consolidation guard, frontend request sequencing, deferred browser harness, and exact-head workflow evidence.

**R5 conclusion:** no unresolved A4-scoped critical or high issue found. The five R1 QA findings are verified closed by the final implementation and tests.

The final workflow-only commits after the source review changed only:
- API: `.github/workflows/executive-overview-a4-api.yml` (deterministic empty `.env` creation).
- App: `.github/workflows/executive-overview-a4-app.yml` (Node 22.12.0).

The final R4 runs above verify those exact final code heads. No source-code change was made after the R5 source review.

## 5. Explicitly retained risks and separate maintenance items

### RISK-A4-01 — OPEN, platform-wide

`InvalidateAuthorizationCacheListener` still catches and logs cache invalidation failures. Other platform permission consumers can retain stale positive cache answers until the 300-second TTL expires if invalidation fails.

This is **not fixed globally** by A4. The Executive Overview audience, Finance report, and Finance entity checks read authoritative database pivots directly, so the listener's stale positive cache cannot preserve access through this endpoint. A durable invalidation/outbox or authorization-generation design remains a separate governed platform work item.

### Existing Finance domain regression failures — confirmed pre-existing

A supplemental full Finance-domain suite was run on the R3 candidate and on a separate control branch created from the untouched A4 API baseline:

- Candidate diagnostic run [#42695728565](https://github.com/akefadaka-git/arna-api/actions/runs/42695728565), code SHA `0b4e65d2787cb9ddad6f404701cc1bdd7e7a60ac`: **3 failed, 47 warnings, 199 assertions**.
- Untouched-baseline control [#42696127777](https://github.com/akefadaka-git/arna-api/actions/runs/42696127777), baseline code SHA `9f5ea1ee0e221bca5007890178f249cdba20c992`: **the same 3 failures, 47 warnings, 199 assertions**.

The identical pre-existing failure signatures were:
1. An effective date not belonging to an open accounting period.
2. A General Ledger test expecting a `source_type` validation error but receiving a different validation error first.
3. A `JournalEntry` lookup for ID 16 returning no model.

These failures are present on the untouched A4 baseline and are not introduced by R3. They remain a separate Finance maintenance item. The final A4 workflow uses the focused A4 security/authorization suites and Finance permission catalogue gate, all of which pass on the final API SHA.

## 6. Final A4 disposition

**A4: VERIFIED-CLOSED — Executive Overview scope.**

- A4-QA-01 through A4-QA-05: VERIFIED-CLOSED.
- R4 exact-head API and App CI: PASS.
- R5 fresh technical retest: PASS.
- RISK-A4-01: OPEN, explicitly carried outside A4 endpoint closure.
- Existing full Finance-suite failures: reproduced on the untouched baseline; separate maintenance item.
- Draft remediation PRs #102 (API) and #51 (App) remain open and unmerged.
- Original A4 baseline PRs #101 (API) and #50 (App) remain untouched.
- No merge, production role grants, signing, deployment, or A5 action was performed or authorized by this closure.

**Next gate:** any merge or deployment requires its own explicit Owner authorization under the separate A5 process.
