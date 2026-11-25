# ARNA D01 — A2 Producer-Domain Source-Owner Review Sheet

- **Record ID:** ARNA-D01-A2-SOR-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-25
- **Status:** A2 WORKING SUPPLEMENT — READ-ONLY / REVIEW REQUIRED
- **Parent reconciliation:** `ARNA-D01-A2-TRACE-001 v1.0.8`, blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`
- **Frozen catalogue:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0`, blob `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc`
- **API source baseline:** `akefadaka-git/arna-api@6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App source baseline:** `akefadaka-git/arna-app@ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Working branch:** `docs/arna-d01-a2-evidence-register`

## 1. Purpose and decision boundary

This sheet operationalizes the next A2 action from TRACE-001 v1.0.8. It reviews the sixteen producer domains D02, D03, D04, D05, D06, D07, D08, D09, D11, D12, D20, D22, D23, D24, D25 and D26 for:

1. formally evidenced named accountable custodian;
2. stable business reference identifier;
3. proposed D01-consumption contract identifier and version;
4. tenant and legal-entity scope;
5. effective-date and supersession semantics; and
6. positive, negative and failure evidence locations.

This is a documentation-only, read-only A2 record. It does not create source-domain identifiers, API/event schemas, permissions, database fields, tests, appointments, authority, or approved contracts. Proposed identifiers below are design candidates only. No producer domain is deemed to have accepted them.

## 2. Review rules

### 2.1 Named accountable custodian

The frozen catalogue establishes business-domain ownership, not the name of an individual custodian. The inspected repository evidence does not authenticate current appointments or delegations for these sixteen source domains. Accordingly, **named accountable custodian = NOT ESTABLISHED for all sixteen domains**.

A future named custodian may be entered only when supported by a current formal appointment/delegation or other competent, effective-dated instrument, with:
- person's canonical D09 identity reference;
- appointed role/position and owning domain;
- appointing authority and instrument/resolution reference;
- effective-from/effective-until dates and status;
- tenant and, where relevant, legal-entity scope.

A GitHub author, code owner, PR reviewer, model property, generic job title, organization assignment or access-control role is not proof of appointment.

### 2.2 Business reference identifiers

The domain IDs (D02 etc.) are stable catalogue identifiers. Existing table/model names and database primary keys are implementation identifiers; they are **not automatically stable cross-domain business reference IDs**.

The candidate namespaces in §3 are proposed canonical reference namespaces. They become stable only after the producer domain approves the identifier's meaning, uniqueness scope, immutability, lifecycle, tenant/entity scope, and resolution/retirement behavior. Until then, consumers must use the source's actual identifier only within its documented implementation boundary and must not fabricate a cross-domain reference.

### 2.3 Proposed contract identifiers and versions

Each candidate contract is marked **PROPOSED / NOT APPROVED** at version **0.1.0-proposed**. The version is a proposal label, not an existing interface version or release. It must not be treated as semver compatibility evidence.

Approval requires source-domain custodian and D01 consumer-owner review, a schema and lifecycle definition, authorization and tenant/entity rules, effective-date semantics, deprecation policy, and positive/negative/failure test evidence bound to an exact implementation commit.

### 2.4 Evidence location conventions

- **Existing test source** means a test file was located in the source inventory. Its presence is not a test pass; no tests/builds/CI were run for this A2 review.
- **Required test location (proposed)** identifies a future test path or suite to be created/extended only after separate A3 authorization.
- A missing producer has no valid producer-side test location today. The proposed location is a gap marker, not evidence that a test exists.
- All tests must assert tenant isolation, legal-entity membership where applicable, effective-date boundaries, stale/superseded record rejection, authorization, and audit/failure behavior relevant to the contract.

## 3. Proposed reference and contract register

All values in the two proposal columns are candidates only.

