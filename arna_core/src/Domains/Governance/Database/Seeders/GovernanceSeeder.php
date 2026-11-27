<?php

declare(strict_types=1);

/* Runs Governance application, permission and role seeders in dependency order. */

namespace MaaleArna\Arna\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;

/** Runs Governance seeders in dependency order. */
final class GovernanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Chains the three G1 seeders in dependency order. The order is
     * dictated by two foreign-key references:
     *
     *   · `app.governance.access` references the
     *     `platform_applications` row created by the first seeder.
     *   · `role_has_permissions.permission_id` references the
     *     permission rows created by the second seeder.
     *
     * See the file-level change-verification block
     * §"Why application-first, then permissions, then roles" for the
     * full reasoning.
     *
     * @return void
     */
    public function run(): void
    {
        $this->call([
            GovernanceApplicationSeeder::class,
            GovernancePermissionsSeeder::class,
            GovernanceRolesSeeder::class,
        ]);
    }
}