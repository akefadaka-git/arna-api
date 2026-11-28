<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceDimension;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceDimensionValue;
use MaaleArna\Arna\Core\Domains\Finance\Models\LegalEntity;

final class FinanceDimensionService
{
    /** @param array<string, mixed> $data */
    public function createDimension(int $tenantId, int $entityId, array $data, int $actorId): FinanceDimension
    {
        $types = require dirname(__DIR__) . '/Config/dimension_types_v1.php';
        $type = strtolower(trim((string) ($data['dimension_type'] ?? '')));
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $nameEn = trim((string) ($data['name_en'] ?? ''));

        if (! isset($types[$type]) || $code === '' || $nameEn === '') {
            throw ValidationException::withMessages([
                'dimension_type' => 'A supported dimension type, code and English name are required.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $entityId, $data, $actorId, $type, $code, $nameEn): FinanceDimension {
            $entity = LegalEntity::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->find($entityId);

            if ($entity === null) {
                throw (new ModelNotFoundException())->setModel(LegalEntity::class, [$entityId]);
            }

            $dimension = FinanceDimension::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $entityId,
                'code' => $code,
                'dimension_type' => $type,
                'name_am' => isset($data['name_am']) ? trim((string) $data['name_am']) : null,
                'name_en' => $nameEn,
                'status' => 'active',
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->audit($tenantId, $entityId, $actorId, 'finance.dimension.created', 'finance_dimension', (int) $dimension->id, $dimension->toArray());

            return $dimension;
        });
    }

    /** @param array<string, mixed> $data */
    public function createValue(int $tenantId, int $entityId, int $dimensionId, array $data, int $actorId): FinanceDimensionValue
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $effectiveFrom = $data['effective_from'] ?? null;
        $effectiveTo = $data['effective_to'] ?? null;

        if ($code === '' || $nameEn === '') {
            throw ValidationException::withMessages([
                'code' => 'A dimension value code and English name are required.',
            ]);
        }

        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages([
                'effective_to' => 'The effective end date must not precede the start date.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $entityId, $dimensionId, $data, $actorId, $code, $nameEn, $effectiveFrom, $effectiveTo): FinanceDimensionValue {
            $dimension = FinanceDimension::query()
                ->where('tenant_id', $tenantId)
                ->where('legal_entity_id', $entityId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->find($dimensionId);

            if ($dimension === null) {
                throw (new ModelNotFoundException())->setModel(FinanceDimension::class, [$dimensionId]);
            }

            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            if ($parentId !== null) {
                $parentExists = FinanceDimensionValue::query()
                    ->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $entityId)
                    ->where('dimension_id', $dimensionId)
                    ->where('status', 'active')
                    ->whereKey($parentId)
                    ->exists();

                if (! $parentExists) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'The parent value must belong to the same active dimension.',
                    ]);
                }
            }

            $value = FinanceDimensionValue::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $entityId,
                'dimension_id' => $dimensionId,
                'parent_id' => $parentId,
                'code' => $code,
                'name_am' => isset($data['name_am']) ? trim((string) $data['name_am']) : null,
                'name_en' => $nameEn,
                'status' => 'active',
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->audit($tenantId, $entityId, $actorId, 'finance.dimension_value.created', 'finance_dimension_value', (int) $value->id, $value->toArray());

            return $value;
        });
    }

    /** @param array<string, mixed> $state */
    private function audit(int $tenantId, int $entityId, int $actorId, string $event, string $subject, int $subjectId, array $state): void
    {
        DB::table('finance_audit_entries')->insert([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $entityId,
            'actor_id' => $actorId,
            'event_type' => $event,
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'before_state' => null,
            'after_state' => json_encode($state, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['source' => 'finance_dimension_service'], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
