# ARNA P0 Enterprise Integration Baseline
**Document ID:** ARNA-P0-EIB-001  
**Version:** 0.1.0-draft  
**Status:** OWNER REVIEW REQUIRED — P0 OPEN — NOT VERIFIED-CLOSED  
**Assessment date:** 2026-11-27  
**Scope:** Read-only enterprise architecture and integration inventory  
**Authority:** Owner-governed architecture baseline; not a legal determination or implementation authorization.

## 1. Purpose and stage boundary

This document consolidates the P0 enterprise integration inventory for the frozen ARNA catalogue of 30 domains. It records domain scope and system-of-record boundaries, observed API/App repository evidence, cross-domain dependencies, application experience mapping, reporting requirements, implementation backlog and unresolved closure conditions.

This is documentation and reconciliation only. It does not authorize A3 implementation, application behavior changes, database migrations, PR merge, A4 verification or A5 deployment. The parent catalogue ARNA-ESPPM-A1-SPEC-002 v1.2.0 remains unchanged and contains exactly D01–D30. No D31 is proposed.

## 2. Exact repository baseline

| Repository | Branch | Commit SHA | Evidence date |
|---|---|---|---|
| akefadaka-git/arna-api | main | 9c0ce83afcc084101fb14e6e55d54e1d953c2ffe | 2026-11-27 |
| akefadaka-git/arna-app | main | 71bed2158894e3ce9cf507e8bf70492ac1f9dfd4 | 2026-11-27 revalidation |

The API README describes the backend as owner of business rules, persistence and authorization. The App README describes frontend experiences consuming backend services. The four App experience boundaries are Workspace, Shareholder Portal, Admin Console and Public Website; entry-point directories previously identified are workspace-entry/, portal-entry/, admin-entry/ and public-entry/.

### 2.1 Current tree observations

API top-level domain folders observed under mases_core/src/Domains/:
- Documents
- FileManagement
- Finance
- Governance
- Loan
- Organization
- People
- PublicContent
- Shareholder
- Task
- Wallet

App feature folders observed:
- appointments
- auth
- calendar
- communications
- dashboard
- executive-overview
- files
- governance
- launcher
- loans
- locale
- notifications
- people
- portal
- public-content
- public-site
- shareholders
- tasks
- wallet

These are repository-tree observations, not declarations of domain completeness. A folder, route, migration, model, API client or PR is not sufficient evidence of domain completeness, operational acceptance or production readiness. Absence of a dedicated folder does not prove that no related capability exists elsewhere.

## 3. D01–D30 domain inventory

The status column preserves the previously approved A2 snapshot: 2 SUPPORTED / 11 PARTIAL / 6 UNVERIFIED / 11 MISSING. These are evidence dispositions, not implementation-completion percentages. Do not upgrade status merely because code is present.

