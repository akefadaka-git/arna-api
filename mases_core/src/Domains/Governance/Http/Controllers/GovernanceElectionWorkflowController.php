<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Governance\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Gate;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Models\\GovernanceElection;
use MaaleArna\\MASES\\Core\\Domains\\Governance\\Models\\GovernanceElectionNomination;

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

        return response()->json(['data' => ['submissions' => $submissions, 'candidates' => $candidates]]);
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

        return DB::transaction(function () use ($tenantId, $governanceElection, $nomination, $data, $request): JsonResponse {
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
