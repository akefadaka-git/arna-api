# ARNA ESPPM D15 / D17 Domain-Contract Requirements and A2 Acceptance Baseline

- **Document ID:** ARNA-ESPPM-D15-D17-A2-CONTRACT-BASELINE-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-24
- **Stage:** A2 — read-only specification and reconciliation
- **Catalogue:** ARNA ESPPM A1 v1.2.0 (frozen 30-domain catalogue)
- **Parent records:** ARNA-ESPPM-A2-CLOSURE-REPORT-001 v1.0.1; ARNA-ESPPM-A2-EVIDENCE-TIMING-ADDENDUM-001 v1.0.0
- **Repository evidence baseline:** API main `6e274c25ecc55b84e026e879d735b77f0e51592f`; App main `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Change class:** Documentation / requirements baseline only
- **Disposition authority:** This document proposes requirements and acceptance criteria. It does not amend Owner-approved A2 domain dispositions or constitute an approved A1 domain specification.

---

## 1. Executive status and evidence discipline

| Domain | Controlling A2 disposition | Contract status | Specification result |
|---|---|---|---|
| D15 Procurement & Supplier Management | **MISSING** | No accepted D15 domain contract identified in the inspected current-main evidence | Requirements and acceptance baseline proposed |
| D17 Sales, Marketing & Customer Relationship Management | **MISSING** | No accepted D17 domain contract identified in the inspected current-main evidence | Requirements and acceptance baseline proposed |

The current-main evidence contains a draft PSC policy, a provisional control matrix, implementation strategy material and Finance accounting references. These support bounded requirements discovery; they do **not** establish accepted D15/D17 domain contracts or adopted commercial/procurement policy.

### 1.1 Authority and evidence rules

1. The PSC policy v0.1.0 is explicitly a draft for formal corporate approval. Its source training manual is not proof of an adopted corporate directive.
2. The Owner-approved PSC matrix is approved only as a provisional control foundation: segregation of duties, evidence retention, conflict declarations, bounded delegation, audit trail, tenant scope and denial of unverified authority.
3. The matrix does not establish legal powers, committee appointments, quorum, monetary limits, bank signatures or specific approvers.
4. Proposed ETB thresholds in draft materials are not approved authority and must not be encoded.
5. The document approval authority register requires source-backed authority rules and default-denies approval/signature/publication when authority is missing, ambiguous, expired or out of scope.
6. Corporate and financial primary source records unavailable until ARNA is deployed and running on the server remain post-deployment evidence dependencies. They are not pre-deployment upload requests or reasons to stop bounded requirements specification.

## 2. D15 — Procurement & Supplier Management

### 2.1 Purpose and bounded ownership

D15 owns the controlled acquisition lifecycle from internal demand and procurement planning through sourcing, evaluation, award, supplier relationship, purchase commitment and procurement-file completion. It owns procurement decisions and supplier lifecycle records within the authority actually delegated to the relevant actors.

D15 does **not** own:
- Enterprise budget approval or financial accounting (D11 Finance / relevant budget owner).
- Bank accounts, payment execution, mandates or bank reconciliation (D12 Treasury).
- Stock custody, inventory balances, stock movements or asset registers (D16 Inventory; D19 Assets where applicable).
- Corporate committee creation, appointments, quorum or delegation authority (D03 Governance).
- Canonical document bytes, retention/legal hold and enterprise records policy (D20 Documents & Records).
- The supplier's legal identity or external registry truth; ARNA stores evidence and verification outcomes, not the external authority itself.

### 2.2 Required business capabilities

**D15-RQ-01 — Procurement planning and demand intake**
- Capture requesting legal entity, requesting unit, requestor, business need, specification, quantity/unit, required date, delivery location and supporting evidence.
- Link to an approved budget/cost-centre reference where such a budget contract exists.
- Prevent requisitions from silently becoming purchase commitments.

**D15-RQ-02 — Requisition lifecycle**
- Maintain versioned requisitions with submit, return-for-revision, approve, reject, cancel and close outcomes.
- Preserve actor, timestamp, reason, evidence, prior state and revision history.
- Enforce tenant/legal-entity ownership and prevent cross-entity references without an explicit authorized intercompany or shared-service basis.

**D15-RQ-03 — Procurement method and competition**
- Support a policy-configurable procurement method, including quotation/tender and documented permitted alternatives.
- Store solicitation issue/response dates, supplier invitations, submitted quotations/bids, validity, currency, tax components, delivery, quality/technical compliance, warranty and payment terms.
- Preserve evaluation criteria, evaluator identities, scoring/assessment evidence, recommendation and reasoned award decision.
- Do not hard-code monetary thresholds or method-selection bands until supported by a current adopted policy and authority instrument.

**D15-RQ-04 — Supplier master and due diligence**
- Maintain supplier identity, legal/trading names, registration/tax identifiers where applicable, contacts, addresses, status, risk/due-diligence status and source evidence references.
- Track verification method, source issuer, verification date, reviewer, expiry/renewal and unresolved exceptions.
- Treat supplier bank-detail changes as sensitive, independently verified changes; never infer validity from an invoice or an email alone.
- Maintain duplicate detection and controlled merge/supersession without deleting historical transaction references.

**D15-RQ-05 — Conflict of interest and supplier integrity**
- Capture declarations from requestors, evaluators, approvers and committee members as required by the approved control policy.
- Record recusal, access restriction, alternate reviewer and independent decision.
- Preserve evidence of competition, sole-source/emergency justification and post-event review where the approved policy permits an exception.

**D15-RQ-06 — Evaluation, award and commitment**
- Separate evaluation recommendation from award approval and contract/PO signature.
- Bind award and commitment approval to a source-backed authority rule with transaction type, legal entity, scope, currency/value basis, effective dates, expiry/revocation, required quorum/signatures and evidence.
- A committee or job title is not authority by itself.
- If authority is missing, ambiguous, expired or conflicting, place the transaction in a blocked/held state; do not fabricate an approver or threshold.

**D15-RQ-07 — Purchase order and contract**
- A PO/contract must reference the approved requisition and award; identify supplier, legal entity, items/services, quantity, unit, currency, price, tax basis, delivery/acceptance terms, payment terms, contract dates and authorized signatories.
- Changes to scope, value, quantity, currency, delivery or duration require versioned change control and the applicable re-approval.
- Prevent duplicate or conflicting active commitments against the same source award unless explicitly permitted and evidenced.

**D15-RQ-08 — Receipt and inspection hand-off**
- D15 coordinates procurement evidence but does not certify inventory custody.
- Record delivery reference and expected items/services; route goods receipt and stock acceptance to D16 and service acceptance to the owning service domain.
- Preserve partial receipt, rejection, shortage, damage, quality exception and return-to-supplier evidence.

**D15-RQ-09 — Invoice validation and Finance hand-off**
- Support PO/contract–receipt/acceptance–invoice matching, including documented alternative matching rules for services or justified exceptions.
- Detect duplicate invoices, quantity/price variances, supplier identity changes and tax-document gaps.
- D15 supplies validated procurement references; D11 owns payable recognition, accounting policy and journal posting.
- D15 must not directly create or alter GL journals.

**D15-RQ-10 — Payment hand-off and closure**
- Payment authorization/execution belongs to the applicable Finance/Treasury process and must use verified payment authority.
- A payment request, voucher or remittance advice is not proof of bank settlement.
- Close the procurement file only when required commitment, receipt/acceptance, invoice, accounting/payment references and unresolved exceptions are dispositioned.

**D15-RQ-11 — Supplier performance and lifecycle**
- Record delivery quality, timeliness, service-level performance, disputes, corrective action, suspension, reinstatement and offboarding.
- Supplier suspension or debarment requires a sourced rule, authorized decision, due process and effective dates; do not infer a sanction from an adverse note.

**D15-RQ-12 — Reporting and records**
- Provide traceability by legal entity, department, supplier, method, status, category, value/currency, budget reference, exception and period.
- Preserve complete procurement file and evidence references under D20 retention/legal-hold rules.

### 2.3 D15 conceptual record classes (not database design)

- ProcurementPlan / ProcurementRequest
- Requisition / RequisitionRevision
- ProcurementMethodDecision
- Solicitation / SupplierInvitation / BidOrQuotation
- Evaluation / EvaluationCriterion / EvaluatorAssessment
- ConflictDeclaration / Recusal
- Supplier / SupplierVerification / SupplierStatusHistory
- AwardDecision / AuthoritySnapshot
- PurchaseOrder / ContractReference / CommitmentAmendment
- ReceiptReference / InspectionOutcome / ServiceAcceptanceReference
- InvoiceMatch / Variance / Exception
- PaymentRequestReference / FinancePostingReference
- SupplierPerformanceReview / CorrectiveAction
- ProcurementFile / EvidenceReference / AuditEvent

These names are conceptual record classes only. No schema, migration, model, route, permission or implementation is approved by this A2 document.

### 2.4 D15 state and invariants

Indicative lifecycle: Draft → Submitted → Authority/Budget Review → Sourcing → Evaluation → Award Pending Approval → Award Approved → PO/Contract Issued → Partially/Fully Received → Invoice Matched/Exception → Finance/Treasury Processing → Completed / Cancelled / Disputed.

The final state machine, transition names and authority routing require a separately approved D15 A1 contract. The following invariants are required:
- No self-approval; incompatible duties are separated or an approved independent compensating control is recorded.
- No award or commitment without a verified authority snapshot.
- No silent mutation of submitted/approved records; revisions and amendments are versioned.
- No receipt is inferred from an invoice or PO.
- No payment or settlement is inferred from a PO, invoice, payment request or Wallet allocation.
- No unsupported supplier verification or status.
- All records and references are tenant/legal-entity scoped; cross-scope access fails closed.
- All exceptions retain reason, authority, evidence and independent review.

## 3. D17 — Sales, Marketing & Customer Relationship Management

### 3.1 Purpose and bounded ownership

D17 owns customer/account relationship records and the commercial lifecycle from lead/opportunity through quotation, order acceptance, commercial terms, customer account relationship, sales returns/refunds initiation and commercial performance reporting.

D17 does **not** own:
- Product/service definition, product lifecycle or innovation governance (D28).
- Operational fulfilment/service delivery and SLA execution (D18).
- Inventory custody, stock reservation and stock movement (D16).
- Accounting, tax policy, invoice posting, revenue recognition or receivables ledger (D11 Finance).
- Bank settlement/payment execution and bank reconciliation (D12 Treasury).
- Wallet account balances, payment rails, allocation and reversals (D14 Wallet).
- Enterprise communication consent/privacy policy or canonical records retention (relevant D21/D20 owners).

### 3.2 Required business capabilities

**D17-RQ-01 — Customer and account records**
- Maintain customer/person/organization account references, contact points, addresses, customer type, legal-entity relationship, lifecycle status and source evidence where applicable.
- Link to canonical People/Organization identity records instead of creating duplicate identity masters.
- Enforce lawful purpose, minimum necessary personal data, role-based access, retention and audit.
- Record verification status and provenance; do not represent an unverified customer as legally verified.

**D17-RQ-02 — Lead and opportunity lifecycle**
- Capture lead source, customer need, product/service interest, owner, expected value/currency, probability, expected decision date, next action and qualification outcome.
- Maintain versioned opportunity stages, reasons for win/loss/hold and relationship history.
- A lead or opportunity is not an order, contract, receivable or recognized revenue.

**D17-RQ-03 — Product/service catalogue reference**
- Consume active, approved product/service identifiers and terms from the authoritative product/service owner (D28 or other designated domain).
- Do not create duplicate product/service master data or publish unavailable/unapproved offers.
- Preserve catalogue version and effective date on quotation/order snapshots.

**D17-RQ-04 — Price lists and commercial terms**
- Support versioned price lists by legal entity, product/service, currency, customer segment/channel and effective period.
- Store list price, permitted discount structure, tax treatment reference, delivery/validity terms and price source.
- Do not invent or activate price, discount or commission authority from job titles, draft tables or historical examples.
- A price-list version must be approved under a source-backed authority rule before use in a binding quotation/order.

**D17-RQ-05 — Quotation**
- Create versioned quotations linked to customer, catalogue version, quantities, currency, price, discount, tax determination reference, validity, delivery and payment terms.
- Recalculate or invalidate a quotation when its catalogue/price/tax basis changes; preserve the originally issued snapshot.
- Approval of a quotation must be separate from its preparation and from contract/order acceptance where required.

**D17-RQ-06 — Discount and exception approvals**
- Record requested discount amount/percentage, reason, commercial impact, customer/order reference, approver and effective authority snapshot.
- Deny discounts beyond approved scope or where the authority rule is missing/expired.
- No ETB or percentage threshold may be configured from unapproved drafts.

**D17-RQ-07 — Credit and payment terms**
- If credit sales are permitted, record credit assessment, limit, terms, due date, exposure, decision evidence and approver under an approved credit policy.
- Until an adopted credit policy and authority schedule are verified, credit approval remains blocked; no default credit limit is inferred.
- Cash/prepayment workflows must distinguish payment initiation, Wallet attribution and bank settlement.

**D17-RQ-08 — Order and contract acceptance**
- Convert a quotation into an order only with explicit customer acceptance and required internal commercial authority.
- Bind order to customer, legal entity, product/service catalogue snapshot, price/discount/tax reference, quantity, currency, terms, delivery/fulfilment conditions and contract evidence.
- Preserve order amendments, cancellations, holds and disputes with reason and approval history.

**D17-RQ-09 — Fulfilment and delivery hand-off**
- Send authorized order requirements to D18 for service fulfilment and/or D16 for inventory availability and goods issue.
- D17 may track fulfillment status but must not certify service completion, delivery or stock movement without the owning domain's evidence.
- Partial fulfilment, substitutions, short delivery, customer acceptance and failed delivery must be represented distinctly.

**D17-RQ-10 — Invoice, receipt and Finance hand-off**
- Request invoice issuance from D11 based on the approved order and fulfilment evidence required by accounting policy.
- D11 owns invoice numbering, tax determination/accounting, receivables and revenue recognition.
- D17 may display invoice status and customer-facing references but must not post journals or alter receivable balances.

**D17-RQ-11 — Collections and payment attribution**
- Link customer payment evidence to the correct customer/order/invoice.
- D14 owns wallet payment records and allocation; D12 owns bank settlement/reconciliation; D11 owns accounting.
- Unapplied receipts, partial payments, reversals and disputes must remain visible and must not be silently allocated.
- A Wallet payment or allocation is not proof of bank settlement.

**D17-RQ-12 — Returns, refunds and credit notes**
- Link return/refund requests to the original sale/order/invoice/payment and capture reason, evidence, quantity/service issue, approval and disposition.
- D16 owns physical inventory return disposition; D18 owns service remediation; D11 owns credit note/accounting treatment; D12/D14 own the actual payment/Wallet reversal according to their contracts.
- Never erase a posted sale or receipt; use controlled credit note, reversal or correction with immutable linkage.

**D17-RQ-13 — Customer service and relationship history**
- Track complaints, enquiries, follow-up, commitments and resolution references; route operational cases to D18 and formal communication to D21 as applicable.
- Restrict sensitive customer data by purpose and authorized role.

**D17-RQ-14 — Reporting and records**
- Provide pipeline, conversion, quotation aging, order backlog, realized sales (from authoritative Finance data), discounts, returns, customer retention and receivables indicators.
- Distinguish operational pipeline measures from posted financial results.
- Preserve commercial evidence and retention/legal-hold references through D20.

### 3.3 D17 conceptual record classes (not database design)

- CustomerAccountReference / ContactPoint
- Lead / Opportunity / OpportunityStageHistory
- ProductServiceCatalogueReference / CatalogueSnapshot
- PriceList / PriceListVersion / CommercialTerms
- Quotation / QuotationRevision / QuotationApproval
- DiscountRequest / DiscountDecision / AuthoritySnapshot
- CreditAssessmentReference / CreditDecision
- SalesOrder / OrderRevision / CustomerAcceptance
- ContractReference / OrderCancellation / Dispute
- FulfilmentRequestReference / DeliveryAcceptanceReference
- InvoiceReference / ReceiptReference / PaymentAllocationReference
- ReturnRequest / RefundDecision / CreditNoteReference
- CustomerCase / FollowUp / RelationshipEvent
- CommercialEvidenceReference / AuditEvent

Conceptual classes only; no schema, migration, model, route, permission or implementation is approved.

### 3.4 D17 state and invariants

Indicative lifecycle: Lead → Qualified Opportunity → Quotation Draft → Approval (where required) → Quotation Issued → Customer Accepted → Order Confirmed → Fulfilment Pending/In Progress/Partially Fulfilled/Fulfilled → Invoice Requested/Issued → Payment Pending/Partially Paid/Paid/Disputed → Completed, with separate Cancelled, Returned, Refunded and Closed-with-exception paths.

The final state machine requires a separately approved D17 A1 contract. Required invariants:
- No quotation/order uses an inactive or unapproved catalogue/price-list version.
- No discount, credit, contract or refund approval without verified authority.
- Customer acceptance and internal approval are distinct events.
- No delivery/fulfilment is inferred from an invoice or order status.
- No journal, receivable or bank settlement is created by D17.
- No refund is completed by changing or deleting the original sale.
- Customer data access is tenant-scoped, purpose-limited and audited.
- Every amendment preserves the previous version and authority/evidence snapshot.

## 4. Cross-domain producer/consumer contracts

| Flow | Source owner | Consumer | Required hand-off / invariant |
|---|---|---|---|
| Procurement requisition/award/PO | D15 | D11, D16, D20 | Source-owned immutable business reference, legal entity, currency, effective version and authority evidence |
| Goods receipt/stock movement | D16 | D15, D11 | D16 certifies quantity/condition and inventory movement; D15 cannot fabricate receipt |
| Service acceptance | D18 / service owner | D15, D11 | Service owner certifies delivery; D15 records procurement linkage |
| Supplier invoice / payable | D15 supplies validated procurement references; D11 owns accounting | D11 | Match PO/contract, receipt/service acceptance and invoice; duplicate detection and exception quarantine |
| Supplier payment | D11/D12 according to payment workflow | D15 status view | Payment status only from authoritative Finance/Treasury reference; no PO/invoice implication of settlement |
| Lead/opportunity/quotation/order | D17 | D18, D16, D11, D20 | Commercial source reference, customer/legal entity, catalogue and price snapshot, approval evidence |
| Fulfilment/delivery | D18 / D16 | D17, D11 | Owning domain supplies fulfillment/delivery evidence |
| Invoice/receivable/revenue | D11 | D17 | D11 owns invoice, tax/accounting and posted financial status |
| Wallet payment/allocation | D14 | D17, D11 | Wallet owns payment and allocation references; allocation is not bank settlement |
| Bank settlement/reconciliation | D12 | D11, D17, D15 | Treasury owns bank evidence and settlement result |
| Customer communications | D21 | D17 and customer-facing channels | D21 owns communication delivery/preferences where applicable; delivery is not commercial approval |
| Evidence and retention | D20 | D15, D17 and all linked domains | Canonical evidence reference, version, hash/provenance, access and retention/legal hold |

All cross-domain references must be server-resolved and validated for tenant/legal-entity scope. A foreign identifier supplied by a client is not proof of ownership or authorization. Unsupported source types must fail closed; Finance's existing source enum includes procurement-related values, but enum presence is not proof of an active D15 adapter or end-to-end posting.

## 5. Shared authority and security contract

The following are mandatory requirements for both domains:
1. Authority rules must be explicit, versioned and sourced to a valid instrument; include action, transaction type, legal entity, scope, value/currency basis where applicable, effective period, expiry/revocation, required signatories/quorum and evidence.
2. RBAC permission is necessary but not sufficient for legal/business approval. A role label, title, uploader identity, document creator or UI visibility does not prove authority.
3. Missing, ambiguous, expired, revoked or out-of-scope authority must deny approval and hold the affected transition.
4. Separate prepare, recommend, approve, execute/receive, record and independent-review duties. Where staffing constraints require a compensating control, record the independent reviewer and basis.
5. Conflict declarations and recusals must affect the decision eligibility and be auditable.
6. Tenant and legal-entity isolation must be enforced server-side at every read/write/reference boundary.
7. Approved records are immutable in history; corrections use versioned amendments, reversal or superseding records.
8. All approval snapshots bind to the exact submitted record revision and evidence version.
9. Audit events record actor, tenant/entity, action, before/after state, timestamp, reason, authority reference, evidence reference and correlation/idempotency reference.
10. No business approval threshold, tax treatment, credit limit, supplier eligibility or public claim may be inferred from draft materials or seeded content.

## 6. A2 acceptance criteria — requirements/specification review

These criteria determine whether the D15/D17 requirements baseline is sufficiently specified for Owner review. They do not constitute A4 software acceptance.

### 6.1 D15 specification acceptance

| ID | Acceptance criterion | Evidence required at A2 | Current assessment |
|---|---|---|---|
| D15-A01 | Purpose, scope, exclusions and source ownership are explicit | This baseline and frozen catalogue | PROPOSED |
| D15-A02 | Requisition-to-procurement-file lifecycle and state ownership are defined | Lifecycle requirements and transition invariants | PROPOSED |
| D15-A03 | Sourcing, quotation/tender evaluation and exceptions are defined without invented thresholds | Policy draft reviewed; method/threshold authority explicitly unresolved | PARTIAL |
| D15-A04 | Supplier identity, due diligence, status and bank-detail change controls are defined | Supplier requirements and evidence provenance rules | PROPOSED |
| D15-A05 | Authority, committee, conflict and segregation controls are explicit and fail closed | Owner-approved provisional matrix and authority register | SUPPORTED AS CONTROL DESIGN ONLY |
| D15-A06 | PO/contract, receipt, invoice and payment boundaries are explicit | PSC policy draft and D11/D12/D16 boundaries | PROPOSED |
| D15-A07 | D11/D12/D16/D20 producer-consumer interfaces and immutable references are defined | Finance source contract/integration evidence and this matrix | PARTIAL |
| D15-A08 | Tenant/entity isolation, audit, versioning and idempotency requirements are explicit | Existing platform/security contracts; domain acceptance remains future | PROPOSED |
| D15-A09 | Reporting and records/retention requirements are defined | PSC draft and D20 ownership boundary | PROPOSED |
| D15-A10 | A3 entry gates and unresolved evidence dependencies are explicit | This baseline and evidence-timing addendum | PROPOSED |

### 6.2 D17 specification acceptance

| ID | Acceptance criterion | Evidence required at A2 | Current assessment |
|---|---|---|---|
| D17-A01 | Purpose, scope, exclusions and customer/product ownership are explicit | This baseline and frozen catalogue | PROPOSED |
| D17-A02 | Lead/opportunity/quotation/order lifecycle and state ownership are defined | Lifecycle requirements and invariants | PROPOSED |
| D17-A03 | Price-list, discount, tax-reference and quotation controls are defined without invented values | PSC draft; price/authority policy remains unverified | PARTIAL |
| D17-A04 | Credit and payment-term controls fail closed absent adopted policy | PSC draft and authority register | SUPPORTED AS CONTROL DESIGN ONLY |
| D17-A05 | Customer acceptance, fulfilment, invoice and collection boundaries are explicit | PSC draft and D11/D12/D14/D16/D18 boundaries | PROPOSED |
| D17-A06 | Returns, refunds and credit-note ownership and linkage are defined | PSC draft and Finance/Wallet/Treasury boundaries | PROPOSED |
| D17-A07 | D11/D12/D14/D16/D18/D20/D21/D28 interfaces are explicit | Current integration evidence and this matrix | PARTIAL |
| D17-A08 | Customer-data privacy, tenant/entity isolation, audit and versioning requirements are explicit | Platform security and records boundaries | PROPOSED |
| D17-A09 | Commercial and pipeline reporting is separated from posted financial results | Finance reporting boundary and this baseline | PROPOSED |
| D17-A10 | A3 entry gates and unresolved evidence dependencies are explicit | This baseline and evidence-timing addendum | PROPOSED |

### 6.3 A3 entry conditions (not authorization)

Before either domain can enter A3:
- Owner separately approves a versioned domain A1 contract derived from this requirements baseline.
- D03 Governance provides a verified authority-rule interface or an explicit fail-closed integration contract; the existing narrow Governance delegation service must not be treated as generic procurement/sales approval authority.
- D11 Finance accounting interfaces and source-event adapter contracts are agreed; source-owned business records remain in D15/D17.
- D12 Treasury, D16 Inventory and D18 Operations interfaces are specified for the selected vertical slice.
- D20 evidence-reference and retention integration is defined.
- Required source documents remain explicitly deferred to post-deployment intake where unavailable pre-deployment; no claim of authority verification is made before then.
- A bounded vertical slice, exact file scope, migration strategy, rollback, test matrix and security review plan are separately approved.
- A3 implementation is separately authorized by the Owner.

## 7. Future software acceptance matrix (A4 design inputs only)

The following positive and negative scenarios must be covered on the exact candidate SHA before software acceptance. They are proposed test requirements, not tests executed by this A2 work.

### D15 test scenarios
- Tenant/entity isolation: user cannot read or mutate another tenant/entity's supplier, requisition, bid, award, PO or evidence by changing identifiers.
- Authority denial: absent, expired, revoked, ambiguous or wrong-entity authority cannot approve award, PO or amendment.
- Segregation: requester cannot self-approve; evaluator with a declared conflict is excluded from the decision.
- Tender integrity: submitted quotations and evaluation criteria remain version-bound; post-submission mutation is rejected or creates a controlled revision.
- Supplier integrity: duplicate supplier identity and unverified bank-detail change trigger review/hold; invoice email does not validate bank details.
- Match control: invoice mismatch, duplicate invoice or missing receipt produces an exception; it cannot silently proceed as matched.
- Receipt boundary: PO/invoice cannot create a D16 receipt or D18 service acceptance.
- Finance boundary: client cannot submit arbitrary GL account/journal payload; Finance adapter resolves source and entity, deduplicates event and fails closed on unsupported source.
- Idempotency/concurrency: repeated event delivery creates one accepted business outcome; concurrent approval against stale revision is rejected.
- Audit: cancellation, award, amendment, receipt reference, exception and closure preserve actor, time, reason and source evidence.

### D17 test scenarios
- Tenant/entity isolation: customer, opportunity, quotation, order and commercial evidence are inaccessible across tenant/entity boundaries.
- Catalogue/price version: inactive or future/expired price list cannot be used for binding quotation; issued quotation snapshot is immutable.
- Authority denial: discount, credit, order exception or refund cannot be approved without current source-backed authority.
- Customer acceptance: internal quotation approval alone cannot mark customer acceptance or order fulfilment.
- Fulfilment boundary: D17 cannot mark delivery complete without D16/D18 source evidence.
- Finance boundary: D17 cannot create/alter invoice posting, receivable, revenue or journal; only authoritative D11 references update posted status.
- Payment boundary: wallet allocation cannot be represented as bank settlement; D12 settlement status is separately sourced.
- Refund integrity: refund/credit note references original transaction; original posted sale/payment is not deleted; reversal is idempotent.
- Privacy: customer data fields are purpose-limited and role-protected; access/export is audited.
- Audit/concurrency: stale quotation/order revisions and duplicate webhook/event deliveries cannot produce duplicate orders, invoices or refunds.

## 8. Post-deployment evidence and verification plan

The following are explicitly **POST-DEPLOYMENT EVIDENCE DEFERRED** where not already available in inspected repository sources:
- Adopted procurement and commercial policies and current approved amendments.
- Competent authority instruments, committee charters/appointments, resolutions and current delegation schedules.
- Approved procurement method/threshold schedule, supplier eligibility rules and supplier source evidence.
- Approved price lists, discount/commission/credit/refund schedules and customer contract evidence.
- Supplier and customer source records, tender/quotation/award/contract/receipt/invoice and commercial transaction evidence.
- Tax/legal instruments applicable to actual products, services and transaction types.

After ARNA is deployed and running:
1. Upload through the authorized, secured D20 evidence intake workflow.
2. Capture source issuer, instrument/document type, legal entity, version, effective date, jurisdiction, source location, uploader, upload timestamp, hash/checksum and confidentiality classification.
3. Assign an authorized independent reviewer; uploader/creator must not self-verify or self-approve.
4. Verify completeness, authenticity, currentness, authority, scope and supersession against the governing instrument and resolution register.
5. Record exact clauses/decisions and the action/transaction types they support.
6. Configure no authority rule until the source evidence and competent approval are verified.
7. Preserve unresolved/contradictory evidence as blocked; never silently choose a preferred version.
8. Reassess domain status only through a separately controlled Owner decision and versioned A2 addendum.

## 9. Repository evidence index

All paths below were fetched from API `main` at `6e274c25ecc55b84e026e879d735b77f0e51592f`. Blob SHAs identify file contents, not commits.

| Evidence | Path | Blob SHA | Relevance and limitation |
|---|---|---|---|
| PSC governance policy v0.1.0 | `docs/governance/ARNA-GOV-POL-PSC-001.md` | `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | Draft lifecycle and controls for procurement and sales; explicitly not adopted. |
| PSC control/authority matrix v0.2.0 | `docs/governance/ARNA-GOV-MAT-PSC-001-AM.md` | `07eb3f567bcc67fe2c7ac8e66c0a993c0d1e69c2` | Owner-approved provisional control principles; legal/business authority and thresholds remain unverified. |
| Document approval authority register | `docs/governance/document-approval-authority-matrix.md` | `f53446ece9481ddf09759854cf2fd63e4ba20709` | Fail-closed source-backed approval rule; Governance delegation is narrow, not generic financial/commercial authority. |
| Phase 1 implementation strategy | `docs/phase-1/PHASE-1-FULL-IMPLEMENTATION-STRATEGY.md` | `e5e973c15c4ced4900de3ae5c48a8472fdc349f0` | Candidate capabilities, dependencies and intended sequence; planning document, not accepted domain contract. |
| P1.0 baseline implementation control contract | `docs/phase-1/P1.0-BASELINE-IMPLEMENTATION-CONTROL-CONTRACT.md` | `1af1856791deeb30d851797711441ced304567f1` | Current inspected tree did not show top-level Procurement/Sales domains; treats them as prospective work and requires state-machine, approval and financial integration tests. |
| Finance journal source enum | `mases_core/src/Domains/Finance/Enums/JournalSourceType.php` | `e0991fc4026952589102691abc2b3a1393b21b20` | Procurement source enum and reference requirements exist; enum is provenance metadata, not active adapter or domain implementation. |
| Finance CoA v1.0.0 | `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php` | `72424d5f12c2af03604a7979cf79552423447e5f` | Supplier advances/payables, customer advances, sales/service revenue and cost categories; tax/ownership notes remain limited and some classifications undetermined. |

