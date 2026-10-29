<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\People\Models\Person;

final class PersonPolicy
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null && $this->canViewPeople($user, $tenantId);
    }

    public function view(User $user, Person $person): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null
            && $this->belongsToTenant($person, $tenantId)
            && $this->canViewPeople($user, $tenantId);
    }

    public function create(User $user): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null
            && $this->authorization->hasPermission(
                permissionSlug: 'people.manage',
                user: $user,
                tenantId: $tenantId,
            );
    }

    public function update(User $user, Person $person): bool
    {
        $tenantId = $this->tenantId();

        return $tenantId !== null
            && $this->belongsToTenant($person, $tenantId)
            && $this->authorization->hasPermission(
                permissionSlug: 'people.manage',
                user: $user,
                tenantId: $tenantId,
            );
    }

    private function canViewPeople(User $user, int $tenantId): bool
    {
        return $this->authorization->hasPermission(
            permissionSlug: 'people.view',
            user: $user,
            tenantId: $tenantId,
        ) || $this->authorization->hasPermission(
            permissionSlug: 'people.manage',
            user: $user,
            tenantId: $tenantId,
        );
    }

    private function belongsToTenant(Person $person, int $tenantId): bool
    {
        return $person->tenantAccesses()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
    }

    private function tenantId(): ?int
    {
        if (! function_exists('mases_tenant_id')) {
            return null;
        }

        $id = mases_tenant_id();

        return $id === null ? null : (int) $id;
    }
}
