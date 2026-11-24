# ARNA ESPPM A2 Evidence-Timing Addendum 001

- **Document ID:** ARNA-ESPPM-A2-EVIDENCE-TIMING-ADDENDUM-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-24
- **Stage:** A2 — read-only reconciliation
- **Parent record:** ARNA-ESPPM-A2-CLOSURE-REPORT-001 v1.0.1
- **Purpose:** Separate pre-deployment specification and repository reconciliation from post-deployment intake of corporate and financial source records.
- **Change class:** Documentation / governance clarification only.
- **Authority:** Owner instruction to proceed with the corrected evidence sequence for D12, D15 and D17.
- **Controlling rule:** This addendum does not amend the frozen A1 catalogue, change an approved A2 domain disposition, accept a legal/financial authority, close A2, or authorize implementation, merge, A4 verification or A5 deployment.

---

## 1. Mandatory evidence-timing rule

Corporate and financial source records that the Owner can upload only after ARNA is deployed and running on the server **must not be requested as pre-deployment uploads**.

Before deployment, A2 work may:
1. Inspect existing repository, documentation and already-available evidence.
2. Define domain responsibilities, lifecycle requirements, conceptual records, authority-control requirements and cross-domain interfaces.
3. Identify source records required for later verification, with provenance and authenticity criteria.
4. Specify secure post-deployment intake, access, review, approval, audit and retention controls.
5. Record legal, financial and operational verification as deferred where the required primary source records are not yet available.

After deployment, the Owner may upload the deferred source records through ARNA's secured document/evidence workflow. Their authenticity, currency, scope and approval authority must then be independently checked. Upload alone is not verification.

**Do not convert a post-deployment evidence dependency into a pre-deployment upload blocker.** Conversely, do not claim that a deferred authority or operating control has been verified merely because its future intake workflow has been specified.

## 2. Status vocabulary

| Label | Meaning |
|---|---|
| PRE-DEPLOYMENT SPECIFIABLE | Requirements, ownership, controls and interfaces can be documented from current evidence without the deferred source records. |
| POST-DEPLOYMENT EVIDENCE DEFERRED | Primary source records are intentionally scheduled for secure intake after ARNA is deployed and running. |
| UNVERIFIED | The authoritative fact, instrument, appointment, approval or operational state has not yet been established. |
| DOMAIN DISPOSITION UNCHANGED | The Owner-approved A2 disposition remains controlling; this addendum is not a reclassification decision. |

## 3. D12 — Treasury & Cash Management

**Controlling A2 disposition: MISSING.**

### 3.1 Pre-deployment specification scope
- Define Treasury ownership of bank-account registers, mandates, liquidity, payment initiation, payment authorization, bank reconciliation and cash-position controls.
- Preserve the boundary with D11 Finance (GL, CoA, journals and accounting) and D14 Wallet (wallet obligations and payment attribution).
- Specify legal-entity and currency scoping, account lifecycle, payment maker/checker controls, approval thresholds, beneficiary controls, reconciliation matching, exception handling and audit requirements.
- Define source-owned Treasury events and the authoritative Finance posting/reconciliation interface.
- Define document classifications, metadata, provenance, access permissions, approval evidence and secure intake/review workflow.

### 3.2 Post-deployment evidence deferred
Where not already available in the inspected repository evidence, defer upload and verification of:
- Bank account ownership/confirmation records.
- Executed bank mandates and current signatory schedules.
- Board/GA or other competent-authority resolutions supporting account and payment authority.
- Bank statements, cashbook extracts, payment vouchers and reconciliation evidence.
- Evidence of bank-channel activation and actual payment/reconciliation operations.

### 3.3 Acceptance gates
1. **Specification gate:** Treasury domain contract and control matrix defined; D11/D14 boundaries explicit.
2. **Intake-readiness gate:** Secure document intake, least-privilege access, immutable provenance/audit, reviewer assignment and exception workflow specified.
3. **Post-deployment evidence gate:** Source records uploaded and authenticated; legal entity, account, currency, effective dates and signatories reconciled.
4. **Operational acceptance gate:** Payment authorization and bank reconciliation tested against verified mandates and controlled test evidence.

