# ARNA D01 — A2 Complete Path Inventory and Cross-Domain Acceptance Supplement

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.4
- **Type:** Controlled exact-main path-inventory and interface-acceptance supplement
- **Prepared:** 2026-11-24
- **Parent matrix:** v1.0.0, commit `f9e5fda2360b2f4d44f1a13efe2ac318b40b70df`
- **Controlling arithmetic errata:** v1.0.1, commit `c2d064ebd5159e8c9a6f3a3dd2f00dd6a6a480f8`
- **Prior route supplement:** v1.0.2, commit `ac2fc45f5fbe54e1485632b46641ae94b9241aec`
- **Prior search supplement:** v1.0.3, commit `01da9dfa117b42445e3d6800dd892d17c17790df`
- **API main inspected:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App main inspected:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`

## 1. Purpose and scope

This supplement advances D01 A2 by enumerating the complete Git tree at the two frozen repository commits, identifying the full set of domain roots and application route/navigation source files, and refining the D01-to-domain interface acceptance criteria.

This remains a source-level, read-only reconciliation. A complete Git path listing is not equivalent to reading every source file, running Laravel's route registry, querying a database, or proving runtime behavior. The source inventory is complete at the path-enumeration level for the two exact commits; candidate-source inspection and runtime verification remain separate activities.

## 2. Complete Git tree enumeration

GitHub's recursive Git Trees API returned both exact commit trees with `truncated=false`.

| Repository | Exact commit | Tree entries | Blob/file entries | Truncated |
|---|---|---:|---:|---|
| `akefadaka-git/arna-api` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | 1,279 | 1,009 | false |
| `akefadaka-git/arna-app` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | 632 | 480 | false |

### 2.1 API domain roots

The complete API tree contains these ten roots under `mases_core/src/Domains`:

1. Documents
2. FileManagement
3. Finance
4. Governance
5. Loan
6. Organization
7. People
8. PublicContent
9. Shareholder
10. Wallet

No `D01`, `StrategyManagement`, or dedicated `StrategicPlanning` domain directory exists at the inspected API commit. The only path containing the literal `D01` in the complete API tree is `.github/workflows/arna-d01-login-throttling.yml`; it is an authentication-throttling workflow, not a D01 strategy capability.

### 2.2 App route and feature roots

The complete App tree contains route tables:
- `src/router/routes-base.ts`
- `src/router/routes-workspace.ts`
- `src/router/routes-portal.ts`
- `src/router/routes-admin.ts`

The workspace feature directories include Governance, Calendar, Dashboard, Executive Overview, People, Finance-adjacent wallet/loan interfaces, Shareholders, Files, Communications, and other application features. No D01 strategy-planning feature directory or dedicated strategy workspace route was identified.

The complete path inventory does not establish that no future or external service exists. It establishes that no dedicated D01 strategy module is present in the exact `main` tree inspected.

## 3. Newly verified API registration and source boundaries

| Source | Blob SHA | Finding | D01 interpretation |
|---|---|---|---|
| `bootstrap/providers.php` | `b94472b043139c34a1aa9ea1e975d7fbb2241650` | Central Laravel provider registry explicitly registers Governance and Finance providers. Finance is provider entry 10. | Finance and Governance domain services/routes are intended to be registered; this is not D01 strategy functionality. |
| `mases_core/src/Domains/Governance/Providers/GovernanceServiceProvider.php` | `179ae2ec24dcc26820555b26d41992d76f39f3c7` | Loads Governance migrations and routes under configured prefix/middleware; binds governance services/policies and audit event listeners. | Governance lifecycle foundation is registered. No strategy version, objective, planning-cycle or scenario service is registered here. |
| `mases_core/src/Domains/Governance/Routes/api.php` | `9fdd4bcb8c2b189748fa3cba39e3c73de3568c36` | Routes cover bodies, positions, terms, appointments, memberships and related governance functions. | Governance-body and office-holder lifecycle only; no strategy approval or strategy-plan API route. |
| `mases_core/src/Domains/Governance/Enums/GovernanceBodyType.php` | `7423a0538405bdfa37b6a905dbb7da46e0874f2a` | Defines generic assembly, board, committee, subcommittee and ad-hoc body types. Its documentation describes Strategic Planning as an example committee. | A committee may be represented as a generic Governance body; this is not a strategy-management engine. |
| `mases_core/src/Domains/Governance/Database/Migrations/2026_09_18_100000_create_governance_bodies_table.php` | `9f9652ca5c2f2a53bcb77572b2c4ce2f2353497f` | Creates tenant-scoped governance bodies and their structural/mandate/quorum/effective-range attributes. The migration explicitly says meetings, agendas, minutes, resolutions, actions, delegations, declarations, elections and documents are outside this table. | A governance committee's existence/mandate can be represented, but strategy content, adoption decisions and planning cycles are not established by this schema. |
| `mases_core/src/Domains/Governance/Models/GovernanceBody.php` | `d56ba1a5955c5546c6f63c2ad5743df7a9c935d9` | Read-oriented model with tenant, parent/reporting, positions, terms, appointments and memberships relations. | Governance structure and authority metadata, not strategy/objective persistence. |
| `mases_core/src/Domains/Governance/Services/GovernanceBodyService.php` | `532b4be33a34f138c1dce2b47bacb816a271cd5e` | Transactional body registration/update/deactivation/reactivation with domain invariants and post-commit events; authorization is handled by controller/policy. | Reusable lifecycle and audit pattern; no D01 plan or objective lifecycle. |
| `mases_core/src/Domains/Finance/Providers/FinanceServiceProvider.php` | `84ce71513b5afbb9923b4c63e86153043539b8bd` | Binds Finance repositories/services/contracts, registers policies/events, loads Finance migrations and API routes when enabled. | Finance can be an authoritative producer for actual ledger/reporting facts; no strategy alignment contract located. |
| `mases_core/src/Domains/Finance/Routes/api.php` | `29f596d9b77ac38a027fd848ff168ccc66012c09` | Exposes entities, source mappings, accounts, periods, journals, trial balance, governance settlement, intelligence proposals, subledger inbox and integration health. | Existing financial operations/reporting are not a D01 budget-prioritization or strategy-investment approval workflow. |
| `mases_core/src/Domains/Finance/Contracts/FinancialReportContract.php` | `d94a16d692f6122122e8e5f3fa1870f83b434fc5` | Read contract exposes a trial balance by tenant, legal entity and as-of date. | Candidate source for realized financial performance, not strategic objectives or approved budget baselines. |
| `mases_core/src/Domains/Finance/Services/FinancialReportService.php` | `cffcf398bce79f582983e921990f2430c9e13827` | Produces entity-scoped trial-balance accounts and debit/credit/balance aggregates. | Candidate Finance producer for a future D01/D02/D23 data contract; no strategy objective IDs or target linkage. |

### 3.1 Important Governance distinction

The phrase “Strategic Planning” appears in Governance documentation as an example of a committee whose identity is stored as a generic governance-body row. The migration states that committee identities live in the body's code/name and that the Governance domain does not branch on a particular committee's code, name or type.

Therefore:
- **Supported:** ARNA can represent a Strategic Planning Committee as a Governance body, subject to authoritative establishment and permissions.
- **Not supported by this evidence:** strategic-plan authoring, objective hierarchy, scenario/baseline versioning, enterprise planning calendar, strategy adoption workflow, objective-to-budget linkage, or domain-plan cascade.
- **Not inferred:** that such a committee has been legally established, appointed, convened, or authorized to adopt company strategy.

## 4. Newly verified App route and navigation boundaries

| Source | Blob SHA | Finding | D01 interpretation |
|---|---|---|---|
| `src/router/index.ts` | inspected at App main; see exact source path | Scope-aware router composes base routes with workspace, portal or admin route trees and then catch-all. | No D01 route is introduced by router composition itself. |
| `src/router/routes-workspace.ts` | `84efa77c5adfe6f4a2d109aa1d5061626a5d66da` | Workspace includes Governance overview, body list/detail, attendance, people/membership views, Finance settlement view and Calendar. | Governance UI is committee/body/office-holder administration; no strategy-plan workspace. |
| `src/router/shell-labels.ts` | `ccc52aa553dbb4e6c922ea94f86f53aa0bd86872` | Workspace navigation includes Executive Overview, Governance and Calendar entries. Governance is permission-gated by `governance.body.view`; Executive Overview by `dashboard.executive.view` and UX role names. | No dedicated strategy/objective/planning navigation entry. |
| `src/layouts/AppShell/AppSidebar/AppSidebar.vue` | `1ba94fe1303524aef79e19613e0d8b2c0d472c66` | Sidebar filters navigation by permission and optional role; it is a presentation gate. | Frontend visibility does not replace server authorization or prove current corporate appointment. |
| `src/features/governance/GovernanceOverviewView.vue` | `f75e80515765218427c3ac804d0e790443339c02` | Overview fetches Governance body list and links to body, attendance and people pages. | Governance overview, not D01 strategic planning. |
| `src/features/governance/api.ts` | `b69c3083f2127f2ba70374ceca67b0a8320a8a01` | Typed API wrappers cover governance bodies, terms, positions, appointments and memberships. | No strategy-plan or objective API type. |
| `src/features/governance/service.ts` | `eb71ea6b878b822edc3aa96495019857dc16d281` | Composes body detail from body-scoped Governance endpoints. | No strategy lifecycle service. |
| `src/features/dashboard/DashboardView.vue` | `28612651e341958a019a99686a8caae292f46b2e` | Permission-filtered feature tile catalogue; dashboard is a navigation index, not an executive performance data surface. | Not an enterprise strategy dashboard. |
| `src/features/executive-overview/api.ts` | `866e5ce88a4fc77d8feb3b4a34bceb88588d28a6` | Typed Finance trial-balance and People active-workforce widget response. | Existing executive reporting only; no strategic objective, KPI target, scenario or planning-period contract. |

## 5. D01-to-domain producer/consumer acceptance matrix

The following is a proposed A2 acceptance matrix. It records the evidence boundary and the minimum contract that must exist before a D01 interface can be accepted. It does not approve a new dependency or implementation.

| Domain / capability | Existing source evidence | Candidate relationship to D01 | Minimum D01 acceptance evidence | A2 disposition |
|---|---|---|---|---|
| D03 Organization | Organization units, hierarchy/assignment policies and tenant-scoped organization services exist. | Producer of organizational structure and accountable-unit references; D01 consumer of approved organizational scope. | Canonical unit IDs, hierarchy/effective dates, owner assignment, tenant/legal-entity scope, version and authorization rules. | PARTIAL — source foundation; no accepted D01 mapping. |
| Governance | Bodies, positions, terms, appointments, memberships, authority grants and audit events exist. | Producer of governance authority/committee/appointment references; D01 consumer for proposal, review and competent approval evidence. | Authoritative instrument type/ID, body/position/appointment IDs, term/effective dates, delegated authority, quorum/vote/decision reference and immutable approval outcome. | PARTIAL — lifecycle foundation; no D01 strategy adoption workflow. |
| D08 People | Canonical People and workforce aggregate sources exist. | Producer of accountable-person references and workforce facts; D01 consumer of owner assignments and approved workforce assumptions. | Stable person IDs, active tenant access, role/assignment effective dates, privacy classification and workforce metric definitions. | PARTIAL — source foundation; no D01 ownership/assumption contract. |
| D04 Risk | Governance and policy patterns exist; no accepted D01 strategic-risk linkage located. | Producer of approved risk appetite/register references; D01 consumer of strategic risks and assumptions. | Approved appetite version, risk IDs, likelihood/impact method, owner, response, residual risk, tolerance and strategy linkage. | MISSING for D01 integration. |
| D06 Compliance / Legal | Governance, document and policy controls exist; company-specific legal instrument identity remains unresolved. | Producer of obligation/evidence references; D01 consumer of strategic constraints and compliance dependencies. | Jurisdiction, instrument citation/version, obligation ID, applicability, effective dates, accountable owner, evidence and legal review status. | PARTIAL — generic controls only; no accepted D01 obligation contract. |
| D02 KPI / Performance | Executive Overview has Finance and People reporting widgets; no accepted D01 KPI dictionary/objective map located. | Producer of approved KPI definitions and measured actuals; D01 consumer of strategic measures, targets and performance reviews. | Canonical KPI ID, definition, formula, unit, owner, source, grain, target, period, threshold, freshness, lineage and objective ID. | PARTIAL — reporting patterns only; no D01 KPI cascade. |
| D07 Portfolio / Projects | No accepted D01 strategy-to-project intake interface located in the inspected sources. | Consumer of approved strategic priorities and objective references; producer of delivery status/benefits evidence back to D01. | Strategy/objective IDs, prioritization decision, scoring method, sponsor, delivery owner, approved funding reference, stage gate, benefit owner and status lineage. | MISSING for D01 integration. |
| D23 BI / Data | Executive Overview exposes typed Finance/People widgets; Finance report contract provides trial-balance facts. | Producer of certified semantic datasets and lineage; D01 consumer of trusted actuals and executive views. | Dataset/metric IDs, semantic definitions, source lineage, transformation version, freshness, quality checks, tenant scope and row-level access evidence. | PARTIAL — two widget sources only; no D01 semantic model. |
| D25 Investment & Capital | Finance legal-entity, ledger, consolidation, exchange-rate and reporting capabilities exist; no strategy-to-capital allocation contract located. | Consumer of D01-approved strategic investment priorities; producer of approved capital decisions/actuals. | Investment ID, objective reference, valuation/assumptions, decision authority, approved amount/currency, legal entity, funding source, stage gate and realized actuals. | PARTIAL — Finance foundation; no D01 capital alignment contract. |
| D26 Business Development & Strategic Partnerships | No accepted D01 strategy-to-partnership opportunity interface located in the inspected sources. | Consumer of strategic themes and growth priorities; producer of opportunity/partnership evidence. | Opportunity/partner IDs, strategic fit, due diligence, risk/compliance review, owner, expected benefits, approval and outcome. | MISSING for D01 integration. |
| Calendar | Shared calendar API, event lifecycle, Ethiopian-calendar localization and workspace calendar UI exist. | D01 consumer for approved planning horizon, submission/review/approval milestones and strategy-review cadence. | Approved planning cycle ID, E.C./G.C. period semantics, timezone, milestone owner, event linkage, recurrence and change/audit history. | PARTIAL — shared calendar capability; no D01 planning calendar. |
| Documents / Records | Document registry, workflow aggregates, immutable evidence/signing and Ethiopian document-year resolver exist. | Producer/retainer of strategy versions, assumptions, approvals, minutes and evidence attachments. | Canonical strategy/document IDs, version lineage, signature verification, authority binding, retention, legal hold, classification and retrieval permissions. | PARTIAL — records foundation; no accepted D01 record schema. |
| Finance / Budget | Finance provider, trial balance, periods, journals, reports and integration contracts exist. | Producer of actuals and legal-entity/currency facts; D01 consumer for approved budget baselines and variance. | Budget version, account/cost-center mapping, legal entity, currency, period, scenario, approval, locked baseline, actuals lineage and variance rules. | PARTIAL — accounting/reporting only; no accepted budget-to-strategy contract. |
| Policy / Versioned policy | Versioned policy registry and policy documents exist. | Reusable version/effective-date pattern; D01 may reference approved methodology and strategic-planning policies. | Policy ID/version, effective date, approver, supersession, applicability and immutable decision history. | PARTIAL — generic policy pattern; not a strategy version register. |

### 5.1 Interface direction and authority constraints

- D01 must not become the system of record for Finance actuals, People identities, Organization hierarchy, Governance appointments, Risk registers, Compliance obligations, Portfolio delivery, or BI lineage.
- D01 should reference canonical producer IDs and preserve source version/effective date. It must not duplicate authoritative domain records.
- A D01 strategy approval record must identify the competent corporate body and exact authority instrument. A role label or frontend gate alone is not sufficient.
- A strategy recommendation, committee review, executive dashboard view, CMS publication, or Owner-provided public-site vision/mission text is not equivalent to formal strategy adoption.
- D01 budget alignment must not grant spending authority; D25/Finance approval and posting controls remain distinct.
- No D01 integration should bypass tenant/legal-entity isolation, backend authorization, audit, privacy, or records-retention controls.

## 6. Requirement-to-source matrix refinement

The 24-row matrix remains the controlling requirement set. This inventory refines the source interpretation of the following rows without changing their acceptance status:

| Requirement | Refined exact-main evidence | Status after this pass |
|---|---|---|
| D01-A2-02 / 03 / 04 / 05 — corporate authority, adopted strategy and office-holder evidence | Governance models/routes establish body/position/term/appointment lifecycle; no adopted D01 strategy instrument or current competent approval record is established by these source files. | PARTIAL / UNRESOLVED as previously registered |
| D01-A2-07 — objective hierarchy and cascade | Complete API/App path inventory and code search reveal no dedicated D01 objective hierarchy, objective register or strategy-to-domain cascade. | MISSING |
| D01-A2-08 — scenario, assumptions, baseline and versioning | Generic versioned Policy and Document lifecycle patterns exist; no D01 scenario/baseline/version aggregate or approval contract located. | MISSING |
| D01-A2-09 — strategy-to-budget/investment | Finance source/provider/reporting exists; no D01 objective-to-budget/investment decision mapping located. | PARTIAL |
| D01-A2-10 — strategy-to-project/portfolio | No D01-to-portfolio/project intake contract or UI route located. | MISSING |
| D01-A2-11 / 12 — KPI and certified BI lineage | Executive Overview and Finance report sources are limited to Finance/People widgets and trial balance; no D01 objective/KPI semantic contract. | PARTIAL |
| D01-A2-13 — strategic risk | No accepted D01 strategic risk appetite/register mapping located. | MISSING |
| D01-A2-15 — API source inventory | Complete API path tree enumerated; Governance, Finance, Organization, People, Calendar, Documents, Policy, Audit and provider/route sources inspected selectively. Runtime route registry and every candidate source file not executed/read exhaustively. | PARTIAL — path enumeration complete; runtime/source acceptance incomplete |
| D01-A2-16 — App source inventory | Complete App path tree enumerated; route tables, shell navigation, Governance and Executive Overview surfaces inspected. No D01 strategy route/feature identified. | PARTIAL — path enumeration complete; runtime/build acceptance incomplete |
| D01-A2-18 / 19 / 20 / 23 / 24 — shared platform controls and domain dependencies | Existing authorization, tenant, audit, localization/calendar, document and cross-domain source patterns are identified. D01-specific accepted access, retention, planning-period and producer/consumer contracts remain absent. | PARTIAL |

No requirement is promoted to VERIFIED-CLOSED by this supplement.

## 7. Search and inspection coverage

### Completed in this pass
- Recursive exact-SHA Git tree enumeration for API and App; both trees complete and non-truncated.
- API domain-root enumeration and check for D01/strategy module path names.
- App router and feature-root enumeration.
- Inspection of API provider registry, Governance provider/routes/model/service/enum/migration, Finance provider/routes/report contract/service.
- Inspection of App workspace routes, shell navigation, sidebar permission filtering, Governance overview/API/service, Dashboard and Executive Overview source boundaries.
- Additional GitHub code searches for `governance strategic plan`, `objective hierarchy`, `planning cycle`, `strategy approval`, `strategy objective`, `strategic plan` and D01-related terms.

### Not performed
- No Laravel `route:list`, application boot, Composer/PHP execution or database query.
- No App build, type check, browser test or Node test execution.
- No migration execution or schema inspection against a live database.
- No Actions workflow run or CI result inspected for this pass.
- No production server, runtime configuration, private data or live user records accessed.
- No code, routes, models, migrations, permissions, seeders or runtime configuration changed.
- No merge, implementation, A4 verification or deployment performed.

## 8. Updated A2 status

- Exact-main API/App Git path enumeration: **VERIFIED COMPLETE** for the two named commit trees.
- Candidate source inspection: **PARTIAL**; relevant sources inspected, not every source file.
- Requirement traceability: **24/24 mapped**.
- Requirement acceptance: **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**; no status changes.
- Cross-domain acceptance matrix: **PROPOSED — NOT OWNER-APPROVED**.
- D01 A2: **ACTIVE — PARTIAL — NOT VERIFIED-CLOSED**.
- A3: **NOT AUTHORIZED**.
- A4: **NOT STARTED**.
- A5: **NOT AUTHORIZED**.
- Merge/deployment: **NOT AUTHORIZED**.

## 9. Next A2 actions

1. Inspect the remaining candidate API providers, route files, policies, models, migrations, events and tests that could supply D01 producer references; prioritize Organization, People, Calendar, Documents, Policy and Audit.
2. Inspect corresponding App route, navigation, feature API and test contracts for those same domains.
3. Reconcile exact producer IDs, ownership, version/effective-date, tenant/legal-entity scope and audit evidence against the 30-domain catalogue.
4. Convert the proposed cross-domain acceptance rows into a requirement-linked acceptance/test matrix, with source-of-truth and negative/failure cases.
5. Keep corporate instrument identity and formal strategy adoption as separate unresolved authority evidence matters.

No additional Owner documents or server access are required for the independent technical inventory described above.

**Disposition: D01 A2 remains ACTIVE — PARTIAL — NOT VERIFIED-CLOSED.**
