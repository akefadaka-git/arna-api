<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Repositories;

use Illuminate\\Contracts\\Pagination\\LengthAwarePaginator;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\FinanceDimension;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\FinanceDimensionValue;

final class FinanceDimensionRepository
{
    public function paginateForEntity(int $tenantId, int $entityId, int $perPage = 25): LengthAwarePaginator
    {
        return FinanceDimension::query()
            ->withCount('values')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->orderBy('dimension_type')
            ->orderBy('code')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function paginateValues(int $tenantId, int $entityId, int $dimensionId, int $perPage = 25): LengthAwarePaginator
    {
        return FinanceDimensionValue::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->where('dimension_id', $dimensionId)
            ->orderBy('code')
            ->paginate(min(max($perPage, 1), 100));
    }
}
