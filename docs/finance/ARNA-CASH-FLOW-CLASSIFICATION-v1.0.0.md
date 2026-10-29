# ARNA Cash-Flow Classification Contract v1.0.0

**Implementation status:** feature branch only; static implementation review pending. No migration, tests, CI workflow, endpoint or production activation has been run.

## Scope

This contract reports posted cash movements for one tenant and legal entity over an inclusive effective-date period. It is a read-only movement report; it does not yet claim to be a complete IAS 7 cash-flow statement because opening/closing cash balances, cash-equivalent reconciliation, investing/financing transaction review, and entity-specific reporting policy are separate requirements.

## Classification values

- `operating`: cash movements arising from principal revenue-producing and other operating activities.
- `investing`: cash movements related to acquisition/disposal of long-term assets and investments not treated as cash equivalents.
- `financing`: cash movements that change contributed equity and borrowings.
- `undetermined`: a cash movement with missing or invalid activity metadata. It remains visible and is never silently assigned to an activity.

The first three values are implemented by `CashFlowActivity`. `undetermined` is a report state, not an authorized posting classification.

## Data contract

- `ledger_accounts.is_cash_equivalent` defaults to false. No account is included merely because its code/name resembles cash. Each legal entity's cash and cash-equivalent accounts must be explicitly reviewed and enabled.
- `journal_lines.cash_flow_activity` stores the explicit classification on the cash movement line.
- `journal_lines.cash_flow_classification_note` records the rationale/evidence reference. The HTTP request contract and canonical journal service require a non-empty note whenever `cash_flow_activity` is assigned; the service also validates the enum and rejects classification on accounts not explicitly enabled as cash equivalents.
- Non-cash journal lines do not create cash movement rows.
- The report service independently validates both dates as real `YYYY-MM-DD` calendar dates and rejects a start date after the end date, including when called outside HTTP request validation.
- Tenant and legal-entity IDs are enforced across journal lines, journal entries and accounts in the report query.
- Only `posted` and `reversed` journal entry states are included, preserving original and reversal movements in the net movement report.
- Debit to a cash/equivalent account is treated as inflow; credit is treated as outflow. Amounts are accumulated as decimal strings using `MoneyArithmetic`, not binary floating point.
- The canonical journal service independently validates cash-flow activity and evidence notes so callers that bypass HTTP request validation cannot store an unknown activity or an unsupported classification.
- Report totals expose inflows, outflows, signed net movement, movement count and unclassified movement count.

## Fail-closed behavior

Missing or invalid classification becomes `undetermined`; it is not guessed from account code, account name, journal description, source domain or counterparty. Unclassified movements are listed with journal and account references. A caller should treat `has_unclassified_movements=true` as an exception requiring review before a statement is finalized.

## Known implementation boundary

The service reports period cash movements only. It does not compute opening/closing cash balances, reconcile payment-provider/wallet/bank subledgers, classify non-cash investing/financing transactions, apply foreign-exchange presentation rules, or consolidate multiple legal entities. Those require separate approved contracts.

The migration uses schema guards to avoid assuming every deployment has the Finance tables at migration time. It must still be reviewed against the actual migration order and supported database engines before execution. No local tests are authorized or claimed.
