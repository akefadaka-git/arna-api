# ARNA Finance Accounting Completion — v1.0.0

## Scope delivered in this branch

This branch extends the canonical CoA implementation branch with:
- protection against mass assignment of system-managed CoA metadata on `LedgerAccount`;
- an explicit schema precondition for `legal_entities.id`, `tenant_id`, and `status` before seeding;
- a control-account reconciliation ownership register covering all canonical accounts marked as control accounts;
- acceptance tests for CoA structure, parent ordering, category values, and one-to-one ownership coverage.

## Control-account accountability status

The register includes **36** control accounts. Every entry defines:
- proposed accountable role;
- contributing domain(s);
- reconciliation frequency;
- required evidence;
- independent reviewer role;
- named accountable person (currently `null`);
- designation status (`PENDING_OWNER_DESIGNATION`);
- adapter activation guard (`BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED`).

These are role-level implementation defaults, not formal personnel appointments. The Owner must designate actual people and approve reconciliation procedures before the corresponding adapters are enabled. The independent reviewer should not be the preparer.

## Seeder and activation boundary

- The seeder is still deliberately not registered in `DatabaseSeeder`.
- It creates no opening balances.
- It only maintains rows marked `is_system_account=true` for the same tenant, legal entity, and code.
- It fails closed when required schema columns are absent.
- No seeder execution, database mutation, CI run, deployment, or production posting was performed as part of this change.

## Tax policy status

Per Owner direction, external tax review is deferred for now. Account tax values remain technical routing defaults, not final Ethiopian tax advice. Transaction-level tax treatment must continue to be determined from approved tax codes and transaction evidence. This implementation does not approve production tax reporting.

## Verification

- GitHub source inspection confirmed 79 CoA register rows.
- Static generation mapped all 36 register control accounts to an ownership record.
- Automated tests added but **NOT RUN** per the Owner's no-local-tests instruction.
- CI/workflows: **NOT RUN**.
- Database migration/seeder execution: **NOT RUN**.
- Merge/deployment: **NOT AUTHORIZED / NOT PERFORMED**.