| Producer | Catalogue business owner | Named accountable custodian | Stable business reference namespace | Proposed D01 contract ID / version |
|---|---|---|---|---|
| D02 Enterprise Performance Management | D02 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D02-OBJECTIVE`, `D02-KPI`, `D02-TARGET` (PROPOSED; distinct namespaces) | `ARNA-D01-DEP-D02-001` / `0.1.0-proposed` |
| D03 Corporate Governance & Board Management | D03 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D03-RESOLUTION`, `D03-AUTHORITY-GRANT`, `D03-APPOINTMENT` (PROPOSED; do not treat table PKs as business IDs) | `ARNA-D01-DEP-D03-001` / `0.1.0-proposed` |
| D04 Enterprise Risk Management | D04 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D04-RISK`, `D04-ASSESSMENT`, `D04-TREATMENT` (PROPOSED) | `ARNA-D01-DEP-D04-001` / `0.1.0-proposed` |
| D05 Compliance & Internal Control Management | D05 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D05-OBLIGATION`, `D05-CONTROL`, `D05-FINDING` (PROPOSED) | `ARNA-D01-DEP-D05-001` / `0.1.0-proposed` |
| D06 Legal & Regulatory Affairs | D06 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D06-INSTRUMENT`, `D06-OPINION`, `D06-LEGAL-OBLIGATION` (PROPOSED) | `ARNA-D01-DEP-D06-001` / `0.1.0-proposed` |
| D07 Project, Programme & Portfolio Management | D07 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D07-PORTFOLIO`, `D07-INITIATIVE`, `D07-INTAKE` (PROPOSED) | `ARNA-D01-DEP-D07-001` / `0.1.0-proposed` |
| D08 Organization & Structure Management | D08 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D08-ORG-UNIT`, `D08-ASSIGNMENT` (PROPOSED; distinguish unit from dated assignment) | `ARNA-D01-DEP-D08-001` / `0.1.0-proposed` |
| D09 People & Human Capital Management | D09 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D09-PERSON`, `D09-EMPLOYMENT` (PROPOSED; person identity is not authority) | `ARNA-D01-DEP-D09-001` / `0.1.0-proposed` |
| D11 Finance & Accounting Management | D11 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D11-LEGAL-ENTITY`, `D11-BUDGET-REFERENCE`, `D11-ACCOUNTING-PERIOD` (PROPOSED; no budget contract inferred) | `ARNA-D01-DEP-D11-001` / `0.1.0-proposed` |
| D12 Treasury & Cash Management | D12 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D12-CASH-POSITION`, `D12-LIQUIDITY-FORECAST` (PROPOSED) | `ARNA-D01-DEP-D12-001` / `0.1.0-proposed` |
| D20 Documents & Records Management | D20 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D20-RECORD`, `D20-DOCUMENT`, `D20-WORKFLOW` (PROPOSED; formal registration ID must be distinguished from storage key) | `ARNA-D01-DEP-D20-001` / `0.1.0-proposed` |
| D22 Calendar, Events & Scheduling Management | D22 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D22-CALENDAR`, `D22-EVENT` (PROPOSED; calendar event is not a planning-cycle record) | `ARNA-D01-DEP-D22-001` / `0.1.0-proposed` |
| D23 Enterprise Reporting & Business Intelligence | D23 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D23-DATASET`, `D23-SEMANTIC-MODEL`, `D23-REPORT` (PROPOSED; KPI definition remains D02-owned) | `ARNA-D01-DEP-D23-001` / `0.1.0-proposed` |
| D24 Information Technology & Digital Systems Management | D24 | NOT ESTABLISHED — technical custodian and business approver must be separately appointed | `D24-SYSTEM`, `D24-SERVICE`, `D24-RELEASE-EVIDENCE` (PROPOSED) | `ARNA-D01-DEP-D24-001` / `0.1.0-proposed` |
| D25 Investment & Capital Management | D25 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D25-INVESTMENT-PROPOSAL`, `D25-INVESTMENT-DECISION`, `D25-CAPITAL-COMMITMENT` (PROPOSED) | `ARNA-D01-DEP-D25-001` / `0.1.0-proposed` |
| D26 Business Development & Strategic Partnerships | D26 | NOT ESTABLISHED — current appointment/delegation not evidenced | `D26-OPPORTUNITY`, `D26-PARTNERSHIP`, `D26-AGREEMENT` (PROPOSED) | `ARNA-D01-DEP-D26-001` / `0.1.0-proposed` |

## 4. Producer-specific source, scope, temporal rules and test-evidence locations

The exact source baseline for all API paths below is API main SHA `6e274c25ecc55b84e026e879d735b77f0e51592f`. Existing source paths are inventory anchors only. Where a source-domain producer was not found in the inspected tree, it is explicitly marked MISSING.

### D02 — Enterprise Performance Management

