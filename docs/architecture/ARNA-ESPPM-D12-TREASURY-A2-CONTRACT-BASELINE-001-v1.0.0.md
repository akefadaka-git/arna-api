# ARNA ESPPM D12 Treasury & Cash Management — A2 Contract and Traceability Baseline

- **Document ID:** ARNA-ESPPM-D12-TREASURY-A2-CONTRACT-BASELINE-001
- **Version:** 1.0.0
- **Prepared:** 2026-11-24
- **Stage:** A2 — read-only requirements specification and source reconciliation
- **Domain:** D12 — Treasury & Cash Management
- **Approved A2 disposition:** **MISSING — PRESERVED**
- **Specification status:** A2 candidate baseline; not an approved A1 domain contract
- **Repository of record:** `akefadaka-git/arna-api`
- **Inspected API `main`:** `6e274c25ecc55b84e026e879d735b77f0e51592f`
- **Inspected App `main`:** `ffe0495c0eb7c8b5d2013419123aac5b68893d51`
- **Change class:** Documentation only
- **Implementation / merge / deployment authority:** NOT GRANTED

---

## 1. Purpose and decision boundary

This baseline defines the bounded business purpose, ownership, requirements, conceptual records, lifecycle invariants, cross-domain interfaces, source-to-requirement traceability, A2 acceptance criteria and future A4 verification scenarios for D12.

It is a requirements and evidence-reconciliation artifact only. It does not approve the D12 business policy, establish corporate or bank signing authority, approve bank accounts or payment limits, freeze an A1 contract, define database schemas, or authorize implementation.

**Controlling disposition:** D12 remains **MISSING** in the Owner-approved 30-domain A2 register. This baseline records a specification candidate and evidence gaps; it does not upgrade D12 to PARTIAL or SUPPORTED.

## 2. Domain purpose and ownership

D12 owns the controlled custody, visibility, movement and reconciliation of the enterprise's cash and bank positions across each legally recognized entity and authorized currency. It is the system-of-record boundary for:

1. bank-account and cash-location register metadata;
2. bank relationship and account lifecycle records;
3. effective-dated mandates, signatories and bank channel permissions;
4. cash positioning and liquidity planning;
5. controlled payment initiation, approval, bank submission and settlement confirmation;
6. bank statement acquisition, validation and reconciliation;
7. inter-account and inter-entity cash transfers;
8. bank fees, interest and other bank-originated cash movements;
9. exception handling, payment returns, reversals and unresolved differences; and
10. source evidence and audit history for Treasury actions.

D12 does **not** own:
- general ledger, chart of accounts, journal policy, accounting periods or financial statements (D11);
- commercial obligation creation, supplier selection, purchase orders or supplier acceptance (D15);
- stock custody, inventory movement or valuation source records (D16);
- customer, quotation, sales order, price, credit or refund decisions (D17);
- operational service fulfilment and acceptance (D18);
- wallet balances, customer funds, wallet payment allocation or wallet custody ledger (D14);
- loan approval, loan receivable terms or repayment schedules (D13);
- investment selection, capital allocation or investment portfolio holdings (D25/D30);
- grant eligibility, grant agreement or funding award decisions (D29); or
- legal authority creation. D12 consumes authority instruments and enforces their effective scope; it cannot invent them.

A bank transfer request, wallet allocation, accounting journal, payment provider callback or bank statement line is not independently sufficient proof of final settlement. Settlement status must be supported by the authoritative bank/provider evidence and a reconciled transaction identity.

## 3. Core operating principles and invariants

