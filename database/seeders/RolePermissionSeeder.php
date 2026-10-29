<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\RolePermissionGrants;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use RuntimeException;

/**
 * RolePermissionSeeder
 *
 * Attaches permissions to roles — the join-table writes that turn the
 * twelve role definitions and fifty-three permissions into a
 * meaningful authorization graph. Runs after RoleSeeder and
 * PermissionSeeder, before the two user seeders.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to resolve
 * each role's grant spec — the list of permission slugs and wildcards
 * the catalogue defines — into a concrete set of permission ids, and
 * to write that set into the role's permission pivot.
 *
 * It contains no reference data — that lives in
 * Data\RolePermissionGrants. It writes no new rows to any table; the
 * roles and permissions it syncs were created by the preceding
 * seeders. Its only effect is on the pivot table between them.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-14). Its behavior is byte-for-byte identical to
 * the two private methods it replaces: assignRolePermissions() and
 * its helper resolvePermissionIds().
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(array $roles, array $permissions): void
 *     The primary entry point. Resolves and syncs. Returns nothing —
 *     see §"Why seed() returns void" below.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Resolves both
 *     prerequisites from the database and delegates to seed(). Throws
 *     a RuntimeException when either is missing or empty — see
 *     §"Why run() throws on an empty prerequisite" below.
 *
 * =============================================================================
 * The two arguments
 * =============================================================================
 *   $roles
 *     The persisted role models, keyed by slug, as returned by
 *     RoleSeeder::seed(). The keys are the role slugs — the same
 *     slugs that key RolePermissionGrants::MAP. A grant spec whose
 *     outer key does not appear in this map is skipped.
 *
 *   $permissions
 *     The persisted permission models, keyed by slug, as returned by
 *     PermissionSeeder::seed(). The keys are the permission slugs —
 *     the same slugs the grant specs name (whether literally or via
 *     a 'scope:{scope}' wildcard). A spec that names a permission
 *     not in this map is skipped.
 *
 * =============================================================================
 * The grant-spec resolution
 * =============================================================================
 * Each role's grant spec is a list of strings, each of which is one
 * of three forms:
 *
 *   1. '*' — the wildcard. Matches every permission in the
 *            permissions map. The role receives the ids of every
 *            permission passed in.
 *
 *      NOTE: '*' is checked FIRST and SHORT-CIRCUITS. If a spec
 *      contains '*' plus any other form, the other forms are
 *      ignored. This matches the source method exactly — see its
 *      early return.
 *
 *   2. 'scope:{scope}' — the scope wildcard. Matches every
 *                        permission whose `scope` field equals the
 *                        named scope (e.g. 'global', 'tenant',
 *                        'application'). The role receives the ids
 *                        of every matching permission.
 *
 *   3. A literal permission slug — the exact match. The role
 *      receives the id of the single permission whose key equals
 *      the string. A slug that does not appear in the permissions
 *      map is silently skipped.
 *
 * The resolved ids from all non-'*' specs are unioned and
 * deduplicated. A permission matched by two specs on the same role —
 * e.g. a role with both 'scope:tenant' and a literal 'file.view'
 * slug, when file.view has scope 'tenant' — is attached once.
 *
 * =============================================================================
 * The sync semantics
 * =============================================================================
 * The final write is `$role->permissions()->sync($ids)`. Laravel's
 * sync is REPLACE-with-diff: it attaches missing ids, detaches
 * extra ids, and leaves matching ids in place. It does NOT append.
 *
 * This gives the seeder two properties:
 *
 *   · Idempotent. Running the seeder repeatedly produces the same
 *     pivot state. The second run detaches nothing and attaches
 *     nothing (the diff is empty).
 *
 *   · Correcting. If a role's permission set was manually edited
 *     between runs, the next run restores it to the catalogue's
 *     definition. This is intentional — the catalogue is the
 *     authority for what each role holds.
 *
 * =============================================================================
 * Why seed() returns void
 * =============================================================================
 * Unlike the seeders before it, this one produces no new model that a
 * downstream seeder needs. The roles and permissions it operates on
 * already exist; the pivot rows it creates are not consumed by
 * anything that follows.
 *
 * The two user seeders that run next — AdminUserSeeder and
 * ShareholderSeeder — attach ROLES to USERS, not permissions to
 * roles. They read the roles map that RoleSeeder returned; they do
 * not read anything from this seeder's work.
 *
 * Returning the roles map (unchanged) would be a hollow gesture.
 * Returning the resolved permission-id lists would be data no caller
 * consumes. void is the honest signature.
 *
 * =============================================================================
 * The run() delegate
 * =============================================================================
 * A standalone invocation (`php artisan db:seed
 * --class=RolePermissionSeeder`) has no orchestrator to pass the two
 * maps. The run() delegate therefore resolves them from the database:
 *
 *   · roles are queried and keyed by slug.
 *   · permissions are queried and keyed by slug.
 *
 * Both queries return Collections keyed by the slug column. The
 * resulting maps are identical in shape to what the orchestrator
 * would have passed.
 *
 * =============================================================================
 * Why run() throws on an empty prerequisite
 * =============================================================================
 * Two failure cases are guarded against, and both throw:
 *
 *   · No roles. There is nothing to attach permissions to. The
 *     seeder runs and does nothing. That is silent failure, which is
 *     worse than an explicit one. Throwing tells the operator to run
 *     RoleSeeder first.
 *
 *   · No permissions. This case is more serious. The seeder would
 *     iterate each role's grant spec, resolve it against an empty
 *     permissions map, produce an empty id list, and sync it. The
 *     sync would DETACH every permission from every role. The
 *     database would be left with twelve permission-less roles and
 *     fifty-three unattached permissions — a state the operator
 *     would not have asked for and might not notice.
 *
 * Throwing on both cases is the correct behavior. The delegate exists
 * to serve a legitimate standalone invocation on a properly seeded
 * database; it is not a convenience for invocation on an incomplete
 * one.
 *
 * This matches the philosophy of RoleSeeder::run() (Unit S-13), which
 * throws rather than write a state inconsistent with its catalogue.
 *
 * =============================================================================
 * The roles-not-in-the-map skip
 * =============================================================================
 * The source method carries a defensive skip:
 *
 *     if (!isset($roles[$roleSlug])) {
 *         continue;
 *     }
 *
 * A grant spec whose outer key names a role that does not exist in
 * the roles map is skipped silently. This allows the grant map to
 * reference a role that has not yet been created (a staging order),
 * at the cost of silently omitting the grant rather than failing
 * loudly.
 *
 * This behavior is preserved exactly. The orchestrator always passes
 * a complete roles map — RoleSeeder::seed() returns one — so the
 * skip is dormant in the orchestrator path and active only in
 * partially seeded environments where a role is intentionally absent
 * from the catalogue.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT create roles or permissions. It attaches existing
 *     ones. A spec that names a missing permission is skipped, not
 *     created.
 *
 *   - It does NOT clear pivot rows before syncing. sync() is
 *     replace-with-diff; it handles the pivot state directly. A
 *     manual pivot clear is neither necessary nor helpful.
 *
 *   - It does NOT validate the grant catalogue. A malformed spec
 *     (a non-string entry, an unrecognized 'scope:' value) produces
 *     whatever the resolution logic does with it — the catalogue is
 *     not defended against its own editor. A malformed spec that
 *     matches no permission simply contributes no ids.
 *
 *   - It does NOT log per-role progress. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT swallow exceptions. If a sync fails — a missing
 *     pivot table, a database connection error — the exception
 *     propagates to the orchestrator, which is where a seeding run
 *     should fail loudly.
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
         * the row shape and the four grant-spec forms.
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
            $roles[$roleSlug]->permissions()->sync(
                $this->resolvePermissionIds($grantSpec, $permissions)
            );
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
            return array_map(
                static fn (Permission $p): int => (int) $p->id,
                array_values($permissions)
            );
        }

        $ids = [];

        foreach ($grantSpec as $entry) {
            /*
             * Scope wildcard. A plain `scope:{scope}` entry matches all
             * permissions in that scope. The optional
             * `-except:{slug}[,{slug}]` suffix excludes specific slugs from
             * this scope entry without affecting other grant entries.
             */
            if (str_starts_with($entry, 'scope:')) {
                $scopeSpec = substr($entry, 6);
                $parts = explode('-except:', $scopeSpec, 2);
                $scope = $parts[0];
                $excludedSlugs = isset($parts[1])
                    ? array_values(array_filter(array_map('trim', explode(',', $parts[1]))))
                    : [];

                foreach ($permissions as $slug => $permission) {
                    if ($permission->scope === $scope && !in_array($slug, $excludedSlugs, true)) {
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
                . 'the MasesCoreSeeder orchestrator, which passes the '
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
                . 'through the MasesCoreSeeder orchestrator, which '
                . 'passes the permissions map explicitly.'
            );
        }

        $this->seed($roles, $permissions);
    }
}