- **Business authority:** D02 owns authoritative enterprise objectives, objective hierarchy, KPI definitions, targets, scorecards, performance periods and performance accountability. D01 may retain strategy-to-objective mapping only. This prevents a competing D01 `StrategicObjective` system of record.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D02 domain root or accepted objective/KPI producer contract was located in the inspected API baseline.
- **Reference/contract:** `D02-OBJECTIVE` / `D02-KPI` / `D02-TARGET`; `ARNA-D01-DEP-D02-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant is mandatory. Legal entity is mandatory for entity-specific measures; group consolidation must be an explicit, authorized aggregation scope. Tenant ID is not a legal-entity ID.
- **Effective/supersession:** D02 owns objective, KPI, target and scorecard valid periods and review periods. A superseded definition/target remains historically addressable and must not be silently overwritten. D01 strategy-version validity is a separate interval.
- **Positive evidence location:** Required future producer suite `tests/Feature/PerformanceManagementObjectiveContractTest.php` (PROPOSED); consumer mapping evidence `tests/Feature/D01ObjectiveMappingContractTest.php` (PROPOSED). Assert a D02-issued objective/KPI/target reference is accepted only for matching tenant, approved version and valid period.
- **Negative evidence location:** Same proposed suite. Reject D01-authored duplicate objective, orphan/cyclic hierarchy, stale/superseded target, cross-tenant parent and objective owner inferred solely from D08 assignment.
- **Failure evidence location:** Same proposed suite. Simulate absent D02 producer, unknown reference, schema/version mismatch and stale response; D01 mapping becomes UNAVAILABLE and never fabricates a local objective.
- **Current status:** Producer and test evidence MISSING; ownership rule ESTABLISHED by catalogue; interface NOT ESTABLISHED.

### D03 — Corporate Governance & Board Management

- **Business authority:** D03 owns bodies, mandates, positions, terms, appointments, memberships, delegations, authority, meetings, decisions and resolutions.
- **Named custodian:** NOT ESTABLISHED; model existence does not establish current appointed custodian.
- **Current source anchors:** `mases_core/src/Domains/Governance/Models/GovernanceBody.php`; `GovernanceAppointment.php`; `GovernanceAuthorityGrant.php`; related governance routes/provider/config. Verified tables include `governance_bodies`, `governance_appointments`, `governance_authority_grants` and related governance tables. These are implementation names, not accepted D01 contract IDs.
- **Reference/contract:** `D03-RESOLUTION` / `D03-AUTHORITY-GRANT` / `D03-APPOINTMENT`; `ARNA-D01-DEP-D03-001@0.1.0-proposed`.
- **Tenant/entity:** Governance records carry tenant scope. A resolution's legal-entity or group scope must be read from the authoritative instrument and validated; no cross-tenant authority is implied.
- **Effective/supersession:** Body `effective_from/effective_until`; appointment `effective_date` plus lifecycle status; authority grant `effective_from/effective_until`. Resolve authority and appointment as of the decision timestamp; revoked, expired or superseded grants cannot authorize later decisions.
- **Positive evidence locations:** Existing source tests `mases_core/src/Domains/Governance/Tests/Feature/GovernanceBodyTest.php`, `GovernanceAppointmentTest.php`, `GovernanceAuthorityGrantTest.php`; D01 consumer suite `tests/Feature/D01GovernanceAuthorityContractTest.php` (PROPOSED). Verify competent body, current appointment, grant and resolution all match the decision date and tenant.
- **Negative evidence locations:** Existing governance feature tests should be extended; proposed D01 consumer suite rejects expired/revoked appointment, insufficient grant, wrong body, wrong tenant/entity and role-name-only authority.
- **Failure evidence locations:** Proposed D01 consumer suite. Missing resolution, conflicting instruments, unavailable governance service or ambiguous effective interval must block adopted-strategy status and return UNRESOLVED/UNAVAILABLE with auditable reason.
- **Current status:** Governance source and test files exist; D01 accepted contract, resolution evidence and named custodian NOT ESTABLISHED. Existing tests NOT RUN.

### D04 — Enterprise Risk Management

- **Business authority:** D04 owns enterprise risk register, risk appetite/tolerance, assessment and treatment.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D04 producer root or accepted risk-register contract located in the inspected API baseline.
- **Reference/contract:** `D04-RISK` / `D04-ASSESSMENT` / `D04-TREATMENT`; `ARNA-D01-DEP-D04-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant mandatory; legal-entity applicability and any group-level aggregation explicitly declared.
- **Effective/supersession:** Risk appetite, assessment, response and treatment have separate effective/review dates. Expired assessment or superseded appetite cannot be represented as current.
- **Positive evidence location:** Required future `tests/Feature/EnterpriseRiskD01ReferenceContractTest.php` (PROPOSED): valid D04 risk reference, owner, assessment date, appetite and treatment accepted.
- **Negative evidence location:** Same proposed suite: reject duplicate D01 risk, stale assessment, invalid tenant/entity, unowned risk and out-of-tolerance risk without authorized response.
- **Failure evidence location:** Same proposed suite: D04 outage, missing risk ID, stale feed or schema mismatch produces unresolved risk posture; no silent fallback.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D05 — Compliance & Internal Control Management

