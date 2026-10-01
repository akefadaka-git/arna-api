<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — Governance Seeder (aggregate runner)
 * =============================================================================
 *
 * File:            Database/Seeders/GovernanceSeeder.php  (NEW)
 * Unit:            Utility — aggregate runner for the three Governance
 *                  G1 seeders
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The three Governance G1 seeders were invoked individually:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernancePermissionsSeeder"
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceRolesSeeder"
 *
 *   The order was load-bearing — ApplicationSeeder must run first
 *   (it creates the `platform_applications` row that the
 *   application-scoped permission in PermissionsSeeder references),
 *   and PermissionsSeeder must run before RolesSeeder (it creates
 *   the permission rows the role grants reference). An operator who
 *   ran them out of order received a RuntimeException naming the
 *   missing prerequisite, but the sequencing was manual.
 *
 * After:
 *   One file that chains the three seeders in the correct order via
 *   Laravel's `$this->call([...])` mechanism:
 *
 *     GovernanceSeeder::run()
 *       ├── call(GovernanceApplicationSeeder)   → platform_applications
 *       ├── call(GovernancePermissionsSeeder)   → 37 permission rows
 *       └── call(GovernanceRolesSeeder)         → role grants
 *
 *   The operator invokes one command:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceSeeder"
 *
 *   The chained seeders retain their own pre-resolution guards
 *   (`resolvePermissionIds()` and `resolveRoles()` in
 *   GovernanceRolesSeeder, `seedApplicationAccessPermission()` in
 *   GovernancePermissionsSeeder). If a prerequisite is missing, the
 *   chain throws a RuntimeException naming the missing item, exactly
 *   as it did when the seeders ran individually.
 *
 *   What the aggregate does NOT do:
 *
 *     · It does NOT replace the three individual seeders. Each
 *       remains invocable on its own for a targeted re-run
 *       (e.g. re-seeding only permissions after a slug change).
 *
 *     · It does NOT register itself in
 *       `Database\Seeders\DatabaseSeeder`. It runs explicitly, on
 *       demand, following the platform's convention that domain
 *       seeders are not part of the default aggregate.
 *
 *     · It does NOT change the three chained seeders' behaviour.
 *       The aggregate is a call chain, not a re-implementation.
 *
 *     · It does NOT insert, update, or delete any row directly.
 *       Every write is performed by a chained seeder through its
 *       own idempotent `updateOrCreate()` or `sync()` call.
 *
 * =============================================================================
 * Why application-first, then permissions, then roles
 * =============================================================================
 * The order is dictated by two foreign-key dependencies:
 *
 *   1. `permissions.application_id` → `platform_applications.id`
 *      The `app.governance.access` permission references the
 *      Governance application row. GovernanceApplicationSeeder
 *      must run first. (GovernancePermissionsSeeder's
 *      `seedApplicationAccessPermission()` throws a named
 *      RuntimeException if the row is missing.)
 *
 *   2. `role_has_permissions.permission_id` → `permissions.id`
 *      GovernanceRolesSeeder resolves every permission slug in its
 *      matrix into an id before it touches any pivot. The
 *      permission rows must exist. (GovernanceRolesSeeder's
 *      `resolvePermissionIds()` throws a named RuntimeException if
 *      any slug is missing.)
 *
 * The reverse order — roles before permissions — would fail on
 * step 2. The reverse order — permissions before application —
 * would fail on step 1. The chosen order satisfies both.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * Running the aggregate repeatedly is safe. Each chained seeder is
 * independently idempotent:
 *
 *   · ApplicationSeeder: `updateOrCreate(['slug' => 'governance'], ...)`
 *     — same row, same values, no duplicate.
 *   · PermissionsSeeder: `updateOrCreate(['slug' => $slug], ...)` per
 *     slug — same 37 rows, same values, no duplicates.
 *   · RolesSeeder: composite sync per role — the composite set equals
 *     the role's current set on the second run; the sync is a no-op
 *     on the governance portion and preserves every other domain's
 *     grants.
 *
 * A second run of the aggregate therefore produces the same final
 * database state as the first, and reports the same success output
 * from each chained seeder.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Seeders chained:           3
 * Direct writes performed:   0 (all via the chained seeders)
 * =============================================================================
 */

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