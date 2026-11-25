<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\BootstrapUsers;
use Database\Seeders\Data\ShareholderRegister;
use Database\Seeders\Data\TenantProfile;
use Database\Seeders\Support\UserPivotCleaner;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderNumberReconciler;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates core reference-data and account seeders in dependency order.
 * Prints a summary after the seeding workflow completes.
 */
final class MasesCoreSeeder extends Seeder
{
    /**
     * Run the MASES core seeding flow.
     *
     * See the class docblock §"The composition" for the nine steps.
     *
     * The steps are composed in a single method so that the flow is
     * readable top-to-bottom. Each step's arguments are the return
     * values of earlier steps; the data flow is explicit.
     */
    public function run(): void
    {
        \Database\Seeders\Support\DemoSeederGuard::assertAllowed();
        /*
         * Step 1 — clear.
         *
         * Wipe the user graph: users, shareholders, user-to-X pivots,
         * Sanctum tokens. Reference data (tenants, applications,
         * permissions, roles) is NOT cleared — it is upserted by its
         * seeders below.
         */
        /*
         * Permanent shareholder-number reservations and legacy aliases
         * must outlive shareholder rows. This seeder's legacy clear step
         * deletes shareholders, so refuse that destructive reset once
         * numbering history exists. The check runs before any clearing
         * or seeding; the caller's existing data remains untouched.
         *
         * Repeated destructive reseeding is not a supported operation
         * after identity numbers have been issued. Use a non-destructive
         * reconciliation/update workflow instead.
         */
        if (Schema::hasTable('shareholder_number_reservations')
            && DB::table('shareholder_number_reservations')->exists()) {
            throw new RuntimeException(
                'MasesCoreSeeder cannot clear and reseed shareholders after permanent numbering history exists. '
                . 'No data was cleared; use a non-destructive reconciliation workflow.'
            );
        }

        if (Schema::hasTable('shareholder_legacy_aliases')
            && DB::table('shareholder_legacy_aliases')->exists()) {
            throw new RuntimeException(
                'MasesCoreSeeder cannot clear and reseed shareholders after permanent legacy aliases exist. '
                . 'No data was cleared; use a non-destructive reconciliation workflow.'
            );
        }

        if (Schema::hasTable('shareholders')
            && Schema::hasColumn('shareholders', 'shareholder_number')
            && DB::table('shareholders')->whereNotNull('shareholder_number')->exists()) {
            throw new RuntimeException(
                'MasesCoreSeeder cannot clear and reseed numbered shareholders. '
                . 'No data was cleared; use a non-destructive reconciliation workflow.'
            );
        }

        (new UserPivotCleaner())->clear();

        /*
         * Step 2 — tenant.
         *
         * The tenant is the anchor for every subsequent row: users'
         * default_tenant_id, roles' tenant_id, and the tenant
         * membership pivot all reference it.
         */
        $tenant = (new TenantSeeder())->seed();

        /*
         * Step 3 — applications.
         *
         * The applications map is needed by the permission seeder
         * (to resolve each app.{slug}.access permission's
         * application_id) and by the role seeder (to resolve the
         * two application-scoped roles' application_id).
         */
        $applications = (new ApplicationSeeder())->seed();

        /*
         * Step 4 — permissions.
         *
         * The permissions map is needed by the role-permission
         * seeder, which resolves each role's grant spec against it.
         */
        $permissions = (new PermissionSeeder())->seed($applications);
        app(\MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernanceElectionCommitteePermissionsSeeder::class)->run();

        /*
         * Step 5 — roles.
         *
         * The roles map is needed by the role-permission seeder
         * (to attach permissions), the admin user seeder (to attach
         * the two admins' roles), and the shareholder seeder (to
         * attach the 100 shareholders' roles).
         */
        $roles = (new RoleSeeder())->seed($tenant, $applications);

        /*
         * Step 6 — role-permission pivot.
         *
         * Attaches permissions to roles. Runs before the user
         * seeders because the user seeders do not touch the pivot;
         * the ordering between step 6 and steps 7–8 is a matter of
         * grouping reference data before identity data, not a
         * dependency.
         */
        (new RolePermissionSeeder())->seed($roles, $permissions);

        /*
         * Step 7 — bootstrap admins.
         *
         * Creates the two admin accounts and attaches their pivots.
         * Returns the two persisted models; the super administrator
         * is needed by the shareholder seeder as the attributor for
         * every shareholder role assignment.
         */
        $admins = (new AdminUserSeeder())->seed($tenant, $roles);

        /*
         * Step 8 — shareholder cohort.
         *
         * Creates the 100 shareholder users and their register rows,
         * and attaches their pivots. The super administrator is
         * passed as the attributor for every role assignment.
         *
         * The return value is not consumed by anything downstream —
         * this is the last step before the summary. A future CR
         * that adds a downstream step can consume it without a
         * signature change.
         */
        (new ShareholderSeeder())->seed($tenant, $roles, $admins['super']);

        /*
         * Migrations execute before seeders on a fresh installation.
         * Reconcile again after the shareholder cohort is written so
         * every seeded record has its permanent number, reservation,
         * legacy alias, and tenant sequence state before seeding ends.
         */
        if (Schema::hasTable('shareholders') && Schema::hasColumn('shareholders', 'shareholder_number')) {
            app(ShareholderNumberReconciler::class)->reconcile();
        }

        /*
         * Step 9 — summary.
         *
         * Print the operator summary. See printSummary() below for
         * the exact output and the source it reads from.
         */
        $this->printSummary();
    }

    /**
     * Print the operator summary.
     *
     * The summary reports seeded counts and account identifiers only.
     * It deliberately does not emit credentials; new account holders
     * establish passwords through the verified-email reset flow.
     *
     * The method uses $this->command, which Laravel's seeder
     * dispatcher sets when the seeder is invoked via
     * `php artisan db:seed`. A direct invocation from tinker would
     * fatal on the first info() call, matching the source's
     * behavior — this is not a regression.
     */
    private function printSummary(): void
    {
        $this->command->info('MASES Core seeding completed.');
        $this->command->line('  Tenant:       ' . TenantProfile::ROW['slug']);
        $this->command->line('  Admins:       2');
        $this->command->line('  Shareholders: ' . count(ShareholderRegister::ROWS));
        $this->command->line('  Users total:  ' . (2 + count(ShareholderRegister::ROWS)));
        $this->command->line('');
        $this->command->line('  Super admin: ' . BootstrapUsers::SUPER_ADMIN['email']);
        $this->command->line('  Admin:       ' . BootstrapUsers::PLATFORM_ADMIN['email']);
        $this->command->line('');
        $this->command->line('  No passwords are issued or printed by this seeder.');
        $this->command->line('  Account holders must establish passwords through verified email reset.');
        $this->command->line('  Usernames are derived from the first name (lowercase).');
        $this->command->line('  Emails follow {firstname}{fathername}@maalearna.com.et.');
        $this->command->line('  Example: SH-0009 Gelebo Gelefa → gelebo / gelebogelefa@maalearna.com.et');
        $this->command->line('');
    }
}