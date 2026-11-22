<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionManualCountService;

final class GovernanceElectionManualCountController extends Controller
{
    public function index(
        Request $request,
        int $election,
        GovernanceElectionManualCountService $counts,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);

        return response()->json([
            'data' => $counts->listForElection($tenantId, (int) $record->id, (int) $actor->getAuthIdentifier()),
        ]);
    }

    public function store(
        Request $request,
        int $election,
        GovernanceElectionManualCountService $counts,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $data = $request->validate([
            'batch_reference' => ['required', 'string', 'min:3', 'max:100'],
            'polling_station' => ['nullable', 'string', 'max:120'],
            'ballots_received' => ['required', 'integer', 'min:0'],
            'ballots_valid' => ['required', 'integer', 'min:0'],
            'ballots_rejected' => ['required', 'integer', 'min:0'],
            'sealed_box_reference' => ['required', 'string', 'min:3', 'max:160'],
            'count_sheet_reference' => ['required', 'string', 'min:3', 'max:160'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.candidate_id' => ['required', 'integer', 'min:1'],
            'lines.*.ballot_count' => ['required', 'integer', 'min:0'],
        ]);

        $result = $counts->record(
            $tenantId,
            (int) $record->id,
            (int) $actor->getAuthIdentifier(),
            $data,
        );

        return response()->json(['data' => $result], 201);
    }

    public function witness(
        Request $request,
        int $election,
        int $batch,
        GovernanceElectionManualCountService $counts,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $attested = $counts->witness(
            $tenantId,
            (int) $record->id,
            $batch,
            (int) $actor->getAuthIdentifier(),
        );

        return response()->json(['data' => $attested]);
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
}
