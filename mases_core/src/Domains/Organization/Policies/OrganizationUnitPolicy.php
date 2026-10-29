<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;

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

    /** Audit history requires a separate explicit capability. */
    public function viewAudit(User $user): bool
    {
        return $this->allows($user, 'organization.audit.view');
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
        if (!function_exists('mases_tenant_id') || mases_tenant_id() === null) {
            return false;
        }

        return $this->authorization->hasPermission($permission, $user, (int) mases_tenant_id());
    }

    private function sameTenant(OrganizationUnit $unit): bool
    {
        return function_exists('mases_tenant_id')
            && mases_tenant_id() !== null
            && (int) $unit->tenant_id === (int) mases_tenant_id();
    }
}