**Current result:** Treasury domain contract MISSING; primary authority and bank-operation evidence POST-DEPLOYMENT EVIDENCE DEFERRED / UNVERIFIED. This addendum does not upgrade D12.

## 4. D15 — Procurement & Supplier Management

**Controlling A2 disposition: MISSING.** The proposed PARTIAL upgrade in PR #215 remains rejected under the explicit Owner decision recorded in A2-R06.

### 4.1 Pre-deployment specification scope
- Define procurement planning and requisition intake, sourcing method, solicitation, bid/evaluation, award, purchase order, receipt/acceptance, supplier performance, disputes and supplier offboarding.
- Define procurement categories, thresholds, approval routing, segregation of duties, conflict-of-interest declarations, competitive-bidding controls, emergency procurement exceptions and audit trail.
- Define supplier master-data ownership, due diligence, beneficial-owner/identity evidence requirements, tax/licence/bank-detail verification and controlled supplier status.
- Define interfaces: D15 owns sourcing/award/supplier lifecycle; D16 owns inventory receipt/stock; D11 owns accounting and payables; D12 owns payment execution and bank settlement; D20 owns controlled records/evidence.
- Define source-owned event contracts and idempotent hand-off requirements; a purchase order, receipt or invoice must not itself be treated as proof of payment.

### 4.2 Post-deployment evidence deferred
Where source documents are not already available for A2 inspection, defer secure upload and verification of:
- Adopted procurement policy, procedures and current approval/threshold schedules.
- Competent-authority resolutions and current procurement/delegation appointments.
- Supplier registration, licence, tax and due-diligence records.
- Tender/quotation, evaluation, award, contract, purchase-order, goods-received and invoice source records.
- Conflict-of-interest declarations, exception approvals and supplier-performance evidence.

### 4.3 Acceptance gates
1. **Specification gate:** Procurement lifecycle, roles, thresholds as configurable policy, separation of duties and D11/D12/D16/D20 interfaces specified.
2. **Policy-authority gate:** Adopted procurement policy and current authority schedule uploaded and authenticated after deployment where deferred.
3. **Supplier-evidence gate:** Supplier identity, eligibility, licence/tax status and bank-detail change controls verified against source records.
4. **Operational acceptance gate:** Controlled requisition-to-award-to-receipt-to-invoice traceability and exception/audit tests completed using authorized evidence.

**Current result:** Procurement domain contract MISSING; adopted policy, current authority and supplier/source-record verification POST-DEPLOYMENT EVIDENCE DEFERRED where not already available. This addendum does not upgrade D15.

## 5. D17 — Sales, Marketing & Customer Relationship Management

**Controlling A2 disposition: MISSING.** The proposed PARTIAL upgrade in PR #215 remains rejected under the explicit Owner decision recorded in A2-R06.

### 5.1 Pre-deployment specification scope
- Define lead and opportunity intake, qualification, customer/account lifecycle, quotation, pricing, discount approval, order/contract acceptance, fulfilment hand-off, invoicing, complaints, returns, refunds and customer relationship history.
- Define approved price-list ownership, discount/credit/refund authority, tax treatment, customer due diligence where applicable, contract approval, sales-channel controls and exception escalation.
- Define customer-data purpose, consent/legal basis where applicable, access boundaries, retention and audit requirements.
- Define interfaces: D17 owns commercial/customer lifecycle; D18 owns fulfilment/service delivery; D11 owns invoices, revenue recognition and accounting; D12 owns bank settlement; D14 owns wallet payment obligations and attribution; D20 owns controlled commercial records.
- Define source-owned events and reconciliation references. A quotation/order is not an invoice, a wallet payment is not bank settlement, and a refund authorization is not proof of a completed cash movement.

