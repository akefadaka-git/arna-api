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
            ->whereIn('roles.slug', self::ROLE_SLUGS)
            ->where('roles.is_active', true)
            ->where('roles.tenant_id', $tenantId)
            ->exists();
    }
}
