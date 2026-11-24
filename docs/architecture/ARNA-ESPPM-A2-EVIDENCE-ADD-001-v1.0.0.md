# ARNA ESPPM A2 Evidence Addendum 001 — Treasury, Procurement and Sales

**Document ID:** ARNA-ESPPM-A2-EVIDENCE-ADD-001  
**Version:** 1.0.2 (proposed controlled archival record; supplemental Treasury and mailbox source review)  
**Stage:** A2 — read-only reconciliation  
**Status:** Evidence assessment updated with supplemental D12 source review; Owner decision on revised dispositions pending  
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


## 10. Supplemental D12 Treasury source review — 2026-11-24

This section records additional Library and repository evidence located after the initial v1.0.0 draft. It supplements Section 3; it does not change the recommended D12 disposition.

### 10.1 Recovered hard-copy training manual — limited operational evidence

**Library document:** *MAALE ARNA S.C. — የግዥ፣ የሽያጭ፣ የቁጥጥር ኮሚቴ፣ የመዝገብ አያያዝና ሪፖርት አዘገጃጀት: የሃርድ ኮፒ ተግባራዊ ስልጠና ማኑዋል እና የሙከራ ቅፆች***; version 1.0.0; dated 18 September 2026.

The document describes itself as a hard-copy training package for practising procurement, sales, control-committee, records and reporting workflows before later conversion into MASES. It is not described as a Board/General Assembly-approved Treasury policy or bank mandate.

**Supporting content recovered:**
- General controls require traceable transaction references, segregation of duties, evidence before payment/approval, no backdating, and version control.
- Procurement flow includes budget/authority check, approval/award, PO/contract, receipt/inspection, invoice/payment package and filing.
- Procurement evidence sequence includes a payment voucher and proof of payment.
- Sales records link customer order, pricing/credit approval, invoice/receipt, delivery evidence and payment evidence.
- Form 06 is a payment-file checklist that includes approval and proof-of-payment evidence.

**Treasury limitation:** text search of the extracted manual content found no occurrence of the Amharic bank term (ባንክ) or the searched reconciliation term (ማስታረቅ). The reviewed content does not provide company bank-account ownership, bank mandates, signatories, payment execution limits, bank-statement procedures, or bank reconciliation rules. The manual is useful as operational training evidence for D15/D17 and payment-file traceability, not as D12 authority evidence.

### 10.2 Legacy MASES SQL export — cash-drawer controls, not bank authority

**Library file:** `maale_arna_smart_system(1).sql` (legacy system export; not the current ARNA database and not a verified current accounting record).

The extracted SQL contains:
- `cash_drawer` with a Main Cash Drawer at Head Office and opening/current balances recorded as zero in the exported row;
- `cash_transactions` fields for cash-in, cash-out and transfer, drawer/account references, status, creator, approver and paid-by metadata;
- `sp_approve_cash_transaction`, which checks that a transaction exists and is in draft status, then records `approved_by` and `approved_at`;
- `sp_cashier_close_session`, which computes expected drawer cash from opening balance and posted cash transactions, then compares it with the counted closing balance.

These are legacy cash-handling design artifacts. The approval procedure does not, by itself, prove the approver's corporate delegation, bank mandate, limit, or dual-signatory authority. The session-close calculation is a physical cash-drawer reconciliation, not a bank-statement reconciliation. Targeted searches within this SQL export did not find a `CREATE TABLE bank...` definition or a `bank_reconciliation` object.

**Disposition:** SUPPORTING / LEGACY ONLY. Do not migrate its user IDs, drawer balances, cash transactions, or approval semantics into current ARNA as authoritative opening balances, current cash positions, bank-account records, or payment authority.

### 10.3 Finance and Wallet architecture proposals — explicit design intent, still pending

- The Library-held `MASES-FIN-INT-001` G2 architecture proposal places Treasury in the operational-domain layer and proposes cash/bank registers, transfers, statement import and reconciliation. It states no live bank integration at G2; live integration is deferred pending provider/legal verification. Its status is PROPOSAL pending Owner approval.
- The Library-held Phase 2 Shareholder Charter v0.6.0 is explicitly DRAFT — NOT APPROVED. It describes Wallet as a statement-of-account ledger, not a bank account or outbound-transfer rail. Bank-transfer provider selection, merchant/collection account details, matching, statement format and reconciliation remain pending decisions/source documents.
- Repository CoA v1.0.0 proposes Finance/Treasury ownership for cash and bank control accounts, but accounting-policy/tax review and Owner approval remain pending; each control account needs a defined reconciliation owner and evidence schedule before adapter activation.
- The repository Finance A3.8 hybrid-controls baseline states source-domain control-total reconciliation is NOT IMPLEMENTED and reconciliation remains NOT_RECONCILED until authoritative source control totals and event versions are supplied.

