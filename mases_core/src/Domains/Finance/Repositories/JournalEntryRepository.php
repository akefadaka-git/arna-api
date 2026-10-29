<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

/** Tenant- and legal-entity-scoped read repository for journal entries. */
final class JournalEntryRepository
{
    public function paginateForEntity(int $tenantId, int $entityId, int $perPage = 25): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(['lines' => static function ($query) use ($tenantId, $entityId): void {
                $query->where('tenant_id', $tenantId)->where('legal_entity_id', $entityId)->orderBy('line_number');
            }])
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForEntity(int $tenantId, int $entityId, int $journalId): ?JournalEntry
    {
        return JournalEntry::query()
            ->with('lines')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->find($journalId);
    }
}
