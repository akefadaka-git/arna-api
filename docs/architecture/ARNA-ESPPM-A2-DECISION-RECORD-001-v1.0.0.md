# ARNA ESPPM A2 Owner Decision Record

- **Document ID:** ARNA-ESPPM-A2-DECISION-RECORD-001
- **Version:** 1.0.0
- **Decision date:** 2026-11-24
- **Status:** OWNER-APPROVED DECISION / A2 PARTIAL — NOT VERIFIED-CLOSED
- **Repository:** `akefadaka-git/arna-api`
- **Base branch:** `main`
- **Base commit:** `40428db0a41d54397dd2aee482c9395f7950e9d0`
- **Change class:** Documentation / governance archival only
- **Related frozen baseline:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0`
- **Related A1 archival PR:** [PR #212](https://github.com/akefadaka-git/arna-api/pull/212) (separate, unmerged A1 archival proposal)
- **Authority:** Explicit Owner approval of `ARNA-ESPPM-A2-DECISION-PACKAGE-001`, recorded in the conversation on 2026-11-24.

## 1. Purpose and decision authority

This record archives the Owner's decisions on the A2 read-only reconciliation package for the ARNA Enterprise Strategy, Planning & Performance Management (ESPPM) architecture.

The Owner decision was:

> **APPROVE** — ARNA-ESPPM-A2-DECISION-PACKAGE-001. Notes: None. Keep A3 unauthorized unless I explicitly authorize it separately.

This approval accepts the governance dispositions and decision framework recorded below. It does **not** convert unresolved evidence into verified facts, close A2, approve any domain implementation, or authorize merge, migration, production transactions, A4 verification or A5 deployment.

This record is documentation-only. It does not alter application code, database schemas, migrations, seeders, configuration, runtime behavior, or the frozen A1 specification.

## 2. Baseline preservation and stage-gate decision

The following controls remain binding:

1. Preserve A1 v1.0 as the historical original 24-domain baseline.
2. Preserve A1 v1.1 / `ARNA-ESPPM-A1-ADD-001` as the historical approved amendment.
3. Preserve A1 v1.2.0 / `ARNA-ESPPM-A1-SPEC-002` as the current frozen 30-domain successor. Do not silently edit or replace it.
4. A2 is read-only reconciliation against the frozen A1 baseline. A2 findings do not amend A1.
5. A3 implementation requires a separate, explicit Owner authorization identifying bounded scope.
6. A4 verification and A5 deployment remain separate authorization gates.
7. No merge to `main`, production migration, production financial transaction, or deployment is authorized by this decision.

## 3. A2 overall disposition

**A2 status: PARTIAL — NOT VERIFIED-CLOSED.**

The A2 inspection and decision package established a consolidated disposition across all 30 domains, proposed accountable roles, contract acceptance criteria, Finance document lineage, and Legal evidence limitations. It did not establish complete accepted domain contracts for all domains or satisfy all evidence and acceptance requirements.

The following terms are used consistently:

- **SUPPORTED:** Relevant contract/evidence exists in the inspected scope; this is not production verification.
- **PARTIAL:** Some relevant foundations exist, but material scope, authority, acceptance, reconciliation or evidence gaps remain.
- **UNVERIFIED:** The inspected evidence is insufficient to establish the domain contract or disposition; this is not a finding that the domain is unnecessary.
- **MISSING:** No suitable domain contract or implementation evidence was found in the inspected repository scope; this is not proof that no work exists elsewhere.

### 3.1 Domain disposition register

| ID | Domain | A2 disposition | Reconciliation finding |
|---|---|---|---|
| D01 | Corporate Strategy & Enterprise Planning | UNVERIFIED | No dedicated accepted strategy/planning contract established. |
| D02 | Enterprise Performance Management | PARTIAL | Executive Overview work does not establish the complete KPI, target, scorecard and performance-review domain. |
| D03 | Corporate Governance & Board Management | PARTIAL | Governance authority and election foundations exist; current-main reconciliation remains open. |
| D04 | Enterprise Risk Management | UNVERIFIED | Risk references exist in draft policy material; no accepted Risk domain contract established. |
| D05 | Compliance & Internal Control Management | UNVERIFIED | Draft control-policy material is not an adopted, complete domain contract. |
| D06 | Legal & Regulatory Affairs | PARTIAL | Election-specific legal evidence register exists; it is not a complete Legal Affairs contract. |
| D07 | Project, Programme & Portfolio Management | UNVERIFIED | No accepted delivery portfolio contract established. |
| D08 | Organization & Structure Management | SUPPORTED | Owner-approved Organization contract exists; implementation PR still requires current-main reconciliation. |
| D09 | People & Human Capital Management | PARTIAL | Canonical Person/identity foundations exist; full HR/workforce contract remains incomplete. |
| D10 | Shareholder & Ownership Management | PARTIAL | Register/statutory implementation work exists; legal-source authority and completeness remain unresolved. |
| D11 | Finance & Accounting Management | PARTIAL | A3 baseline asserts A1/A2 approval, but current Finance contracts contain conflicting approval statuses and boundaries. |
| D12 | Treasury & Cash Management | MISSING | No independent Treasury contract found; Wallet scope excludes bank operations, payment rails and custody. |
| D13 | Loan & Credit Management | PARTIAL | Substantial implementation and product-policy evidence exists; full domain acceptance is not established. |
| D14 | Wallet & Digital Financial Services | PARTIAL | Wallet ledger implementation exists; Treasury boundary and complete acceptance remain open. |
| D15 | Procurement & Supplier Management | MISSING | Procurement appears in draft/strategy material; no accepted domain contract found. |
| D16 | Inventory & Supply Chain Management | MISSING | No dedicated contract or implementation branch established in the inspected scope. |
| D17 | Sales, Marketing & Customer Relationship Management | MISSING | Planning descriptions exist; no accepted domain contract found. |
| D18 | Operations & Service Delivery Management | UNVERIFIED | Phase-planning references exist; no dedicated accepted contract established. |
| D19 | Enterprise Asset & Facilities Management | MISSING | No dedicated contract or implementation branch found in the inspected scope. |
| D20 | Documents & Records Management | PARTIAL | Registry/numbering implementation exists; formal lifecycle contract acceptance remains unresolved. |
| D21 | Communication & Stakeholder Engagement | PARTIAL | Announcement workflow exists; the broader communication hub remains incomplete. |
| D22 | Calendar, Events & Scheduling Management | SUPPORTED | Owning-domain authorization and reminder-outbox contract exists; integration is merged. |
| D23 | Enterprise Reporting & Business Intelligence | PARTIAL | Finance reporting and Executive Overview exist; enterprise BI governance remains incomplete. |
| D24 | Information Technology & Digital Systems Management | UNVERIFIED | Platform evidence exists; IT service management versus shared platform-operations boundary remains unresolved. |
| D25 | Investment & Capital Management | MISSING | No dedicated domain contract found. |
| D26 | Business Development & Strategic Partnerships | MISSING | No partnership lifecycle contract found; public-site inquiry intake is insufficient. |
| D27 | Corporate Development, Mergers & Acquisitions | MISSING | No contract or implementation evidence found. |
| D28 | Product, Service & Innovation Management | MISSING | No dedicated lifecycle contract found. |
| D29 | Grants, Funding & Financing Management | MISSING | No funding lifecycle contract found; governance role grants are unrelated. |
| D30 | Enterprise Investment Asset Portfolio | MISSING | No investment holdings/portfolio contract found. |

## 4. Finance A2 decision

### 4.1 Approved document-lineage disposition

The Owner-approved A2 governance disposition is:

- Designate `ARNA-FIN-CONTRACT-001 v0.1.0`, file `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md`, as the **single candidate Finance accounting contract for formal Owner review**.
- Retain `mases_core/src/Domains/Finance/Documentation/financial-accounting-domain-contract.md` as supporting GL implementation and acceptance material, not as a competing authoritative contract.
- Do not describe either document as Owner-approved until approval is explicitly recorded against its exact version and content SHA.
- Retain `docs/finance/ARNA-FINANCE-A3-IMPLEMENTATION-BASELINE.md` and PR #195 as implementation-lineage evidence, not as primary proof of Finance A1/A2 approval.
- Finance remains **PARTIAL** in A2 until contract version, approval authority, legal-entity/accounting-policy decisions and source-domain boundaries are reconciled.

### 4.2 Evidence references and qualifications

- Finance A3 implementation baseline blob recorded during A2 inspection: `1d66249eb813d785af50ae36f4844a9818f21ff5`.
- Supporting GL contract blob: `945be5238b452806e60cb4e42f1b9eec3e143801`. It states that it is an initial implementation contract for Owner review, not production acceptance.
- Candidate Finance contract blob: `a28040d3926973004675aaf5bbe0760cd5b4565a`. It is `ARNA-FIN-CONTRACT-001 v0.1.0`, proposed for an Owner decision and not an implementation or production-release authorization.
- [PR #195](https://github.com/akefadaka-git/arna-api/pull/195) is an integration candidate. Its recorded head at the time of inspection was `463a1386487283a42a325fe1c54c641d4747073a`; the historical report recorded 24/26 workflow runs passed, with two cancelled before job creation and no failed run. This is historical evidence, not a current CI assertion. Recheck the PR and exact SHA before relying on its status.

Known implementation limitations recorded in the A2 package include no foreign-currency journal posting, no registered external subledger adapters, unresolved evidence-binary orphan cleanup, and outstanding independent accounting/security review and backup/restore drills.

### 4.3 Finance ownership boundaries

- D11 Finance owns the general ledger, chart of accounts, accounting periods, journal posting, trial balance and financial reporting.
- D13 Loan and D14 Wallet remain operational subledgers and own their operational records.
- Other operational domains own their business transactions and publish controlled, idempotent accounting events.
- Posting, reversals, period controls, money arithmetic and ledger transitions must be deterministic.
- Tenant and legal-entity authority must be resolved server-side.
- ETB is the default currency only where approved for the relevant entity; foreign-currency posting and consolidation require explicit policy.
- AI may support analysis and proposals; it is not authorized to autonomously post, reverse, pay or close accounting periods.

## 5. Legal evidence disposition

### 5.1 Evidence artifacts

The A2 package recorded the following election-specific evidence branch:

- Branch: `docs/p2.5-legal-evidence-a2`
- Commit: `376f697001d215559bd68e9ceb17c51848e50b42`
- Register blob: `cfbf958cb09589878522880d7d358da0fcb636c4`
- Source-review addendum blob: `e49f80af227ed49667e5f9492c2d3f9ade60478b`
- [Legal evidence register](https://github.com/akefadaka-git/arna-api/blob/docs/p2.5-legal-evidence-a2/docs/governance/elections/P2.5-LEGAL-EVIDENCE-A2-REGISTER-v1.0.md)
- [Source review addendum](https://github.com/akefadaka-git/arna-api/blob/docs/p2.5-legal-evidence-a2/docs/governance/elections/P2.5-LEGAL-EVIDENCE-A2-SOURCE-REVIEW-v1.0.md)

The record includes Owner-supplied provenance for a 17-page bylaws scan and supplied-byte SHA-256 `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`.

### 5.2 Legal matter register

| Matter | Disposition | Remaining requirement |
|---|---|---|
| LEG-01 Operative Memorandum & Articles, including amendments | PARTIAL | Certified operative copy, amendments, issuing authority and current legal effect. |
| LEG-02 Share-class rights, voting weight/caps | UNRESOLVED | Certified clauses and legal interpretation reconciled to shareholder register and election rules. |
| LEG-03 Proxy scope, submission, revocation and replacement | PARTIAL | Operative clause, submission evidence, deadline and decision-maker. |
| LEG-04 Candidate qualification/disqualification | PARTIAL | Certified clause, statutory basis, evidence standard and consistent procedure. |
| LEG-05 Objection/appeal authority and procedure | UNRESOLVED | Governing provision, competent authority, deadlines, recusal and review route. |
| LEG-06 Legal Advisor authority, independence and recusal | UNRESOLVED | Appointment/engagement authority, scope, conflict declarations, recusal and reporting line. |

Accepted for evidence tracking: existence of the register and addendum, recorded provenance and supplied-scan hash, and classification of the six matters and gaps.

Not established as legal fact: that the scan is certified, current or operative; unverified interpretations of share rights, vote caps, proxy rules, candidate disqualification or appeal authority; or the Legal Advisor's independent authority and conflict-free mandate.

**Fail-closed rule:** Do not assume voting eligibility, weighting, candidate disqualification or appeal authority while the relevant legal questions remain unresolved. The election-specific register is not a complete D06 Legal Affairs contract.

## 6. Accountable-owner framework

The Owner approved the role-based accountable-owner framework in principle. The roles below are proposed domain-accountability roles, not evidence that named individuals have been appointed. Named role-holders and deputies remain to be appointed and recorded by the Owner.

| Domain | Proposed accountable role |
|---|---|
| D01 | Chief Executive / Strategy Executive |
| D02 | Strategy & Performance Executive |
| D03 | Board Secretary / Governance Executive |
| D04 | Chief Risk Officer / Risk Executive |
| D05 | Compliance Officer / Internal Control Executive |
| D06 | General Counsel / Legal Affairs Lead |
| D07 | PMO Director / Portfolio Executive |
| D08 | Organization / Corporate Services Executive |
| D09 | HR / People Executive |
| D10 | Company Secretary / Shareholder Affairs Lead |
| D11 | CFO / Finance Executive |
| D12 | Treasurer / Treasury Executive |
| D13 | Credit / Lending Executive |
| D14 | Digital Financial Services Executive |
| D15 | Procurement Executive |
| D16 | Supply Chain / Inventory Executive |
| D17 | Commercial / Sales & Marketing Executive |
| D18 | Chief Operating Officer / Operations Executive |
| D19 | Asset & Facilities Manager / Executive |
| D20 | Records Management / Company Secretariat Lead |
| D21 | Corporate Communications Executive |
| D22 | Corporate Secretariat / Calendar Product Owner |
| D23 | Enterprise Data / BI Executive |
| D24 | CIO / IT Services Executive |
| D25 | Investment / Capital Allocation Executive |
| D26 | Business Development / Partnerships Executive |
| D27 | Corporate Development Executive |
| D28 | Chief Product / Innovation Executive |
| D29 | Funding / Grants Executive |
| D30 | Investment Portfolio / Asset Management Executive |

Priority classification approved in the package:

- **P1:** Foundational or critical authority, financial, operational and control domains — D01–D06, D09–D12, D15–D18 and D23–D25.
- **P2:** Remaining contract-completion and maintenance domains.

This priority classification does not waive any domain's contract, legal, security or acceptance requirements.

## 7. Domain contract acceptance register

Every domain contract must satisfy the common requirements below. The domain-specific acceptance focus is an additional minimum, not a substitute for the common requirements.

### 7.1 Common mandatory acceptance criteria

1. Explicit authority, accountable owner, document identity, version and approval status.
2. Scope, inclusions, exclusions and cross-domain ownership boundaries.
3. Canonical records and authoritative source of truth.
4. State machines, invariants, permitted transitions and exception handling.
5. Tenant isolation, identity, authorization, delegation and segregation of duties.
6. Auditability, evidence, retention, privacy and legal-hold requirements.
7. API/event contracts, idempotency, retries, consistency and reconciliation behavior.
8. Schema/data ownership, migrations, compatibility, quality and lineage.
9. Amharic-first localization and Ethiopian Calendar support where relevant.
10. Positive, negative, tenant-isolation, concurrency, migration and integration tests against an exact candidate SHA.
11. Operational monitoring, recovery and failure-mode handling appropriate to the domain.
12. Explicit Owner acceptance recorded separately from implementation, merge and deployment authorization.

### 7.2 Domain-specific acceptance focus

| ID | Minimum domain-specific acceptance focus |
|---|---|
| D01 | Approved strategy hierarchy, planning periods, versioning, objectives cascade, ownership and change control. |
| D02 | KPI dictionary, formulas, units, targets, baselines, periods, source lineage, scorecards and review/variance workflow. |
| D03 | Governance-body mandates, membership/terms, quorum, authority/delegation, meeting decisions, resolutions and evidence. |
| D04 | Risk taxonomy, appetite/tolerance, assessment methodology, ownership, treatment, residual risk and escalation. |
| D05 | Obligation register, control framework, control testing/attestation, findings, remediation and assurance evidence. |
| D06 | Legal matter and obligation lifecycle, authoritative source provenance, advice/review authority, conflicts, deadlines and legal evidence. |
| D07 | Portfolio/programme/project lifecycle, business case, stage gates, scope/schedule/dependencies, delivery risks and benefits. |
| D08 | Organizational units, hierarchy, effective-dated assignments, legal-entity boundaries and separation from application permissions. |
| D09 | Canonical person and workforce records, employment/engagement lifecycle, privacy, access separation and effective-dated history. |
| D10 | Statutory register, identity types, share classes/holdings, transfers, certificates, entitlement calculations and legal-source traceability. |
| D11 | CoA, legal-entity books, journal validation/posting/reversal, periods, trial balance, statements and deterministic accounting. |
| D12 | Bank/cash account authority, liquidity position/forecast, transfers, reconciliation, treasury controls and separation from wallet ledger. |
| D13 | Credit policy, application/assessment/approval, contract, disbursement, schedules, allocation, delinquency, restructuring and loan subledger. |
| D14 | Wallet identity/ownership, ledger invariants, balances, payment lifecycle, idempotency, reversals, external-rail reconciliation and controls. |
| D15 | Supplier lifecycle, sourcing, requisition, evaluation, approval, purchase order, contract and segregation of duties. |
| D16 | Item/warehouse master, receipt/issue/transfer/reservation/count, stock invariants, replenishment and valuation inputs. |
| D17 | Customer/lead/opportunity lifecycle, consent/privacy, campaign, quotation, order and handoff to fulfilment. |
| D18 | Service/work order lifecycle, allocation, execution, SLA measurement, incident handling, fulfilment and completion evidence. |
| D19 | Operational asset register, custody/location, maintenance, inspection, condition, utilization and disposal. |
| D20 | Record identity/classification, numbering, registration, approval, issuance, retention, archival, legal hold and revocation. |
| D21 | Stakeholder identity/segments, communication plans, content approval, publication, delivery evidence, feedback and accountability. |
| D22 | Event/calendar lifecycle, recurrence, invitations, responses, scheduling, reminders and owning-domain authorization of linked resources. |
| D23 | Governed datasets, semantic definitions, lineage, quality, access controls, dashboard/report lifecycle and reproducibility. |
| D24 | IT service/system inventory, ownership, service lifecycle, incidents/requests, change, availability, support and platform boundary. |
| D25 | Investment pipeline, appraisal methodology, capital proposal, approval gates, commitments, allocation and performance expectations. |
| D26 | Partner/opportunity pipeline, due diligence, evaluation, relationship governance, agreement lifecycle and outcome measures. |
| D27 | Transaction pipeline, valuation/due-diligence coordination, approval gates, legal/financial controls and integration/separation planning. |
| D28 | Product/service portfolio, discovery, requirements, innovation pipeline, roadmap, release decision and lifecycle performance hypotheses. |
| D29 | Grant/funding opportunity, application, agreement, restrictions/conditions, milestones, disbursement evidence and donor/lender reporting. |
| D30 | Investment holding register, asset identity, valuation inputs, performance/risk monitoring, portfolio reporting and exit/disposal. |

## 8. Cross-domain boundary controls

The following separation decisions remain part of the frozen A1 architecture and are reaffirmed as the A2 reconciliation standard:

- D01 strategy; D02 objectives/KPI definitions/targets/reviews; D23 governed analytics/reporting.
- D03 governance bodies/appointments/delegations; D08 organizational units/hierarchy/assignments; D09 canonical people. None implies application authorization.
- D04 risk; D05 compliance/internal-control assurance; D06 legal interpretation/advice/matters.
- D07 delivery portfolio; D25 investment appraisal/capital deployment.
- D11 accounting; D12 liquidity/cash/bank operations; D13 Loan subledger; D14 Wallet/payment ledger.
- D15 procurement; D16 inventory/supply chain; D11 accounting.
- D17 customer/commercial lifecycle; D28 product/service definition/innovation; D18 fulfilment/operations.
- D19 operational assets/facilities; D25 investment decisions; D30 investment holdings/portfolio.
- D20 formal records lifecycle; shared file storage is binary persistence/transport.
- D21 communication workflows; notification delivery is platform infrastructure.
- D22 Calendar owns event lifecycle; linked resources remain authorized by their owning domains.
- D26 partnerships; D27 M&A/corporate transactions; D29 grants/funding arrangements.

Cross-cutting platform capabilities remain shared infrastructure rather than additional business domains: identity/authentication, tenancy, authorization, audit/security, notifications, integration/API/event infrastructure, data governance/quality/lineage, cybersecurity/privacy/secrets, workflow/automation, binary file storage, localization/accessibility (including Amharic and Ethiopian Calendar), backup/disaster recovery, observability and platform operations.

## 9. Owner decision register

| Decision | Owner disposition | Effect |
|---|---|---|
| A1 v1.2.0 frozen 30-domain successor and historical preservation | APPROVED previously | Preserve v1.0 and v1.1; v1.2.0 remains frozen A1. |
| A2 decision package ARNA-ESPPM-A2-DECISION-PACKAGE-001 | APPROVED, 2026-11-24 | Accept governance dispositions and criteria recorded here; A2 remains PARTIAL. |
| Finance candidate contract lineage | APPROVED as governance disposition | One candidate for formal Owner review; no claim of Finance contract approval. |
| Legal evidence register/provenance | ACCEPTED for evidence tracking only | Unresolved legal matters remain PARTIAL/UNRESOLVED and fail-closed. |
| Role-based accountable-owner framework | APPROVED IN PRINCIPLE | Named appointments and deputies remain outstanding. |
| Domain contract sponsorship and acceptance requirements | APPROVED | Apply common and domain-specific criteria before domain acceptance. |
| A3 implementation | NOT AUTHORIZED | Requires separate explicit Owner authorization. |
| Merge to main | NOT AUTHORIZED by this record | Separate authorization required. |
| Production migration/financial transaction | NOT AUTHORIZED by this record | Separate authorization and applicable controls required. |
| A4 verification | NOT STARTED / NOT AUTHORIZED | Separate gate after implementation. |
| A5 deployment | NOT AUTHORIZED | Separate deployment approval required. |

## 10. Archival and change-control statement

This artifact records the Owner's approval of the A2 decision package. It is not a new A1 baseline, does not supersede A1 v1.0, v1.1 or v1.2.0, and does not silently amend any domain contract.

This file is proposed for archival through a documentation-only branch and pull request. The pull request must remain unmerged unless a separate merge authorization is given. Its creation does not imply A2 closure.

Any material correction to this decision record must be made through a controlled, versioned amendment with traceable evidence. Any new implementation activity remains subject to a separate A3 authorization.

## 11. Status snapshot at decision time

| Phase | Status | Completion |
|---|---|---:|
| A1 | VERIFIED-CLOSED | 100% |
| A2 | PARTIAL — NOT VERIFIED-CLOSED | ~85% |
| A3 | NOT AUTHORIZED | 0% |
| A4 | NOT STARTED | 0% |
| A5 | NOT AUTHORIZED | 0% |

**Production readiness:** NOT ASSESSED / NOT CLEARED.

**Immediate next step:** Archive and inspect this documentation-only decision record through its draft pull request. Continue A2 only against the remaining evidence and reconciliation gaps. Do not start A3 without a separate explicit Owner authorization.
