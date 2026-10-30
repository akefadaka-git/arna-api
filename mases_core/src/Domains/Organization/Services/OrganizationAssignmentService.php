<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    /** @param array<string, mixed> $data */
    public function create(int $tenantId, int $unitId, array $data, User $actor, Request $request): OrganizationAssignment
    {
        return DB::transaction(function () use ($tenantId, $unitId, $data, $actor, $request): OrganizationAssignment {
            $unit = OrganizationUnit::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($unitId);
            abort_unless($unit->status === 'active', 409, 'Assignments can only be made to active units.');

            $access = PersonTenantAccess::query()
                ->where('person_id', $data['person_id'])
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $person = $access ? Person::query()->whereKey($data['person_id'])->first() : null;
            if (!$person) {
                throw new NotFoundHttpException('Person is not available in the resolved tenant.');
            }

            $assignment = OrganizationAssignment::query()->create([
                'tenant_id' => $tenantId,
                'organization_unit_id' => $unit->id,
                'person_id' => $person->id,
                'assignment_type' => $data['assignment_type'],
                'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null,
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'status' => $this->initialStatus($data['starts_on'], $data['ends_on'] ?? null),
                'lifecycle_reason' => $data['reason'],
                'created_by' => $actor->getAuthIdentifier(),
                'updated_by' => $actor->getAuthIdentifier(),
            ]);

            app(OrganizationAuditService::class)->record(
                $tenantId, $request, 'assignment', (int) $assignment->id,
                'created', $data['reason'], null, $assignment->toArray()
            );

            return $assignment;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $assignmentId, array $data, User $actor, Request $request): OrganizationAssignment
    {
        return DB::transaction(function () use ($tenantId, $assignmentId, $data, $actor, $request): OrganizationAssignment {
            $locked = OrganizationAssignment::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->findOrFail($assignmentId);
            $before = $locked->toArray();

            if (array_key_exists('ends_on', $data) && $data['ends_on'] !== null
                && Carbon::parse($data['ends_on'])->toDateString() < $locked->starts_on->toDateString()) {
                throw ValidationException::withMessages([
                    'ends_on' => 'The end date must be on or after the persisted start date.',
                ]);
            }

            $effectiveEnd = array_key_exists('ends_on', $data)
                ? $data['ends_on']
                : $locked->ends_on?->toDateString();
            $today = now()->toDateString();

            if (array_key_exists('status', $data) && $data['status'] === 'active'
                && ($locked->starts_on->toDateString() > $today || ($effectiveEnd !== null && $effectiveEnd < $today))) {
                throw new ConflictHttpException('An assignment outside its effective date interval cannot be activated.');
            }

            if ($effectiveEnd !== null && $effectiveEnd < $today
                && ($data['status'] ?? $locked->status) !== 'ended') {
                throw new ConflictHttpException('An assignment ending before today must be marked ended.');
            }

            if (array_key_exists('status', $data)) {
                $allowedTransitions = [
                    'scheduled' => ['active', 'cancelled'],
                    'active' => ['ended', 'cancelled'],
                    'ended' => [],
                    'cancelled' => [],
                ];
                if (!in_array($data['status'], $allowedTransitions[$locked->status] ?? [], true)) {
                    throw new ConflictHttpException('The assignment status transition is not allowed.');
                }
            }

            $locked->fill(array_intersect_key($data, array_flip(['ends_on', 'status'])));
            $locked->lifecycle_reason = $data['reason'];
            $locked->updated_by = $actor->getAuthIdentifier();
            $locked->save();
            $updated = $locked->fresh();

            app(OrganizationAuditService::class)->record(
                $tenantId, $request, 'assignment', (int) $locked->id,
                'updated', $data['reason'], $before, $updated->toArray()
            );

            return $updated;
        });
    }

    private function initialStatus(string $startsOn, ?string $endsOn): string
    {
        $startDate = Carbon::parse($startsOn)->toDateString();
        $endDate = $endsOn !== null ? Carbon::parse($endsOn)->toDateString() : null;
        $today = now()->toDateString();

        if ($startDate > $today) {
            return 'scheduled';
        }

        if ($endDate !== null && $endDate < $today) {
            return 'ended';
        }

        return 'active';
    }

}