- **Business authority:** D05 owns obligations registers, controls, compliance monitoring, attestations, findings and assurance.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D05 producer root or accepted obligations/control contract located.
- **Reference/contract:** `D05-OBLIGATION` / `D05-CONTROL` / `D05-FINDING`; `ARNA-D01-DEP-D05-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant, jurisdiction and affected legal entity must be explicit. A tenant is not proof of which legal entity is subject to an obligation.
- **Effective/supersession:** Obligation applicability dates, control operating periods, finding dates, remediation deadlines and closure dates are distinct. Superseded obligation versions remain traceable.
- **Positive evidence location:** Required future `tests/Feature/ComplianceObligationD01ContractTest.php` (PROPOSED): applicable D05 obligation and current control evidence accepted for the correct entity and period.
- **Negative evidence location:** Same proposed suite: reject expired/inapplicable obligation, unsupported attestation, wrong entity and finding incorrectly marked closed.
- **Failure evidence location:** Same proposed suite: unavailable D05 register, missing control evidence or stale assurance status leaves compliance state unresolved; never infer compliance.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D06 — Legal & Regulatory Affairs

- **Business authority:** D06 owns legal interpretation, opinions, statutory obligations, legal matters and legal instruments. D20 may custody records but cannot determine legal applicability.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D06 legal-opinion/instrument producer or accepted applicability contract located in the inspected API baseline.
- **Reference/contract:** `D06-INSTRUMENT` / `D06-OPINION` / `D06-LEGAL-OBLIGATION`; `ARNA-D01-DEP-D06-001@0.1.0-proposed`.
- **Tenant/entity:** Jurisdiction, affected legal entity and tenant scope required. Applicability must be assessed for the exact entity and date.
- **Effective/supersession:** Preserve issue, publication, effective, expiry, amendment, repeal and transition dates separately. Supersession must identify the replacing instrument and affected provisions. Upload/registration date is not legal effect.
- **Positive evidence location:** Required future `tests/Feature/LegalApplicabilityD01ContractTest.php` (PROPOSED): D06-validated instrument/opinion applies to the specified entity and decision date.
- **Negative evidence location:** Same proposed suite: reject draft, repealed, expired, wrong-jurisdiction or wrong-entity instrument and legal conclusion inferred from document metadata alone.
- **Failure evidence location:** Same proposed suite: conflicting opinions, missing certified instrument, unknown amendment chain or unavailable D06 service yields UNRESOLVED and blocks legal/compliance claims.
- **Current status:** Producer missing; legal instrument identity and applicability remain UNRESOLVED; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D07 — Project, Programme & Portfolio Management

- **Business authority:** D07 owns project/programme/portfolio intake and delivery lifecycle. D01 supplies an approved strategic priority; it does not approve project delivery.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D07 portfolio/intake producer or accepted contract located in the inspected API baseline.
- **Reference/contract:** `D07-PORTFOLIO` / `D07-INITIATIVE` / `D07-INTAKE`; `ARNA-D01-DEP-D07-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant and receiving legal entity required; multi-entity portfolio scope must be explicit and authorized.
- **Effective/supersession:** Intake submission, acceptance, stage-gate decision, start, suspension, closure and cancellation timestamps/statuses must be separately retained. A later gate decision supersedes only the applicable stage state, not the historical record.
- **Positive evidence location:** Required future `tests/Feature/PortfolioIntakeD01ContractTest.php` (PROPOSED): accepted D07 intake references an approved D01 priority and D02 objective.
- **Negative evidence location:** Same proposed suite: reject unapproved priority, duplicate intake, missing sponsor, bypassed stage gate and strategy priority treated as capital approval.
- **Failure evidence location:** Same proposed suite: missing D07 service, withdrawn/rejected intake or stale stage-gate state means initiative is not shown as active delivery.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D08 — Organization & Structure Management

- **Business authority:** D08 owns organizational units, hierarchy, taxonomy and effective-dated structural assignments.
- **Named custodian:** NOT ESTABLISHED.
- **Current source anchors:** `OrganizationUnit.php` and `OrganizationAssignment.php`; tables `organization_units` and `organization_assignments`.
- **Reference/contract:** `D08-ORG-UNIT` / `D08-ASSIGNMENT`; `ARNA-D01-DEP-D08-001@0.1.0-proposed`.
- **Tenant/entity:** Both source records carry tenant scope; organization unit carries `legal_entity_id`. Validate assignment's unit belongs to the same tenant and applicable entity.
- **Effective/supersession:** Assignment uses `starts_on/ends_on`; resolve the hierarchy and accountable organizational assignment as of the relevant date. A changed assignment supersedes the current assignment prospectively; retain history.
- **Positive evidence locations:** Existing `tests/Feature/OrganizationDomainTest.php` and `tests/Feature/OrganizationHierarchyMySqlConcurrencyTest.php`; proposed D01 suite `tests/Feature/D01OrganizationResponsibilityReferenceTest.php`. Verify effective-dated unit/assignment resolution.
- **Negative evidence locations:** Existing organization suites to extend; proposed D01 suite rejects expired assignment, cross-tenant unit, wrong legal entity and use of assignment as governance authority.
- **Failure evidence locations:** Proposed D01 suite: missing unit, overlapping conflicting assignment, unavailable hierarchy or invalid entity relationship returns unresolved owner mapping.
- **Current status:** Organization source and test source exist; no accepted D01 mapping contract; tests NOT RUN.

