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
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionLifecycleService;

final class GovernanceElectionWorkflowController extends Controller
{
    public function verifyElectionAuthority(Request $request, GovernanceElection $governanceElection, GovernanceElectionLifecycleService $lifecycle): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate(['authority_reference' => ['required', 'string', 'min:3', 'max:160']]);
        $election = $lifecycle->verifyAuthority($tenantId, (int) $governanceElection->id,
            $data['authority_reference'], (int) $request->user()->getAuthIdentifier());
        return response()->json(['data' => $election]);
    }

    public function openElectionNominations(Request $request, GovernanceElection $governanceElection, GovernanceElectionLifecycleService $lifecycle): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'rule_version' => ['required', 'string', 'min:1', 'max:80'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
        ]);
        $election = $lifecycle->openNominations($tenantId, (int) $governanceElection->id,
            $data['rule_version'], $data['opens_at'], $data['closes_at']);
        return response()->json(['data' => $election]);
    }

    public function openPhysicalElectionVoting(Request $request, GovernanceElection $governanceElection, GovernanceElectionLifecycleService $lifecycle): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
        ]);
        $election = $lifecycle->openPhysicalVoting($tenantId, (int) $governanceElection->id,
            $data['opens_at'], $data['closes_at']);
        return response()->json(['data' => $election]);
    }

    public function closeElectionVoting(Request $request, GovernanceElection $governanceElection, GovernanceElectionLifecycleService $lifecycle): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $election = $lifecycle->closeVoting($tenantId, (int) $governanceElection->id);
        return response()->json(['data' => $election]);
    }

    public function submitNomination(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        abort_unless($governanceElection->status === 'nominating', 409, 'Nomination period is not open.');

        $data = $request->validate([
            'candidate_person_id' => ['required', 'integer', 'min:1'],
            'self_nomination' => ['sometimes', 'boolean'],
            'statement' => ['nullable', 'string', 'max:10000'],
        ]);

        $self = (bool) ($data['self_nomination'] ?? false);
        $candidateShareholders = DB::table('shareholders')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $data['candidate_person_id'])
            ->where('shareholder_type', 'individual')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->limit(2)
            ->get(['id', 'person_id']);
        abort_unless($candidateShareholders->count() === 1, 422, 'Candidate must resolve to exactly one active natural-person shareholder in this tenant.');
        if ($self) {
            $nominator = DB::table('shareholders')->where('tenant_id', $tenantId)->where('id', $shareholderId)->first(['person_id']);
            abort_unless($nominator !== null && (int) ($nominator->person_id ?? 0) === (int) $data['candidate_person_id'], 422, 'Self-nomination must identify the authenticated shareholder person.');
        }
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
        $person = DB::table('people')->where('id', $data['person_id'])->whereNull('deleted_at')->first(['id', 'user_id']);
        abort_unless($person !== null, 422, 'Committee member must resolve to an active canonical Person.');
        abort_unless((int) ($person->user_id ?? 0) === (int) $data['user_id'], 422, 'Committee user must be the account linked to the canonical Person.');
        $tenantAccess = DB::table('person_tenant_accesses')->where('person_id', $data['person_id'])
            ->where('tenant_id', $tenantId)->where('status', 'active')->exists();
        abort_unless($tenantAccess, 422, 'Committee member must have active Person tenant access.');

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

    public function decideNominationSubmission(Request $request, GovernanceElection $governanceElection, int $submission): JsonResponse
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
        ]);

        return DB::transaction(function () use ($tenantId, $governanceElection, $submission, $data, $request, $committeeMember): JsonResponse {
            $item = DB::table('governance_election_nomination_submissions')
                ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                ->lockForUpdate()->find($submission);
            abort_if($item === null || $item->status !== 'submitted', 404, 'Open nomination submission not found.');
            $members = DB::table('governance_election_committee_members')
                ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                ->where('status', 'active')->whereNull('recused_at')->where('member_role', 'committee_member')->count();
            abort_if($members < 3, 409, 'Committee quorum is not established.');
            abort_if($committeeMember->recused_at !== null, 403, 'A recused committee member cannot decide this submission.');
            $candidate = DB::table('shareholders')->where('tenant_id', $tenantId)
                ->where('person_id', $item->candidate_person_id)->where('shareholder_type', 'individual')->where('is_active', true)
                ->whereNull('deleted_at')->first(['id']);
            abort_if($candidate === null, 409, 'Candidate shareholder identity is no longer eligible.');
            abort_if((int) $committeeMember->person_id === (int) $item->candidate_person_id, 403, 'A candidate cannot decide their own nomination.');

            if ($data['decision'] === 'approved') {
                $existing = GovernanceElectionNomination::query()
                    ->where('tenant_id', $tenantId)->where('election_id', $governanceElection->id)
                    ->where('shareholder_id', $candidate->id)->first();
                abort_if($existing !== null, 409, 'This shareholder already has a nomination record for the election.');
                $committeeNomination = GovernanceElectionNomination::query()->create([
                    'tenant_id' => $tenantId, 'election_id' => $governanceElection->id,
                    'shareholder_id' => $candidate->id, 'status' => 'approved',
                    'candidate_person_id' => $item->candidate_person_id,
                    'nomination_source' => $item->self_nomination ? 'self' : 'shareholder',
                    'nominated_by_shareholder_id' => $item->nominated_by_shareholder_id,
                    'nomination_statement' => $item->statement,
                    'committee_status' => 'approved', 'committee_reason' => $data['reason'],
                    'committee_decided_at' => now(), 'committee_decided_by' => (int) $request->user()->getAuthIdentifier(),
                    'final_ballot_approved' => true, 'final_ballot_approved_at' => now(),
                    'reviewed_by' => (int) $request->user()->getAuthIdentifier(), 'reviewed_at' => now(),
                ]);
            }

            DB::table('governance_election_nomination_submissions')->where('id', $item->id)
                ->where('tenant_id', $tenantId)->update([
                    'status' => $data['decision'],
                    'committee_candidate_id' => $data['decision'] === 'approved' ? $committeeNomination->id : null,
                    'updated_at' => now(),
                ]);
            DB::table('governance_election_committee_decisions')->insert([
                'tenant_id' => $tenantId, 'election_id' => $governanceElection->id,
                'nomination_id' => null, 'decision_type' => 'nomination_submission',
                'decision' => $data['decision'], 'reason' => $data['reason'],
                'decided_by' => (int) $request->user()->getAuthIdentifier(),
                'meeting_reference' => $data['meeting_reference'] ?? null,
                'evidence_reference' => $data['evidence_reference'] ?? null,
                'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            return response()->json(['data' => ['submission_id' => $item->id, 'decision' => $data['decision']]]);
        });
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
                ->where('status', 'active')->whereNull('recused_at')->where('member_role', 'committee_member')->count();
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

    public function submitObjection(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        $data = $request->validate([
            'nomination_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['required', 'in:eligibility,nomination,procedure,ballot,counting,other'],
            'grounds' => ['required', 'string', 'min:20', 'max:10000'],
            'evidence_reference' => ['nullable', 'string', 'max:160'],
        ]);
        if (!empty($data['nomination_id'])) {
            GovernanceElectionNomination::query()->where('tenant_id', $tenantId)
                ->where('election_id', $governanceElection->id)->findOrFail($data['nomination_id']);
        }
        $id = DB::table('governance_election_objections')->insertGetId([
            'tenant_id' => $tenantId, 'election_id' => $governanceElection->id,
            'nomination_id' => $data['nomination_id'] ?? null,
            'submitted_by_shareholder_id' => $shareholderId, 'category' => $data['category'],
            'grounds' => $data['grounds'], 'evidence_reference' => $data['evidence_reference'] ?? null,
            'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => ['objection_id' => $id, 'status' => 'submitted']], 201);
    }

    public function submitAppeal(Request $request, GovernanceElection $governanceElection, int $objection): JsonResponse
    {
        Gate::authorize('view', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $shareholderId = $this->authenticatedShareholderId($tenantId);
        $data = $request->validate([
            'grounds' => ['required', 'string', 'min:20', 'max:10000'],
            'evidence_reference' => ['nullable', 'string', 'max:160'],
        ]);
        $record = DB::table('governance_election_objections')->where('tenant_id', $tenantId)
            ->where('election_id', $governanceElection->id)->where('id', $objection)->first();
        abort_if($record === null || !in_array($record->status, ['upheld', 'dismissed'], true), 409,
            'An appeal may be submitted only after a reasoned objection decision.');
        $id = DB::table('governance_election_appeals')->insertGetId([
            'tenant_id' => $tenantId, 'election_id' => $governanceElection->id,
            'objection_id' => $objection, 'appellant_shareholder_id' => $shareholderId,
            'grounds' => $data['grounds'], 'evidence_reference' => $data['evidence_reference'] ?? null,
            'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['data' => ['appeal_id' => $id, 'status' => 'submitted']], 201);
    }

    public function certifyElection(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate([
            'tally_id' => ['required', 'integer', 'min:1'],
            'certifying_authority' => ['required', 'string', 'min:3', 'max:160'],
            'certification_reference' => ['required', 'string', 'min:3', 'max:160'],
            'evidence_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        return DB::transaction(function () use ($tenantId, $governanceElection, $data, $request): JsonResponse {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($governanceElection->id);
            abort_unless($election->status === 'counting', 409, 'Election must be in counting status before independent certification.');
            $tally = DB::table('governance_election_tallies')->where('tenant_id', $tenantId)
                ->where('election_id', $election->id)->where('id', $data['tally_id'])->first();
            abort_if($tally === null, 404, 'Election tally not found.');
            $actorId = (int) $request->user()->getAuthIdentifier();
            abort_if((int) $tally->prepared_by === $actorId, 403, 'The tally preparer cannot independently certify the tally.');
            abort_unless(hash_equals(strtolower((string) $tally->evidence_sha256), strtolower($data['evidence_sha256'])), 422, 'Certification hash must match the frozen tally evidence hash.');
            $openObjections = DB::table('governance_election_objections')->where('tenant_id', $tenantId)
                ->where('election_id', $election->id)->whereNotIn('status', ['dismissed', 'upheld', 'withdrawn'])->exists();
            $openAppeals = DB::table('governance_election_appeals')->where('tenant_id', $tenantId)
                ->where('election_id', $election->id)->whereNotIn('status', ['dismissed', 'upheld', 'withdrawn'])->exists();
            abort_if($openObjections || $openAppeals, 409, 'All objections and appeals must receive a recorded outcome before certification.');
            $id = DB::table('governance_election_certifications')->insertGetId([
                'tenant_id' => $tenantId, 'election_id' => $election->id, 'tally_id' => $tally->id,
                'certifying_authority' => $data['certifying_authority'],
                'certification_reference' => $data['certification_reference'],
                'evidence_sha256' => strtolower($data['evidence_sha256']),
                'certified_by' => $actorId, 'certified_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $election->forceFill(['status' => 'certified'])->save();
            return response()->json(['data' => ['certification_id' => $id, 'status' => 'certified']], 201);
        });
    }

    public function ratifyElection(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        Gate::authorize('update', $governanceElection);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        $data = $request->validate(['ratification_reference' => ['required', 'string', 'min:3', 'max:160']]);
        return DB::transaction(function () use ($tenantId, $governanceElection, $data, $request): JsonResponse {
            $election = GovernanceElection::query()->where('tenant_id', $tenantId)
                ->lockForUpdate()->findOrFail($governanceElection->id);
            abort_unless($election->status === 'certified', 409, 'Only an independently certified election may be ratified.');
            $certification = DB::table('governance_election_certifications')->where('tenant_id', $tenantId)
                ->where('election_id', $election->id)->lockForUpdate()->first();
            abort_if($certification === null, 409, 'Independent certification evidence is required.');
            $actorId = (int) $request->user()->getAuthIdentifier();
            abort_if((int) $certification->certified_by === $actorId, 403, 'The certifier cannot ratify the same election.');
            DB::table('governance_election_certifications')->where('id', $certification->id)
                ->where('tenant_id', $tenantId)->update([
                    'ratified_by' => $actorId, 'ratified_at' => now(),
                    'ratification_reference' => $data['ratification_reference'],
                    'updated_at' => now(),
                ]);
            $election->forceFill(['status' => 'ratified'])->save();
            return response()->json(['data' => ['status' => 'ratified', 'ratified_at' => now()]]);
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
