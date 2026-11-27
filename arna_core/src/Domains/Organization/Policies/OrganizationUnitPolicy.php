<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Organization\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Organization\Models\OrganizationUnit;

final class OrganizationUnitPolicy
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'organization.unit.view');
    }

    public function view(User $user, OrganizationUnit $unit): bool
    {
        return $this->sameTenant($unit) && $this->allows($user, 'organization.unit.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'organization.unit.manage');
    }

    public function update(User $user, OrganizationUnit $unit): bool
    {
        return $this->sameTenant($unit) && $this->allows($user, 'organization.unit.manage');
    }

    public function delete(User $user, OrganizationUnit $unit): bool
    {
        return $this->sameTenant($unit) && $this->allows($user, 'organization.unit.manage');
    }

    private function allows(User $user, string $permission): bool
    {
        if (!function_exists('arna_tenant_id') || arna_tenant_id() === null) {
            return false;
        }

        return $this->authorization->hasPermission($permission, $user, (int) arna_tenant_id());
    }

    private function sameTenant(OrganizationUnit $unit): bool
    {
        return function_exists('arna_tenant_id')
            && arna_tenant_id() !== null
            && (int) $unit->tenant_id === (int) arna_tenant_id();
    }
}