<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Models\Role;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

/**
 * Idempotent pivot helper for tenant membership and role assignment; records joined_at, assigned_at and assigned_by.
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