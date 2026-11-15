# AGE A3.5 — MySQL integration test path

Status: **PASS — exact tested code head** on 2026-11-15. Full-stack migration, HTTP authorization, permission-role review, production database controls, and independent security review remain outstanding.

## Purpose

The default `phpunit.xml` deliberately pins the normal test suite to in-memory SQLite. Do not change that hermetic default to make MySQL tests run.

The separate `phpunit.age-mysql.xml` configuration runs only `AgeVoterRegisterMySqlIntegrationTest` and forces `DB_CONNECTION=mysql`.

## Safety boundary

The integration test creates and drops these fixture tables:

- `governance_election_register_snapshots`
- `governance_election_voters`

It refuses to proceed unless `AGE_MYSQL_INTEGRATION=1`, the active connection driver is MySQL, and the selected database name contains `age_test` (case-insensitive). Use only a disposable database; never point this suite at staging or production.

## Local execution

Provide normal Laravel MySQL connection variables using a disposable database, for example `arna_age_test`, then run:

```sh
AGE_MYSQL_INTEGRATION=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=arna_age_test DB_USERNAME=root DB_PASSWORD=... vendor/bin/phpunit -c phpunit.age-mysql.xml --testdox
```

## GitHub Actions remote runner

`.github/workflows/age-mysql-integration.yml` uses a disposable MySQL 8.0 service on a GitHub-hosted `ubuntu-24.04` runner. It supports `workflow_dispatch` and automatic pushes to `fix/age-a3-frozen-register-db-immutability` when relevant workflow, migration, test, service, or PHPUnit configuration paths change. It installs PHP 8.3 with `pdo_mysql` and `pcntl`; it does not connect to any ARNA production database.

## Exact-head verification

- Workflow run: [#43725159660](https://github.com/akefadaka-git/arna-api/actions/runs/43725159660)
- Tested code commit: `1efd6605a0de3dc04e5a78a2a979ebb55cc9b0f4`
- MySQL: `8.0.46`; PHP: `8.3.6`; PHPUnit: `11.5.56`
- MySQL integration: **PASS — 6 tests, 26 assertions**
- SQLite database regression: **PASS — 9 tests, 45 assertions**
- All workflow steps completed successfully.

MySQL coverage includes invalid freeze transition rejection; premature `frozen_at` rejection; direct snapshot INSERT attempts that bypass the initial prepared-draft state; missing/cross-tenant/cross-election references; frozen voter/snapshot mutation rejection; concurrent two-process/two-connection voter INSERT serialization against freeze; and trigger migration rollback/reapplication.

SQLite coverage includes the independent three-actor lifecycle, tamper detection, ledger/projection reconciliation, invalid snapshot references, approval requirements, premature freeze timestamp rejection, direct database mutation rejection, initial snapshot draft-state enforcement, and tenant isolation.

The MySQL race worker runs as a separate PHP process with its own PDO connection. The parent holds the snapshot row lock, advances the register to frozen and commits; the blocked child then observes the frozen state and its voter INSERT is rejected.

## Implemented database guards

Seven triggers are installed for MySQL and SQLite:

1. Voter-row INSERT guard.
2. Voter-row UPDATE guard.
3. Voter-row DELETE guard.
4. Frozen snapshot UPDATE guard.
5. Frozen snapshot DELETE guard.
6. Initial snapshot INSERT guard: only a prepared draft may be inserted; preparer must equal creator; reconciliation, approval, and integrity-verification fields must be empty; `frozen_at` must be null; digest must be non-empty; voter count must be positive.
7. Freeze-transition guard: requires a reconciled prior state, three distinct preparer/reconciler/approver actors, required timestamps, non-empty digest, and positive voter count.

MySQL voter-row triggers lock parent snapshots with `SELECT ... FOR UPDATE` before evaluating frozen state. Updates moving a voter between snapshots lock old/new parents in ascending ID order and validate tenant/election scope. SQLite provides equivalent write rejection for hermetic regression tests but is not evidence of MySQL locking behavior.

Canonical snapshot hashing normalizes scalar and DECIMAL values to a stable representation across supported database drivers.

## Explicit remaining gaps

- This focused suite does not prove full application migration ordering or rollback across the complete AGE stack.
- The full default PHP test suite has not been reported as run by this workflow.
- HTTP authorization, permission-role assignment review, production MySQL version/configuration and operational database privileges remain **NOT VERIFIED**.
- Independent security review remains **NOT RUN**.
- Database administrators able to disable/drop triggers or alter schema remain outside trigger enforcement; production privilege separation, backups, and audit controls are required.

No merge, deployment, production migration, role grant, or voting activation is authorized by this test path.
