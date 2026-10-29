<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Validation\ValidationException;

final class GovernanceElectionController extends Controller
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $rows = DB::table('governance_elections')->where('tenant_id', $tenant)->whereNull('deleted_at')
            ->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 100));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $data = $request->validate([
            'governance_body_id' => ['required', 'integer', 'min:1'],
            'title_am' => ['nullable', 'string', 'max:200'],
            'title_en' => ['nullable', 'string', 'max:200'],
            'mandate' => ['required', 'string', 'max:255'],
            'nominations_open_at' => ['required', 'date'],
            'nominations_close_at' => ['required', 'date', 'after:nominations_open_at'],
            'voting_open_at' => ['required', 'date', 'after:nominations_close_at'],
            'voting_close_at' => ['required', 'date', 'after:voting_open_at'],
        ]);
        if (blank($data['title_am'] ?? null) && blank($data['title_en'] ?? null)) {
            throw ValidationException::withMessages(['title_am' => ['Provide an Amharic or English election title.']]);
        }
        $body = DB::table('governance_bodies')->where('tenant_id', $tenant)->where('id', $data['governance_body_id'])->where('is_active', true)->first();
        if (!$body) throw new NotFoundHttpException();
        $id = DB::transaction(function () use ($tenant, $body, $data, $request): int {
            $id = DB::table('governance_elections')->insertGetId([
                'tenant_id' => $tenant, 'governance_body_id' => $body->id,
                'title_am' => $data['title_am'] ?? null, 'title_en' => $data['title_en'] ?? null,
                'mandate' => $data['mandate'], 'status' => 'draft', 'created_by' => (int) $request->user()->getAuthIdentifier(),
                'nominations_open_at' => $data['nominations_open_at'], 'nominations_close_at' => $data['nominations_close_at'],
                'voting_open_at' => $data['voting_open_at'], 'voting_close_at' => $data['voting_close_at'],
                'rules_snapshot' => json_encode(['rules_source' => 'owner_approved_governance_policy', 'nomination' => 'both', 'quorum' => 'half_register', 'winner' => 'majority_runoff', 'final_tie' => 'rerun', 'challenge_window' => 'bylaw', 'certification' => 'independent', 'secret_ballot' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($tenant, $id, (int) $request->user()->getAuthIdentifier(), 'election.created', ['body_id' => $body->id]);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('id', $id)->first()], 201);
    }

    public function nominate(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'nominations_open' || now()->lt($election->nominations_open_at) || now()->gt($election->nominations_close_at)) {
            throw new ConflictHttpException('Nominations are not open.');
        }
        $data = $request->validate([
            'governance_membership_id' => ['required', 'integer', 'min:1'],
            'statement' => ['nullable', 'string', 'max:10000'],
            'candidate_consent_confirmed' => ['sometimes', 'boolean'],
        ]);
        $votingDate = Carbon::parse($election->voting_open_at)->toDateString();
        $membership = DB::table('governance_memberships')->where('tenant_id', $tenant)->where('governance_body_id', $election->governance_body_id)
            ->where('id', $data['governance_membership_id'])->where('status', 'active')->where('is_voting_member', true)
            ->whereDate('start_date', '<=', $votingDate)->where(function ($query) use ($votingDate): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $votingDate);
            })->first();
        if (!$membership) throw new NotFoundHttpException();
        $actorId = (int) $request->user()->getAuthIdentifier();
        $isSelfNomination = (int) $membership->user_id === $actorId;
        if (!$isSelfNomination) {
            $this->requirePermission($request, 'governance.election.manage');
            if (($data['candidate_consent_confirmed'] ?? false) !== true) {
                throw ValidationException::withMessages(['candidate_consent_confirmed' => ['Candidate consent must be confirmed for a third-party nomination.']]);
            }
        }
        $id = DB::transaction(function () use ($tenant, $electionId, $membership, $data, $request, $actorId, $isSelfNomination): int {
            $id = DB::table('governance_election_nominations')->insertGetId([
                'tenant_id' => $tenant, 'election_id' => $electionId, 'governance_membership_id' => $membership->id,
                'nomination_source' => $isSelfNomination ? 'self' : 'third_party',
                'nominated_by_user_id' => $actorId,
                'candidate_consent_at' => $isSelfNomination || (($data['candidate_consent_confirmed'] ?? false) === true) ? now() : null,
                'statement' => $data['statement'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($tenant, $electionId, $actorId, 'nomination.submitted', ['nomination_id' => $id, 'source' => $isSelfNomination ? 'self' : 'third_party']);
            return $id;
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_election_nominations')->where('id', $id)->first()], 201);
    }

    public function transition(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $data = $request->validate([
            'transition' => ['required', 'in:open_nominations,close_nominations,open_voting,close_voting,start_runoff,start_rerun,certify,cancel'],
            'round_closes_at' => ['required_if:transition,start_runoff,start_rerun', 'nullable', 'date', 'after:now'],
        ]);
        $transition = $data['transition'];
        $this->requirePermission($request, $transition === 'certify' ? 'governance.election.certify' : 'governance.election.manage');
        $election = $this->election($tenant, $electionId);

        if (in_array($transition, ['start_runoff', 'start_rerun'], true)) {
            $requiredStatus = $transition === 'start_runoff' ? 'runoff_required' : 'rerun_required';
            if ($election->status !== $requiredStatus) throw new ConflictHttpException('The requested round is not available from the current election state.');
            $nextRound = (int) $election->current_round + 1;
            $candidates = $transition === 'start_runoff'
                ? (json_decode((string) $election->result_summary_json, true)['runoff_candidate_ids'] ?? [])
                : DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('status', 'accepted')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (count($candidates) < 2 || ($transition === 'start_runoff' && count($candidates) !== 2)) {
                throw new ConflictHttpException('The round candidate set is incomplete; no vote can open.');
            }
            DB::transaction(function () use ($tenant, $electionId, $election, $nextRound, $candidates, $transition, $data, $request): void {
                $register = DB::table('governance_election_eligible_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)->orderBy('id')->get();
                if ($register->isEmpty()) throw new ConflictHttpException('The frozen eligible-voter register is empty.');
                DB::table('governance_election_rounds')->insert([
                    'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => $nextRound,
                    'round_type' => $transition === 'start_runoff' ? 'runoff' : 'rerun',
                    'status' => 'open', 'eligible_voter_count' => (int) $election->frozen_eligible_voter_count,
                    'opened_at' => now(), 'closes_at' => $data['round_closes_at'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($candidates as $nominationId) {
                    DB::table('governance_election_round_candidates')->insert([
                        'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => $nextRound,
                        'nomination_id' => (int) $nominationId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach ($register as $voter) {
                    DB::table('governance_election_round_voters')->insert([
                        'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => $nextRound,
                        'governance_membership_id' => $voter->governance_membership_id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update([
                    'status' => 'voting_open', 'current_round' => $nextRound, 'winner_nomination_id' => null,
                    'result_summary_json' => null, 'challenge_bylaw_reference' => null, 'challenge_window_ends_at' => null, 'updated_at' => now(),
                ]);
                $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.round_opened', [
                    'round_number' => $nextRound, 'round_type' => $transition === 'start_runoff' ? 'runoff' : 'rerun',
                    'candidate_ids' => array_values(array_map('intval', $candidates)), 'closes_at' => $data['round_closes_at'],
                ]);
            });
            return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->first()]);
        }

        if ($transition === 'certify') {
            if ($election->status !== 'voting_closed' || !$election->winner_nomination_id || !$election->frozen_eligible_voter_count) {
                throw new ConflictHttpException('Certification requires a completed, quorum-valid round with a majority winner.');
            }
            if (blank($election->challenge_bylaw_reference) || !$election->challenge_window_ends_at) {
                throw new ConflictHttpException('Certification is blocked until the approved bylaw/resolution clause and challenge-window deadline are recorded.');
            }
            if (now()->lt($election->challenge_window_ends_at)) throw new ConflictHttpException('The bylaw-defined challenge/appeal window has not expired.');
            if (DB::table('governance_election_challenges')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('status', 'pending')->exists()) {
                throw new ConflictHttpException('Certification is blocked while a challenge or appeal remains unresolved.');
            }
            $administrativeActors = DB::table('governance_election_audit_events')->where('tenant_id', $tenant)->where('election_id', $electionId)
                ->whereIn('event_type', ['election.created', 'election.transitioned', 'election.round_opened', 'election.round_closed', 'election.challenge_window_recorded', 'nomination.reviewed', 'voter.registered'])
                ->whereNotNull('actor_id')->pluck('actor_id')->map(fn ($id): int => (int) $id)->unique()->all();
            $certifierId = (int) $request->user()->getAuthIdentifier();
            if ((int) ($election->created_by ?? 0) === $certifierId || in_array($certifierId, $administrativeActors, true)) {
                throw new AccessDeniedHttpException('The certifier must be independent of election administration.');
            }
            $update = ['status' => 'certified', 'certified_by' => $certifierId, 'certified_at' => now(), 'updated_at' => now()];
            DB::transaction(function () use ($tenant, $electionId, $update, $request, $election): void {
                DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update($update);
                $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.certified', [
                    'winner_nomination_id' => (int) $election->winner_nomination_id,
                    'frozen_eligible_voter_count' => (int) $election->frozen_eligible_voter_count,
                    'result_summary' => json_decode((string) $election->result_summary_json, true),
                    'challenge_bylaw_reference' => $election->challenge_bylaw_reference,
                    'challenge_window_ends_at' => $election->challenge_window_ends_at,
                ]);
            });
            return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->first()]);
        }

        $map = [
            'open_nominations' => ['draft', 'nominations_open'],
            'close_nominations' => ['nominations_open', 'nominations_closed'],
            'open_voting' => ['nominations_closed', 'voting_open'],
            'close_voting' => ['voting_open', 'voting_closed'],
            'cancel' => [['draft', 'nominations_open', 'nominations_closed', 'voting_open'], 'cancelled'],
        ];
        [$expected, $next] = $map[$transition];
        if (is_array($expected) ? !in_array($election->status, $expected, true) : $election->status !== $expected) {
            throw new ConflictHttpException('Election lifecycle transition is not allowed from the current status.');
        }
        if ($transition === 'open_nominations' && (now()->lt($election->nominations_open_at) || now()->gt($election->nominations_close_at))) throw new ConflictHttpException('Nomination window is not currently open.');
        if ($transition === 'close_nominations' && now()->lt($election->nominations_close_at)) throw new ConflictHttpException('Nomination window is still open.');
        if ($transition === 'open_voting') {
            if (now()->lt($election->voting_open_at) || now()->gt($election->voting_close_at)) throw new ConflictHttpException('Voting window is not currently open.');
            $eligibleCount = DB::table('governance_election_eligible_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)->count();
            $acceptedIds = DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('status', 'accepted')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($eligibleCount < 1) throw new ConflictHttpException('Voting cannot open with an empty eligible-voter register.');
            if (count($acceptedIds) < 2) throw new ConflictHttpException('Majority/runoff elections require at least two accepted candidates.');
            DB::transaction(function () use ($tenant, $electionId, $eligibleCount, $acceptedIds, $election, $request): void {
                DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update([
                    'frozen_eligible_voter_count' => $eligibleCount, 'current_round' => 1, 'winner_nomination_id' => null,
                    'result_summary_json' => null, 'updated_at' => now(),
                ]);
                DB::table('governance_election_rounds')->insert([
                    'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => 1, 'round_type' => 'initial',
                    'status' => 'open', 'eligible_voter_count' => $eligibleCount, 'opened_at' => now(),
                    'closes_at' => $election->voting_close_at, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($acceptedIds as $nominationId) {
                    DB::table('governance_election_round_candidates')->insert([
                        'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => 1,
                        'nomination_id' => $nominationId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach (DB::table('governance_election_eligible_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)->orderBy('id')->get() as $voter) {
                    DB::table('governance_election_round_voters')->insert([
                        'tenant_id' => $tenant, 'election_id' => $electionId, 'round_number' => 1,
                        'governance_membership_id' => $voter->governance_membership_id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.round_opened', ['round_number' => 1, 'round_type' => 'initial', 'eligible_voter_count' => $eligibleCount]);
            });
        }
        if ($transition === 'close_voting') {
            $round = DB::table('governance_election_rounds')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('round_number', $election->current_round)->first();
            if (!$round || $round->status !== 'open' || now()->lt($round->closes_at)) throw new ConflictHttpException('The current round is not due to close.');
            $castCount = DB::table('governance_election_round_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('round_number', $election->current_round)->whereNotNull('cast_at')->count();
            $validCount = DB::table('governance_election_ballots')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('round_number', $election->current_round)->count();
            $quorumMet = $castCount >= (int) ceil(((int) $round->eligible_voter_count) / 2);
            $tallies = DB::table('governance_election_round_candidates as rc')
                ->leftJoin('governance_election_ballots as b', function ($join): void {
                    $join->on('b.tenant_id', '=', 'rc.tenant_id')->on('b.election_id', '=', 'rc.election_id')
                        ->on('b.round_number', '=', 'rc.round_number')->on('b.nomination_id', '=', 'rc.nomination_id');
                })
                ->where('rc.tenant_id', $tenant)->where('rc.election_id', $electionId)->where('rc.round_number', $election->current_round)
                ->groupBy('rc.nomination_id')->select('rc.nomination_id', DB::raw('COUNT(b.id) as vote_count'))->orderByDesc('vote_count')->orderBy('rc.nomination_id')->get();
            $winner = null;
            $runoffCandidates = [];
            $nextStatus = 'quorum_failed';
            $roundStatus = 'quorum_failed';
            $reason = 'quorum_not_met';
            if ($quorumMet && $validCount > 0) {
                $majority = $tallies->first(fn ($row): bool => (int) $row->vote_count > ($validCount / 2));
                if ($majority) {
                    $winner = (int) $majority->nomination_id;
                    $nextStatus = 'voting_closed';
                    $roundStatus = 'majority_reached';
                    $reason = 'majority_reached';
                } else {
                    $rows = $tallies->values();
                    $isRunoff = $round->round_type === 'initial';
                    $topTwo = $rows->take(2);
                    $cutoffTie = $rows->count() > 2 && (int) $rows[1]->vote_count === (int) $rows[2]->vote_count;
                    $topTie = $rows->count() > 1 && (int) $rows[0]->vote_count === (int) $rows[1]->vote_count;
                    if ($isRunoff && !$cutoffTie) {
                        $runoffCandidates = $topTwo->pluck('nomination_id')->map(fn ($id): int => (int) $id)->all();
                        $nextStatus = 'runoff_required';
                        $roundStatus = 'runoff_required';
                        $reason = 'majority_not_reached';
                    } else {
                        $nextStatus = 'rerun_required';
                        $roundStatus = 'tie_or_no_majority';
                        $reason = $cutoffTie ? 'runoff_cutoff_tie' : ($topTie ? 'final_tie' : 'majority_not_reached_after_runoff');
                    }
                }
            }
            $summary = [
                'round_number' => (int) $election->current_round, 'round_type' => $round->round_type,
                'eligible_voter_count' => (int) $round->eligible_voter_count, 'ballots_cast_count' => $castCount,
                'valid_ballot_count' => $validCount, 'quorum_required' => (int) ceil(((int) $round->eligible_voter_count) / 2),
                'quorum_met' => $quorumMet, 'majority_required' => (int) floor($validCount / 2) + 1,
                'winner_nomination_id' => $winner, 'runoff_candidate_ids' => $runoffCandidates, 'disposition' => $reason,
                'tallies' => $tallies->map(fn ($row): array => ['nomination_id' => (int) $row->nomination_id, 'vote_count' => (int) $row->vote_count])->all(),
            ];
            DB::transaction(function () use ($tenant, $electionId, $election, $round, $castCount, $validCount, $quorumMet, $summary, $winner, $nextStatus, $roundStatus, $request): void {
                DB::table('governance_election_rounds')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('round_number', $election->current_round)->update([
                    'status' => $roundStatus, 'ballots_cast_count' => $castCount, 'valid_ballot_count' => $validCount,
                    'winner_nomination_id' => $winner, 'closed_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update([
                    'status' => $nextStatus, 'winner_nomination_id' => $winner, 'result_summary_json' => json_encode($summary, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.round_closed', $summary);
            });
            return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->first()]);
        }

        if ($transition === 'close_nominations' && now()->lt($election->nominations_close_at)) throw new ConflictHttpException('Nomination window is still open.');
        $update = ['status' => $next, 'updated_at' => now()];
        DB::transaction(function () use ($tenant, $electionId, $update, $request, $election, $next, $transition): void {
            DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update($update);
            $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.transitioned', ['from' => $election->status, 'to' => $next, 'transition' => $transition]);
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->first()]);
    }

    public function reviewNomination(Request $request, int $electionId, int $nominationId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        if (!in_array($election->status, ['nominations_open', 'nominations_closed'], true)) {
            throw new ConflictHttpException('Nomination review is only available before voting opens.');
        }
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected']]);
        $nomination = DB::table('governance_election_nominations')->where('tenant_id', $tenant)
            ->where('election_id', $electionId)->where('id', $nominationId)->where('status', 'pending')->first();
        if (!$nomination) throw new NotFoundHttpException();
        DB::transaction(function () use ($nominationId, $data, $request, $tenant, $electionId): void {
            DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('id', $nominationId)->update([
                'status' => $data['status'], 'reviewed_by' => (int) $request->user()->getAuthIdentifier(),
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'nomination.reviewed', ['nomination_id' => $nominationId, 'status' => $data['status']]);
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_election_nominations')->where('id', $nominationId)->first()]);
    }

    public function registerVoter(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        if (!in_array($election->status, ['draft', 'nominations_open', 'nominations_closed'], true)) throw new ConflictHttpException('Voter register is locked after voting opens.');
        $data = $request->validate(['governance_membership_id' => ['required', 'integer', 'min:1']]);
        $votingDate = Carbon::parse($election->voting_open_at)->toDateString();
        $membership = DB::table('governance_memberships')->where('tenant_id', $tenant)->where('governance_body_id', $election->governance_body_id)
            ->where('id', $data['governance_membership_id'])->where('status', 'active')->where('is_voting_member', true)
            ->whereDate('start_date', '<=', $votingDate)->where(function ($query) use ($votingDate): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $votingDate);
            })->first();
        if (!$membership) throw new NotFoundHttpException();
        DB::transaction(function () use ($tenant, $electionId, $membership, $request): void {
            $inserted = DB::table('governance_election_eligible_voters')->insertOrIgnore([
                'tenant_id' => $tenant, 'election_id' => $electionId, 'governance_membership_id' => $membership->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1) {
                $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'voter.registered', ['membership_id' => $membership->id]);
            }
        });
        return response()->json(['success' => true, 'data' => ['registered' => true]]);
    }

    public function castBallot(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.vote');
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'voting_open') throw new ConflictHttpException('Voting is not open.');
        $round = DB::table('governance_election_rounds')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('round_number', $election->current_round)->where('status', 'open')->first();
        if (!$round || now()->gt($round->closes_at)) throw new ConflictHttpException('The current round is outside its voting window.');
        $data = $request->validate(['governance_membership_id' => ['required', 'integer', 'min:1'], 'nomination_id' => ['required', 'integer', 'min:1']]);
        $actorId = (int) $request->user()->getAuthIdentifier();
        $today = now()->toDateString();
        $membership = DB::table('governance_memberships')->where('tenant_id', $tenant)->where('governance_body_id', $election->governance_body_id)
            ->where('id', $data['governance_membership_id'])->where('user_id', $actorId)->where('status', 'active')->where('is_voting_member', true)
            ->whereDate('start_date', '<=', $today)->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })->first();
        if (!$membership) throw new AccessDeniedHttpException('Ballot must be cast for the authenticated member.');
        $receipt = bin2hex(random_bytes(32));
        DB::transaction(function () use ($tenant, $electionId, $election, $round, $data, $receipt, $actorId): void {
            $voter = DB::table('governance_election_round_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)
                ->where('round_number', $election->current_round)->where('governance_membership_id', $data['governance_membership_id'])->lockForUpdate()->first();
            if (!$voter || $voter->cast_at !== null) throw new ConflictHttpException('Voter is not eligible for this round or has already cast a ballot.');
            $candidate = DB::table('governance_election_round_candidates')->where('tenant_id', $tenant)->where('election_id', $electionId)
                ->where('round_number', $election->current_round)->where('nomination_id', $data['nomination_id'])->exists();
            if (!$candidate) throw new ConflictHttpException('Candidate is not eligible in the current round.');
            DB::table('governance_election_ballots')->insert([
                'tenant_id' => $tenant, 'election_id' => $electionId, 'nomination_id' => $data['nomination_id'],
                'round_number' => $election->current_round, 'ballot_receipt_hash' => hash('sha256', $receipt), 'cast_at' => now(),
            ]);
            DB::table('governance_election_round_voters')->where('id', $voter->id)->update(['cast_at' => now(), 'updated_at' => now()]);
            $this->audit($tenant, $electionId, $actorId, 'ballot.cast', ['round_number' => (int) $election->current_round, 'receipt_hash' => hash('sha256', $receipt)]);
        });
        return response()->json(['success' => true, 'data' => ['receipt' => $receipt]]);
    }

    public function voters(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $this->election($tenant, $electionId);
        $election = $this->election($tenant, $electionId);
        $rows = DB::table('governance_election_eligible_voters as v')
            ->join('governance_memberships as m', function ($join): void {
                $join->on('m.id', '=', 'v.governance_membership_id')->on('m.tenant_id', '=', 'v.tenant_id');
            })
            ->leftJoin('governance_election_round_voters as rv', function ($join) use ($election): void {
                $join->on('rv.tenant_id', '=', 'v.tenant_id')->on('rv.election_id', '=', 'v.election_id')
                    ->on('rv.governance_membership_id', '=', 'v.governance_membership_id')->where('rv.round_number', '=', (int) $election->current_round);
            })
            ->where('v.tenant_id', $tenant)->where('v.election_id', $electionId)
            ->select('v.id', 'v.governance_membership_id', 'rv.cast_at', 'm.user_id', 'm.member_name_am', 'm.member_name_en')
            ->orderBy('v.id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function nominations(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $this->election($tenant, $electionId);
        $rows = DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)
            ->orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function auditEvents(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $this->election($tenant, $electionId);
        $rows = DB::table('governance_election_audit_events')->where('tenant_id', $tenant)->where('election_id', $electionId)
            ->orderByDesc('occurred_at')->limit(200)->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function results(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $election = $this->election($tenant, $electionId);
        if (!in_array($election->status, ['voting_closed', 'certified', 'runoff_required', 'rerun_required', 'quorum_failed'], true)) throw new ConflictHttpException('Results are withheld until the current round closes.');
        $roundNumber = (int) ($election->current_round ?? 1);
        $rows = DB::table('governance_election_round_candidates as rc')
            ->join('governance_election_nominations as n', function ($join): void {
                $join->on('n.id', '=', 'rc.nomination_id')->on('n.election_id', '=', 'rc.election_id')->on('n.tenant_id', '=', 'rc.tenant_id');
            })->leftJoin('governance_election_ballots as b', function ($join): void {
                $join->on('b.nomination_id', '=', 'rc.nomination_id')->on('b.election_id', '=', 'rc.election_id')
                    ->on('b.tenant_id', '=', 'rc.tenant_id')->on('b.round_number', '=', 'rc.round_number');
            })->where('rc.tenant_id', $tenant)->where('rc.election_id', $electionId)->where('rc.round_number', $roundNumber)
            ->groupBy('n.id', 'n.governance_membership_id')->select('n.id as nomination_id', 'n.governance_membership_id', DB::raw('COUNT(b.id) as vote_count'))->orderByDesc('vote_count')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function setChallengeWindow(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'voting_closed') {
            throw new ConflictHttpException('The challenge/appeal window can only be set after a round closes.');
        }
        $data = $request->validate([
            'bylaw_reference' => ['required', 'string', 'max:255'],
            'window_ends_at' => ['required', 'date', 'after:now'],
        ]);
        DB::transaction(function () use ($tenant, $electionId, $data, $request): void {
            DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update([
                'challenge_bylaw_reference' => trim($data['bylaw_reference']),
                'challenge_window_ends_at' => $data['window_ends_at'],
                'updated_at' => now(),
            ]);
            $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.challenge_window_recorded', [
                'bylaw_reference' => trim($data['bylaw_reference']), 'window_ends_at' => $data['window_ends_at'],
            ]);
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->first()]);
    }

    public function submitChallenge(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.challenge');
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'voting_closed') throw new ConflictHttpException('Challenges can be filed only against a final majority result before certification.');
        if (blank($election->challenge_bylaw_reference) || !$election->challenge_window_ends_at) {
            throw new ConflictHttpException('The applicable bylaw/resolution clause and filing deadline are not recorded.');
        }
        if (now()->gt($election->challenge_window_ends_at)) throw new ConflictHttpException('The bylaw-defined challenge/appeal window has expired.');
        $data = $request->validate([
            'challenge_type' => ['required', 'in:challenge,appeal'],
            'reason' => ['required', 'string', 'min:10', 'max:10000'],
            'evidence_references' => ['sometimes', 'array', 'max:20'],
            'evidence_references.*' => ['string', 'max:500'],
        ]);
        $id = DB::table('governance_election_challenges')->insertGetId([
            'tenant_id' => $tenant, 'election_id' => $electionId, 'submitted_by_user_id' => (int) $request->user()->getAuthIdentifier(),
            'challenge_type' => $data['challenge_type'], 'reason' => trim($data['reason']),
            'evidence_references' => json_encode($data['evidence_references'] ?? [], JSON_THROW_ON_ERROR),
            'status' => 'pending', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.challenge_submitted', ['challenge_id' => $id, 'challenge_type' => $data['challenge_type']]);
        return response()->json(['success' => true, 'data' => DB::table('governance_election_challenges')->where('id', $id)->first()], 201);
    }

    public function resolveChallenge(Request $request, int $electionId, int $challengeId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.challenge.review');
        $this->election($tenant, $electionId);
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'decision_note' => ['required', 'string', 'min:5', 'max:5000']]);
        $challenge = DB::table('governance_election_challenges')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('id', $challengeId)->where('status', 'pending')->first();
        if (!$challenge) throw new NotFoundHttpException();
        DB::transaction(function () use ($tenant, $electionId, $challengeId, $data, $request): void {
            DB::table('governance_election_challenges')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('id', $challengeId)->update([
                'status' => $data['status'], 'decision_note' => trim($data['decision_note']),
                'decided_by_user_id' => (int) $request->user()->getAuthIdentifier(), 'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($tenant, $electionId, (int) $request->user()->getAuthIdentifier(), 'election.challenge_decided', ['challenge_id' => $challengeId, 'status' => $data['status']]);
        });
        return response()->json(['success' => true, 'data' => DB::table('governance_election_challenges')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('id', $challengeId)->first()]);
    }

    public function challenges(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $this->election($tenant, $electionId);
        $rows = DB::table('governance_election_challenges')->where('tenant_id', $tenant)->where('election_id', $electionId)->orderBy('id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function audit(int $tenantId, int $electionId, ?int $actorId, string $event, array $metadata = []): void
    {
        DB::table('governance_election_audit_events')->insert([
            'tenant_id' => $tenantId, 'election_id' => $electionId, 'actor_id' => $actorId,
            'event_type' => $event, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    private function election(int $tenantId, int $id): object
    {
        $row = DB::table('governance_elections')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('deleted_at')->first();
        if (!$row) throw new NotFoundHttpException();
        return $row;
    }

    private function tenantId(): int
    {
        $tenant = function_exists('mases_tenant_id') ? mases_tenant_id() : null;
        if ($tenant === null || (int) $tenant < 1) throw new NotFoundHttpException();
        return (int) $tenant;
    }

    private function requirePermission(Request $request, string $permission): void
    {
        $tenant = $this->tenantId();
        if (!$this->authorization->hasPermission(permissionSlug: $permission, user: $request->user(), tenantId: $tenant)) {
            throw new AccessDeniedHttpException();
        }
    }
}