1. **Legal-entity isolation:** every account, mandate, payment, statement, transfer, cash position and reconciliation is scoped to a tenant and a legal entity. Cross-entity access or movement requires explicit source-backed authority.
2. **Authority is evidence-backed:** an RBAC permission is necessary but insufficient. An action must also satisfy an effective-dated, approved authority instrument and applicable transaction-specific controls. Unknown or expired authority fails closed.
3. **No invented limits:** monetary approval thresholds, signatory combinations, payment ceilings, cash buffers and delegation limits remain unset until a competent authority approves a source instrument. No values may be inferred from examples, role names, UI labels or draft policy.
4. **Segregation of duties:** requester, beneficiary maintainer, approver, payment releaser and reconciliation reviewer must be separated according to approved authority. A person must not approve their own request or reconcile their own execution where independence is required.
5. **Bank truth vs ledger truth:** bank/provider status and Finance posting status are distinct. A submitted or accepted instruction is not necessarily settled; a balanced journal is not proof that a bank movement occurred.
6. **Idempotency:** payment instructions, provider callbacks, statement imports, transfer records and Finance events require stable identities and replay-safe handling. Duplicate submissions or callbacks must not create duplicate cash movements or journals.
7. **Immutable evidence:** retain original statement/document bytes or an integrity-preserving reference, source identity, issuer/channel, legal entity, account, statement period, capture time, upload actor, checksum, parser/version, reviewer, reconciliation decision and correction lineage.
8. **No silent netting:** unmatched, partially matched, duplicate, stale, reversed, returned or cross-entity transactions remain explicit exceptions. No automatic write-off, suspense posting or forced match without approved policy.
9. **Provider isolation:** bank/payment credentials remain in a deployment secret manager; never in source control, client bundles, logs or ordinary application configuration. Provider adapters are replaceable and fail closed when unavailable or unauthorized.
10. **No autonomous execution:** AI or automated forecasts may classify, explain or propose. They may not approve mandates, release payments, alter bank instructions, post/reverse journals or close reconciliations autonomously.
11. **Currency integrity:** amount, currency, value date, transaction date, exchange-rate source and conversion/rounding treatment remain explicit. No unsupported foreign-currency posting or conversion.
12. **Auditability:** every material state transition records actor, authorization basis, effective policy/mandate version, timestamp, tenant/entity scope, reason, evidence and before/after state.

## 4. D12 requirements register

Requirement IDs are stable within this document. “A2 evidence status” describes the inspected repository evidence, not an operational readiness rating.