### D09 — People & Human Capital Management

- **Business authority:** D09 owns canonical natural-person profile and workforce lifecycle. D03 appointment/authority remains the source of governance office/decision rights.
- **Named custodian:** NOT ESTABLISHED.
- **Current source anchors:** `Person.php` (`people`) and `EmployeeRecord.php` (`employees`); tenant access relationships are separate.
- **Reference/contract:** `D09-PERSON` / `D09-EMPLOYMENT`; `ARNA-D01-DEP-D09-001@0.1.0-proposed`.
- **Tenant/entity:** Person identity is distinct from tenant access and employment. Validate active tenant relationship and employment/legal-entity assignment; do not expose personal data beyond the minimum needed.
- **Effective/supersession:** Employment commencement, suspension, termination and rehire periods are D09-owned. Person record creation date is not employment start or governance appointment date.
- **Positive evidence locations:** Existing `tests/Feature/PersonApiIntegrationTest.php` and `tests/Feature/PersonIdentityContractTest.php`; proposed `tests/Feature/D01AccountablePersonReferenceTest.php`. Verify a valid person/employment reference without inferring D03 appointment.
- **Negative evidence locations:** Existing person suites to extend; proposed D01 suite rejects inactive employment, wrong tenant, duplicate identity and person-only evidence offered as proof of appointed authority.
- **Failure evidence locations:** Proposed D01 suite: missing person/tenant link, unavailable D09 source or ambiguous employment dates makes owner identity unavailable; do not select another person silently.
- **Current status:** People source and test source exist; no D01 accountability contract or current appointment evidence; tests NOT RUN.

### D11 — Finance & Accounting Management

- **Business authority:** D11 owns accounting books, chart of accounts, journals, periods and consolidation. D01 strategy priority is not budget appropriation or spending authority.
- **Named custodian:** NOT ESTABLISHED.
- **Current source anchors:** `LegalEntity.php` (`legal_entities`), `AccountingPeriod.php` (`accounting_periods`), and Finance accounting-domain contract documentation. Existing accounting period carries `tenant_id`, `legal_entity_id`, `starts_on`, `ends_on`.
- **Reference/contract:** `D11-LEGAL-ENTITY` / `D11-BUDGET-REFERENCE` / `D11-ACCOUNTING-PERIOD`; `ARNA-D01-DEP-D11-001@0.1.0-proposed`. Budget reference is only a candidate; no accepted D01 budget interface was located.
- **Tenant/entity:** Both tenant and exact legal entity required; validate entity belongs to tenant. Currency and accounting period must be explicit. Consolidated scope requires its own authorization and definition.
- **Effective/supersession:** Accounting period uses `starts_on/ends_on`; distinguish open/closed/adjustment periods and budget version validity. A journal entry is not proof of investment approval or available budget.
- **Positive evidence locations:** Existing `tests/Feature/FinanceAccountingDomainTest.php` if present in the branch baseline is NOT CONFIRMED in the inspected test inventory; therefore required future suite is `tests/Feature/D01FinanceBudgetReferenceTest.php` (PROPOSED). Existing verified source anchor only: Finance domain contract and accounting models. Assert entity/period/currency-aligned approved budget reference.
- **Negative evidence locations:** Proposed D01 suite: reject wrong tenant/entity, currency mismatch, closed/inapplicable period, journal treated as budget approval, and unapproved allocation.
- **Failure evidence locations:** Proposed D01 suite: missing budget source, stale period, unbalanced/unavailable accounting reference or consolidation failure returns unfunded/unavailable; no inferred financial approval.
- **Current status:** Finance/accounting source exists; D01 budget contract and dedicated test evidence NOT ESTABLISHED.

### D12 — Treasury & Cash Management

