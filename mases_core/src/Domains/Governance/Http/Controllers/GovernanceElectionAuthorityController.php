<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceElectionAuthorityService;

final class GovernanceElectionAuthorityController extends Controller
{
    public function index(Request $request, int $election, GovernanceElectionAuthorityService $authority): JsonResponse
    {
        return response()->json([
            'data' => $authority->listEvidence($this->tenantId(), $election, $this->actor($request)),
        ]);
    }

    public function store(Request $request, int $election, GovernanceElectionAuthorityService $authority): JsonResponse
    {
        $data = $request->validate([
            'instrument_type' => ['required', 'string', 'max:48'],
            'instrument_reference' => ['required', 'string', 'max:180'],
            'instrument_date' => ['nullable', 'date_format:Y-m-d'],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);

        $evidence = $authority->recordAuthorityEvidence(
            $this->tenantId(),
            $election,
            $data['instrument_type'],
            $data['instrument_reference'],
            $data['instrument_date'] ?? null,
            (int) $data['document_id'],
            $this->actor($request),
        );

        return response()->json(['data' => $evidence], 201);
    }

    public function review(
        Request $request,
        int $election,
        int $evidence,
        GovernanceElectionAuthorityService $authority,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', 'in:reviewed,rejected'],
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $reviewed = $authority->reviewAuthorityEvidence(
            $this->tenantId(),
            $election,
            $evidence,
            $data['decision'],
            $data['reason'],
            $this->actor($request),
        );

        return response()->json(['data' => $reviewed]);
    }

    public function verify(
        Request $request,
        int $election,
        int $evidence,
        GovernanceElectionAuthorityService $authority,
    ): JsonResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        $verified = $authority->verifyAuthority(
            $this->tenantId(),
            $election,
            $evidence,
            $data['reason'],
            $this->actor($request),
        );

        return response()->json(['data' => $verified]);
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
