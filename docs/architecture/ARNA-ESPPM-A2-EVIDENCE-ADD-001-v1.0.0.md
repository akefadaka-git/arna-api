# ARNA ESPPM A2 Evidence Addendum 001 — Treasury, Procurement and Sales

**Document ID:** ARNA-ESPPM-A2-EVIDENCE-ADD-001  
**Version:** 1.0.0 (proposed controlled archival record)  
**Stage:** A2 — read-only reconciliation  
**Status:** Evidence assessment prepared; Owner decision on revised dispositions pending  
**Repository baseline:** `akefadaka-git/arna-api`  
**Base commit:** `40428db0a41d54397dd2aee482c9395f7950e9d0`  
**Prepared:** 2026-11-24  
**Scope:** D12 Treasury & Cash Management; newly recovered D15 Procurement & Supplier Management and D17 Sales, Marketing & Customer Relationship Management evidence  
**Change class:** Documentation only. No application, schema, migration, seeder, configuration, or production-data changes.

## 1. Purpose and preservation rules

This addendum records evidence recovered during the continued A2 read-only repository inspection. It supplements, and does not replace or silently amend:

- frozen A1 v1.2.0 and its separate archival PR #212;
- Owner-approved A2 decision package `ARNA-ESPPM-A2-DECISION-PACKAGE-001`;
- A2 decision-record draft PR #213 and its archival-accuracy review.

The approved A2 package remains the historical record of the Owner's original dispositions. This addendum presents evidence-based proposed status refinements for separate Owner decision. Until approved, the original A2 dispositions remain the operative approved dispositions.

No A3 authorization, merge authorization, migration authorization, A4 acceptance, or A5 deployment authorization is created by this addendum.

## 2. Repository baseline and evidence method

The inspection used GitHub repository file search and exact file retrieval against the recorded `main` baseline `40428db0a41d54397dd2aee482c9395f7950e9d0`. Search excerpts were followed by full-file retrieval where material. This was a source/document review only; no local tests, database inspection, workflow execution, live bank access, financial-statement sampling, or external confirmation was performed.

Evidence labels in this addendum:
- **VERIFIED IN REPOSITORY:** exact file/content was retrieved at the stated baseline.
- **SUPPORTING / LIMITED:** evidence exists but proves only the stated narrow behavior or design.
- **DRAFT / NOT ADOPTED:** document expressly awaits formal approval.
- **NOT LOCATED IN INSPECTED SCOPE:** targeted search did not identify the required evidence; this is not proof that it does not exist elsewhere.
- **UNRESOLVED:** evidence is insufficient to determine the authoritative rule or operational state.

## 3. D12 — Treasury & Cash Management

### 3.1 Evidence recovered