| Requirement | Requirement statement | A2 evidence status |
|---|---|---|
| D12-RQ-01 Domain ownership and boundary | Maintain an explicit Treasury owner and the D11/D13/D14/D15/D17/D18/D25/D29/D30 ownership interfaces. | PARTIAL — boundaries can be specified; no accepted D12 domain contract located. |
| D12-RQ-02 Legal-entity cash scope | Every bank/cash account and transaction must belong to one verified legal entity and tenant; prohibit implicit cross-entity reuse. | MISSING — Finance entity primitives exist, but no D12 account ownership contract found. |
| D12-RQ-03 Bank-account register | Maintain account identity, bank/branch, currency, account type, purpose, entity, status, effective dates and masked identifiers; preserve account history. | MISSING — CoA bank account classification is not a bank-account register. |
| D12-RQ-04 Bank mandate and signatory register | Maintain source-backed mandate instruments, signatory identities, signing combinations, channel scope, effective dates, revocation and expiry. | MISSING — no verified mandate or signatory schedule. |
| D12-RQ-05 Payment authority and limits | Resolve payment approval authority from approved instruments, with amount/currency/entity/channel/beneficiary scope and effective date; unknown limits fail closed. | MISSING — draft PSC and provisional controls do not establish bank signatures or monetary authority. |
| D12-RQ-06 Payment request intake | Accept an authorized request tied to a source obligation, legal entity, beneficiary, amount, currency, due date, purpose and evidence reference. | MISSING — no general Treasury request lifecycle found. |
| D12-RQ-07 Beneficiary controls | Maintain verified beneficiary identity and bank destination, controlled creation/change, independent verification, effective dates and fraud-sensitive change review. | MISSING — no Treasury beneficiary register or controls found. |
| D12-RQ-08 Payment approval and release | Enforce maker-checker separation, applicable mandate/signature combination, limits, duplicate detection and explicit release authorization before bank submission. | MISSING — narrow Governance settlement separation is not general payment authority. |
| D12-RQ-09 Bank/provider execution | Submit instructions through a versioned provider adapter, retain bank reference and raw/normalized responses, distinguish accepted, pending, settled, failed and returned states. | MISSING — Finance A3.5 explicitly excludes automatic payments and external transport. |
| D12-RQ-10 Bank statement acquisition | Import statements through controlled channels with account/period identity, source provenance, integrity checks, duplicate detection and import audit. | MISSING — no bank statement ingestion or bank feed found. |
| D12-RQ-11 Bank reconciliation | Match statement lines to authorized payments, receipts, transfers and approved adjustments; support partial matches, reversals, fees, unmatched queues and independent sign-off. | MISSING — no general bank reconciliation service or evidence found. |
| D12-RQ-12 Cash position and cashbook | Provide verified balances by account/entity/currency as-of time, distinguish bank-confirmed, book, in-transit and restricted funds, and reconcile to source evidence. | MISSING — CoA classes are not a cash position or cashbook. |
| D12-RQ-13 Liquidity planning | Forecast expected inflows/outflows, committed obligations, maturities, restricted cash, minimum buffers and scenario assumptions with provenance and review. | MISSING — no liquidity forecast/treasury planning contract found. |
| D12-RQ-14 Inter-account and inter-entity transfers | Control transfer initiation, dual-side confirmation, entity ownership, fees, value dates, intercompany references and both-side reconciliation. | MISSING — no Treasury transfer lifecycle found. |
| D12-RQ-15 Bank charges and interest | Identify bank-originated charges, interest and corrections from statement evidence; route accounting treatment to D11 with approved mapping. | PARTIAL — CoA has bank/payment charge and interest classifications; no D12 source process. |
| D12-RQ-16 Cross-domain settlement interface | Consume approved obligations/instructions from source domains and publish authoritative execution/settlement outcomes without owning the originating business decision. | PARTIAL — Finance/Wallet/Loan/Governance foundations exist; no general D12 adapter or end-to-end bank transport. |
| D12-RQ-17 Currency and FX controls | Preserve transaction currency, entity base currency, rate source/time, conversion approvals, rounding and realized/unrealized differences. | MISSING — Finance FX foundations do not establish D12 execution/valuation controls. |
| D12-RQ-18 Security, audit and evidence | Enforce least privilege, step-up controls where required, secure secrets, immutable audit, evidence retention, privacy and exception escalation. | PARTIAL — platform/Finance controls exist; D12-specific policy, evidence and acceptance absent. |
| D12-RQ-19 Continuity and operational resilience | Define provider outage, delayed settlement, credential rotation, recovery, replay, cut-off, business continuity and emergency/manual process controls. | MISSING — no accepted Treasury resilience/runbook contract. |
| D12-RQ-20 Treasury reporting and oversight | Produce account, payment, liquidity, reconciliation, exception, mandate-expiry and cash exposure reports with traceable definitions and access controls. | MISSING — no D12 report catalogue or approved Treasury KPI contract. |

## 5. Conceptual record classes (not a database design)

The following are conceptual information classes only. They do not prescribe table names, columns, migrations, ORM models, API routes or implementation structure.

- **BankAccountRecord:** legal entity, bank and branch identity, masked account reference, currency, purpose, account type, status and effective dates.
- **BankMandateRecord:** source instrument, issuing authority, mandate version, signatories, signing rule, channel, limits if explicitly approved, effective/expiry dates and revocation.
- **TreasuryAuthorityGrant:** approved actor/role, action, entity/account scope, currency/amount range only where source-backed, effective dates and evidence reference.
- **BeneficiaryRecord:** verified counterparty identity, bank destination reference, verification state, change history and restricted access.
- **PaymentInstruction:** source obligation, beneficiary, amount/currency, entity/account, purpose, due/value date, idempotency key and approval history.
- **PaymentExecutionAttempt:** provider, request identity, submission time, provider/bank reference, response evidence, attempt count and outcome.
- **BankStatementBatch / BankStatementLine:** source, account, period, checksum, line identity, booking/value date, amount, currency, reference and import provenance.
- **ReconciliationCase / Match:** statement line, candidate source transaction, matching method, confidence, reviewer, exceptions, adjustments and closure evidence.
- **CashPositionSnapshot:** account/entity/currency, bank-confirmed balance, book balance, in-transit/restricted amounts, as-of timestamp and source completeness.
- **LiquidityForecast / Scenario:** forecast horizon, assumptions, expected receipts/payments, committed obligations, restricted funds, scenario version and reviewer.
- **TreasuryTransfer:** source/destination account, entities, currency, amount, approvals, both bank references and paired reconciliation state.
- **TreasuryException:** duplicate, unmatched, returned, stale, unauthorized, disputed or unreconciled event, owner, due date and resolution evidence.
- **TreasuryAuditEvent:** actor, tenant/entity, action, authority version, timestamp, evidence, state transition and correlation identifiers.

