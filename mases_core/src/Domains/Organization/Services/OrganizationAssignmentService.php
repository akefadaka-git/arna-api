<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;

final class OrganizationAssignmentService
{
    /** @param array<string, mixed> $filters */
    public function paginateForUnit(int $tenantId, int $unitId, array $filters): LengthAwarePaginator
    {
        return OrganizationAssignment::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_unit_id', $unitId)
            // Do not filter by current People access: assignments are retained as Organization history.
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['person_id']), fn ($q) => $q->where('person_id', $filters['person_id']))
            ->when(isset($filters['effective_on']), function ($q) use ($filters): void {
                $effectiveOn = Carbon::parse($filters['effective_on'])->toDateString();
                $q->whereDate('starts_on', '<=', $effectiveOn)
                    ->where(function ($dates) use ($effectiveOn): void {
                        $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $effectiveOn);
                    });
            })
            ->with(['person' => fn ($person) => $person->whereHas('tenantAccesses', fn ($access) => $access->where('tenant_id', $tenantId)->where('status', 'active'))])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }
}
