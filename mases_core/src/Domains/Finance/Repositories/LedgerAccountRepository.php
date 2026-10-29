<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;

/** Tenant- and legal-entity-scoped read repository for the chart of accounts. */
final class LedgerAccountRepository
{
    public function paginateForEntity(int $tenantId, int $entityId, int $perPage = 25): LengthAwarePaginator
    {
        return LedgerAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->orderBy('code')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForEntity(int $tenantId, int $entityId, int $accountId): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->find($accountId);
    }
}
