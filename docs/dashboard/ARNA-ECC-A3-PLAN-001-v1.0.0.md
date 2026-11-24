# ARNA Executive Command Centre — Controlled A3 Implementation Scope and Priority Plan

**Control ID:** ARNA-ECC-A3-PLAN-001  
**Version:** 1.0.0  
**Date:** 24 November 2026  
**Owner:** Akefa Ayele Daka  
**Repository:** `akefadaka-git/arna-api` (authoritative destination repository)  
**Status:** OWNER-APPROVED PLAN — IMPLEMENTATION AUTHORIZATION NOT ESTABLISHED BY THE SAVE REQUEST

## 1. Governance and authorization

This plan follows the accepted and frozen records:

- A1: `ARNA-ECC-A1-001 v1.0.0` — VERIFIED-CLOSED.
- A2: `ARNA-ECC-A2-RECON-001` — OWNER-ACCEPTED / VERIFIED-CLOSED.

This document records the Owner's approval of the A3 plan and the instruction to save it in the repository. That approval is not interpreted as authorization to begin implementation because the Owner did not select one of the implementation authorization scopes in the plan's decision options. A separate explicit scope authorization is required before A3 code changes begin.

No merge, deployment, production configuration change, or A5 activity is authorized by this document. A5 remains a separate Owner gate.

## 2. Executive direction

Implement ARNA Executive Command Centre as a governed enterprise decision-support programme, not as 30 independent dashboard pages.

- The 30 business domains are information sources and accountable business capabilities.
- The 14 dashboards are decision and presentation workspaces.
- Domain models, routes, and operational services do not by themselves establish KPI-provider readiness.
- Only verified, authorized, scoped and governed data may be presented as a KPI.

The programme should first establish shared KPI governance, provider contracts and security controls, then deliver priority providers and dashboards in incremental, reviewable work packages.

## 3. Objectives

1. Establish an authoritative KPI catalogue across all 30 domains.
2. Implement a versioned dashboard data-provider contract.
3. Strengthen authorization, tenant isolation and legal-entity boundaries.
4. Expand the existing Executive Overview using verified sources.
5. Deliver priority dashboard workspaces incrementally.
6. Standardize provenance, freshness, limitations and error handling.
7. Establish reusable frontend dashboard components.
8. Prepare exact-SHA A4 verification evidence before release decisions.

## 4. Controlled work breakdown and priorities

### P0 — Shared foundations

**A3.0 — Execution baseline and safeguards**
- Reconfirm live API and app `main` SHAs before implementation.
- Inventory open PRs, branches and unrelated work.
- Define branch/PR boundaries and an implementation evidence register.
- Capture baseline tests and workflow status.
- Preserve all existing approved baselines and unrelated work.

Acceptance: every change is traceable to an approved work item; no unrelated work is overwritten.

**A3.1 — Enterprise KPI governance**
- Define KPI ID, name, business definition and formula.
- Record source domain, source reference, aggregation and calculation rules.
- Assign accountable business owner and data steward.
- Define unit, currency, legal-entity scope and reporting period.
- Define freshness, validation rules, limitations and approval state.

Acceptance: no production KPI is introduced without an approved definition, source and accountable owner.

**A3.2 — Shared provider and data contract**
Minimum contract fields:
- `contract_version`, `dashboard_id`, `widget_id`, `metric_id`
- `tenant_scope`, `legal_entity_scope`
- `period_start`, `period_end`
- `value`, `unit`, `currency`
- `source_domain`, `source_key`, `source_reference`
- `calculation_version`
- `observed_at`, `generated_at`, `freshness_policy`
- `limitations`, `status`

Required statuses: `complete`, `partial`, `unavailable`, `stale`, `error`, `not_authorized`.

Acceptance: `complete` describes only the declared provider scope; it must never imply that all 30 domains are complete when only a subset responded.

**A3.3 — Authorization and data-security controls**
- Keep dashboard audience authorization separate from widget permissions.
- Verify tenant and legal-entity isolation.
- Test role-derived and direct user permission assignments.
- Ensure denial responses do not disclose sensitive widget metadata.
- Investigate and remediate `RISK-A4-01`, the authorization-cache invalidation risk.
- Add regression tests for identity and tenant changes.

Acceptance: no unauthorized dashboard/widget disclosure; cache-risk remediation is independently verified.

Restrictions: preserve canonical RBAC tables (`roles`, `permissions`, `user_has_roles`, `user_has_permissions`, `role_has_permissions`). Do not introduce wildcard grants or automatically expand business-data access. Keep `people.workforce.view` independently gated.

### P1 — First provider and dashboard delivery wave

**A3.4 — Priority domain data providers**