| ID | Domain | A2 status | System-of-record boundary | Current source evidence / limitation |
|---|---|---|---|---|
| D01 | Corporate Strategy & Enterprise Planning | UNVERIFIED | Strategy, themes, multi-year and annual plans, priorities and planning baselines | No dedicated Strategy domain folder or App feature observed. Subordinate D01 A1 remains draft; A2 open. |
| D02 | Enterprise Performance Management | PARTIAL | Enterprise objectives, KPI definitions, target versions, scorecards, accountability and reviews | Executive Overview/dashboard foundations observed; not a complete performance lifecycle. |
| D03 | Corporate Governance & Board Management | PARTIAL | Governance bodies, authority, appointments, delegations, Board/committee processes and decisions | API Domains/Governance and App governance observed; current legal authority/officeholder evidence unresolved. |
| D04 | Enterprise Risk Management | UNVERIFIED | Risk taxonomy, appetite, assessment, treatment, acceptance, monitoring and reporting | No dedicated Risk folder/feature observed; policy references do not prove lifecycle completeness. |
| D05 | Compliance & Internal Control Management | UNVERIFIED | Obligations, controls, testing, issues, remediation and assurance evidence | No dedicated Compliance folder/feature observed; operating effectiveness not established. |
| D06 | Legal & Regulatory Affairs | PARTIAL | Legal instruments, obligations, matters, regulatory calendar and advice records | Legal/governance reconciliation documents exist; no complete Legal feature observed. |
| D07 | Project, Programme & Portfolio Management | UNVERIFIED | Project/programme/portfolio authorization, baselines, delivery, benefits and closure | Task domain and App tasks exist; task tracking is not portfolio management. |
| D08 | Organization & Structure Management | SUPPORTED | Legal/organizational units, hierarchy, positions and assignments | API Domains/Organization contains models and policies; no dedicated App organization feature observed. Historical A2 status is not production certification. |
| D09 | People & Human Capital Management | PARTIAL | Person/workforce records and employment lifecycle | API Domains/People and App people observed; HR lifecycle remains partial. |
| D10 | Shareholder & Ownership Management | PARTIAL | Shareholders, holdings, share classes, representatives and ownership evidence | API Domains/Shareholder; App shareholders and portal. Governance/source evidence remains a dependency. |
| D11 | Finance & Accounting Management | PARTIAL | Chart of accounts, journals, ledgers, subledgers, financial statements and periods | API Domains/Finance contains contracts/configuration/migrations/seeders; no dedicated App finance feature observed. Database readiness/seed state not inferred. |
| D12 | Treasury & Cash Management | MISSING | Cash/bank accounts, cash position, mandates, payments, reconciliation and liquidity | No independent Treasury folder or App feature observed. Wallet is not a Treasury substitute. |
| D13 | Loan & Credit Management | PARTIAL | Origination, underwriting, approval, disbursement, servicing, collateral and recovery | API Domains/Loan; App loans and portal. End-to-end credit governance/acceptance not established. |
| D14 | Wallet & Digital Financial Services | PARTIAL | Wallet accounts, entries, obligations, allocations and reversals | API Domains/Wallet; App wallet and portal. Does not replace D11 accounting or D12 treasury. |
| D15 | Procurement & Supplier Management | MISSING | Sourcing, requisitions, tenders, supplier due diligence, contracts, POs and receiving | No dedicated Procurement folder or App feature observed. |
| D16 | Inventory & Supply Chain Management | MISSING | Item master, stock, warehouses, movements, replenishment and supply chain | No dedicated Inventory folder or App feature observed. |
| D17 | Sales, Marketing & Customer Relationship Management | MISSING | Leads, opportunities, customers, quotations, orders, campaigns and lifecycle | No dedicated Sales/CRM folder or App feature observed. |
| D18 | Operations & Service Delivery Management | UNVERIFIED | Operational processes, service delivery, service levels, incidents and quality | No dedicated Operations folder/feature observed; tasks/communications do not prove operational lifecycle. |
| D19 | Enterprise Asset & Facilities Management | MISSING | Asset register, custody, lifecycle, maintenance and facilities | No dedicated Asset/Facilities folder or App feature observed. |
| D20 | Documents & Records Management | PARTIAL | Formal records, classification, retention, disposition, legal hold and document lifecycle | API Domains/Documents and shared FileManagement; App files. Binary storage and records governance are distinct. |
| D21 | Communication & Stakeholder Engagement | PARTIAL | Stakeholder registers, communication plans, announcements, engagement and feedback | Notification/public-content foundations; App communications and notifications. |
| D22 | Calendar, Events & Scheduling Management | SUPPORTED | Calendar events, appointments, scheduling and event records | App calendar and appointments; shared/API contracts exist. Historical A2 status does not imply runtime acceptance. |
| D23 | Enterprise Reporting & Business Intelligence | PARTIAL | Reporting definitions, certified datasets, lineage, analytics and distribution | Executive Overview/dashboard and finance reporting foundations; enterprise certification not demonstrated. |
| D24 | Information Technology & Digital Systems Management | UNVERIFIED | IT services, systems, configuration, service desk, change, incidents and technology assets | Shared Core/platform foundations and auth/locale/notifications/launcher; not a complete ITSM domain. |
| D25 | Investment & Capital Management | MISSING | Investment proposals, appraisal, capital allocation, valuation and returns | No dedicated Investment folder or App feature observed. |
| D26 | Business Development & Strategic Partnerships | MISSING | Partnership pipeline, due diligence, agreements, benefits and relationships | Public inquiry intake is not a partnership lifecycle; no dedicated feature observed. |
| D27 | Corporate Development, Mergers & Acquisitions | MISSING | Transactions, due diligence, valuation, approvals and integration | No dedicated M&A folder or App feature observed. |
| D28 | Product, Service & Innovation Management | MISSING | Product/service lifecycle, roadmap, innovation intake, stage gates and portfolio | No dedicated Product/Innovation folder or App feature observed. |
| D29 | Grants, Funding & Financing Management | MISSING | Funding opportunities, applications, grant agreements, conditions, disbursement and reporting | No dedicated Grants/Funding folder or App feature observed. |
| D30 | Enterprise Investment Asset Portfolio | MISSING | Investment holdings, valuation, performance, exposure and portfolio reporting | No dedicated investment portfolio folder or App feature observed. |

### 3.1 Shared capabilities are not new domains

FileManagement, Core identity, RBAC, audit, workflow, events, notifications, search, configuration, localization and observability are shared platform capabilities. They require explicit service ownership and contracts but do not become additional business domains.

Canonical User remains in Core. People owns person identity; Organization owns structural identity; Governance owns authority/delegation; Shareholder owns ownership and representatives. Avoid duplicate models and competing systems of record.

## 4. Strategy, Planning & Executive Management

ARNA is to provide a dedicated Strategy, Planning & Executive Management experience inside Enterprise Workspace. This is a composed experience, not a new domain.

| Capability | System of record | Boundary |
|---|---|---|
| Corporate strategy and priorities | D01 | Strategy and planning baselines |
| Multi-year and annual plans | D01 | Links approved objectives, budgets and delivery plans |
| Objectives, KPIs and targets | D02 | D01 references D02-owned objectives; no duplicate KPI system |
| Governance authority and approvals | D03 | Decision authority, delegations and approval evidence |
| Budgets and accounting actuals | D11 | Plans consume approved budget references and financial actuals |
| Project/programme execution | D07 | Delivery baselines, milestones and benefits |
| Organization structure | D08 | Department/unit hierarchy and assignments |
| Workforce records | D09 | Accountable people/workforce context |
| Enterprise reporting | D23 | Certified cross-domain reporting and lineage |

