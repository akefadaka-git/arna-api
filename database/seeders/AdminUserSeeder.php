<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\BootstrapUsers;
use Database\Seeders\Support\UserAttacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * AdminUserSeeder
 *
 * Creates the platform's two bootstrap administrator accounts — the
 * super administrator and the platform administrator — attaches each
 * to the seeded tenant, and attaches each to its catalogue role.
 * Runs after TenantSeeder and RoleSeeder, before ShareholderSeeder.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * two admin user rows and their pivot relations, then hand the two
 * persisted models back to the caller. It contains no reference data
 * beyond the two rows it reads from Data\BootstrapUsers. It contains
 * no shareholder logic — that is ShareholderSeeder's job.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-15b). Its behavior is byte-for-byte identical
 * to the combination of the private upsertAdminUser() method and the
 * admin-specific calls that lived in the source's assignUserRelations()
 * method.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(Tenant $tenant, array $roles): array
 *     The primary entry point. Creates both admins, attaches them to
 *     the tenant and to their roles, and returns the two persisted
 *     models. Called by the orchestrator when it needs the super
 *     administrator's id to attribute later role assignments.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Resolves the tenant
 *     and roles map from the database and delegates to seed().
 *     Throws a RuntimeException when either prerequisite is missing —
 *     see §"Why run() throws" below.
 *
 * =============================================================================
 * The two arguments
 * =============================================================================
 *   $tenant
 *     The persisted Tenant model, as returned by TenantSeeder::seed().
 *     Written into each admin's `default_tenant_id` column and used
 *     by UserAttacher to write the tenant-membership pivot.
 *
 *   $roles
 *     The persisted Role models, keyed by slug, as returned by
 *     RoleSeeder::seed(). Each admin's catalogue row names a role
 *     slug — 'super_admin' for the first, 'platform_admin' for the
 *     second. The role is looked up by slug in this map; a missing
 *     slug leaves the admin unattached to any role.
 *
 * =============================================================================
 * The return shape
 * =============================================================================
 * The return is a map:
 *
 *     [
 *         'super' => User,   // the super administrator
 *         'admin' => User,   // the platform administrator
 *     ]
 *
 * Keyed by role-in-the-flow, not by username, because the
 * orchestrator's next step — ShareholderSeeder — needs the SUPER
 * administrator specifically: it passes that User as the `assignedBy`
 * value for every shareholder role assignment.
 *
 * PHP has no native support for shape types on array returns, so the
 * shape is documented in this docblock and reflected in the
 * ShareholderSeeder's parameter. A future CR that wants a native
 * shape would need a small DTO — not this unit's job.
 *
 * =============================================================================
 * The DEFAULT_PASSWORD constant
 * =============================================================================
 * The two admins are created with the same bootstrap password, held
 * here as a public constant:
 *
 *     AdminUserSeeder::DEFAULT_PASSWORD = 'Arna@2019'
 *
 * It lives on this seeder rather than on the orchestrator because
 * this seeder creates the platform's FIRST identity. The password
 * this seeder writes is, by definition, the bootstrap credential that
 * every subsequently seeded user inherits. ShareholderSeeder (Unit
 * S-16) references this constant directly.
 *
 * The dependency direction is one-way: ShareholderSeeder → AdminUserSeeder.
 * AdminUserSeeder does not know ShareholderSeeder exists. The coupling
 * is intentional and bounded.
 *
 * SECURITY: The password is a bootstrap credential. It MUST be
 * rotated on first login in any non-development environment. The
 * orchestrator's printSummary() warns the operator about this.
 *
 * =============================================================================
 * The upsert pattern
 * =============================================================================
 * Each admin is upserted via User::firstOrNew() with the email as the
 * lookup key:
 *
 *     $user = User::firstOrNew(['email' => $definition['email']]);
 *     $user->fill([ … ]);
 *     $user->password = Hash::make(self::DEFAULT_PASSWORD);
 *     $user->save();
 *
 * The two-step pattern is used instead of User::updateOrCreate() for
 * two reasons:
 *
 *   1. The `password` column is not in the fillable array — it is
 *      guarded, matching the Laravel convention. The seeder must
 *      assign it directly rather than through fill().
 *
 *   2. The three Schema-guarded audit columns (password_changed_at,
 *      last_login_at, last_login_ip) are set conditionally. Some
 *      schema branches include them; others do not. A fill() call
 *      with a key that has no matching column would fail.
 *
 * This matches the source method exactly.
 *
 * =============================================================================
 * The three Schema::hasColumn guards
 * =============================================================================
 * Three audit columns are nulled on each upsert, guarded by
 * Schema::hasColumn():
 *
 *   · password_changed_at — nulled so that the operator is prompted
 *     to change the bootstrap password on first login.
 *
 *   · last_login_at       — nulled so that a reseed resets the
 *     "last seen" state. On a fresh install these columns are
 *     already null; the assignment is only meaningful on a re-run.
 *
 *   · last_login_ip       — same rationale as last_login_at.
 *
 * The guards exist because the current schema is in flux: the three
 * columns are present on some branches and absent on others. Setting
 * them unconditionally would fail on a branch that lacks them; the
 * guard makes the seeder portable across schema branches without
 * coupling to a specific set.
 *
 * A future CR that stabilizes the schema can remove the guards. Until
 * then, they are the correct defensive posture.
 *
 * =============================================================================
 * The role attribution
 * =============================================================================
 * The two admins receive their roles with different `assigned_by`
 * values:
 *
 *   · The super administrator's role is attached with
 *     `assignedBy = null`. There is no higher authority to attribute
 *     the assignment to — the super administrator's own role grant
 *     is the platform's bootstrap act.
 *
 *   · The platform administrator's role is attached with
 *     `assignedBy = $superAdmin`. The platform administrator's role
 *     is recorded as having been granted BY the super administrator,
 *     which is the semantically correct attribution.
 *
 * This matches the source method's behavior exactly.
 *
 * =============================================================================
 * Why run() throws
 * =============================================================================
 * The standalone invocation (`php artisan db:seed
 * --class=AdminUserSeeder`) requires the tenant and the roles map to
 * already exist. Two failure cases are guarded against:
 *
 *   · No tenant. Each admin's `default_tenant_id` column must be set,
 *     and each admin must be attached to a tenant. Without a tenant,
 *     both writes are impossible. The seeder throws.
 *
 *   · No roles. Each admin's catalogue row names a role slug; the
 *     role is looked up in the map. Without a map, both lookups
 *     resolve to null, and both admins are created WITHOUT any role
 *     — a state the operator would not have asked for and might not
 *     notice. The seeder throws rather than write it.
 *
 * Both throws name the specific prerequisite and point the operator
 * to the fix. See RoleSeeder (S-13) and RolePermissionSeeder (S-14)
 * for the same philosophy.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing admins. Clearing is
 *     UserPivotCleaner's job (Unit S-9). An admin left over from a
 *     previous run is updated in place by this seeder's upsert.
 *
 *   - It does NOT attach permissions to the admins. Permissions flow
 *     through roles; RolePermissionSeeder (S-14) already attached
 *     them to the roles the admins will hold.
 *
 *   - It does NOT create the shareholder cohort. That is
 *     ShareholderSeeder's job (S-16).
 *
 *   - It does NOT write anything to stdout. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT swallow exceptions. If an upsert fails — a UNIQUE
 *     violation on username or email, a foreign-key violation on
 *     default_tenant_id, a database connection error — the exception
 *     propagates to the orchestrator, which is where a seeding run
 *     should fail loudly.
 *
 * @package Database\Seeders
 */
