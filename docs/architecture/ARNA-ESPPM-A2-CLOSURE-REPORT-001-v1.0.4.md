# ARNA ESPPM A2 Closure Report 001

- **Document ID:** ARNA-ESPPM-A2-CLOSURE-REPORT-001
- **Version:** 1.0.4
- **Prepared:** 2026-11-24 (Ethiopia, E.C./G.C. display follows ARNA localization policy)
- **Stage:** A2 — read-only reconciliation
- **Disposition:** **CLOSURE NOT RECOMMENDED — PARTIAL / NOT VERIFIED-CLOSED**
- **Authority:** Prepared as the evidence-based closure recommendation. This report is not itself an Owner closure decision.
- **Repository of record:** `akefadaka-git/arna-api`
- **Inspection repositories:** `akefadaka-git/arna-api`, `akefadaka-git/arna-app`
- **API inspected main HEAD:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **App inspected main HEAD:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **A2 decision baseline:** `40428db0a41d54397dd2aee482c9395f7950e9d0`
- **Change class:** Documentation / governance record only
- **Implementation, merge and deployment authority:** NOT GRANTED

---

## 1. Executive closure decision

### 1.1 Recommendation

**A2 status: PARTIAL — NOT VERIFIED-CLOSED.**

The read-only repository and governance-record reconciliation accounts for all 30 domains in the frozen A1 v1.2.0 catalogue. It establishes the approved A2 dispositions, identifies the relevant implementation and documentation evidence found in the inspected repository scope, records material contract gaps, and maps the principal cross-domain dependencies.

The evidence is not sufficient to certify A2 closure. Material domain contracts remain missing or incomplete; legal and financial authority questions remain unresolved; the D15 and D17 PARTIAL status proposals have been explicitly rejected and their approved MISSING dispositions retained; and not every domain has an accepted, versioned contract with a fully reconciled current-main implementation and verification record.

This report therefore recommends that A2 remain open for bounded evidence reconciliation. It does not authorize A3 implementation, PR merge, migration, financial execution, A4 verification or A5 deployment.

### 1.2 Approved A2 disposition count

The authoritative domain dispositions below are those recorded in the Owner-approved A2 decision package. Supplemental evidence is recorded separately and does not silently change those dispositions.

| A2 disposition | Count | Meaning |
|---|---:|---|
| SUPPORTED | 2 | Relevant contract/evidence exists in the inspected scope; not production verification |
| PARTIAL | 11 | Some relevant foundations exist, but material scope, authority, acceptance or evidence gaps remain |
| UNVERIFIED | 6 | Inspected evidence is insufficient to establish the domain contract or disposition |
| MISSING | 11 | No suitable domain contract or implementation evidence was found in the inspected repository scope |
| **Total** | **30** | All frozen A1 domains accounted for |

**Important:** MISSING means not located in the inspected repository scope; it does not prove that no related work exists elsewhere. SUPPORTED does not mean production-ready, fully implemented or independently verified.

### 1.3 Explicit decision record

| Decision item | Disposition |
|---|---|
| A1 v1.0 historical baseline | PRESERVE |
| A1 v1.1 approved amendment | PRESERVE |
| A1 v1.2.0 frozen 30-domain successor | PRESERVE |
| A2 30-domain inventory | RECONCILED |
| A2 contract/dependency gaps | RECORDED; OPEN |
| A2 overall closure | **NOT APPROVED / NOT VERIFIED-CLOSED** |
| A3 implementation | **NOT AUTHORIZED** |
| A4 verification | NOT STARTED UNDER THIS DECISION |
| A5 deployment | NOT AUTHORIZED |
| Merge of this report or related draft PRs | NOT AUTHORIZED |
| Production migrations, data changes or financial transactions | NOT AUTHORIZED |

This is the report preparer's closure recommendation. Only a separately recorded, explicit Owner decision can close A2 or authorize a later stage.

---

## 2. Scope, method and evidence limitations

### 2.1 Scope performed

Read-only inspection covered:

1. The frozen 30-domain catalogue and its domain boundaries.
2. The Owner-approved A2 domain disposition record.
3. The supplemental Treasury / Procurement / Sales evidence addendum.
4. API repository main tree and selected domain source/document paths.
5. App repository main tree and selected feature/test paths.
6. Exact Git commit, branch, PR and selected file-blob identifiers.
7. D12 Treasury A2 requirements and source-to-requirement traceability against exact current API/App main snapshots.
7. A sample of current GitHub Actions runs to distinguish candidate-branch CI from mainline evidence.
8. D04/D05 risk and compliance/control draft evidence, requirement traceability, and repository boundary statements.

### 2.2 Repository baselines

| Repository | Ref | Exact SHA | Inspection result |
|---|---|---|---|
| `akefadaka-git/arna-api` | `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Inspected; current sampled HEAD |
| `akefadaka-git/arna-app` | `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Inspected; current sampled HEAD |
| API A2 baseline | `main` parent / merge base | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Historical baseline used by A1/A2 documentation proposals |

API main HEAD `6e274c25ecc55b84e026e879d735b77f0e51592f` is a documentation/progress commit whose parent is `40428db0a41d54397dd2aee482c9395f7950e9d0`. The A2 proposal branches are based on that parent and remain separate from main.

### 2.3 Method and limitations

- Repository tree/path inspection and exact file retrieval were used. A source directory, route, model, UI feature, policy, draft contract or test file is evidence of existence only to the extent explicitly stated; it is not by itself proof of accepted domain ownership or production acceptance.
- PR branches were reviewed as proposals and are not treated as merged mainline evidence.
- CI runs were sampled as run records tied to their exact branch and SHA. A successful run on a candidate branch is not a global 30-domain acceptance result.
- No test suite was run by this report preparation.
- No live production database, bank account, bank statement, external regulator, statutory registry, production deployment, or external financial rail was accessed.
- No migration, seed, production data, application source, runtime configuration or main branch was changed during the A2 reconciliation.
- The source tree and workflow state can change after the inspected SHAs. Any later decision must re-check the exact current heads.

### 2.4 Evidence labels

- **VERIFIED IN REPOSITORY:** exact path/content or Git metadata retrieved at the cited ref/SHA.
- **SUPPORTING / LIMITED:** evidence proves a narrow capability or design only.
- **DRAFT / NOT ADOPTED:** proposal or policy awaits formal approval.
- **NOT LOCATED IN INSPECTED SCOPE:** targeted repository inspection did not locate the required evidence.
- **UNRESOLVED:** evidence is insufficient to establish the authoritative rule, ownership or operational state.
- **NOT RUN:** no test or verification execution was performed as part of this report.

---

## 3. Final 30-domain evidence register

The domain names and order below follow the frozen A1 v1.2.0 catalogue. The A2 disposition column preserves the Owner-approved A2 decision record. The evidence column identifies the exact inspected repository path or evidence class; exact file blobs and commit anchors are listed in Section 8.

### D01–D10 — Strategy, governance and enterprise structure

| ID | Frozen A1 domain | A2 disposition | Evidence found in inspected scope | Contract gap / closure condition |
|---|---|---|---|---|
| D01 | Corporate Strategy & Enterprise Planning | UNVERIFIED | No dedicated accepted strategy/planning contract identified in the inspected API/App paths. | Define strategic hierarchy, planning cycle, scenario/baseline controls, approval authority, strategic objective ownership and traceability to performance measures. |
| D02 | Enterprise Performance Management | PARTIAL | App `src/features/executive-overview/`, `src/features/dashboard/`; API executive-overview work and tests. | Approve enterprise KPI dictionary, formulas, owners, targets, scorecards, review cadence, exceptions, lineage and performance decision workflow. Executive Overview is not the whole EPM domain. |
| D03 | Corporate Governance & Board Management | PARTIAL | API `mases_core/src/Domains/Governance/`; governance models, policies, routes, services and tests; governance/election documentation. | Reconcile authority/delegation, board and committee lifecycle, statutory evidence, decision records, election rules and current-main integration. |
| D04 | Enterprise Risk Management | UNVERIFIED | Risk references appear in draft policy/control material; no accepted Risk domain contract identified. | Establish risk taxonomy, appetite, scoring, inherent/residual risk, controls, treatment, acceptance/escalation and risk-register ownership. |
| D05 | Compliance & Internal Control Management | UNVERIFIED | Draft PSC policy and control-matrix evidence. These are not a complete adopted Compliance/Internal Control domain contract. | Approve obligation register, control catalogue, test/evidence lifecycle, breach handling, assurance and independent reporting. |
| D06 | Legal & Regulatory Affairs | PARTIAL | `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md`; election-specific legal evidence register lineage. | Resolve legal-source limitations and establish full legal matter, advice, obligation, contract, dispute and regulatory-change lifecycle. |
| D07 | Project, Programme & Portfolio Management | UNVERIFIED | Planning references exist, but no accepted project/programme/portfolio domain contract identified. | Define intake, business case, prioritization, stage gates, schedule/cost/scope controls, benefits, dependencies, change control and closure. |
| D08 | Organization & Structure Management | SUPPORTED | `docs/architecture/organization-domain-contract.md`; `mases_core/src/Domains/Organization/`. | Contract exists and is Owner-approved for bounded implementation. Close remaining implementation/current-main reconciliation and acceptance evidence; do not infer authorization from org hierarchy. |
| D09 | People & Human Capital Management | PARTIAL | `mases_core/src/Domains/People/`; Person, EmployeeRecord, PersonTenantAccess, identity and workforce aggregate foundations. | Complete employee lifecycle, employment relations, positions, compensation/benefits, attendance/leave, performance, training, offboarding and privacy/retention contract. |
| D10 | Shareholder & Ownership Management | PARTIAL | `mases_core/src/Domains/Shareholder/`; shareholder, shareholding, share class, certificate, registry and services; shareholder regression workflow evidence. | Reconcile operative legal instruments, share-class rights, voting weight/caps, ownership changes, transfers, proxy and statutory register evidence. |

### D11–D20 — Financial management, commercial operations and records