| Evidence | Exact repository reference | What it establishes | Limitation |
|---|---|---|---|
| Chart of Accounts register | `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php`; search result anchored to baseline commit `40428db0a41d54397dd2aee482c9395f7950e9d0` | Account categories include cash on hand (1110), bank accounts (1120), mobile-money/payment clearing (1130), and wallet settlement receivable (1140). | Accounting classification is not a Treasury operating model, bank mandate, cashbook, or reconciliation proof. |
| CoA v1.0.0 document | `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md`; blob `800af9f878b0c5f261904e07449b9f50dc78ad01` | Proposed Finance/Treasury ownership for cash and bank control accounts; each control account requires a defined reconciliation owner and evidence schedule before adapter activation. | Structural baseline is for continued review; accounting-policy/tax review remains pending Owner approval. No production activation is authorized. |
| Finance A3.8 hybrid controls baseline | `docs/finance/ARNA-FINANCE-A3.8-HYBRID-CONTROLS-BASELINE.md`; blob `80f5041774370f05dad7aa4396af8faef2b47959` | Finance integration health deliberately remains NOT_RECONCILED until authoritative source control totals and event versions are supplied. Source-domain control-total reconciliation is listed NOT IMPLEMENTED. | This is a Finance control baseline, not evidence of bank-statement reconciliation or Treasury operations. Independent accounting/security review and operational drills remain outstanding. |
| Finance settlement service | `mases_core/src/Domains/Finance/Services/GovernancePenaltySettlementService.php`; blob `21224b7f9c07357a838f12ce5a943957ba3c837f` | A narrow Governance penalty cash-settlement path uses an approved entity-scoped cash/bank ledger-account mapping, separates mapping creator/approver/executor, and posts an idempotent ETB settlement journal. | A specific penalty settlement workflow does not prove general cash management, payment authorization policy, bank integration, bank mandate, statement import, or reconciliation. |
| Finance settlement test | `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAccountingTest.php` | A feature-test source exists for balanced, idempotent cash settlement behavior. | Test source existence is not a test result. This A2 pass did not run it or verify current CI. |
| Provisional PSC control matrix | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`; blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Limited Owner-approved control principles: segregation of duties, evidence retention, conflict declarations, bounded delegation, audit trail, tenant scope, and denial of unverified authority. | Does not establish bank signatories, financial signing mandates, payment limits, or named approvers. |
| Document approval authority matrix | `docs/governance/document-approval-authority-matrix.md` | Explicitly states financial report/journal support/financial statement approval, posting, close, and sign-off authority require a separately approved Finance matrix; Governance grants do not authorize them. | Does not supply the missing Finance authority matrix. |
| Shareholder charter scaffold | `docs/PHASE_2_SHAREHOLDER_CHARTER.md`; blob `ca16db92b7fc1eb3da31f398120b22204e8716c7` | Wallet is described as a statement-of-account ledger, not a bank account, and is not an outbound transfer rail. Bank transfer integration and bank-statement reconciliation questions are marked pending. | Explicitly DRAFT — NOT APPROVED; it is not an adopted Treasury policy. |
| Historical legal reconciliation notes | `docs/legal/articles-of-association/HISTORICAL-RECONCILIATION-NOTES.md`; blob `856cd6afbcfd16f26b26c98d91850ebd82fcd29a` | Earlier source assessment records bank mandates and adopted procurement/sales policies as not located/verified at that time; original Articles binary remains unavailable for direct repository inspection. | Historical working notes, not legal proof; current availability must not be inferred from the historical statement. |

### 3.2 Targeted search result

Searches for Treasury, bank account, bank reconciliation, cash and bank, bank statement, cashbook, payment authorization, bank signatory, cash receipt, payment voucher, and liquidity recovered accounting classifications, a narrow penalty cash-settlement implementation, draft governance language, and pending wallet bank-transfer questions.

No standalone D12 Treasury domain contract, approved Treasury policy, verified bank mandate/signatory schedule, cash-management procedure, bank-statement reconciliation evidence, or Treasury-specific control-total adapter was identified in the inspected repository scope.

### 3.3 D12 disposition

**Recommended A2 status: retain MISSING for the D12 domain contract and authoritative Treasury operating controls.**

Supporting evidence is present in Finance, but it is not sufficient to upgrade D12 to PARTIAL as a domain contract. In particular, the cash/bank CoA rows and penalty-settlement workflow must not be mistaken for a complete Treasury capability.

Required evidence to resolve D12:
1. Approved Treasury/cash-management policy and domain contract, including scope and boundaries with Finance, Wallet, and operational domains.
2. Legal-entity bank-account register, account ownership, authorized users, and current bank mandates/signatory instruments.
3. Payment lifecycle and approval matrix, including initiation, verification, authorization, execution, dual control, limits, emergency handling, and revocation.
4. Cashbook and bank-statement procedures, reconciliation frequency, preparer/reviewer separation, stale-item handling, escalation, and evidence retention.
5. Cash forecasting/liquidity policy and inter-entity transfer rules, if applicable.
6. Source-to-ledger control totals, bank statement provenance, event versions, idempotency/reversal semantics, and reconciliation-owner assignments.
7. Current adopted Board/shareholder resolutions and competent authority evidence supporting any reserved financial powers.

Until these are verified, do not infer bank-signatory authority from application roles, a cashier/finance-manager label, a ledger mapping, a job title, or the provisional PSC matrix.

## 4. D15 — Procurement & Supplier Management

### 4.1 Evidence recovered

| Evidence | Exact repository reference | What it establishes | Limitation |
|---|---|---|---|
| Procurement, Sales & Control Governance Policy | `docs/governance/ARNA-GOV-POL-PSC-001.md`; blob `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft workflow covers requisition, budget/authority check, specifications, RFQ/tender, supplier register, evaluation, conflicts, award, PO/contract, receipt/inspection, invoice matching, payment, inventory/accounting update, and procurement file evidence. | Version 0.1.0 is explicitly a draft for formal corporate approval, not an adopted policy. Monetary thresholds, procurement methods, committee appointments, and reserved powers remain TBD. |
| Provisional PSC control matrix | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`; blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Limited control principles are Owner-approved provisionally. | Does not set procurement limits, committee authority, supplier award approvers, or bank-signatory powers. |
| P1.0 baseline implementation control contract | `docs/phase-1/P1.0-BASELINE-IMPLEMENTATION-CONTROL-CONTRACT.md` | Prior baseline assessment states no top-level procurement domain was observed and procurement order authorization/state transitions remained open. | Historical assessment is not a current full-tree implementation audit; it supports the gap, not a definitive claim of absence in all branches. |
| Finance accounting domain contract | `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md`; blob `a28040d3926973004675aaf5bbe0760cd5b4565a` | Defines procurement receipt, supplier invoice, payment, and credit note as future Finance posting events, after the procurement approval contract exists. | Candidate Finance contract is not Owner-approved; it explicitly defers procurement adapters until source contracts exist. |

### 4.2 D15 disposition

**Recommended A2 status: PARTIAL — supporting workflow/control evidence found; domain contract and authoritative business authority remain unresolved.**

The policy draft is useful evidence of a proposed end-to-end procurement workflow and record set. The Owner-approved provisional matrix supports cross-cutting controls. Neither source establishes an adopted procurement policy or executable procurement domain contract.

Outstanding evidence:
- formally adopted procurement policy and version/effective-date record;
- approved procurement methods and thresholds;
- authorized budget owners and monetary delegation schedule;
- current committee charter, membership, quorum, appointment and award authority;
- supplier onboarding/due diligence and conflict-management rules;
- PO/contract signing authority and amendment limits;
- receipt/inspection independence and three-way-match procedure;
- payment segregation and bank execution authority;
- procurement-specific data lifecycle, APIs/events, audit, reporting, and reconciliation contract.

## 5. D17 — Sales, Marketing & Customer Relationship Management

### 5.1 Evidence recovered

| Evidence | Exact repository reference | What it establishes | Limitation |
|---|---|---|---|
| Procurement, Sales & Control Governance Policy | `docs/governance/ARNA-GOV-POL-PSC-001.md`; blob `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft sales workflow covers customer/order records, price/discount authorization, credit approval, sales order, controlled invoice/receipt, delivery/service evidence, collections/receivables, ledger updates, returns/refunds/credit notes, and reconciliation. | Version 0.1.0 is explicitly a draft for formal corporate approval. Price/discount bands, credit limits, approvers, tax treatment, and collection authority remain unresolved. |
| Provisional PSC control matrix | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md`; blob `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Supports segregation of duties, conflict declarations, evidence retention, bounded delegation, tenant scope, audit trail, and denial of unsupported authority. | Does not approve pricing, discounts, credit, refunds, sales commitments, or customer disclosure authority. |
| Finance accounting domain contract | `mases_core/src/Domains/Finance/Documentation/finance-accounting-domain-contract.md`; blob `a28040d3926973004675aaf5bbe0760cd5b4565a` | Treats sales invoice, delivery/revenue recognition, receipt, and credit note as future Finance posting events; revenue recognition and tax treatment require approved policy. | Candidate contract is not Owner-approved and does not define the sales source-domain contract. |
| P1.0 baseline implementation control contract | `docs/phase-1/P1.0-BASELINE-IMPLEMENTATION-CONTROL-CONTRACT.md` | Prior baseline assessment states sales-order/payment/fulfilment reconciliation remained open. | Historical assessment, not a current full-tree implementation audit. |

