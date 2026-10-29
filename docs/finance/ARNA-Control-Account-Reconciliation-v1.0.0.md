# ARNA Finance — Control-account reconciliation baseline v1.0.0

**Status:** Code-generated baseline. Role assignments are proposed defaults, not formal personnel appointments. All 36 canonical control accounts remain disabled.

## Source and scope

- Canonical account register: `config/finance/chart_of_accounts_v1.php`
- Reconciliation baseline: `config/finance/control_account_reconciliation_v1.php`
- Version: `1.0.0`

For each control account the register identifies the accountable function, independent reviewer function, minimum reconciliation frequency, and evidence expected. These are implementation defaults to be reviewed against the operating model and each legal entity.

## Fail-closed activation

Each row has null named-person identifiers, `designation_status=pending_owner_designation`, and `enabled=false`. A role is not a formally designated person. Before activation, the Owner or an authorized governance process must record the named accountable person and independent reviewer, approve the evidence schedule and frequency, and explicitly approve activation for the relevant legal entity. The same person must not be both accountable preparer and independent reviewer for the same period.

This configuration does not enable adapters, automated posting, opening balances, or tax classification. No runtime code consumes this register yet; it is a configuration artifact for the next integration tranche.

## Minimum reconciliation record

For each period retain account and legal entity, period/as-of date, GL balance, source/subledger balance, reconciling items and age, evidence references, preparer, reviewer, review timestamp, approval status, and resolution evidence. Unresolved differences must be visible and escalated; they must not be silently written off.

## Verification status

Generated from the 79-account canonical register. Static source enumeration identified 36 rows marked as control accounts and posting-eligible. No tests, CI/workflows, migrations, seeders, database writes, or production operations were run for this change. The file remains unverified at runtime.
