<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Organization\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;

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
        return function_exists('arna_tenant_id')
            && arna_tenant_id() !== null
            && $this->authorization->hasPermission($permission, $user, (int) arna_tenant_id());
    }

    private function sameTenant(int $tenantId): bool
    {
        return function_exists('arna_tenant_id')
            && arna_tenant_id() !== null
            && (int) arna_tenant_id() === $tenantId;
    }
}