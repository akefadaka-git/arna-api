# ARNA Finance & Accounting Domain — Enhancement Strategy and Phased Roadmap

**Document ID:** ARNA-FIN-ROADMAP-001  
**Version:** 1.0.0  
**Status:** Owner-approved strategy; implementation remains gated by the controls in this document  
**Owner:** Akefa Ayele Daka  
**Approval date:** 2026-10-29  
**Repository:** `akefadaka-git/arna-api`  
**Scope:** Finance & Accounting domain only  
**Authority:** The Owner retains approval authority for requirements, accounting-policy decisions, risk acceptance, schema and code changes, opening balances, production posting, migrations, deployment, merge, and gate closure.

## 1. Executive objective

Build a dependable, auditable, multi-entity Finance & Accounting domain for ARNA OS. The domain must support accurate books, traceable source transactions, controlled financial reporting, governed subledger integration, and future jurisdiction/framework adaptation. It should be Amharic-first and mobile-accessible while maintaining enterprise-grade controls and data integrity.

The roadmap is an approved strategy, not evidence that every phase has been implemented or passed. Status must be reported from the exact destination-repository commit and workflow evidence. Do not represent the platform as legally or standards-compliant until applicability has been determined and the required controls and evidence have been independently assessed.

## 2. Non-negotiable accounting and engineering principles

1. **Balanced journals:** every posted journal must balance in the ledger's defined currency and precision; unbalanced journals cannot post.
2. **Controlled lifecycle:** draft, validation, approval (where required), posting, reversal, and period-close transitions must be explicit and authorized.
3. **Immutable posted history:** posted entries are not silently edited or deleted. Corrections use linked reversals and replacement entries with audit history.
4. **Decimal-safe arithmetic:** use decimal strings or fixed-precision decimal types; do not use binary floating-point for monetary calculations.
5. **Tenant and legal-entity isolation:** resolve tenant/entity context fail-closed and enforce it in every read, write, report, and integration path.
6. **Idempotent integrations:** retries must not create duplicate postings. Reuse of an idempotency key with a different request payload must fail deterministically.
7. **Traceability:** every journal line must be traceable to its source transaction, policy, actor, timestamps, and reversal/correction history.
8. **Period controls:** posting into closed or unauthorized periods must be blocked; reopening requires explicit authority and an audit record.
9. **Separate entities, governed consolidation:** maintain each legal entity's books independently; consolidated reporting must preserve entity and elimination provenance.
10. **Governed configuration:** account classifications, tax treatment, cash-equivalent designation, reporting mappings, and posting rules must be versioned, effective-dated, and authorized.
11. **No assumed opening balances:** no opening balance may be imported or posted until the source, reconciliation, accountable owner, and Owner approval are documented.
12. **No ungoverned AI posting:** AI may assist with explanation, classification suggestions, and exception triage, but cannot grant itself authority or bypass approval and posting controls.

## 3. Target architecture

- **Finance workspace:** role-specific views for finance officers, accountants, reviewers, approvers, auditors, and executives.
- **Policy and control layer:** entity-specific accounting policy, authorization matrix, period controls, tax configuration, and effective-dated reporting mappings.
- **Accounting core:** chart of accounts, journals, posting engine, ledger balances, periods, reversals, audit events, and control-account reconciliation.
- **Reporting and close:** trial balance, income statement, balance sheet, cash-flow statement, comparative reports, close workflow, and consolidated reporting.
- **Subledger and integration boundary:** governed adapters for Wallet, Loan, Shareholder, Procurement, revenue/receivables, banking, fixed assets, and other approved domains.
- **Operational foundation:** tenant identity, permissions, idempotency, outbox/retry handling, monitoring, retention, backup/restore, and controlled release workflows.

The accounting core is the canonical posting authority. Domain adapters must not independently write ledger tables or bypass the posting lifecycle.

## 4. Phased roadmap

Durations are planning estimates, not commitments. Phases can overlap only when dependencies and acceptance criteria are satisfied. Every phase follows the ARNA gates: A1 assessment/specification, A2 read-only inspection, A3 implementation, A4 verification, and A5 deployment. Owner approval is required before crossing a gated boundary.

