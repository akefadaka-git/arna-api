<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * UserAttacher
 *
 * The pivot-write step of the MASES core seeding flow: attaches a
 * user to a tenant (with a `joined_at` timestamp on the pivot) and
 * attaches a role to a user (with `assigned_at` and `assigned_by` on
 * the pivot). Both operations are idempotent — a second attempt with
 * the same arguments is a no-op.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a SUPPORT class. It performs two small pivot-attach
 * operations on behalf of the seeders that create users — the two
 * bootstrap admins (AdminUserSeeder) and the shareholder cohort
 * (ShareholderSeeder). It contains no reference data, no business
 * logic, and no output.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-15a). Its behavior is byte-for-byte identical
 * to the two private methods it replaces: attachUserToTenant() and
 * attachRoleToUser(). Both helpers lived on MasesCoreSeeder; both
 * are needed by two different user seeders after the split that
 * introduced Units S-15 and S-16; both moved here so that neither
 * seeder depends on the other and neither duplicates the logic.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   attachUserToTenant(User $user, Tenant $tenant): void
 *     Ensures the user is a member of the tenant. No-op if the
 *     membership already exists.
 *
 *   attachRole(User $user, ?Role $role, ?User $assignedBy): void
 *     Ensures the user holds the role. No-op if the role is null or
 *     the assignment already exists.
 *
 * Both methods return void. Their effect is the state of the pivot
 * tables when they return.
 *
 * =============================================================================
 * Why these methods are on a support class and not on a seeder
 * =============================================================================
 * In the pre-refactoring source, both methods were private on
 * MasesCoreSeeder and were called only from a single method
 * (assignUserRelations) that handled the admins and the shareholders
 * together. The refactoring split that method into AdminUserSeeder
 * and ShareholderSeeder. Both need these helpers; neither should own
 * them.
 *
 * Putting them on UserPivotCleaner was considered and rejected — that
 * class clears pivots, this one writes them. Different
 * responsibilities, different lifecycles, and no shared state.
 *
 * Putting them on AdminUserSeeder with a public visibility would have
 * coupled ShareholderSeeder to a class about admins. That coupling
 * reads wrong in the dependency graph even though it works.
 *
 * Duplicating them in both seeders would have made the pivot-write
 * rules live in two places. A future change to the timestamps or the
 * pivot columns would need to be made twice, and the two copies could
 * drift.
 *
 * A single shared support class avoids all three problems. See the
 * S-15a preflight for the full option analysis.
 *
 * =============================================================================
 * attachUserToTenant — the semantics
 * =============================================================================
 * The user-to-tenant pivot represents membership. A user may belong
 * to more than one tenant; the pivot is a many-to-many relation.
 *
 * The method writes:
 *
 *     $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
 *
 * The `joined_at` column records when the membership began. It is set
 * to `now()` on the insert, not carried from a data source. This
 * matches the source method.
 *
 * The method is guarded by an existence check:
 *
 *     if (!$user->tenants()->where('tenant_id', $tenant->id)->exists())
 *
 * If the user already belongs to the tenant, the method returns
 * without writing. This makes it idempotent — running the seeder
 * twice produces one pivot row, not two.
 *
 * WHY THE GUARD IS A QUERY, NOT A COLLECTION CHECK:
 * The user's `tenants` relation may not be loaded. Calling
 * `$user->tenants->contains($tenant->id)` would trigger a lazy load
 * of the relation, then check the loaded collection in memory. That
 * works, but it fetches every one of the user's memberships to answer
 * a question about one. The `->where(...)->exists()` form issues a
 * single `SELECT EXISTS` query, which is cheaper and does not require
 * the relation to be loaded. This matches the source method.
 *
 * =============================================================================
 * attachRole — the semantics
 * =============================================================================
 * The user-to-role pivot represents a role assignment. A user may
 * hold more than one role; the pivot is a many-to-many relation.
 *
 * The method writes:
 *
 *     $user->roles()->attach($role->id, [
 *         'assigned_at' => now(),
 *         'assigned_by' => $assignedBy?->id,
 *     ]);
 *
 * Two pivot columns:
 *
 *   assigned_at  — when the assignment was made. Set to `now()`, not
 *                  carried from a data source.
 *
 *   assigned_by  — the id of the user who made the assignment, or
 *                  null. The seeder passes the super administrator's
 *                  id for every assignment except the super
 *                  administrator's own — see AdminUserSeeder for the
 *                  attribution rule.
 *
 * The method has two guards:
 *
 *   1. A null-role early return. A role lookup that failed (a
 *      catalogue slug that does not resolve) produces null; the
 *      method returns without writing. This matches the source
 *      method, which allowed the caller to iterate a list of role
 *      slugs without needing to check each one.
 *
 *   2. An existence check, same pattern as attachUserToTenant:
 *      `$user->roles()->where('role_id', $role->id)->exists()`.
 *      Idempotent — running the seeder twice produces one pivot row.
 *
 * WHY $role IS NULLABLE BUT $user IS NOT:
 * A null user is a caller defect — the caller has no target to attach
 * a role to. A null role is a DATA condition — a catalogue slug that
 * does not resolve. The source method allowed the latter by
 * design (the caller iterates slugs and looks each up in a map);
 * it did not allow the former. The signature reflects that.
 *
 * =============================================================================
 * Why the methods are on an instance, not static
 * =============================================================================
 * Both methods are instance methods, matching UserPivotCleaner's
 * pattern. There is no state to hold today, but the class is
 * instantiable so that a future CR that needs to carry state — a
 * logger, a policy for what to write into pivot metadata — can add
 * it without a signature change at every call site.
 *
 * The cost is one `new` at each call site:
 *
 *     (new UserAttacher())->attachUserToTenant($user, $tenant);
 *
 * A caller that attaches many users can cache the instance:
 *
 *     $attacher = new UserAttacher();
 *     foreach ($users as $user) {
 *         $attacher->attachUserToTenant($user, $tenant);
 *     }
 *
 * Both call styles are supported. The seeder that follows uses the
 * cached-instance style.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT create users, tenants, or roles. It attaches
 *     existing models. A null role is skipped, not created.
 *
 *   - It does NOT detach. Both methods only add. Removing a
 *     membership or a role assignment is not a seeding concern.
 *
 *   - It does NOT clear pivots. Clearing is UserPivotCleaner's job
 *     (Unit S-9); this class writes.
 *
 *   - It does NOT validate the models. A user that has not been
 *     persisted (`$user->id === null`) produces whatever the pivot
 *     write does with it — typically a foreign-key violation the
 *     caller should see. The class does not defend against a caller
 *     passing an unsaved model.
 *
 *   - It does NOT log. It produces no output. The orchestrator's
 *     printSummary() reports the run; individual support classes do
 *     not report their own work.
 *
 *   - It does NOT swallow exceptions. If a pivot write fails — a
 *     missing pivot table, a foreign-key violation, a database
 *     connection error — the exception propagates to the caller,
 *     which is where a seeding run should fail loudly.
 *
 * @package Database\Seeders\Support
 */
