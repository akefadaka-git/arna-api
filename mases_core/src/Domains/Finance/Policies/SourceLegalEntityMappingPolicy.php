<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\SourceLegalEntityMapping;

final class SourceLegalEntityMappingPolicy extends FinancePolicy
{
    public function create(User $user): bool
    {
        return $this->allows($user, 'finance.source_mapping.create');
    }

    public function approve(User $user, SourceLegalEntityMapping $mapping): bool
    {
        return $this->belongsToCurrentTenant($mapping)
            && $this->allows($user, 'finance.source_mapping.approve', (int) $mapping->tenant_id);
    }

    public function revoke(User $user, SourceLegalEntityMapping $mapping): bool
    {
        return $this->belongsToCurrentTenant($mapping)
            && $this->allows($user, 'finance.source_mapping.revoke', (int) $mapping->tenant_id);
    }
}
