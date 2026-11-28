<?php

declare(strict_types=1);

namespace MaaleArna\\Arna\\Core\\Domains\\Finance\\Repositories;

use Illuminate\\Contracts\\Pagination\\LengthAwarePaginator;
use MaaleArna\\Arna\\Core\\Domains\\Finance\\Models\\FinanceDocumentLink;

final class FinanceDocumentLinkRepository
{
    /** @param array<string, string> $filters */
    public function paginateForEntity(
        int $tenantId,
        int $legalEntityId,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = FinanceDocumentLink::query()
            ->with('document')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId);

        foreach (['source_domain', 'source_type', 'source_id', 'purpose'] as $field) {
            if (isset($filters[$field]) && trim($filters[$field]) !== '') {
                $query->where($field, trim($filters[$field]));
            }
        }

        return $query->orderByDesc('linked_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }
}