## 6. Indicative lifecycle models

These lifecycles are requirements vocabulary, not adopted policy or implemented state machines. Exact transitions, roles, timeouts and approval thresholds require Owner-approved A1 policy.

### 6.1 Bank account
`proposed → authority_review → approved → active → suspended → closed`

An account cannot become active merely because a record is entered. Closure preserves historical references and requires outstanding payment, statement and reconciliation exceptions to be resolved or explicitly carried under approved policy.

### 6.2 Mandate and signatory
`draft → source_verified → approval_pending → approved → effective → expired / revoked`

No payment may rely on a mandate outside its effective period, legal entity, account, channel or signing scope.

### 6.3 Payment instruction
`draft → submitted_for_review → approved → released → provider_submitted → bank_accepted → settled`

Alternative terminal/exception states include `rejected`, `failed`, `returned`, `cancelled_before_submission`, `reversed` and `disputed`. Provider acceptance is not settlement. A return or reversal must preserve the original instruction and execution history.

### 6.4 Statement and reconciliation
`received → integrity_checked → parsed → duplicate_checked → ready_for_matching → partially_reconciled / reconciled → independently_reviewed → closed`

Invalid, duplicate, incomplete or corrupted statement batches remain quarantined. A reconciled batch cannot be silently overwritten; correction creates a traceable successor or adjustment.

### 6.5 Liquidity forecast
`draft → assumptions_review → approved_for_monitoring → superseded`

A forecast is a planning estimate, not an instruction to move funds. Actuals and forecast variances remain distinguishable.

## 7. Cross-domain producer / consumer contract

| Source / domain | D12 receives | D12 returns / publishes | Ownership boundary |
|---|---|---|---|
| D11 Finance | approved accounting obligations, entity/account mappings, journal/posting references, book balances and reconciliation requirements | verified bank execution/statement facts, bank fees/interest and reconciliation outcomes for Finance accounting | Finance owns journals and accounting policy; Treasury owns bank movement and bank evidence. |
| D13 Loan | approved disbursement/collection obligations and source identifiers | payment execution and bank settlement status | Loan owns credit decision and loan ledger; Treasury owns bank execution. |
| D14 Wallet | approved provider/bank settlement instructions and wallet settlement control totals | bank/provider settlement evidence and exceptions | Wallet owns wallet ledger and customer allocations; Treasury owns bank settlement and bank reconciliation. Wallet allocation is not bank settlement. |
| D15 Procurement | approved supplier payment obligations and beneficiary references | payment status and bank execution evidence | Procurement owns sourcing, award, PO and supplier acceptance; Treasury does not approve supplier selection. |
| D16 Inventory | no direct payment authority; may provide accepted receipt/stock evidence through D15/D11 source chain | payment settlement evidence only where linked to approved obligations | Inventory owns stock events; it does not instruct bank release. |
| D17 Sales | approved customer collection/refund obligations and references | verified collection/refund settlement evidence | Sales owns customer/commercial decision; Treasury owns bank execution and reconciliation. |
| D18 Operations | accepted service delivery evidence where a payment obligation requires it | payment status for an authorized service obligation | Operations owns fulfilment and service acceptance. |
| D20 Documents | immutable evidence references, approved records and retention/hold controls | bank mandate, statement, payment and reconciliation evidence metadata | Documents owns records lifecycle; D12 owns Treasury decisions and transaction state. |
| D21 Communications | authorized notices and exception escalations | payment/mandate/reconciliation notices to eligible recipients | Communications transports notices; it does not approve financial actions. |
| D25 / D29 | approved capital/funding obligations and transaction authority | execution/settlement evidence | Investment/funding decisions remain with the originating domain and competent authority. |
| Banks / payment providers | statement data, instruction acknowledgements, settlement/return evidence, fee/interest lines | authenticated instructions and statement/confirmation requests | External provider is authoritative for its own bank-side status; ARNA retains provenance and reconciliation. |

