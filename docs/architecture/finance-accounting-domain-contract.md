# ARNA Finance & Accounting Domain Contract and Schema Design

**Document ID:** ARNA-FIN-CONTRACT-001  
**Version:** 0.1.0  
**Status:** Architecture baseline proposed for Owner decision; not an implementation or production-release authorization  
**Repository baseline:** arna-api main at ccb2292e1c3374568cc3c3da923c6c103dcbab09  
**Branch:** feat/finance-accounting-contract

## 1. Authoritative boundaries

Finance & Accounting is authoritative for the general ledger, chart of accounts, journal posting, accounting periods, legal-entity books, trial balances, financial statements, and consolidation.

It must not replace or duplicate existing operational subledgers:
- **Loan** owns applications, contracts, schedules, installments, repayment allocations, interest/penalty accruals, restructurings, and write-offs.
- **Wallet** owns wallets, obligations, payments, allocations, wallet entries, and reversals.
- **Shareholder** owns share classes, holdings, share movements, and certificates.
- **People** owns canonical natural-person identity.
- **Organization** owns structural units and assignments, not accounting policy.
- **Governance** owns governance bodies, appointments, delegations, and authority records.
- **Documents & Records** owns formal document lifecycle; Core Files remains binary storage and file-version metadata.

Finance consumes approved, idempotent source events or controlled posting requests. It must not directly mutate source-domain tables to “correct” accounting. Reconciliation exceptions return to the source domain or enter an explicitly authorized accounting adjustment workflow with evidence.

## 2. Mandatory invariants

1. Every posted journal belongs to one tenant, legal entity, accounting period, and currency context.
2. Each journal has at least two lines; debits equal credits under the approved transaction- and functional-currency rounding policy.
3. Monetary values use exact decimal arithmetic and database DECIMAL columns, never binary floating point.
4. Posted journals and lines are immutable. Corrections use linked reversal and replacement journals.
5. Draft editing and posting are separate permissions and state transitions.
6. Posting, period checks, balance validation, source idempotency, account validation, and ledger writes occur in one transaction.
7. A source event posts at most once per tenant/legal entity/source-domain/source-event key. Same key and same canonical payload returns the existing result; same key with a different payload is a conflict.
8. Tenant and legal-entity access are checked server-side and fail closed. Tenant membership alone is not posting authority.
9. Closed periods reject ordinary posting. Reopening requires separately authorized, reasoned, audited action.
10. Account and entity identifiers are stable; localized labels are separate.
11. No opening balances or fabricated historical transactions may be seeded. Opening balances require approved, independently reconciled evidence.
12. Reports derive from posted ledger data and identify the cutoff and accounting basis.

## 3. Proposed logical schema

Exact FK types, index lengths, engine compatibility, and migration discovery must be checked against current repository conventions before implementation. Reuse a canonical legal-entity registry if one exists and is accepted; do not create a duplicate registry.

### finance_accounts — chart of accounts
- ID, tenant ID, canonical legal-entity ID, stable account code.
- English/Amharic labels and optional description.
- Account type: asset, liability, equity, revenue, expense.
- Normal balance: debit or credit.
- Optional parent account, postable/control-account flags, status, effective dates, audit metadata.
- Unique account code per legal entity. Parent must be same tenant/entity. Reject self-parenting and cycles. Only active postable accounts accept new journals.

### finance_periods — accounting periods
- Tenant/entity, fiscal-year code, period code, start/end date, state (open, closing, closed).
- Opened/closed by, timestamps, reason, and auditable reopen history.
- Periods for the same entity/calendar cannot overlap. Posting date must fall within exactly one open period.

### finance_journals — journal header
- Tenant/entity/period; unique journal number and numbering scope.
- Journal type, source type/ID/source event key, transaction date, posting date.
- Transaction and functional currency, exchange-rate reference and immutable rate snapshot when relevant.
- Status (draft, pending approval if required, posted, reversed), description/reference, creator/submitter/poster, posted/reversed timestamps, linked reversal journal.
- Canonical source-payload hash and idempotency key.

### finance_journal_lines — debit/credit lines
- Tenant, journal, line number, account, optional source-subledger/source-record reference.
- Description; debit and credit in transaction and functional currency; currency and FX snapshot/reference.
- Exactly one positive side per line (debit XOR credit); nonnegative DECIMAL amounts at approved scale.
- At least two lines per journal; debit total equals credit total under approved rounding policy. Tenant/entity scope must agree across journal, line, and account. Posted lines cannot be edited or deleted.

