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

## GitHub Actions

`.github/workflows/age-mysql-integration.yml` adds a manual-only workflow using a disposable MySQL 8.0 service. It is intentionally `workflow_dispatch` only; it does not run on every push or pull request. Dispatch it from the repository Actions tab when an authorized reviewer is ready to spend a public-repository runner.

## Coverage

The current MySQL integration class exercises:

- MySQL trigger DDL creation through the actual migration;
- rejection of invalid draft-to-frozen transitions;
- rejection of missing, cross-tenant, and cross-election snapshot references;
- acceptance of a structurally valid three-distinct-actor freeze transition;
- post-freeze voter-row and snapshot mutation rejection;
- trigger migration rollback and reapplication;\n- a two-process, two-connection freeze-versus-insert race test using InnoDB row locking (requires `pcntl`).

## Explicit remaining gaps

- The test suite has not yet been executed against MySQL.
- The current suite does not yet run a two-session concurrent approval-versus-voter-write race. MySQL `FOR UPDATE` semantics must be tested with two independent connections before claiming concurrency protection.
- Full application migration ordering and rollback across the entire AGE stack remain separate from this focused trigger migration test.
- HTTP authorization, permission-role assignment review, and independent security review remain outstanding.

No merge, deployment, production migration, role grant, or voting activation is authorized by this test path.
