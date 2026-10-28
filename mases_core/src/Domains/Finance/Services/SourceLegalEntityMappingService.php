<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\SourceLegalEntityMappingContract;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceAuditEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\SourceLegalEntityMapping;

final class SourceLegalEntityMappingService implements SourceLegalEntityMappingContract
{
    public function request(
        int $tenantId,
        JournalSourceType $sourceType,
        string $sourceId,
        int $legalEntityId,
        User $actor,
        string $reason,
    ): SourceLegalEntityMapping {
        $sourceId = trim($sourceId);
        $reason = trim($reason);
        $this->assertTrustedTenant($tenantId);
        if ($sourceId === '' || !$sourceType->requiresSourceReference() || $legalEntityId < 1 || $reason === '') {
            throw ValidationException::withMessages([
                'mapping' => 'An external source, source reference, legal entity, and non-empty reason are required.',
            ]);
        }

        Gate::forUser($actor)->authorize('create', SourceLegalEntityMapping::class);
        $this->assertActiveEntity($tenantId, $legalEntityId);

        return DB::transaction(function () use ($tenantId, $sourceType, $sourceId, $legalEntityId, $actor, $reason): SourceLegalEntityMapping {
            $mapping = new SourceLegalEntityMapping();
            $mapping->fill([
                'tenant_id' => $tenantId,
                'source_type' => $sourceType->value,
                'source_id' => $sourceId,
                'legal_entity_id' => $legalEntityId,
                'created_by' => (int) $actor->id,
                'reason' => $reason,
            ]);
            $mapping->forceFill(['status' => SourceLegalEntityMapping::PENDING]);
            $mapping->save();

            $this->audit($mapping, $actor, 'source_mapping.requested', null, $this->state($mapping), $reason);
            return $mapping;
        });
    }

    public function approve(int $mappingId, User $actor, string $reason): SourceLegalEntityMapping
    {
        $reason = trim($reason);
        if ($mappingId < 1 || $reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mapping ID and approval reason are required.']);
        }
        $tenantId = $this->requireTrustedTenant();

        return DB::transaction(function () use ($mappingId, $actor, $reason, $tenantId): SourceLegalEntityMapping {
            $mapping = SourceLegalEntityMapping::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($mappingId)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $mapping);

            if ($mapping->status !== SourceLegalEntityMapping::PENDING) {
                throw ValidationException::withMessages(['mapping' => 'Only pending mappings may be approved.']);
            }
            $this->assertActiveEntity($tenantId, (int) $mapping->legal_entity_id);

            $before = $this->state($mapping);
            $mapping->forceFill([
                'status' => SourceLegalEntityMapping::APPROVED,
                'approved_by' => (int) $actor->id,
                'approved_at' => now(),
                'revoked_at' => null,
                'reason' => $reason,
            ]);
            $mapping->save();
            $this->audit($mapping, $actor, 'source_mapping.approved', $before, $this->state($mapping), $reason);

            return $mapping;
        });
    }

    public function revoke(int $mappingId, User $actor, string $reason): SourceLegalEntityMapping
    {
        $reason = trim($reason);
        if ($mappingId < 1 || $reason === '') {
            throw ValidationException::withMessages(['reason' => 'A mapping ID and revocation reason are required.']);
        }
        $tenantId = $this->requireTrustedTenant();

        return DB::transaction(function () use ($mappingId, $actor, $reason, $tenantId): SourceLegalEntityMapping {
            $mapping = SourceLegalEntityMapping::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($mappingId)
                ->lockForUpdate()
                ->firstOrFail();
            Gate::forUser($actor)->authorize('revoke', $mapping);

            if ($mapping->status !== SourceLegalEntityMapping::APPROVED) {
                throw ValidationException::withMessages(['mapping' => 'Only approved mappings may be revoked.']);
            }

            $before = $this->state($mapping);
            $mapping->forceFill([
                'status' => SourceLegalEntityMapping::REVOKED,
                'revoked_at' => now(),
                'reason' => $reason,
            ]);
            $mapping->save();
            $this->audit($mapping, $actor, 'source_mapping.revoked', $before, $this->state($mapping), $reason);

            return $mapping;
        });
    }

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

        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $mappings->first()->legal_entity_id)
            ->where('status', 'active')
            ->first();

        if (!$entity) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'The mapped legal entity is unavailable in this tenant; subledger posting is blocked.',
            ]);
        }

        return (int) $entity->id;
    }

    private function assertTrustedTenant(int $tenantId): void
    {
        $trusted = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        if ($trusted === null || (int) $trusted !== $tenantId) {
            throw ValidationException::withMessages(['tenant_id' => 'A matching trusted tenant context is required.']);
        }
    }

    private function requireTrustedTenant(): int
    {
        $tenantId = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        if ($tenantId === null || (int) $tenantId < 1) {
            throw ValidationException::withMessages(['tenant_id' => 'A trusted tenant context is required.']);
        }
        return (int) $tenantId;
    }

    private function assertActiveEntity(int $tenantId, int $legalEntityId): void
    {
        $exists = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalEntityId)
            ->where('status', 'active')
            ->exists();
        if (!$exists) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'The legal entity must be active and belong to the trusted tenant.',
            ]);
        }
    }

    private function state(SourceLegalEntityMapping $mapping): array
    {
        return [
            'tenant_id' => (int) $mapping->tenant_id,
            'source_type' => (string) $mapping->source_type,
            'source_id' => (string) $mapping->source_id,
            'legal_entity_id' => (int) $mapping->legal_entity_id,
            'status' => (string) $mapping->status,
            'created_by' => $mapping->created_by === null ? null : (int) $mapping->created_by,
            'approved_by' => $mapping->approved_by === null ? null : (int) $mapping->approved_by,
            'approved_at' => $mapping->approved_at?->toISOString(),
            'revoked_at' => $mapping->revoked_at?->toISOString(),
            'reason' => $mapping->reason,
        ];
    }

    private function audit(
        SourceLegalEntityMapping $mapping,
        User $actor,
        string $eventType,
        ?array $before,
        array $after,
        string $reason,
    ): void {
        FinanceAuditEntry::query()->create([
            'tenant_id' => (int) $mapping->tenant_id,
            'legal_entity_id' => (int) $mapping->legal_entity_id,
            'actor_id' => (int) $actor->id,
            'event_type' => $eventType,
            'subject_type' => 'SourceLegalEntityMapping',
            'subject_id' => (int) $mapping->id,
            'before_state' => $before,
            'after_state' => $after,
            'metadata' => ['reason' => $reason],
            'occurred_at' => now(),
        ]);
    }
}