final class UserAttacher
{
    /**
     * Ensure the user is a member of the tenant.
     *
     * No-op when the membership already exists; otherwise attaches
     * the user with a `joined_at` timestamp of `now()`.
     *
     * Idempotent. See the class docblock §"attachUserToTenant — the
     * semantics".
     *
     * @param User   $user   the user to attach. Must be persisted.
     * @param Tenant $tenant the tenant to attach the user to. Must
     *                       be persisted.
     *
     * @return void
     */
    public function attachUserToTenant(User $user, Tenant $tenant): void
    {
        /*
         * Existence check via a single SELECT EXISTS query. See the
         * class docblock §"attachUserToTenant — the semantics" for
         * why this is a query and not a loaded-collection check.
         */
        if (!$user->tenants()->where('tenant_id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['joined_at' => now()]);
        }
    }

    /**
     * Ensure the user holds the role.
     *
     * No-op when the role is null, or when the user already holds
     * the role; otherwise attaches the role with `assigned_at` set
     * to `now()` and `assigned_by` set to the given attributor's id
     * (or null).
     *
     * Idempotent. See the class docblock §"attachRole — the
     * semantics".
     *
     * @param User      $user       the user to attach the role to.
     *                              Must be persisted.
     * @param Role|null $role       the role to attach, or null to
     *                              skip. A null role is a data
     *                              condition (an unresolved
     *                              catalogue slug), not a caller
     *                              defect.
     * @param User|null $assignedBy the user to attribute the
     *                              assignment to, or null for "no
     *                              attributor". The seeder passes
     *                              the super administrator for
     *                              every assignment except the
     *                              super administrator's own.
     *
     * @return void
     */
    public function attachRole(User $user, ?Role $role, ?User $assignedBy): void
    {
        /*
         * Null-role skip. See the class docblock §"attachRole — the
         * semantics".
         */
        if ($role === null) {
            return;
        }

        /*
         * Existence check. Same rationale as the tenant attach.
         */
        if ($user->roles()->where('role_id', $role->id)->exists()) {
            return;
        }

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => $assignedBy?->id,
        ]);
    }
}