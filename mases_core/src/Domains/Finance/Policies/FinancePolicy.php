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
        if ($trusted === null || $target === null || $trusted !== $target) return false;
        return $this->authorization->hasPermission(permissionSlug: $permission, user: $user, tenantId: $target);
    }

    protected function belongsToCurrentTenant(object $model): bool
    {
        $tenantId = $this->tenantId();
        return $tenantId !== null && isset($model->tenant_id) && (int) $model->tenant_id === $tenantId;
    }
}
