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
 * Production-safe domain reference-data orchestration.
 *
 * Runs only deterministic permission, role-grant, Governance application,
 * and share-class reference seeders. It deliberately excludes operational
 * Loan product/policy activation and Finance chart-of-accounts provisioning.
 *
 * Every child seeder is invoked through Laravel's seeder dispatcher so its
 * console output and nested calls are correctly initialized.
 */
final class ProductionDomainReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Governance owns an application -> permissions -> roles chain.
            GovernanceSeeder::class,

            // All remaining domain permissions must exist before their role
            // matrices are resolved and applied.
            ShareholderPermissionsSeeder::class,
            WalletPermissionsSeeder::class,
            LoanPermissionsSeeder::class,

            // Each role seeder uses composite sync and preserves other
            // domains' grants on shared platform roles.
            ShareholderRolesSeeder::class,
            WalletRolesSeeder::class,
            LoanRolesSeeder::class,

            // Share classes are independent shareholder-domain reference data.
            ShareClassSeeder::class,
        ]);
    }
}
