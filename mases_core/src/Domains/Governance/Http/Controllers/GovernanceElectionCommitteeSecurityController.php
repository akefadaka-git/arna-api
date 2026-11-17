<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCommitteeSecurityService;

final class GovernanceElectionCommitteeSecurityController extends Controller
{
    public function grantAdministrator(
        Request $request,
        int $election,
        GovernanceElectionCommitteeSecurityService $security,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $this->assertElection($tenantId, $election);
        $actor = $this->actor($request);
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'appointment_reference' => ['required', 'string', 'min:3', 'max:160'],
        ]);

        $authority = $security->grantAdministrator(
            $tenantId,
            $election,
            (int) $data['person_id'],
            (int) $data['user_id'],
            $data['appointment_reference'],
            $actor,
        );

        return response()->json(['data' => $authority], 201);
    }

    public function revokeAdministrator(
        Request $request,
        int $election,
        int $userId,
        GovernanceElectionCommitteeSecurityService $security,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $this->assertElection($tenantId, $election);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $security->revokeAdministrator($tenantId, $election, $userId, $this->actor($request), $data['reason']);

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function declareConflict(
        Request $request,
        int $election,
        GovernanceElectionCommitteeSecurityService $security,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $this->assertElection($tenantId, $election);
        $data = $request->validate([
            'subject_person_id' => ['nullable', 'integer', 'min:1'],
            'conflict_type' => ['required', 'in:self,immediate_family,financial,professional,personal,other'],
            'scope' => ['required', 'in:candidate,all'],
            'disclosure' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence_reference' => ['nullable', 'string', 'max:160'],
        ]);

        $conflict = $security->declareConflict(
            $tenantId,
            $election,
            (int) ($data['subject_person_id'] ?? 0),
            $data['conflict_type'],
            $data['scope'],
            $data['disclosure'],
            $data['evidence_reference'] ?? null,
            $this->actor($request),
        );

        return response()->json(['data' => $conflict], 201);
    }

    public function securityEvents(
        Request $request,
        int $election,
        GovernanceElectionCommitteeSecurityService $security,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $this->assertElection($tenantId, $election);
        $integrity = $security->verifySecurityEventChain($tenantId, $election);

        $events = DB::table('governance_election_committee_security_events')
            ->where('tenant_id', $tenantId)
            ->where('election_id', $election)
            ->orderByDesc('sequence')
            ->limit(200)
            ->get([
                'sequence', 'event_uuid', 'event_type', 'actor_user_id',
                'subject_user_id', 'subject_person_id', 'reference',
                'payload', 'previous_hash', 'event_hash', 'occurred_at',
            ]);

        return response()->json([
            'data' => $events,
            'meta' => [
                'limit' => 200,
                'append_only' => true,
                'hash_chain' => 'sha256',
                'integrity' => $integrity,
            ],
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        return $actor;
    }

    private function assertElection(int $tenantId, int $electionId): void
    {
        abort_if($tenantId < 1 || $electionId < 1, 404);
        abort_unless(
            DB::table('governance_elections')
                ->where('tenant_id', $tenantId)
                ->where('id', $electionId)
                ->exists(),
            404,
        );
    }

    private function tenantId(): int
    {
        if (!function_exists('mases_tenant_id')) {
            abort(404);
        }

        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 404);

        return (int) $tenantId;
    }
}