### Phase 0 — Exact baseline, evidence, and decision register
**Estimate:** 1–2 weeks  
**Gate:** A1–A2; read-only

**Work**
- Establish the destination GitHub repository and exact source SHA as the source of truth.
- Reconcile the Finance PR chain and distinguish each PR's base/head SHA and unique file contribution.
- Inventory routes, controllers, policies, services, models, migrations, seeders, events, jobs, configuration, tests, and workflows.
- Map each requirement to implementation path, authorization/tenant controls, tests, workflow evidence, and outstanding decisions.
- Classify each item as `VERIFIED`, `PARTIAL`, `MISSING`, `UNRESOLVED`, `UNKNOWN`, or `NOT RUN`.
- Preserve the no-opening-balances decision and record the 36 control-account ownership gaps.

**Exit criteria**
- Exact-commit evidence register is reviewed and internally consistent.
- Known gaps and dependencies are explicit; no unverified implementation is marked complete.
- Owner accepts the baseline and selects the next bounded implementation unit.

### Phase 1 — Domain contract and accounting-policy foundation
**Estimate:** 2–3 weeks  
**Gate:** A2 contract review, then A3 only after approval

**Work**
- Define legal-entity, ledger, fiscal-year, accounting-period, account, journal, journal-line, posting, reversal, and reconciliation contracts.
- Define multi-entity operation, ETB base-currency assumptions, currency precision, exchange-rate provenance, and the future consolidation boundary.
- Specify journal lifecycle, authorization matrix, segregation of duties, period locks, correction semantics, and audit events.
- Establish versioned/effective-dated accounting policies and mappings.
- Define stable API contracts, domain events, idempotency behavior, and failure responses.

**Exit criteria**
- Contracts, invariants, permissions, and migration plan are documented.
- Owner decisions are recorded; unresolved policy questions remain explicit and block dependent behavior.

### Phase 2 — Ledger engine and posting lifecycle
**Estimate:** 3–6 weeks  
**Gate:** A3 implementation, A4 verification

**Work**
- Implement the canonical journal validation and posting service.
- Enforce balanced journal entries, decimal-safe arithmetic, tenant/entity scoping, period status, and authorized transitions.
- Implement reversals, correction links, idempotency, audit history, and transaction boundaries.
- Prevent direct or alternate paths from bypassing the canonical posting service.
- Add deterministic error handling and concurrency protection.

**Exit criteria**
- Invariant tests cover balanced/unbalanced entries, precision, duplicate requests, changed-payload conflicts, closed periods, cross-tenant access, reversal integrity, and concurrent posting.
- Applicable destination GitHub workflows pass on the exact reviewed SHA.
- No production posting is enabled by this phase alone.

### Phase 3 — Chart of accounts, control accounts, and opening-balance governance
**Estimate:** 2–4 weeks  
**Gate:** A3; opening balances require separate explicit authorization

**Work**
- Validate the canonical chart of accounts, including the currently documented 79-account CoA, against entity needs and reporting classifications.
- Confirm account types, normal balances, posting eligibility, cash/cash-equivalent classification, control-account mappings, and effective dates.
- Resolve all 36 control-account records currently marked `PENDING_OWNER_DESIGNATION`: name an accountable person and define reconciliation cadence, evidence, escalation, and sign-off.
- Keep the CoA seeder opt-in until its prerequisites and invocation are reviewed.
- Design an opening-balance import/reconciliation package without importing or posting balances before approval.

**Exit criteria**
- CoA validation report and approved mapping register exist.
- Every control account has a named accountable owner and documented reconciliation procedure, or the dependent adapter remains blocked.
- Opening balances remain untouched until their separate authorization gate passes.

### Phase 4 — Financial statements and management reporting
**Estimate:** 3–5 weeks  
**Gate:** A3 implementation, A4 reconciliation