### finance_posting_sources — idempotency/inbox
- Tenant/entity, source domain, source event key, canonical payload hash, event schema version, received timestamp.
- Processing state, resulting journal ID, failure classification, retry count, correlation/request ID, audit timestamps.
- Unique key: tenant + legal entity + source domain + source event key. Payload mismatch for an existing key is a conflict.

### finance_journal_approvals — only if policy requires approval
- Journal, submitted revision/hash, approver, decision, reason, authority reference, timestamp.
- Maker-checker prevents a preparer from approving their own journal where policy requires it. Approval and posting authority are distinct.

### Audit
Prefer the established shared audit service if it meets append-only finance requirements. Do not create duplicate audit infrastructure without evidence of a gap. Record actor, tenant/entity, action, target, state/hash, reason, correlation ID, and timestamp.

## 4. Source-domain posting contracts

| Source | Candidate events | Boundary |
|---|---|---|
| Loan | Disbursement, repayment allocation, interest/penalty accrual, write-off, reversal | Map only verified business events; never infer accounting from a UI status |
| Wallet | Obligation, payment, adjustment, reversal | Wallet remains operational source; post approved event versions |
| Shareholder | Share issue/transfer/buyback/dividend, if supported | Do not assume every holding entry is a cash transaction |
| Procurement (future) | Receipt, supplier invoice, payment, credit note | Integrate after procurement approval contract exists |
| Sales (future) | Invoice, delivery/revenue recognition, receipt, credit note | Revenue recognition and tax treatment require approved policy |
| Manual journal | Controlled adjustment | Reason, supporting document, permissions, approval policy, audit required |

Every adapter must specify event name/version, source key, tenant/entity, currency/precision, effective date, mapping version, retry and reversal semantics, and reconciliation query. Unknown mapping, invalid account, closed period, unknown entity, or currency mismatch must be quarantined; never use a default/suspense account without explicit policy.

## 5. Reconciliation and reporting

Mandatory initial reports: trial balance by entity/period/account; account activity and closing balance; journal register with reversal links; source-to-ledger reconciliation; failed/quarantined source events; period-close checklist and variance report. Financial statements and consolidation follow only after statement mappings, intercompany rules, elimination policy, and FX policy are approved.

Every report identifies tenant, legal entity, currency basis, period/cutoff, posting filter, and generation timestamp. Consolidation is not merely adding entity trial balances.

## 6. Authorization

Proposed permissions for mapping into the existing permission authority:
- finance.account.view / finance.account.manage
- finance.period.view / finance.period.manage / finance.period.close / finance.period.reopen
- finance.journal.view / finance.journal.create / finance.journal.edit / finance.journal.submit / finance.journal.approve / finance.journal.post / finance.journal.reverse
- finance.reconciliation.view / finance.reconciliation.resolve
- finance.report.view / finance.report.export
- finance.mapping.manage

Use existing tenant membership, permission authority, audit service, and Governance authority records. Never infer finance rights from Organization hierarchy, job-title text, frontend state, or tenant membership. Approval limits and acting/delegated powers require a valid effective-dated authority record with scope, limit, expiry, and source document.

## 7. Implementation gates

**FIN-A1 — Decisions:** confirm canonical legal-entity registry, functional currency, fiscal calendar, account-code convention, journal approval policy, FX policy, tax boundary, and opening-balance evidence process.

**FIN-A2 — Schema and invariant tests:** migrations, FK/unique/check constraints, tenant/entity isolation, account hierarchy cycle tests, period-overlap tests, precision tests, posting atomicity, idempotency conflict, reversal immutability, and real MySQL concurrency tests.

**FIN-A3 — Core ledger:** account/period services, draft journals, balanced posting, reversals, posting-source inbox, audit integration, and permissions. No source adapters before core invariants pass.

**FIN-A4 — Subledger adapters:** inventory Loan and Wallet events first; define mapping from verified semantics. Integrate Shareholder only for confirmed accounting events.

**FIN-A5 — Reporting and close:** trial balance, ledger, reconciliation, period close, then financial statements/consolidation after policy approval.

Production acceptance requires exact-SHA review, fresh disposable MySQL migration/seed/repeat migration tests, genuine concurrent posting tests, tenant/entity isolation, idempotency and conflict behavior, immutable posted journals, reviewed source-to-ledger reconciliation, and approved accounting policy. No production database or deployment action is included.

## 8. Decisions intentionally not invented

This contract does not set tax rates, depreciation, revenue-recognition policy, materiality thresholds, approval amounts, account numbers, opening balances, fiscal-year dates, FX source, or statutory statement formats. These require explicit accounting/business approval.

**Disposition:** logical schema and domain boundaries established; implementation not started. This document alone does not authorize ledger migrations or posting code.
