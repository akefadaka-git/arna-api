<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\RolePermissionGrants;
use MaaleArna\Arna\Core\Authorization\ExecutiveOverviewAccessPolicy;
use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\Permission;
use MaaleArna\Arna\Core\Authorization\Models\Role;
use RuntimeException;

/**
 * Resolves role grant specifications into permission pivots; runs after role and permission seeders and creates no catalogue rows.
 *
 * @package Database\Seeders
 */
final class RolePermissionSeeder extends Seeder
{
    /**
     * Resolve each role's grant spec and sync it to the role's
     * permission pivot.
     *
     * Idempotent: sync replaces with diff, so a second run is a
     * no-op. See the class docblock §"The sync semantics".
     *
     * @param array<string, Role>       $roles
     *        the persisted role models, keyed by slug, as returned by
     *        RoleSeeder::seed()
     *
     * @param array<string, Permission> $permissions
     *        the persisted permission models, keyed by slug, as
     *        returned by PermissionSeeder::seed()
     *
     * @return void
     */
    public function seed(array $roles, array $permissions): void
    {
        /*
         * Read the grant map from the Data\ layer. See Unit S-6 for
         * the row shape and the three grant-spec forms.
         *
         * The map is keyed by role slug; the keys match the role
         * slugs the preceding seeder used.
         */
        $grants = RolePermissionGrants::MAP;

        foreach ($grants as $roleSlug => $grantSpec) {
            /*
             * Skip a grant whose role does not exist in the roles
             * map. See the class docblock §"The
             * roles-not-in-the-map skip".
             */
            if (!isset($roles[$roleSlug])) {
                continue;
            }

            /*
             * Resolve the grant spec to a concrete list of permission
             * ids, then sync it. sync() replaces with diff — see the
             * class docblock §"The sync semantics".
             */
            $permissionIds = $this->resolvePermissionIds($grantSpec, $permissions);

            // The Executive Overview audience is a frozen security boundary.
            // Wildcard and scope grants must not leak the entry permission to
            // roles outside CEO, President and Finance Manager.
            $executivePermission = $permissions['dashboard.executive.view'] ?? null;
            if (
                $executivePermission !== null
                && !in_array($roleSlug, ExecutiveOverviewAccessPolicy::ROLE_SLUGS, true)
            ) {
                $executivePermissionId = (int) $executivePermission->id;
                $permissionIds = array_values(array_filter(
                    $permissionIds,
                    static fn (int $permissionId): bool => $permissionId !== $executivePermissionId
                ));
            }

            $roles[$roleSlug]->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    /**
     * Resolve a grant spec into a list of permission ids.
     *
     * Three spec forms are recognized; see the class docblock
     * §"The grant-spec resolution" for their semantics.
     *
     * The wildcard form ('*') short-circuits — if present anywhere in
     * the spec, the spec resolves to every permission id and the
     * other entries are ignored. This matches the source method
     * exactly.
     *
     * The remaining forms are unioned and deduplicated. The return
     * order is the order of first appearance, which is stable for a
     * given (spec, permissions) pair but is not otherwise meaningful —
     * sync() treats the ids as a set.
     *
     * @param array<int, string>        $grantSpec
     * @param array<string, Permission> $permissions
     *
     * @return array<int, int>
     */
    private function resolvePermissionIds(array $grantSpec, array $permissions): array
    {
        /*
         * Wildcard short-circuit. If the spec contains '*', the role
         * receives every permission. The `array_map` extracts each
         * permission's id as an int — the pivot expects ints, and the
         * model's id is typed as int|string depending on the driver.
         *
         * The `array_values()` call is load-bearing: `$permissions` is
         * keyed by slug, but the id list must be a plain list. Without
         * it, `array_map` would preserve the string keys and the
         * subsequent sync would receive a map instead of a list.
         */
        if (in_array('*', $grantSpec, true)) {
            return array_values(array_map(
                static fn (Permission $p): int => (int) $p->id,
                array_values(array_filter(
                    $permissions,
                    static fn (Permission $p, string $slug): bool => !in_array(
                        $slug,
                        RolePermissionGrants::WILDCARD_EXCLUSIONS,
                        true
                    ),
                    ARRAY_FILTER_USE_BOTH
                ))
            ));
        }

        $ids = [];

        foreach ($grantSpec as $entry) {
            /*
             * Scope wildcard. The spec name is 'scope:' followed by
             * the scope value; the prefix is 6 characters long. Match
             * every permission whose `scope` field equals the given
             * value.
             */
            if (str_starts_with($entry, 'scope:')) {
                $scope = substr($entry, 6);
                foreach ($permissions as $permission) {
                    if (
                        $permission->scope === $scope
                        && !in_array($permission->slug, RolePermissionGrants::WILDCARD_EXCLUSIONS, true)
                    ) {
                        $ids[] = (int) $permission->id;
                    }
                }
                continue;
            }

            /*
             * Literal slug. Look it up in the permissions map. A
             * slug that does not appear is skipped silently — see
             * the class docblock §"The grant-spec resolution".
             */
            if (isset($permissions[$entry])) {
                $ids[] = (int) $permissions[$entry]->id;
            }
        }

        /*
         * Deduplicate. The `array_values()` wrapper restores a plain
         * list shape, since `array_unique` preserves keys.
         */
        return array_values(array_unique($ids));
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Resolves both prerequisites from the database and delegates to
     * seed(). Throws a RuntimeException when either is missing or
     * empty — see the class docblock §"Why run() throws on an empty
     * prerequisite".
     *
     * Exists so that Laravel's seeder dispatcher (`php artisan
     * db:seed --class=…`) can invoke this class without silently
     * no-op'ing through the base class's empty run().
     *
     * The orchestrator uses seed() directly — it holds both maps from
     * the two preceding steps.
     *
     * @throws RuntimeException when no roles exist, or when no
     *         permissions exist. Both conditions make the seeder's
     *         work either meaningless or destructive.
     */
    public function run(): void
    {
        /*
         * Query the roles and key them by slug. The Collection's
         * keyBy() preserves the model objects as values; the
         * resulting map matches the shape RoleSeeder::seed() returns.
         */
        $roles = Role::query()
            ->get()
            ->keyBy('slug')
            ->all();

        if ($roles === []) {
            throw new RuntimeException(
                'RolePermissionSeeder requires at least one role to '
                . 'exist before it can attach permissions. '
                . 'Run RoleSeeder first, or invoke the seeder through '
                . 'the ArnaCoreSeeder orchestrator, which passes the '
                . 'roles map explicitly.'
            );
        }

        /*
         * Query the permissions and key them by slug. Same shape
         * PermissionSeeder::seed() returns.
         */
        $permissions = Permission::query()
            ->get()
            ->keyBy('slug')
            ->all();

        if ($permissions === []) {
            throw new RuntimeException(
                'RolePermissionSeeder will not run against an empty '
                . 'permission set: sync() would detach every '
                . 'permission from every role. '
                . 'Run PermissionSeeder first, or invoke the seeder '
                . 'through the ArnaCoreSeeder orchestrator, which '
                . 'passes the permissions map explicitly.'
            );
        }

        $this->seed($roles, $permissions);
    }
}