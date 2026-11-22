<?php

declare(strict_types=1);

namespace Database\\Seeders;

use Illuminate\\Database\\Seeder;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Loan\\Database\\Seeders\\LoanPermissionsSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Loan\\Database\\Seeders\\LoanRolesSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareClassSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareholderPermissionsSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareholderRolesSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Wallet\\Database\\Seeders\\WalletPermissionsSeeder;
use MaaleArna\\MASES\\Core\\Domains\\Wallet\\Database\\Seeders\\WalletRolesSeeder;

/**
 * Safe, repeatable platform reference-data baseline.
 *
 * This deliberately does NOT call MasesCoreSeeder: that legacy cohort
 * orchestrator clears user-linked pivots, users, tokens and shareholder
 * rows before rebuilding its demonstration/bootstrap cohort. It is not a
 * production baseline seeder.
 *
 * This seeder installs foundational tenant/application/RBAC reference
 * records and domain catalogues only. It never creates or resets people,
 * users, shareholder register records, wallets, loans, journals, documents,
 * or other operational business records.
 *
 * Financial product/policy assumptions and Finance chart-of-accounts
 * provisioning remain separate owner-controlled operations.
 */
final class ArnaBaselineSeeder extends Seeder
{
    public function run(): void
    {
        // Core dependency chain. Use seed() directly so application IDs are
        // passed into application-scoped permissions and roles (the standalone
        // PermissionSeeder::run() intentionally has no application map).
        $tenant = app(TenantSeeder::class)->seed();
        $applications = app(ApplicationSeeder::class)->seed();
        $permissions = app(PermissionSeeder::class)->seed($applications);
        $roles = app(RoleSeeder::class)->seed($tenant, $applications);
        app(RolePermissionSeeder::class)->seed($roles, $permissions);

        // Domain-specific authorization catalogues. These use upsert or
        // composite-sync semantics and preserve grants owned by other domains.
        $this->call([
            ShareholderPermissionsSeeder::class,
            ShareholderRolesSeeder::class,
            WalletPermissionsSeeder::class,
            WalletRolesSeeder::class,
            LoanPermissionsSeeder::class,
            LoanRolesSeeder::class,
            GovernanceSeeder::class,
            ShareClassSeeder::class,
        ]);
    }
}
