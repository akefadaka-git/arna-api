# ARNA D01 — A2 Route-Level Traceability Supplement

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.2
- **Type:** Controlled route-level evidence supplement
- **Prepared:** 2026-11-24
- **Parent matrix:** `ARNA-D01-A2-TRACE-001 v1.0.0`
- **Parent matrix commit:** `f9e5fda2360b2f4d44f1a13efe2ac318b40b70df`
- **Controlling errata:** `ARNA-D01-A2-TRACE-001 v1.0.1`, commit `c2d064ebd5159e8c9a6f3a3dd2f00dd6a6a480f8`
- **API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`

## 1. Verified route-level source references

The following exact route files were re-fetched from the repository main branches and their relevant route declarations inspected.

| Repository source | Blob SHA | Route evidence | D01 interpretation |
|---|---|---|---|
| API `routes/api.php` | `7284faefc156548394a9ca7edfc2ead791773fb5` | `GET /api/dashboard/v1/overview` is inside `auth:sanctum` + `tenant.membership`; route additionally requires `dashboard.executive.view` and throttle. | Existing read-only executive overview; not a strategy lifecycle route |
| API `routes/api.php` | Same blob | Corporate CMS administration uses `auth:sanctum` + `public-content.review`, with page create/update/lifecycle/review routes. | Public content editing/review/publication only; not GA/Board strategy approval |
| App `src/router/routes-workspace.ts` | `84efa77c5adfe6f4a2d109aa1d5061626a5d66da` | `executive-overview` route loads `ExecutiveOverviewView.vue`, requires `dashboard.executive.view`, and lists UX role gates `ceo`, `president`, `finance_manager`. | Executive dashboard UI; not D01 planning |
| App `src/features/executive-overview/api.ts` | `866e5ce88a4fc77d8feb3b4a34bceb88588d28a6` | Calls `GET /api/dashboard/v1/overview`; response union currently declares Finance trial-balance and People active-workforce widgets. | No strategy/objective/scenario/planning data contract |
| App `src/features/executive-overview/ExecutiveOverviewView.vue` | `4e5d7a115e7788ee6ad7f22dca0bfdcf194ff7eb` | UI clears prior sensitive data on user/tenant changes and cancels superseded requests; renders returned widgets. | Shared UI/security pattern only |
| App `tests/executive-overview-route-contract.test.mjs` | `e10707d156aa2e6254ae84348568b9a1a0276aab` | Static assertions cover route, permission/role UX gates, API path, tenant changes and sensitive-state clearing. | Test source exists; NOT RUN in this D01 A2 pass |

## 2. Route and feature boundary

The API route declaration and controller establish an Executive Overview surface that returns only Finance and People reporting widgets. Its authorization policy is a frozen dashboard audience policy; it does not verify that a person is currently appointed as a company director, executive or strategy approver.

The App route and sidebar UX gate mirror the dashboard role/permission identifiers, but frontend guards remain advisory. Backend authorization is authoritative.

The Corporate CMS route group allows content administration and lifecycle decisions under the platform-wide `public-content.review` permission. The public-site A1 and CMS authority documents require editor/reviewer separation and published-only public delivery. This is a content publication control, not corporate strategy adoption by the competent GA/Board authority.

## 3. Traceability impact

This supplement refines the source mapping for:
- **D01-A2-02 / 03 / 04 / 05:** route existence and dashboard audience policy do not prove adopted strategy, corporate authority or current appointments.
- **D01-A2-09 / 11 / 12:** Executive Overview is an existing Finance/People reporting consumer, not an approved strategic objective/KPI/BI cascade.
- **D01-A2-15 / 16:** exact API and App route sources are now identified; full repository-tree and all-feature enumeration remain PARTIAL.
- **D01-A2-18 / 23:** tenant and role/permission controls are existing shared patterns; D01-specific access matrix and segregation-of-duties policy remain unapproved.
- **D01-A2-20:** the App uses the shared calendar formatter and locale store in the Executive Overview, but D01 planning-period semantics and acceptance evidence remain missing.

The 24-item status distribution is unchanged and is governed by traceability errata v1.0.1:
- PARTIAL: 16
- MISSING: 7
- UNRESOLVED: 1
- VERIFIED-CLOSED: 0

## 4. Verification and limits

- Exact API/App route files were fetched from the specified main baselines and relevant route declarations inspected.
- Related controller, policy, UI, API-client and test source files were fetched and inspected as recorded in matrix v1.0.0.
- No route-list command, test suite, frontend build, API test, database query or production request was executed.
- No application file, route, permission, migration, schema, seed or runtime configuration was changed.
- This route-level supplement does not complete the exhaustive tree/path inventory and does not authorize A3, merge, A4 or A5.

**Disposition: D01 A2 remains ACTIVE — PARTIAL — NOT VERIFIED-CLOSED.**
