# ARNA D01 — A2 API/App Inventory and Requirement-to-Source Traceability

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.0
- **Stage:** A2 — read-only reconciliation
- **Status:** **ASSESSMENT COMPLETED FOR THIS INVENTORY PASS; D01 A2 REMAINS PARTIAL**
- **Prepared:** 2026-11-24
- **Repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`
- **API main baseline:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main baseline:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Evidence-register parent:** `ARNA-D01-A2-EVID-REG-001 v1.0.3`, commit `0e103950e65c8b6ec895dae9d6fbe63bee19b43b`

## 1. Purpose and control boundary

This record advances the D01 A2 assessment by tracing the 24 existing evidence requirements to repository sources, platform capabilities, related domain contracts, and remaining evidence gaps.

The work is read-only against both application repositories. This document does not modify application source, schema, migrations, seeders, permissions, runtime configuration or production data. It does not authorize A3, merge, A4 or A5. No new documents, server access or Owner uploads are requested.

**Important distinction:** a platform capability, neighboring-domain feature, dashboard, public CMS record, source-code route or draft specification is not automatically a D01 strategy-management capability or proof of corporate adoption.

## 2. Exact source baselines and inspected artefacts

### 2.1 Repository baselines

| Repository | Branch | Baseline SHA | Identity / boundary |
|---|---|---|---|
| `akefadaka-git/arna-api` | `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Laravel backend; owns business rules, persistence, tenant scope and authoritative authorization |
| `akefadaka-git/arna-app` | `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Vue 3 / TypeScript frontend; owns presentation and client integration, not authoritative business rules or authorization |

These are the exact baselines used for this pass, matching the API/App baselines already recorded in the D01 evidence register. This is not a claim that the repositories were freshly tested during this documentation pass.

### 2.2 API source inventory

| Source path | Blob SHA | Evidence and D01 relevance | Classification |
|---|---|---|---|
| `routes/api.php` | `7284faefc156548394a9ca7edfc2ead791773fb5` | Central API route tiers distinguish public, authenticated, diagnostic and tenant-scoped routes. Executive Overview is registered under authenticated tenant-scoped access; public corporate pages are published-only reads; corporate CMS administration has a separate permission-gated route group. | Shared platform/API routing; not a D01 strategy route |
| `app/Http/Controllers/ExecutiveOverviewController.php` | `9de8f7c0f15aefaff928279a96965a0db2da5491` | Read-only executive overview obtains Finance trial-balance widgets and a People active-workforce aggregate after tenant and independent domain-permission checks. | Existing executive dashboard aggregation; not strategic planning |
| `mases_core/src/Authorization/ExecutiveOverviewAccessPolicy.php` | `cb4b5268022d89b463b226cb67249a08f19d8181` | Frozen entry audience is CEO, President or Finance Manager, tenant-bound; widget data is separately authorized. | Shared authorization control; does not establish the company's current office-holders |
| `tests/Feature/ExecutiveOverviewRouteTest.php` | `cb331992c72eeb71ae0e7b2e1fa50653abdb824d` | Feature-test source exists for Executive Overview route/audience behavior. | Test source exists; not executed in this A2 pass |
| `docs/dashboard/ARNA-EXECUTIVE-OVERVIEW-A4-SECURITY-VERIFICATION.md` | `70aa07dc63465832a167b174ca98e5ed6e7cfba9` | Records A4 security/isolation closure for the Executive Overview feature branch, with explicit limitations on merge, production grants and deployment. | Feature-specific A4 record; not D01 A2 acceptance |
| `docs/governance/P1.3-governance-contract-and-correction-plan.md` | `adce193eb1cbf073652c1aaa4d1bfb90955a9eea` | Documents Governance bodies, positions, terms, appointments, memberships, services, policies, controllers, migrations and test foundations, plus identified behavior gaps. | D03 governance source; not strategy approval evidence |
| `mases_core/src/Domains/Governance/Routes/api.php` | `9fdd4bcb8c2b189748fa3cba39e3c73de3568c36` | Governance routes cover bodies, positions, terms, appointments and memberships. The route file explicitly does not expose certain lifecycle transitions. | D03 operational routes; no D01 strategy lifecycle |
| `mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php` | `179ae2ec24dcc26820555b26d41992d76f39f3c7` | Provider registers Governance bindings, policies and audit event listeners. | D03 service wiring |
| `mases_core/src/Domains/Governance/Config/governance.php` | `e9c7cc5080ac454e1583a46877b7d147b2a7232f` | Governance domain configuration foundation. | D03 configuration |
| `database/seeders/CorporateSiteBaselineSeeder.php` | `63c8399653053e46b6a605da7622c5622d7755bb` | Inserts only missing corporate pages as drafts; does not approve or publish; includes Owner-provided vision/mission text. | Public content seed; not adopted strategy |
| `app/Http/Controllers/CorporateSiteController.php` | Located by repository search | Public corporate page reads use published content. | Public content delivery; not D01 strategy service |
| `mases_core/src/Domains/PublicContent/Models/CorporateSitePage.php` | Located by repository search | Corporate page model supports the public-content storage layer. | CMS model; not D01 strategy model |
| `docs/governance/ARNA-GOV-POL-PSC-001.md` | `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Committee/procurement/sales/control policy is version 0.1.0, explicitly draft and pending formal corporate approval. | Proposed policy; not adopted authority |
| `docs/governance/ARNA-GOV-GA-A2-CLOSURE-A3-IMPLEMENTATION-CONTRACT.md` | `691012753ab51604ed487b0edf6a8ca0ef4aa5d4` | Technical GA contract requires authority evidence, certified resolutions and fail-closed treatment of unconfigured quorum/voting rules. | GA technical contract; not proof that a specific strategy resolution exists |