- **Business authority:** D12 owns bank/cash/liquidity operations. D01 may consume an approved liquidity forecast as a planning input; it does not own bank/cash balances.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D12 source root or accepted liquidity/cash contract located in the inspected API baseline.
- **Reference/contract:** `D12-CASH-POSITION` / `D12-LIQUIDITY-FORECAST`; `ARNA-D01-DEP-D12-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant, legal entity, bank/cash account scope and currency mandatory; restricted account details must not be exposed to D01 without least-privilege approval.
- **Effective/supersession:** Cash position is point-in-time with as-of timestamp; forecast has horizon, scenario, issue time and validity. A later forecast supersedes a prior forecast for the overlapping scope but does not rewrite historical actuals.
- **Positive evidence location:** Required future `tests/Feature/TreasuryLiquidityD01ReferenceTest.php` (PROPOSED): authorized, fresh forecast for matching entity/currency/horizon.
- **Negative evidence location:** Same proposed suite: reject stale forecast, wrong entity/currency, unauthorized account scope and forecast misrepresented as realized cash.
- **Failure evidence location:** Same proposed suite: bank feed/source outage or stale snapshot marks liquidity unavailable; no stale-value silent substitution.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D20 — Documents & Records Management

- **Business authority:** D20 owns formal document identity, registration, review, approval, issuance, retention, archival, legal hold and revocation. It records evidence; it does not itself grant D03 authority or D06 legal interpretation.
- **Named custodian:** NOT ESTABLISHED.
- **Current source anchors:** `Document.php` (`documents`), `DocumentWorkflowAggregateService.php`, document workflow aggregate/event/lifecycle source and related authorization tests.
- **Reference/contract:** `D20-RECORD` / `D20-DOCUMENT` / `D20-WORKFLOW`; `ARNA-D01-DEP-D20-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant-scoped records; legal entity, jurisdiction, classification and confidentiality scope must be explicitly carried when relevant. Storage object key is not a formal record ID.
- **Effective/supersession:** Registration, review, approval, issuance, effective date, retention start, archival, hold and revocation are separate lifecycle events. A new version supersedes only under controlled version rules; preserve prior issued record and audit chain.
- **Positive evidence locations:** Existing `tests/Feature/DocumentWorkflowAggregateServiceTest.php` and `tests/Feature/DocumentsWorkflowAuthorizationCatalogueTest.php`; proposed `tests/Feature/D01StrategyEvidenceRecordContractTest.php`. Verify registered, issued, non-revoked record with authority reference and matching tenant/entity.
- **Negative evidence locations:** Existing document suites to extend; proposed D01 suite rejects draft, unapproved, revoked, wrong-tenant, wrong-entity or storage-key-only evidence.
- **Failure evidence locations:** Proposed D01 suite: workflow/audit/storage outage, incomplete lifecycle or legal hold conflict prevents evidence from being marked authoritative.
- **Current status:** Document lifecycle source and test source exist; no accepted D01 strategy record type/contract; tests NOT RUN.

### D22 — Calendar, Events & Scheduling Management

