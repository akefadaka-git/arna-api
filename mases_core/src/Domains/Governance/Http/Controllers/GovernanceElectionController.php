<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $id = DB::table('governance_elections')->insertGetId([
            'tenant_id' => $tenant, 'governance_body_id' => $body->id,
            'title_am' => $data['title_am'] ?? null, 'title_en' => $data['title_en'] ?? null,
            'mandate' => $data['mandate'], 'status' => 'draft',
            'nominations_open_at' => $data['nominations_open_at'], 'nominations_close_at' => $data['nominations_close_at'],
            'voting_open_at' => $data['voting_open_at'], 'voting_close_at' => $data['voting_close_at'],
            'rules_snapshot' => json_encode(['rules_source' => 'owner_approved_governance_policy_required', 'secret_ballot' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('id', $id)->first()], 201);
    }

    public function nominate(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'nominations_open' || now()->lt($election->nominations_open_at) || now()->gt($election->nominations_close_at)) {
            throw new ConflictHttpException('Nominations are not open.');
        }
        $data = $request->validate(['governance_membership_id' => ['required', 'integer', 'min:1'], 'statement' => ['nullable', 'string', 'max:10000']]);
        $membership = DB::table('governance_memberships')->where('tenant_id', $tenant)->where('governance_body_id', $election->governance_body_id)
            ->where('id', $data['governance_membership_id'])->where('status', 'active')->first();
        if (!$membership) throw new NotFoundHttpException();
        $id = DB::table('governance_election_nominations')->insertGetId([
            'tenant_id' => $tenant, 'election_id' => $electionId, 'governance_membership_id' => $membership->id,
            'statement' => $data['statement'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => DB::table('governance_election_nominations')->where('id', $id)->first()], 201);
    }

    public function transition(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        $data = $request->validate(['transition' => ['required', 'in:open_nominations,close_nominations,open_voting,close_voting,certify,cancel']]);
        $map = [
            'open_nominations' => ['draft', 'nominations_open'],
            'close_nominations' => ['nominations_open', 'nominations_closed'],
            'open_voting' => ['nominations_closed', 'voting_open'],
            'close_voting' => ['voting_open', 'voting_closed'],
            'certify' => ['voting_closed', 'certified'],
            'cancel' => [['draft', 'nominations_open', 'nominations_closed', 'voting_open'], 'cancelled'],
        ];
        [$expected, $next] = $map[$data['transition']];
        if (is_array($expected) ? !in_array($election->status, $expected, true) : $election->status !== $expected) {
            throw new ConflictHttpException('Election lifecycle transition is not allowed from the current status.');
        }
        if ($data['transition'] === 'open_nominations' && now()->lt($election->nominations_open_at)) throw new ConflictHttpException('Nomination window has not started.');
        if ($data['transition'] === 'close_nominations' && now()->lt($election->nominations_close_at)) throw new ConflictHttpException('Nomination window is still open.');
        if ($data['transition'] === 'open_voting' && now()->lt($election->voting_open_at)) throw new ConflictHttpException('Voting window has not started.');
        if ($data['transition'] === 'close_voting' && now()->lt($election->voting_close_at)) throw new ConflictHttpException('Voting window is still open.');
        if ($data['transition'] === 'certify') {
            $accepted = DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)->where('status', 'accepted')->count();
            if ($accepted < 1) throw new ConflictHttpException('Certification requires at least one accepted nomination.');
        }
        $update = ['status' => $next, 'updated_at' => now()];
        if ($next === 'certified') {
            $update['certified_by'] = (int) $request->user()->getAuthIdentifier();
            $update['certified_at'] = now();
        }
        DB::table('governance_elections')->where('tenant_id', $tenant)->where('id', $electionId)->update($update);
        return response()->json(['success' => true, 'data' => DB::table('governance_elections')->where('id', $electionId)->first()]);
    }

    public function registerVoter(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.manage');
        $election = $this->election($tenant, $electionId);
        if (!in_array($election->status, ['draft', 'nominations_open', 'nominations_closed'], true)) throw new ConflictHttpException('Voter register is locked after voting opens.');
        $data = $request->validate(['governance_membership_id' => ['required', 'integer', 'min:1']]);
        $membership = DB::table('governance_memberships')->where('tenant_id', $tenant)->where('governance_body_id', $election->governance_body_id)
            ->where('id', $data['governance_membership_id'])->where('status', 'active')->where('is_voting_member', true)->first();
        if (!$membership) throw new NotFoundHttpException();
        DB::table('governance_election_eligible_voters')->insertOrIgnore([
            'tenant_id' => $tenant, 'election_id' => $electionId, 'governance_membership_id' => $membership->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => ['registered' => true]]);
    }

    public function castBallot(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.vote');
        $election = $this->election($tenant, $electionId);
        if ($election->status !== 'voting_open' || now()->lt($election->voting_open_at) || now()->gt($election->voting_close_at)) throw new ConflictHttpException('Voting is not open.');
        $data = $request->validate(['governance_membership_id' => ['required', 'integer', 'min:1'], 'nomination_id' => ['required', 'integer', 'min:1']]);
        $receipt = bin2hex(random_bytes(32));
        DB::transaction(function () use ($tenant, $electionId, $election, $data, $receipt): void {
            $voter = DB::table('governance_election_eligible_voters')->where('tenant_id', $tenant)->where('election_id', $electionId)
                ->where('governance_membership_id', $data['governance_membership_id'])->lockForUpdate()->first();
            if (!$voter || $voter->cast_at !== null) throw new ConflictHttpException('Voter is not eligible or has already cast a ballot.');
            $nomination = DB::table('governance_election_nominations')->where('tenant_id', $tenant)->where('election_id', $electionId)
                ->where('id', $data['nomination_id'])->where('status', 'accepted')->first();
            if (!$nomination) throw new ConflictHttpException('Candidate is not an accepted nomination for this election.');
            DB::table('governance_election_ballots')->insert([
                'tenant_id' => $tenant, 'election_id' => $electionId, 'nomination_id' => $nomination->id,
                'ballot_receipt_hash' => hash('sha256', $receipt), 'cast_at' => now(),
            ]);
            DB::table('governance_election_eligible_voters')->where('id', $voter->id)->update(['cast_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['success' => true, 'data' => ['receipt' => $receipt]]);
    }

    public function results(Request $request, int $electionId): JsonResponse
    {
        $tenant = $this->tenantId();
        $this->requirePermission($request, 'governance.election.view');
        $election = $this->election($tenant, $electionId);
        if (!in_array($election->status, ['voting_closed', 'certified'], true)) throw new ConflictHttpException('Results are withheld until voting closes.');
        $rows = DB::table('governance_election_nominations as n')->leftJoin('governance_election_ballots as b', function ($join): void {
            $join->on('b.nomination_id', '=', 'n.id')->on('b.election_id', '=', 'n.election_id');
        })->where('n.tenant_id', $tenant)->where('n.election_id', $electionId)->where('n.status', 'accepted')
            ->groupBy('n.id', 'n.governance_membership_id')->select('n.id as nomination_id', 'n.governance_membership_id', DB::raw('COUNT(b.id) as vote_count'))->orderByDesc('vote_count')->get();
        return response()->json(['success' => true, 'data' => $rows]);
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
