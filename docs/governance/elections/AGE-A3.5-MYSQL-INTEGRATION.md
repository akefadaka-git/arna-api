# AGE A3.5 — MySQL integration test path

Status: implementation prepared; runtime execution and concurrency verification remain pending.

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

`.github/workflows/age-mysql-integration.yml` uses a disposable MySQL 8.0 service on a GitHub-hosted `ubuntu-latest` runner.

The workflow retains `workflow_dispatch` and now also starts automatically on pushes to
`fix/age-a3-frozen-register-db-immutability` when one of these paths changes:

- the workflow definition itself;
- AGE governance database migrations;
- `AgeVoterRegisterMySqlIntegrationTest.php`;
- `phpunit.age-mysql.xml`.

It does not run for unrelated branches or unrelated file changes. The job has a 25-minute timeout, installs PHP 8.3 with `pdo_mysql` and `pcntl`, and uploads PHPUnit/log diagnostics as a seven-day artifact when available. The runner uses an isolated MySQL service and does not connect to any ARNA production database.

## Coverage

The current MySQL integration class exercises:

- MySQL trigger DDL creation through the actual migration;
- rejection of invalid draft-to-frozen transitions;
- rejection of missing, cross-tenant, and cross-election snapshot references;
- acceptance of a structurally valid three-distinct-actor freeze transition;
- post-freeze voter-row and snapshot mutation rejection;
- trigger migration rollback and reapplication;
- a two-process, two-connection freeze-versus-insert race test using InnoDB row locking (requires `pcntl`).

## Explicit remaining gaps

- The test suite, including the new two-process race test, has not yet been confirmed executed against MySQL. A successful remote run is required before concurrency protection can be claimed.
- Full application migration ordering and rollback across the entire AGE stack remain separate from this focused trigger migration test.
- HTTP authorization, permission-role assignment review, and independent security review remain outstanding.

No merge, deployment, production migration, role grant, or voting activation is authorized by this test path.
