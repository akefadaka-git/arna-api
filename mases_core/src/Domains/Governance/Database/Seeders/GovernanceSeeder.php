<?php

declare(strict_types=1);

/* Runs Governance application, permission and role seeders in dependency order. */

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * GovernanceSeeder
 *
 * The aggregate runner for the Governance domain's G1 seeders.
 * Chains the three seeders in the order their foreign-key
 * dependencies require.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Run all three at once:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceSeeder"
 *
 * Expected output (in order):
 *
 *     Governance application registered: slug=governance, id=8, frontend_ready=false.
 *     Governance permissions seeded: 37 entries (36 tenant + 1 application).
 *     Governance role permissions synced: 8 roles, 161 total assignments.
 *
 * If any chained seeder throws, the chain stops. The two preceding
 * seeders' writes remain committed (each seeder commits its own
 * transaction), but the failed seeder's write does not occur. The
 * operator resolves the missing prerequisite and re-runs the
 * aggregate — the earlier seeders are idempotent and will not
 * duplicate their rows.
 *
 * =============================================================================
 * When to invoke the individual seeders instead
 * =============================================================================
 * The aggregate is the recommended default. There are two cases
 * where invoking an individual seeder is preferable:
 *
 *   1. Targeted re-seed. After a CR that changes a single
 *      permission's Amharic label, re-run only
 *      GovernancePermissionsSeeder — the application row is
 *      unchanged and the role grants are unchanged.
 *
 *   2. Diagnostic run. When investigating a specific failure, run
 *      the three individually to see which step fails and with
 *      what message.
 *
 * In every other case, this aggregate is the correct entry point.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Database\Seeders
 * @version 1.0.0
 */
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