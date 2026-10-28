<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\SourceLegalEntityMapping;

final class SourceLegalEntityMappingService implements SourceLegalEntityMappingContract
{
    public function resolve(int $tenantId, JournalSourceType $sourceType, string $sourceId): int
    {
        $sourceId = trim($sourceId);
        if ($tenantId < 1 || $sourceId === '' || !$sourceType->requiresSourceReference()) {
            throw ValidationException::withMessages([
                'source_id' => 'An external source type, tenant, and non-empty source reference are required.',
            ]);
        }

        $mappings = SourceLegalEntityMapping::query()
            ->where('tenant_id', $tenantId)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->where('status', SourceLegalEntityMapping::APPROVED)
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_by')
            ->limit(2)
            ->get();

        if ($mappings->count() !== 1) {
            throw ValidationException::withMessages([
                'legal_entity_id' => $mappings->isEmpty()
                    ? 'No approved source-to-legal-entity mapping exists; subledger posting is blocked.'
                    : 'Multiple approved source mappings exist; subledger posting is blocked.',
            ]);
        }

        $mapping = $mappings->first();
        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $mapping->legal_entity_id)
            ->where('status', 'active')
            ->first();

        if (!$entity) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'The mapped legal entity is unavailable in this tenant; subledger posting is blocked.',
            ]);
        }

        return (int) $entity->id;
    }
}
