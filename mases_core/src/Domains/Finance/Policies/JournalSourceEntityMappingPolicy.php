<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;

final class JournalSourceEntityMappingPolicy extends FinancePolicy
{
    // Reuse the established tenant-scoped Finance entity-management permission.
    // No role grants are broadened by this workflow change.
    public function create(User $user): bool
    {
        return $this->allows($user, 'finance.entity.manage');
    }

    public function approve(User $user, JournalSourceEntityMapping $mapping): bool
    {
        return $this->belongsToCurrentTenant($mapping)
            && (int) $mapping->created_by !== (int) $user->id
            && $this->allows($user, 'finance.entity.manage', (int) $mapping->tenant_id);
    }

    public function revoke(User $user, JournalSourceEntityMapping $mapping): bool
    {
        return $this->belongsToCurrentTenant($mapping)
            && $this->allows($user, 'finance.entity.manage', (int) $mapping->tenant_id);
    }
}
