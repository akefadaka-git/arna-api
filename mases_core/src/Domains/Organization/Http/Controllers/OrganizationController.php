<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Organization\Http\Requests\OrganizationRequestRules;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationAssignmentPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationUnitPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationAssignmentService;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationAuditService;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationUnitService;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly OrganizationUnitPolicy $unitPolicy,
        private readonly OrganizationAssignmentPolicy $assignmentPolicy,
        private readonly OrganizationUnitService $unitService,
        private readonly OrganizationAssignmentService $assignmentService,
        private readonly OrganizationAuditService $auditService,
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
        $data = $request->validate(OrganizationRequestRules::storeUnit($tenantId));
        $this->assertName($data);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $unit = $this->unitService->create($tenantId, $data, $actor, $request);

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
        $data = $request->validate(OrganizationRequestRules::updateUnit($this->tenantId(), (int) $unit->id));
        $this->assertName(array_merge($unit->only(['name_am', 'name_en']), $data));
        $tenantId = $this->tenantId();

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $updated = $this->unitService->update($tenantId, (int) $unit->id, $data, $actor, $request);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->delete($request->user(), $unit), 403, 'Organization unit archive is not authorized.');
        $data = $request->validate(OrganizationRequestRules::archiveUnit());
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->unitService->archive($this->tenantId(), (int) $unit->id, $data, $actor, $request);
        return response()->json(['success' => true, 'data' => ['id' => $id, 'status' => 'archived']]);
    }

    public function assignments(Request $request, int $id): JsonResponse
    {
        abort_unless($this->assignmentPolicy->viewAny($request->user()), 403, 'Assignment listing is not authorized.');
        $unit = $this->unit($id);
        abort_unless($this->unitPolicy->view($request->user(), $unit), 403, 'Organization unit access is not authorized.');
        $tenantId = $this->tenantId();
        $data = $request->validate(OrganizationRequestRules::listAssignments());
        $items = $this->assignmentService->paginateForUnit($tenantId, (int) $unit->id, $data);
        return response()->json(['success' => true, 'data' => $items]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        abort_unless($this->assignmentPolicy->create($request->user()), 403, 'Assignment creation is not authorized.');
        $unit = $this->unit($id);
        abort_unless($unit->status === 'active', 409, 'Assignments can only be made to active units.');
        $data = $request->validate(OrganizationRequestRules::storeAssignment());
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $assignment = $this->assignmentService->create($this->tenantId(), (int) $unit->id, $data, $actor, $request);
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
        $data = $request->validate(OrganizationRequestRules::auditEvents());

        $events = $this->auditService->paginateForTenant($tenantId, $data);

        return response()->json(['success' => true, 'data' => $events]);
    }

    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $item = OrganizationAssignment::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        abort_unless($this->assignmentPolicy->update($request->user(), (int) $item->tenant_id), 403, 'Assignment update is not authorized.');
        $data = $request->validate(OrganizationRequestRules::updateAssignment());
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $updated = $this->assignmentService->update($this->tenantId(), (int) $item->id, $data, $actor, $request);
        return response()->json(['success' => true, 'data' => $updated]);
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
        $this->auditService->record($tenantId, $request, $type, $id, $action, $reason, $before, $after);
    }
}