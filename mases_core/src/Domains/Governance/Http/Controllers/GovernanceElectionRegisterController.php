<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionRegisterService;

final class GovernanceElectionRegisterController extends Controller
{
    public function show(Request $request, int $election): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $snapshot = DB::table('governance_election_register_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $record->id)
            ->first([
                'id', 'snapshot_uuid', 'record_date', 'source_reference', 'rule_version',
                'content_sha256', 'voter_count', 'register_status', 'created_by', 'prepared_by',
                'reconciled_by', 'reconciled_at', 'approved_by', 'approved_at',
                'integrity_verified_at', 'frozen_at', 'created_at', 'updated_at',
            ]);

        abort_if($snapshot === null, 404);

        return response()->json(['data' => $snapshot]);
    }

    public function prepare(
        Request $request,
        int $election,
        GovernanceElectionRegisterService $register,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $data = $request->validate([
            'record_date' => ['required', 'date_format:Y-m-d'],
            'source_reference' => ['required', 'string', 'min:3', 'max:160'],
            'rule_version' => ['required', 'string', 'min:1', 'max:80'],
        ]);

        // Voter identities, quantities and weights are reconstructed server-side.
        $entries = $register->reconstructAsOf(
            $tenantId,
            $data['record_date'],
            $data['source_reference'],
        );
        $snapshotId = $register->prepare(
            $tenantId,
            (int) $record->id,
            $data['record_date'],
            $data['source_reference'],
            $data['rule_version'],
            (int) $actor->getAuthIdentifier(),
            $entries,
        );

        return response()->json([
            'data' => ['snapshot_id' => $snapshotId, 'status' => 'draft'],
        ], 201);
    }

    public function reconcile(
        Request $request,
        int $election,
        int $snapshot,
        GovernanceElectionRegisterService $register,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $hash = $register->reconcile(
            $tenantId,
            (int) $record->id,
            $snapshot,
            (int) $actor->getAuthIdentifier(),
        );

        return response()->json([
            'data' => ['snapshot_id' => $snapshot, 'status' => 'reconciled', 'content_sha256' => $hash],
        ]);
    }

    public function approveAndFreeze(
        Request $request,
        int $election,
        int $snapshot,
        GovernanceElectionRegisterService $register,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $actor = $this->actor($request);
        $record = GovernanceElection::query()->forTenant($tenantId)->findOrFail($election);
        $register->approveAndFreeze(
            $tenantId,
            (int) $record->id,
            $snapshot,
            (int) $actor->getAuthIdentifier(),
        );

        return response()->json([
            'data' => ['snapshot_id' => $snapshot, 'status' => 'frozen'],
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
        abort_unless(function_exists('mases_tenant_id'), 404);
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null || (int) $tenantId < 1, 404);

        return (int) $tenantId;
    }
}
