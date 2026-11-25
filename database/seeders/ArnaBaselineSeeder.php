<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernanceSeeder;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanPermissionsSeeder;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanRolesSeeder;
use MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders\ShareClassSeeder;
use MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders\ShareholderPermissionsSeeder;
use MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders\ShareholderRolesSeeder;
use MaaleArna\MASES\Core\Domains\Wallet\Database\Seeders\WalletPermissionsSeeder;
use MaaleArna\MASES\Core\Domains\Wallet\Database\Seeders\WalletRolesSeeder;

/**
 * Non-destructive reference-data baseline. Operational cohorts, identities,
 * credentials, legal/accounting setup and financial assumptions are separate.
 */
final class ArnaBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantSeeder::class)->seed();
        $applications = app(ApplicationSeeder::class)->seed();
        $permissions = app(PermissionSeeder::class)->seed($applications);
        $roles = app(RoleSeeder::class)->seed($tenant, $applications);
        app(RolePermissionSeeder::class)->seed($roles, $permissions);

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