Proposed Workspace navigation:
- Executive Workspace
- Strategy & Vision
- Annual Plans & Operating Plans
- Objectives & KPIs
- Budget & Financial Plans
- Executive Management
- Department Management
- Projects & Programmes
- Tasks & Decisions
- Meetings & Calendar
- Performance Reviews
- Risk & Compliance
- Management Reports

Management workflows include executive reviews, department-head plans, weekly/monthly work plans, responsibilities, meetings, decisions, action items, escalations, corrective actions, performance and budget variance. They must not create competing records for D02 objectives, D03 approvals, D07 projects, D08 structure, D09 workforce or D11 budgets.

## 5. Enterprise reporting framework

Reporting frequencies, preparers and recipients below are target architecture direction. Statutory deadlines, signatory powers, shareholder distribution channels and approval requirements remain subject to authoritative legal/governance reconciliation.

| Frequency | Report | Preparer | Reviewer / recipient |
|---|---|---|---|
| Daily | Cashier Daily Report | Cashier | CFO reviews; opening/closing cash, receipts, payments, vouchers, deposits, transfers, physical count, variances and evidence |
| Daily | CFO Daily Financial Report | CFO | CEO reviews; cash/bank position, revenue/expense, liquidity, receivables/payables, budget exceptions, obligations and unusual transactions |
| Daily | CEO Executive Briefing | CEO / Executive Office | Relevant management; critical activities, exceptions, approvals, risks and actions |
| Weekly | Department Report | Department head | CEO/executive management; objectives, activities, deliverables, KPI status, budget use, risks, issues and next-week plan |
| Monthly | Consolidated Management Report | CEO, CFO validates financial content | Board; strategy/annual-plan progress, departments, financials, budget variance, projects, risk, HR, decisions and actions |
| Quarterly | Corporate Performance & Financial Report | CEO/CFO | Shareholders through approved governance channel |
| Half-yearly | Strategic & Financial Review | CEO/CFO | Proposed Board and shareholder channel; audience/approval route to be confirmed |
| Annual | Annual Shareholder Report | CEO/CFO | Shareholders |
| Annual | Annual Public Report | Authorized corporate communications | Public; approved public information only |
| Annual | Government & Regulatory Reports | Responsible officers | Relevant government/regulatory authorities; formats, deadlines and signatories to be confirmed |

### 5.1 Separation of duties and lifecycle

- Cashier prepares cash records; CFO independently reviews/reconciles. Cashier must not approve own cash reconciliation or payments.
- CFO prepares daily financial report; CEO reviews.
- Department heads prepare weekly reports; executive management reviews and CEO/delegate accepts.
- CEO prepares monthly Board report; CFO validates financial content; Board reviews and records oversight/directions.
- Shareholder reporting uses the applicable governance channel. Quarterly reporting does not itself create a General Meeting.
- Public disclosure requires confidentiality and Legal/Compliance review.
- Government submissions require responsible officers, appropriate CFO/Legal/Compliance review and legally authorized signatories.

Lifecycle: source records → prepare → validate → review → approve where required → distribute → archive signed report/evidence → actions/decisions → D02 performance monitoring → D23 consolidated reporting.

### 5.2 Calendar and localization

Support configurable daily, weekly, monthly, quarterly, half-yearly and annual cycles; configurable fiscal year; Ethiopian Calendar and Gregorian Calendar representation; deadlines; reminders; overdue escalation; correction/resubmission; and immutable report versions. Suggested internal close windows are planning defaults, not statutory deadlines.

## 6. Shared Core and integration responsibilities

| Capability | Responsibility |
|---|---|
| Identity/authentication | Canonical user identity, authentication and session context |
| Tenant/legal entity | Tenant/entity context and data partition boundaries |
| RBAC | Role/permission grants and scoped authorization |
| Authority/delegation | Authorized actor, delegation, limits and approval validation |
| Audit/evidence | Traceable action history and durable evidence |
| Workflow | Controlled lifecycle transitions and state validation |
| Events/integration | Versioned contracts, idempotency, retries, dead-letter/replay and reconciliation |
| Notifications | Delivery to authorized recipients |
| Files/search | Binary storage, version/hash and retrieval; formal records lifecycle remains D20 |
| Localization | Amharic-first language and E.C./G.C. date interoperability |
| Reporting | Certified datasets, source lineage, freshness and control totals |
| Configuration | Governed policies, thresholds and tenant settings |
| Observability | Logs, metrics, traces, alerting and incident evidence |

The existing RBAC schema on API main is the authoritative starting point: roles, permissions, user_has_roles, user_has_permissions, role_has_permissions. Do not reconstruct historical RBAC as the target.

## 7. Cross-domain dependency baseline