- **Business authority:** D22 owns calendars, events, recurrence, invitations and scheduling. D01 owns planning-cycle semantics; a calendar event is not a planning cycle.
- **Named custodian:** NOT ESTABLISHED.
- **Current source anchors:** `CalendarEvent.php`, calendar core/lifecycle migrations and calendar domain route/tests.
- **Reference/contract:** `D22-CALENDAR` / `D22-EVENT`; `ARNA-D01-DEP-D22-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant-scoped calendar access. Linked resources retain source-domain authorization; legal entity only where event/resource scope requires it.
- **Effective/supersession:** Event start/end, timezone, recurrence, cancellation and rescheduling are D22-owned. Preserve original event and change history; do not infer fiscal, E.C. planning-year or D01 cycle boundaries from calendar dates.
- **Positive evidence locations:** Existing `tests/Feature/CalendarHttpAuthorizationTest.php`, `tests/Feature/CalendarLifecycleTest.php`, App `tests/calendar-workspace-contract.test.mjs` and `tests/calendar-browser-contract.spec.mjs`; proposed `tests/Feature/D01PlanningCalendarReferenceTest.php`. Verify event linkage and explicit calendar/timezone semantics.
- **Negative evidence locations:** Existing calendar suites to extend; proposed D01 suite rejects cross-tenant event links, cancelled/stale events, invalid timezone and event-as-planning-cycle substitution.
- **Failure evidence locations:** Proposed D01 suite: calendar service outage, recurrence expansion failure or ambiguous E.C./G.C. conversion returns scheduling unavailable without altering D01 cycle dates.
- **Current status:** Calendar source and test sources exist; D01 planning-cycle contract NOT ESTABLISHED; tests NOT RUN.

### D23 — Enterprise Reporting & Business Intelligence

- **Business authority:** D23 owns governed analytical datasets, semantic definitions, lineage, models, dashboards and reports. D02 remains authoritative for KPI formula/target meaning.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D23 domain root or accepted certified dataset/semantic contract located in the inspected API baseline. Executive Overview is not a D23 semantic-layer contract.
- **Reference/contract:** `D23-DATASET` / `D23-SEMANTIC-MODEL` / `D23-REPORT`; `ARNA-D01-DEP-D23-001@0.1.0-proposed`.
- **Tenant/entity:** Dataset row-level scope and lineage must preserve tenant and legal-entity filters through every transformation; consolidated views require explicit authority.
- **Effective/supersession:** Record dataset snapshot/as-of time, freshness SLA, transformation version, semantic version and source lineage. A new snapshot supersedes only its stated scope; it cannot rewrite source facts or D02 KPI definition.
- **Positive evidence location:** Required future `tests/Feature/BusinessIntelligenceD01LineageContractTest.php` (PROPOSED), plus App/API executive-overview tests only for their narrow current widget contract. Assert certified dataset, lineage, freshness and D02 KPI reference.
- **Negative evidence location:** Proposed suite rejects uncertified/stale dataset, missing lineage, tenant filter loss, wrong entity and D23 redefining D02 KPI formula.
- **Failure evidence location:** Proposed suite simulates source outage, failed quality check, stale snapshot and semantic version mismatch; report state becomes stale/unavailable, not silently substituted.
- **Current status:** D23 producer contract and test suite MISSING; domain owner ESTABLISHED; existing Executive Overview is not accepted substitute.

### D24 — Information Technology & Digital Systems Management

- **Business authority:** D24 owns enterprise IT/service/system lifecycle and technology governance. Business decision authority remains with each business domain and D03 where governance decisions apply.
- **Named custodian:** NOT ESTABLISHED; no current formal technical custodian appointment/delegation evidenced.
- **Current source anchors:** API/App repository, routes, providers, models, migrations and test/workflow evidence. App Executive Overview route/API contracts and API route/policy sources are technical implementation evidence, not a D01 service lifecycle record.
- **Reference/contract:** `D24-SYSTEM` / `D24-SERVICE` / `D24-RELEASE-EVIDENCE`; `ARNA-D01-DEP-D24-001@0.1.0-proposed`.
- **Tenant/entity:** Repository/branch/environment boundaries are technical scopes; business tenant isolation must be enforced in API/data authorization. Release identity must bind exact repo, commit and environment.
- **Effective/supersession:** Source evidence binds immutable commit SHA, path/blob and workflow run; runtime release effective time and rollback/supersession are distinct. A later branch head does not retroactively change evidence for an earlier SHA.
- **Positive evidence locations:** Existing API `tests/Feature/ExecutiveOverviewAuthorizationTest.php`, `tests/Feature/ExecutiveOverviewRouteTest.php`; App `tests/executive-overview-route-contract.test.mjs`, `tests/executive-overview-security.spec.mjs`; D01 system-evidence suite `tests/Feature/D01TechnicalEvidenceContractTest.php` (PROPOSED). Verify exact SHA, route/permission parity, tenant reset and workflow run linkage.
- **Negative evidence locations:** Existing suites to extend; proposed D01 suite rejects stale workflow run, wrong branch/SHA, frontend-only authorization, missing route registration, tenant cache leakage and privilege inferred from a repo role.
- **Failure evidence locations:** Proposed suite simulates missing/failed/cancelled workflow, CI permission denial, unavailable service and source mismatch; state remains NOT VERIFIED, never PASS.
- **Current status:** Platform implementation/test sources exist; no D01-specific service record/contract or named custodian; test execution NOT RUN.

### D25 — Investment & Capital Management

- **Business authority:** D25 owns investment appraisal, capital proposals, decisions, allocation, commitments and investment approval gates. D01 sets strategic priority only.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D25 source root or accepted investment-decision/commitment contract located in the inspected API baseline.
- **Reference/contract:** `D25-INVESTMENT-PROPOSAL` / `D25-INVESTMENT-DECISION` / `D25-CAPITAL-COMMITMENT`; `ARNA-D01-DEP-D25-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant, investment legal entity, currency, capital source and portfolio scope mandatory. Cross-entity commitments require explicit authority.
- **Effective/supersession:** Appraisal date, decision date, approval validity, allocation, commitment, drawdown and cancellation dates/statuses are distinct. Superseding appraisal does not itself revoke a prior binding commitment.
- **Positive evidence location:** Required future `tests/Feature/InvestmentCapitalD01ReferenceTest.php` (PROPOSED): valid D25 approved decision/commitment linked to D01 priority and correct D11 entity/budget reference.
- **Negative evidence location:** Same proposed suite: reject strategy priority as investment approval, unapproved proposal, expired authority, wrong entity/currency and commitment exceeding approved allocation.
- **Failure evidence location:** Same proposed suite: unavailable D25 approval, stale appraisal or missing D11 funding evidence yields NOT APPROVED/UNAVAILABLE; no implied funding.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

### D26 — Business Development & Strategic Partnerships

