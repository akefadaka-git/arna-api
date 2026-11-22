<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization;

use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Owner-frozen audience boundary for the ARNA Executive Overview.
 *
 * The entry permission is necessary but not sufficient: callers must hold
 * one of the approved tenant-bound roles in the resolved tenant. Domain
 * permissions are checked separately by each widget provider.
 */
final class ExecutiveOverviewAccessPolicy
{
    /** @var list<string> */
    public const ROLE_SLUGS = ['ceo', 'president', 'finance_manager'];

    public function allows(User $user, int $tenantId): bool
    {
        return $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->where(function ($tenantRole): void {
                    $tenantRole
                        ->whereIn('roles.slug', ['ceo', 'president'])
                        ->where('roles.scope', 'tenant');
                })->orWhere(function ($financeRole): void {
                    $financeRole
                        ->where('roles.slug', 'finance_manager')
                        ->where('roles.scope', 'application')
                        ->whereHas('application', static fn ($application) => $application
                            ->where('slug', 'finance')
                            ->where('is_active', true));
                });
            })
            ->whereHas('permissions', static function ($permissionQuery) use ($tenantId): void {
                $permissionQuery
                    ->where('permissions.slug', 'dashboard.executive.view')
                    ->where('permissions.scope', 'tenant')
                    ->where('permissions.is_active', true)
                    ->where(function ($tenantScope) use ($tenantId): void {
                        $tenantScope->whereNull('permissions.tenant_id')
                            ->orWhere('permissions.tenant_id', $tenantId);
                    });
            })
            ->exists();
    }
}