| Consumer | Producers / dependencies | Integration control |
|---|---|---|
| D01 strategy/plans | D03 authority, D02 objectives, D11 budgets, D07 delivery, D08 structure | Approved versions, stable references, no duplicate KPI/budget/project records |
| D02 performance | D01 strategy, D07 delivery, D09 workforce, D11 actuals and source domains | KPI definitions, periods, formulas, source lineage and target versions |
| D03 governance | D10 ownership, D20 records, Core identity/audit | Authenticated instruments, appointment status, authority and delegation |
| D07 portfolio | D01 alignment, D03 approval, D08 owner, D11 budget, D09 resources | Approved baseline, funding, accountable owner, dependencies and benefits |
| D09 workforce | D08 structure, D03 authority, Core identity | Person/employee distinction, assignment history and access lifecycle |
| D11 finance | D10 entity context, D12 treasury, D13 loans, D14 wallet, D15 procurement, D17 sales, D16 inventory | Source references, legal-entity mapping, periods, posting rules and reconciliation |
| D12 treasury | D11 accounting, banks/payment providers, D03 mandates | Maker-checker, bank mandate, payment idempotency and independent reconciliation |
| D14 wallet | D11 accounting, D12 payment rails, relevant identity domains | Ledger integrity, settlement/reconciliation and no double posting |
| D20 records | All producer domains, FileManagement | Classification, retention, legal hold, immutable evidence and binary hash/version |
| D23 reporting | All source domains and Core integration | Certified source, freshness, control totals, lineage, access and disclosure filtering |
| Enterprise Workspace | D01–D09, D11, D20–D24 as applicable | Role/authority-aware navigation; no frontend business-rule duplication |
| Shareholder Portal | D03, D10, D20–D22 and relevant D11/D13/D14 | Shareholder-scoped access and approved disclosure |
| Admin Console | Core identity/RBAC/configuration/audit and domain administration | Least privilege, administrative separation and audit |
| Public Website | D21, D26, D28 and approved D20 public records | Public-only content and authorized publication |

This is a dependency baseline for contract discovery. It does not claim accepted interface names, event names, schemas or stable contract IDs. Each interface needs source/consumer owner acceptance, versioning, security, error semantics and reconciliation rules.

## 8. P0 implementation-ready backlog

| ID | Work package | Priority | Dependencies | Acceptance evidence |
|---|---|---|---|---|
| P0-01 | Exact API/App repository baseline | Critical | None | Exact SHAs, branches/PRs, evidence timestamps |
| P0-02 | D01–D30 ownership/domain register | Critical | P0-01 | Scope, owner/steward status, source-of-record and evidence for all domains |
| P0-03 | Strategy/executive management architecture | Critical | P0-02 | D01/D02/D03/D07/D08/D09/D11 responsibilities and workflow |
| P0-04 | Shared Core architecture inventory | Critical | P0-01 | Shared capabilities, owners, contracts and security boundaries |
| P0-05 | Cross-domain dependency matrix | Critical | P0-02–04 | Producers, consumers, dependency and failure/reconciliation needs |
| P0-06 | API/event contract inventory | Critical | P0-04/05 | Routes, services, events, schemas, versions, owners and lifecycle |
| P0-07 | Database ownership/data inventory | Critical | P0-02–06 | Tables, migrations, owners, entity scope, lineage and status |
| P0-08 | Four-application experience map | High | P0-01 | Workspace, Shareholder Portal, Admin Console and Public Website |
| P0-09 | UI/workflow inventory | High | P0-08 | Routes/screens, roles, state transitions and missing experiences |
| P0-10 | Permissions/authority/separation-of-duties matrix | Critical | P0-04–07 | Roles, permissions, authority, maker-checker and audit |
| P0-11 | Capability/evidence gap register | Critical | P0-02–10 | Supported/partial/unverified/missing and unresolved/not-run findings |
| P0-12 | Dependency-aware implementation sequence | High | P0-05–11 | Sequenced delivery, prerequisites and critical path |
| P0-13 | P0 closure evidence package | Critical | P0-01–12 | Traceability, exact evidence, exceptions and closure recommendation |
| P0-14 | Strategy-to-annual-plan hierarchy | Critical | P0-03, D01/D02/D03 | Multi-year strategy, annual plans and approval lineage |
| P0-15 | Executive/department management workflow | Critical | P0-14, D07–D09/D11 | Department plans, meetings, decisions, actions and escalations |
| P0-16 | Daily cashier/CFO/CEO reporting contracts | Critical | D11/D12/D23, P0-10 | Three controlled daily report contracts |
| P0-17 | Weekly departmental reporting | Critical | D02/D08/D09/D23 | Department report and executive review |
| P0-18 | Monthly CEO-to-Board reporting | Critical | D02/D03/D11/D23 | Board report, CFO validation and decision tracking |
| P0-19 | Quarterly shareholder reporting | High | D03/D10/D11/D23 | Approved shareholder disclosure/reporting contract |
| P0-20 | Half-yearly strategic/financial review | High | D01–D03/D11/D23 | Review workflow and confirmed audience |
| P0-21 | Annual shareholder/public/government reports | Critical | D03/D06/D10/D11/D20/D23 | Three separate controlled annual outputs |
| P0-22 | Approval/disclosure/audit controls | Critical | D03/D05/D06/D20 | Approval matrix, signatories, confidentiality and retention |
| P0-23 | Reporting calendar/deadline escalation | High | D22/D23 | E.C./G.C. calendar, fiscal periods and configurable deadlines |
| P0-24 | Cross-domain reporting/data reconciliation | High | D02/D11/D23, P0-06/07 | Certified datasets, control totals, freshness and lineage |

