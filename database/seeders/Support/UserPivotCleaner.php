<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * UserPivotCleaner
 *
 * The clear step of the MASES core seeding flow: deletes every row
 * from `users` and every table that references a user, so that a
 * subsequent seeding run starts from an empty user graph.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a SUPPORT class. It performs one piece of infrastructure
 * work — the transactional wipe of the user-adjacent tables — on
 * behalf of the orchestrating seeder. It contains no reference data,
 * no business logic, and no output.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-9). Its behavior is byte-for-byte identical to
 * the two private methods it replaces: clearAllUsers() and its
 * helper discoverUserPivotTables(). The 1.2.1 forceDelete() fix is
 * preserved verbatim; see §"The 1.2.1 forceDelete() rule" below.
 *
 * =============================================================================
 * Why the name is broader than the class
 * =============================================================================
 * The class is named UserPivotCleaner because the pivot tables are
 * the widest part of its scope and the most likely source of
 * confusion. But it clears more than pivots:
 *
 *   1. every user-to-X pivot table discovered from the User model
 *      (typically `user_has_roles`, `tenant_memberships`,
 *      `user_has_permissions`, and their Laravel-default
 *      counterparts),
 *   2. `personal_access_tokens` — Sanctum's bearer-token records,
 *      each of which references a user via `tokenable_id`,
 *   3. `shareholders` — the shareholder register's tenant rows,
 *      each of which references a user via `user_id`,
 *   4. `users` itself — the parent of every table above.
 *
 * A future CR that renames the class to reflect its full scope is
 * welcome; the current name matches the plan under which it was
 * approved.
 *
 * =============================================================================
 * The public surface
 * =============================================================================
 * One method:
 *
 *   clear(): void
 *
 * Called by the orchestrator's run() as the first step, before any
 * reference data is written. The method returns nothing; its effect
 * is the state of the database after it returns.
 *
 * =============================================================================
 * The deletion order
 * =============================================================================
 * The order matters. Foreign-key constraints (or, where foreign keys
 * are absent, referential integrity as a matter of correctness)
 * require that child rows are deleted before the parent rows they
 * reference. The clear() method deletes in this order:
 *
 *   1. Pivot tables        (reference users, roles, tenants,
 *                           permissions)
 *   2. personal_access_tokens  (reference users via tokenable_id)
 *   3. shareholders        (reference users via user_id)
 *   4. users               (the parent)
 *
 * If the order were reversed — deleting users first — the pivot,
 * token, and shareholder rows would be orphaned: their foreign-key
 * columns would reference user ids that no longer exist. In a
 * schema that enforces foreign keys, the delete would be rejected.
 * In a schema that does not, it would succeed and leave dangling
 * references. Both outcomes are wrong; the order above is the
 * correct one.
 *
 * =============================================================================
 * The single transaction
 * =============================================================================
 * The entire wipe runs inside one DB::transaction. If any step
 * fails — a table is missing an expected column, a query times out,
 * the connection drops — the whole wipe rolls back and the database
 * is left in its pre-clear state. This is the correct failure mode
 * for a clear step: a partial wipe would leave the user graph in an
 * inconsistent state that the subsequent seed would not repair.
 *
 * =============================================================================
 * The 1.2.1 forceDelete() rule
 * =============================================================================
 * The users deletion uses User::query()->forceDelete(), NOT
 * ->delete(). This is the fix recorded in the 1.2.1 revision of
 * MasesCoreSeeder, and it is preserved here without alteration.
 *
 * WHY: The User model uses the SoftDeletes trait. A call to
 * ->delete() on a model with that trait does NOT issue a SQL
 * DELETE. It issues an UPDATE setting `deleted_at` to the current
 * timestamp and leaves the row physically in the table.
 *
 * The `users.users_username_unique` index sees soft-deleted rows.
 * After the first seeder run, the `superadmin`, `admin`, and every
 * shareholder username were present in the table with a non-null
 * `deleted_at` value. The second run's insert of `superadmin`
 * collided with the soft-deleted row and the seeder aborted with:
 *
 *     SQLSTATE[23000]: Integrity constraint violation:
 *     1062 Duplicate entry 'superadmin' for key
 *     'users.users_username_unique'
 *
 * forceDelete() bypasses the SoftDeletes trait and issues a real
 * SQL `DELETE FROM users`. Every row — live or soft-deleted — is
 * removed. The unique index is clean, and the subsequent inserts
 * succeed.
 *
 * WHY THIS WAS NOT CAUGHT EARLIER: The seeder's first run after
 * `migrate:fresh` succeeded because the users table was empty —
 * clearAllUsers() had nothing to soft-delete. The collision only
 * surfaced on the SECOND run, when the first run's soft-deleted
 * rows were still present. The fix makes the seeder correct
 * regardless of how many times it has been run.
 *
 * The other deletions in clear() — for the pivot tables,
 * `personal_access_tokens`, and `shareholders` — were already
 * correct. They use `DB::table(...)->delete()`, which is the query
 * builder and has no knowledge of SoftDeletes; it always issues a
 * real `DELETE`. Only the User model call was affected.
 *
 * =============================================================================
 * The pivot-table discovery method
 * =============================================================================
 * The list of pivot tables to clear is not hardcoded. The class
 * probes the User model for the three relation methods the platform
 * uses — `roles`, `tenants`, `permissions` — and, for each one that
 * returns a BelongsToMany relation, reads the pivot table name from
 * the relation itself:
 *
 *     $relation = (new User())->{$relationName}();
 *     if ($relation instanceof BelongsToMany) {
 *         $discovered[] = $relation->getTable();
 *     }
 *
 * This covers the project's custom pivot names — `user_has_roles`,
 * `tenant_memberships`, `user_has_permissions` — and any future
 * rename of those pivots, as long as the User model's relation
 * methods are updated in lockstep.
 *
 * A fallback list covers the Laravel-default pivot names
 * (`role_user`, `tenant_user`, `user_has_tenants`, `permission_user`)
 * plus the project's custom names, in case a relation method is
 * misconfigured or the related model cannot be resolved at probe
 * time. The discovery and the fallback are unioned and deduplicated
 * before the wipe.
 *
 * A relation whose resolution throws is caught and skipped, so that
 * a single misconfigured relation does not abort the whole clear.
 *
 * =============================================================================
 * The Schema::hasTable guards
 * =============================================================================
 * Every deletion is guarded by Schema::hasTable(). This lets the
 * clear step run on a partially migrated database — for example,
 * during development on a schema branch where `shareholders` does
 * not yet exist, or on a fresh install where a specific migration
 * has been temporarily disabled. The guards make the class tolerant
 * of a schema that is narrower than the full platform schema, at the
 * cost of silently skipping the tables that are absent.
 *
 * The `users` deletion is NOT guarded. The users table is the one
 * table the seeder fundamentally requires; if it is absent, the
 * seeder cannot proceed regardless of the clear step. A failure at
 * that point is the correct outcome.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT seed. It clears. The seeding of reference data and
 *     user records is the orchestrator's job.
 *
 *   - It does NOT log. It produces no output. The orchestrator's
 *     printSummary() reports what was seeded; nothing reports what
 *     was cleared, because a successful clear leaves no trace.
 *
 *   - It does NOT take configuration. There is no "preserve existing
 *     users" flag. A caller that wants to preserve existing users
 *     does not call this class at all. The strategy that documented
 *     this refactoring made that a call-site decision, not a
 *     method-argument decision.
 *
 *   - It does NOT catch exceptions from the transaction. If the
 *     transaction fails, the exception propagates to the caller
 *     (the orchestrator), which is where a seeding run should fail
 *     loudly.
 *
 *   - It does NOT carry reference data. That is the `Data\` layer's
 *     job.
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