Initial focus:
- D11 Finance & Accounting
- D12 Treasury & Cash
- D09 People & Workforce
- D03 Governance & Board
- D01 Strategy & Enterprise Planning
- D02 Enterprise Performance

Every provider requires source evidence, scope enforcement, calculation tests, freshness handling and documented limitations.

**A3.5 — Reusable dashboard frontend**
- Dashboard shell, navigation and reusable workspace structure.
- KPI cards, trends, tables, charts and drill-down patterns.
- Loading, empty, stale, partial, unavailable and error states.
- Permission-aware widget rendering.
- Amharic-first localization and Ethiopian Calendar support.
- Responsive mobile layouts and accessibility remediation.

Acceptance: components consume real provider contracts consistently and never render unauthorized data.

**A3.6 — Priority dashboard workspaces**

Delivery order:
1. ECC-01 Executive Command Centre — expand the existing foundation.
2. ECC-02 Finance & Treasury.
3. ECC-06 People & Workforce.
4. ECC-03 Governance & Board.
5. ECC-04 Strategy & Performance.
6. ECC-10 Risk & Compliance.

Each workspace requires a defined audience, permission matrix, KPI catalogue, tested provider contracts and documented limitations.

### P2 — Expansion and closure

**A3.7 — Remaining domain-provider waves**

Implement remaining providers in dependency-aware waves, subject to source readiness, approved KPI definitions and domain-owner accountability.

**A3.8 — Integration and implementation closure**
- Complete approved cross-domain integration and regression testing.
- Reconcile every approved work item against its acceptance criteria.
- Produce implementation documentation and an A4 handover evidence package.
- Record unresolved risks and limitations explicitly.

Acceptance: every approved A3 work item has implementation evidence and a PASS, PARTIAL, MISSING, UNRESOLVED, UNKNOWN or NOT RUN outcome.

## 5. Thirty-domain implementation priority register

Priority definitions:
- **P0:** shared governance, architecture and security foundations.
- **P1:** first business-provider tranche.
- **P2:** second expansion tranche, dependent on source readiness and P1 foundations.
- **P3:** later expansion and specialized capabilities.

| Domain | Priority | Initial provider focus |
|---|---:|---|
| D01 Strategy & Enterprise Planning | P1 | Strategic objectives |
| D02 Enterprise Performance | P1 | KPI scorecards and targets |
| D03 Governance & Board | P1 | Resolutions and governance |
| D04 Enterprise Risk | P2 | Risk exposure and register |
| D05 Compliance & Internal Control | P2 | Obligations and controls |
| D06 Legal & Regulatory Affairs | P2 | Legal obligations |
| D07 Project & Portfolio Management | P2 | Project delivery |
| D08 Organization & Structure | P3 | Organizational structure |
| D09 People & Human Capital | P1 | Workforce metrics |
| D10 Shareholder & Ownership | P2 | Ownership and capital records |
| D11 Finance & Accounting | P1 | Financial reporting |
| D12 Treasury & Cash | P1 | Cash and liquidity |
| D13 Loan & Credit | P2 | Loan portfolio |
| D14 Wallet & Digital Finance | P2 | Wallet and payment activity |
| D15 Procurement & Suppliers | P3 | Procurement performance |
| D16 Inventory & Supply Chain | P3 | Stock and supply |
| D17 Sales, Marketing & CRM | P3 | Customer and sales metrics |
| D18 Operations & Service Delivery | P2 | Operational performance |
| D19 Assets & Facilities | P3 | Asset and facilities status |
| D20 Documents & Records | P2 | Records and compliance |
| D21 Communication & Engagement | P3 | Stakeholder engagement |
| D22 Calendar & Scheduling | P3 | Activities and completion |
| D23 Reporting & Business Intelligence | P2 | Enterprise reporting |
| D24 IT & Digital Systems | P3 | Technology operations |
| D25 Investment & Capital | P2 | Investment performance |
| D26 Business Development & Partnerships | P2 | Partnership pipeline |
| D27 Corporate Development / M&A | P3 | Corporate transactions |
| D28 Product & Innovation | P3 | Product pipeline |
| D29 Grants, Funding & Financing | P2 | Funding portfolio |
| D30 Investment Asset Portfolio | P2 | Asset portfolio performance |

Priority is sequencing guidance, not evidence of existing data availability or provider readiness. Each domain must receive a documented readiness assessment.

## 6. Fourteen-dashboard delivery register

| Dashboard | A3 target |
|---|---|
| ECC-01 Executive Command Centre | Expand existing foundation |
| ECC-02 Finance & Treasury | First delivery wave |
| ECC-03 Governance & Board | First delivery wave |
| ECC-04 Strategy & Performance | First delivery wave |
| ECC-05 Organization & Administration | Later wave |
| ECC-06 People & Workforce | First delivery wave |
| ECC-07 Shareholder & Capital | Later wave |
| ECC-08 Operations & Projects | Later wave |
| ECC-09 Investment & Partnerships | Later wave |
| ECC-10 Risk & Compliance | First delivery wave |
| ECC-11 Documents & Information | Later wave |
| ECC-12 Communication & Engagement | Later wave |
| ECC-13 Calendar & Activities | Later wave |
| ECC-14 Enterprise Intelligence & Analytics | Later wave |

