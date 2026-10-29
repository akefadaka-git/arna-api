<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Organization\Http\Requests\OrganizationRequestRules;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAuditEvent;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationAssignmentPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationHierarchyPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationUnitPolicy;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly OrganizationUnitPolicy $unitPolicy,
        private readonly OrganizationAssignmentPolicy $assignmentPolicy,
        private readonly OrganizationHierarchyPolicy $hierarchyPolicy,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeUnitList($request->user());
        $tenantId = $this->tenantId();
        $request->validate(OrganizationRequestRules::unitIndex());

        $units = OrganizationUnit::query()->where('tenant_id', $tenantId)
            ->when($request->query('parent_id') !== null, fn ($q) => $q->where('parent_id', $request->integer('parent_id')))
            ->when($request->query('status') !== null, fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('parent_id')->orderBy('code')->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => $units]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->unitPolicy->create($request->user()), 403, 'Organization unit creation is not authorized.');
        $tenantId = $this->tenantId();
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper($request->input('code'))]);
        }
        $data = $request->validate(OrganizationRequestRules::storeUnit($tenantId));;
        $this->assertName($data);

        $unit = DB::transaction(function () use ($data, $tenantId, $request): OrganizationUnit {
            $this->lockTenantHierarchy($tenantId);
            $parent = $this->resolveParent($tenantId, $data['parent_id'] ?? null);
            $this->assertParentType($data['unit_type'], $parent);
            $unit = OrganizationUnit::query()->create([
                'tenant_id' => $tenantId, 'parent_id' => $parent?->id,
                'legal_entity_id' => $data['legal_entity_id'] ?? null,
                'code' => strtoupper($data['code']), 'unit_type' => $data['unit_type'],
                'name_am' => $data['name_am'] ?? null, 'name_en' => $data['name_en'] ?? null,
                'status' => 'active', 'lifecycle_reason' => $data['reason'],
                'created_by' => $request->user()->getAuthIdentifier(),
                'updated_by' => $request->user()->getAuthIdentifier(),
            ]);
            $this->audit($tenantId, $request, 'unit', $unit->id, 'created', $data['reason'], null, $unit->toArray());
            return $unit;
        });

        return response()->json(['success' => true, 'data' => $unit], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->view($request->user(), $unit), 403, 'Organization unit access is not authorized.');
        return response()->json(['success' => true, 'data' => $unit->load(['parent', 'children'])]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->update($request->user(), $unit), 403, 'Organization unit update is not authorized.');
        if ($request->exists('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper($request->input('code'))]);
        }
        $data = $request->validate(OrganizationRequestRules::updateUnit($this->tenantId(), (int) $unit->id));;
        $this->assertName(array_merge($unit->only(['name_am', 'name_en']), $data));
        $tenantId = $this->tenantId();

        $updated = DB::transaction(function () use ($unit, $data, $tenantId, $request): OrganizationUnit {
            $this->lockTenantHierarchy($tenantId);
            $locked = OrganizationUnit::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($unit->id);
            $before = $locked->toArray();
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $locked->parent_id;
            $parent = $this->resolveParent($tenantId, $parentId);
            if ($parent && (int) $parent->id === (int) $locked->id) {
                throw new ConflictHttpException('An organization unit cannot parent itself.');
            }
            $this->assertNoCycle($locked, $parent);
            $type = $data['unit_type'] ?? $locked->unit_type;
            $this->assertParentType($type, $parent);
            $this->assertSubtreeCompatible($locked, $type, $tenantId);
            $changes = array_intersect_key($data, array_flip(['unit_type', 'name_am', 'name_en', 'status']));
            if (array_key_exists('code', $data)) {
                $changes['code'] = strtoupper($data['code']);
            }
            $changes['parent_id'] = $parent?->id;
            $changes['lifecycle_reason'] = $data['reason'];
            $changes['updated_by'] = $request->user()->getAuthIdentifier();
            $locked->fill($changes)->save();
            $this->audit($tenantId, $request, 'unit', $locked->id, 'updated', $data['reason'], $before, $locked->fresh()->toArray());
            return $locked->fresh();
        });

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->delete($request->user(), $unit), 403, 'Organization unit archive is not authorized.');
        $data = $request->validate(OrganizationRequestRules::archiveUnit());
        DB::transaction(function () use ($unit, $request, $data): void {
            $tenantId = $this->tenantId();
            $this->lockTenantHierarchy($tenantId);
            $locked = OrganizationUnit::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($unit->id);
            if ($locked->children()->whereNull('deleted_at')->exists()
                || $locked->assignments()->whereNull('deleted_at')->where('status', 'active')->exists()) {
                throw new ConflictHttpException('Units with active children or assignments cannot be archived.');
            }
            $before = $locked->toArray();
            $locked->status = 'inactive';
            $locked->lifecycle_reason = $data['reason'];
            $locked->updated_by = $request->user()->getAuthIdentifier();
            $locked->save();
            $locked->delete();
            $this->audit($tenantId, $request, 'unit', $locked->id, 'archived', $data['reason'], $before, $locked->toArray());
        });
        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'archived']]);
    }

    public function assignments(Request $request, int $id): JsonResponse
    {
        abort_unless($this->assignmentPolicy->viewAny($request->user()), 403, 'Assignment listing is not authorized.');
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->view($request->user(), $unit), 403, 'Organization unit access is not authorized.');
        $tenantId = $this->tenantId();
        $request->validate(OrganizationRequestRules::listAssignments());
        $items = OrganizationAssignment::query()->where('tenant_id', $tenantId)
            ->where('organization_unit_id', $unit->id)
            ->whereHas('person.tenantAccesses', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))
            ->with('person')
            ->orderByDesc('starts_on')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        abort_unless($this->assignmentPolicy->create($request->user()), 403, 'Assignment creation is not authorized.');
        $unit = $this->unit($id);
        abort_unless($unit->status === 'active', 409, 'Assignments can only be made to active units.');
        $data = $request->validate(OrganizationRequestRules::storeAssignment());;
        $assignment = DB::transaction(function () use ($unit, $data, $request): OrganizationAssignment {
            $tenantId = $this->tenantId();
            $lockedUnit = OrganizationUnit::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($unit->id);
            abort_unless($lockedUnit->status === 'active', 409, 'Assignments can only be made to active units.');
            $access = \MaaleArna\MASES\Core\Domains\People\Models\PersonTenantAccess::query()
                ->where('person_id', $data['person_id'])->where('tenant_id', $tenantId)
                ->where('status', 'active')->lockForUpdate()->first();
            $person = $access ? Person::query()->whereKey($data['person_id'])->first() : null;
            if (!$person) {
                throw new NotFoundHttpException('Person is not available in the resolved tenant.');
            }
            $item = OrganizationAssignment::query()->create([
                'tenant_id' => $tenantId, 'organization_unit_id' => $lockedUnit->id, 'person_id' => $person->id,
                'assignment_type' => $data['assignment_type'], 'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null, 'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'status' => $this->initialAssignmentStatus($data['starts_on'], $data['ends_on'] ?? null),
                'lifecycle_reason' => $data['reason'], 'created_by' => $request->user()->getAuthIdentifier(),
                'updated_by' => $request->user()->getAuthIdentifier(),
            ]);
            $this->audit($tenantId, $request, 'assignment', $item->id, 'created', $data['reason'], null, $item->toArray());
            return $item;
        });
        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    /**
     * Return append-only lifecycle history for the current tenant.
     * The endpoint deliberately exposes no cross-tenant subject enumeration.
     */
    public function auditEvents(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->unitPolicy->viewAudit($user), 403, 'Organization audit access is not authorized.');

        $tenantId = $this->tenantId();
        $data = $request->validate(OrganizationRequestRules::auditEvents());;

        $events = OrganizationAuditEvent::query()
            ->where('tenant_id', $tenantId)
            ->when(isset($data['subject_type']), fn ($q) => $q->where('subject_type', $data['subject_type']))
            ->when(isset($data['subject_id']), fn ($q) => $q->where('subject_id', $data['subject_id']))
            ->when(isset($data['action']), fn ($q) => $q->where('action', $data['action']))
            ->when(isset($data['from']), fn ($q) => $q->whereDate('created_at', '>=', $data['from']))
            ->when(isset($data['to']), fn ($q) => $q->whereDate('created_at', '<=', $data['to']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['success' => true, 'data' => $events]);
    }

    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $item = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        abort_unless($this->assignmentPolicy->update($request->user(), (int) $item->tenant_id), 403, 'Assignment update is not authorized.');
        $data = $request->validate(OrganizationRequestRules::updateAssignment());;
        DB::transaction(function () use ($item, $data, $request): void {
            $locked = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($item->id);
            $before = $locked->toArray();
            if (array_key_exists('ends_on', $data) && $data['ends_on'] !== null
                && Carbon::parse($data['ends_on'])->toDateString() < $locked->starts_on->toDateString()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
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
            $locked->updated_by = $request->user()->getAuthIdentifier();
            $locked->save();
            $this->audit($this->tenantId(), $request, 'assignment', $locked->id, 'updated', $data['reason'], $before, $locked->fresh()->toArray());
        });
        return response()->json(['success' => true, 'data' => $item->fresh()]);
    }

    private function authorizeUnitList(?User $user): void
    {
        abort_unless($user && $this->unitPolicy->viewAny($user), 403, 'Organization unit listing is not authorized.');
    }

    private function tenantId(): int
    {
        $tenant = $this->tenantContext->requireTenant();
        return (int) $tenant->getKey();
    }

    private function unit(int $id): OrganizationUnit
    {
        return OrganizationUnit::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function lockTenantHierarchy(int $tenantId): void
    {
        // Every hierarchy-changing transaction locks the tenant row first.
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
        if (!$tenant) {
            throw new NotFoundHttpException('Resolved tenant no longer exists.');
        }
    }

    private function resolveParent(int $tenantId, mixed $parentId): ?OrganizationUnit
    {
        if ($parentId === null) {
            return null;
        }
        $parent = OrganizationUnit::query()->where('tenant_id', $tenantId)->whereNull('deleted_at')->find($parentId);
        if (!$parent) {
            throw new NotFoundHttpException('Parent unit was not found in the resolved tenant.');
        }
        if ($parent->status !== 'active') {
            throw new ConflictHttpException('An inactive organization unit cannot be a parent.');
        }
        return $parent;
    }

    private function assertSubtreeCompatible(OrganizationUnit $unit, string $newType, int $tenantId): void
    {
        $virtualParent = clone $unit;
        $virtualParent->unit_type = $newType;
        $this->assertDescendantsCompatible($virtualParent, $tenantId, [(int) $unit->id => true], 0);
    }

    private function assertDescendantsCompatible(OrganizationUnit $parent, int $tenantId, array $seen, int $depth): void
    {
        if ($depth > 100) {
            throw new ConflictHttpException('Organization hierarchy exceeds the maximum depth.');
        }
        $children = OrganizationUnit::query()->where('tenant_id', $tenantId)->where('parent_id', $parent->id)->get();
        foreach ($children as $child) {
            if (isset($seen[(int) $child->id])) {
                throw new ConflictHttpException('Organization hierarchy cycle detected.');
            }
            $this->assertParentType((string) $child->unit_type, $parent);
            $nextSeen = $seen;
            $nextSeen[(int) $child->id] = true;
            $this->assertDescendantsCompatible($child, $tenantId, $nextSeen, $depth + 1);
        }
    }

    private function initialAssignmentStatus(string $startsOn, ?string $endsOn): string
    {
        // The request validator accepts date-time strings as well as date-only values.
        // Compare normalized calendar dates so a timestamp at today's midnight is not
        // incorrectly classified as future merely because its string is longer.
        $startDate = \Illuminate\Support\Carbon::parse($startsOn)->toDateString();
        $endDate = $endsOn !== null
            ? \Illuminate\Support\Carbon::parse($endsOn)->toDateString()
            : null;
        $today = now()->toDateString();

        if ($startDate > $today) {
            return 'scheduled';
        }
        if ($endDate !== null && $endDate < $today) {
            return 'ended';
        }
        return 'active';
    }

    private function assertParentType(string $type, ?OrganizationUnit $parent): void
    {
        $this->hierarchyPolicy->assertParentType($type, $parent);
    }

    private function assertNoCycle(OrganizationUnit $unit, ?OrganizationUnit $parent): void
    {
        $seen = [];
        while ($parent !== null) {
            if ((int) $parent->id === (int) $unit->id || isset($seen[$parent->id])) {
                throw new ConflictHttpException('Organization hierarchy cycle detected.');
            }
            $seen[$parent->id] = true;
            if (count($seen) > 100) {
                throw new ConflictHttpException('Organization hierarchy exceeds the maximum depth.');
            }
            $parent = $parent->parent()->where('tenant_id', $this->tenantId())->first();
        }
    }

    private function assertName(array $data): void
    {
        if (blank($data['name_am'] ?? null) && blank($data['name_en'] ?? null)) {
            abort(422, 'At least one localized organization unit name is required.');
        }
    }

    private function audit(int $tenantId, Request $request, string $type, int $id, string $action, string $reason, ?array $before, ?array $after): void
    {
        OrganizationAuditEvent::query()->create([
            'tenant_id' => $tenantId, 'actor_user_id' => $request->user()?->getAuthIdentifier(),
            'subject_type' => $type, 'subject_id' => $id, 'action' => $action, 'reason' => $reason,
            'before_state' => $before, 'after_state' => $after,
            'request_id' => $request->header('X-Request-ID'), 'created_at' => now(),
        ]);
    }
}