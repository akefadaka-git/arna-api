<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationHierarchyPolicy;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrganizationHierarchyService
{
    public function __construct(
        private readonly OrganizationHierarchyPolicy $policy,
    ) {
    }

    /** Serialize hierarchy mutations for one tenant before locking individual units. */
    public function lockTenant(int $tenantId): void
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        if (!$tenant) {
            throw new NotFoundHttpException('Resolved tenant no longer exists.');
        }
    }

    public function resolveParent(int $tenantId, mixed $parentId): ?OrganizationUnit
    {
        if ($parentId === null) {
            return null;
        }

        $parent = OrganizationUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->find($parentId);

        if (!$parent) {
            throw new NotFoundHttpException('Parent unit was not found in the resolved tenant.');
        }

        if ($parent->status !== 'active') {
            throw new ConflictHttpException('An inactive organization unit cannot be a parent.');
        }

        return $parent;
    }

    public function assertParentType(string $type, ?OrganizationUnit $parent): void
    {
        $this->policy->assertParentType($type, $parent);
    }

    public function assertNoCycle(OrganizationUnit $unit, ?OrganizationUnit $parent, int $tenantId): void
    {
        $seen = [];

        while ($parent !== null) {
            if ((int) $parent->id === (int) $unit->id || isset($seen[(int) $parent->id])) {
                throw new ConflictHttpException('Organization hierarchy cycle detected.');
            }

            $seen[(int) $parent->id] = true;
            if (count($seen) > 100) {
                throw new ConflictHttpException('Organization hierarchy exceeds the maximum depth.');
            }

            $parent = $parent->parent()->where('tenant_id', $tenantId)->first();
        }
    }

    public function assertSubtreeCompatible(OrganizationUnit $unit, string $newType, int $tenantId): void
    {
        $virtualParent = clone $unit;
        $virtualParent->unit_type = $newType;
        $this->assertDescendantsCompatible($virtualParent, $tenantId, [(int) $unit->id => true], 0);
    }

    /** @param array<int, bool> $seen */
    private function assertDescendantsCompatible(OrganizationUnit $parent, int $tenantId, array $seen, int $depth): void
    {
        if ($depth > 100) {
            throw new ConflictHttpException('Organization hierarchy exceeds the maximum depth.');
        }

        $children = OrganizationUnit::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $parent->id)
            ->get();

        foreach ($children as $child) {
            if (isset($seen[(int) $child->id])) {
                throw new ConflictHttpException('Organization hierarchy cycle detected.');
            }

            $this->policy->assertParentType((string) $child->unit_type, $parent);
            $nextSeen = $seen;
            $nextSeen[(int) $child->id] = true;
            $this->assertDescendantsCompatible($child, $tenantId, $nextSeen, $depth + 1);
        }
    }
}
