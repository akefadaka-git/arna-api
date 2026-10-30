# ARNA Financial Statements API — v1.0.0

## Implemented report

### Income statement

- Endpoint: `GET /api/finance/income-statement`
- Required query parameters: `legal_entity_id`, `from` (inclusive ISO date), `to` (inclusive ISO date).
- Authorization: authenticated tenant member with `finance.report.view`.
- Entity scope: the legal entity must belong to the resolved tenant.
- Source: posted and reversed journal entries whose effective dates fall within the requested period. Draft and cancelled journals are excluded.
- Currency: the legal entity's configured base currency.
- Monetary aggregation: decimal-string arithmetic at the ledger's four fractional digits; binary floating point is not used.
- Output: per-account debit and credit activity, signed account amount, total revenue, total expenses, and net income.

Revenue is calculated as credits less debits; expenses are debits less credits. Therefore a net debit posted to a revenue account or a net credit posted to an expense account is represented as a negative amount rather than silently clamped to zero. Net income is revenue credits plus expense credits less revenue debits and expense debits.

## Balance sheet

- Endpoint: `GET /api/finance/balance-sheet`
- Required query parameters: `legal_entity_id` and `as_of` (ISO date).
- Authorization: authenticated tenant member with `finance.report.view`.
- Source: posted and reversed journal activity through the requested date.
- Asset accounts are presented as debit less credit; liability and equity accounts as credit less debit. Unclosed cumulative revenue/expense activity is included as a separate `current_earnings` equity amount.
- The response reports total assets, liabilities, equity-account balances, current earnings, total liabilities and equity, and an arithmetic balance check.
- This is a ledger-derived presentation; it does not replace approved period-close, retained-earnings transfer, consolidation, or statutory financial-statement policies.

## Tax and activation boundary

This report aggregates ledger postings only. It does not calculate VAT, withholding, income tax, or tax return liabilities. Account-level tax defaults remain provisional routing hints per Owner direction; transaction-level tax treatment must not be inferred from this report.

## Verification and release status

- Code committed to a feature branch for review.
- Repository tests: NOT RUN per Owner instruction.
- GitHub Actions/workflows: NOT RUN; commits use `[skip ci]`.
- Database migration/seeder execution: NOT RUN.
- Production reporting, posting, and deployment: NOT authorized or performed.
