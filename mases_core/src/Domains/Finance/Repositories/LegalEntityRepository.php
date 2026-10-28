<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Repositories;

use Illuminate\\Contracts\\Pagination\\LengthAwarePaginator;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LegalEntity;

/**
 * Tenant-scoped read repository for Finance legal entities.
 *
 * All public queries require an explicit tenant id. Mutations belong to
 * LegalEntityService so domain invariants remain in one write path.
 */
final class LegalEntityRepository
{
    public function paginateForTenant(int $tenantId, int $perPage = 25): LengthAwarePaginator
    {
        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('code')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForTenant(int $tenantId, int $entityId): ?LegalEntity
    {
        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->find($entityId);
    }
}