### 2.3 App source inventory

| Source path | Blob SHA | Evidence and D01 relevance | Classification |
|---|---|---|---|
| `README.md` | `e0a341263168f177e455f313d0ece04ff55eb839` | Confirms the four application builds and that frontend does not own backend business rules, persistence, tenant isolation or authoritative authorization. | Repository boundary |
| `src/router/index.ts` | Located by repository search | Router factory and route-tree composition. | Shared routing |
| `src/router/routes-workspace.ts` | Located by repository search | Authenticated workspace routes; contains Executive Overview route. | Workspace route source |
| `src/router/routes-admin.ts` | Located by repository search | Admin route tree; no D01 strategy route surfaced in targeted search. | Admin routing; inventory not exhaustive |
| `src/features/executive-overview/api.ts` | `866e5ce88a4fc77d8feb3b4a34bceb88588d28a6` | Calls `GET /api/dashboard/v1/overview`; types only Finance trial-balance and People active-workforce widgets. | Existing executive dashboard API client; not D01 strategy data |
| `src/features/executive-overview/service.ts` | `254a486bd2d29f747df59c08afe0fff3bed3a2c6` | Thin API/request-injection boundary. | Dashboard service |
| `src/features/executive-overview/ExecutiveOverviewView.vue` | `4e5d7a115e7788ee6ad7f22dca0bfdcf194ff7eb` | Renders API-returned widgets, clears sensitive state on identity/tenant changes and aborts superseded requests. | Executive dashboard UI; not strategy planning |
| `tests/executive-overview-route-contract.test.mjs` | `e10707d156aa2e6254ae84348568b9a1a0276aab` | Static contract tests for route, role/permission UX gates, API client, tenant changes and sensitive-state clearing. | Test source exists; not executed in this A2 pass |
| `docs/dashboard/ARNA-DASHBOARD-A3-POLICY-IMPLEMENTATION-RECONCILIATION-2026-11-09.md` | `a23d11652f428793c5a85078154179f1f558e84f` | Describes the Executive Overview audience and Finance/People widget authorization boundaries. | Dashboard implementation record |
| `docs/public-site/CORPORATE_CONTENT_DESIGN_A1.md` | `942f7cad142ae268d8af1f9108f5f28558bbeb0b` | Owner-approved/frozen A1 for public corporate website; requires vision/mission/objectives to respect approval status and source authority. | Public website A1; not D01 corporate strategy adoption |
| `docs/public-site/CORPORATE_CMS_ADMIN_EDITING_AND_PUBLICATION_AUTHORITY.md` | `721407c447c76f54961ea5bd27df2b82fc10228a` | Defines CMS editing/review/publication separation and server-side enforcement. | Public content publication controls; not GA/Board strategy authority |
| `docs/public-site/MAALE_ARNA_BYLAWS_CONTENT_MAP.md` | `5f37cee13a1b38e3048b3b4db3ef3dd068e18fc2` | Distinguishes bylaws-based corporate purpose from Owner-provided vision/mission/strategy. | Public content source map; not adoption evidence |

