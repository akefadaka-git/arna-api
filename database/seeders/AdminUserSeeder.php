<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\BootstrapUsers;
use Database\Seeders\Support\UserAttacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Models\Role;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * Seeds platform bootstrap administrators, attaches tenant membership and roles, and delegates account data to BootstrapUsers.
 *
 * @package Database\Seeders
 */
final class AdminUserSeeder extends Seeder
{
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
                . 'the ArnaCoreSeeder orchestrator, which passes the '
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
                . 'the ArnaCoreSeeder orchestrator, which passes the '
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
        $isNew = ! $user->exists;

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
         * New demo accounts receive a unique, unshared random secret.
         * It is never displayed or returned; the account holder
         * establishes a usable password through email reset.
         * Existing account passwords are preserved on ordinary reseeds.
         */
        if ($isNew) {
            $user->password = Hash::make(Str::random(64));
        }

        /*
         * Null the three audit columns if they exist on this schema
         * branch. See the class docblock §"The three Schema::hasColumn
         * guards".
         */
        if ($isNew && Schema::hasColumn('users', 'password_changed_at')) {
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