# Finance Domain

This directory is the ownership boundary for Finance application code and its domain-owned artifacts.

## Structure

- `Config/` — canonical chart of accounts and control-account ownership configuration.
- `Contracts/`, `Enums/`, `Events/`, `Http/`, `Listeners/`, `Models/`, `Policies/`, `Providers/`, `Repositories/`, `Resources/`, `Services/`, `Support/` — Finance application capabilities.
- `Database/Migrations/` — Finance schema changes, loaded by `FinanceServiceProvider`.
- `Database/Seeders/` — Finance-owned seeders. The chart-of-accounts seeder is deliberately not added to the global `DatabaseSeeder`; invoke it explicitly only after the required Owner approval.
- `Tests/Feature/` and `Tests/Unit/` — Finance domain regression and contract tests.

## Framework integration

The Finance provider is registered in the application-level `bootstrap/providers.php` registry. It merges the two Finance configuration files under:

- `finance.chart_of_accounts_v1`
- `finance.control_account_ownership_v1`

It also registers `Database/Migrations` with Laravel's migrator. This keeps the migration files inside the domain while allowing the normal `php artisan migrate` command to discover them.

The PHPUnit `Domain` suite already recursively discovers tests under `mases_core/src/Domains`. The focused MySQL workflow remains in `.github/workflows/` because GitHub Actions requires workflow entrypoints to live there; it targets the Finance domain migration and test paths.

## Seeder invocation

The canonical CoA seeder remains opt-in and is not called by `DatabaseSeeder`. Its class is:

`MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceChartOfAccountsSeeder`

Do not run it against production or enable it in the default seed flow without separate Owner approval.
