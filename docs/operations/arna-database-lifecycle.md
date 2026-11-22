# ARNA database lifecycle

## Migration discovery

Laravel's default `database/migrations` directory remains active. The
`DatabaseLifecycleServiceProvider` recursively discovers every directory
named `Migrations` beneath `mases_core/src`, including Core, Policy and
all domain migration folders. This discovery is independent of domain
feature flags and route activation.

Migration filenames must be globally unique by basename. Do not reuse a
migration class/file name in another directory. New migrations must be
additive or provide a reviewed, reversible data-preserving transition.
Never rewrite a migration already recorded in a deployed database.

## Safe baseline seeding

`php artisan db:seed` invokes `ArnaBaselineSeeder`. It creates or updates
foundational tenant, application, permission and role reference data, then
runs domain permission/role catalogues and Shareholder share-class reference
data in dependency order.

It intentionally excludes:

- `MasesCoreSeeder`, which is a legacy reset-and-rebuild cohort workflow;
- bootstrap/admin users and the 100-row shareholder demonstration cohort;
- loan products and loan financial-policy assumptions;
- Finance legal-entity and chart-of-accounts provisioning;
- operational records such as wallets, loans, journals and documents.

Those excluded operations require separate, explicitly controlled setup
because they create identities, business records or financial assumptions.

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