**Integration rule:** source-specific adapters require the source domain's canonical read/authorization contract, stable source state, tenant/entity resolution, idempotency, evidence mapping, and contract tests. Until these exist, unknown sources fail closed. No direct writes from Treasury into source-domain records.

## 8. Shared authority, security and operational controls

1. Authority instruments must be approved by the competent corporate body and reconciled against the registered governing instruments and applicable Ethiopian law by qualified reviewers. This baseline does not determine which officer or body has legal signing authority.
2. Record each mandate and delegation with source document identity, issuer, approval reference, effective date, expiry, revocation, legal entity, accounts, channels and authorized combinations.
3. Do not hard-code approval limits or signatory counts. Missing, ambiguous, expired or conflicting authority must block the action.
4. Separate beneficiary maintenance from payment request/approval/release. Material beneficiary changes require independent verification and heightened controls under an approved policy.
5. Apply tenant and legal-entity scoping server-side. Client-supplied entity/account references are not trusted without ownership checks.
6. Require idempotency and replay protection for payment submissions, callbacks, imports and journal events.
7. Preserve raw provider evidence securely; redact account numbers and credentials from logs and UI. Secrets are stored only in the deployment secret manager.
8. Enforce controlled corrections, reversals and adjustments; do not delete or overwrite original financial evidence.
9. Escalate stale payments, unmatched statements, duplicate lines, returned funds, unexpected bank fees, mandate expiry and liquidity shortfalls to assigned accountable owners.
10. Emergency/manual bank operations require an approved offline procedure, independent evidence capture and post-event reconciliation. No informal workaround may bypass authority.

## 9. Source-to-requirement traceability against exact current main

The sources below were retrieved from the exact API/App `main` SHAs stated in the header. A source proves only the narrow capability described. “Not located” is a bounded repository inspection result, not proof that no external corporate/bank document exists.

