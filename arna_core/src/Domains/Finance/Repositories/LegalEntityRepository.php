<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Repositories;

use Illuminate\\Contracts\\Pagination\\LengthAwarePaginator;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\LegalEntity;

final class LegalEntityRepository
{
    public function paginateForTenant(int $tenantId, int $perPage = 25): LengthAwarePaginator
    {
        return LegalEntity::query()
            ->with('profile')
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForTenant(int $tenantId, int $entityId): ?LegalEntity
    {
        return LegalEntity::query()
            ->with('profile')
            ->where('tenant_id', $tenantId)
            ->find($entityId);
    }
}