### 8.1 Recommended delivery order

1. P0-01–07: baseline, ownership, Core, dependencies, API/event contracts and database ownership.
2. P0-08–13: application map, UI/workflow inventory, permissions, gaps, sequence and closure evidence.
3. P0-14–15: strategy/planning and executive/department management.
4. P0-16–24: reporting contracts, governance, calendar, disclosure and reconciliation.

## 9. Material blockers and closure conditions

| Blocker | Status | Required evidence |
|---|---|---|
| Authenticated GA Resolution 0001/2019 and current operative governance instruments | UNRESOLVED | Authenticated instrument and authoritative source provenance |
| Current Board and CEO/MD appointment instruments | UNRESOLVED | Current valid appointment evidence |
| Current delegations, signatories and approval thresholds | UNRESOLVED | Approved delegation matrix and underlying instruments |
| Strategy approval authority and approved strategy | UNRESOLVED | Legal/bylaw reconciliation and authenticated approval |
| Named domain accountable officers/stewards | MISSING | Owner-approved appointments, deputies and scope |
| D01 subordinate A1 specification | DRAFT / NOT FROZEN | Explicit Owner approval and controlled version |
| D01 A2 evidence/contracts | OPEN / UNVERIFIED | Reconciled evidence, approved contract IDs/versions and source owners |
| D12 Treasury | MISSING | Approved scope, accounts, bank mandates, signatories and reconciliation design |
| D15 Procurement / D17 Sales | MISSING | Approved policy, authority thresholds and lifecycle requirements |
| D04 Risk / D05 Compliance | UNVERIFIED | Risk taxonomy/appetite, obligations, controls and assurance lifecycle |
| Database/data ownership | PARTIAL | Current-main schema inventory, domain ownership, migration order and entity boundaries |
| API/event contracts | PARTIAL | Accepted source/consumer contracts, stable IDs, versioning and error semantics |
| Reporting framework | PARTIAL | Approved definitions, preparers, reviewers, recipients, disclosure and lineage |
| Runtime/integration acceptance | NOT RUN | Exact-head builds/tests, access tests, cross-domain reconciliation and runtime inspection |
| Historical PR branch currency | RECONCILIATION REQUIRED | Re-fetch PR metadata and reconcile intended artifacts against current main |

### 9.1 Historical PR comparison evidence

At the assessment point:
- API PR #223 head 88d839c5c46894ccfc821c50e4ec3400a7812a2f versus API main cd13c555976ed74050920bb69dd5c0d013ec9509: 26 commits ahead, 424 behind.
- App PR #44 head 3bb3e82bf266202468f680861b0a81c53fc34fec versus App main 648815b69b6419554b656ff91bf5b53df211aebb: 20 commits ahead, 342 behind.

These are point-in-time comparisons, not proof of current PR state or merge suitability. Re-fetch live metadata and reconcile every intended artifact. Do not merge stale branches as-is.

Historical references:
- API PR #219: https://github.com/akefadaka-git/arna-api/pull/219
- API PR #220: https://github.com/akefadaka-git/arna-api/pull/220
- API PR #223: https://github.com/akefadaka-git/arna-api/pull/223
- App PR #44: https://github.com/akefadaka-git/arna-app/pull/44
- App PR #11: https://github.com/akefadaka-git/arna-app/pull/11

## 10. P0 acceptance criteria

P0 may be recommended for Owner closure only when:
1. Frozen D01–D30 catalogue is preserved and every domain has scope, accountable owner/steward status, system-of-record boundary and evidence disposition.
2. Shared Core/domain boundaries are reconciled without duplicate ownership of identity, authority, KPI, budget, project, document or reporting records.
3. Dependencies identify source/consumer owners, accepted contracts, versioning, security, failure handling and reconciliation.
4. API routes/services/events and database objects are inventoried against exact current main SHAs.
5. All four App experiences are mapped to domain capabilities, roles, routes and workflows.
6. Permissions, delegated authority, separation of duties, audit and disclosure controls are mapped.
7. Strategy/annual-plan and executive/department management flows trace to D01/D02/D03/D07/D08/D09/D11.
8. Six reporting frequencies have definitions, sources, preparers, reviewers, approval routes, recipients, calendar and archive controls.
9. Legal/authority, owner/steward and source-evidence exceptions are resolved or explicitly accepted by the competent authority with remediation plans.
10. Exact evidence and reproducible closure review exist. P0 closure does not close A2 or authorize A3/A4/A5.

## 11. Current P0 assessment

