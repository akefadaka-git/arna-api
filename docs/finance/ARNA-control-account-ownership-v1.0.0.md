# ARNA Control-Account Ownership Register v1.0.0

**Status:** Implemented as a role-based accountability baseline. Named personnel appointments, independent approval, and production reconciliation readiness are **NOT CONFIRMED**.

## Canonical configuration

- Account register: `config/finance/chart_of_accounts_v1.php`
- Accountability policy: `config/finance/control_account_ownership_v1.php`
- Acceptance coverage: `tests/Unit/FinanceControlAccountOwnershipTest.php`
- Version: `1.0.0`

The ownership policy assigns every control account an accountable role, preparation role, independent review role, reconciliation frequency, minimum evidence schedule, and escalation role. Account codes are the join key to the canonical CoA register.

## Assignment rules

1. These are functional roles, not invented or inferred employee appointments.
2. Before production use, an authorized Owner/manager must assign an active user to each role, confirm the role holder's authority, and record the effective date.
3. The preparer and reviewer must be different people. Where practical, the accountable role holder must not prepare the reconciliation.
4. Finance retains general-ledger reconciliation accountability. A domain owner supplies and certifies the operational subledger evidence; this does not authorize that domain to post directly to the GL.
5. Daily frequencies are minimum operational controls for cash, payment settlement, wallet balances, and loan principal movements. Monthly frequencies require completion during the period close; per-transaction controls apply to capital and distribution events.
6. All differences require an identified owner, amount, age, cause, expected resolution date, and documented disposition. Material, overdue, or suspected-fraud differences escalate to the configured escalation role.
7. Do not activate a subledger adapter, automate journal posting, or infer opening balances from this configuration.

## Tax-policy boundary

This work does not complete or approve Ethiopian tax classifications. Existing account-level tax values remain technical routing defaults only. Tax treatment must be decided from transaction-level facts and the applicable approved tax code. A future qualified review may change the defaults without changing the ownership register's purpose.

## Required pre-production evidence

- Each role is assigned to an authorized, active user for the relevant tenant/legal entity.
- Segregation-of-duties check confirms preparer and reviewer differ.
- Reconciliation evidence location, retention, frequency, cutoff, materiality threshold, aging and escalation SLA are approved.
- Each control-account balance ties to the related subledger and source evidence.
- Exceptions are tracked through resolution and reviewer sign-off.
- Acceptance tests and CI are run against the exact candidate SHA.

## Verification record

- Configuration and test files added to branch `feat/finance-control-account-ownership`.
- Tests: **NOT RUN** (Owner's no-local-tests instruction).
- CI/workflows: **NOT RUN**.
- Personnel assignments and production activation: **NOT RUN / NOT APPROVED**.
