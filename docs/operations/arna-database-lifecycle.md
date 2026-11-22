# ARNA database lifecycle

## Migration discovery

Laravel's default `database/migrations` directory remains active. The
`DatabaseLifecycleServiceProvider` recursively discovers every directory
named `Migrations` beneath `mases_core/src`, including Core, Policy and
all domain migration folders. This discovery is independent of domain
feature flags and route activation.

The source inventory at the A3 baseline includes migration directories for:

- Root application migrations: `database/migrations/`
- Core platform: `mases_core/src/Migrations/`
- Versioned Policy: `mases_core/src/Policy/Database/Migrations/`
- Finance, Governance, Loan, Shareholder and Wallet domain migrations.

The source inventory at this verification checkpoint contains 91 migration files
across seven directories (the Laravel root plus six Core/domain directories).
The current inventory has zero duplicate migration basenames. The automated
integration test recomputes both values from the checked-out source tree.

Migration filenames must be globally unique by basename. Do not reuse a
migration class/file name in another directory. New migrations must be
additive or provide a reviewed, reversible data-preserving transition.
Never rewrite a migration already recorded in a deployed database.

## Seeder inventory and execution policy

### Safe default baseline

`php artisan db:seed` invokes `ArnaBaselineSeeder`. Its dependency order is:

1. `TenantSeeder`
2. `ApplicationSeeder`
3. `PermissionSeeder` (composes Core, Finance and Documents catalogues)
4. `RoleSeeder`
5. `RolePermissionSeeder`
6. Shareholder permissions and composite role grants
7. Wallet permissions and composite role grants
8. Loan permissions and composite role grants
9. `GovernanceSeeder` (application, permissions, election committee permissions, roles)
10. `ShareClassSeeder`

This baseline does not create or reset people, users, shareholder register
records, wallets, loans, journals, documents, or other operational business
records. The Core permission and role catalogues include Finance and Documents
domain contributions; the separate domain grant seeders preserve grants
owned by other domains using composite synchronization.

### Existing seeders that remain separately controlled

| Seeder | Why it is not in the default baseline |
|---|---|
| `MasesCoreSeeder` | Legacy reset-and-rebuild cohort workflow; clears user-linked pivots, users, tokens and shareholder rows |
| `AdminUserSeeder` | Creates bootstrap identities and credentials |
| `ShareholderSeeder` | Creates the 100-row shareholder cohort and role assignments |
| `FinanceChartOfAccountsSeeder` | Creates canonical system accounts for each active legal entity; requires legal entities and an explicit accounting setup decision |
| `LoanProductsSeeder` | Creates tenant lending product catalogue entries with example principal limits |
| `LoanProductRulesPolicySeeder` | Creates and attaches a financial policy containing interest and arrears assumptions; requires separate financial-policy approval |
| `DatabaseSeeder` | Entry point only; delegates to the safe baseline |

Other domain catalogues such as `FinancePermissionCatalogue`,
`FinanceRoleCatalogue`, `FinanceRolePermissionGrants`,
`DocumentsPermissionCatalogue`, and `DocumentsRolePermissionGrants`
are data definitions consumed by the core catalogue seeders, not standalone
Laravel Seeder classes.

### Rules for adding future domains

Every new domain must declare:

- migration directory and schema dependencies;
- reference-data seeder(s) and their execution order;
- whether each seeder is safe for repeated production execution;
- whether it creates identities, credentials, legal/accounting policy, or
  operational records;
- idempotency key and conflict behavior;
- required clean-install and upgrade tests.

Do not rely on a file existing under a `Database/Seeders` folder to make it
automatically execute. Add it to the appropriate orchestrator only after
reviewing its side effects and prerequisites.

## Production rules

- Do not run `migrate:fresh` against an existing production database.
- Do not run a demo/cohort seeder in production.
- Review every pending migration against the live schema and backup first.
- Use `php artisan migrate --pretend` for SQL preview where supported,
  then execute only the separately approved migration plan.
- Run the safe baseline seeder only after its effects have been reviewed
  against the target database; upsert semantics do not replace the need
  for a backup and data-integrity checks.
- Keep business policy, legal-entity setup, chart of accounts and bootstrap
  credential provisioning as separate approvals.


## A3 verification harness

The dedicated MySQL integration test validates migration discovery and duplicate
basenames, creates a disposable pre-existing demo user/shareholder cohort, then
runs the default safe baseline twice and compares persistent table snapshots.
It separately executes Finance chart-of-accounts and Loan product/policy seeders
twice against synthetic test-only tenant/legal-entity data. The CI workflow uses
an ephemeral MySQL service; none of these fixtures or financial assumptions are
intended for Plesk production seeding.