### 5.2 D17 disposition

**Recommended A2 status: PARTIAL — supporting workflow/control evidence found; domain contract and authoritative commercial authority remain unresolved.**

The recovered draft provides a meaningful proposed lifecycle and evidence requirements, but it is not an adopted sales policy or proof of an implemented/verified sales domain.

Outstanding evidence:
- formally adopted sales/commercial policy;
- approved price list, discount bands, and exception approvers;
- credit policy, limits, terms, overdue/over-limit handling and write-off authority;
- tax and revenue-recognition policy, including delivery/service acceptance;
- customer onboarding, identity, privacy, consent, and data-retention requirements;
- sales order/invoice/receipt numbering and cancellation controls;
- collection/refund/return/credit-note approval and execution authority;
- sales-to-inventory, receivable, cash/bank, and GL reconciliation contracts;
- customer communication, CRM lifecycle, complaint and service ownership boundaries.

## 6. Proposed disposition delta

The following is a recommendation for a separate Owner decision. It does not silently alter the approved A2 decision package.

| Domain | Approved A2 package disposition | New evidence | Proposed revised disposition |
|---|---|---|---|
| D12 Treasury & Cash Management | MISSING | CoA cash/bank accounts, narrow governance penalty cash-settlement workflow, limited Finance controls; no standalone Treasury contract, approved bank mandate or bank reconciliation evidence located | **MISSING** — retain; evidence is adjacent Finance support, not a Treasury domain contract |
| D15 Procurement & Supplier Management | MISSING | Draft PSC policy with procurement lifecycle/evidence; Owner-approved provisional control matrix; Finance candidate contract defers procurement adapter pending source contract | **PARTIAL** — workflow/control evidence exists, but adoption, authority, domain contract and implementation verification remain unresolved |
| D17 Sales, Marketing & CRM | MISSING | Draft PSC policy with sales lifecycle/evidence; Owner-approved provisional control matrix; Finance candidate contract defers sales adapter pending source contract | **PARTIAL** — workflow/control evidence exists, but adoption, commercial authority, domain contract and implementation verification remain unresolved |

