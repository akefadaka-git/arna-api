<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionNomination;

final class GovernanceElectionWorkflowController extends Controller
{
    public function submitNomination(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        abort_unless($governanceElection->status === 'nominations_open', 409, 'Nomination period is not open.');

        $data = $request->validate([
            'candidate_person_id' => ['required', 'integer', 'min:1'],
            'self_nomination' => ['sometimes', 'boolean'],
            'statement' => ['nullable', 'string', 'max:10000'],
        ]);

        $self = (bool) ($data['self_nomination'] ?? false);
        $submission = DB::table('governance_election_nomination_submissions')->updateOrInsert(
            [
                'tenant_id' => $tenantId,
                'election_id' => $governanceElection->id,
                'candidate_person_id' => $data['candidate_person_id'],
                'nominated_by_shareholder_id' => $shareholderId,
            ],
            [
                'self_nomination' => $self,
                'statement' => $data['statement'] ?? null,
                'status' => 'submitted',
                'submitted_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['data' => ['submitted' => (bool) $submission]], 201);
    }

    public function candidateReviewIndex(GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);

        $candidates = GovernanceElectionNomination::query()
            ->where('tenant_id', $tenantId)
            ->where('election_id', $governanceElection->id)
            ->where('final_ballot_approved', true)
            ->orderBy('id')
            ->get(['id', 'candidate_person_id', 'candidate_name_snapshot', 'nomination_statement', 'committee_status']);

        return response()->json(['data' => $candidates, 'meta' => ['binding' => false]]);
    }

    public function storeFeedback(Request $request, GovernanceElection $governanceElection, int $nomination): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:5000']]);
        $candidate = $this->approvedCandidate($tenantId, (int) $governanceElection->id, $nomination);

        DB::table('governance_election_candidate_feedback')->updateOrInsert(
            ['tenant_id' => $tenantId, 'election_id' => $governanceElection->id, 'candidate_nomination_id' => $candidate->id, 'shareholder_id' => $shareholderId],
            ['rating' => $data['rating'], 'comment' => $data['comment'] ?? null, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['data' => ['saved' => true, 'binding' => false]]);
    }

    public function storePreference(GovernanceElection $governanceElection, int $nomination): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        $candidate = $this->approvedCandidate($tenantId, (int) $governanceElection->id, $nomination);

        DB::table('governance_election_candidate_preferences')->updateOrInsert(
            ['tenant_id' => $tenantId, 'election_id' => $governanceElection->id, 'shareholder_id' => $shareholderId, 'candidate_nomination_id' => $candidate->id],
            ['updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['data' => ['saved' => true, 'binding' => false]]);
    }

    public function appointCommitteeMember(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'member_role' => ['required', 'in:committee_member,legal_advisor,supervisory_member'],
            'ex_officio' => ['sometimes', 'boolean'],
            'appointment_reference' => ['required', 'string', 'max:160'],
        ]);
        $role = $data['member_role'];
        $exOfficio = (bool) ($data['ex_officio'] ?? false);
        abort_if(($role === 'legal_advisor') !== $exOfficio, 422,
            'Only the Legal Advisor appointment is ex officio under the adopted Committee framework.');

        $member = DB::transaction(function () use ($tenantId, $governanceElection, $data, $role, $exOfficio): object {
            $members = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                ->lockForUpdate();
            $exists = (clone $members)->where(function ($query) use ($data): void {
                $query->where('person_id', $data['person_id'])->orWhere('user_id', $data['user_id']);
            })->exists();
            abort_if($exists, 409, 'This person or user is already appointed to this Committee.');

            $roleCount = (clone $members)->where('member_role', $role)->where('status', 'active')->count();
            $maximum = match ($role) {
                'committee_member' => 3,
                'legal_advisor', 'supervisory_member' => 1,
            };
            abort_if($roleCount >= $maximum, 409, 'The adopted Committee composition limit has been reached for this role.');

            $id = DB::table('governance_election_committee_members')->insertGetId([
                'tenant_id' => $tenantId, 'election_id' => $governanceElection->id,
                'person_id' => $data['person_id'], 'user_id' => $data['user_id'],
                'member_role' => $role, 'ex_officio' => $exOfficio,
                'status' => 'active', 'appointed_at' => now(),
                'appointment_reference' => $data['appointment_reference'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('governance_election_committee_members')->where('id', $id)->first();
        });

        return response()->json(['data' => $member], 201);
    }

    public function recuseCommitteeMember(Request $request, GovernanceElection $governanceElection, int $member): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate(['recusal_scope' => ['required', 'string', 'max:80']]);
        $updated = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
            ->where('id', $member)->where('status', 'active')
            ->update(['recused_at' => now(), 'recusal_scope' => $data['recusal_scope'], 'updated_at' => now()]);
        abort_if($updated !== 1, 404);
        return response()->json(['data' => ['recused' => true]]);
    }

    public function committeeQueue(GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $this->assertCommitteeMember($tenantId, (int) $governanceElection->id);

        $submissions = DB::table('governance_election_nomination_submissions')
            ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
            ->orderBy('submitted_at')->get();
        $candidates = GovernanceElectionNomination::query()
            ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
            ->orderBy('id')->get();
        $members = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
            ->orderBy('appointed_at')->orderBy('id')
            ->get(['id', 'person_id', 'user_id', 'member_role', 'ex_officio', 'status', 'recused_at', 'recusal_scope', 'appointment_reference']);

        return response()->json(['data' => ['submissions' => $submissions, 'candidates' => $candidates, 'members' => $members]]);
    }

    public function decideNomination(Request $request, GovernanceElection $governanceElection, int $nomination): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $committeeMember = $this->assertCommitteeMember($tenantId, (int) $governanceElection->id);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,returned'],
            'reason' => ['required', 'string', 'min:10', 'max:10000'],
            'meeting_reference' => ['nullable', 'string', 'max:160'],
            'evidence_reference' => ['nullable', 'string', 'max:160'],
            'final_ballot_approved' => ['sometimes', 'boolean'],
        ]);

