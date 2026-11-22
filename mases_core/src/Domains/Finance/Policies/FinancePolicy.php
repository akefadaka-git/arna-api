<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

abstract class FinancePolicy
{
    public function __construct(protected readonly AuthorizationService $authorization) {}

    protected function tenantId(): ?int
    {
        $id = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        return $id === null ? null : (int) $id;
    }

    protected function allows(User $user, string $permission, ?int $tenantId = null): bool
    {
        $trusted = $this->tenantId();
        $target = $tenantId ?? $trusted;
        if ($trusted === null || $target === null || $trusted !== $target) {
            return false;
        }

        // Financial entity authorization is evaluated from the current
        // database pivots on every request. A stale positive cache entry
        // must never preserve access after a Finance permission is revoked.
        $permissionExists = static function ($query) use ($permission, $target): void {
            $query->where('permissions.slug', $permission)
                ->where('permissions.scope', 'tenant')
                ->where('permissions.is_active', true)
                ->where(function ($scope) use ($target): void {
                    $scope->whereNull('permissions.tenant_id')
                        ->orWhere('permissions.tenant_id', $target);
                });
        };

        $direct = $user->permissions()
            ->where('permissions.slug', $permission)
            ->where('permissions.scope', 'tenant')
            ->where('permissions.is_active', true)
            ->where(function ($scope) use ($target): void {
                $scope->whereNull('permissions.tenant_id')
                    ->orWhere('permissions.tenant_id', $target);
            })
            ->exists();

        if ($direct) {
            return true;
        }

        return $user->roles()
            ->where('roles.is_active', true)
            ->where(function ($scope) use ($target): void {
                $scope->whereNull('roles.tenant_id')
                    ->orWhere('roles.tenant_id', $target);
            })
            ->where(function ($application): void {
                $application->whereNull('roles.application_id')
                    ->orWhereHas('application', static fn ($app) => $app
                        ->where('slug', 'finance')
                        ->where('is_active', true));
            })
            ->whereHas('permissions', $permissionExists)
            ->exists();
    }

    protected function belongsToCurrentTenant(object $model): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null && isset($model->tenant_id) && (int) $model->tenant_id === $tenantId;
    }
}