| Area | Status |
|---|---|
| Frozen catalogue coverage D01–D30 | PASS — inventory coverage |
| API/App exact SHA baseline | PASS — point-in-time baseline |
| API domain-folder/App feature-tree observation | PASS — presence inventory only |
| Domain/system-of-record boundaries | PARTIAL — architecture baseline; owner evidence incomplete |
| Strategy/Planning/Executive Management | PARTIAL — architecture defined; subordinate D01 A1/A2 open |
| Six-frequency reporting framework | PARTIAL — target model defined; contracts/authority incomplete |
| API/event contracts | PARTIAL |
| Database ownership/schema reconciliation | PARTIAL |
| Named accountable owners/stewards | MISSING |
| Current legal/authority evidence | UNRESOLVED |
| Runtime/UI/integration verification | NOT RUN |
| Repository-wide P0 acceptance review | NOT RUN |
| P0 closure | NOT VERIFIED-CLOSED |

**Disposition: P0 OPEN — PARTIAL — OWNER REVIEW REQUIRED.** This baseline consolidates the inventory but does not assert that every P0 acceptance criterion is met. It is not an A2 closure record.

## 12. Change control and exclusions

- No API/App application source, routes, models, migrations, schema, seeders, runtime configuration or production data are in scope.
- No merge to main, deployment, paid CI/service dependency or production operation is authorized.
- No legal authority, officeholder, signatory or statutory deadline may be inferred from historical text alone.
- No domain status may be upgraded without authoritative evidence.
- The database named arna must not be described as migrated or seeded without fresh evidence.
- maale_arna_smart_system(1).sql is excluded as invalid/junk evidence.
- Any catalogue change requires a formal A1 amendment and explicit Owner approval.
- A2 remains open until its own acceptance criteria are met; P0 completion cannot automatically close A2.

## 13. Evidence and limitations

Evidence used:
- API/App repository main refs and recursive tree observations at the SHAs in Section 2.
- Previously approved A2 disposition snapshot (2 supported, 11 partial, 6 unverified, 11 missing).
- Prior D01 A1/A2 evidence records and known legal/authority and owner-evidence gaps.
- Owner-stated Strategy, Planning & Executive Management and reporting-frequency requirements.

Not performed:
- Full inspection of every API route, event, migration, model, seeder or database table.
- Runtime, browser, visual or accessibility testing.
- API/App builds, automated tests, CI workflows or deployment checks.
- Legal review of governing instruments or confirmation of current signatories.
- Independent security review or production operational verification.

This is a structured P0 baseline and backlog. It must be reconciled with a complete current-head inventory before being declared complete.


## 14. Technical inventory revalidation — 2026-11-27

### 14.1 Revalidated repository heads

| Repository | Ref | Revalidated commit | Evidence |
|---|---|---|---|
| akefadaka-git/arna-api | main | 9c0ce83afcc084101fb14e6e55d54e1d953c2ffe | Commit object fetched; routes, bootstrap, Core provider and phase-gate report inspected |
| akefadaka-git/arna-app | main | 71bed2158894e3ce9cf507e8bf70492ac1f9dfd4 | Commit object fetched; README, package manifest and router/features searched |
| akefadaka-git/arna-api | docs/p0-enterprise-integration-baseline-001 | Existing P0 draft branch | Branch exists; PR #239 remains open and draft |

The prior App baseline SHA 648815b69b6419554b656ff91bf5b53df211aebb is superseded by the revalidated main SHA above. API main remains 9c0ce83afcc084101fb14e6e55d54e1d953c2ffe. This correction is documentation-only.

### 14.2 API evidence observed

| Layer | Evidence path | Observation | Status |
|---|---|---|---|
| Runtime/bootstrap | `bootstrap/app.php` | Central routing, middleware aliases, Sanctum stateful SPA middleware and canonical error rendering | PRESENT — source inspected |
| HTTP routes | `routes/api.php` | Declares public, authenticated, diagnostic and tenant-scoped route trust tiers; uses permission, role, application and tenant-membership middleware conventions | PRESENT — full route file fetched; endpoint-to-domain matrix NOT YET COMPLETE |
| Core registration | `bootstrap/providers.php`; `mases_core/src/Providers/CoreServiceProvider.php` | CoreServiceProvider registered; central bindings, migration registration, policies and event listeners are wired centrally | PRESENT — source inspected |
| Core authorization | `mases_core/src/Authorization/Models/Role.php`; `mases_core/src/Authorization/Services/AuthorizationService.php` | Role/permission relationships and authorization service evidence surfaced | PARTIAL — full permission matrix and all route guards not reconciled |
| Domain model sample | `mases_core/src/Domains/People/Models/Person.php`; `.../Wallet/Models/Wallet.php`; `.../Loan/Models/LoanProduct.php`; `.../Task/Models/Task.php` | Domain-namespaced Eloquent models exist in multiple domains | PARTIAL — search samples, not exhaustive model inventory |
| Domain events sample | `mases_core/src/Domains/Shareholder/Events/ShareHoldingChanged.php`; `.../Wallet/Listeners/AuditWalletEvent.php` | Domain event/listener pattern exists; at least one event is documented as synchronous | PARTIAL — event/listener registry and producer-consumer map not complete |
| RBAC schema contract | `docs/PHASE_1_GATE_REPORT.md`; route and model search | Five canonical RBAC tables are documented: `roles`, `permissions`, `role_has_permissions`, `user_has_roles`, `user_has_permissions`; report also lists audit/security tables | PARTIAL — current schema and migration ownership still require full reconciliation |
| Migration locations | `database/migrations/`; `mases_core/src/Migrations/` | Root Laravel migrations and at least one Core migration path are present in repository evidence. Phase 1 Gate Report v2.2.0 says working Phase 1 migrations were flat under `database/migrations/` and corrects earlier documentation that said all migrations were under Core | UNRESOLVED — enumerate every migration, trace provider loading, dependencies and duplicate table definitions before assigning authoritative ownership |