| Source ID | Exact current-main source / blob | Requirements informed | Finding and limitation |
|---|---|---|---|
| D12-S01 | API `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php` — `72424d5f12c2af03604a7979cf79552423447e5f` | RQ-03, RQ-12, RQ-15, RQ-17 | CoA classifies cash on hand (1110), bank accounts (1120), mobile-money/payment clearing (1130), wallet settlement receivable (1140), borrowings (2400/2500), interest and bank/payment charges (7100/7200). These are accounting classifications, not bank accounts, mandates, payments or reconciliations. |
| D12-S02 | API `mases_core/src/Domains/Finance/Documentation/ARNA-COA-v1.0.0.md` — `800af9f878b0c5f261904e07449b9f50dc78ad01` | RQ-01, RQ-03, RQ-11, RQ-15 | Proposes Finance/Treasury ownership for cash/bank and says each control account needs a reconciliation owner and evidence schedule before adapter activation. It is not an approved Treasury policy or evidence of an active adapter. |
| D12-S03 | API `docs/finance/ARNA-FINANCE-A3-IMPLEMENTATION-BASELINE.md` — `1d66249eb813d785af50ae36f4844a9818f21ff5` | RQ-05, RQ-08, RQ-09, RQ-16, RQ-17, RQ-18 | Describes provider-independent bank/payment adapters and says AI cannot execute payments or post autonomously. It records incomplete source adapters and FX work; it is a Finance implementation baseline, not a D12 contract. |
| D12-S04 | API `docs/finance/ARNA-FINANCE-A3.5-JOURNAL-OUTBOX-AND-SUBLEDGER-BOUNDARY.md` — `1cedd498fa8f2e96ecf59b9827200ae0915d0b46` | RQ-08, RQ-09, RQ-16 | Durable transactional outbox/inbox foundation exists, but external publisher/queue transport is unconfigured; explicit exclusions include automatic payments and settlement. Source adapters remain fail-closed absent authoritative contracts. |
| D12-S05 | API `docs/finance/ARNA-FINANCE-A3.8-HYBRID-CONTROLS-BASELINE.md` — `80f5041774370f05dad7aa4396af8faef2b47959` | RQ-09, RQ-18, RQ-19 | Integration-health reports NOT_RECONCILED until authoritative source totals and event versions exist; bank credentials belong in deployment secret manager; source-domain control-total reconciliation is NOT IMPLEMENTED. |
| D12-S06 | API `mases_core/src/Domains/Finance/Enums/JournalSourceType.php` — `e0991fc4026952589102691abc2b3a1393b21b20` | RQ-09, RQ-16 | A `BANK` journal source enum exists. An enum is a provenance label, not a bank adapter, bank feed, account register or reconciliation engine. |
| D12-S07 | API `mases_core/src/Domains/Finance/Services/GovernancePenaltySettlementService.php` — `21224b7f9c07357a838f12ce5a943957ba3c837f` | RQ-05, RQ-08, RQ-16, RQ-18 | Narrow Governance penalty cash settlement has account mapping approval, segregation/idempotency and Finance journal behavior. It does not establish general Treasury authority, bank mandates, external bank execution or statement reconciliation. |
| D12-S08 | API `mases_core/src/Domains/Finance/Http/Controllers/GovernancePenaltySettlementController.php` — `6ae90c3ddc553167c3643b423afcd9546274343d` | RQ-06, RQ-08, RQ-11 | Exposes the specific Governance penalty settlement and entity-scoped reconciliation surface only. No general Treasury route family was identified in the inspected `routes/api.php` (`7284faefc156548394a9ca7edfc2ead791773fb5`). |
| D12-S09 | API `mases_core/src/Domains/Finance/Models/GovernanceSettlementAccountMapping.php` — `b2fece69bb4904805f7b0a3e213919fb515e1a31` | RQ-03, RQ-15, RQ-16 | Cash/bank is one role in a purpose-specific mapping model; it is not a bank-account master or mandate record. |
| D12-S10 | API `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementServiceTest.php` — `788b6b59a3b1a16f835bfe6352578f7e80dab4d2` | RQ-05, RQ-08, RQ-18 | Tests narrow mapping approval and independent actor behavior. Tests do not prove general Treasury acceptance. |
| D12-S11 | API `mases_core/src/Domains/Finance/Tests/Feature/GovernancePenaltySettlementAccountingTest.php` — `780ccf6894cb8594f79a8100fa3f7be52c8d890c` | RQ-08, RQ-09, RQ-16 | Tests specific cash settlement journal balancing, idempotent replay and reconciliation. They do not test bank statement matching or bank-side settlement. |
| D12-S12 | API `mases_core/src/Domains/Shareholder/Config/shareholder.php` — `4de78750a837c44cfd1ed06522931244d17e7410` | RQ-01, RQ-03 | Treasury/buyback flag is explicitly informational in Phase 2A; actual Treasury operations belong to a later phase. This is not D12 cash management. |
| D12-S13 | API `mases_core/src/Domains/Shareholder/Database/Seeders/ShareClassSeeder.php` — `8d34ad2b15a8d3d6f9b17267d408ab4b0839a14c` | RQ-01 | Both share classes are seeded with `is_treasury_eligible = false`; this flag relates to share-class buyback eligibility only. |
| D12-S14 | API `mases_core/src/Domains/Shareholder/Database/Migrations/2026_09_11_000001_create_share_classes_table.php` — `e28ca992a1ad078afa365b6beb9060a98fa62b57` | RQ-01 | Defines the per-share-class treasury/buyback eligibility flag; no bank-account or cash-management schema. |
| D12-S15 | API `docs/governance/ARNA-GOV-POL-PSC-001.md` — `0486e6f1870f52fa596460b1c5cc2d8c5f10b485` | RQ-04, RQ-05, RQ-06, RQ-08, RQ-11, RQ-18 | Version 0.1.0 is explicitly a draft for formal corporate approval. It says thresholds, committee appointments and reserved matters must not be inferred; it cannot prove bank signatures or payment limits. |
| D12-S16 | API `docs/governance/document-approval-authority-matrix.md` — `f53446ece9481ddf09759854cf2fd63e4ba20709` | RQ-04, RQ-05, RQ-18 | Owner-approved provisional controls cover segregation, evidence, delegation principles and fail-closed handling; the matrix expressly does not verify bank signatures, monetary limits or specific approvers. |
| D12-S17 | API `mases_core/src/Domains/Finance/Config/chart_of_accounts_v1.php` and Finance A3.5 boundary above | RQ-09, RQ-15, RQ-16 | Cash/bank and payment-clearing accounts are present, but no source evidence establishes bank statement ingestion, payment provider connectivity or D12 reconciliation. |
| D12-S18 | App `src/features` directory listing at App `main` `ffe0495c0eb7c8b5d2013419123aac5b68893d51` | RQ-20 and all UI requirements | Current feature listing includes auth, calendar, communications, dashboard, executive-overview, files, governance, launcher, loans, locale, notifications, people, portal, public-content, public-site, shareholders and wallet; no Treasury feature directory was present. This is a bounded tree observation, not a claim about external or unmerged work. |

