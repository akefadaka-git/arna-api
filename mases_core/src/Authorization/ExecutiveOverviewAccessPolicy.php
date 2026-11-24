<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Authorization;

use MaaleArna\\MASES\\Core\\Auth\\Models\\User;

/**
 * Owner-approved Executive Overview audience boundary.
 *
 * CEO and President enter through a tenant-scoped permission. Finance
 * Manager enters through a Finance-application-scoped permission. The
 * role/permission pair is checked from current pivots, without cache.
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
            ->where(function ($audience) use ($tenantId): void {
                $audience->where(function ($tenantRole) use ($tenantId): void {
                    $tenantRole
                        ->whereIn('roles.slug', ['ceo', 'president'])
                        ->where('roles.scope', 'tenant')
                        ->whereHas('permissions', static function ($permission) use ($tenantId): void {
                            $permission
                                ->where('permissions.slug', 'dashboard.executive.view')
                                ->where('permissions.scope', 'tenant')
                                ->where('permissions.is_active', true)
                                ->whereNull('permissions.application_id')
                                ->where(function ($scope) use ($tenantId): void {
                                    $scope->whereNull('permissions.tenant_id')
                                        ->orWhere('permissions.tenant_id', $tenantId);
                                });
                        });
                })->orWhere(function ($financeRole): void {
                    $financeRole
                        ->where('roles.slug', 'finance_manager')
                        ->where('roles.scope', 'application')
                        ->whereHas('application', static fn ($application) => $application
                            ->where('slug', 'finance')
                            ->where('is_active', true))
                        ->whereHas('permissions', static function ($permission): void {
                            $permission
                                ->where('permissions.slug', 'dashboard.finance.executive.view')
                                ->where('permissions.scope', 'application')
                                ->where('permissions.is_active', true)
                                ->whereColumn('permissions.application_id', 'roles.application_id')
                                ->whereNull('permissions.tenant_id');
                        });
                });
            })
            ->exists();
    }
}