**Work**
- Establish trial balance as a reconciled foundation for all statements.
- Complete income statement and as-of balance sheet with account mapping, entity filters, reporting period semantics, and drill-down to journal/source.
- Extend cash-flow reporting into a complete statement where applicable: opening and closing cash/cash equivalents, operating/investing/financing classifications, non-cash adjustments where required, foreign-exchange effects, and comparative periods.
- Make unclassified movements visible as exceptions rather than silently assigning a category.
- Add report reproducibility: policy/mapping version, generation timestamp, filters, and source ledger cutoff.
- Add entity-level reports first; implement consolidated statements only with approved consolidation rules.

**Exit criteria**
- Statement totals reconcile to the trial balance and underlying ledger for controlled fixtures and approved reference periods.
- Cash-flow opening-to-closing movement reconciles, with documented treatment of FX and cash equivalents.
- Authorization and tenant isolation are verified for every report endpoint.
- Any incomplete statement is explicitly labelled partial; no claim of full IAS 7 or other framework compliance without a completed applicability/control assessment.

### Phase 5 — Governed subledger integrations
**Estimate:** 4–8 weeks, then one integration at a time  
**Gate:** A3 per adapter; Owner approval for activation

**Recommended order**
1. Wallet and payment movements.
2. Loan disbursement, repayment, interest, fees, and reversals.
3. Shareholder-related receipts/distributions, where applicable.
4. Procurement and payables.
5. Revenue and receivables.
6. Bank statement/import and reconciliation.
7. Fixed assets and depreciation, if in scope.

**Work**
- Define a mapping and event contract per source domain.
- Use transactional outbox/retry or an equivalent reliable delivery design.
- Make replay safe and idempotent; expose rejected events and reconciliation exceptions.
- Reconcile source totals, ledger postings, suspense items, and timing differences.
- Keep each adapter disabled until control-account ownership, mappings, authorization, and reconciliation procedure are approved.

**Exit criteria**
- Each adapter has contract tests, failure/replay tests, idempotency evidence, source-to-ledger reconciliation, and an operational owner.
- No adapter is considered complete merely because an endpoint or event handler exists.

### Phase 6 — Ethiopian tax and statutory reporting
**Estimate:** 3–6 weeks for initial requirements mapping; ongoing updates  
**Gate:** A1/A2 legal applicability mapping; A3 only after policy approval

**Work**
- Maintain a versioned requirements matrix for each legal entity and applicable reporting framework.
- Assess the current Ethiopian legal and regulatory requirements, including VAT rules and tax administration, against the entity's actual activities and obligations.
- Define effective-dated tax codes, rates, taxable bases, exemptions, invoice/credit-note treatment, rounding, evidence retention, and reporting extracts where applicable.
- Map applicable financial-reporting requirements and disclosures, including foreign currency, financial instruments, revenue, leases, assets, employee benefits, and income tax only where relevant.
- Track standards changes and effective dates, including IFRS 18's international effective date of annual periods beginning on or after 1 January 2027; confirm Ethiopia-specific applicability/adoption rather than assuming it.
- Preserve the Owner's current direction that external tax/accounting review is deferred.

**Exit criteria**
- Requirements and software controls are traceable to a source, effective date, entity, and owner decision.
- Unreviewed legal interpretations are marked unresolved.
- Do not claim Ethiopian statutory or IFRS compliance until applicability, implementation, and appropriate review evidence support that claim.

### Phase 7 — Period close, consolidation, and planning
**Estimate:** 4–8 weeks  
**Gate:** A3/A4

**Work**
- Build a close calendar, task ownership, review evidence, accrual/adjustment journals, and controlled period locks.
- Add intercompany transaction matching, elimination journals, and consolidation provenance.
- Add currency translation and consolidated reporting after exchange-rate and accounting-policy rules are approved.
- Add dimensions, budget-versus-actual reporting, and forecast support without mixing budget data with posted actuals.

**Exit criteria**
- Close tasks are auditable and cannot be silently bypassed.
- Consolidated reports reconcile to entity books and documented eliminations.
- Reopening a closed period is permissioned, reasoned, and audited.

### Phase 8 — Security, resilience, and operational controls
**Estimate:** 3–6 weeks to establish, then continuous  
**Gate:** A4

