# ARNA D01 — Enterprise Strategy & Planning Domain Contract and Owner Approval

- **Record ID:** ARNA-D01-CONTRACT-001
- **Version:** 1.0.0
- **Record type:** Controlled D01 domain contract and Owner decision record
- **Disposition:** **OWNER-APPROVED — ARCHITECTURE BASELINE**
- **Approval date:** 2026-11-25
- **Approval scope:** Architecture and domain-boundary approval only; subject to the explicit legal and authority exceptions in §10
- **Repository:** `akefadaka-git/arna-api`
- **Branch:** `docs/arna-d01-a2-evidence-register`
- **Parent frozen catalogue:** `ARNA-ESPPM-A1-SPEC-002 v1.2.0` (preserved; not amended)
- **Related D01 A2 evidence:** `ARNA-D01-A2-EVID-REG-001 v1.0.4`
- **Related D01–D02 boundary:** `D01–D02 Objective Ownership Clarification v1.0.0` (PR #222)
- **Related D01 traceability:** `ARNA-D01-A2-TRACE-001 v1.0.8`

## 1. Owner decision record

The Owner has explicitly approved the following decisions for the D01 architecture baseline:

| Decision | Approved value | Contract effect |
|---|---|---|
| Strategy authority model | **CONFIRM** | GM → Board → CEO → D01 coordination model, subject to applicable law, operative instruments and verified delegations |
| Annual strategic plan and budget | **BOARD** | Board approves the annual strategic plan and associated budget, but only within its lawful authority |
| Major strategic changes | **GM** | GM approval is required for a major change to corporate strategic direction or a matter reserved to the GM |
| Major-change classification | **IMPACT** | Classify by strategic impact, reserved authority, risk and materiality; numerical thresholds belong in a separate controlled policy |
| Contract disposition | **APPROVE** | Approve this D01 contract v1.0.0 as an architecture baseline, subject to explicit legal exceptions |

This is an Owner-approved architecture decision. It is **not** a legal opinion, proof of statutory competence, confirmation that any particular bylaw clause is operative, or evidence that the required corporate resolutions and appointments already exist.

## 2. Purpose and mandate

D01 — Enterprise Strategy & Planning — is the enterprise coordination domain responsible for maintaining the controlled corporate strategy and planning framework. It translates strategic direction approved by the competent corporate organ into versioned strategy records, planning cycles, strategic themes and priorities, domain-alignment context, coordinated strategic reviews and traceable strategy-change records.

D01 provides a coherent enterprise planning baseline. It does not itself exercise General Meeting or Board powers, execute operational programmes, own enterprise performance measurement, or provide legal determinations.

## 3. Authority and accountability model

The approved architecture model is:

1. **General Meeting of Shareholders (GM):** ultimate corporate authority for strategic direction and approval of multi-year strategy and major changes to corporate strategic direction, to the extent permitted or required by applicable law and the operative corporate instruments.
2. **Board of Directors:** governs and oversees strategy; reviews strategic proposals; and approves the annual strategic plan and associated budget within its lawful and delegated authority.
3. **CEO:** executes approved strategy and the approved annual plan/budget, directs management implementation, and reports performance and material variances through the applicable governance process.
4. **D01:** coordinates strategy formulation and planning, maintains controlled strategy versions and decision/evidence records, coordinates domain alignment and review cycles, and records—not manufactures—corporate approvals.
5. **D02 — Enterprise Performance Management:** owns authoritative enterprise objective records and hierarchy, KPI definitions, formulas, target versions, scorecards, measurement periods and performance accountability/reviews. D01 maintains strategic alignment references to D02 objectives; it must not create a competing enterprise-objective system of record.

A role title, application permission, workflow state, user assertion or system administrator privilege does not independently establish legal corporate authority. Approval must be attributable to a competent organ or properly delegated office-holder and supported by the required decision evidence.

## 4. Strategy approval and change-control rules

### 4.1 Multi-year corporate strategy

The GM approval route is the architecture baseline for adoption of multi-year corporate strategy. D01 coordinates preparation, consultation, impact assessment, version control and publication after the required approval evidence is recorded.

### 4.2 Annual strategic plan and budget

The Board approval route is the architecture baseline for the annual strategic plan and associated budget, **within the Board's lawful authority**. D01 coordinates the plan and records the Board decision. Finance-domain ownership of budget preparation, accounting, treasury and financial controls remains with the relevant finance domains.

If applicable law or the operative registered instruments reserve all or part of the annual budget or plan to the GM, the legal exception must be resolved before the affected approval workflow is treated as legally effective. This contract does not override such a reservation.

### 4.3 Major strategic changes

GM approval is the architecture baseline for a major change to corporate strategic direction or a matter reserved to the GM. The Board may review, recommend and oversee the change, but must not be represented by D01 as having displaced a GM-reserved approval.

Routine execution adjustments and changes within a validly approved delegation are not automatically major strategic changes. Their classification and delegated approval route must be supported by the controlled policy and verified delegation instruments.

### 4.4 Impact-based classification

A proposed change is assessed using the combined impact of:
- effect on corporate purpose, long-term direction, strategic outcomes or approved multi-year commitments;
- whether the matter is reserved by law or operative corporate instrument to the GM or another organ;
- enterprise-wide, cross-domain, legal-entity or stakeholder reach;
- financial, operational, legal, regulatory, reputational and continuity risk;
- materiality, reversibility and effect on approved plans, commitments and risk appetite.

Numerical thresholds, scoring weights, examples and escalation cut-offs must be defined in a separate controlled Strategy Change Classification Policy. They are intentionally **not** embedded as numeric thresholds in this contract. Until that policy is approved, uncertain or potentially reserved changes must be escalated for competent-organ determination rather than silently classified as routine.

### 4.5 Evidence and version control

Each strategy approval or amendment record must identify the strategy/version affected, decision organ, resolution or instrument reference, decision date, effective date, scope, conditions, recorded dissent or conflicts where applicable, delegated execution responsibility, impact assessment and supersession relationship. D01 records and controls the evidence trail; it does not create missing resolutions or retroactively validate an approval.

## 5. D01 capabilities and controlled records

D01 is responsible for:
- enterprise strategy framework and multi-year strategy coordination;
- annual and periodic enterprise planning-cycle coordination;
- strategic themes, priorities and planning assumptions;
- cross-domain strategic alignment and planning-context references;
- strategic initiative identification and coordination, without taking over project/programme execution;
- strategy proposal, review, approval-evidence and amendment coordination;
- strategy versioning, effective dates, supersession and controlled publication;
- periodic strategy review, exception escalation and archival evidence.

Conceptual business records may include Strategy, Strategy Version, Planning Cycle, Strategic Theme, Strategic Priority, Domain Alignment Reference, Strategic Initiative Reference, Strategy Approval, Strategy Amendment, Strategy Review and Strategy Archive. These are conceptual contract entities only; this approval does **not** authorize database tables, migrations, seeders, APIs, events, UI, permissions or runtime changes.

## 6. Domain boundaries and interfaces

| Domain | Authoritative responsibility | D01 relationship |
|---|---|---|
| D01 Enterprise Strategy & Planning | Corporate strategy and planning framework, cycles, themes, priorities, controlled versions and coordination | Owns this contract's scope |
| D02 Enterprise Performance Management | Enterprise objective records/hierarchy, KPI definitions, formulas, targets, scorecards and performance accountability | D01 references and aligns strategy to D02-owned objectives; no duplicate system of record |
| D03 Governance | Governance structures, decision processes and corporate governance records | Supplies authoritative governance decision evidence |
| D06 Legal & Regulatory Affairs | Legal/regulatory analysis, obligations and legal advice | Determines legal issues through competent counsel; D01 does not interpret law |
| D07 Project/Programme/Portfolio Management | Programme/project/portfolio delivery governance and execution | Receives approved strategic initiative context; D01 does not own delivery execution |
| D09 People / HR | Workforce, appointments and people administration | Supplies formally evidenced appointment and accountability records |
| D11 Finance & Accounting | Financial planning/accounting and budget controls | Supports budget formulation and financial control; D01 does not own accounting |
| D12 Treasury | Cash, banking, liquidity and treasury operations | No D01 treasury authority |
| D25 Investment & Capital Management | Investment/capital decisions and related controls | D01 may record strategic alignment only; no capital approval or transaction authority |

No interface contract ID, event schema, API endpoint or named current source owner is approved by this document. Those remain subject to separate A2 evidence and contract work.

## 7. Lifecycle

The controlled strategy lifecycle is:

1. Initiation and mandate confirmation.
2. Evidence gathering, context and scenario assessment.
3. Strategy formulation and option appraisal.
4. Domain consultation and alignment.
5. Impact, risk, legal and financial assessment.
6. Review and approval by the competent corporate organ.
7. Controlled publication with version and effective-date metadata.
8. Execution by the CEO and accountable operating domains.
9. Performance measurement by D02 and periodic governance review.
10. Controlled amendment, supersession or archival.

A workflow completion state is not a substitute for a valid corporate resolution or delegated authority.

## 8. Segregation, access and audit controls

- Separate preparation, review, approval, execution and independent assurance duties.
- Enforce existing RBAC and trusted authorization context; do not create a universal administrator bypass.
- Distinguish platform-global records from tenant/legal-entity-scoped records.
- Enforce tenant and legal-entity boundaries for records and references.
- Fail closed when the acting identity, authority, delegation, approval evidence or scope cannot be established.
- Preserve version history and traceable decision evidence; corrections must not silently erase prior approved records.
- Record conflicts of interest, recusals, delegation basis, expiry and scope where applicable.
- Do not infer authority from a job title, login, shareholder status, UI control or possession of an administrative permission.

## 9. Explicit non-goals

D01 does not:
- confer, transfer or reinterpret statutory powers of the GM, Board or CEO;
- provide legal opinions or determine which conflicting instrument has legal priority;
- own D02's authoritative objective/KPI/target system of record;
- execute projects or programmes or replace D07;
- own accounting, treasury, investment transactions or capital approval;
- appoint directors, executives or domain owners;
- convert historical shareholder, capital or director schedules into current operational truth;
- authorize implementation, production data changes, merge or deployment.

## 10. Legal and authority exception register — OPEN

The following exceptions are explicit conditions on the architecture baseline. They are **not** resolved by Owner approval of this contract:

| Exception | Unresolved evidence / determination | Required resolution |
|---|---|---|
| LE-01 — Competent authority | Current statutory/bylaw authority for multi-year strategy, annual plan/budget and major strategic changes is not legally verified | Reconcile certified operative MoA/AoA and bylaws; obtain competent Ethiopian corporate-law review and authoritative organ determination |
| LE-02 — Public/internal instrument hierarchy | The 17-page public/government-use scan and 22-page internal-use instrument have distinct Owner-confirmed purposes; their registration, adoption, amendment history, legal identity and hierarchy remain unresolved | Preserve both separately; obtain certified copies, registration/adoption records, resolutions and legal determination |
| LE-03 — Annual budget reservation | Whether the Board may approve the full annual budget or whether any part is reserved to the GM is not verified | Reconcile statute and operative instruments; record any required GM approval or delegation |
| LE-04 — Major-change reservation and delegation | Exact reserved matters, delegated change authority and escalation boundary are not legally verified | Confirm through legal review and competent-organ resolutions; implement thresholds only in a separately approved policy |
| LE-05 — Current appointments and delegations | Current Board, CEO, corporate secretary and relevant delegated office-holders/mandates are not established by this contract | Obtain current appointment, election, acceptance, term and delegation evidence |
| LE-06 — Adopted strategy baseline | Existence and effective version of a duly adopted corporate multi-year strategy resolution is not established here | Locate and authenticate the competent-organ resolution and approved strategy version |

Until each relevant exception is resolved, the architecture may guide design and documentation but must not be presented as verified legal authority or used to bypass the operative legal approval process. If legal review identifies a conflict, the mandatory law and verified operative instruments prevail; update this contract only through a controlled, versioned amendment.

## 11. Baseline preservation and stage status

- Parent enterprise catalogue **A1 v1.2.0 remains APPROVED/FROZEN and unchanged**.
- This D01 contract **v1.0.0 is APPROVED as an architecture baseline**, with the legal exceptions in §10 open.
- The subordinate D01 A1 specification remains a separate controlled artifact; this approval does not silently overwrite or reclassify its prior status.
- D01 A2 remains **UNVERIFIED / OPEN**. The current evidence record v1.0.4 reports the requirement checklist as **16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED**. Contract approval does not close A2 or promote the domain disposition.
- Existing A2 evidence and traceability baselines are preserved. This document is additive and does not revise their findings.
- No A3 implementation is authorized. No A4 verification or A5 deployment is authorized.
- No merge, deployment, production data change, application source change, schema change, migration, seeder, permission or runtime configuration change is authorized by this decision.
- This record is documentation-only and is placed on the existing D01 evidence branch; no new branch is to be created.

## 12. Acceptance and next gate

**Architecture contract disposition: APPROVED.**

The next work remains bounded to D01 A2 legal/authority exception reconciliation and evidence traceability. Any proposal to begin D01 A3 must be submitted as a separate, explicit Owner authorization after the applicable stage gate. Merge and deployment each require separate authorization.