The first delivery wave is limited to six dashboards. The remaining eight stay in the approved catalogue and are not cancelled.

## 7. Non-negotiable controls

| Area | Control |
|---|---|
| Repository authority | Destination repositories under `akefadaka-git` are authoritative |
| Existing work | Preserve unrelated changes and approved baselines |
| RBAC | Preserve canonical schema; no broad access expansion |
| Finance | Enforce tenant and legal-entity isolation |
| Consolidation | No consolidated Finance metric without approved accounting/consolidation policy |
| Currency | Respect each entity's configured currency; ETB remains the platform default |
| Workforce | `people.workforce.view` remains independently gated |
| Data quality | No fabricated, inferred or unverified KPIs |
| AI | Evidence-linked explanations; human approval for consequential actions |
| Localization | Amharic-first; Ethiopian Calendar default; Gregorian preference |
| Accessibility | WCAG 2.2 AA target; verify rather than assume conformance |
| Security | Keep `RISK-A4-01` open until remediation is evidenced and verified |
| Deployment | A5 remains a separate Owner authorization |

## 8. A3 acceptance and verification framework

Each work package must provide:
1. Approved requirement and scope.
2. Exact base and head SHAs.
3. Affected-file inventory.
4. Branch and PR references.
5. Migration and rollback assessment, where applicable.
6. Unit, feature, integration and security test evidence.
7. Workflow results and links, where available.
8. API and frontend verification.
9. Permission and tenant-isolation test results.
10. Known limitations and unresolved risks.

Status vocabulary:
- `PASS`: evidence meets the acceptance criterion.
- `VERIFIED-CLOSED`: implementation and required verification are complete.
- `PARTIAL`: some required work is complete.
- `MISSING`: required implementation or evidence is absent.
- `UNRESOLVED`: a known issue remains.
- `UNKNOWN`: evidence is insufficient to determine status.
- `NOT RUN`: verification has not been executed.

A3 completion requires all approved work packages to meet their individual acceptance criteria, a traceable readiness register for all 30 domains, no unapproved permission or cross-entity disclosure, no unexplained KPI calculation variance, explicit disposition of known risks, and a complete A4 handover package.

## 9. Explicit exclusions

This plan does not itself authorize:
- Production deployment or production configuration changes.
- Merging implementation PRs to `main`.
- Production database changes without an approved migration plan.
- Unapproved financial consolidation.
- Broad or wildcard permission grants.
- Autonomous AI decisions or financial transactions.
- Paid services or billing commitments.
- Reopening frozen A1/A2 baselines without material authoritative evidence.
- Unrelated application rework.
- An enterprise-wide WCAG 2.2 AA conformance claim without complete evidence.

## 10. Stage gates

1. **A3 authorization:** Owner explicitly authorizes the implementation scope.
2. **A3.0–A3.3:** establish baseline, KPI governance, provider contract and security controls.
3. **A3.4–A3.6:** implement first provider and six-dashboard delivery wave in incremental PRs, only if authorized.
4. **A3.7–A3.8:** complete approved expansion and prepare verification handover.
5. **A4:** independently verify exact implementation SHAs, including security, data, accessibility and regression evidence.
6. **A5:** separate Owner deployment authorization.

A3 or A4 completion does not automatically authorize deployment.

## 11. Baseline status at plan approval

| Stage | Status |
|---|---|
| A1 assessment/specification | VERIFIED-CLOSED |
| A2 read-only reconciliation | OWNER-ACCEPTED / VERIFIED-CLOSED |
| A3 plan | OWNER-APPROVED; recorded by this document |
| A3 implementation | NOT AUTHORIZED BY THE SAVE REQUEST |
| A4 expanded verification | NOT STARTED |
| A5 deployment | NOT AUTHORIZED |

The A2 inspection SHAs were historical snapshots, not guaranteed current heads. Reconfirm live repository heads before any separately authorized implementation.

## 12. Owner decision record

**Decision:** APPROVED — save this controlled A3 plan in the authoritative ARNA repository.

**Recorded interpretation:** Approval covers this plan as the controlled A3 planning baseline. The Owner has not specified whether implementation authorization is for the full plan or P0-only. Therefore, implementation remains gated pending explicit scope authorization.

**Owner:** Akefa Ayele Daka  
**Approval date:** 24 November 2026  
**Record:** ARNA-ECC-A3-PLAN-001 v1.0.0