Additional source decisions already recorded in the A2 closure report:
- D15 and D17 proposed PARTIAL upgrades in PR #215 were explicitly rejected; MISSING remains controlling.
- Draft thresholds in earlier working materials are not authority and must not be configured.
- PR #215 remains an evidence proposal and does not amend the controlling disposition.

## 10. A2 conclusion and next bounded work

**D15:** Requirements baseline prepared; accepted domain contract still MISSING. Procurement policy adoption, method/threshold authority, current appointments, supplier source verification and operational evidence remain unresolved/deferred as appropriate.

**D17:** Requirements baseline prepared; accepted domain contract still MISSING. Commercial policy adoption, price/discount/credit/refund authority, customer source verification and operational evidence remain unresolved/deferred as appropriate.

**A2 impact:** This document improves requirement-level specification coverage only. It does not alter the 30-domain disposition register, R06 decisions, A2 closure recommendation or stage authority.

- A2 overall: **PARTIAL — NOT VERIFIED-CLOSED**.
- D15: **MISSING — no domain upgrade**.
- D17: **MISSING — no domain upgrade**.
- A3: **NOT AUTHORIZED**.
- A4: **NOT STARTED**.
- A5: **NOT AUTHORIZED**.
- Merge: **NOT AUTHORIZED**.
- Tests/CI: **NOT RUN**.
- Application source/schema/migrations/seeds/runtime/production data: **UNCHANGED**.

**Next A2 task:** reconcile the D15/D17 requirements against the exact App main feature tree and any existing UI/API consumers, then prepare a source-to-requirement traceability matrix. Do not implement or infer that planned UI paths are present. Continue to defer primary corporate/financial source-record intake until ARNA is deployed and running on the server.
