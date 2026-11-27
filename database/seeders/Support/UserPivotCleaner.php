<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\Arna\Core\Auth\Models\User;

/**
 * Destructive, transactional seeder reset of users and dependent pivots. Run only through the controlled seed/reset workflow.
 *
 * @package Database\Seeders\Support
 */
final class UserPivotCleaner
{
    /**
     * Wipe the user graph.
     *
     * Deletes every row from:
     *   - every user-to-X pivot table discovered from the User model,
     *   - `personal_access_tokens`,
     *   - `shareholders`,
     *   - `users` (via forceDelete — see the class docblock §"The
     *     1.2.1 forceDelete() rule").
     *
     * Runs inside a single transaction; a failure rolls the entire
     * wipe back.
     *
     * Returns nothing. The method's effect is the state of the
     * database when it returns.
     */
    public function clear(): void
    {
        \Database\Seeders\Support\DemoSeederGuard::assertAllowed();
        DB::transaction(function (): void {
            /*
             * Step 1 — user-to-X pivot tables.
             *
             * The list is discovered from the User model's relation
             * methods, with a fallback for names that the model
             * cannot currently resolve. See the class docblock
             * §"The pivot-table discovery method".
             *
             * Each pivot is guarded by Schema::hasTable, so a pivot
             * that has not been migrated (or has been removed) is
             * skipped without error.
             */
            foreach ($this->discoverUserPivotTables() as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            /*
             * Step 2 — Sanctum bearer-token records.
             *
             * Every row in `personal_access_tokens` references a user
             * via `tokenable_id`. A user without a token cannot
             * authenticate; deleting the tokens before the users is
             * both correct and semantically clean.
             */
            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->delete();
            }

            /*
             * Step 3 — the shareholder register rows.
             *
             * Every row in `shareholders` references a user via
             * `user_id`. Deleting them before the users avoids
             * leaving the register with dangling user references.
             */
            if (Schema::hasTable('shareholders')) {
                DB::table('shareholders')->delete();
            }

            /*
             * Step 4 — the users themselves.
             *
             * forceDelete() — NOT delete(). The User model uses the
             * SoftDeletes trait, so delete() would issue an UPDATE
             * setting deleted_at rather than a SQL DELETE. The
             * soft-deleted rows would retain their username and
             * email values, and the subsequent inserts would
             * collide with the unique indexes. forceDelete() issues
             * a real DELETE FROM users. See the class docblock
             * §"The 1.2.1 forceDelete() rule".
             *
             * This deletion is NOT guarded by Schema::hasTable —
             * the users table is the one table the seeder
             * fundamentally requires. A failure here is the correct
             * outcome on a schema that lacks it.
             */
            User::query()->forceDelete();
        });
    }

    /**
     * Discover the names of the user-to-X pivot tables that should be
     * cleared.
     *
     * Two-tier strategy:
     *
     *   1. Probe the User model for the three relation methods the
     *      platform uses — `roles`, `tenants`, `permissions` — and
     *      read the pivot table name from each one that returns a
     *      BelongsToMany relation.
     *
     *   2. Union that with a fallback list that covers the project's
     *      custom pivot names and the Laravel-default equivalents,
     *      in case a relation method is misconfigured or its related
     *      model cannot be resolved at probe time.
     *
     * The two lists are deduplicated before being returned. The
     * return is a list of table names; the caller is responsible for
     * guarding each deletion with Schema::hasTable.
     *
     * A relation whose resolution throws is caught and skipped, so
     * that a single misconfigured relation does not abort the whole
     * clear.
     *
     * @return array<int, string>
     */
    private function discoverUserPivotTables(): array
    {
        $discovered = [];

        /*
         * A fresh User instance, used only to invoke its relation
         * methods. The instance is not persisted and its relations
         * are not loaded; the relation methods below return fresh
         * BelongsToMany builders, which is what we want.
         */
        $probe = new User();

        foreach (['roles', 'tenants', 'permissions'] as $relationName) {
            if (!method_exists($probe, $relationName)) {
                continue;
            }
            try {
                $relation = $probe->{$relationName}();
                if ($relation instanceof BelongsToMany) {
                    $discovered[] = $relation->getTable();
                }
            } catch (\Throwable) {
                /*
                 * Skip relations whose related model is
                 * misconfigured — for example, a related model
                 * class that has been renamed or removed. The
                 * relation is not fatal to the clear; its table
                 * name would be caught by the fallback below if it
                 * matched one of the known names.
                 */
            }
        }

        /*
         * Fallback names. These cover the project's custom pivot
         * naming and the Laravel defaults, in both directions:
         *
         *   custom      — user_has_roles, user_has_permissions,
         *                 tenant_memberships
         *   default     — role_user, tenant_user,
         *                 user_has_tenants, permission_user
         *
         * The list is intentionally broader than the current
         * schema. Schema::hasTable in the caller filters out the
         * names that do not correspond to a real table.
         */
        $fallbacks = [
            'user_has_roles',
            'user_has_permissions',
            'tenant_memberships',
            'role_user',
            'tenant_user',
            'user_has_tenants',
            'permission_user',
        ];

        /*
         * Union the discovered names with the fallbacks, preserving
         * the order of first appearance and removing duplicates.
         */
        return array_values(array_unique(array_merge($discovered, $fallbacks)));
    }
}