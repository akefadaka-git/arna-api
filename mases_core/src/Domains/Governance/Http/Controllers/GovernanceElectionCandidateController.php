<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionCandidateService;

final class GovernanceElectionCandidateController extends Controller
{
    public function configureWindow(Request $request, int $election, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
            'rule_set_version' => ['required', 'string', 'max:80'],
            'rule_set_digest' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'authority_reference' => ['required', 'string', 'max:200'],
        ]);
        $window = $service->configureNominationWindow(
            $this->tenantId(), $election, $this->actor($request),
            $data['opens_at'], $data['closes_at'], $data['rule_set_version'],
            $data['rule_set_digest'], $data['authority_reference'],
        );
        return response()->json(['success' => true, 'data' => $window], 201);
    }

    public function openWindow(Request $request, int $election, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $window = $service->openNominationWindow($this->tenantId(), $election, $this->actor($request), (int) $data['expected_version']);
        return response()->json(['success' => true, 'data' => $window]);
    }

    public function closeWindow(Request $request, int $election, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $window = $service->closeNominationWindow($this->tenantId(), $election, $this->actor($request), (int) $data['expected_version'], $data['reason']);
        return response()->json(['success' => true, 'data' => $window]);
    }

    public function submitNomination(Request $request, int $election, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'candidate_person_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'statement' => ['nullable', 'string', 'max:10000'],
        ]);
        $submission = $service->submitNomination(
            $this->tenantId(),
            $election,
            $this->actor($request),
            (int) $data['candidate_person_id'],
            $data['idempotency_key'],
            $data['statement'] ?? null,
        );
        return response()->json(['success' => true, 'data' => $submission], 201);
    }

    public function withdrawNomination(Request $request, int $election, int $submission, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $result = $service->withdrawNomination($this->tenantId(), $election, $submission, $this->actor($request), $data['reason']);
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function correctNomination(Request $request, int $election, int $submission, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'statement' => ['required', 'string', 'min:1', 'max:10000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $result = $service->correctNomination(
            $this->tenantId(), $election, $submission, $this->actor($request),
            $data['statement'], $data['reason'],
        );
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function index(Request $request, int $election, GovernanceElectionCandidateService $service): JsonResponse
    {
        $candidates = $service->listCandidates($this->tenantId(), $election, $this->actor($request));
        return response()->json(['success' => true, 'data' => $candidates]);
    }

    public function consent(Request $request, int $election, int $candidate, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:direct,documented'],
            'evidence_file_ids' => ['sometimes', 'array', 'max:10'],
            'evidence_file_ids.*' => ['integer', 'min:1'],
        ]);
        $result = $service->recordConsent(
            $this->tenantId(), $election, $candidate, $this->actor($request),
            $data['method'], array_map('intval', $data['evidence_file_ids'] ?? []),
        );
        return response()->json(['success' => true, 'data' => $result]);
    }

    public function evidence(Request $request, int $election, int $candidate, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'file_id' => ['required', 'integer', 'min:1'],
            'evidence_type' => ['required', 'in:identity,qualification,declaration,consent,other'],
        ]);
        $result = $service->attachEvidence(
            $this->tenantId(), $election, $candidate, $this->actor($request),
            (int) $data['file_id'], $data['evidence_type'],
        );
        return response()->json(['success' => true, 'data' => $result], 201);
    }

    public function decide(Request $request, int $election, int $candidate, GovernanceElectionCandidateService $service): JsonResponse
    {
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', 'in:eligible,ineligible,deferred_unresolved'],
            'rationale' => ['required', 'string', 'min:5', 'max:2000'],
            'evidence_ids' => ['required', 'array', 'min:1', 'max:30'],
            'evidence_ids.*' => ['integer', 'min:1'],
        ]);
        $result = $service->decideEligibility(
            $this->tenantId(), $election, $candidate, $this->actor($request),
            (int) $data['expected_version'], $data['outcome'], $data['rationale'],
            array_map('intval', $data['evidence_ids']),
        );
        return response()->json(['success' => true, 'data' => $result]);
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
