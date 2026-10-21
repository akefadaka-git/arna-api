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
use RuntimeException;

/**
 * MasesCoreSeeder
 *
 * The orchestrator for the MASES core seeding flow. Its single
 * responsibility is to compose the sixteen classes that perform the
 * actual work into the correct order, and to print the operator
 * summary when the flow completes.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Two users, five roles.
 *
 * 1.1.0  — shareholder cohort: 100 shareholders, eight new roles,
 *          default password Arna@2019.
 *
 * 1.1.1  — pivot table discovery: clears user pivot tables by
 *          discovering their names from the User model rather than
 *          hardcoding. Guards every deletion with Schema::hasTable().
 *
 * 1.1.2  — shareholders table support: creates one shareholders row
 *          per register entry, linked to the corresponding user.
 *
 * 1.2.0  — username, address, and audit-column support.
 *
 * 1.2.1  — clearAllUsers() force-delete fix. The users deletion
 *          changed from User::query()->delete() to forceDelete() so
 *          that soft-deleted rows from a prior run do not collide
 *          with the current run's inserts on the unique index.
 *          Preserved without alteration in the refactored
 *          UserPivotCleaner class (Unit S-9).
 *
 * 1.3.0  — orchestrator extraction (Unit S-17):
 *          · The class's inline reference data (nine constants),
 *            private methods (fifteen), and the clearing,
 *            upserting, and pivot-writing logic they carried are
 *            extracted into sixteen files:
 *
 *              Data\*          (8 files, S-1 … S-8)
 *              Support\*       (2 files, S-9 and S-15a)
 *              Domain seeders  (6 files, S-10 … S-14 and S-15b
 *                               and S-16)
 *
 *          · This class is now a thin orchestrator. Its run()
 *            composes the extracted classes in the correct order;
 *            its printSummary() reads from the Data\ classes to
 *            produce the same operator output the source produced.
 *
 *          · Behavior is preserved byte-for-byte. The seeded
 *            database state after this refactoring is identical to
 *            the state the pre-refactoring source produced — same
 *            row counts, same usernames, same emails, same pivot
 *            rows, same role-permission grants, same attribution.
 *            The only difference is the ORDER of INSERT statements
 *            during the run: the source batched all pivot writes
 *            at the end; the refactored seeders write their pivots
 *            immediately after creating their own user rows.
 *
 *          · No entry in the source's revision history is
 *            invalidated. Every fix and behavior documented in
 *            1.0.0 through 1.2.1 has a corresponding carrier in
 *            the extracted classes.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is an ORCHESTRATOR. It contains no reference data, no upsert
 * logic, and no pivot-write logic. It reads nothing from the database
 * except through the seeders it invokes, and it holds no state across
 * its own run() invocation.
 *
 * The reference data the flow writes lives in the Data\ layer:
 *
 *     Data\TenantProfile          (S-2)  1 tenant
 *     Data\ApplicationRegistry    (S-3)  7 applications
 *     Data\PermissionCatalogue    (S-4)  37 permissions
 *     Data\RoleCatalogue          (S-5)  12 roles
 *     Data\RolePermissionGrants   (S-6)  12 role-grant entries
 *     Data\BootstrapUsers         (S-7)  2 bootstrap admins
 *     Data\BoardRoleAssignments   (S-8)  8 board-member role entries
 *     Data\ShareholderRegister    (S-1)  100 register rows
 *
 * The clearing, upserting, and pivot-writing logic lives in the
 * domain seeders and support classes:
 *
 *     Support\UserPivotCleaner    (S-9)   the clear step
 *     Support\UserAttacher        (S-15a) the pivot-attach helpers
 *     TenantSeeder                (S-10)  writes the tenant
 *     ApplicationSeeder           (S-11)  writes the applications
 *     PermissionSeeder            (S-12)  writes the permissions
 *     RoleSeeder                  (S-13)  writes the roles
 *     RolePermissionSeeder        (S-14)  writes the role-permission pivot
 *     AdminUserSeeder             (S-15b) writes the two bootstrap admins
 *     ShareholderSeeder           (S-16)  writes the 100-shareholder cohort
 *
 * =============================================================================
 * The composition
 * =============================================================================
 * The flow below is the source's flow, expressed as a composition of
 * the extracted classes. Each step's behavior — the arguments passed,
 * the rows written, the return value consumed — mirrors the source
 * exactly.
 *
 *     1. Support\UserPivotCleaner::clear()
 *          Delete every row from users and the tables that reference
 *          them: the user pivot tables, personal_access_tokens, and
 *          shareholders. Runs inside a single transaction.
 *
 *     2. TenantSeeder::seed(): Tenant
 *          Upsert the default tenant by slug. Returns the persisted
 *          model for the seeders that follow.
 *
 *     3. ApplicationSeeder::seed(): array<string, PlatformApplication>
 *          Upsert the seven application rows by slug. Returns the
 *          map keyed by slug.
 *
 *     4. PermissionSeeder::seed($applications): array<string, Permission>
 *          Upsert the 37 permission rows by slug. Resolves each
 *          app.{slug}.access permission's application_id from the
 *          applications map. Returns the map keyed by slug.
 *
 *     5. RoleSeeder::seed($tenant, $applications): array<string, Role>
 *          Upsert the 12 role rows by slug. Resolves each tenant-bound
 *          role's tenant_id from the tenant, and each application-
 *          scoped role's application_id from the applications map.
 *          Returns the map keyed by slug.
 *
 *     6. RolePermissionSeeder::seed($roles, $permissions): void
 *          Sync each role's permission pivot against its grant spec.
 *
 *     7. AdminUserSeeder::seed($tenant, $roles): array{super, admin}
 *          Upsert the two bootstrap admins. Attaches each to the
 *          tenant and to its catalogue role. Returns the two models,
 *          keyed 'super' and 'admin'.
 *
 *     8. ShareholderSeeder::seed($tenant, $roles, $admins['super']): array
 *          Upsert the 100-shareholder cohort. Attaches each to the
 *          tenant and to its role(s) — the eight board members
 *          receive their multi-role assignments, the other 92
 *          receive the default 'shareholder' role. Attributes every
 *          role attachment to the super administrator. Returns the
 *          map keyed by SH-ID.
 *
 *     9. printSummary()
 *          Print the operator summary.
 *
 * =============================================================================
 * Why the pivots are now written earlier
 * =============================================================================
 * The source batched every pivot write — tenant memberships and role
 * assignments for both admins and all 100 shareholders — into a
 * single assignUserRelations() pass that ran after every user row
 * existed. The refactored seeders each write their own pivots
 * immediately after creating their own user rows.
 *
 * The final database state is identical. The pivot rows are the same
 * rows, with the same columns and the same values. Only the ORDER in
 * which the INSERT statements execute during the run differs — the
 * refactored version interleaves user and pivot writes, the source
 * batched them.
 *
 * The reordering is the price of locality: a reader of
 * AdminUserSeeder can see every write that concerns the admin
 * cohort — the user rows, the tenant attachments, the role
 * assignments — in one file. A reader of ShareholderSeeder sees
 * every write that concerns the shareholder cohort in one file. The
 * alternative — keeping the pivot writes batched in the orchestrator
 * — would have required the orchestrator to know about every pivot
 * column, undoing the extraction.
 *
 * =============================================================================
 * The clear-then-seed policy
 * =============================================================================
 * The flow begins with a clear step (UserPivotCleaner::clear()). It
 * deletes every user, every shareholder-register row, every pivot
 * row that references a user, and every Sanctum bearer token. It
 * does NOT delete tenants, applications, permissions, roles, or the
 * role-permission pivot. Those are reference data: they are
 * upserted by their seeders, not cleared.
 *
 * The clear runs inside a single transaction. A failure rolls it
 * back; the database is left in its pre-clear state.
 *
 * To preserve existing users instead, comment out the
 * UserPivotCleaner::clear() call below. The rest of the flow is
 * idempotent and upserts over existing rows.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT contain reference data. See the Data\ classes.
 *
 *   - It does NOT perform upserts, deletes, or pivots. See the
 *     domain seeders and support classes.
 *
 *   - It does NOT validate the flow's intermediate results. Each
 *     domain seeder validates its own prerequisites; the
 *     orchestrator consumes their returns without inspecting them.
 *
 *   - It does NOT catch exceptions from any step. A failure in any
 *     seeder propagates to the caller, which is where a seeding
 *     run should fail loudly.
 *
 *   - It does NOT create a transaction around the whole flow. The
 *     individual steps that need a transaction (the clear step)
 *     carry their own. Wrapping the entire flow in one transaction
 *     would make the run longer-lived than necessary and would
 *     roll back reference data on a failure that only concerns
 *     user seeding.
 *
 * @package Database\Seeders
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
     * The output is byte-for-byte identical to the source's
     * printSummary() output. The values are read from the Data\
     * classes rather than from the class constants they replaced —
     * see the class docblock's revision history entry for 1.3.0.
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
        $this->command->line('  Super admin: ' . BootstrapUsers::SUPER_ADMIN['email'] . ' / ' . AdminUserSeeder::DEFAULT_PASSWORD);
        $this->command->line('  Admin:       ' . BootstrapUsers::PLATFORM_ADMIN['email'] . ' / ' . AdminUserSeeder::DEFAULT_PASSWORD);
        $this->command->line('');
        $this->command->line('  All shareholders share the password: ' . AdminUserSeeder::DEFAULT_PASSWORD);
        $this->command->line('  Usernames are derived from the first name (lowercase).');
        $this->command->line('  Emails follow {firstname}{fathername}@maalearna.com.et.');
        $this->command->line('  Example: SH-0009 Gelebo Gelefa → gelebo / gelebogelefa@maalearna.com.et');
        $this->command->line('');
        $this->command->warn('Change all passwords immediately — this is a bootstrap credential.');
    }
}