---

## 13. Controlled review addendum — PR #217

**Review record:** 24 November 2026  
**Reviewed PR:** #217  
**Reviewed base SHA:** `6e274c25ecc55b84e026e879d735b77f0e51592f`  
**Reviewed head SHA before addendum:** `918346f6933bed23c2f47dc7775d19e00ce3be3a`

The documentation-only PR was reviewed. The reviewed change contained one file, with 295 additions and no deletions. Content review found the A3 work breakdown, priority sequence, governance boundaries and separate A4/A5 gates consistent with the accepted A1/A2 baselines. No PR review submissions, inline review threads or PR comments were present at the time of review.

Review disposition: **CONTENT ACCEPTABLE, WITH CONTROLLED CLARIFICATIONS BELOW**. This is not a merge approval.

### 13.1 KPI readiness clarification

A3.1 must distinguish:
1. A 30-domain readiness and ownership register, which is required for every domain; and
2. Approved KPI definitions, which may only be finalized for metrics whose authoritative sources, formulas, aggregation rules, accountable owners and data stewards have been established.

A domain may be marked assessed or partially ready without being represented as KPI-ready. Missing source evidence or ownership must be recorded explicitly; no KPI may be invented to fill a gap.

### 13.2 Authorization-cache remediation clarification

A3.3 must define a bounded remediation work item for `RISK-A4-01` before changing shared authorization-cache behavior. The work item must include:
- the affected cache keys, permission paths and invalidation events;
- failure modes and stale-positive exposure;
- intended invalidation/expiry behavior and fail-safe expectations;
- regression tests for role changes, direct permission changes, tenant changes and invalidation failures;
- compatibility and performance impact;
- exact evidence required to close the risk.

No unrestricted authorization rewrite is authorized by this plan. Any remediation beyond the bounded Executive Overview/dashboard authorization scope requires a separately documented impact assessment and approval.

### 13.3 Repository governance observation

At review time, repository metadata indicated branch protection and required status checks were not configured. This is a repository-governance observation, not an A3 implementation deliverable or permission to change repository settings. Any change to branch protection or repository administration requires separate authorization.

### 13.4 Review evidence qualification

The review observed a successful tenant-isolation regression workflow and a successful shareholder seed-number initialization regression workflow. The Executive Overview API security verification workflow was still running at the time of inspection. These observations do not constitute complete A3 verification, and no claim of A3 test completion is made.

## 14. Owner authorization addendum — P0 only

**Authorization record:** `ARNA-ECC-A3-AUTH-001`  
**Owner decision:** APPROVED — P0 ONLY  
**Decision date:** 24 November 2026  
**Basis:** The Owner's instruction, “Yes add and save,” following the recommendation to authorize P0 only and incorporate the controlled review clarifications.

The Owner authorizes implementation of the following work packages only:

- **A3.0** — Execution baseline and safeguards.
- **A3.1** — Enterprise KPI governance foundation, including the 30-domain readiness/ownership register and only those KPI definitions supported by authoritative sources and accountable owners.
- **A3.2** — Shared provider and data contract.
- **A3.3** — Bounded dashboard/Executive Overview authorization and security controls, including investigation and scoped remediation of `RISK-A4-01` under Section 13.2.

### 14.1 Explicitly not authorized by this decision

- A3.4 priority domain-provider implementation.
- A3.5 reusable dashboard frontend expansion.
- A3.6 implementation of the six priority dashboard workspaces.
- A3.7 remaining domain-provider waves.
- A3.8 full programme integration and closure beyond P0 handover evidence.
- Merge to `main`, production database changes, production configuration changes or deployment.
- Broad or wildcard permission grants, unapproved financial consolidation, or unrestricted platform-wide authorization changes.

P1/P2 implementation requires a separate Owner authorization after P0 evidence is reviewed. A4 verification remains a distinct stage, and A5 deployment remains a separate Owner gate.

### 14.2 P0 completion and handover gate

Before requesting P1 authorization, provide:
1. Exact API/app base and head SHAs and affected-file inventories.
2. KPI catalogue and 30-domain readiness/ownership register.
3. Versioned provider contract and compatibility notes.
4. Security/cache-risk impact assessment and bounded remediation evidence.
5. Unit, feature, integration and security test results, including workflow links.
6. Migration/rollback assessment where applicable.
7. Explicit PASS/PARTIAL/MISSING/UNRESOLVED/UNKNOWN/NOT RUN outcomes.
8. Remaining risks and a recommendation for the next Owner decision.

**Current control status:** A3 plan OWNER-APPROVED; P0 implementation AUTHORIZED; P1/P2 implementation NOT AUTHORIZED; merge NOT AUTHORIZED; A4 NOT STARTED; A5 NOT AUTHORIZED.
