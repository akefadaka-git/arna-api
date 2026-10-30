# ARNA Finance & Accounting — General Ledger Architecture Contract

- **Contract ID:** ARNA-FIN-ARCH-001
- **Version:** 1.1.0
- **Status:** **PHASE 0 CLOSED — architecture and technical baseline established**
- **Owner:** ARNA Owner
- **Applies to:** destination repository `akefadaka-git/arna-api`
- **Implementation target:** PHP 8.3 / Laravel 12; tenant-isolated; Amharic-first
- **Important:** Phase 0 closure is not production acceptance, accounting-policy approval, migration approval, or deployment authorization.

## 1. Phase 0 closure statement

Phase 0 establishes the authoritative Finance domain boundary, entity model, ledger ownership, technical invariants, currency baseline, source-domain boundaries, security principles, and release gates. The contract is sufficiently defined to govern continued implementation without creating parallel accounting registries or silently inventing policy.

Phase 0 is closed for architecture baseline purposes. Items explicitly listed as policy-dependent, evidence-dependent, or later-phase work remain blocked from activation. They do not reopen the completed architecture baseline; they remain mandatory gates for the relevant implementation and release phases.

## 2. Authoritative architecture decisions

1. **Finance owns the general ledger.** Finance is the authoritative system of record for posted accounting journals, ledger balances, accounting periods, chart-of-accounts assignments, and Finance audit history.
2. **Operational domains retain source ownership.** Loan owns loan lifecycle and loan subledger facts; Wallet owns wallet obligations, payments, allocations and reversals; Shareholder owns the shareholder register and holding events; Procurement and People own their respective operational facts. Finance consumes verified, approved source events or read contracts. A source-domain balance is not itself a GL balance.
3. **Separate legal-entity books.** Each legal entity has its own chart of accounts, accounting periods, journals, and ledger balances. Every Finance record and operation is scoped to both tenant and legal entity.
4. **Consolidation is a read model.** Consolidated reporting may read explicitly selected entity books. It must not merge, mutate, or erase entity-level books. Intercompany elimination requires a separately approved policy and reproducible evidence.
5. **ETB baseline.** ETB is the default Finance base currency under the Owner's project direction. The legal entity's currency is explicit in its record. Foreign-currency journal posting remains disabled until a separately approved FX contract defines rate source, effective time, precision, rounding, realized/unrealized treatment, and audit provenance.
6. **No opening balances by inference.** No opening balances, cash, receivables, payables, inventory, retained earnings, shareholder capital, or other balances may be fabricated from examples, current operational totals, or unverified legacy data. Cutover requires reconciled source evidence and separate Owner authorization.
7. **Canonical identity.** Finance uses the canonical `MaaleArna\\MASES\\Core\\Auth\\Models\\User` model. Tenant context is resolved by the established tenancy middleware. A client-supplied tenant identifier is never authoritative.
8. **No parallel registries.** The implemented schema names are `legal_entities`, `ledger_accounts`, `accounting_periods`, `journal_entries`, and `journal_lines`. These names are grounded in the current Finance migration. Alternative `finance_*` names in design proposals are logical proposals only; they do not authorize duplicate tables, entity registries, or currency catalogues.

## 3. Technical accounting invariants

### 3.1 Tenant and legal-entity isolation

- Resolve tenant context server-side from the authenticated request and tenancy middleware.
- Validate that the selected legal entity belongs to the resolved tenant and is active for the requested operation.
- Scope account, period, journal, journal-line, mapping, audit, and report queries by tenant and legal entity.
- Enforce same-entity references for journal periods, journal lines, accounts, parents, and reversals at both application and database levels where supported.
- Missing, inactive, conflicting, or cross-tenant context fails closed.
- Never accept a client-supplied tenant ID as an authority override.

### 3.2 Monetary representation

- Monetary values are decimal strings or database decimal values; binary floating-point arithmetic is prohibited.
- The current GL storage baseline is four fractional digits for journal debit/credit amounts (`DECIMAL(20,4)`).
- Comparisons, balancing, aggregation, and idempotency fingerprints must be deterministic and must not cast money to PHP float.
- Four-place ledger storage is a technical implementation baseline, not a declaration that every currency has four minor units or that all FX/rounding policies have been approved.
- Currency-specific display, transaction-currency precision, FX rate scale, rounding mode, and statutory presentation remain separately governed decisions.