These artifacts corroborate an architectural requirement and known evidence gaps. None is an adopted Treasury policy, a bank's executed mandate, an account ownership certificate, or evidence that bank reconciliation is operating.

### 10.4 Supplemental evidence disposition

| Evidence requirement | Supplemental result | Status |
|---|---|---|
| Current legal-entity bank-account ownership register | No primary account register, account-opening instrument or bank confirmation located in inspected sources | NOT LOCATED IN INSPECTED SCOPE |
| Current bank mandate and authorized signatories | No executed bank mandate, specimen-signature schedule or bank confirmation located | NOT LOCATED IN INSPECTED SCOPE |
| Payment initiation/approval/execution authority | Training/manual and legacy SQL show workflow concepts only; no current adopted authority matrix or bank execution mandate | UNRESOLVED |
| Approved cash-management/Treasury policy | Training manual is a paper-workflow training package; PSC policy is draft; Finance G2 is proposal | MISSING |
| Bank-statement reconciliation procedures and completed evidence | Legacy cash-drawer close exists; no bank-statement matching procedure, reconciliation worksheet or signed reviewer evidence located | NOT LOCATED IN INSPECTED SCOPE |
| Treasury-to-GL control totals and named reconciliation owner | CoA and Finance architecture identify proposed ownership/design; operational adapter/control totals remain absent | PARTIAL DESIGN ONLY; OPERATIONAL PROOF MISSING |
| Liquidity forecast and inter-entity transfer authority | Legacy forecast table and G2 proposal show concepts; no adopted liquidity/transfer policy or approval evidence located | UNRESOLVED |

### 10.5 D12 determination after supplemental review

**D12 remains MISSING at domain level.** The additional sources improve understanding of legacy cash-drawer workflow and the intended Treasury architecture, but do not close the primary evidence gaps.

No current bank account, bank mandate, signatory, payment limit, or cash/bank balance is asserted in this record. No bank authority may be inferred from legacy database fields, application roles, Finance/Treasury labels, training forms, or draft proposals.

To close the evidence gate, obtain and authenticate:
1. Current bank-account ownership/confirmation for each legal entity, including account holder name and account status.
2. Executed bank mandates and specimen-signature/authorized-user records, including effective dates and revocations.
3. Adopted Board/General Assembly resolutions and valid delegation instruments supporting the mandates and payment powers.
4. Approved Treasury/cash-management and payment-control policies, including initiation, verification, approval, execution, limits, dual control, emergency controls and revocation.
5. Bank statements and completed bank reconciliations for a defined sample period, with preparer/reviewer evidence, reconciling-item ageing and follow-up.
6. Cashbook and physical-cash count records, cash deposit slips, receipts and payment vouchers tied to source transactions and GL entries.
7. Approved liquidity forecasting, cash positioning and inter-entity transfer rules, if applicable.

**Control instruction:** continue to deny any unsupported payment, bank-signatory, transfer, reconciliation sign-off or period-close authority. Preserve legacy exports as historical leads only; do not treat them as current ARNA records.


## 11. Supplemental connected-mailbox search — 2026-11-24

A read-only search was performed in the connected Gmail mailbox for:
- bank mandate, bank signatory, authorized signatory and specimen signature;
- Maale Arna bank account, bank statement and bank reconciliation;
- attachments matching bank, statement, mandate or reconciliation;
- cash management and payment authorization in the Maale Arna / MASES context.

No relevant Maale Arna bank mandate, bank-account confirmation, statement, reconciliation record, or payment-authority email/attachment was returned. Two broad signatory-term matches were inspected and were unrelated (a NÚ MAALE administrative-register referral and an ORG-03 institutional-evidence request). The attachment-focused and Maale Arna bank-account searches returned no relevant threads.

**Scope limitation:** this is a search of the connected mailbox using the stated terms and indexed email content; it does not prove that no such records exist in another mailbox, offline archive, bank portal, paper file, or unindexed attachment. No message was sent, modified, or deleted.
