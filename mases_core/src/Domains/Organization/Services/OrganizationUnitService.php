<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class OrganizationUnitService
{
    public function __construct(
        private readonly OrganizationHierarchyService $hierarchy,
        private readonly OrganizationAuditService $audit,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(int $tenantId, array $data, User $actor, Request $request): OrganizationUnit
    {
        return DB::transaction(function () use ($tenantId, $data, $actor, $request): OrganizationUnit {
            $this->hierarchy->lockTenant($tenantId);
            $parent = $this->hierarchy->resolveParent($tenantId, $data['parent_id'] ?? null);
            $this->hierarchy->assertParentType($data['unit_type'], $parent);

            $unit = OrganizationUnit::query()->create([
                'tenant_id' => $tenantId,
                'parent_id' => $parent?->id,
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'code' => strtoupper($data['code']),
                'unit_type' => $data['unit_type'],
                'name_am' => $data['name_am'] ?? null,
                'name_en' => $data['name_en'] ?? null,
                'status' => 'active',
                'lifecycle_reason' => $data['reason'],
                'created_by' => $actor->getAuthIdentifier(),
                'updated_by' => $actor->getAuthIdentifier(),
            ]);

            $this->audit->record($tenantId, $request, 'unit', (int) $unit->id, 'created', $data['reason'], null, $unit->toArray());

            return $unit;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $unitId, array $data, User $actor, Request $request): OrganizationUnit
    {
        return DB::transaction(function () use ($tenantId, $unitId, $data, $actor, $request): OrganizationUnit {
            $this->hierarchy->lockTenant($tenantId);
            $locked = OrganizationUnit::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($unitId);

            $before = $locked->toArray();
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $locked->parent_id;
            $parent = $this->hierarchy->resolveParent($tenantId, $parentId);

            if ($parent && (int) $parent->id === (int) $locked->id) {
                throw new ConflictHttpException('An organization unit cannot parent itself.');
            }

            $this->hierarchy->assertNoCycle($locked, $parent, $tenantId);
            $type = $data['unit_type'] ?? $locked->unit_type;
            $this->hierarchy->assertParentType($type, $parent);
            $this->hierarchy->assertSubtreeCompatible($locked, $type, $tenantId);

            $nextStatus = $data['status'] ?? $locked->status;
            if ($nextStatus === 'inactive' && $locked->status !== 'inactive'
                && ($locked->children()->whereNull('deleted_at')->exists()
                    || $locked->assignments()->whereNull('deleted_at')->where('status', 'active')->exists())) {
                throw new ConflictHttpException('Units with active children or assignments cannot be deactivated.');
            }

            $changes = array_intersect_key($data, array_flip(['unit_type', 'name_am', 'name_en', 'status']));
            if (array_key_exists('code', $data)) {
                $changes['code'] = strtoupper($data['code']);
            }
            $changes['parent_id'] = $parent?->id;
            $changes['lifecycle_reason'] = $data['reason'];
            $changes['updated_by'] = $actor->getAuthIdentifier();

            $locked->fill($changes)->save();
            $updated = $locked->fresh();
            $this->audit->record($tenantId, $request, 'unit', (int) $locked->id, 'updated', $data['reason'], $before, $updated->toArray());

            return $updated;
        });
    }

    /** @param array{reason: string} $data */
    public function archive(int $tenantId, int $unitId, array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($tenantId, $unitId, $data, $actor, $request): void {
            $this->hierarchy->lockTenant($tenantId);
            $locked = OrganizationUnit::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($unitId);

            if ($locked->children()->whereNull('deleted_at')->exists()
                || $locked->assignments()->whereNull('deleted_at')->where('status', 'active')->exists()) {
                throw new ConflictHttpException('Units with active children or assignments cannot be archived.');
            }

            $before = $locked->toArray();
            $locked->status = 'inactive';
            $locked->lifecycle_reason = $data['reason'];
            $locked->updated_by = $actor->getAuthIdentifier();
            $locked->save();
            $locked->delete();

            $this->audit->record($tenantId, $request, 'unit', (int) $locked->id, 'archived', $data['reason'], $before, $locked->toArray());
        });
    }
}
