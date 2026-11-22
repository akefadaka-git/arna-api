<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionPhysicalBallotService;

final class GovernanceElectionPhysicalBallotController extends Controller
{
    public function issue(
        Request $request,
        int $election,
        GovernanceElectionPhysicalBallotService $ballots,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $data = $request->validate([
            'voter_id' => ['required', 'integer', 'min:1'],
            'ballot_reference' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        $ballot = $ballots->issue(
            $tenantId,
            (int) $record->id,
            (int) $data['voter_id'],
            $data['ballot_reference'],
            (int) $actor->getAuthIdentifier(),
        );

        return response()->json(['data' => $ballot], 201);
    }

    public function receive(
        Request $request,
        int $election,
        int $ballot,
        GovernanceElectionPhysicalBallotService $ballots,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $data = $request->validate([
            'custody_reference' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        $received = $ballots->receive(
            $tenantId,
            (int) $record->id,
            $ballot,
            $data['custody_reference'],
            (int) $actor->getAuthIdentifier(),
        );

        return response()->json(['data' => $received]);
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