### 2.4 Search coverage and limitations

Search terms included strategy, strategic objectives, planning cycles, scenarios, baselines, strategy approval, objective, dashboard, Executive Overview, governance, route registration and related feature names. The search located the Executive Overview and Governance capabilities described above, but did not locate a dedicated D01 strategy version register, objective hierarchy, scenario/baseline workflow, strategy approval workflow, planning-cycle engine or strategy-to-domain cascade.

This was a targeted code/document search plus inspection of selected route, provider, controller, policy, feature, test and contract files. It was not a complete repository tree export, every-file static analysis, route-list execution, database inspection, full test run or production inspection. Therefore:
- “Not located” means not found within the stated search and inspection scope.
- It does not prove absolute absence from every file, branch, database or environment.
- The remaining exact-head full inventory stays PARTIAL until systematic tree/path enumeration and relevant route/provider/test coverage is completed.

## 3. Key architecture conclusions

### 3.1 Executive Overview is not D01

The existing Executive Overview is a read-only, role-gated, tenant-aware aggregation surface. The inspected API response types and controller expose Finance trial-balance and People active-workforce widgets. The implementation does not expose:
- approved strategy versions or effective dates;
- strategic themes/pillars or objective hierarchies;
- planning cycles, submissions or reviews;
- scenario, assumption or baseline records;
- objective ownership or domain cascades;
- strategy amendment/supersession decisions;
- strategy-to-budget, portfolio or KPI traceability.

It is therefore reusable only as a possible future presentation pattern. It is not evidence that D01 has been implemented.

### 3.2 Governance domain is not strategy authority

Governance routes and services manage governance bodies, terms, positions, appointments and memberships. They provide a potential technical source for governance records, but source-code existence does not authenticate current office-holders, the operative governing instruments, quorum, delegation or a particular strategy approval decision.

The draft committee/procurement policy remains explicitly unadopted. The GA technical contract is a technical contract, not a corporate resolution.

### 3.3 Public CMS is not the strategy system of record

The corporate CMS stores and publishes corporate pages under a distinct review/publication workflow. Its Owner-provided vision/mission content and public-site A1 requirements must retain source attribution and approval-state qualification. CMS publication approval cannot substitute for competent GA/Board adoption of corporate strategy.

## 4. D01 requirement-to-source traceability matrix

**Status meanings**
- **LOCATED — SUPPORTING:** source exists and supports part of the requirement; not necessarily accepted or complete.
- **PARTIAL:** source evidence exists but important requirements or acceptance evidence are missing.
- **NOT LOCATED:** no qualifying source found in this bounded inspection; full inventory remains incomplete.
- **UNRESOLVED / FAIL-CLOSED:** cannot be used to authorize a corporate decision.
- **NOT RUN:** a test or workflow source exists, but it was not executed during this pass.