**Work**
- Verify least privilege, segregation of duties, tenant/entity isolation, and sensitive-field access.
- Add audit-log integrity, secrets management, encryption in transit/at rest where supported, and retention/access controls.
- Review database constraints, transaction isolation, deadlock/retry behavior, and safe handling of duplicate events.
- Define backup/restore tests, recovery objectives, incident response, monitoring, and reconciliation alerts.

**Exit criteria**
- Security and recovery evidence is linked to the exact release candidate.
- Cross-tenant and unauthorized posting attempts fail closed.
- Recovery procedures are demonstrated and owned.

### Phase 9 — Automated verification and release engineering
**Estimate:** 2–4 weeks to establish, then continuous  
**Gate:** A4; destination GitHub evidence

**Work**
- Add/discover feature, integration, migration, authorization, tenant-isolation, and accounting-invariant coverage.
- Verify fresh-schema migration discovery and ordering, opt-in seeder behavior, and test discovery.
- Verify workflow path filters include every file and dependency required by the Finance acceptance suite.
- Add static analysis, dependency/security checks, performance checks for critical ledger/report paths, and deterministic fixtures.
- Record exact commit SHA, workflow URL, conclusion, and failed/skipped jobs.

**Exit criteria**
- Required checks pass against the exact candidate SHA and the result is reviewed.
- Missing or skipped checks are reported as such; a test file existing is not proof that it ran.
- Respect the Owner's instruction not to run local repository tests; use destination GitHub workflows and existing authorized verification paths. Do not start additional workflows without authorization.

### Phase 10 — Controlled pilot and production release
**Estimate:** 4–8 weeks after all prerequisite gates  
**Gate:** A5; separate explicit Owner authorization required

**Work**
- Define pilot entity, users, access boundaries, transaction limits, reconciliation cadence, support owner, and rollback criteria.
- Validate opening balances against approved source records before any import.
- Run parallel/reference-ledger comparisons and reconcile all differences.
- Confirm backup/restore, incident response, access review, monitoring, and release/rollback procedure.
- Enable production posting only after required approvals and acceptance evidence are complete.

**Exit criteria**
- Owner explicitly authorizes the environment, migration/opening-balance activity, posting activation, and deployment.
- Pilot reconciliations and release evidence pass.
- Merge, deployment, database mutation, and production posting are not implied by approval of this strategy document.

## 5. Compliance and evidence matrix

| Area | Required evidence |
|---|---|
| Framework and entity applicability | Entity-by-entity framework decision, authoritative sources, effective dates, approved policy |
| General ledger | Balanced posting invariant, journal lifecycle, reversals, period controls, immutable audit trail |
| Financial statements | Trial balance reconciliation, income statement, as-of balance sheet, applicable cash-flow statement, disclosures |
| Cash and cash equivalents | Approved account mapping, opening/closing balance reconciliation, classification and FX treatment |
| Foreign currency | Rate source, effective time, revaluation/translation policy, realized/unrealized treatment and reporting |
| Tax and VAT | Applicable obligation matrix, effective-dated tax rules, invoice/credit-note controls, reconciled extracts |
| Subledgers | Source-to-ledger mappings, idempotency, replay, exception queues, named reconciliation owner |
| Security | Tenant/entity isolation, permission tests, segregation of duties, audit access, secrets and retention controls |
| Release assurance | Exact SHA, migration evidence, required workflow URLs/conclusions, test discovery, reviewed exceptions |
| Operations | Close ownership, reconciliation sign-off, monitoring, backup/restore evidence, incident and rollback plans |

Reference points for legal/framework assessment include Ethiopia's Financial Reporting Proclamation No. 847/2014, applicable AABE decisions and frameworks, and current Ethiopian tax legislation. These references are not a substitute for confirming current applicability to each ARNA legal entity. The roadmap records a technical plan, not a legal opinion.

## 6. Enterprise benchmark and ARNA differentiation

Use mature finance platforms as capability benchmarks, not as claims that ARNA already has equivalent functionality:

- **SAP S/4HANA Finance / Group Reporting:** integrated ledger and subledgers, asset accounting, intercompany, and consolidation.
- **Oracle Fusion Financials:** general ledger, payables/receivables, intercompany, cash management, tax, assets, and reporting.
- **Microsoft Dynamics 365 Finance:** financial dimensions, bank reconciliation, period close, budgeting, tax, and financial reporting.

ARNA's intended differentiation is Amharic-first and mobile-first operation, domain-native integration with Governance/Wallet/Loan/Shareholder/Procurement, Ethiopian requirements handled through explicit versioned policy, and governed AI assistance that does not bypass human authority or accounting controls.

## 7. Release acceptance scorecard

A release candidate must provide evidence for each applicable item:

- [ ] No unbalanced journal can be posted.
- [ ] Monetary calculations avoid binary floating-point.
- [ ] Idempotent retries do not duplicate postings; changed-payload key reuse is rejected.
- [ ] Reversal/correction history is complete and immutable.
- [ ] Closed-period and unauthorized posting attempts fail.
- [ ] Tenant and legal-entity isolation is verified for reads, writes, and reports.
- [ ] Trial balance and statements reconcile to the ledger.
- [ ] Cash-flow opening/closing movement and classifications reconcile where applicable.
- [ ] Every active control account has a named owner and documented reconciliation process.
- [ ] Each enabled adapter has replay, exception, and source-to-ledger reconciliation evidence.
- [ ] Tax/framework applicability and unresolved decisions are explicitly recorded.
- [ ] Migrations and seeder behavior are reviewed; no unapproved opening balances are imported.
- [ ] Required destination GitHub workflows pass on the exact candidate SHA.
- [ ] Backup/restore, incident response, monitoring, and rollback are evidenced.
- [ ] Owner authorizations for merge, migration, posting activation, and deployment are separately recorded.

A failed or unverified critical item blocks the relevant release gate. Do not calculate a single readiness percentage by averaging unlike or unverified controls.

## 8. Long-horizon evolution (20–50 years)

- **0–12 months:** trustworthy ledger foundation, reports, explicit controls, and one-at-a-time reconciled integrations.
- **1–3 years:** deeper enterprise integrations, consolidation, planning, audit support, and improved operations.
- **3–7 years:** governed anomaly detection, forecasting, reconciliation assistance, and explainable AI suggestions with human approval.
- **7–15 years:** additional jurisdictions and legal entities through modular policy packs, not hard-coded country assumptions.
- **15–30 years:** technology replacement, API compatibility, data portability, schema evolution, and archival migration.
- **30–50 years:** institutional continuity, durable audit records, cryptographic agility, explainable decision history, and planned replacement of obsolete components.

The long-horizon items are strategic directions, not committed features or implementation status.

## 9. Immediate next step

**Run the Phase 0 exact-commit, read-only Finance baseline and decision-register reconciliation against destination GitHub.** Identify the current Finance PR chain and exact base/head SHAs; map code, routes, migrations, seeders, tests, and workflow coverage; verify the reporting implementation status; and enumerate the 36 control-account owner/procedure gaps. Produce one evidence-based register and select the next bounded implementation unit.

**Scope guard:** no local repository tests, database mutation, opening-balance import, production posting, adapter activation, merge, deployment, or unrelated-domain changes as part of this step. Existing workflow evidence may be inspected; new workflow runs require authorization. The Owner must accept the baseline before the next implementation gate.

## 10. Status vocabulary

- **VERIFIED:** directly demonstrated by evidence tied to the exact commit/environment.
- **PARTIAL:** some required behavior is evidenced, but acceptance is incomplete.
- **MISSING:** required artifact or behavior is absent from the inspected scope.
- **UNRESOLVED:** evidence or a decision is insufficient to conclude.
- **UNKNOWN:** not yet determined.
- **NOT RUN:** a specified verification has not been executed.

Every report must state the repository, branch, exact SHA, evidence links, relevant workflow outcomes, unresolved decisions, and the single next step. Do not claim implementation, compliance, or release readiness solely because this roadmap has been approved.
