# ARNA Finance & Accounting — Contract Reconciliation and Schema Elaboration

- **Document ID:** ARNA-FIN-ADR-001
- **Status:** Companion A1 design proposal; not a replacement for the canonical Finance contract.
- **Canonical contract:** [`docs/architecture/finance-accounting-domain-contract.md`](https://github.com/akefadaka-git/arna-api/blob/main/docs/architecture/finance-accounting-domain-contract.md) (current main blob `a28040d3926973004675aaf5bbe0760cd5b4565a`)
- **Baseline:** `main` at `e853874ee41130d47be07d2ffc097a41ecb69a9b`
- **Branch:** `docs/finance-accounting-domain-contract`
- **Purpose:** Reconcile the existing contract with current source-subledger evidence and elaborate the logical schema, integration guarantees, and unresolved implementation decisions.
- **Explicit exclusions:** No application code, migrations, seed data, opening balances, production database changes, deployment, or merge.

> The canonical Finance contract already defines the main GL boundary and logical tables. This companion does not create a second Finance specification. Where table names differ, the canonical contract names below are preferred: `finance_periods`, `finance_posting_sources`, and optional `finance_journal_approvals`. Reuse a verified shared legal-entity/currency catalogue rather than creating duplicate registries.

## 1. Contract summary

Finance & Accounting owns the authoritative **posted general ledger (GL)** and accounting-period controls. It does not replace operational domain records.

- **Wallet** remains authoritative for wallet obligations, payments, allocations, reversals, and wallet statements.
- **Loan** remains authoritative for loan lifecycle events, disbursements, instalments, accruals, repayments, and write-offs.
- **Shareholder** remains authoritative for shareholder registry, share classes, holdings, holding entries, and certificates.
- **Finance** consumes approved, uniquely identified business events from those domains and posts balanced accounting journals according to approved accounting mappings.
- A subledger balance is not itself a GL balance. No domain may write directly into another domain's tables to simulate a posting.
- Each legal entity has its own books and accounting periods. Consolidation is a reporting layer over approved entity books; it must not merge or mutate source books.
- Base/reporting currency default: **ETB**, subject to Owner confirmation of the reporting policy. Currency minor-unit and rounding rules must be explicit; do not infer them from PHP floats or database defaults.
- **Opening balances remain prohibited** until verified against approved source records and explicitly authorized.

## 2. Evidence-based current assessment

The current `main` already contains a Finance & Accounting domain contract (`docs/architecture/finance-accounting-domain-contract.md`, blob `a28040d3926973004675aaf5bbe0760cd5b4565a`). That is the canonical architecture baseline, not evidence of an implemented GL. Source searches did not establish implemented GL tables/services for chart of accounts, balanced posting, periods, trial balance, or consolidation. Treat the GL runtime as **MISSING in inspected source paths / NOT YET VERIFIED exhaustively**, while treating the existing architecture contract as **EXISTS**. This companion records the delta and elaborates implementation invariants; it must not be mistaken for a second canonical contract.

The existing Governance delegation package is deliberately non-financial: its documented action catalogue covers appointment and membership management. It does not authorize financial posting or approval thresholds.

Before implementation, inventory and map actual tables, migration order, source event identities, reversal semantics, amount/currency precision, and tenant/legal-entity relationships for each subledger. Do not infer accounting meaning from similarly named fields.

## 3. Domain boundaries and invariants

1. **Tenant and legal-entity isolation:** every account, journal, period, mapping, reconciliation, and report query is scoped to a validated tenant and a legal entity. A legal entity must belong to that tenant. Missing, inactive, or conflicting context fails closed.
2. **Exact decimal arithmetic:** use decimal strings / decimal-capable value objects end-to-end; never use binary floating point for money, rates, rounding, or balance comparisons.
3. **Balanced journal:** a journal cannot be posted unless total base-currency debits equal total base-currency credits exactly at the defined ledger scale, each line is valid, and all referenced accounts are postable for the date/entity.
4. **Draft vs posted:** drafts may be edited under permission. A posted journal is immutable. Correction is by a linked reversing journal and, if needed, a replacement journal—not by changing or deleting posted lines.
5. **Atomic posting:** journal header, lines, source-event claim, posting timestamp, and audit record commit in one transaction. Concurrent retries must not create duplicate postings.
6. **Idempotency:** source identity is a tuple such as `tenant_id + legal_entity_id + source_domain + source_event_id + event_version`. Same key and same canonical payload returns the original posting; same key with different payload returns a conflict and records a security/audit signal. The exact key must be agreed with source domains before coding.
7. **Period control:** posting requires an open period and permitted accounting date. Closing is transactional, authorized, audited, and guarded against concurrent posting. Reopening requires an explicit high-authority permission, reason, and retained history; do not silently reopen.
8. **Segregation of duties:** preparing, approving, posting, reversing, reconciling, and closing are distinct permissioned capabilities. Actual approver identities and thresholds remain policy decisions until supported by approved instruments.
9. **No silent loss:** never cascade-delete posted accounting history. Legal-entity/account deactivation blocks future use but preserves historical reports.
10. **Auditability:** actor, tenant, legal entity, source identity, request/correlation ID, before/after state, reason, timestamps, and reversal linkage are retained.
11. **No invented balances:** do not seed chart balances, receivables, payables, retained earnings, cash, share capital, or opening journals from examples or current operational totals without verified cutover evidence and Owner authorization.

## 4. Proposed relational schema

Names below are proposals, not existing tables. Use Laravel migration conventions and database-supported constraints only after checking the production MySQL version and current migration practices.

### 4.1 Legal entities and currency policy

**`finance_legal_entities` (conditional; do not create if a canonical registry is adopted)**
- `id`, `tenant_id`, stable `entity_code`, localized `name`, `status`, `functional_currency_code`, effective dates, timestamps.
- Unique: `(tenant_id, entity_code)`.
- Functional currency is explicit per entity; ETB is the proposed default, not a reason to force every entity's functional currency to ETB.

**`finance_currencies`** (or a controlled shared currency catalogue if one already exists)
- ISO-like `currency_code`, display metadata, `minor_unit_scale`, enabled status, effective dates.
- Currency precision and allowed rates are controlled data, not hard-coded assumptions. Confirm the canonical currency catalogue before creating a duplicate.

**`finance_periods` (canonical table name)**
- `id`, `tenant_id`, `legal_entity_id`, period code, start/end dates, status (`open`, `closing`, `closed`), closed/reopened actor and timestamps, reason, optimistic version.
- Unique period code per entity; no overlapping periods for the same entity. Enforce overlap and close/post race behavior transactionally; do not rely only on a preflight query.

### 4.2 Chart of accounts

**`finance_accounts`**
- `id`, `tenant_id`, `legal_entity_id`, stable `account_code`, localized names, account class (asset/liability/equity/revenue/expense), normal balance, optional `parent_account_id`, `is_postable`, status, effective dates.
- Unique `(tenant_id, legal_entity_id, account_code)`.
- Parent must belong to the same tenant and legal entity; reject cycles; non-postable headers cannot receive journal lines.
- Account class, code ranges, required dimensions, and default chart are Owner-approved accounting-policy decisions. Do not seed a speculative chart as authoritative.

### 4.3 Journals and lines

**`finance_journals`**
- `id`, `tenant_id`, `legal_entity_id`, journal number, journal type, status (`draft`, `pending_approval`, `approved`, `posted`, `reversed`), accounting date, period ID, description, source-domain/source-event identity and event version, canonical payload hash, idempotency key, created/approved/posted actor and timestamps, request ID, reversal-of journal ID, reason.
- Unique journal number within legal entity and fiscal-year/numbering scope; unique source-event idempotency tuple.
- A draft/approval flow may be simplified only if the approved authority matrix explicitly permits it; never silently equate permission to create with permission to post.

**`finance_journal_lines`**
- `id`, `tenant_id`, `legal_entity_id`, `journal_id`, line number, `account_id`, description, transaction currency, exact transaction debit/credit, exact base debit/credit, FX rate/reference/date when applicable, optional approved dimensions, source reference.
- Unique `(journal_id, line_number)`.
- Each line must have non-negative values and exactly one positive side in each amount basis; zero-value lines are rejected. Base debit/credit totals must balance exactly before posting.
- Suggested storage baseline: decimal columns with sufficient fixed scale for transaction/base amounts and a higher fixed scale for FX rates. Final precision/scale must be decided after reviewing source-domain maximums, currency rules, MySQL behavior, and rounding policy; it is **not set by this contract**.
- Store the applied rate and policy/source for reproducibility. Do not recalculate historical lines from a later FX rate.

### 4.4 Posting rules and subledger bridge

**`finance_account_mappings`**
- `id`, tenant, legal entity, source domain, source event type, source account/category code, debit/credit account IDs, optional effective dates, approval provenance, status.
- Unique active mapping for a specific source event/category and effective scope. Mapping changes are versioned/effective-dated and cannot rewrite posted journals.
- A source event with no unique valid mapping must be held as an exception; never post to a generic suspense account without approved policy.

**`finance_posting_sources` (canonical table name)**
- `id`, tenant, legal entity, source domain, source event ID, event version, payload hash, journal ID, status, received/posted timestamps, error code/reference.
- Unique source-event idempotency tuple. Stores delivery/processing status; source operational data remains owned by the source domain.
- Source domains publish committed business events or provide a stable read API/outbox. Finance must not read half-committed state or mutate subledger rows.

**`finance_reconciliation_runs`** and **`finance_reconciliation_items`**
- Run: tenant, entity, source domain, date range/cutoff, run status, source snapshot/reference, GL cutoff, started/completed actor/timestamps, summary totals and exception count.
- Item: run, source reference, expected amount/currency, GL journal/line reference, matched amount, difference, status, reason/resolution provenance.
- A reconciliation result is not proof of correctness unless the source cutoff and GL cutoff are reproducible and the exception set is retained.

### 4.5 Optional after policy approval

- `finance_exchange_rates`: approved rates, source, effective time, precision, and approval provenance.
- `finance_dimensions`: cost centres, departments, projects, and their legal-entity scope.
- `finance_consolidation_groups`, `finance_consolidation_memberships`, `finance_intercompany_mappings`, and consolidation/elimination snapshots. These require explicit consolidation policy and legal-entity relationship evidence.
- Budget/planning tables are a later bounded domain; do not mix budgets with posted actuals.

Do not store approver chains in free-form JSON or build a generic workflow engine as a substitute for the governance authority model.

## 5. Subledger integration contract

Each source domain must provide, at minimum:
- stable tenant and source-record identity;
- explicit legal-entity ownership or a verified deterministic mapping to one;
- immutable event identity/version and event timestamp;
- currency and exact amount components;
- event semantics (recognition, settlement, reversal, write-off, accrual, etc.);
- linkage to the original event for reversals/corrections;
- idempotent delivery/replay semantics;
- a reconciliation extract/API at a defined cutoff.

Finance owns mapping from approved event types to GL accounts and journal generation. Source domains own event correctness and source balance calculations. A failed/ambiguous mapping enters a visible exception queue and is not silently dropped or guessed.

Required source mapping assessment before implementation:
- **Wallet:** distinguish obligation recognition, payment receipt/disbursement, allocation, adjustment, and reversal; avoid double-counting a payment and its allocation.
- **Loan:** distinguish principal disbursement/receivable, interest/penalty accrual, cash receipt, allocation, write-off, and reversal; determine accounting policy for accrual basis and recognition before journal rules are created.
- **Shareholder:** distinguish subscribed/issued capital, paid-in capital, transfers between holders, and non-cash register events; a holding transfer is not automatically a GL transaction.
- Map each event only after tracing its authoritative service, database transaction, reversal behavior, currency, tenant and legal entity.

## 6. Reports and close controls

First release should provide:
- account activity/general ledger;
- trial balance with opening, period movement, and closing totals derived only from posted journals;
- journal register and reversal chain;
- source-to-GL reconciliation status and exception report;
- period close checklist and immutable close evidence.

Later, after accounting policies are approved:
- income statement, balance sheet, cash-flow presentation, entity-level statements, consolidation and intercompany elimination;
- comparative periods and controlled restatement handling.

A report must state entity, period/cutoff, base currency, posting status, and generation time. Draft/unposted journals must never leak into posted-balance reports.

## 7. Required permissions (capabilities, not role assignments)

Candidate permission names:
- `finance.entity.view/manage`
- `finance.account.view/manage`
- `finance.journal.view/create/edit/submit/approve/post/reverse`
- `finance.period.view/close/reopen`
- `finance.mapping.view/manage/approve`
- `finance.reconciliation.run/view/resolve`
- `finance.report.view/export`

These are capability identifiers only. The role/position-to-permission and approval-threshold matrix is unresolved and must be grounded in approved governance instruments. No permission is granted merely by creating this document.

## 8. Acceptance and release gates

**A1 (this document):**
- [x] Existing domain boundaries and the absent/unverified GL gap recorded.
- [x] Proposed schema and integration invariants specified.
- [ ] Owner approves accounting basis, legal-entity ownership, currency precision/rounding, journal approval/posting separation, period-close/reopen authority, and chart-of-accounts policy.
- [ ] Read-only source audit maps every Wallet/Loan/Shareholder posting event and reversal to verified schema/service contracts.

**A2 (read-only):**
- Inspect actual migrations/models/services/tests for all source event types, amount/currency precision, tenant ownership, entity mapping, event transaction boundaries, reversal and idempotency behavior.
- Confirm whether a shared currency/tenant/legal-entity catalogue already exists before proposing duplicate tables.
- Produce event-to-accounting mapping register and exception policy.

**A3 (implementation, separately authorized):**
- Implement schema/services/tests only after A1 decisions and A2 mappings are accepted.
- No opening balances, speculative chart seeds, or silent automatic posting.
- Include MySQL concurrency, tenant/entity isolation, exact-decimal, idempotency, balanced journal, immutable reversal, and period-close race tests.

**A4/A5:**
- Verify the exact candidate SHA. Do not run Actions while the Owner's runner-startup/quota restriction is in effect.
- Deployment and production migration require separate authorization and verified cutover balances.

## 9. Open decision register

| ID | Decision | Current disposition |
|---|---|---|
| FIN-D01 | Legal entities and authoritative source for entity registry | UNRESOLVED |
| FIN-D02 | Functional/base/reporting currency per entity; ETB group default | ETB proposed; Owner confirmation required |
| FIN-D03 | Currency scales, amount/rate precision and rounding mode | UNRESOLVED; never use floats |
| FIN-D04 | Accounting basis and event-recognition rules by subledger event | UNRESOLVED |
| FIN-D05 | Chart of accounts, account-code rules and dimension requirements | UNRESOLVED |
| FIN-D06 | Journal preparer/approver/poster separation and limits | UNRESOLVED; no financial authority inferred |
| FIN-D07 | Fiscal calendar, period close/reopen authority and exceptions | UNRESOLVED |
| FIN-D08 | FX rate source, approval, timing and realized/unrealized gain/loss policy | UNRESOLVED |
| FIN-D09 | Intercompany transactions, consolidation scope and elimination rules | UNRESOLVED |
| FIN-D10 | Verified opening balances, cutoff date and evidence package | BLOCKED; no balances may be invented or seeded |
| FIN-D11 | Source-event delivery/outbox and replay guarantees | UNRESOLVED per domain |
| FIN-D12 | Retention, audit access, export controls and financial record legal hold | UNRESOLVED |

**Disposition:** schema and domain contract proposed; no ledger implementation or production readiness claim.