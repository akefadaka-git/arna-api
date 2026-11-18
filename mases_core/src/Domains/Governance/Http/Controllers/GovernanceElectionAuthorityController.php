<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElection;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionAuthorityEvidence;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;

final class GovernanceElectionAuthorityController extends Controller
{
    public function __construct(private readonly GovernanceElectionAuthorityService $elections)
    {
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', GovernanceElection::class);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null, 404);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $page = GovernanceElection::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', GovernanceElection::class);
        $tenantId = $this->tenantId();
        abort_if($tenantId === null, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'governance_body_id' => ['nullable', 'integer', 'min:1'],
            'record_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (!empty($data['governance_body_id'])) {
            $exists = GovernanceBody::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($data['governance_body_id'])
                ->exists();
            abort_unless($exists, 404);
        }

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $election = $this->elections->createPlanned(
            $tenantId,
            $data['code'],
            $data['title'],
            $data['governance_body_id'] ?? null,
            $data['record_date'] ?? null,
            $actor,
        );

        return response()->json(['data' => $election], 201);
    }

    public function show(GovernanceElection $governanceElection): JsonResponse
    {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('view', $governanceElection);

        return response()->json(['data' => $governanceElection]);
    }

    public function authorityEvidenceIndex(GovernanceElection $governanceElection): JsonResponse
    {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('view', $governanceElection);

        $evidence = $governanceElection->authorityEvidence()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get([
                'id',
                'tenant_id',
                'election_id',
                'instrument_type',
                'instrument_reference',
                'instrument_date',
                'storage_reference',
                'content_sha256',
                'status',
                'recorded_by',
                'reviewed_by',
                'reviewed_at',
                'review_reason',
                'created_at',
            ]);

        return response()->json(['data' => $evidence]);
    }

    public function recordAuthorityEvidence(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('manage', $governanceElection);

        $data = $request->validate([
            'instrument_type' => ['required', 'string', 'max:48'],
            'instrument_reference' => ['required', 'string', 'max:180'],
            'instrument_date' => ['nullable', 'date_format:Y-m-d'],
            'storage_reference' => ['required', 'string', 'max:255'],
            'content_sha256' => ['required', 'regex:/^[a-fA-F0-9]{64}$/'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $evidence = $this->elections->recordAuthorityEvidence(
            $tenantId,
            (int) $governanceElection->getKey(),
            $data['instrument_type'],
            $data['instrument_reference'],
            $data['instrument_date'] ?? null,
            $data['storage_reference'],
            $data['content_sha256'],
            $actor,
        );

        return response()->json(['data' => $evidence], 201);
    }

    public function reviewAuthorityEvidence(
        Request $request,
        GovernanceElection $governanceElection,
        GovernanceElectionAuthorityEvidence $evidence,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('reviewAuthorityEvidence', $governanceElection);
        abort_unless(
            (int) $evidence->tenant_id === $tenantId
            && (int) $evidence->election_id === (int) $governanceElection->getKey(),
            404,
        );

        $data = $request->validate([
            'decision' => ['required', 'in:reviewed,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $reviewed = $this->elections->reviewAuthorityEvidence(
            $tenantId,
            (int) $governanceElection->getKey(),
            (int) $evidence->getKey(),
            $data['decision'],
            $data['reason'],
            $actor,
        );

        return response()->json(['data' => $reviewed]);
    }

    public function verifyAuthority(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('verifyAuthority', $governanceElection);

        $data = $request->validate([
            'evidence_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $election = $this->elections->verifyAuthority(
            $tenantId,
            (int) $governanceElection->getKey(),
            (int) $data['evidence_id'],
            $data['reason'],
            $actor,
        );

        return response()->json(['data' => $election]);
    }

    public function openNominations(Request $request, GovernanceElection $governanceElection): JsonResponse
    {
        $tenantId = $this->tenantId();
        abort_if($tenantId === null || (int) $governanceElection->tenant_id !== $tenantId, 404);
        Gate::authorize('manage', $governanceElection);

        $data = $request->validate([
            'rule_version' => ['required', 'string', 'max:80'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
        ]);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $election = $this->elections->openNominations(
            $tenantId,
            (int) $governanceElection->getKey(),
            $data['rule_version'],
            $data['opens_at'],
            $data['closes_at'],
            $actor,
        );

        return response()->json(['data' => $election]);
    }

    private function tenantId(): ?int
    {
        if (!function_exists('mases_tenant_id')) {
            return null;
        }

        $tenantId = mases_tenant_id();

        return $tenantId === null ? null : (int) $tenantId;
    }
}