        return DB::transaction(function () use ($tenantId, $governanceElection, $nomination, $data, $request, $committeeMember): JsonResponse {
            $candidate = GovernanceElectionNomination::query()
                ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                ->lockForUpdate()->findOrFail($nomination);

            $activeMembers = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                ->where('status', 'active')->whereNull('recused_at')->count();
            abort_if($activeMembers < 3, 409, 'Committee quorum is not established.');
            abort_if($committeeMember->recused_at !== null, 403, 'A recused committee member cannot decide this nomination.');
            abort_if((int) ($committeeMember->person_id ?? 0) === (int) ($candidate->candidate_person_id ?? 0), 403, 'A candidate cannot decide their own nomination.');

            $actorId = (int) $request->user()->getAuthIdentifier();
            $candidate->committee_status = $data['decision'];
            $candidate->committee_reason = $data['reason'];
            $candidate->committee_decided_at = now();
            $candidate->committee_decided_by = $actorId;
            $candidate->final_ballot_approved = $data['decision'] === 'approved' && (bool) ($data['final_ballot_approved'] ?? false);
            $candidate->final_ballot_approved_at = $candidate->final_ballot_approved ? now() : null;
            $candidate->save();

            DB::table('governance_election_committee_decisions')->insert([
                'tenant_id' => $tenantId,
                'election_id' => $governanceElection->id,
                'nomination_id' => $candidate->id,
                'decision_type' => 'candidate_review',
                'decision' => $data['decision'],
                'reason' => $data['reason'],
                'decided_by' => $actorId,
                'meeting_reference' => $data['meeting_reference'] ?? null,
                'evidence_reference' => $data['evidence_reference'] ?? null,
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['data' => $candidate->fresh()]);
        });
    }

    private function assertCommitteeMember(int $tenantId, int $electionId): object
    {
        $userId = auth()->id();
        abort_if($userId === null, 401);
        $member = DB::table('governance_election_committee_members')
            ->where('tenant_id', $tenantId)->where('election_id', $electionId)
            ->where('user_id', $userId)->where('status', 'active')->first();
        abort_if($member === null, 403, 'Active Electoral Committee membership is required.');
        abort_if($member->member_role === 'legal_advisor', 403,
            'Legal Advisor voting and decision eligibility is unresolved and requires verified governing-instrument authority.');
        return $member;
    }

    private function approvedCandidate(int $tenantId, int $electionId, int $nomination): GovernanceElectionNomination
    {
        return GovernanceElectionNomination::query()->where('tenant_id', $tenantId)
            ->where('election_id', $electionId)->where('final_ballot_approved', true)->findOrFail($nomination);
    }

    private function authenticatedShareholderId(int $tenantId): int
    {
        $userId = auth()->id();
        abort_if($userId === null, 401);
        $matches = DB::table('shareholders')->where('tenant_id', $tenantId)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)->orWhere('representative_user_id', $userId);
            })->limit(2)->pluck('id');
        abort_if($matches->count() !== 1, 409, 'The authenticated account must resolve to exactly one shareholder identity.');
        return (int) $matches->first();
    }

    private function tenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) return null;
        $tenantId = mases_tenant_id();
        return $tenantId === null ? null : (int) $tenantId;
    }
}
