<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

final class OrganizationAssignmentPolicy
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'organization.assignment.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'organization.assignment.manage');
    }

    public function update(User $user, int $tenantId): bool
    {
        return $this->sameTenant($tenantId) && $this->allows($user, 'organization.assignment.manage');
    }

    private function allows(User $user, string $permission): bool
    {
        return function_exists('mases_tenant_id')
            && mases_tenant_id() !== null
            && $this->authorization->hasPermission($permission, $user, (int) mases_tenant_id());
    }

    private function sameTenant(int $tenantId): bool
    {
        return function_exists('mases_tenant_id')
            && mases_tenant_id() !== null
            && (int) mases_tenant_id() === $tenantId;
    }
}