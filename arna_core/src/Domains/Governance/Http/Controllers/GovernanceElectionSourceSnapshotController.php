<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Governance\Services\GovernanceElectionSourceSnapshotService;

final class GovernanceElectionSourceSnapshotController extends Controller
{
    public function index(
        Request $request,
        int $election,
        GovernanceElectionSourceSnapshotService $service,
    ): JsonResponse {
        return response()->json([
            'data' => $service->list($this->tenantId(), $election, $this->actor($request)),
        ]);
    }

    public function store(
        Request $request,
        int $election,
        GovernanceElectionSourceSnapshotService $service,
    ): JsonResponse {
        $data = $request->validate([
            'record_date_time' => ['required', 'string', 'max:40'],
        ]);

        $snapshot = $service->capture(
            $this->tenantId(),
            $election,
            $data['record_date_time'],
            $this->actor($request),
        );

        return response()->json([
            'data' => $snapshot,
            'meta' => [
                'electorate_state' => 'blocked_unverified',
                'notice' => 'This is a technical source snapshot only; it is not a verified statutory register or approved electorate.',
            ],
        ], 201);
    }

    public function show(
        Request $request,
        int $election,
        int $snapshot,
        GovernanceElectionSourceSnapshotService $service,
    ): JsonResponse {
        return response()->json([
            'data' => $service->get($this->tenantId(), $election, $snapshot, $this->actor($request)),
        ]);
    }

    public function reconcile(
        Request $request,
        int $election,
        int $snapshot,
        GovernanceElectionSourceSnapshotService $service,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        return response()->json([
            'data' => $service->reconcile(
                $this->tenantId(),
                $election,
                $snapshot,
                $data['reason'],
                $this->actor($request),
            ),
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        return $actor;
    }

    private function tenantId(): int
    {
        abort_unless(function_exists('arna_tenant_id'), 404);
        $tenantId = arna_tenant_id();
        abort_if($tenantId === null || (int) $tenantId < 1, 404);
        return (int) $tenantId;
    }
}