### 3.3 Chart of accounts

- The current canonical implementation baseline is CoA v1.0.0 with 79 account definitions and English/Amharic labels, hierarchy, account type, normal balance, posting eligibility, control-account designation, domain mapping, and provisional tax-routing category.
- The current control-account register covers 36 control accounts and defines accountable functions, contributing domains, frequency, evidence, and independent reviewer role.
- Named accountable people remain unassigned; role descriptions are not personnel appointments.
- Control-account adapters remain blocked until the Owner or authorized management designates accountable people and approves reconciliation procedures.
- The CoA seeder remains opt-in and must not be registered in `DatabaseSeeder` without separate approval. It creates no opening balances and must not overwrite matching non-system accounts.
- Tax categories are routing metadata only, not rates or approved transaction tax decisions.

### 3.4 Journal lifecycle and posting

- A journal contains at least two lines.
- Each line has exactly one positive debit or credit side; zero-sided and two-sided lines are rejected.
- A journal must balance exactly at the configured ledger precision before posting.
- Every line references an active, postable account belonging to the same tenant and legal entity.
- Drafts may be changed only through authorized draft operations.
- Posted journal headers and lines are immutable. Corrections use a separately authorized, linked reversing journal and, when required, a replacement journal. Posted history is never silently edited or deleted.
- Posting is permitted only within an open accounting period and within that period's date range.
- Journal header/lines, posting state, source-event claim, and audit evidence must be committed atomically.
- Reversal preserves the original journal, links the reversal, records actor/time/reason, and creates equal-and-opposite lines. A reversal is not a deletion.
- Journal preparation, approval, posting, reversal, reconciliation, and period close are separate capabilities. No role, approval threshold, or financial authority is inferred from a code default.

### 3.5 Idempotency and source-event integrity

- The technical idempotency fingerprint includes a canonical payload hash.
- A retry using the same scoped idempotency key and same payload must return the original persisted result.
- Reuse of the same scoped key with a different payload must return a conflict; it must never overwrite the original transaction.
- The required source-event identity for automated adapters is tenant + legal entity + source domain + immutable source event ID + event version (or a formally equivalent stable source identity).
- The source-event identity must be protected by a database uniqueness guarantee, not merely by a caller-supplied arbitrary key.
- Until each source domain's event identity, versioning, reversal linkage, transaction boundary, and replay guarantee have been verified, automated posting adapters remain disabled.
- A missing or ambiguous source mapping is a visible exception; Finance must not guess a ledger account or silently discard an event.

### 3.6 Period and audit controls

- Periods are legal-entity scoped and have explicit date windows and lifecycle status.
- Period overlap, posting/closing races, and concurrent close attempts require transactional or database-backed protection.
- Closing is authorized, transactional, reasoned, and audited. Reopening requires a separate high-authority capability, reason, and retained history; no silent reopen is allowed.
- Financial lifecycle events must retain actor, tenant, legal entity, subject, event type, time, relevant before/after state, reason, and source/reversal linkage where applicable.
- Audit records must remain queryable under least-privilege authorization and must not be cascade-deleted with operational records.

## 4. Domain integration boundaries

- Loan, Wallet, Shareholder, Procurement, and People remain owners of their operational transactions and source balances.
- Finance owns approved accounting mappings and GL journal generation.
- A source event must carry stable identity, tenant, legal entity or verified entity mapping, event version, effective/event time, currency, exact amount components, event semantics, reversal/correction linkage, and replay semantics.
- Source integrations must avoid double recognition (for example, a wallet payment and its allocation) and must distinguish principal, interest, fees, settlement, accrual, refund, reversal, write-off, and non-cash register events.
- No adapter is activated solely because an account or permission exists.
- Source-to-entity mappings must be approved, effective-dated where necessary, auditable, and fail closed when ambiguous.

## 5. Reporting boundary

- Ledger-derived reports include only posted accounting activity and the linked reversal activity required to preserve net accounting history.
- Trial balance and account activity must reconcile to posted journal lines.
- Reports identify tenant, legal entity, reporting period/as-of date, currency, basis, and relevant limitations.
- Current source includes trial balance, period income statement, as-of balance sheet, and explicit cash-movement reporting.
- The current cash-flow endpoint is a period movement report only. It is not a complete IAS 7 cash-flow statement: opening/closing cash balances, cash-equivalent reconciliation, FX presentation, and entity consolidation remain outside its current claim.
- Current income-statement and balance-sheet code is source-level implementation; no runtime correctness or statutory compliance is implied until exact-head verification passes.