- **Business authority:** D26 owns opportunity development and partnership lifecycle. D01 strategic alignment is not counterparty approval or contract execution authority.
- **Named custodian:** NOT ESTABLISHED.
- **Current producer source:** No dedicated D26 opportunity/partnership producer or accepted contract lifecycle contract located in the inspected API baseline.
- **Reference/contract:** `D26-OPPORTUNITY` / `D26-PARTNERSHIP` / `D26-AGREEMENT`; `ARNA-D01-DEP-D26-001@0.1.0-proposed`.
- **Tenant/entity:** Tenant and contracting legal entity required; counterparty identity/jurisdiction and authorized signatory scope explicit.
- **Effective/supersession:** Opportunity stage, due diligence, approval, agreement execution, effective/expiry, renewal, amendment and termination are distinct. A signed amendment supersedes only specified clauses/periods; preserve prior agreement.
- **Positive evidence location:** Required future `tests/Feature/PartnershipD01AlignmentContractTest.php` (PROPOSED): approved opportunity/partnership reference with executed agreement and authorized contracting entity.
- **Negative evidence location:** Same proposed suite: reject unapproved opportunity, unsigned/draft agreement as executed, wrong entity, expired agreement and strategy alignment treated as signing authority.
- **Failure evidence location:** Same proposed suite: missing due diligence, unavailable D26 record, disputed counterparty or missing amendment chain returns unresolved/unavailable; no partnership status inferred.
- **Current status:** Producer and tests MISSING; domain owner ESTABLISHED; interface NOT ESTABLISHED.

## 5. Cross-domain acceptance envelope

Before any proposed contract can be approved, the producer and D01 consumer must agree a common minimum envelope:

- producer domain ID and consumer domain ID;
- stable immutable business reference ID and record type;
- source record version and contract ID/version;
- tenant ID and optional legal-entity ID, with validated tenant/entity relationship;
- effective-from/effective-until, decision/approval timestamp, recorded timestamp and source-issued timestamp as distinct values;
- lifecycle state, supersession/revocation pointer and historical retrieval rule;
- source owner and approval authority references;
- provenance (repository/release or formal document identity/hash);
- freshness and availability semantics;
- authorization scope and minimum disclosure fields;
- idempotency/correlation where events are emitted;
- positive, negative and failure test evidence at the exact contract version.

A consumer must reject unknown versions, invalid tenant/entity relationships, expired/revoked sources, unauthorized scope and malformed references. An unavailable producer must produce an explicit unavailable/unresolved state and auditable reason. No fallback to a D01-local substitute is permitted.

## 6. Consolidated review disposition

| Review dimension | Result |
|---|---|
| Frozen catalogue business owner | ESTABLISHED for all 16 domains |
| Named accountable individual | NOT ESTABLISHED for all 16; no formal current appointment evidence in this technical review |
| Existing implementation source | PARTIAL: D03, D08, D09, D11, D20, D22 and D24 have relevant source anchors; D02, D04, D05, D06, D07, D12, D23, D25 and D26 producer sources not located |
| Existing stable cross-domain business reference IDs | NOT ESTABLISHED for all 16 |
| Proposed reference namespaces | DEFINED — PROPOSED / NOT APPROVED |
| Accepted D01 contract IDs/versions | NONE ESTABLISHED |
| Proposed D01 contract IDs | DEFINED — all `0.1.0-proposed`, NOT APPROVED |
| Tenant/entity rules | DEFINED as acceptance requirements; producer-specific contracts not yet approved |
| Effective/supersession rules | DEFINED as acceptance requirements; producer-specific contract semantics not yet approved |
| Positive/negative/failure test locations | Existing suites identified where available; proposed suites identified for every producer; no test executed |
| Producer-domain custodian review/approval | NOT RECEIVED / NOT EVIDENCED |

### 6.1 Required evidence to upgrade each row

A producer row may advance only when all of the following are attached or independently verified:
1. current formal named custodian appointment/delegation and authority scope;
2. producer-owned stable business reference specification, including uniqueness and lifecycle;
3. approved contract ID/version and schema, jointly accepted by producer and D01 consumer;
4. exact tenant/legal-entity validation rules and effective/supersession semantics;
5. positive, negative and failure test results tied to exact commit/contract version;
6. source lineage and formal record identity where a legal/governance decision is involved.

A code implementation without the formal custodian/authority evidence does not close governance ownership. A proposed identifier or test path does not count as an existing producer artifact.

## 7. Relationship to the four unresolved D01 evidence matters

This review does not resolve:
1. adopted-strategy resolution;
2. competent approval authority and delegation chain;
3. current executive/planning appointments;
4. legal instrument identity, amendments and applicability.

In particular, D03's governance tables are not evidence that a specific strategy resolution exists; D09 person/employment records are not current governance appointments; D20 document custody is not legal validity; and D06 legal applicability remains unverified.

## 8. Change boundary and next A2 action

- Documentation only; no API/App code, migration, schema, seed, permission, configuration or production data changed.
- No test, build, type check, browser test or CI workflow was run.
- API main and App main remain the frozen source baselines cited above; this record is on the A2 evidence documentation branch only.
- No merge, A3 implementation, A4 verification or A5 deployment is authorized by this record.

**Next A2 action:** request/record formal producer-domain review of the proposed custodian, reference namespace, contract and scope semantics. Until producer evidence is available, keep each missing item explicitly NOT ESTABLISHED; do not manufacture source records or downgrade D01's enterprise A2 disposition from UNVERIFIED.
