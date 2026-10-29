<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;

/** Tenant- and legal-entity-scoped read repository for accounting periods. */
final class AccountingPeriodRepository
{
    public function paginateForEntity(int $tenantId, int $entityId, int $perPage = 25): LengthAwarePaginator
    {
        return AccountingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForEntity(int $tenantId, int $entityId, int $periodId): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->find($periodId);
    }
}