| ID | D01 requirement | API source / result | App source / result | Current disposition and closure evidence |
|---|---|---|---|---|
| D01-A2-01 | Operative bylaws, MoA/AoA and certified amendments | Governance and legal records indexed; no original registered instrument independently authenticated in this pass | Bylaws content map is a source map, not legal authentication | **UNRESOLVED.** Preserve 17-page scan and 22-page DOCX as distinct sources; establish registered instrument relationship and current effect |
| D01-A2-02 | Current GA/Board strategy approval resolutions | GA technical contract describes certified resolution evidence; no specific adopted strategy resolution located | Public content is not a resolution | **MISSING / FAIL-CLOSED.** Need decision reference, competent body, date, quorum/vote, exact strategy/version and effective date |
| D01-A2-03 | Authority, delegation and approval matrix | Governance policy v0.1.0 is draft; document approval matrix/default-deny controls are supporting controls only | CMS publication authority applies to public content only | **PARTIAL.** Verify operative legal clauses and current adopted delegation/reserved matters; do not infer strategy approver |
| D01-A2-04 | Current org chart and accountable executive/planning appointments | Governance models/routes support appointment records; current appointment evidence not verified | No D01 accountable executive or planning-owner UI contract located | **PARTIAL.** Current effective-dated appointments and planning-owner assignment remain unverified |
| D01-A2-05 | Adopted strategy, multi-year plans and prior versions | Seeder contains Owner-provided vision/mission but creates draft content; no D01 strategy register located | Public-site A1 requires source authority and approval-state respect | **PARTIAL.** Owner-provided statements are not an adopted strategy; need approved version history and effective/supersession evidence |
| D01-A2-06 | Approved planning calendar and cycle milestones | No D01 planning-cycle engine or approved calendar located | E.C. date utility exists as a shared platform capability; not a D01 planning calendar | **PARTIAL.** Define approved horizon, cycle, submission/review/approval milestones and E.C./G.C. rules |
| D01-A2-07 | Strategic hierarchy and objective cascade | No objective register/hierarchy source located | No objective-cascade feature located | **MISSING.** Need approved themes, objectives, parent-child links, owners, periods and domain-plan traceability |
| D01-A2-08 | Scenarios, assumptions, baselines, versioning and change records | No qualifying D01 scenario/baseline contract located | No corresponding UI or API client located | **MISSING.** Need scenario methodology, assumptions, baseline provenance, versions, approvals and change history |
| D01-A2-09 | Strategy-to-budget/investment decisions | Finance and LegalEntity/reporting contracts exist; no strategy-objective/capital decision linkage located | Executive Overview displays Finance reporting only | **PARTIAL.** D25 retains capital approval; D01 alignment is not spending authority; need approved traceability links |
| D01-A2-10 | Strategy-to-project/portfolio intake and handoff | No D01-to-D07 approved intake contract located | No strategy-to-portfolio UI contract located | **MISSING.** Need objective references, prioritization decision, portfolio intake, delivery owner and benefits handoff |
| D01-A2-11 | D02 KPI dictionary and objective-to-KPI mapping | KPI ownership register PR #220 is draft; no approved D01 KPI map located | Executive Overview contains Finance/People widgets, not strategic objective KPIs | **PARTIAL.** Need approved KPI definitions, formulas, targets, periods, owners and objective mappings |
| D01-A2-12 | D23 certified BI datasets and lineage | No D01-certified strategy dataset/semantic contract located | Dashboard displays typed widget sources, not D01 strategy lineage | **PARTIAL.** Need certified semantic contract, objective IDs, lineage, freshness, quality and access evidence |
| D01-A2-13 | D04 risk appetite and strategic-risk links | No accepted D04 risk appetite or D01 strategic risk mapping located | No D01 risk-planning feature located | **MISSING.** Need approved risk appetite, taxonomy, register, owners and strategic risk/assumption mapping |
| D01-A2-14 | Legal/regulatory obligations and legal advice | Legal/governance source leads and statutory context exist; source identity and company-specific effect unresolved | Public content rules prohibit unsupported legal/leadership claims | **PARTIAL.** Need certified instruments, obligations register and competent Ethiopian legal review |
| D01-A2-15 | Exact-main API D01 source inspection | Route/provider/controller/policy/test search located Governance, CMS and Executive Overview; no dedicated D01 strategy contract located | N/A | **PARTIAL.** Selected source inventory completed; exhaustive tree, route:list, model/migration/event/test scan not run |
| D01-A2-16 | Exact-main App D01 feature/UI inspection | N/A | Router, workspace route, Executive Overview UI/API/service/tests and public content docs inspected; no D01 strategy workspace located | **PARTIAL.** Selected source inventory completed; exhaustive route/navigation/feature/permission scan not run |
| D01-A2-17 | D01 branches, PRs and workflow evidence | PR #219, #220, #222, #223 are documentation drafts; no D01 A3 implementation PR | Executive Overview A4 test/workflow sources exist for that separate feature | **PARTIAL.** Reconcile D01 branches/PRs and Actions on exact heads; dashboard A4 evidence cannot close D01 |
| D01-A2-18 | Identity, tenant, RBAC and authorization conventions | API route groups and ExecutiveOverviewAccessPolicy enforce tenant-bound access and separate widget permissions | App route/sidebar UX gates use role/permission; backend remains authoritative | **PARTIAL — PLATFORM FOUNDATION LOCATED.** D01-specific access matrix, entity scope, delegations and tests remain unapproved |
| D01-A2-19 | Audit, retention, records and legal-hold policy | Governance event listeners and CMS audit/version controls exist; no D01 retention schedule located | CMS editing/publication contract requires version history and audit | **PARTIAL.** Need approved D01 retention schedule, immutable decision evidence, custodian and legal-hold rules |
| D01-A2-20 | Amharic-first, E.C. calendar and accessibility | API date/data contracts support ISO/API date handling; no D01-specific calendar acceptance suite located | Executive Overview uses shared calendar formatter and locale store; public A1 is Amharic-first and targets WCAG 2.2 AA | **PARTIAL — SHARED FOUNDATIONS LOCATED.** Need D01 glossary, planning-period semantics, translation review and accessibility acceptance |
| D01-A2-21 | Data dictionary, lineage, reference IDs and freshness | Executive Overview widgets identify source domain, metric/report key and calculation version; no D01 objective identifiers or dictionary located | App types mirror those two widget shapes only | **MISSING for D01.** Need canonical strategy/objective IDs, definitions, lineage, effective dates, quality and freshness |
| D01-A2-22 | Strategy process maps, templates, consultation and review minutes | No accepted D01 process pack located | No D01 planning forms/workflow located | **MISSING.** Need approved strategy formulation, annual planning, consultation, review and exception processes |
| D01-A2-23 | Security/privacy, access matrix and segregation of duties | Executive Overview demonstrates role/tenant and domain permission separation; CMS has editor/reviewer separation; no D01-specific access matrix | App UX gates are advisory; server authorization remains authoritative | **PARTIAL — CONTROL PATTERNS LOCATED.** Need D01 classification, proposer/reviewer/approver roles, conflict rules and publication permissions |
| D01-A2-24 | Accepted D01 dependencies and producer/consumer contracts | Governance, Finance, People, CMS and platform contracts exist; no accepted D01 interface contract located | Dashboard widget types expose Finance and People source references only | **PARTIAL.** Need Owner-accepted D01 contracts with D02/D07/D23 consumers and D03/D04/D06/D08/D25/D26 dependencies |