Important migration caution: a historical gate report is not a substitute for current source tracing. Do not run migrations or infer the live `arna` database schema from repository files.

### 14.3 App evidence observed

| Layer | Evidence path | Observation | Status |
|---|---|---|---|
| Application topology | `README.md`; `package.json` | Four independent Vite builds: Workspace, Shareholder Portal, Admin Console and Public Website, with separate entry points and output directories | PRESENT — source inspected |
| Router composition | `src/router/index.ts` | Router factory composes scope-specific route tables | PRESENT — source inspected |
| Route tables | `src/router/routes-base.ts`, `routes-workspace.ts`, `routes-portal.ts`, `routes-admin.ts` | Search confirms split route-table architecture; portal and admin route files exist | PARTIAL — complete route enumeration and role/permission matrix pending |
| Executive experience | `src/features/executive-overview/ExecutiveOverviewView.vue`; `api.ts` | Executive Overview view calls `/api/dashboard/v1/overview`; UI gate checks `dashboard.executive.view` and roles `ceo`, `president`, `finance_manager` | PRESENT — one executive surface identified; not proof of D01 Strategy & Planning experience |
| Feature map | `src/features/` and README | README names People/shareholder, Governance, Documents/File Management, Loans/Wallet, public content and launcher capabilities | PARTIAL — README list is not exhaustive and feature-level workflow completeness is unknown |
| Build/test definitions | `package.json` | Type-check, four builds, static verification, contract tests and browser tests are defined | PRESENT — commands exist; execution NOT RUN in this inventory |

### 14.4 Cross-domain control observations

1. API route comments define distinct authentication and tenant trust tiers. Every route must be checked against actual middleware, policy and tenant resolution; comments alone are not enforcement evidence.
2. The App Executive Overview uses a role/permission visibility gate. The API remains the authorization authority; frontend visibility is not an access-control boundary.
3. The canonical five-table RBAC schema remains the starting point already approved by the Owner. Any additional audit/security tables must be treated as separate concerns, not substitutes for the five-table RBAC baseline.
4. CoreServiceProvider is a central integration point. Its bindings, event listeners, policy registration and migration loading need a complete dependency inventory to detect hidden cross-domain coupling.
5. The executive dashboard endpoint is not evidence that D01 strategy, D02 objectives/KPIs, D03 authority and D11 budget workflows are integrated end-to-end.

### 14.5 Remaining exhaustive inventory work

| Inventory item | Current disposition | Required next evidence |
|---|---|---|
| Full API directory/tree and all 30 domain file counts | PARTIAL | Recursive repository tree or paginated directory listing at exact SHA |
| All controllers, route groups and endpoint methods | PARTIAL | Complete route file plus controller-to-domain mapping |
| All models, services, policies, contracts, resources and repositories | PARTIAL | Full path-level file listing and namespace classification |
| All migrations and table ownership | UNRESOLVED | Enumerated migration paths, migration registration, create/alter graph and duplicate checks |
| All seeders/factories and test coverage | MISSING | File listing and mapping to domain/table/workflow |
| All events/listeners/jobs/commands/schedules | PARTIAL | Full inventory and event producer-consumer registry |
| App routes/pages/screens and application-scope mapping | PARTIAL | Enumerated route tables and page/component references for all four entry points |
| App feature-to-API endpoint mapping | MISSING | API client inventory and traceable endpoint contracts |
| Permissions/roles/authority/maker-checker matrix | PARTIAL | Route-policy-permission-role matrix with authoritative approval evidence |
| Reporting source lineage and six-frequency output contracts | PARTIAL | D01/D02/D03/D11/D23 approved producer contracts and accountable source owners |

### 14.6 Tooling limitation and confidence

The available GitHub connector did not expose a recursive Git tree API. Public GitHub directory pages were therefore used to enumerate selected direct child listings at the pinned SHAs. This improves path evidence but does not constitute a complete recursive inventory of every nested file. Directory traversal remains in progress.

No builds, tests, CI, migrations, database queries, browser execution, runtime inspection or deployment checks were run. No API/App application source was modified.



### 14.7 Direct directory enumeration at pinned SHAs

**API domain root** `mases_core/src/Domains/` exposes these 11 direct domain directories:
`Documents`, `FileManagement`, `Finance`, `Governance`, `Loan`, `Organization`, `People`, `PublicContent/Models`, `Shareholder`, `Task`, `Wallet`.

