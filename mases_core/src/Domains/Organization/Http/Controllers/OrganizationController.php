<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAuditEvent;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationAssignmentPolicy;
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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeUnitList($request->user());
        $tenantId = $this->tenantId();

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
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'legal_entity_id' => ['nullable', 'integer'],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'unit_type' => ['required', Rule::in(['company', 'division', 'branch', 'department', 'team'])],
            'name_am' => ['nullable', 'string', 'max:180'],
            'name_en' => ['nullable', 'string', 'max:180'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $this->assertName($data);

        $unit = DB::transaction(function () use ($data, $tenantId, $request): OrganizationUnit {
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
        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'legal_entity_id' => ['sometimes', 'nullable', 'integer'],
            'code' => ['sometimes', 'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'unit_type' => ['sometimes', 'required', Rule::in(['company', 'division', 'branch', 'department', 'team'])],
            'name_am' => ['sometimes', 'nullable', 'string', 'max:180'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:180'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $this->assertName(array_merge($unit->only(['name_am', 'name_en']), $data));
        $tenantId = $this->tenantId();

        $updated = DB::transaction(function () use ($unit, $data, $tenantId, $request): OrganizationUnit {
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
            $changes = array_intersect_key($data, array_flip(['legal_entity_id', 'unit_type', 'name_am', 'name_en', 'status']));
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
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        DB::transaction(function () use ($unit, $request, $data): void {
            $locked = OrganizationUnit::query()->where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($unit->id);
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
            $this->audit($this->tenantId(), $request, 'unit', $locked->id, 'archived', $data['reason'], $before, $locked->toArray());
        });
        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'archived']]);
    }

    public function assignments(Request $request, int $id): JsonResponse
    {
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->view($request->user(), $unit), 403, 'Organization unit access is not authorized.');
        $items = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())
            ->where('organization_unit_id', $unit->id)->with('person')
            ->orderByDesc('starts_on')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        abort_unless($this->assignmentPolicy->create($request->user()), 403, 'Assignment creation is not authorized.');
        $unit = $this->unit($id);
        abort_unless($unit->status === 'active', 409, 'Assignments can only be made to active units.');
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'assignment_type' => ['required', 'string', 'max:48'],
            'title_am' => ['nullable', 'string', 'max:160'], 'title_en' => ['nullable', 'string', 'max:160'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $assignment = DB::transaction(function () use ($unit, $data, $request): OrganizationAssignment {
            $person = Person::query()->whereKey($data['person_id'])->whereHas('tenantAccesses', function ($q): void {
                $q->where('tenant_id', $this->tenantId())->where('status', 'active');
            })->first();
            if (!$person) {
                throw new NotFoundHttpException('Person is not available in the resolved tenant.');
            }
            $tenantId = $this->tenantId();
            $item = OrganizationAssignment::query()->create([
                'tenant_id' => $tenantId, 'organization_unit_id' => $unit->id, 'person_id' => $person->id,
                'assignment_type' => $data['assignment_type'], 'title_am' => $data['title_am'] ?? null,
                'title_en' => $data['title_en'] ?? null, 'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null, 'status' => 'active',
                'lifecycle_reason' => $data['reason'], 'created_by' => $request->user()->getAuthIdentifier(),
                'updated_by' => $request->user()->getAuthIdentifier(),
            ]);
            $this->audit($tenantId, $request, 'assignment', $item->id, 'created', $data['reason'], null, $item->toArray());
            return $item;
        });
        return response()->json(['success' => true, 'data' => $assignment], 201);
    }

    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $item = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        abort_unless($this->assignmentPolicy->update($request->user(), (int) $item->tenant_id), 403, 'Assignment update is not authorized.');
        $data = $request->validate([
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::in(['active', 'ended', 'cancelled'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($item, $data, $request): void {
            $locked = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($item->id);
            $before = $locked->toArray();
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

    private function resolveParent(int $tenantId, mixed $parentId): ?OrganizationUnit
    {
        if ($parentId === null) {
            return null;
        }
        $parent = OrganizationUnit::query()->where('tenant_id', $tenantId)->whereNull('deleted_at')->find($parentId);
        if (!$parent) {
            throw new NotFoundHttpException('Parent unit was not found in the resolved tenant.');
        }
        return $parent;
    }

    private function assertParentType(string $type, ?OrganizationUnit $parent): void
    {
        $allowed = [
            'company' => [], 'division' => ['company'], 'branch' => ['company', 'division'],
            'department' => ['company', 'division', 'branch'], 'team' => ['department'],
        ];
        if (($type === 'company' && $parent !== null)
            || ($type !== 'company' && ($parent === null || !in_array($parent->unit_type, $allowed[$type] ?? [], true)))) {
            throw new ConflictHttpException('The parent unit type is not valid for this organization unit type.');
        }
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