### 9.1 Requirement coverage summary

- **20 requirements defined:** D12-RQ-01 through D12-RQ-20.
- **PARTIAL evidence:** RQ-01, RQ-15, RQ-16, RQ-18.
- **MISSING evidence:** RQ-02 through RQ-14 except RQ-15; RQ-17; RQ-19; RQ-20.
- **No requirement is VERIFIED-CLOSED.**
- A requirement with a PARTIAL evidence label is not accepted or implemented; it records only a related repository foundation.

## 10. A2 specification acceptance criteria

These criteria determine whether the D12 A2 requirements specification is sufficiently explicit. They do not constitute implementation tests.

| Criterion | A2 specification acceptance |
|---|---|
| D12-A2-AC-01 | Domain purpose and boundaries distinguish bank/cash operations from Finance accounting, Wallet, Loan, Procurement, Sales and investment/funding decisions. |
| D12-A2-AC-02 | Every requirement has a stable identifier, statement and evidence status. |
| D12-A2-AC-03 | Account, mandate, payment, statement, reconciliation and liquidity concepts are defined without prescribing schema. |
| D12-A2-AC-04 | Authority is source-backed, effective-dated, entity-scoped and fail-closed; no invented monetary/signatory thresholds. |
| D12-A2-AC-05 | Payment execution, provider acceptance and actual settlement are separate states. |
| D12-A2-AC-06 | Statement provenance, duplicate handling, matching, exceptions and independent review are specified. |
| D12-A2-AC-07 | Cross-domain producers, consumers and ownership boundaries are explicit. |
| D12-A2-AC-08 | Finance posting requires authoritative source events, entity resolution, idempotency and reconciliation. |
| D12-A2-AC-09 | Security, segregation, audit, evidence, secret management and resilience requirements are stated. |
| D12-A2-AC-10 | Post-deployment primary evidence is explicitly deferred and is not treated as a pre-deployment upload blocker. |

**A2 specification assessment:** The ten criteria are addressed in this candidate document. This is a document-quality assessment only; Owner approval of an A1 D12 contract remains outstanding, and the domain disposition remains MISSING.

## 11. Future A4 verification scenarios — NOT RUN

The following are proposed acceptance scenarios for a future, separately authorized implementation and A4 stage:

1. A bank account cannot be activated without a verified legal-entity relationship and approved source instrument.
2. Expired, revoked, out-of-scope or ambiguous mandates block payment release.
3. A requester cannot approve/release their own payment; beneficiary changes are independently verified.
4. Unknown amount limits or signing combinations fail closed rather than using defaults.
5. Duplicate payment requests and provider callbacks do not create duplicate instructions, cash movements or journals.
6. Provider submission, bank acceptance, settlement, return and reversal are represented distinctly.
7. A provider outage leaves instructions in a controlled retry/exception state without claiming settlement.
8. Duplicate or corrupted statement batches are quarantined and cannot be imported twice.
9. A statement line cannot be matched across tenant, legal entity, account or currency boundaries.
10. Partial, unmatched and ambiguous lines remain visible until independently resolved.
11. Reconciliation totals agree to source statement controls and Finance records; unresolved differences prevent closure.
12. Cash position separates bank-confirmed balances, book balances, in-transit funds and restricted funds with as-of times.
13. Liquidity forecast preserves assumptions, versions, scenarios and forecast-versus-actual variance.
14. Inter-entity transfer requires authorized paired records and independently reconciled bank-side legs.
15. Bank fees/interest route to approved Finance mappings without D12 writing journals directly.
16. Wallet, Loan, Procurement and Sales source events are rejected until their authoritative adapters are registered and verified.
17. Audit records preserve actor, authority version, source evidence, state changes and reversal lineage.
18. Secrets are absent from source, logs, browser payloads and test artifacts.
19. Restore/replay and credential-rotation procedures preserve idempotency and audit continuity.
20. Access control and reports respect tenant, legal entity, account and role scope.