## 6. Explicitly deferred decisions and activation gates

The following remain unresolved or blocked and must not be inferred from the technical architecture:

- accounting basis and recognition timing for each source-domain event;
- fiscal year and period calendar, including any legally required calendar;
- currency-specific precision, FX rate source, rounding, revaluation, and gain/loss treatment;
- Ethiopian tax treatment, rates, filings, and statutory reporting;
- adopted approval matrix, financial limits, statement signatories, and named preparer/approver/poster/reconciler appointments;
- source-domain event contracts and complete event-to-accounting mappings;
- named control-account reconciliation owners and approved reconciliation procedures;
- intercompany accounting and consolidation/elimination rules;
- opening-balance cutoff, evidence pack, reconciliation, and authorization;
- financial record retention, export control, legal hold, and audit access policy.

The Owner has directed continued code generation and deferred external tax/accounting review for now. Therefore account-level tax categories remain provisional routing defaults. This direction does not authorize tax calculations, statutory tax reporting, or production tax activation.

## 7. Phase 0 acceptance record

| Acceptance item | Disposition |
|---|---|
| Finance GL ownership and source-domain boundaries | CLOSED — documented |
| Multi-entity books and tenant/entity scoping | CLOSED — documented |
| ETB baseline and no-FX activation boundary | CLOSED — documented |
| Canonical identity and tenant-context authority | CLOSED — documented |
| Exact-decimal and ledger-scale technical baseline | CLOSED — documented; policy-specific rounding deferred |
| Journal lifecycle, balance, immutability, and reversal invariants | CLOSED — documented |
| Idempotency and source-event identity requirements | CLOSED — contract defined; implementation verification remains in later gate |
| Period and audit-control requirements | CLOSED — documented; concurrency verification remains in later gate |
| CoA and control-account governance boundaries | CLOSED — documented |
| Opening-balance prohibition | CLOSED — hard block |
| Reporting and consolidation boundary | CLOSED — documented |
| Tax, FX, source adapters, named appointments, statutory policy | DEFERRED — explicit later-phase gates |

**Phase 0 result: CLOSED for architecture and technical-contract baseline.** This is not a statement that all Phase 1 code is complete or that the Finance domain is production-ready.

## 8. Implementation and release gates

- **Phase 0 — Architecture baseline:** CLOSED by this contract and the reconciled implementation register.
- **Phase 1 — Core GL integrity:** implement and verify posting invariants, source-event idempotency, immutability, reversals, period race protection, audit atomicity, and tenant/entity isolation.
- **Phase 2 — CoA and control accounts:** verify canonical register, hierarchy, seeder preflight, ownership coverage, and activation restrictions; no opening balances.
- **Phase 3 — Financial reporting:** verify trial balance, account activity, income statement, balance sheet, cash movement, and report authorization at exact candidate SHA.
- **Phase 4 — Subledger integration:** complete read-only source audits and event-to-GL mapping for Loan, Wallet, Shareholder, Procurement, and People before adapter implementation or activation.
- **Phase 5 — Period close and consolidation:** implement close/reopen controls, entity reporting, and approved intercompany/consolidation rules.
- **Phase 6 — FX and statutory/tax policy:** implement only after the required Owner decisions and qualified policy review; tax review may remain deferred during code generation.
- **Phase 7 — Verification and release:** exact-head tests, migration/rollback checks, CI, security and reconciliation evidence, pilot acceptance, and separately authorized production release.

## 9. Production acceptance — separate from Phase 0

Before production acceptance, a clean disposable MySQL migration and rollback must succeed on the exact candidate SHA; tenant/entity isolation and authorization must be proven; balanced posting, immutability, reversals, source-event uniqueness, concurrent retry behavior, period-close races, and audit atomicity must pass; ledger reports must reconcile; and exact-head tests/CI must pass. Opening balances and production cutover require verified evidence and separate authorization. Production database mutation, merge, deployment, and production posting are not authorized by closing Phase 0.
