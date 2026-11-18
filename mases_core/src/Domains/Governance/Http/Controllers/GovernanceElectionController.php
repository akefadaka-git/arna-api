<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;

final class GovernanceElectionController extends Controller
{
    public function index(Request $request, GovernanceElectionLifecycleService $service): JsonResponse
    {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $this->assertView($actor, $tenantId);
        return response()->json(['success' => true, 'data' => $service->list($tenantId, (int) $request->query('per_page', 25))]);
    }

    public function store(Request $request, GovernanceElectionLifecycleService $service): JsonResponse
    {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'governance_body_id' => ['nullable', 'integer', 'min:1'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'election_scope' => ['required', 'string', 'max:64'],
            'election_type' => ['required', 'string', 'max:64'],
            'timezone' => ['nullable', 'timezone'],
        ]);
        $election = $service->create($tenantId, $actor, $data);
        return response()->json(['success' => true, 'data' => $election], 201);
    }

    public function show(Request $request, int $election, GovernanceElectionLifecycleService $service): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->assertView($this->actor($request), $tenantId);
        $record = $service->get($tenantId, $election);
        return response()->json(['success' => true, 'data' => $record, 'meta' => ['transitions' => $record->transitions()->orderBy('sequence')->get()]]);
    }

    public function transition(Request $request, int $election, GovernanceElectionLifecycleService $service): JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'action' => ['required', 'in:authorize,schedule,suspend,resume,cancel,archive'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'min:5', 'max:2000'],
            'evidence_references' => ['sometimes', 'array', 'max:20'],
            'authority' => ['sometimes', 'array'],
            'schedule' => ['sometimes', 'array'],
        ]);
        $record = $service->transition(
            $tenantId, $election, $this->actor($request), $data['action'],
            (int) $data['expected_version'], $data['reason'] ?? null,
            $data['evidence_references'] ?? [], $data['authority'] ?? [], $data['schedule'] ?? [],
        );
        return response()->json(['success' => true, 'data' => $record]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        return $actor;
    }

    private function tenantId(): int
    {
        abort_unless(function_exists('mases_tenant_id'), 404);
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null || (int) $tenantId < 1, 404);
        return (int) $tenantId;
    }

    private function assertView(User $actor, int $tenantId): void
    {
        abort_unless(app(\MaaleArna\MASES\Core\Authorization\Services\AuthorizationService::class)
            ->hasPermission($actor, 'governance.election.view', $tenantId), 403);
    }
}