## 5. Traceability summary

| Measure | Result |
|---|---:|
| D01 A2 requirements traced | 24 / 24 |
| Requirements with some supporting source evidence | 15 |
| Requirements still PARTIAL | 13 |
| Requirements MISSING | 7 |
| Requirements UNRESOLVED | 1 |
| Requirements VERIFIED-CLOSED | 0 |

The 15 source-supported count is a traceability indicator, not an acceptance count. The status totals preserve the original evidence register's 16 PARTIAL, 7 MISSING and 1 UNRESOLVED baseline: this pass adds more precise source references but does not promote any requirement to VERIFIED-CLOSED. D01-A2-15 and D01-A2-16 remain PARTIAL because the selected inventory is not exhaustive.

## 6. Work not performed

- No repository source, route, model, migration, seeder, permission, test or runtime code changed.
- No migration or database query executed.
- No application build, type check, test suite or GitHub Actions workflow was run as part of this A2 documentation pass.
- No production system, server or live user data accessed.
- No PR merged; no main branch modified.
- No legal conclusion or corporate approval inferred from source code or draft documents.

## 7. Remaining A2 work — proceed independently

1. Complete exhaustive API path enumeration for D01-relevant models, services, contracts, policies, providers, routes, events, migrations, seeders and tests.
2. Complete exhaustive App path enumeration for workspace/admin routes, sidebar/navigation, features, API clients, permission gates and tests.
3. Inspect exact route registration and provider bindings for any candidate D01-related capability; separate Executive Overview, CMS and Governance from strategy management.
4. Reconcile the 24 requirements against the frozen 30-domain catalogue and record every producer/consumer interface, owner and version boundary.
5. Build the A2 gap-to-acceptance criteria and proposed D01 test matrix, without creating implementation code.
6. Keep legal instrument identity, strategy adoption and current office-holder questions isolated as unresolved evidence matters; do not let them block unrelated inventory work.

## 8. Gate disposition

- **D01 A2:** ACTIVE — PARTIAL — NOT VERIFIED-CLOSED.
- **Inventory pass:** selected API/App source inventory completed; exhaustive enumeration remains open.
- **Traceability matrix:** v1.0.0 created for all 24 requirements.
- **A3:** NOT AUTHORIZED.
- **A4:** NOT STARTED for D01.
- **A5:** NOT AUTHORIZED; separate gate preserved.
- **Merge/deployment:** NOT AUTHORIZED.

**Conclusion:** the current repositories provide reusable Governance, authorization, CMS, executive dashboard, localization and audit patterns. They do not, on the inspected evidence, establish a dedicated accepted D01 corporate strategy and enterprise planning system. The next A2 work is exhaustive source enumeration and acceptance traceability—not software implementation.

**End of record.**