### 5.2 Post-deployment evidence deferred
Where source documents are not already available for A2 inspection, defer secure upload and verification of:
- Adopted commercial, sales, marketing and customer-management policies.
- Current approved price lists, discount bands, credit limits and refund/return authority schedules.
- Competent-authority resolutions and current commercial delegation/appointment records.
- Executed customer contracts, approved quotations, invoices, credit notes, refund approvals and source transaction evidence.
- Tax, licence or other regulatory records applicable to the actual products/services and channels.

### 5.3 Acceptance gates
1. **Specification gate:** Commercial lifecycle, customer-data controls, approval points and D11/D12/D14/D18/D20 boundaries specified.
2. **Policy-authority gate:** Adopted commercial policy and current pricing/discount/credit/refund authority evidence uploaded and authenticated after deployment where deferred.
3. **Transaction-evidence gate:** Customer, contract, price, tax, invoice, payment and refund references reconciled to authoritative source records.
4. **Operational acceptance gate:** Controlled lead-to-order-to-fulfilment-to-invoice-to-settlement/refund traceability and exception/audit tests completed.

**Current result:** Sales/CRM domain contract MISSING; adopted commercial policy, authority schedules and source-transaction verification POST-DEPLOYMENT EVIDENCE DEFERRED where not already available. This addendum does not upgrade D17.

## 6. Cross-domain evidence and control principles

1. A domain owns its business decision and source records; consuming domains must not manufacture or overwrite that authority.
2. D11 Finance records accounting consequences from validated, source-owned events; it does not establish procurement, sales, Treasury or supplier authority.
3. D12 owns bank mandates, payment execution and bank reconciliation; D14 wallet allocation is not bank settlement.
4. D15 procurement award and D16 receipt are distinct events; D11 accounting and D12 payment are separate downstream controls.
5. D17 commercial order, D18 fulfilment, D11 invoice/accounting and D12/D14 settlement/payment attribution are distinct lifecycle events.
6. Evidence records must preserve source identity, issuer, legal entity, effective date, version, upload actor/time, reviewer, verification outcome and immutable audit history.
7. A document's presence or upload status is not equivalent to approval, authenticity, legal effect or current validity.
8. Post-deployment verification must be performed by authorized reviewers with appropriate segregation of duties; no self-approval should be inferred.

## 7. Consolidated evidence-timing register

| Domain | Approved A2 disposition | Pre-deployment work | Post-deployment dependency | Authority verified now? |
|---|---|---|---|---|
| D12 Treasury | MISSING | Contract, controls, interfaces and intake workflow specification | Bank ownership, mandates, signatories, statements and reconciliations | NO |
| D15 Procurement | MISSING | Lifecycle, sourcing, supplier controls, thresholds and interfaces specification | Adopted policy/authority and supplier/tender/award/receipt source records where deferred | NO |
| D17 Sales/CRM | MISSING | Commercial lifecycle, customer controls, pricing/refund rules and interfaces specification | Adopted policy/authority, price schedules and transaction source records where deferred | NO |

## 8. Governance and stage status

- Frozen A1 v1.2.0 catalogue: PRESERVED.
- Owner-approved A2 dispositions: PRESERVED; D12, D15 and D17 remain MISSING.
- D01 remains UNVERIFIED.
- A2 overall: PARTIAL — NOT VERIFIED-CLOSED.
- A2-R06: COMPLETE as previously recorded; this addendum clarifies evidence timing and does not reopen its disposition decisions.
- A2-R07: remains submitted for Owner review; this addendum is supplemental evidence-timing guidance, not an Owner closure decision.
- A3 implementation: NOT AUTHORIZED.
- A4 verification: NOT STARTED.
- A5 deployment: NOT AUTHORIZED.
- PR merge: NOT AUTHORIZED.
- Tests/CI: NOT RUN; documentation-only record.
- No application source, schema, migrations, seeders, runtime configuration or production data changed.

**Final control:** No request for the Owner to upload corporate or financial primary source records is made by this addendum before ARNA is deployed and running on the server. The records are deferred to the secure post-deployment intake and verification process.