**Execution status:** NOT RUN. No test suite, migration, integration, bank connection or production environment was exercised by this A2 work.

## 12. Deferred primary evidence and post-deployment intake

Primary corporate and financial records must **not** be requested or uploaded before ARNA is deployed and running on the server. This is an explicit evidence-timing constraint, not a waiver of the eventual verification requirement.

### 12.1 Deferred evidence classes

After deployment, through an approved secure intake path, authorized reviewers may collect and verify:

- bank account opening/ownership confirmation and account holder legal-entity identity;
- original bank mandates, board/Owner resolutions, signatory specimen/authorization evidence and effective/revocation history;
- bank service agreements, channel entitlements and provider onboarding/credential custody records;
- bank statements, transaction advices, deposit slips and payment confirmations;
- opening cash/bank balances and reconciliation cut-off evidence;
- approved payment limits, dual-control/signature rules, liquidity buffers and emergency procedures;
- source-domain obligations and bank settlement evidence needed for D11/D12 reconciliation.

No document is presumed to exist, be complete, current, authentic or legally effective. The list defines future evidence categories, not a request to the Owner now.

### 12.2 Intake and verification controls

1. Use authenticated ARNA upload with least-privilege access, tenant/entity scope and encrypted transport/storage.
2. Preserve original bytes, checksum, source, issuer, capture time, uploader and immutable version history.
3. Separate uploader, verifier and authority approver; record conflicts and recusals.
4. Verify legal entity, bank, account, mandate, signatures, effective date, expiry/revocation and scope against competent primary instruments.
5. Reconcile statements and transactions to D12 execution records and D11 accounting by amount, currency, dates, references and control totals.
6. Record outcomes as VERIFIED, PARTIAL, UNRESOLVED, REJECTED or NOT PROVIDED with reviewer, rationale and evidence references.
7. Never infer authority from a bank account appearing in a CoA, a seeded account code, a UI permission, a draft policy or an uploaded image alone.

## 13. A3 entry conditions — not authorization

A future A3 proposal must first have:

1. separately approved A1 D12 domain contract and explicit Owner A3 authorization;
2. competent authority-approved Treasury policy, bank mandate and signatory interfaces, with legal review where required;
3. approved legal-entity/account ownership and beneficiary controls;
4. source-specific payment and statement-provider adapter contracts and secure credential architecture;
5. agreed D11/D13/D14/D15/D17/D20/D21/D25/D29 boundaries and event contracts;
6. approved payment, approval, liquidity, reconciliation, exception and continuity policies;
7. versioned API/permission/state-machine and App route/consumer specifications;
8. a bounded vertical slice and migration/security/concurrency/idempotency/audit test plan;
9. a post-deployment evidence intake and independent verification plan; and
10. a separate Owner authorization to begin A3.

No A3 implementation is authorized by this document.

## 14. Final D12 disposition

| Item | Status |
|---|---|
| A2 requirements baseline | PREPARED — CANDIDATE SPECIFICATION |
| Source-to-requirement traceability | PREPARED — EXACT MAIN SHAS RECORDED |
| Primary bank/corporate evidence | DEFERRED UNTIL POST-DEPLOYMENT |
| Bank mandates/signatories/payment limits | NOT VERIFIED |
| Bank account register / payment execution | NOT LOCATED IN INSPECTED SCOPE |
| Bank statement ingestion / reconciliation | NOT LOCATED IN INSPECTED SCOPE |
| Liquidity management / forecasting | NOT LOCATED IN INSPECTED SCOPE |
| A2 domain disposition | **MISSING — PRESERVED** |
| A1 D12 contract approval | NOT GRANTED |
| A3 implementation | NOT AUTHORIZED |
| A4 verification | NOT STARTED |
| A5 deployment | NOT AUTHORIZED |
| Tests/CI | NOT RUN |

**Conclusion:** D12 has a structured A2 requirements and traceability baseline, but no accepted D12 contract or operational Treasury capability has been established. Keep D12 MISSING and keep overall A2 open. No primary financial evidence upload is requested before deployment.

---
