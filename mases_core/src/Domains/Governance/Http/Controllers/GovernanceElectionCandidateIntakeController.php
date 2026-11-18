<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceElectionCandidate;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateIntakeService;

final class GovernanceElectionCandidateIntakeController extends Controller
{
    public function __construct(private readonly GovernanceElectionCandidateIntakeService $candidates) {}

    public function index(Request $request, int $election): JsonResponse
    {
        $tenantId = $this->tenantId();
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $page = GovernanceElectionCandidate::query()->where('tenant_id', $tenantId)
            ->where('election_id', $election)->withCount(['nominations', 'evidence'])
            ->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(int $election, int $candidate): JsonResponse
    {
        $record = GovernanceElectionCandidate::query()->where('tenant_id', $this->tenantId())
            ->where('election_id', $election)->with(['nominations', 'evidence', 'eligibilityDecisions'])
            ->findOrFail($candidate);

        return response()->json(['data' => $record]);
    }

    public function submitNomination(Request $request, int $election): JsonResponse
    {
        $data = $request->validate([
            'candidate_person_id' => ['required', 'integer', 'min:1'],
            'nominator_shareholder_id' => ['sometimes', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'statement' => ['nullable', 'string', 'max:10000'],
        ]);
        $result = $this->candidates->submitNomination(
            $this->tenantId(), $election, (int) $data['candidate_person_id'],
            isset($data['nominator_shareholder_id']) ? (int) $data['nominator_shareholder_id'] : null,
            $data['idempotency_key'], $data['statement'] ?? null, $this->actor($request),
        );

        return response()->json(['data' => $result], 201);
    }

    public function amendNomination(Request $request, int $election, int $nomination): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:128'], 'statement' => ['nullable', 'string', 'max:10000']]);
        $record = $this->candidates->amendNomination($this->tenantId(), $election, $nomination,
            $data['idempotency_key'], $data['statement'] ?? null, $this->actor($request));

        return response()->json(['data' => $record]);
    }

    public function withdrawNomination(Request $request, int $election, int $nomination): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:2000']]);
        $record = $this->candidates->withdrawNomination($this->tenantId(), $election, $nomination,
            $data['reason'], $this->actor($request));

        return response()->json(['data' => $record]);
    }

    public function attachEvidence(Request $request, int $election, int $candidate): JsonResponse
    {
        $data = $request->validate(['file_id' => ['required', 'integer', 'min:1'], 'evidence_category' => ['required', 'string', 'min:2', 'max:64']]);
        $record = $this->candidates->attachEvidence($this->tenantId(), $election, $candidate,
            (int) $data['file_id'], $data['evidence_category'], $this->actor($request));

        return response()->json(['data' => $record], 201);
    }

    public function reviewEvidence(Request $request, int $election, int $candidate, int $evidence): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'reason' => ['required', 'string', 'min:10', 'max:4000']]);
        $record = $this->candidates->reviewEvidence($this->tenantId(), $election, $candidate, $evidence,
            $data['decision'], $data['reason'], $this->actor($request));

        return response()->json(['data' => $record]);
    }

    public function deferEligibility(Request $request, int $election, int $candidate): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:4000']]);
        $record = $this->candidates->deferEligibility($this->tenantId(), $election, $candidate,
            $data['reason'], $this->actor($request));

        return response()->json(['data' => $record], 201);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        return $actor;
    }

    private function tenantId(): int
    {
        if (!function_exists('mases_tenant_id')) abort(404);
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 404);
        return (int) $tenantId;
    }
}
