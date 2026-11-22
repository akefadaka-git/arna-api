<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Policies;

use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/**
 * Canonical read-visibility policy for global Person identities.
 *
 * People reads intentionally follow the existing PersonController contract:
 * active Person + active PersonTenantAccess in the validated tenant. They do
 * not require people.manage, which is reserved for identity-management work.
 */
final class PersonPolicy
{
    public function __construct(private readonly TenantContextService $tenantContext)
    {
    }

    public function viewAny(User $user): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        return $tenantId !== null && $this->hasActiveTenantMembership($user, $tenantId);
    }

    public function view(User $user, Person $person): bool
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($tenantId === null
            || ! $this->hasActiveTenantMembership($user, $tenantId)
            || $person->trashed()
            || $person->status !== 'active') {
            return false;
        }

        return $person->tenantAccesses()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();
    }

    private function hasActiveTenantMembership(User $user, int|string $tenantId): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        return DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->exists();
    }
}
