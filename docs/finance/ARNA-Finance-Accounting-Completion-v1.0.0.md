# ARNA Finance Accounting Completion — v1.0.0

## Scope delivered in this branch

This branch extends the canonical CoA implementation branch with:
- protection against mass assignment of system-managed CoA metadata on `LedgerAccount`;
- an explicit schema precondition for `legal_entities.id`, `tenant_id`, and `status` before seeding;
- a control-account reconciliation ownership register covering all canonical accounts marked as control accounts;
- a read-only `ControlAccountOwnershipRegistry` that validates exact CoA coverage, required role/evidence fields, and fail-closed pending-personnel-assignment state;
- a seeder preflight that validates ownership coverage before the first account write;
- unit/acceptance tests for CoA structure, parent ordering, category values, and one-to-one ownership coverage.

## Control-account accountability status

The register includes **36** control accounts. Every entry defines:
- proposed accountable role;
- contributing domain(s);
- reconciliation frequency;
- required evidence;
- independent reviewer role;
- named accountable person (currently `null`);
- designation status (`ROLE_DESIGNATED_PERSON_PENDING`);
- adapter activation guard (`BLOCKED_UNTIL_OWNER_AND_RECONCILIATION_PROCEDURE_APPROVED`).

The accountable functional roles are designated under the Owner's authorization. Named individuals remain unassigned and must be recorded by the Owner/authorized management; reconciliation procedures must also be approved before corresponding adapters are enabled. The independent reviewer should not be the preparer.

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
- Automated tests added for CoA structure and ownership registry invariants but **NOT RUN** per the Owner's no-local-tests instruction.
- Static source changes in the latest commits: `2b32370e1b65779ea47e787408bdbc14469c0010`, `515f7e1dfb9516350eb8ea39427913bf316b742b`, and `f614f7a79157868c2fbef92c7bd066a9ac8ff2f9`.
- CI/workflows: **NOT RUN**.
- Database migration/seeder execution: **NOT RUN**.
- Merge/deployment: **NOT AUTHORIZED / NOT PERFORMED**.