The original approved A2 package and PR #213 must retain their historical D12/D15/D17 values unless the Owner separately approves this proposed delta. If approved, record the decision as a controlled addendum; do not rewrite A1 or overwrite the original A2 decision record.

## 7. Cross-domain boundaries confirmed by this pass

- **D12 vs D11:** Treasury owns cash/bank operations and liquidity controls; Finance owns GL, accounting periods, journals, and financial reporting. Treasury-to-GL reconciliation must be explicit.
- **D12 vs D14 Wallet:** Wallet is a statement-of-account/obligation ledger in the recovered draft charter, not a bank account, custodian, or outbound payment rail. Wallet settlement and actual bank/cash movements require a separately approved integration and reconciliation contract.
- **D15 vs D11:** Procurement owns requisitions, sourcing, evaluation, award, PO/contract, and receipt evidence. Finance owns accounting treatment and ledger posting. Finance must not invent procurement approvals.
- **D17 vs D11:** Sales owns customer orders, commercial pricing/credit decisions, delivery, returns and customer-facing lifecycle. Finance owns accounting recognition/posting and ledger reporting under approved policy.
- **D15/D17 vs D12:** Procurement payment execution and sales collections touch cash/bank but do not confer Treasury account/signatory authority.

## 8. A2 acceptance and open evidence register

| ID | Required evidence | Current status |
|---|---|---|
| T-01 | Approved Treasury domain contract/policy | MISSING |
| T-02 | Legal-entity bank-account ownership register and bank mandates | NOT LOCATED IN INSPECTED SCOPE |
| T-03 | Current bank signatory/dual-authorization schedule | NOT LOCATED IN INSPECTED SCOPE |
| T-04 | Payment initiation/approval/execution authority matrix | UNRESOLVED |
| T-05 | Cashbook and bank-statement reconciliation procedures and evidence | NOT LOCATED IN INSPECTED SCOPE |
| T-06 | Cash forecasting/liquidity/inter-entity transfer policy | NOT LOCATED IN INSPECTED SCOPE |
| T-07 | Source control totals, bank statement provenance, and Treasury-to-GL reconciliation owner | PARTIAL DESIGN ONLY; OPERATIONAL PROOF MISSING |
| P-01 | Adopted procurement policy, methods, thresholds, and committee charter | DRAFT ONLY / UNRESOLVED |
| P-02 | Procurement authority/delegation and PO/contract signing schedule | UNRESOLVED |
| S-01 | Adopted sales policy, price/discount schedule and approvers | DRAFT ONLY / UNRESOLVED |
| S-02 | Credit policy/limits and write-off authority | UNRESOLVED |
| S-03 | Revenue/tax treatment and delivery/returns/collections authority | UNRESOLVED |

## 9. Scope, verification and approval statement

- This artifact is documentation-only and records source findings at the stated repository baseline.
- No tests or CI workflows were run; no test-pass claim is made.
- No external bank/provider, live bank account, or financial transaction was accessed or executed.
- No named individual was assigned Treasury, procurement, sales, payment, bank-signatory, or committee authority.
- No monetary threshold, approval limit, or legal power was inferred.
- A2 remains **PARTIAL — NOT VERIFIED-CLOSED**.
- A3 remains **NOT AUTHORIZED**.
- PR #212 and PR #213 remain separate archival proposals and must not be merged without separate Owner authorization.
- This addendum itself must remain unmerged until separately authorized.

**Requested Owner decision:** review and explicitly approve, reject, or amend the proposed D15/D17 disposition refinement and confirm D12 remains MISSING at domain level. Until then, the original approved A2 dispositions remain controlling.