This is a source-folder observation only. It is not a substitute for the frozen D01–D30 catalogue. A domain with no same-named direct folder is not automatically classified MISSING; implementation may be represented through shared Core, another domain, a capability module or not yet implemented. The complete route/schema/contract evidence must decide.

**App feature root** `src/features/` exposes these 19 direct feature directories:
`appointments`, `auth`, `calendar`, `communications`, `dashboard`, `executive-overview`, `files`, `governance`, `launcher`, `loans`, `locale`, `notifications`, `people`, `portal`, `public-content`, `public-site`, `shareholders`, `tasks`, `wallet`.

The four build entry points remain `workspace-entry/`, `portal-entry/`, `admin-entry/`, and `public-entry/`. Feature directory presence does not establish route exposure in every app or workflow completion.

**Migration directory listing:**

| Location at API SHA 9c0ce83 | Direct PHP migration files | Observation |
|---|---:|---|
| `database/migrations/` | 53 | Core/platform and cross-domain migrations, including canonical RBAC, tenant, audit/security, People, Organization, Loan, Wallet, Documents, Public Content, Communications, Tasks, Calendar and Shareholder import areas |
| `mases_core/src/Migrations/` | 4 | Calendar core/lifecycle and reminder evolution migrations |
| **Total observed direct migration files** | **57** | File count only; not proof that every file is loaded, ordered correctly, applied to a database or free of collisions |

Migration evidence requires a follow-up trace of `CoreServiceProvider` migration loading, provider-level domain migration registration, Laravel migration discovery, timestamp collisions, duplicate table operations and foreign-key dependencies. The root list contains repeated timestamps with different filenames; these need explicit ordering/dependency review. No migration was run.

**Finance domain structural evidence:** its README documents Config, Contracts, Enums, Events, Listeners, domain-owned Database/Migrations and Database/Seeders, Documentation, Http, Models, Policies, Repositories, Services, Support, Routes/api.php and Feature/Unit tests. It also states that the chart-of-accounts seeder is not automatically invoked and that consolidation remains read-only pending governed rules. These are documented design claims; seeder execution and live behavior remain unverified.

**Observed structural variation across API domains:** Documents exposes Contracts, Database/Seeders, Models, Registries and Services; Governance exposes Config, Database, Enums, Events, Http, Listeners, Models, Policies, Providers, Repositories, Resources/lang, Routes, Services and Tests/Feature; Loan and Wallet expose broader domain application structures; People and Organization show narrower visible folder structures. The variation means a single expected folder template cannot be used as a completeness test. Each domain must be assessed against its approved scope and actual artifacts.



### 14.8 App feature direct-file inspection

Direct child listings were inspected for all 19 `src/features/` folders at App SHA `71bed2158894e3ce9cf507e8bf70492ac1f9dfd4`. Selected concrete surfaces include:

| Feature | Directly observed files/components | P0 implication |
|---|---|---|
| appointments | `AppointmentWorkspaceView.vue`, `api.ts`, `labels.ts` | Calendar/appointment capability surface |
| auth | AuthLayout, login/register/reset/verification forms, `api.ts`, `service.ts` | Identity UI; server authorization remains authoritative |
| calendar | `CalendarWorkspaceView.vue`, `api.ts` | Calendar workspace surface |
| communications | `AnnouncementsView.vue`, `CommunicationHubView.vue`, `api.ts` | Communication Hub surface |
| dashboard | `DashboardView.vue`, `labels.ts` | General dashboard surface |
| executive-overview | `ExecutiveOverviewView.vue`, `api.ts`, `labels.ts`, `service.ts` | Executive read model; not a full D01 strategy/planning system |
| files | FileDetailView, FilesListView, `api.ts`, `labels.ts`, `service.ts` | File Management UI surface |
| governance | Governance attendance/body/overview/finance-settlement/people views plus API/service files | Governance experience is broader than Board meeting records alone |
| launcher | `service.ts` | Application launcher integration |
| loans | Application, approval, collateral, disbursement, repayment, restructure, write-off, list/detail/schedule/statement components plus API/service files | Loan lifecycle UI surface; actual role/approval controls require API reconciliation |
| locale | `api.ts` | Localization integration surface |
| notifications | `NotificationsCenter.vue`, `api.ts` | Notification UI surface |
| people | PeopleDirectoryView, PeopleProfileView, `api.ts` | People UI surface |
| portal | Portal home/layout and shareholder certificate, loan, shareholding and wallet pages | Shareholder portal-specific experience |
| public-content | `api.ts` | Public content integration surface |
| public-site | `admin-api.ts`, `api.ts` | Public website and content administration API clients |
| shareholders | Certificate issue/cancel, holding entry, share class, shareholder detail/form/list components plus API/service files | Shareholder administration UI surface |
| tasks | `TaskWorkspaceView.vue`, `api.ts`, `labels.ts` | Task workspace surface |
| wallet | Wallet adjustment/allocation/reversal/detail/list/obligation/open/payment/statement components plus API/service files | Wallet lifecycle UI surface; financial maker-checker evidence still required |

These observations establish visible feature surfaces and direct files only. They do not establish the route coverage in each of the four app builds, API endpoint correctness, successful workflows, authorization enforcement, accessibility or production readiness.

