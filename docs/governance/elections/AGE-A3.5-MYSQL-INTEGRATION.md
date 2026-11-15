# AGE A3.5 — MySQL integration test path

Status: **PASS — focused MySQL integration suite executed successfully** on 2026-11-15. Full-stack migration, HTTP authorization, and independent security review remain outstanding.

## Purpose

The default `phpunit.xml` deliberately pins the normal test suite to in-memory SQLite. Do not change that hermetic default to make MySQL tests run.

The separate `phpunit.age-mysql.xml` configuration runs only
`AgeVoterRegisterMySqlIntegrationTest` and forces `DB_CONNECTION=mysql`.

## Safety boundary

The integration test creates and drops these tables:

- `governance_election_register_snapshots`
- `governance_election_voters`

It refuses to proceed unless:

- `AGE_MYSQL_INTEGRATION=1`
- the active connection driver is MySQL
- the selected database name contains `age_test` (case-insensitive)

Use only a disposable database. Never point this suite at staging or production.

## Local execution

Provide the normal Laravel MySQL connection variables, using a disposable database named for example `arna_age_test`, then run:

```sh
AGE_MYSQL_INTEGRATION=1 DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=arna_age_test DB_USERNAME=root DB_PASSWORD=... vendor/bin/phpunit -c phpunit.age-mysql.xml --testdox
```

The custom PHPUnit configuration forces the MySQL driver and opt-in flag. Connection credentials remain environment supplied.

## GitHub Actions remote runner

`.github/workflows/age-mysql-integration.yml` uses a disposable MySQL 8.0 service on a GitHub-hosted `ubuntu-24.04` runner.

The workflow retains `workflow_dispatch` and starts automatically on pushes to
`fix/age-a3-frozen-register-db-immutability` when one of these paths changes:

- the workflow definition itself;
- AGE governance database migrations;
- `AgeVoterRegisterMySqlIntegrationTest.php`;
- `phpunit.age-mysql.xml`.

It does not run for unrelated branches or unrelated file changes. The job has a 30-minute timeout, installs PHP 8.3 with `pdo_mysql` and `pcntl`, and uploads available PHPUnit/log diagnostics as a seven-day artifact. The runner uses an isolated MySQL service and does not connect to any ARNA production database.

## Verified execution

- Workflow run: [#43713743319](https://github.com/akefadaka-git/arna-api/actions/runs/43713743319)
- Tested commit: `dcff3304a9c2513a45385b3a9ada62c768c72f10`
- MySQL server: `8.0.46`
- PHP: `8.3.6`
- PHPUnit: `11.5.56`
- Result: **4 tests, 20 assertions, all passed**
- Exact coverage:
  - invalid draft-to-frozen transition rejected;
  - missing, cross-tenant, and cross-election references rejected;
  - direct same-snapshot tenant/election scope tampering rejected;
  - independent three-actor freeze transition accepted;
  - post-freeze voter and snapshot mutations rejected;
  - two-process/two-connection concurrent voter insert waits for freeze and is rejected;
  - trigger migration rollback and reapplication succeeds.

The race worker now runs in a separate PHP process image with its own PDO connection, avoiding destruction of the parent's inherited Laravel connection. The test sets the snapshot lock in the parent, verifies the child remains blocked, commits the frozen state, and asserts the child receives a database rejection and no voter row is inserted.

## Explicit remaining gaps

- This is focused trigger/migration integration evidence, not proof of full application migration ordering or rollback across the complete AGE stack.
- The full default PHP test suite has not been reported as run by this workflow.
- HTTP authorization, permission-role assignment review, and independent security review remain outstanding.
- Production MySQL version/configuration and operational database privilege controls must be validated separately.

No merge, deployment, production migration, role grant, or voting activation is authorized by this test path.