| ID | Frozen A1 domain | A2 disposition | Evidence found in inspected scope | Contract gap / closure condition |
|---|---|---|---|---|
| D11 | Finance & Accounting Management | PARTIAL | `mases_core/src/Domains/Finance/`; candidate Finance contract, GL contract, CoA, A3 baseline, journal outbox and hybrid-controls documents. | Resolve competing contract lineage/approval status, legal-entity/accounting-policy decisions, source-domain adapters, reconciliation, FX, operational controls and independent review. |
| D12 | Treasury & Cash Management | MISSING | Finance CoA cash/bank classifications; narrow Governance penalty settlement service/tests; Shareholder treasury/buyback flag; Finance outbox boundary; D12 A2 requirements/traceability candidate baseline (PR #219). | No independent Treasury contract, bank mandate/signatory schedule, approved bank-account register, payment authority matrix, bank-statement ingestion/reconciliation, liquidity controls or bank execution evidence was verified. |
| D13 | Loan & Credit Management | PARTIAL | `mases_core/src/Domains/Loan/`; loan, installment, accrual, restructuring, write-off, application and approval services; loan route and tests. | Complete approved credit policy, product/underwriting authority, pricing, collateral, collections, impairment, provisioning, write-off governance and D11 accounting adapter acceptance. |
| D14 | Wallet & Digital Financial Services | PARTIAL | `mases_core/src/Domains/Wallet/`; wallet, entries, obligations, payments, allocations, reversals, services and tests. | Complete external bank/mobile-money rail, custody, settlement, reconciliation, dispute/refund, liquidity and Treasury boundary acceptance. Wallet is not Treasury or an independent bank. |
| D15 | Procurement & Supplier Management | MISSING | Draft `ARNA-GOV-POL-PSC-001` includes procurement workflow/control descriptions; provisional PSC matrix. | No adopted Procurement contract or policy. Supplier lifecycle, sourcing, requisition, tender, evaluation, award, purchase order, receipt, invoice matching and authority thresholds require approval. |
| D16 | Inventory & Supply Chain Management | MISSING | No dedicated accepted domain contract or implementation domain established in inspected scope. | Define item/master data, stock locations, receipts/issues/transfers, valuation, reservations, reorder, lot/serial, counts, loss, returns and D15/D11 integration. |
| D17 | Sales, Marketing & Customer Relationship Management | MISSING | Draft PSC policy includes sales/customer/order/collection workflow descriptions. | No adopted Sales/CRM contract. Define customer lifecycle, pricing, quotation/order, discount/credit authority, revenue/tax, fulfilment, returns/refunds, collections and D18/D11 integration. |
| D18 | Operations & Service Delivery Management | UNVERIFIED | Phase planning references; no dedicated accepted operations/service-delivery contract identified. | Establish service catalogue, work/order lifecycle, capacity, fulfilment, service levels, quality, incidents, customer acceptance and operational cost evidence. |
| D19 | Enterprise Asset & Facilities Management | MISSING | No dedicated accepted contract or implementation domain established in inspected scope. | Define operational asset register, custody, assignment, maintenance, depreciation boundary with D11, facilities, safety, disposal and lifecycle controls. |
| D20 | Documents & Records Management | PARTIAL | `mases_core/src/Domains/Documents/`; centralized `FileManagement` storage; document registry, numbering, lifecycle and evidence code; lifecycle contract draft. | Formal record approval matrix, taxonomy, confidentiality, retention, legal hold, archival/destruction, publication and immutable decision history remain incomplete or awaiting Owner decisions. |

### D21–D30 — Communication, intelligence, digital systems and strategic expansion

| ID | Frozen A1 domain | A2 disposition | Evidence found in inspected scope | Contract gap / closure condition |
|---|---|---|---|---|
| D21 | Communication & Stakeholder Engagement | PARTIAL | `docs/communication/ANNOUNCEMENTS-001.md`; API announcement controller; App `src/features/communications/`, notifications and stakeholder-facing features. | Announcement implementation candidate does not prove full communication hub. Define channels, conversations, stakeholder groups, moderation, approvals, delivery evidence, retention and consent. |
| D22 | Calendar, Events & Scheduling Management | SUPPORTED | `docs/calendar/ARNA_CALENDAR_OWNING_DOMAIN_AUTH_AND_REMINDER_OUTBOX_CONTRACT.md`; App `src/features/calendar/`; calendar contract tests. | Contract covers domain-resource authorization and reminder outbox. Continue exact-head verification of recurrence, localization, integration, operations and security; do not treat contract as access grant. |
| D23 | Enterprise Reporting & Business Intelligence | PARTIAL | Finance reports, Executive Overview and dashboard foundations in API/App. | Define enterprise semantic layer, certified datasets, data ownership, lineage, refresh/freshness, access policy, reconciliation, metric certification and BI release governance. |
| D24 | Information Technology & Digital Systems Management | UNVERIFIED | Shared identity/auth, tenancy, RBAC, audit, API, file, notification, observability and platform foundations. | Resolve boundary between IT service management and shared platform operations; define service catalogue, configuration/asset ownership, incident/problem/change, capacity, continuity and vendor lifecycle. |
| D25 | Investment & Capital Management | MISSING | No dedicated accepted domain contract or implementation evidence identified. | Define investment mandate, proposal, due diligence, appraisal, hurdle/valuation, capital allocation, approval, disbursement and investment monitoring. |
| D26 | Business Development & Strategic Partnerships | MISSING | Public-site inquiry intake exists but does not establish partnership lifecycle ownership. | Define opportunity pipeline, partner due diligence, strategic fit, negotiation, agreement, obligations, benefits, renewal and termination. |
| D27 | Corporate Development, Mergers & Acquisitions | MISSING | No dedicated contract or implementation evidence identified. | Define acquisition/divestiture strategy, target screening, valuation, due diligence, deal approval, transaction execution, integration and post-deal review. |
| D28 | Product, Service & Innovation Management | MISSING | No dedicated product/service lifecycle contract identified. | Define discovery, portfolio, roadmap, requirements, design, stage gates, release, adoption, feedback, end-of-life and innovation investment. |
| D29 | Grants, Funding & Financing Management | MISSING | No grant/funding lifecycle contract identified. Governance role grants are not this domain. | Define funder/source, eligibility, application, award, conditions, drawdown, restricted-fund tracking, reporting, audit and closeout. |
| D30 | Enterprise Investment Asset Portfolio | MISSING | No investment-holdings/portfolio contract identified. Operational asset records are not investment holdings. | Define portfolio holdings, ownership/valuation, returns, performance, concentration, risk, impairment, disposals and D25/D11 reporting. |

### 3.1 Domain count check

- SUPPORTED: D08, D22 = 2
- PARTIAL: D02, D03, D06, D09, D10, D11, D13, D14, D20, D21, D23 = 11
- UNVERIFIED: D01, D04, D05, D07, D18, D24 = 6
- MISSING: D12, D15, D16, D17, D19, D25, D26, D27, D28, D29, D30 = 11

Count control: 2 + 11 + 6 + 11 = 30.

---

## 4. Contract gap register

The register below turns the domain findings into controlled closure work. Role titles are proposed accountability roles, not confirmed appointments. The Owner must appoint named accountable role-holders and deputies separately.

| Gap ID | Domain(s) | Required contract / evidence | Proposed accountable role | Priority | Closure evidence |
|---|---|---|---|---|---|
| GAP-001 | D01 | Enterprise strategy and planning contract | Chief Executive / Strategy Executive | P0 | Approved strategy hierarchy, planning calendar, objective cascade and decision authority |
| GAP-002 | D02 | KPI/EPM contract | Strategy & Performance Executive | P0 | Metric dictionary, formulas, targets, owners, scorecards, review and exception workflows |
| GAP-003 | D03 | Governance and board contract reconciliation | Board Secretary / Governance Executive | P0 | Board/committee authority, appointment/delegation, decision register and statutory mapping |
| GAP-004 | D04 | Enterprise risk contract | Chief Risk Officer / Risk Executive | P0 | Risk taxonomy, appetite, assessment, treatment and acceptance matrix |
| GAP-005 | D05 | Compliance and internal-control contract | Compliance Officer / Internal Control Executive | P0 | Obligations, controls, testing, evidence, breach and assurance lifecycle |
| GAP-006 | D06 | Legal Affairs contract and statutory evidence | Legal Affairs Executive / authorized Legal Counsel | P0 | Certified operative sources, legal matter lifecycle, advice authority and unresolved issue dispositions |
| GAP-007 | D07 | Project/programme/portfolio contract | PMO / Portfolio Executive | P0 | Intake, business case, prioritization, delivery stages, benefits and closure controls |
| GAP-008 | D08 | Organization implementation acceptance | Organization Executive | P1 | Current-main contract reconciliation, tests, lifecycle and authority-boundary acceptance |
| GAP-009 | D09 | Human Capital contract | HR / People Executive | P1 | Employee lifecycle, workforce records, privacy and employment policy controls |
| GAP-010 | D10 | Shareholder ownership/statutory contract | Shareholder Affairs Executive / Company Secretary | P0 | Certified share rights, ownership register, voting/proxy and statutory reconciliation |
| GAP-011 | D11 | Finance accounting contract lineage and acceptance | Chief Financial Officer / Controller | P0 | One approved version, accounting policy, entity controls, source-event acceptance and reconciliations |
| GAP-012 | D12 | Independent Treasury contract | Treasurer / Treasury Executive | P0 | Bank mandate, account register, signatories, payment authority, cash forecast and bank reconciliation |
| GAP-013 | D13 | Credit and Loan contract | Credit Executive / Credit Committee | P1 | Approved credit policy, underwriting, servicing, impairment and accounting adapter evidence |
| GAP-014 | D14 | Wallet and digital-finance contract | Digital Finance Executive | P1 | Rail/custody boundary, settlement, reconciliation, disputes and operational controls |
| GAP-015 | D15 | Procurement and supplier contract | Procurement Executive | P0 | Adopted policy, threshold matrix, supplier lifecycle and three-way matching controls |
| GAP-016 | D16 | Inventory and supply-chain contract | Supply Chain Executive | P0 | Stock ledger, valuation, movement, count, custody and integration tests |
| GAP-017 | D17 | Sales/CRM contract | Commercial / Sales Executive | P0 | Adopted pricing, credit, order, fulfilment, returns, collections and revenue/tax policy |
| GAP-018 | D18 | Operations/service-delivery contract | Chief Operating Officer / Operations Executive | P0 | Service catalogue, order lifecycle, capacity, quality and SLA evidence |
| GAP-019 | D19 | Operational asset/facilities contract | Asset & Facilities Executive | P1 | Asset register, custody, maintenance, depreciation boundary and disposal controls |
| GAP-020 | D20 | Formal document/records acceptance | Records Manager / Company Secretary | P1 | Approved taxonomy, lifecycle authority, retention, legal hold, archival and publication rules |
| GAP-021 | D21 | Communication/stakeholder contract | Corporate Communications Executive | P1 | Channel, stakeholder, conversation, approval, consent and retention controls |
| GAP-022 | D22 | Calendar contract current-head acceptance | Calendar Product Owner / Platform Owner | P1 | Current-head contract tests, recurrence, E.C./G.C. and authorization regression evidence |
| GAP-023 | D23 | Enterprise BI/data governance contract | Chief Data / Performance Executive | P0 | Certified datasets, lineage, metric governance, freshness and access rules |
| GAP-024 | D24 | IT service management contract | CIO / IT Operations Executive | P1 | Service catalogue, incident/problem/change, configuration, continuity and vendor controls |
| GAP-025 | D25 | Investment/capital contract | Investment Committee / Investment Executive | P1 | Mandate, appraisal, capital allocation, approval and monitoring |
| GAP-026 | D26 | Business development/partnership contract | Business Development Executive | P1 | Opportunity, due diligence, agreement and partnership performance lifecycle |
| GAP-027 | D27 | Corporate development/M&A contract | Corporate Development Executive | P1 | Target screening, due diligence, valuation, deal authority and integration |
| GAP-028 | D28 | Product/service/innovation contract | Product & Innovation Executive | P1 | Product lifecycle, roadmap, stage gates, release and end-of-life |
| GAP-029 | D29 | Grants/funding contract | Grants & Funding Executive | P1 | Eligibility, award, restricted funds, drawdown, reporting and closeout |
| GAP-030 | D30 | Investment asset portfolio contract | Investment Portfolio Manager | P1 | Holdings, valuation, performance, risk, concentration and disposal |

### 4.1 Mandatory contract acceptance template

Every domain contract must define:

1. Domain purpose, accountable owner and authoritative records.
2. Included responsibilities, exclusions and adjacent-domain boundaries.
3. Lifecycle states, transitions, invariants and exception paths.
4. Tenant/legal-entity context, identity, RBAC, delegation and separation of duties.
5. Audit, evidence, retention, privacy, confidentiality and legal hold.
6. APIs, events, versioning, idempotency, replay, retries and reconciliation.
7. Data ownership, schema/migration boundaries and rollback/recovery.
8. Amharic-first localization, Ethiopian Calendar, G.C. interoperability and accessibility.
9. KPI/reporting lineage, source control totals and freshness.
10. Positive, negative, authorization, tenant-isolation, concurrency and recovery tests.
11. Operational monitoring, support, incident response and disaster recovery.
12. Explicit approval and release gates.

A folder, route, model, permission, screen, test source or draft PR is not a substitute for this contract.

---

## 5. Cross-domain dependency matrix

Dependencies below express required business ownership and integration relationships. They are not claims that all integrations are implemented.

| Dependency ID | Producer / authoritative owner | Consumer / dependent domain | Contract boundary and required evidence |
|---|---|---|---|
| DEP-01 | D01 Strategy | D02 EPM; D07 Portfolio; D23 BI | Strategy objectives and approved planning baselines become performance measures and portfolio priorities; KPI lineage must reference approved objectives. |
| DEP-02 | D02 EPM | D23 BI | KPI definitions, target versions and calculation rules are owned by EPM; BI consumes certified measures and must not redefine them. |
| DEP-03 | D03 Governance | All domains | Board/committee decisions and delegated authority are authoritative governance records; domain workflows enforce approved authority but do not invent it. |
| DEP-04 | D06 Legal | D03, D05, D10 and all regulated domains | Legal obligations and certified instruments constrain policy and authority; unresolved legal interpretations remain fail-closed. |
| DEP-05 | D08 Organization | D09 People; D03 Governance | Organization units and assignments provide structural context; they do not automatically grant application permissions or approval authority. |
| DEP-06 | D09 People | D08 Organization; D03 Governance; D21 Communication | Person/workforce identity and tenant access are referenced by other domains; each consumer must authorize through the owning domain. |
| DEP-07 | D10 Shareholder | D03 Governance; D11 Finance; D21 Communication | Shareholding, class rights and voting weights must come from the canonical shareholder register and certified legal rights; voting rules cannot be inferred from person count. |
| DEP-08 | D13 Loan | D11 Finance | Loan owns applications, schedules, accruals and servicing; Finance consumes versioned eligible posting events through a source-specific idempotent adapter. |
| DEP-09 | D14 Wallet | D11 Finance; D12 Treasury | Wallet owns wallet/payment operational ledgers; Finance receives controlled accounting events; Treasury owns bank/cash authority and reconciliation. |
| DEP-10 | D12 Treasury | D11 Finance; D14 Wallet; D17 Sales; D15 Procurement | Treasury owns bank accounts, cash position, bank mandates, payment initiation and reconciliation; other domains request/record business obligations but cannot claim bank execution authority. |
| DEP-11 | D15 Procurement | D16 Inventory; D11 Finance | Procurement owns sourcing, supplier selection, award and purchase order; Inventory owns receipt/stock movement; Finance owns accounting and invoice posting. |
| DEP-12 | D16 Inventory | D18 Operations; D11 Finance; D19 Assets | Inventory owns stock quantities/locations; Operations consumes availability; Finance owns valuation/accounting; capitalized assets transfer to D19 under controlled receipt. |
| DEP-13 | D17 Sales | D18 Operations; D11 Finance; D14 Wallet | Sales owns customer/order/pricing/credit decision; Operations owns fulfilment; Finance owns receivables/revenue accounting; Wallet records payment events without owning sales obligations. |
| DEP-14 | D18 Operations | D17 Sales; D19 Assets; D23 BI | Operations owns service execution and delivery evidence; Sales consumes fulfilment state; D19 supplies asset availability; BI consumes certified operational measures. |
| DEP-15 | D19 Assets | D11 Finance; D18 Operations; D23 BI | D19 owns operational asset custody/maintenance; D11 owns accounting/depreciation policy; D18 consumes asset availability. Investment holdings remain D30, not D19. |
| DEP-16 | D20 Documents | All domains | Formal record lifecycle and evidence references are owned by D20; binary bytes/version storage remains a shared file capability and is not the formal record. |
| DEP-17 | D21 Communication | All domains; D22 Calendar | Communication owns content/conversation/stakeholder approval; notification transport is shared infrastructure; communication does not own event scheduling. |
| DEP-18 | D22 Calendar | All domains | Calendar owns event/time/reminder lifecycle; linked resource access is always delegated to the resource-owning domain policy; a calendar link never grants resource access. |
| DEP-19 | D25 Investment & Capital | D30 Portfolio; D11 Finance; D27 Corporate Development | D25 owns investment appraisal and capital-allocation decisions; D30 owns resulting investment holdings/portfolio; Finance owns accounting records. |
| DEP-20 | D26 Business Development | D27 Corporate Development; D28 Product; D29 Grants | Partnership/opportunity records may create strategic opportunities, product collaborations or funding applications; each destination retains its own lifecycle. |
| DEP-21 | D27 Corporate Development | D25 Investment; D30 Portfolio; D03 Governance; D06 Legal | M&A transaction decisions require investment appraisal, governance approval and legal due diligence; resulting holdings are recorded by D30. |
| DEP-22 | D28 Product / Innovation | D17 Sales; D18 Operations; D23 BI | Product owns lifecycle, requirements and release; Sales owns customer pricing/order; Operations owns service execution; BI measures adoption and outcomes. |
| DEP-23 | D29 Grants / Funding | D11 Finance; D25 Investment; D23 BI | D29 owns eligibility, award conditions and funder reporting; Finance owns accounting/restricted-fund ledger; D25 owns investment decisions, not grant compliance. |
| DEP-24 | D30 Investment Asset Portfolio | D25 Investment; D11 Finance; D23 BI | D30 owns investment holdings, valuation/performance/risk records; D25 owns capital allocation; Finance owns GL recognition and reporting. |

### 5.1 Cross-cutting platform dependencies

The following are shared platform capabilities, not extra business domains:

1. Identity and authentication.
2. Tenant resolution and isolation.
3. Authorization, RBAC, delegation and policy enforcement.
4. Audit and immutable evidence.
5. Notification delivery.
6. API, integration and event infrastructure.
7. Data governance, quality and lineage.
8. Cybersecurity, privacy, secrets and key management.
9. Workflow orchestration.
10. Binary object/file storage.
11. Amharic-first localization, Ethiopian Calendar and G.C. interoperability.
12. Backup, restore and disaster recovery.
13. Observability and platform operations.

Every domain must use these capabilities through explicit contracts. No shared capability should become a shadow business-domain owner.

---

## 6. Legal and governance exceptions

### 6.1 Legal evidence register

The A2 decision package recorded an election-specific legal evidence branch and a six-matter register. The evidence establishes that a reconciliation register and source-review addendum exist; it does not establish that every underlying legal source is certified, current or operative.

| Exception | Matter | Current disposition | Evidence required to close |
|---|---|---|---|
| LEG-01 | Identity, operative status and legal relationship of the company bylaws to the separate registered Memorandum/Articles and amendments | PARTIAL | The Owner-confirmed 17-page scan is titled `የመተዳደሪያ ደንብ` (bylaws/internal regulations) and is recorded as the latest complete approved bylaws version. It is not evidence, by itself, that the separate statutory Memorandum/Articles has been produced, registered, or authenticated. The separate instrument's binary and registration particulars were not available for this reconciliation. | Obtain and inspect the separate registered Memorandum/Articles and complete amendment/registration chain; establish whether and how the bylaws were adopted, incorporated or otherwise given legal effect; confirm current operative status with competent registration evidence and qualified Ethiopian-law review. Do not relabel or treat the 17-page bylaws scan as the registered Memorandum/Articles absent documentary proof. |
| LEG-02 | Share-class rights, voting weight and caps | UNRESOLVED | Certified clauses and legal interpretation reconciled to share register and election rules. |
| LEG-03 | Proxy scope, submission, revocation and replacement | PARTIAL | Operative clause, valid submission evidence, deadline, form and competent decision-maker. |
| LEG-04 | Candidate qualification/disqualification | PARTIAL | Certified clause/statutory basis, evidence standard, notice and consistent procedure. |
| LEG-05 | Objection/appeal authority and procedure | UNRESOLVED | Governing provision, competent authority, deadlines, recusal and review route. |
| LEG-06 | Legal Advisor authority, independence and recusal | UNRESOLVED | Appointment/engagement authority, scope, conflict declarations, recusal and reporting line. |

A2 legal evidence references:

- Branch: `docs/p2.5-legal-evidence-a2`
- Commit: `376f697001d215559bd68e9ceb17c51848e50b42`
- Register blob: `cfbf958cb09589878522880d7d358da0fcb636c4`
- Source-review addendum blob: `e49f80af227ed49667e5f9492c2d3f9ade60478b`
- Owner-supplied scan SHA-256 recorded in the A2 decision record: `8df31ad217b2221f42b3e0e1154007bdd5d2ab003df0e1ad3dc7f17c03b61183`

**Fail-closed rule:** Do not assume voting eligibility, voting weight/caps, candidate disqualification, proxy validity or appeal authority while the relevant legal questions remain unresolved. The election-specific legal register is not a complete D06 Legal Affairs domain contract.

### 6.2 Governance / PSC exceptions

- `ARNA-GOV-POL-PSC-001 v0.1.0` is a draft policy and is not formally adopted.
- `ARNA-GOV-MAT-PSC-001-AM v0.2.0` is a provisional Owner-approved control baseline, but source verification and formal corporate approval remain outstanding.
- Draft workflow descriptions are evidence of policy design, not evidence of an adopted Procurement or Sales domain.
- Committee authority, appointment, thresholds, conflicts, recusals and delegated approval limits must be tied to authoritative corporate instruments and current appointments.
- Role-based accountable-owner proposals in the A2 record do not prove named appointments.

---

## 7. Financial and Treasury exceptions

### 7.1 D11 Finance contract lineage

The Owner-approved A2 decision record establishes the following lineage:

1. `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md`, document `ARNA-FIN-CONTRACT-001 v0.1.0`, is the **single candidate Finance accounting contract for formal Owner review**.
2. `mases_core/src/Domains/Finance/Documentation/financial-accounting-domain-contract.md` is supporting GL implementation/acceptance material, not a competing authoritative contract.
3. Neither file may be described as Owner-approved until approval is explicitly recorded against its exact version and content SHA.
4. `docs/finance/ARNA-FINANCE-A3-IMPLEMENTATION-BASELINE.md` and PR #195 are implementation-lineage evidence, not primary proof of Finance A1/A2 approval.
5. D11 remains PARTIAL until contract approval, accounting/legal-entity policy, source-domain boundaries and acceptance are reconciled.

Exact current-main blobs:

- Candidate Finance contract: `a28040d3926973004675aaf5bbe0760cd5b4565a`.
- Supporting GL contract: `945be5238b452806e60cb4e42f1b9eec3e143801`.
- Finance A3 implementation baseline: `1d66249eb813d785af50ae36f4844a9818f21ff5`.
- CoA configuration: `72424d5f12c2af03604a7979cf79552423447e5f`.
- CoA v1.0.0 document: `800af9f878b0c5f261904e07449b9f50dc78ad01`.
- Finance A3.8 hybrid controls: `80f5041774370f05dad7aa4396af8faef2b47959`.
- Finance A3.5 journal outbox/subledger boundary: `1cedd498fa8f2e96ecf59b9827200ae0915d0b46`.

Known limitations recorded in the A2 package / Finance evidence include no foreign-currency journal posting, no registered external subledger adapters for unapproved sources, unresolved evidence-binary orphan cleanup, and outstanding independent accounting/security review and backup/restore drills. The A3.5 contract deliberately fails closed for source domains without exactly one authoritative adapter and resolver. A durable outbox record is not proof of external event delivery.

### 7.2 D12 Treasury evidence and exceptions

| Evidence | Exact reference | What it establishes | What it does not establish |
|---|---|---|---|
| Chart of Accounts | `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php`, blob `72424d5f12c2af03604a7979cf79552423447e5f` | Categories for cash on hand (1110), bank accounts (1120), mobile-money/payment clearing (1130) and wallet settlement receivable (1140). | Treasury operating model, bank mandate, cashbook, statement import or reconciliation. |
| CoA v1.0.0 | `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md`, blob `800af9f878b0c5f261904e07449b9f50dc78ad01` | Proposed cash/bank control-account ownership and need for reconciliation owners/evidence schedules. | Accounting-policy/tax approval or production activation. |
| Finance A3.8 controls | `docs/finance/ARNA-FINANCE-A3.8-HYBRID-CONTROLS-BASELINE.md`, blob `80f5041774370f05dad7aa4396af8faef2b47959` | Finance integration remains NOT_RECONCILED until authoritative source control totals and event versions are supplied. | Actual bank-statement reconciliation or Treasury operation. |
| Governance penalty settlement service | `mases_core/src/Domains/Finance/Services/GovernancePenaltySettlementService.php`, blob `21224b7f9c07357a838f12ce5a943957ba3c837f` | Narrow entity-scoped, segregated and idempotent ETB cash/bank settlement path for a specific Governance penalty. | General payment authority, bank mandate, bank integration or bank reconciliation. |
| Settlement test source | `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAccountingTest.php` | A feature-test source exists. | Test pass; this report did not run it. |
| PSC matrix | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`, blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Limited segregation-of-duties, evidence and delegation principles. | Approved Treasury policy or primary bank authority. |

**Not verified:** primary bank mandate, signatory schedule, approved Treasury policy, bank-statement reconciliation evidence, authoritative bank account register, or relevant bank-authority email/attachment.

**D12 remains MISSING.** Accounting classifications and a narrow penalty settlement service are not an independent Treasury domain.

### 7.3 D15 and D17 supplemental evidence — proposed, not approved

The supplemental A2 addendum records draft PSC policy evidence for procurement and sales workflows and proposes refinements from MISSING to PARTIAL. The Owner-approved original A2 disposition remains operative until the Owner separately approves those refinements.

| Domain | Original approved A2 status | Supplemental proposal | Reason not yet changed |
|---|---|---|---|
| D15 Procurement | MISSING | PARTIAL | Draft policy/control evidence exists, but policy adoption, authority thresholds and complete contract are unresolved. |
| D17 Sales/CRM | MISSING | PARTIAL | Draft workflow evidence exists, but commercial policy, pricing, credit, revenue/tax and lifecycle acceptance are unresolved. |
| D12 Treasury | MISSING | MISSING | No independent Treasury contract or primary bank authority evidence. |

Supplemental addendum details:

- PR #215: `https://github.com/akefadaka-git/arna-api/pull/215`
- Branch: `docs/esppm-a2-treasury-psc-evidence-addendum-001`
- Head commit: `4c65ada3343571167bcb9c2e47e7f92abd4b89d8`
- File blob: `9c88fcd23a58954fde9d63017cea5aad88b030dd`
- Status at inspection: OPEN / DRAFT / UNMERGED.
- Addendum version stated in file: 1.0.2.
- Tests/CI stated by PR: NOT RUN; documentation-only review.

---

## 8. Exact-SHA evidence index

All SHA values below are exact identifiers, not abbreviated labels. A file blob SHA identifies file content; a commit SHA identifies a Git commit. They are not interchangeable.

### 8.1 Repository heads and baseline commits

| Evidence ID | Repository/ref | Exact SHA | Use |
|---|---|---|---|
| SHA-001 | API `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Inspected API main HEAD |
| SHA-002 | API parent / A2 baseline | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Baseline of A1/A2 documentation proposals |
| SHA-003 | App `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Inspected App main HEAD |

### 8.2 A1/A2 governance documents and PRs

| Evidence ID | Item | Branch / PR | Exact commit or blob SHA | Status |
|---|---|---|---|---|
| SHA-004 | Frozen A1 v1.2.0 30-domain catalogue | PR #212, `docs/esppm-a1-v1.2.0-frozen-catalogue` | Head commit `631b7a0eb7f027c17751b7af39bbc826fd70124e`; spec blob `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` | OPEN / DRAFT / UNMERGED |
| SHA-005 | Owner-approved A2 decision record | PR #213, `docs/esppm-a2-owner-decision-record-001` | Head commit `ded7b487544972588f163a0dd7b14a9f02f6fe69`; file blob `722589724b77f5330b4f592f36108ace0309bedc` | OPEN / DRAFT / UNMERGED |
| SHA-006 | Treasury / PSC evidence addendum | PR #215, `docs/esppm-a2-treasury-psc-evidence-addendum-001` | Head commit `4c65ada3343571167bcb9c2e47e7f92abd4b89d8`; file blob `9c88fcd23a58954fde9d63017cea5aad88b030dd` | OPEN / DRAFT / UNMERGED |
| SHA-007 | A1 v1.2.0 PR base | PR #212 base | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Historical baseline |
| SHA-008 | A2 decision PR base | PR #213 base | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Historical baseline |
| SHA-009 | Treasury addendum PR base | PR #215 base | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Historical baseline |

PR links:
- A1 archive: https://github.com/akefadaka-git/arna-api/pull/212
- A2 decision record: https://github.com/akefadaka-git/arna-api/pull/213
- Treasury / PSC addendum: https://github.com/akefadaka-git/arna-api/pull/215

All three were OPEN, DRAFT and UNMERGED at inspection. None is part of API main HEAD `6e274c25ecc55b84e026e879d735b77f0e51592f`.

### 8.3 Domain contract and implementation evidence blobs

| Evidence ID | Path | Blob SHA | Qualification |
|---|---|---|---|
| SHA-010 | `docs/architecture/organization-domain-contract.md` | `823abd0e36ffec936a0fd08ca50d4f773e067277` | Owner-approved bounded Organization implementation contract; production acceptance remains evidence-gated |
| SHA-011 | `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md` | `a28040d3926973004675aaf5bbe0760cd5b4565a` | Candidate Finance contract v0.1.0; proposed for Owner decision |
| SHA-012 | `mases_core/src/Domains/Finance/Documentation/financial-accounting-domain-contract.md` | `945be5238b452806e60cb4e42f1b9eec3e143801` | Supporting GL contract; initial implementation contract for review |
| SHA-013 | `docs/finance/ARNA-FINANCE-A3-IMPLEMENTATION-BASELINE.md` | `1d66249eb813d785af50ae36f4844a9818f21ff5` | Finance implementation lineage, not primary A1/A2 approval |
| SHA-014 | `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php` | `72424d5f12c2af03604a7979cf79552423447e5f` | CoA configuration; classification only |
| SHA-015 | `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md` | `800af9f878b0c5f261904e07449b9f50dc78ad01` | CoA document; accounting-policy/tax approval pending |
| SHA-016 | `docs/finance/ARNA-FINANCE-A3.8-HYBRID-CONTROLS-BASELINE.md` | `80f5041774370f05dad7aa4396af8faef2b47959` | Controls baseline; operational proof outstanding |
| SHA-017 | `docs/finance/ARNA-FINANCE-A3.5-JOURNAL-OUTBOX-AND-SUBLEDGER-BOUNDARY.md` | `1cedd498fa8f2e96ecf59b9827200ae0915d0b46` | Durable journal outbox and fail-closed adapter boundary |
| SHA-018 | `docs/governance/ARNA-GOV-POL-PSC-001.md` | `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft / not formally adopted |
| SHA-019 | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md` | `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Provisional control baseline; source verification and formal approval outstanding |
| SHA-020 | `docs/governance/elections/P2.5-SOURCE-AND-STATUTORY-RECONCILIATION-v1.0.md` | `8101ccbd3a9fbdc194c5a51aef34e0e458c11132` | Election-specific legal reconciliation; not full D06 contract |
| SHA-021 | `docs/communication/ANNOUNCEMENTS-001.md` | `dfba76a9634f90295c411e2443d4eef037efcdce` | Announcement implementation candidate |
| SHA-022 | `docs/calendar/ARNA_CALENDAR_OWNING_DOMAIN_AUTH_AND_REMINDER_OUTBOX_CONTRACT.md` | `afab95176d7bfac5cea57d92a60e6b5396428e74` | Calendar owning-domain authorization and reminder-outbox contract |
| SHA-023 | `docs/architecture/document-records-lifecycle-contract.md` | `edfea5478edd78361eb342c7cacf2679768cdacc` | Draft lifecycle contract; Owner decisions required; file-storage boundary explicitly distinguished |

### 8.4 Source-tree evidence anchors

The following paths were present in the inspected API tree at API main SHA `6e274c25ecc55b84e026e879d735b77f0e51592f`:

- `mases_core/src/Domains/Documents/`
- `mases_core/src/Domains/FileManagement/`
- `mases_core/src/Domains/Finance/`
- `mases_core/src/Domains/Governance/`
- `mases_core/src/Domains/Loan/`
- `mases_core/src/Domains/Organization/`
- `mases_core/src/Domains/People/`
- `mases_core/src/Domains/PublicContent/`
- `mases_core/src/Domains/Shareholder/`
- `mases_core/src/Domains/Wallet/`

These are the ten business-domain roots located in the inspected API source tree. Their existence does not mean there are only ten ARNA domains; the frozen enterprise catalogue has 30 domains. It also does not establish complete contracts for each root.

App feature roots present at App main SHA `ffe0495c0eb7c8b5d2013419123aac5b68893d51` included:

- `src/features/auth/`
- `src/features/calendar/`
- `src/features/communications/`
- `src/features/dashboard/`
- `src/features/executive-overview/`
- `src/features/files/`
- `src/features/governance/`
- `src/features/launcher/`
- `src/features/loans/`
- `src/features/locale/`
- `src/features/notifications/`
- `src/features/people/`
- `src/features/portal/`
- `src/features/public-content/`
- `src/features/public-site/`
- `src/features/shareholders/`
- `src/features/wallet/`

App test paths located included calendar browser/workspace contracts, executive-overview route/security tests, governance route contract, P0 loan route permissions and wallet reversal permission tests. Test-file existence is not a test result.

### 8.5 Sampled workflow evidence

These are sampled run records and must be interpreted only against their exact branch and head SHA.

| Repository | Workflow run | Branch / head SHA | Result | Scope qualification |
|---|---|---|---|---|
| API | 45388020322 — G-01 tenant isolation regression | `docs/arna-ecc-a3-baseline-001` / `b467f2bbb4f8808063a007c1f27c840dd52195fb` | SUCCESS | Candidate branch, not all-domain acceptance |
| API | 45388020187 — Shareholder seed-number regression | same / `b467f2bbb4f8808063a007c1f27c840dd52195fb` | SUCCESS | Candidate branch, shareholder-specific |
| API | 45388020081 — Executive Overview A4 API security | same / `b467f2bbb4f8808063a007c1f27c840dd52195fb` | SUCCESS | Candidate branch, dashboard-specific |
| API | 45388679313 — Clean-install MySQL verification | `feat/communication-people-contracts-a3` / `f3042d364cbe515a77f925dcf395994bbd7090b9` | IN PROGRESS at inspection | Not a completed verification |
| API | 45388645717 — Clean-install MySQL verification | `feat/communication-people-contracts-a3` / `d1605ba087bbfaca8d5cecd4bc59c9fb268a10be` | IN PROGRESS at inspection | Not a completed verification |
| App | 45368222595 — Frontend verification | `accessibility/issue-135-contrast-reproduction` / `3fcccfce73815ffe8de3eb550c8e709a11581276` | SUCCESS | Candidate branch, frontend-specific |
| App | 45367723161 — Frontend verification | same branch / `69551e7d57a4c6f6bc3f31a86e0e25c96e7888f9` | FAILURE | Earlier candidate SHA; not current App main |

Run links:
- https://github.com/akefadaka-git/arna-api/actions/runs/45388020322
- https://github.com/akefadaka-git/arna-api/actions/runs/45388020187
- https://github.com/akefadaka-git/arna-api/actions/runs/45388020081
- https://github.com/akefadaka-git/arna-api/actions/runs/45388679313
- https://github.com/akefadaka-git/arna-api/actions/runs/45388645717
- https://github.com/akefadaka-git/arna-app/actions/runs/45368222595
- https://github.com/akefadaka-git/arna-app/actions/runs/45367723161

No sampled run above verifies all 30 domains or substitutes for domain contract acceptance.

---

## 9. A2 closure criteria and exit assessment

| Criterion | Required evidence | Assessment |
|---|---|---|
| A2-C01 — 30-domain inventory | All frozen domains listed with explicit disposition | **PASS — inventory complete** |
| A2-C02 — Domain ownership and authoritative records | Approved owner, record and boundary contract for each domain | **PARTIAL** |
| A2-C03 — Dependencies | Producer/consumer and integration boundaries | **PARTIAL — matrix prepared; contracts remain open** |
| A2-C04 — Legal/governance evidence | Certified sources and final authority dispositions | **UNRESOLVED** |
| A2-C05 — Finance/Treasury | Approved Finance contract lineage, Treasury mandate and reconciliation evidence | **PARTIAL / D12 MISSING** |
| A2-C06 — Missing domain contracts | Versioned contracts or formally accepted scoped exceptions | **NOT MET** |
| A2-C07 — API/App exact-head reconciliation | Exact current-head contract mapping and domain-specific verification | **PARTIAL** |
| A2-C08 — Contradictions and exceptions | All material contradictions resolved or explicitly accepted by Owner | **PARTIAL** |
| A2-C09 — Closure evidence package | Consolidated report and evidence index | **PREPARED BY THIS REPORT; archival PR remains separate** |
| A2-C10 — Owner closure decision | Explicit Owner closure approval | **NOT GRANTED** |

### 9.1 Conditions to revisit closure

A2 may be resubmitted for closure when:

1. The P0 contract gaps in D01–D07, D10–D12, D15–D18 and D23 have approved contracts or explicit Owner-accepted exceptions.
2. D15/D17 supplemental dispositions have a separate Owner decision; the historical A2 record remains preserved.
3. D12 has an independent Treasury contract and primary bank authority/reconciliation evidence, or an explicit scoped exception approved by the Owner.
4. Legal matters LEG-01 through LEG-06 have authoritative source evidence or a formal legal-risk disposition by the competent authority.
5. D11 Finance candidate contract version, approval authority, legal-entity policy and source-domain boundaries are reconciled.
6. The current API and App heads are mapped to domain contracts and relevant acceptance tests; historical branch CI is not reused as current-head proof.
7. Every domain has an accountable role confirmed by the Owner, with named role-holders recorded through the appropriate governance process.
8. A final exception register identifies residual risks, impact, owner, due date and acceptance authority.
9. The final A2 evidence index is revalidated against the exact commit SHAs in force at closure.
10. The Owner issues a separate explicit A2 closure decision.

---

## 10. A2 remaining work packages

| Work package | Scope | Exit artifact | Priority |
|---|---|---|---|
| A2-R01 | D01–D07 strategy, EPM, governance, risk, compliance, legal and project delivery | Contract/evidence dispositions and legal authority register | P0 |
| A2-R02 | D11–D17 finance, treasury, loan, wallet, procurement, inventory and sales | Approved Finance lineage, Treasury evidence and PSC decision | P0 |
| A2-R03 | D18–D24 operations, assets, records, communication, calendar, BI and IT | Contract boundaries and exact-head integration evidence | P0 |
| A2-R04 | D25–D30 investment, partnerships, M&A, product, grants and portfolio | Six-domain contract inventory and dependencies | P1 |
| A2-R05 | Cross-domain dependencies and shared platform capabilities | Versioned producer/consumer and event/API boundary register | P0 |
| A2-R06 | Exact-SHA evidence and exception reconciliation | Revalidated source index, tests and unresolved-risk register | P0 |
| A2-R07 | Final closure submission | Owner decision record | P0 |

All work packages remain A2 read-only documentation/reconciliation. They do not authorize implementation.

---

## 11. Explicit closure decision record

### 11.1 Prepared recommendation

**Decision ID:** ARNA-ESPPM-A2-CLOSURE-DECISION-001  
**Decision date:** 2026-11-24  
**Decision status:** OWNER DECISION RECORDED — KEEP A2 OPEN  
**Recommendation:** DO NOT CLOSE A2  
**Recommended A2 disposition:** PARTIAL — NOT VERIFIED-CLOSED

**Rationale:** The 30-domain inventory and initial dependency register are complete, but contract acceptance, legal authority, Treasury evidence, source-domain integration completeness and exact-head verification remain incomplete. The D01, D15 and D17 proposal conflicts have been explicitly decided; their underlying domain evidence gaps remain open.

### 11.2 Owner decision fields

| Field | Value |
|---|---|
| Owner decision | **KEEP A2 OPEN — DO NOT CLOSE** |
| Approve A2 closure | NO |
| Accept residual exceptions as grounds to close A2 | NO |
| Authorize A3 | NO — separate authorization required |
| Authorize merge | NO |
| Authorize A4 | NO |
| Authorize A5 / deployment | NO |
| Owner decision date | 2026-11-24 |
| Owner decision source | Explicit Owner instruction in the current ChatGPT project conversation; recorded here as a governance decision, not a cryptographic signature. |
| GitHub formal approval event | NOT PRESENT — connected PR author review submissions are COMMENTED, not APPROVED. |

No approval is inferred from the creation, review or archival of this report.

### 11.3 Preservation and change controls

1. Preserve A1 v1.0 as the historical original 24-domain baseline.
2. Preserve A1 v1.1 / `ARNA-ESPPM-A1-ADD-001` as the historical approved amendment.
3. Preserve A1 v1.2.0 / `ARNA-ESPPM-A1-SPEC-002` as the frozen 30-domain successor.
4. Preserve the Owner-approved A2 decision record and the supplemental evidence addendum as separate records.
5. Do not silently alter the approved A2 dispositions; any refinement requires a separate Owner decision and versioned addendum.
6. Do not modify application code, schemas, migrations, seeders, runtime configuration or production data under A2.
7. Keep A3, A4 and A5 as separate stage gates.
8. Do not merge this report or related draft PRs without separate authorization.

---

## 12. Final assessment

| Dimension | Assessment |
|---|---|
| A1 architecture baseline | APPROVED / FROZEN |
| 30-domain catalogue reconciliation | COMPLETE |
| Domain evidence register | PREPARED; evidence limitations recorded |
| Contract gap register | PREPARED |
| Dependency matrix | PREPARED |
| Legal exceptions | RECORDED; unresolved |
| Financial/Treasury exceptions | RECORDED; unresolved |
| Exact-SHA evidence index | PREPARED |
| A2 closure criteria | NOT ALL MET |
| A2 overall | **PARTIAL — NOT VERIFIED-CLOSED** |
| Production readiness | NOT CLEARED BY THIS REPORT |
| A3 implementation | NOT AUTHORIZED |
| A4 verification | NOT AUTHORIZED UNDER THIS DECISION |
| A5 deployment | NOT AUTHORIZED |

**Final recommendation:** Preserve the frozen A1 baseline and Owner-approved A2 decision record. Continue the bounded A2-R01 through A2-R07 read-only work packages. Re-submit A2 for explicit Owner closure only after the closure criteria are satisfied or residual exceptions are explicitly accepted by the competent authority.

---

## Appendix A — authoritative and related references

- Frozen A1 v1.2.0 archive PR: https://github.com/akefadaka-git/arna-api/pull/212
- Owner-approved A2 decision-record PR: https://github.com/akefadaka-git/arna-api/pull/213
- Treasury / PSC evidence addendum PR: https://github.com/akefadaka-git/arna-api/pull/215
- API repository: https://github.com/akefadaka-git/arna-api
- App repository: https://github.com/akefadaka-git/arna-app

**End of report.**


---

## 13. Controlled A2-R06 resolution addendum — v1.0.1

- **Record ID:** ARNA-ESPPM-A2-R06-RESOLUTION-001
- **Prepared:** 2026-11-24
- **Authority:** Explicit Owner instruction in the current work session to resolve the D01 conflict, decide the D15/D17 proposal status, incorporate R05 and preserve the exact-SHA index.
- **Change class:** Documentation / governance reconciliation only.
- **Supersession:** This controlled addendum supersedes any earlier statement in Sections 1–12 that the D01 disposition conflict or the D15/D17 proposal decisions remain pending. Historical A1 and A2 records, prior report content and proposal branches are preserved.

### 13.1 Explicit Owner decisions — D01, D15 and D17

| Domain | Approved A2 disposition before resolution | Supplemental proposal | Owner decision recorded in this addendum | Controlling A2 disposition |
|---|---|---|---|---|
| D01 Corporate Strategy & Enterprise Planning | UNVERIFIED | PR #223 proposes PARTIAL | **REJECT proposed upgrade.** Additional corporate identity and document-provenance evidence does not establish an adopted enterprise strategy, competent strategy-approval authority or current executive appointments. | **UNVERIFIED** |
| D15 Procurement & Supplier Management | MISSING | PR #215 proposes PARTIAL | **REJECT proposed upgrade.** Draft/provisional workflow and PSC-control material does not establish adopted procurement policy, authority thresholds, supplier lifecycle contract or accepted operating controls. | **MISSING** |
| D17 Sales, Marketing & Customer Relationship Management | MISSING | PR #215 proposes PARTIAL | **REJECT proposed upgrade.** Draft workflow material does not establish adopted commercial policy, pricing/discount/credit/tax/refund authority or a complete accepted Sales/CRM contract. | **MISSING** |

These are explicit Owner disposition decisions, not findings that the underlying evidence gaps have been closed. The D01 v1.0.2 checklist's 24 requirement-level results (16 PARTIAL, 7 MISSING, 1 UNRESOLVED; 0 VERIFIED-CLOSED) remain a lower-level evidence assessment and do not promote the enterprise D01 domain disposition from UNVERIFIED. D01 subordinate A1 remains draft and is not a frozen implementation contract.

PR #215 and PR #223 remain evidence proposals. Their documents are retained as source evidence, but their proposed domain-level upgrades are not accepted. No historical record is rewritten and no PR is merged or closed by this decision.

### 13.2 R05 cross-domain integration findings incorporated

R05 is now incorporated into this consolidated report as a completed A2 evidence work package. It was a read-only review; no code, migration, schema, seed, runtime configuration, production data or test state was changed.

**Exact evidence anchors reviewed:**

| Evidence | Path / component | Exact blob SHA | Finding and qualification |
|---|---|---|---|
| Integration reconciliation plan | `docs/ARNA-INTEGRATION-RECONCILIATION-PLAN-001.md` | `55d5743f8e07b8ebd8e64c773b253aabcb847685` | Baseline integration plan; not proof that every proposed integration is live. |
| Finance A3.5 boundary | `docs/finance/ARNA-FINANCE-A3.5-JOURNAL-OUTBOX-AND-SUBLEDGER-BOUNDARY.md` | `1cedd498fa8f2e96ecf59b9827200ae0915d0b46` | Source-owned events, authoritative adapters and fail-closed posting boundary. |
| Finance subledger posting contract | Finance subledger posting contract | `19700b59f962278b7777fb22c59b8cb78a64d68` | Defines the journal-posting boundary; not proof of full source-domain adapter coverage. |
| Finance source resolver registry | Finance source resolver registry | `6a62422e9d885cde501126adfff4be3797eba0f7` | Resolver registry is explicit; unsupported source types fail closed. |
| Finance source entity mapping resolver | Finance source entity mapping resolver | `d6345dc9820e8d704307e8b60302da86ed8fa100` | Resolves source-to-entity mapping; does not replace source-domain authority. |
| Finance outbox listener | Finance outbox listener | `cca9edcb54c2e9b3a1fdbbdc3a56a62a222df307` | Transactional outbox listener evidence; no enabled external publisher/transport was verified. |
| Finance inbox service | Finance inbox service | `060398dd9b9e94fcc9539f19b78bed21edc73ad2` | Durable inbox, deduplication/quarantine/retry/lease/acknowledgement foundation. |
| Calendar resource authorizer | Calendar resource authorizer | `0570cfb99f412b03f1988c9cdc623040ba7248ea` | Explicit allowlist and owning-domain authorization; linked references do not grant access. |
| Documents workflow aggregate service | Documents workflow aggregate service | `55667ea2df7658cc141057e232aa3afbe4d0a32d` | Tenant/issuer ownership, actor permissions, revision concurrency and immutable lifecycle events; does not own the underlying business decision. |
| Calendar service | Calendar service | `bc2ee942ef66bfc06a670be5257252327db79311` | Calendar owns calendar/event/resource reservation behavior, not the assets or business processes being scheduled. |
| Notification service | Notification service | `33a1bc15afbbaf97545f20d8a88c9a0fbab76a85` | Tenant-scoped recipient delivery and deduplication; notification delivery does not approve or authorize business actions. |

**Reconciled integration conclusions:**

1. Finance has a durable journal-posted outbox and inbox foundation with source-reference validation, deduplication, quarantine, retry, leasing and acknowledgement.
2. No enabled external publisher/transport or complete source-specific adapter set was verified. A durable outbox is not end-to-end event delivery and does not prove automatic accounting.
3. The current source mapping recognizes Shareholder, Wallet, Loan and Governance penalty settlement. Other source types fail closed unless an authoritative resolver is registered.
4. Finance consumes source-owned events; Loan, Wallet and other source domains retain ownership of their operational records. Finance must not mutate source records.
5. Wallet allocation is payment attribution, not a new monetary movement. A Wallet payment record is not proof of bank settlement.
6. Calendar resource authorization does not transfer ownership of the resource to Calendar. Documents workflow controls evidence/lifecycle transitions but does not make the underlying business decision. Notifications deliver messages but do not approve transactions.
7. No D25–D30 source integration contracts or corresponding App consumers were found in the reviewed evidence.

**A2 impact:** R05 is COMPLETE as an evidence-reconciliation work package. Integration maturity remains PARTIAL; this finding does not upgrade any domain disposition or authorize integration implementation.

### 13.3 Updated exact-SHA repository and PR evidence index

The following is the current evidence snapshot used for this addendum. Commit SHAs and blob SHAs are distinct and must not be interchanged.

**Repository heads:**

| Repository/ref | Exact SHA | Use |
|---|---|---|
| API `main` | `6e274c25ecc55b84e026e879d735b77f0e51592f` | Current API main HEAD re-fetched for this reconciliation |
| API A2 historical baseline | `40428db0a41d54397dd2aee482c9395f7950e9d0` | Parent baseline used by original A1/A2 proposal branches |
| App `main` | `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | Current App main HEAD retained from exact-tree inspection |

**Current PR heads and comparison against API main `6e274c25ecc55b84e026e879d735b77f0e51592f`:**

| PR | Branch | Exact head commit | Ahead | Behind | Current state | Evidence interpretation |
|---|---|---|---:|---:|---|---|
| #212 | `docs/esppm-a1-v1.2.0-frozen-catalogue` | `631b7a0eb7f027c17751b7af39bbc826fd70124e` | 2 | 1 | OPEN / DRAFT / UNMERGED | Frozen A1 archive proposal; based on historical A2 parent. |
| #213 | `docs/esppm-a2-owner-decision-record-001` | `ded7b487544972588f163a0dd7b14a9f02f6fe69` | 1 | 1 | OPEN / DRAFT / UNMERGED | Owner-approved A2 decision-record archive proposal; historical base. |
| #215 | `docs/esppm-a2-treasury-psc-evidence-addendum-001` | `4c65ada3343571167bcb9c2e47e7f92abd4b89d8` | 3 | 1 | OPEN / DRAFT / UNMERGED | Supplemental Treasury/PSC evidence; D15/D17 PARTIAL proposals explicitly rejected by this addendum. |
| #219 | `docs/esppm-a2-closure-report-001` | `4d75a24e32cfa13585f15f8be4a1297b8447c949` | 3 | 0 | OPEN / DRAFT / UNMERGED at snapshot cutoff | Branch head immediately before this evidence-index correction; v1.0.1 report commit follows as a documentation-only successor. |
| #220 | `feat/arna-ecc-a3-p0` | `8d0e4f236cc23eea21b0c44b4f3571d0710f2120` | 1 | 0 | OPEN / DRAFT / UNMERGED | Separate ECC KPI readiness workstream; no ESPPM disposition authority. |
| #222 | `docs/arna-d01-a1-spec-a2-evidence` | `cf4486f0c983641bfd3f81ad284b5efe4e57c401` | 1 | 0 | OPEN / DRAFT / UNMERGED | D01 subordinate A1 draft; Owner review pending. |
| #223 | `docs/arna-d01-a2-evidence-register` | `3cdde5acb82d95ef1d008126e0f583f04bca73e0` | 3 | 0 | OPEN / DRAFT / UNMERGED | D01 evidence proposal; latest v1.0.2 addendum does not establish adopted strategy authority. |

**Exact document blobs:**

| Document | Exact blob SHA | Branch/ref |
|---|---|---|
| Consolidated A2 closure report v1.0.0 predecessor | `49f571fb7797ab8cf7027a5a66f072b0e2d17d80` | `docs/esppm-a2-closure-report-001` |
| Consolidated A2 closure report v1.0.1 successor (before index correction) | `a0f04f48d6adcb6abc4e05ec725dd99d6c8df77f` | `docs/esppm-a2-closure-report-001`; index-correction commit follows |
| D01 A1 subordinate specification | `c04dc84e21ccc9114d8952ba4d38913e36e58004` | PR #222 |
| D01 A2 evidence register v1.0.2 | `729644771e7097d6e6be95897cf7ab7ee3d8d710` | PR #223 |
| Treasury/PSC evidence addendum v1.0.2 | `9c88fcd23a58954fde9d63017cea5aad88b030dd` | PR #215 |
| Frozen A1 v1.2.0 catalogue | `e9cd1f4a087a3d7f285ccda7786149d64db6a4bc` | PR #212 |
| Owner-approved A2 decision record | `722589724b77f5330b4f592f36108ace0309bedc` | PR #213 |

**PR references:**  
- #212: https://github.com/akefadaka-git/arna-api/pull/212  
- #213: https://github.com/akefadaka-git/arna-api/pull/213  
- #215: https://github.com/akefadaka-git/arna-api/pull/215  
- #219: https://github.com/akefadaka-git/arna-api/pull/219  
- #220: https://github.com/akefadaka-git/arna-api/pull/220  
- #222: https://github.com/akefadaka-git/arna-api/pull/222  
- #223: https://github.com/akefadaka-git/arna-api/pull/223  

The #212/#213/#215 branches remain one commit behind API main because their bases are the historical commit `40428db0a41d54397dd2aee482c9395f7950e9d0`; the intervening mainline commit is documentation/progress-only. Their historical branch content is preserved and is not silently rebased. PRs #220/#222/#223 are based on the current API main SHA. The #219 row records the exact branch head at the start of this evidence-index correction. The successor report blob and commit are listed in the document index; this correction itself advances the branch by one documentation-only commit. The live PR metadata remains the authority for the post-commit head.

### 13.4 A2-R06 and A2-R07 stage disposition

| Work item | Updated status | Evidence / decision |
|---|---|---|
| R01 D01–D07 | COMPLETE | Domain evidence and dispositions reconciled; D01 domain disposition explicitly retained as UNVERIFIED. |
| R02 D11–D17 | COMPLETE | Finance/Treasury and PSC evidence reconciled; D15/D17 explicitly retained as MISSING. |
| R03 D18–D24 | COMPLETE | Domain and shared-platform boundaries reconciled. |
| R04 D25–D30 | COMPLETE | Six-domain gap inventory reconciled. |
| R05 Cross-domain integrations | COMPLETE | Findings incorporated in Section 13.2. |
| R06 Evidence/contradiction reconciliation | **COMPLETE** | D01, D15 and D17 conflicts decided; R05 incorporated; exact current-main/PR index preserved. |
| R07 Owner closure submission | **SUBMITTED; OWNER DECISION RECORDED** | Owner decision: KEEP A2 OPEN — DO NOT CLOSE. The decision does not close A2 or authorize merge/A3/A4/A5. |

**A2 closure recommendation remains DO NOT CLOSE.** Completing R06 and submitting R07 does not mean the A2 exit criteria have been met. The 30-domain inventory is complete, but domain contract, legal authority, Treasury, integration and current-head verification gaps remain. The Owner's decision is recorded: **KEEP A2 OPEN — DO NOT CLOSE** (2026-11-24). Residual exceptions are not accepted as grounds to close A2.

### 13.5 Updated progress and authorization status

- R01–R06, R08 and R09: 8 work packages complete; R07 submitted with the Owner decision recorded.
- R08 D04/D05 traceability artifact: `docs/architecture/ARNA-ESPPM-D04-D05-A2-TRACEABILITY-001-v1.0.0.md` — blob `ae922a9d41460fa23bf5e7995dd4b3953a0c48f9`; artifact commit `e0aedcd13246e87a4c73a000f1ed6f03b8fe8de0`.
- D04 remains UNVERIFIED; D05 remains UNVERIFIED. No domain disposition upgrade.
- Domain evidence/gap reconciliation: 30/30 domains complete (100%); D01 remains UNVERIFIED and its accepted disposition is preserved.
- Disposition coverage: 30/30 (100%).
- A2 overall: **PARTIAL — NOT VERIFIED-CLOSED**.
- Tests/CI for this documentation-only reconciliation: **NOT RUN**.
- Application source, schema, migrations, seeders, production data and runtime configuration: **UNCHANGED**.
- Merge: **NOT AUTHORIZED**.
- A3 implementation: **NOT AUTHORIZED**.
- A4 verification: separate stage gate; not started by this addendum.
- A5 deployment: **NOT AUTHORIZED**.

**R07 decision record:** The Owner has explicitly directed **KEEP A2 OPEN — DO NOT CLOSE** (2026-11-24). This decision is recorded; it is not an A2 closure, residual-exception acceptance, formal GitHub APPROVE event, merge authorization or A3/A4/A5 authorization.

**End of controlled A2-R06 resolution addendum v1.0.1.**

---

## 14. Controlled A2-R02 D12 Treasury requirements and traceability addendum — v1.0.2

- **Authority:** Owner instruction to complete D12 Treasury A2 requirements and source-to-requirement traceability, defer primary financial evidence until ARNA is deployed and running, and synchronize PR #219 without changing either repository's `main`.
- **New controlled artifact:** `docs/architecture/ARNA-ESPPM-D12-TREASURY-A2-CONTRACT-BASELINE-001-v1.0.0.md`, blob `72046cb9c2c76e73fe3371ec60c3efb52fa6e2e3`.
- **D12 artifact commit:** `b357e14942d35d8f5ff3d4669b0da8687fe7816b`.
- **Inspected API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`.
- **Inspected App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`.
- **D12 requirements:** 20 stable requirements (D12-RQ-01 through D12-RQ-20), conceptual records, lifecycle invariants, cross-domain interfaces, ten A2 specification acceptance criteria and twenty proposed future A4 scenarios (NOT RUN).
- **Traceability:** 18 source entries record exact current-main blob SHAs and the limited evidence each source establishes. Shareholder treasury/buyback flags are explicitly distinguished from Treasury cash/bank management; Finance CoA classifications and narrow Governance settlement are not treated as a bank-account register, bank mandate, general payment system or bank reconciliation engine.
- **Requirement evidence assessment:** 4 PARTIAL, 16 MISSING, 0 VERIFIED-CLOSED. This is a requirement-level evidence summary only; it does not change the Owner-approved domain disposition.
- **Controlling D12 disposition:** **MISSING — PRESERVED**. No independent accepted D12 contract, bank mandate/signatory schedule, general payment execution, statement ingestion/reconciliation or liquidity capability was established.
- **Primary evidence timing:** bank account ownership, bank mandates/signatories, statements, confirmations and reconciliation source records remain deferred until ARNA is deployed and running on the server. No pre-deployment upload is requested or required.
- **D12 A3 entry:** separate A1 contract approval, source-backed corporate/bank authority, cross-domain contracts and explicit Owner A3 authorization remain required.
- **Owner decision synchronized:** **KEEP A2 OPEN — DO NOT CLOSE** (2026-11-24). A2 closure and residual-exception acceptance are NOT APPROVED. A3, merge, A4 and A5 remain NOT AUTHORIZED. Conversational Owner instruction is recorded transparently; no cryptographic signature or formal GitHub APPROVE event is claimed.
- **Change class:** documentation only. No API/App source, schema, migration, seeder, runtime configuration, production data or main branch changed. Tests/CI NOT RUN.

### 14.1 D12 source evidence highlights

| Evidence | Exact source blob | Bounded finding |
|---|---|---|
| Finance CoA | `72424d5f12c2af03604a7979cf79552423447e5f` | Cash/bank/payment-clearing account classifications exist; no account register or bank operations are established. |
| CoA documentation | `800af9f878b0c5f261904e07449b9f50dc78ad01` | Proposed Finance/Treasury control ownership and reconciliation-owner requirement; no active adapter approval. |
| Finance A3.5 boundary | `1cedd498fa8f2e96ecf59b9827200ae0915d0b46` | Durable outbox/inbox foundation; external transport and automatic payments explicitly excluded. |
| Finance A3.8 controls | `80f5041774370f05dad7aa4396af8faef2b47959` | Source control-total reconciliation is NOT IMPLEMENTED; bank credentials belong in deployment secret manager. |
| BANK source enum | `e0991fc4026952589102691abc2b3a1393b21b20` | Provenance classification only; not an adapter or bank integration. |
| Governance settlement service | `21224b7f9c07357a838f12ce5a943957ba3c837f` | Narrow entity-scoped penalty settlement; not general Treasury. |
| Governance settlement controller | `6ae90c3ddc553167c3643b423afcd9546274343d` | Narrow settlement and reconciliation endpoints only. |
| Draft PSC policy | `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft, not adopted; monetary/signatory authority remains unverified. |
| Approval authority matrix | `f53446ece9481ddf09759854cf2fd63e4ba20709` | Provisional control principles do not verify bank signatures, monetary limits or approvers. |
| Shareholder treasury config | `4de78750a837c44cfd1ed06522931244d17e7410` | Informational buyback flag; actual Treasury operations deferred. |
| ShareClass seeder | `8d34ad2b15a8d3d6f9b17267d408ab4b0839a14c` | Both share classes are not treasury/buyback eligible in Phase 2A. |
| ShareClass migration | `e28ca992a1ad078afa365b6beb9060a98fa62b57` | Flag refers to share-class buyback eligibility, not cash management. |

### 14.2 Updated A2 decision and stage status

| Item | Recorded status |
|---|---|
| Frozen A1 v1.2.0 catalogue | PRESERVED |
| Owner-approved 30-domain dispositions | PRESERVED — 2 SUPPORTED / 11 PARTIAL / 6 UNVERIFIED / 11 MISSING |
| D12 | MISSING — PRESERVED |
| D15 / D17 | MISSING — PRESERVED |
| A2 overall | **PARTIAL — NOT VERIFIED-CLOSED** |
| Owner decision | **KEEP A2 OPEN — DO NOT CLOSE** |
| Residual exceptions accepted to close A2 | NO |
| R01–R06 | COMPLETE |
| R07 | SUBMITTED; Owner decision recorded; closure not approved |
| A3 implementation | NOT AUTHORIZED |
| PR #219 merge | NOT AUTHORIZED |
| A4 verification | NOT STARTED |
| A5 deployment | NOT AUTHORIZED |
| Tests/CI | NOT RUN |

### 14.3 Preservation and exact-head rule

This addendum is documentation-only on the existing PR #219 branch. The API `main` and App `main` snapshots above remain the source-of-truth baselines; neither branch is to be advanced by this work. The D12 addendum commit `b357e14942d35d8f5ff3d4669b0da8687fe7816b` is the PR branch head immediately before this report successor is added. The final PR head is recorded in live PR #219 metadata and its description after the successor report commit. The D12 baseline's full requirement-level traceability table is authoritative for its 18 source entries.

**End of controlled A2 closure report v1.0.2.**


---

## 15. Controlled A2-R08 D04/D05 risk and compliance reconciliation — v1.0.3

- **Authority:** Owner instruction to proceed with bounded read-only A2 reconciliation of D04 Enterprise Risk Management and D05 Compliance & Internal Control Management.
- **New controlled artifact:** `docs/architecture/ARNA-ESPPM-D04-D05-A2-TRACEABILITY-001-v1.0.0.md`.
- **Artifact blob:** `ae922a9d41460fa23bf5e7995dd4b3953a0c48f9`.
- **Artifact commit:** `e0aedcd13246e87a4c73a000f1ed6f03b8fe8de0`.
- **Inspected API main:** `6e274c25ecc55b84e026e879d735b77f0e51592f`.
- **Inspected App main:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`.
- **D04 requirement assessment:** 6 PARTIAL / 9 MISSING / 0 VERIFIED-CLOSED. PARTIAL denotes draft concepts only.
- **D05 requirement assessment:** 13 PARTIAL / 5 MISSING / 0 VERIFIED-CLOSED. PARTIAL denotes draft concepts only.
- **D04 disposition:** UNVERIFIED — PRESERVED. Draft risk references do not establish an accepted enterprise risk contract, approved appetite, risk register, residual-risk model or risk-acceptance authority.
- **D05 disposition:** UNVERIFIED — PRESERVED. PSC draft policy and provisional matrix do not establish an adopted compliance/internal-control contract, authoritative obligation register, verified committee mandate or operating effectiveness.
- **Source blobs:** PSC policy `0486e6f1870f52fa596460b1c5cc2d8c5f10b485`; provisional PSC matrix `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2`; API README `b2f730fc147347cb3f2cc3385fa962e036544897`; App README `e0a341263168f177e455f313d0ece04ff55eb839`.
- **R08 result:** COMPLETE — A2 evidence reconciliation only. No domain closure, disposition upgrade, residual-risk acceptance or A3 authorization.
- **Progress:** R01–R06 and R08 complete; R07 submitted with Owner decision recorded. R09 completes D01's remaining domain-level evidence/dependency reconciliation; all 30 domains now have explicit evidence/gap assessments, without changing any disposition. Disposition coverage remains 30/30.
- **Verification:** application tests and A4 scenarios NOT RUN; operating effectiveness NOT RUN; legal adoption and committee authority remain UNRESOLVED.
- **Change class:** documentation only on PR #219 branch. No API/App source, schema, migrations, seeders, runtime configuration, production data or main branch changed.
- **Owner decision:** KEEP A2 OPEN — DO NOT CLOSE (2026-11-24). PR merge, A3, A4 and A5 remain NOT AUTHORIZED.

### 15.1 R08 preservation and boundary

The D04/D05 baseline is a traceability candidate, not an approved A1 domain contract. Draft policy references are not adopted controls. D04 risk management remains distinct from D05 compliance and internal-control assurance. D06 Legal remains authoritative for legal interpretation; D03 Governance remains responsible for competent corporate appointments and authority records; D20 remains the records-platform owner; source domains retain their operational decisions.

No bank information or financial source records were requested or used in R08. The existing evidence-timing decision remains unchanged.

**End of controlled A2-R08 reconciliation addendum.**


---

## 16. Controlled A2-R09 final outstanding evidence and contract-gap reconciliation — v1.0.4

- **Authority:** Owner instruction to proceed with R09 final reconciliation while retaining the frozen 30-domain catalogue and every Owner-approved disposition.
- **New controlled artifact:** `docs/architecture/ARNA-ESPPM-A2-R09-RECONCILIATION-001-v1.0.0.md`.
- **Artifact blob:** `d41d20c0a47d313cf9c0bc4a1f35db2338644351`.
- **Artifact commit:** `92a0291422e0016d1631851ad14efded82aeca1a`.
- **D01 exact evidence baseline:** API main `6e274c25ecc55b84e026e879d735b77f0e51592f`; App main `ffe0495c0eb7c8b5d2013419123aac5b68893d51`; D01 evidence branch head `06483f578ab75e4a9824bc01e213abb0418c0288`; D01 traceability supplement v1.0.8 blob `12dbb73cef6fde2d3cf8889a6422dfaef0af6191`.
- **D01 checklist:** 16 PARTIAL / 7 MISSING / 1 UNRESOLVED / 0 VERIFIED-CLOSED. Enterprise D01 disposition remains UNVERIFIED.
- **Material D01/D02 boundary conflict:** subordinate D01 A1 draft v1.0.0 assigns D01 strategic-objective hierarchy ownership, while the frozen catalogue and D01 traceability assign authoritative enterprise objectives/hierarchy to D02. R09 records the controlling interpretation: D01 owns strategy and alignment references; D02 owns authoritative objectives, KPI definitions and targets. D01 subordinate A1 remains DRAFT and requires a controlled successor plus Owner review; R09 does not approve it.
- **Cross-domain source-owner reconciliation:** business-domain ownership is mapped for D02, D03, D04, D05, D06, D07, D08, D09, D11, D12, D20, D22, D23, D24, D25 and D26. Named individual custodians and accepted producer/consumer contract IDs/versions remain NOT ESTABLISHED where not supported by authoritative evidence.
- **Contract envelope:** canonical source ID/version, contract ID/version, tenant/legal-entity scope, effective dates, authority reference, freshness/revocation/deprecation, failure behavior and exact test evidence are required. No interface version is fabricated from document versions, migrations, namespaces or Git SHAs.
- **Legal/authority gaps:** adopted strategy/resolution, competent approval authority, current appointments/delegations and operative legal instrument identity remain UNRESOLVED.
- **R09 result:** COMPLETE — A2 reconciliation only. All 30 domains now have explicit evidence/gap assessments (30/30); this is not a claim that all contracts are approved, implemented or verified.
- **Preserved distribution:** 2 SUPPORTED / 11 PARTIAL / 6 UNVERIFIED / 11 MISSING. D01, D04 and D05 remain UNVERIFIED; D12, D15 and D17 remain MISSING. No disposition change.
- **Overall A2:** PARTIAL — NOT VERIFIED-CLOSED. Exit criteria C02–C08 remain partially met/not met as detailed in R09.
- **Tests/build/CI/positive-negative-failure acceptance:** NOT RUN. Operating effectiveness NOT RUN.
- **Change class:** documentation only on PR #219 branch. No API/App source, schema, migration, seeder, runtime configuration, production data or main branch changed.
- **Owner decision:** KEEP A2 OPEN — DO NOT CLOSE (2026-11-24). PR merge, A3, A4 and A5 remain NOT AUTHORIZED.

### 16.1 R09 handoff

The next action is exact-SHA evidence-index and exception-register revalidation across the open documentation branches and API/App main snapshots, followed by a closure-readiness reassessment only if all A2 exit criteria have evidence. The recorded Owner decision remains controlling and must not be overridden by work-package completion.

**End of controlled A2-R09 reconciliation addendum.**
