<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;

final class GovernanceAuthorityGrantController
{
    public function __construct(
        private readonly GovernanceAuthorityGrantService $grants,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $tenantId = $this->tenantId();
        $grants = $this->grants->list($actor, $tenantId);

        return response()->json([
            'success' => true,
            'data' => $grants,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'governance_position_id' => ['required', 'integer', 'min:1'],
            'grantee_person_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $grant = $this->grants->grant($actor, $this->tenantId(), $data);

        return response()->json([
            'success' => true,
            'data' => $grant,
        ], 201);
    }

    public function destroy(Request $request, int $governanceAuthorityGrant): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $grant = $this->grants->revoke(
            $actor,
            $this->tenantId(),
            $governanceAuthorityGrant,
            $data['reason'],
        );

        return response()->json([
            'success' => true,
            'data' => $grant,
        ]);
    }

    private function tenantId(): int
    {
        abort_unless(function_exists('mases_tenant_id'), 403, 'Validated tenant context is required.');
        $tenantId = mases_tenant_id();
        abort_if($tenantId === null, 403, 'Validated tenant context is required.');

        return (int) $tenantId;
    }
}