final class AdminUserSeeder extends Seeder
{
    /**
     * The bootstrap password assigned to both admin accounts — and,
     * through ShareholderSeeder's reference to this constant, to every
     * seeded shareholder as well.
     *
     * SECURITY: This is a bootstrap credential. It MUST be rotated on
     * first login in any non-development environment. See the class
     * docblock §"The DEFAULT_PASSWORD constant".
     */
    public const DEFAULT_PASSWORD = 'Arna@2019';

    /**
     * Create both admin accounts, attach each to the tenant and to
     * its catalogue role, and return the two persisted models.
     *
     * Idempotent: each admin is upserted by email, each membership
     * and role assignment is guarded by an existence check in
     * UserAttacher. Running repeatedly produces one row per admin,
     * one membership per admin, one role assignment per admin.
     *
     * @param Tenant $tenant
     *        the tenant the admins are scoped to. Written into each
     *        admin's `default_tenant_id` and used by UserAttacher to
     *        write the tenant-membership pivot.
     *
     * @param array<string, Role> $roles
     *        the persisted role models, keyed by slug, as returned by
     *        RoleSeeder::seed(). Each admin's role is looked up in
     *        this map by the slug its catalogue row names.
     *
     * @return array{super: User, admin: User}
     *         the two persisted admin models. See the class
     *         docblock §"The return shape".
     */
    public function seed(Tenant $tenant, array $roles): array
    {
        \Database\Seeders\Support\DemoSeederGuard::assertAllowed();
        /*
         * Upsert both admins. The order matters: the super
         * administrator is created first so that it can be passed
         * as the `assignedBy` value for the platform administrator's
         * role attachment below.
         */
        $superAdmin = $this->upsert(BootstrapUsers::SUPER_ADMIN, $tenant);
        $admin      = $this->upsert(BootstrapUsers::PLATFORM_ADMIN, $tenant);

        /*
         * Attach both admins to the tenant and to their roles. The
         * UserAttacher (Unit S-15a) supplies both operations and
         * guards each with an existence check — see that class's
         * docblock for the semantics.
         *
         * A single instance is used for all four calls. This is the
         * "cached-instance" style documented in UserAttacher's own
         * docblock; the class holds no state, but a single instance
         * is clearer than four.
         */
        $attacher = new UserAttacher();

        /*
         * Super administrator:
         *   — attached to the tenant.
         *   — attached to its role with assignedBy = null. See the
         *     class docblock §"The role attribution".
         */
        $attacher->attachUserToTenant($superAdmin, $tenant);
        $attacher->attachRole(
            $superAdmin,
            $roles[BootstrapUsers::SUPER_ADMIN['role_slug']] ?? null,
            null,
        );

        /*
         * Platform administrator:
         *   — attached to the tenant.
         *   — attached to its role with assignedBy = $superAdmin.
         */
        $attacher->attachUserToTenant($admin, $tenant);
        $attacher->attachRole(
            $admin,
            $roles[BootstrapUsers::PLATFORM_ADMIN['role_slug']] ?? null,
            $superAdmin,
        );

        return ['super' => $superAdmin, 'admin' => $admin];
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Resolves the tenant and roles map from the database and
     * delegates to seed(). Throws a RuntimeException when either
     * prerequisite is missing — see the class docblock §"Why run()
     * throws".
     *
     * Exists so that Laravel's seeder dispatcher (`php artisan
     * db:seed --class=…`) can invoke this class without silently
     * no-op'ing through the base class's empty run().
     *
     * The orchestrator uses seed() directly — it holds the Tenant
     * model and the roles map from earlier steps.
     *
     * @throws RuntimeException when the tenant is missing, or when
     *         no roles exist.
     */
    public function run(): void
    {
        /*
         * Resolve the tenant by the slug named in the super
         * administrator's catalogue row. The two admins and the
         * tenant are created by earlier seeders in the same flow;
         * TenantSeeder writes the slug this lookup uses.
         */
        $tenant = Tenant::query()
            ->where('slug', BootstrapUsers::SUPER_ADMIN['default_tenant_slug'] ?? 'main')
            ->first();

        /*
         * The `default_tenant_slug` key does not exist on the
         * BootstrapUsers rows — the row shape carries no such field.
         * The lookup falls back to 'main', which is the slug the
         * seeded tenant uses. See Data\TenantProfile for the source.
         *
         * If a future CR adds a `default_tenant_slug` field to the
         * row, the lookup uses it; otherwise the fallback applies.
         */

        if ($tenant === null) {
            throw new RuntimeException(
                'AdminUserSeeder requires the default tenant to exist '
                . 'before it can create the bootstrap admin accounts. '
                . 'Run TenantSeeder first, or invoke the seeder through '
                . 'the MasesCoreSeeder orchestrator, which passes the '
                . 'tenant explicitly.'
            );
        }

        /*
         * Resolve the roles map by querying every role row and
         * keying the result by slug. The resulting map matches the
         * shape RoleSeeder::seed() returns.
         */
        $roles = Role::query()
            ->get()
            ->keyBy('slug')
            ->all();

        if ($roles === []) {
            throw new RuntimeException(
                'AdminUserSeeder requires the role catalogue to exist '
                . 'before it can attach roles to the bootstrap admins. '
                . 'Run RoleSeeder first, or invoke the seeder through '
                . 'the MasesCoreSeeder orchestrator, which passes the '
                . 'roles map explicitly.'
            );
        }

        $this->seed($tenant, $roles);
    }

    /**
     * Upsert one admin user by email.
     *
     * The two-step pattern — firstOrNew + fill + direct password
     * assignment + save — matches the source method. See the class
     * docblock §"The upsert pattern" for why the seeder does not use
     * User::updateOrCreate().
     *
     * @param array<string, mixed> $definition
     *        one of the BootstrapUsers constants: SUPER_ADMIN or
     *        PLATFORM_ADMIN. Shape documented in Data\BootstrapUsers.
     *
     * @param Tenant $tenant
     *        the tenant to write into the admin's default_tenant_id
     *        column.
     *
     * @return User the persisted user model.
     */
    private function upsert(array $definition, Tenant $tenant): User
    {
        /*
         * firstOrNew() returns the existing row (keyed by email) or
         * a fresh unsaved instance. Either way, the subsequent fill
         * and save handle both the insert and the update paths with
         * the same code.
         */
        /** @var User $user */
        $user = User::firstOrNew(['email' => $definition['email']]);

        /*
         * The fill array carries the nine columns that are fillable
         * on the User model. The password is NOT in this array — it
         * is assigned directly below, since the model guards it.
         *
         * email_verified_at is set to now() unconditionally. The
         * bootstrap admin's email is considered verified by the act
         * of seeding — the operator who ran the seeder is the
         * operator who controls the address.
         */
        $fill = [
            'username'            => $definition['username'],
            'first_name_am'       => $definition['first_name_am'],
            'first_name_en'       => $definition['first_name_en'],
            'father_name_am'      => $definition['father_name_am'],
            'father_name_en'      => $definition['father_name_en'],
            'grandfather_name_am' => $definition['grandfather_name_am'],
            'grandfather_name_en' => $definition['grandfather_name_en'],
            'address'             => $definition['address'],
            'default_tenant_id'   => $tenant->id,
            'locale'              => $definition['locale'],
            'is_active'           => $definition['is_active'],
            'email_verified_at'   => now(),
        ];

        $user->fill($fill);

        /*
         * Hash and assign the bootstrap password directly. See the
         * class docblock §"The upsert pattern".
         */
        $user->password = Hash::make(self::DEFAULT_PASSWORD);

        /*
         * Null the three audit columns if they exist on this schema
         * branch. See the class docblock §"The three Schema::hasColumn
         * guards".
         */
        if (Schema::hasColumn('users', 'password_changed_at')) {
            $user->password_changed_at = null;
        }
        if (Schema::hasColumn('users', 'last_login_at')) {
            $user->last_login_at = null;
        }
        if (Schema::hasColumn('users', 'last_login_ip')) {
            $user->last_login_ip = null;
        }

        $user->save();

        return $user;
    }
}