# ARNA D01 — A2 30-Domain Dependency, Interface and Acceptance Reconciliation

- **Record ID:** ARNA-D01-A2-TRACE-001
- **Version:** 1.0.8
- **Prepared:** 2026-11-24
- **Status:** A2 WORKING SUPPLEMENT — READ-ONLY / OWNER REVIEW NOT REQUESTED BY THIS RECORD
- **Frozen parent:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0`, Owner-approved/frozen
- **Parent catalogue blob:** `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc`
- **Catalogue branch:** `docs/esppm-a1-v1.2.0-frozen-catalogue`
- **API source baseline:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App source baseline:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Related A2 decision:** ESPPM A2 closure report v1.0.1, PR #219, blob `9f9adca91eadef06d50bf661d648b71cd7596d40`
- **Related D01 draft:** `ARNA-D01-A1-SPEC-001 v1.0.0`, PR #222, blob `c04dc84e21ccc9114d8952ba4d38913e36e58004`

## 1. Controlling result and scope

This record reconciles D01's 24 A2 acceptance cases against the frozen 30-domain catalogue. It identifies authoritative business-domain owners, repository source anchors, canonical domain/entity identifiers where actually evidenced, interface-version status, effective-date semantics, tenant/legal-entity boundaries and minimum positive/negative/failure evidence.

It is a read-only A2 reconciliation. It does not create an API contract, database schema, migration, permission, legal authority, appointment, adopted strategy, test pass or implementation authorization.

**Controlling D01 status:**
- Enterprise D01 A2 disposition: **UNVERIFIED** — the Owner rejected the proposed upgrade to PARTIAL in the controlling ESPPM A2 closure decision.
- Requirement checklist: **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**.
- A2 workstream: **OPEN / ACTIVE**.
- D01 subordinate A1: **DRAFT / OWNER REVIEW REQUIRED / NOT FROZEN**.
- A3 NOT AUTHORIZED; A4 NOT STARTED; A5 NOT AUTHORIZED.

## 2. Catalogue reconciliation — mandatory ownership correction

The frozen v1.2.0 catalogue is authoritative for domain ownership. It defines:
- **D01 Corporate Strategy & Enterprise Planning:** enterprise mission, strategic direction, strategic themes, multi-year plans, enterprise priorities and approved cascade from strategy into business plans. Owns strategy/planning baseline, not operational KPI calculation or project execution.
- **D02 Enterprise Performance Management:** enterprise objectives, KPI definitions, targets, scorecards, performance periods, review cycles, variance commentary, corrective actions and performance accountability.
- **D03 Corporate Governance & Board Management:** governance bodies, mandates, positions, terms, appointments, memberships, delegations, authority, meetings, decisions and resolutions.
- **D04 Enterprise Risk Management:** enterprise risk register, risk appetite/tolerance, assessment and treatment ownership.
- **D05 Compliance & Internal Control Management:** obligations registers, controls, compliance monitoring, attestations, findings and assurance.
- **D06 Legal & Regulatory Affairs:** legal interpretation, opinions, statutory obligations, legal matters and legal instruments.
- **D07 Project, Programme & Portfolio Management:** project/programme/portfolio intake and delivery lifecycle.
- **D08 Organization & Structure Management:** organizational units, hierarchy, taxonomy and effective-dated structural assignments.
- **D09 People & Human Capital Management:** canonical natural-person profile and workforce lifecycle.
- **D11 Finance & Accounting Management:** accounting books, chart of accounts, journals, periods and consolidation.
- **D12 Treasury & Cash Management:** bank/cash/liquidity operations.
- **D20 Documents & Records Management:** formal document identity, registration, review, approval, issuance, retention, archival, legal hold and revocation.
- **D22 Calendar, Events & Scheduling Management:** calendars, events, recurrence, invitations and scheduling.
- **D23 Enterprise Reporting & Business Intelligence:** governed analytical datasets, semantic definitions, lineage, models, dashboards and reports.
- **D24 Information Technology & Digital Systems Management:** enterprise IT/service/system lifecycle and technology governance.
- **D25 Investment & Capital Management:** investment appraisal, capital proposals, decisions, allocation, commitments and investment approval gates.
- **D26 Business Development & Strategic Partnerships:** opportunity development and partnership lifecycle.

### 2.1 Material subordinate-specification conflict

D01 subordinate A1 draft v1.0.0 currently says D01 defines/owns “strategic objective definition, hierarchy, alignment and accountable ownership” and includes a D01 StrategicObjective record class. That language conflicts with the frozen catalogue's explicit assignment of **enterprise objectives to D02**.

Controlling interpretation for A2:
1. D01 owns strategy direction, themes, multi-year strategy/plans, enterprise priorities and the approved strategy-to-business-plan cascade.
2. D02 owns authoritative enterprise objective records/hierarchy, KPI definitions, targets, scorecards and performance accountability.
3. D01 may own a strategy-to-D02 objective mapping/reference and planning context, but may not create a competing objective system of record.
4. D07 owns accepted project/portfolio delivery intake and execution; a D01 priority is not a project approval.
5. D23 owns analytical datasets and cross-domain semantic/reporting definitions; it does not redefine D02 KPI formulas.
6. D03 owns the authority and resolution record; D01 cannot infer approval from publication, a role label or a draft workflow.

This is a catalogue-conformance finding. It does not silently amend the frozen catalogue or approve the subordinate D01 draft. PR #222 must remain DRAFT until this conflict is handled through a controlled successor and Owner review.

## 3. Canonical identifiers, source ownership and interface-version rules

### 3.1 Identifier hierarchy

| Identifier class | Canonical form / current status | Rule |
|---|---|---|
| Business domain | `D01`–`D30` | Canonical domain IDs from frozen A1 v1.2.0. |
| Catalogue version | `ARNA-ESPPM-A1-SPEC-002 v1.2.0` | Frozen ownership and scope baseline. |
| Repository source identity | Repository + exact commit + path + Git blob SHA | Required to identify inspected technical evidence. |
| Existing entity/table identifiers | Only names verified below (e.g. `governance_bodies`, `organization_units`, `people`) | These are current implementation identifiers, not accepted D01 interface IDs. |
| Cross-domain business reference ID | **NOT ESTABLISHED for D01** | Do not invent objective, strategy, planning-cycle, risk, portfolio or investment reference IDs. |
| API/event contract ID and version | **NOT ESTABLISHED / NOT ACCEPTED** | No D01 producer/consumer interface version is accepted in the inspected baselines. |
| Named human source owner | **NOT ESTABLISHED** | Catalogue identifies accountable business domain, not a verified named role-holder or current appointment. |

### 3.2 Interface version policy for this reconciliation

No stable D01 interface contract, event schema, API endpoint contract or semantic contract version was identified. Therefore the interface-version field is **NOT ESTABLISHED** for every dependency below. A version such as `1.0.0` must not be fabricated or inferred from a document version, Laravel path, migration timestamp or Git commit.

Required future contract envelope (proposal only; not an approved schema):
- contract identifier and semantic version;
- producer domain and consumer domain;
- stable record ID and source record version;
- tenant and, where applicable, legal-entity scope;
- valid-from/valid-until or effective-date semantics;
- event/decision status and authority reference;
- issued-at timestamp and source commit/release;
- idempotency/correlation key where event-driven;
- freshness, deprecation and failure semantics.

A version becomes accepted only after the source owner and consumer owner approve the contract and the contract is tied to exact implementation/test evidence.

## 4. Dependency source-owner register

“Source owner” below means the authoritative **business domain** in frozen A1, not a verified individual. Technical anchors are exact files from API main at `6e274c25ecc55b84e026e879d735b77f0e51592f`. If no implementation exists in the inspected tree, the register says so rather than inventing one.

| Dependency | Catalogue owner | Canonical IDs / existing source anchors | Interface version | Effective-date boundary | Tenant / legal-entity boundary | A2 conclusion |
|---|---|---|---|---|---|---|
| D01 strategy/planning baseline | D01 | Domain ID D01; no accepted StrategyVersion/Theme/Priority/PlanningCycle table, API or event found | NOT ESTABLISHED | Strategy version effective-from/to, approval date and supersession dates required; no accepted schema | Tenant-scoped by default; group-wide scope only if explicitly authorized and recorded. Legal-entity applicability must be explicit; no D01 schema proves it | No D01 strategy source contract located |
| D02 enterprise objectives/KPIs | D02 | Domain ID D02; no dedicated D02 domain root/accepted objective or KPI contract located in API main | NOT ESTABLISHED | Objective/target/KPI version periods and review period must be D02-owned; no source semantics verified | Tenant scope required; legal entity where KPI/measure is entity-specific; consolidated scope only explicitly declared | Owner established by catalogue; technical contract missing |
| D03 governance authority/resolutions | D03 | `governance_bodies`, `governance_positions`, `governance_terms`, `governance_appointments`, `governance_memberships`, `governance_authority_grants`, `governance_seats`, `governance_seat_occupancies`; models `GovernanceBody`, `GovernanceAppointment`, `GovernanceAuthorityGrant` | NOT ESTABLISHED for D01 consumption; source model/migration baseline only | Body: `effective_from/effective_until`; appointment: `effective_date` plus lifecycle status; authority grant: `effective_from/effective_until`. Must resolve authority at decision date | Governance records have `tenant_id`; no automatic cross-tenant or legal-entity authority. Scope of a resolution must be read from authoritative instrument, not inferred | Reusable authority data exists; actual D01 strategy approval record not established |
| D04 enterprise risk | D04 | Domain ID D04; no D04 source root/accepted risk-register contract identified | NOT ESTABLISHED | Risk assessment, appetite and treatment validity dates are D04-owned; exact semantics absent | Tenant scope; legal-entity applicability and group aggregation must be explicit | Technical producer missing in inspected tree |
| D05 compliance/internal control | D05 | Domain ID D05; no D05 source root/accepted obligations/control contract identified | NOT ESTABLISHED | Obligation effective dates, control assessment period and finding/remediation dates are D05-owned | Tenant and jurisdiction/entity scope required; legal entity cannot be inferred from tenant alone | Technical producer missing in inspected tree |
| D06 legal/regulatory | D06 | Domain ID D06; no D06 source root/accepted legal-opinion/obligation contract identified; D20 may store formal evidence but is not legal authority | NOT ESTABLISHED | Instrument issue/effective/expiry/amendment dates and jurisdiction are authoritative; no legal dataset verified | Tenant + jurisdiction + affected legal entity; applicability must be explicit | Legal authority/evidence unresolved; no D06 producer contract located |
| D07 project/portfolio delivery | D07 | Domain ID D07; no D07 source root/accepted portfolio intake contract identified | NOT ESTABLISHED | Intake approval, stage-gate and delivery dates are D07-owned; D01 priority date is not delivery approval | Tenant + receiving project/legal entity; multi-entity portfolio scope must be explicit | Technical producer missing in inspected tree |
| D08 organization structure | D08 | `organization_units`, `organization_assignments`; models `OrganizationUnit`, `OrganizationAssignment` | NOT ESTABLISHED for D01 mapping | Assignments use `starts_on/ends_on`; structure and assignment must be resolved as of relevant date | Both models use `tenant_id`; unit has `legal_entity_id`; assignment is scoped to tenant/unit/person | Source patterns exist; no D01 objective-owner mapping contract |
| D09 people/workforce | D09 | `people`, `employees`, `person_tenant_accesses`; models `Person`, `EmployeeRecord`, `PersonTenantAccess` | NOT ESTABLISHED for D01 ownership | Workforce/employment effective dates are D09-owned; appointment authority remains D03; no inferred active appointment from a Person row | Person identity is canonical; tenant relationship is explicit through tenant-access/workforce relationships. Do not use a person ID as tenant authority | Person/workforce source exists; no D01 accountable-owner contract |
| D11 finance/accounting | D11 | `legal_entities`, `accounting_periods`, `ledger_accounts`, `journal_entries`, `journal_lines`; `LegalEntity`, `AccountingPeriod`; Finance domain contract docs | NOT ESTABLISHED for D01 strategy/budget interface | Accounting period uses `starts_on/ends_on`; journal posting/close status is D11-owned | `tenant_id` + `legal_entity_id`; entity and currency must be explicit. D01 strategy priority grants no budget authority | Finance source exists; no accepted D01-to-budget alignment contract |
| D12 treasury/cash | D12 | Domain ID D12; no D12 source root/accepted liquidity/cash interface identified in this inventory | NOT ESTABLISHED | Cash position/forecast dates are D12-owned | Tenant + bank account/legal entity scope | No D01 dependency contract; only use if planning baseline consumes approved liquidity context |
| D20 documents/records | D20 | `documents`, `document_workflow_aggregates`, `document_workflow_events`, `document_lifecycle_events`; models `Document`, `DocumentWorkflowAggregate`, `DocumentWorkflowEvent`; `DocumentWorkflowAggregateService` | NOT ESTABLISHED for a D01 strategy record type | Formal document issue/review/approval/retention/hold/revocation lifecycle; document dates do not replace strategy effective dates | Tenant-scoped formal records; legal-entity/jurisdiction classification must be explicitly carried where relevant | Formal record engine exists; no accepted D01 strategy type or approval mapping |
| D22 calendar/events | D22 | `calendars`, `calendar_events`, `calendar_event_links`, `calendar_attendees`; models `Calendar`, `CalendarEvent`, `CalendarEventLink` | NOT ESTABLISHED for D01 planning cycle | Calendar/event dates, recurrence and timezone are D22-owned; D01 cycle effective dates remain D01 authority | Tenant-scoped event access; linked resources retain source-domain authorization; no implied access from a calendar link | Calendar source exists; no D01 planning-cycle integration |
| D23 reporting/BI | D23 | Domain ID D23; no D23 source root/accepted semantic dataset contract identified in API main | NOT ESTABLISHED | Dataset snapshot/freshness, lineage and semantic version are D23-owned; KPI target period remains D02 | Tenant/legal-entity filters must be lineage-preserving; certified consolidated view only when explicitly authorized | BI source contract missing |
| D24 IT/digital systems | D24 | Cross-cutting source inventory: API/App repos, routes, providers, Actions; no D01 product/service management record. D24 owns technology/service lifecycle per catalogue | NOT ESTABLISHED | Source evidence must bind exact commit, workflow run and observed time; runtime release validity is separate | Repo access/branch protection and environment scope; tenant data isolation remains platform/domain controls | Technical evidence custodian/domain owner to be designated; no D01 service contract |
| D25 investment/capital | D25 | Domain ID D25; no D25 source root/accepted investment approval contract identified | NOT ESTABLISHED | Appraisal/approval/commitment effective dates and stage gates are D25-owned | Tenant + investment legal entity + currency/capital scope | D01 priority is not investment approval; technical producer missing |
| D26 partnerships | D26 | Domain ID D26; no D26 source root/accepted partnership lifecycle contract identified | NOT ESTABLISHED | Opportunity, agreement, effective/expiry and renewal dates are D26-owned | Tenant + contracting legal entity + counterparty jurisdiction | D01 alignment is not partner approval; technical producer missing |

### 4.1 Technical source ownership vs business ownership

- A domain's business owner remains the frozen catalogue domain, even if the current API code sits under a shared folder or another namespace.
- D24/platform teams own technical runtime, repository, deployment and shared-service controls; they do not acquire the business authority of D01/D02/D03 etc.
- D20 owns formal records and document lifecycle; binary storage does not become document authority.
- D03 owns authority/appointment/resolution semantics; D08 organizational assignments and D09 Person/Employee records do not confer governance authority.
- D11 owns accounting and legal-entity accounting references; a D01 plan may reference approved budgets but cannot create accounting or funding authorization.
- No named individual is certified as source owner by this A2 source inspection.

## 5. Global effective-date, tenant and legal-entity rules

### 5.1 Effective-date rules

1. Resolve every authority, appointment, organization assignment, strategy version, objective, KPI target, risk assessment, legal instrument and planning cycle against the date/time relevant to the decision or report.
2. Distinguish **effective date** (when a record applies) from **created/recorded timestamp** (when it was entered) and **approval/decision timestamp** (when an authority acted).
3. D03 governance body dates and appointment/authority-grant validity must be evaluated at the date of the strategy decision.
4. D08 assignment dates determine organizational responsibility at a point in time; they do not grant D03 approval authority.
5. D02 owns objective/KPI/target effective periods; D01 references the approved version and does not override those periods.
6. D11 accounting periods and D22 calendar/event dates are different temporal domains; no implicit equivalence with D01 planning years.
7. D06 legal instrument applicability requires jurisdiction, issue/effective/expiry/amendment dates and affected entity; a document upload date is not legal effect.
8. If dates overlap, conflict, are missing or cannot be interpreted, the consumer must mark the dependency UNRESOLVED/UNAVAILABLE and fail closed for approval/authority-sensitive actions.

### 5.2 Tenant and legal-entity rules

1. Default business record scope is tenant-specific. Cross-tenant access is never implied by a group-level label, shared user, shared file or dashboard.
2. A group-wide strategy must have explicit authorized scope and a traceable competent decision. Do not assume one tenant's strategy applies to all tenants.
3. Where a dependency is legal-entity-specific (D08 structure, D11 accounting, D25 capital, D26 contract, D06 legal applicability), carry the exact legal-entity reference and validate that it belongs to the tenant.
4. A tenant may contain multiple legal entities. Tenant ID and legal-entity ID are not interchangeable.
5. D03 body/appointment/authority records are tenant-scoped in current implementation; their authority scope must still be proven from the actual governing instrument.
6. D09 Person identity is distinct from tenant access, employment, appointment and authorization. Person existence is not proof of a current executive appointment.
7. D22 event linkage does not grant access to the linked strategy, governance or project record.
8. Missing tenant, wrong legal entity, cross-tenant reference, unverified group scope or expired authority is a hard rejection for protected operations; read-only reports must disclose unavailable/inapplicable sources rather than silently aggregate.

## 6. Reconciled 24-requirement acceptance/evidence matrix

The table assigns the authoritative business owner(s), canonical source IDs, version status and minimum positive/negative/failure evidence. “No D01 interface version” is a material A2 gap, not an invitation to invent one.

| Req. | Frozen-catalogue source owner(s) and canonical source | Version / boundary | Positive evidence required | Negative evidence required | Failure evidence required |
|---|---|---|---|---|---|
| **01 — operative corporate instruments** | D06 legal applicability; D20 formal record; D03 authority/resolution. IDs: D06/D20/D03; source record IDs not yet verified | No interface version. Jurisdiction, tenant, legal entity, instrument effective/expiry/amendment dates | Certified operative instrument, amendment chain, registration identity, custody/hash and D03/D06 confirmation of applicable authority | Show why superseded, draft, unsigned, unregistered, wrong-entity or wrong-jurisdiction records are excluded | Missing or conflicting instrument identity, unauthenticated scan, unresolved amendment or uncertain applicability -> UNRESOLVED; no authority inference |
| **02 — GA/Board strategy approval** | D03 sole authority/resolution owner; D20 evidence record; D01 consumes decision reference | No contract version. Decision date must resolve D03 body/term/appointment/grant validity; tenant and explicit entity/group scope | Certified resolution with exact strategy version, competent body, quorum, vote/outcome, date, conditions, effective date and supersession | Reject draft minutes, publication, recommendation, expired body/term, invalid quorum/vote or wrong scope as approval | No certified resolution or authority chain -> approval unavailable; strategy remains not adopted/UNVERIFIED |
| **03 — authority/delegation matrix** | D03 authority grants, positions, terms, appointments and delegations; D20 stores instruments | No D01 interface version. Effective-at decision date; tenant-scoped; instrument-defined authority scope | Exact authority record, instrument reference, action/scope, delegate, effective dates, revocation and SoD evidence | Reject expired/revoked grant, wrong action/body/entity, unauthorized subdelegation, self-approval or role-name-only evidence | Missing/ambiguous delegation or unresolved competent body -> fail closed; do not infer from RBAC role or organization chart |
| **04 — accountable executive/planning owners** | D03 appointments; D08 org unit/assignment; D09 Person/workforce | No interface version. D03 appointment effective date + D08 starts_on/ends_on + D09 employment validity; tenant/entity match | Person record + active employment/engagement + current D03 appointment if governance role + D08 assignment and approved responsibility instrument | Reject inactive person, expired appointment, cross-tenant identity, assignment without authority, conflicting concurrent responsibility | Missing current appointment or source-owner approval -> accountable role-holder NOT VERIFIED; proposed role remains proposal only |
| **05 — strategy versions/multi-year plans** | D01 owns strategy/planning baseline; D20 formal record/evidence; D03 approval; D02 owns objective records referenced by strategy | No D01 contract/version. D01 version effective/supersession dates; D03 approval date; D20 issue/retention | Immutable version with stable ID, status, approval reference, effective interval, prior-version link, change rationale and owner; objectives link to D02 IDs | Reject in-place overwrite, draft presented as approved, superseded version as current, missing approval or duplicate active baseline | Missing strategy source, version lineage, approval or unresolved contradiction -> no current approved baseline can be asserted |
| **06 — planning calendar** | D01 owns planning-cycle rules; D22 owns calendar/event scheduling; D02 owns performance review periods | No D01↔D22 contract version. Separate D01 cycle period vs D22 event date and D02 review period; tenant scope | Approved cycle definition, planning horizon, E.C./G.C. semantics, cut-offs, milestone owner, D22 event references and D02 review alignment | Reject calendar event as proof of cycle approval; reject ambiguous timezone/date, overlapping active cycles or invalid calendar conversion | Missing cycle approval, date semantics or timezone -> cycle UNRESOLVED; do not infer from generic calendar |
| **07 — objective hierarchy/cascade** | **D02 owns enterprise objectives and hierarchy**; D01 owns strategy-to-objective alignment references; D08 owns organization structure/assignment; D07 owns delivery intake | No accepted interface. Objective version/period D02; strategy version D01; owner assignment D08; tenant/entity scope explicit | D02-issued objective IDs with parent/child links, approved version/period, accountable owner and D01 strategy-version/theme reference; D07 receives approved priority/initiative reference | Reject D01-created duplicate objective, orphan/cyclic hierarchy, stale objective, cross-tenant parent, owner inferred from D08 assignment alone | No D02 objective producer or no accepted D01↔D02 contract -> mapping UNAVAILABLE; subordinate D01 draft's conflicting StrategicObjective ownership flagged for correction |
| **08 — scenarios/assumptions/baselines** | D01 owns strategy/planning scenarios and baselines; D04 owns risk assumptions/assessment; D23 owns analytical dataset/lineage; D20 stores approved evidence | No accepted D01 contract. Version/effective interval; source snapshot timestamp/freshness; tenant/entity scope | Versioned baseline/scenario with provenance, assumptions, author, approval state, snapshot date, comparison basis and D04/D23 source refs | Reject mutable baseline, missing provenance, stale dataset, unsupported forecast, draft scenario presented as approved | Missing source snapshot, unresolved risk, failed data quality or missing approval -> scenario marked incomplete/unavailable |
| **09 — strategy-to-budget/investment** | D25 owns investment appraisal/allocation; D11 owns budget/accounting/legal entity; D12 owns liquidity/cash; D01 owns priority only | No interface version. D11 accounting period/legal entity/currency; D25 approval and commitment effective dates; tenant + legal entity | Approved D25 decision/commitment and D11 budget/accounting reference tied to D01 priority and same entity/currency/period | Reject strategy priority as spending authority, unapproved capex, cross-entity/currency mismatch, D11 journal as investment approval | Missing D25 approval, budget reference, entity mapping or period -> no funded/approved status; fail closed |
| **10 — portfolio/project intake** | D07 owns project/programme/portfolio intake and execution; D01 provides approved strategy priority; D25 if investment gate applies | No interface version. D07 stage-gate dates/status; D01 strategy version; tenant and receiving entity | D07 intake ID, sponsor, delivery owner, stage gate, accepted objective/priority reference, benefits owner and approval evidence | Reject project created without approved priority, unsupported sponsor, bypassed D07 gate or implied capital approval | D07 source contract missing or intake rejected/withdrawn -> D01 cannot represent initiative as active delivery |
| **11 — KPI dictionary/mapping** | D02 sole KPI/objective/target owner; D01 strategy alignment reference; D23 semantic/reporting consumer | No interface version. D02 KPI/target version, unit, formula, effective period and tenant/entity | D02 canonical KPI and objective IDs, formula/unit/target/threshold/version/owner, linked to approved D01 strategy reference | Reject D01-defined KPI formula/target, duplicate/conflicting KPI, missing unit/formula, stale target or unapproved KPI | No D02 producer or unresolved definition -> KPI unavailable; do not calculate/relabel in D01 |
| **12 — BI dataset/lineage** | D23 owns dataset, semantic layer and lineage; D02 owns KPI semantics; source domains own transactions | No interface version. Dataset snapshot, transform/semantic version, freshness SLA; tenant/entity lineage | Certified dataset ID/version, source lineage, transformation version, freshness, quality status and mapping to D02 KPI/D01 strategy references | Reject uncertified/stale dataset, missing lineage, tenant filter loss or D23 KPI redefinition | Failed quality/freshness or source outage -> mark stale/unavailable, no silent substitution |
| **13 — strategic risk** | D04 owns risk register/appetite/treatment; D01 consumes strategy-level risk references; D05 owns control/compliance evidence | No interface version. Risk assessment/appetite effective period and review date; tenant/entity scope | D04 risk ID, owner, appetite/tolerance, assessment date, response/treatment, status and strategy link | Reject D01-created duplicate risk, expired assessment, out-of-tolerance risk without authorized response, unowned risk | D04 source absent or risk data stale/unavailable -> risk posture unresolved; no risk acceptance implied |
| **14 — legal/regulatory obligations** | D06 owns interpretation/legal obligations; D05 owns compliance register/control assurance; D20 owns formal record evidence | No interface version. Jurisdiction, instrument effective/expiry/amendment date; affected tenant/entity | D06-validated instrument/obligation ID, applicability opinion, jurisdiction/entity, owner, review date; D05 compliance/control link | Reject draft/expired/inapplicable instrument, unqualified legal interpretation, document upload as proof of applicability | Conflicting legal advice or missing certified instrument -> UNRESOLVED; no compliance/legal claim |
| **15 — API source/contract inventory** | D24 technical system/repository lifecycle; each business domain owns business contract; D20 stores approved contract artefact | Exact API main SHA is evidence baseline, not interface version. Bind path/blob/commit/workflow; tenant scope per endpoint | Exact route/provider/service/model/migration/test source and contract owner; schema/auth/scope/version and workflow/test evidence at same SHA | Reject unregistered route, source from different SHA, stale PR, undocumented permission or cross-tenant read/write | Missing route registration, contract, test/CI or source mismatch -> NOT VERIFIED; tests NOT RUN are not PASS |
| **16 — App feature/UI inventory** | D24 owns app/system lifecycle; D01 owns strategy business workflow; D02/D03/D07 own their respective domain UI data/decisions | Exact App main SHA; no D01 UI contract version. Tenant switch and locale/calendar semantics | D01 route/menu/page/API typed contract and permission parity with backend; Amharic/E.C. and accessibility states; tenant reset behavior | Reject frontend-only authorization, mock/fabricated data, stale tenant cache, UI approval without D03 backend authority | No D01 feature/route or API contract -> UI capability MISSING; build/browser tests NOT RUN remain NOT RUN |
| **17 — branch/PR/workflow evidence** | D24 technical repository/change lifecycle; business domain owner approves scope; Owner controls project gate | Evidence bound to repo, branch, exact head/base SHA, PR and workflow run ID; no semantic API version | Reproducible PR metadata, changed files, review/approval, workflow run tied to head SHA, test logs/artifacts | Reject unrelated branch, stale run on prior SHA, skipped required job, missing approval or unrelated changes | No run URL/log, failed/cancelled workflow, permission denial or SHA mismatch -> NOT VERIFIED; no claim of CI pass |
| **18 — identity/tenant/RBAC** | Cross-cutting platform identity/authz (D24 technical owner); D03 owns business authority; D08 structure; D09 person identity/workforce | Existing platform RBAC schema is baseline; no D01 permission namespace/interface version accepted | Verified actor, active tenant membership, legal-entity scope, action-specific permission and D03 authority where required | Reject forged tenant context, cross-tenant IDs, revoked membership, role-name inference or D08 assignment as permission | Missing policy/provider, unknown tenant/entity, stale grant or failed authorization -> deny by default |
| **19 — audit/retention/legal hold** | D20 formal records/retention/legal hold; cross-cutting platform Audit; D03 decision authority; D06 legal hold applicability | Audit schema/version not a D01 interface. Event timestamp vs effective date distinguished; tenant nullable only for system-level events | Append-only event with actor, tenant, action, before/after, source record/version, decision reference, timestamp, retention class and custody | Reject event mutation/deletion, missing actor/source, tenant leakage, hold bypass or audit log treated as formal resolution | Audit sink unavailable or evidence chain incomplete -> block protected approval/publication; disclose missing evidence |
| **20 — Amharic/E.C./accessibility** | Cross-cutting localization/accessibility (D24 platform); D22 owns calendar/event semantics; D01 owns planning period meaning; D02 owns performance periods | No D01 locale/calendar contract version. E.C./G.C. conversion and timezone explicit; do not conflate calendar with fiscal/planning year | Approved Amharic glossary, E.C.-first date semantics, conversion test vectors, keyboard/screen-reader and responsive evidence | Reject G.C.-only default where E.C. required, ambiguous date conversion, missing translation or inaccessible approval action | Locale/calendar service failure or unresolved period rule -> block date-sensitive approval; show explicit unavailable state |
| **21 — dictionary/lineage/reference IDs** | D23 owns cross-domain metadata/semantic lineage; each source domain owns its IDs/definitions; D01 owns strategy record identifiers when created | No accepted D01 ID/contract version. Source ID + version + effective date + provenance + freshness required | Canonical source identifier, source domain, owner, definition, version, valid dates, lineage and quality/freshness evidence | Reject invented/duplicate IDs, unowned definition, stale source, ambiguous version or missing lineage | Producer unavailable or no stable ID/version -> UNAVAILABLE; no display as verified source |
| **22 — process/templates/consultation** | D01 owns strategy process; D03 owns approval/meeting/decision governance; D20 owns controlled records; D21 owns stakeholder communication where applicable | No accepted process/template version. Consultation and decision dates, approval/effective date | Approved process map/template version, participant scope, consultation record, minutes, reviewer comments, decision and exception log | Reject bypassed consultation, missing minutes, unauthorized approver, unpublished draft presented as adopted | No competent process approval or incomplete record -> process status OPEN/UNVERIFIED |
| **23 — security/privacy/SoD** | Cross-cutting security/authorization (D24); D05 controls/assurance; D06 legal/privacy interpretation; D20 classification/retention; D03 approval authority | No D01 SoD policy/version accepted. Tenant/entity and classification scope; authority effective dates | Approved access matrix, proposer/reviewer/approver separation, least privilege, confidentiality class, export controls, threat/test evidence | Reject self-approval, privilege escalation, unauthorized export, broad cross-tenant access or admin treated as business approver | Policy absent, permission service failure, classification unknown or SoD conflict -> deny protected action and log |
| **24 — accepted domain dependencies** | D01 orchestrates references; authoritative source owners D02/D03/D04/D05/D06/D07/D08/D09/D11/D12/D20/D22/D23/D24/D25/D26 as applicable | No accepted D01 interface contract/version. Each dependency requires its own source version and contract version | Signed/accepted producer-consumer matrix with owner, stable IDs, contract version, tenant/entity, effective dates, freshness, failure handling and deprecation | Reject unknown version, missing owner, cross-tenant/entity mismatch, stale source, silent fallback or ownership conflict | Producer outage/schema drift/revocation/quality failure -> explicit unavailable state, reconciliation queue and no false success |

## 7. Requirement-to-catalogue crosswalk

| D01 requirement | Primary frozen domain(s) | Secondary / platform dependencies |
|---|---|---|
| 01 instruments | D06, D20, D03 | D24 evidence custody |
| 02 strategy approval | D03, D01 | D20 |
| 03 authority/delegation | D03 | D20, D24 authorization |
| 04 executive/owners | D03, D08, D09 | D24 identity/authz |
| 05 strategy versions | D01, D03, D20 | D02 objective references |
| 06 planning calendar | D01, D22, D02 | D24 localization |
| 07 objective cascade | D02, D01, D08, D07 | D03 approval |
| 08 scenarios/baselines | D01, D04, D23, D20 | D11/D25 where finance/capital inputs apply |
| 09 budget/investment | D25, D11 | D12; D01 priority only |
| 10 portfolio intake | D07, D01 | D25 if capital gate applies |
| 11 KPI mapping | D02, D01 | D23 |
| 12 BI lineage | D23, D02 | Source-owning domains |
| 13 risk | D04 | D05, D06 |
| 14 legal/regulatory | D06, D05, D20 | Affected source domains |
| 15 API inventory | D24 | All business source domains |
| 16 App inventory | D24, D01 | D02/D03/D07 domain workflows |
| 17 GitHub/CI evidence | D24 | Business owner, Owner approval gate |
| 18 identity/tenant/RBAC | D24 platform | D03, D08, D09 |
| 19 audit/records | D20 | D03, D06, D24 audit |
| 20 localization/calendar | D24 platform, D22 | D01, D02 |
| 21 data dictionary/lineage | D23 | All source domains |
| 22 strategy process | D01, D03, D20 | D21 where consultation communication applies |
| 23 security/SoD | D24, D05, D06, D20 | D03 |
| 24 dependency contracts | D01 plus each named producer | D02/D03/D04/D05/D06/D07/D08/D09/D11/D12/D20/D22/D23/D24/D25/D26 |

## 8. Minimum evidence package and disposition logic

For each dependency and requirement, retain:
1. Frozen domain ID and catalogue version.
2. Business source owner domain and verified named owner only if formally evidenced.
3. Canonical source record ID, or explicit `NOT ESTABLISHED`.
4. Producer/consumer contract ID and version, or explicit `NOT ESTABLISHED`.
5. Exact source repository/branch/commit/path/blob or certified document identifier/hash.
6. Effective-from/effective-until, approval/decision timestamp and recorded timestamp as separate fields.
7. Tenant ID and legal-entity ID/scope, with proof of relationship and authorization.
8. Positive test evidence: exact expected accepted result and observed result.
9. Negative test evidence: prohibited operation/input and proof of rejection.
10. Failure test evidence: unavailable/stale/conflicting dependency and proof of fail-closed behavior, audit and recovery.
11. Exact test command/workflow run, commit SHA, log/artifact URL and outcome. File existence alone is not test execution.
12. Exception owner, expiry/review date and approved risk acceptance, if any.

Status rules:
- **VERIFIED-CLOSED:** all mandatory positive, negative and failure evidence accepted at the exact source/contract version; authority and scope independently proven.
- **PARTIAL:** some source/evidence exists, but one or more required proof elements are absent.
- **MISSING:** required source/contract/evidence not located or not produced.
- **UNRESOLVED:** conflicting or ambiguous evidence prevents a reliable decision.
- **NOT RUN:** no test execution evidence; must not be converted to PASS.
- **UNAVAILABLE:** source cannot currently be queried; must not be silently substituted.

## 9. Explicit unresolved evidence matters — preserved fail-closed

This reconciliation does not resolve and must not imply resolution of:
1. **Adopted-strategy resolution:** no certified GA/Board resolution establishing the adopted enterprise strategy/version was authenticated in this technical inventory.
2. **Competent approval authority:** the operative legal/governance instrument and authority/delegation chain for approving strategy remains unverified.
3. **Current appointments:** current executive and planning-role appointments remain unverified; a Person, Employee, OrganizationAssignment or generic role does not prove appointment.
4. **Legal instrument identity:** registered/operative instrument identity, amendments and legal applicability remain unresolved as previously recorded. Distinct bylaws scans/DOCX must not be conflated with registered MoA/AoA.

No repository implementation, draft, meeting calendar, dashboard, CMS publication, branch or role slug may substitute for these records.

## 10. Technical evidence anchors inspected

API main `6e274c25ecc55b84e026e879d735b77f0e51592f`:
- Governance: `GovernanceBody.php` blob `d56ba1a5955c5546c6f63c2ad5743df7a9c935d9`; `GovernanceAppointment.php` blob `2e503c5593daef4d619cf3fddbc90c1384ae3d98`; `GovernanceAuthorityGrant.php` blob `2c8e4ebcc3a2dde02824cbfc7ba5e86d7bf3b0a1`.
- Organization: `OrganizationUnit.php` blob `93fa42ef36e2bcc801958f9f1c42eb122fa1b55a`; `OrganizationAssignment.php` blob `a6b5887318d54ab6d16b22869dfdc8b1d7b355a4`.
- People: `Person.php` blob `e3097b788fd253057fdae72fb96a91c8e9c3ac40`; `EmployeeRecord.php` blob `3ecc31577760c21bfd5bf6801a2d4212e7c8033a`.
- Calendar: `CalendarEvent.php` blob `ee4662b92f8c52dc5c1f2a58e324dd7dbbde7d90`; calendar migrations `2026_11_01_000001_create_calendar_core_tables.php` blob `e9e8ecb1cadb9efb6c0929ef4577a504783387de` and `2026_11_01_000002_create_calendar_lifecycle_tables.php` blob `473f053c26328ccb9f1e97d08c555bd24360d89a`.
- Finance: `LegalEntity.php` blob `d5bf533a34088f23bbc75b33bf154cebc798b9fc`; `AccountingPeriod.php` blob `f864a53d8af0bf1c717001d06c59f2e142a0b08f`; Finance domain contract `finance-accounting-domain-contract.md` blob `a28040d3926973004675aaf5bbe0760cd5b4565a`.
- Documents: `Document.php` blob `999a91d2d8bb7cb4fdd3cdc1cc46fb963cf7ce1b`; `DocumentWorkflowAggregateService.php` blob `55667ea2df7658cc141057e232aa3afbe4d0a32d`.
- Audit: `AuditLog.php` blob `1ce7eefb3306c9ad33cc8a18c19166749dceba58`.
- Tenant membership: migration `2026_09_07_000003_create_tenant_memberships_table.php` blob `e39ec5fc20b558bef091050daba12679c8283950`.
- Authorization: canonical current role/permission models exist; D01-specific permission namespace/interface is not accepted.
- App main `ffe0495c0eb7c8b5d2013419123aac5b68893d51`: no D01 strategy workspace/route was located in the exact tree.

These are repository source anchors, not proof that the corresponding production schema has been migrated or that tests pass.

## 11. A2 completion and next controlled action

- Frozen catalogue reconciliation: **COMPLETED for the 24 D01 requirements**.
- Domain source-owner assignment: **COMPLETED at domain level**; named individual owners remain NOT ESTABLISHED.
- Canonical domain IDs: **ESTABLISHED** as D01–D30 at catalogue v1.2.0.
- Canonical D01 business record IDs: **NOT ESTABLISHED**.
- Existing implementation entity IDs: **PARTIALLY IDENTIFIED** for D03, D08, D09, D11, D20 and D22.
- D01 interface versions: **NOT ESTABLISHED**; no versions invented.
- Effective-date and tenant/legal-entity boundary rules: **DEFINED for A2 acceptance**; per-domain producer contracts still missing.
- Positive/negative/failure evidence: **DEFINED for all 24 requirements**.
- D01 subordinate A1 catalogue-conformance conflict: **IDENTIFIED — D02 owns enterprise objectives**.
- Adopted-strategy resolution, competent authority, current appointments and legal instrument identity: **UNRESOLVED — preserved**.
- Test execution/build/CI: **NOT RUN**.
- Enterprise D01 A2 disposition: **UNVERIFIED**; checklist remains 16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED.

**Immediate next A2 action:** produce a source-owner review sheet for each producer domain (D02, D03, D04, D05, D06, D07, D08, D09, D11, D12, D20, D22, D23, D24, D25 and D26), with a named accountable custodian, stable business reference ID, proposed contract ID/version, tenant/entity and effective-date rules, and exact test-evidence location. Any unavailable producer must remain explicitly unavailable; do not create substitute records in D01.

**Change boundary:** documentation only on the D01 evidence branch. No runtime code, migrations, schema, seeders, permissions, configuration, production data, merge or deployment changed or authorized.
