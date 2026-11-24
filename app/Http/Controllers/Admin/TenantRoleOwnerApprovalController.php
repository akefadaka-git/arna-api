<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\TenantRoleOwnerApprovalService;

final class TenantRoleOwnerApprovalController
{
    public function __construct(
        private readonly TenantRoleOwnerApprovalService $approvals,
    ) {
    }

    public function challenge(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:tenant.role.assign,tenant.role.revoke,tenant.role.permissions.sync'],
            'role_id' => ['required', 'integer', 'min:1'],
            'target_user_id' => ['nullable', 'integer', 'min:1'],
            'permission_slugs' => ['sometimes', 'array', 'max:250'],
            'permission_slugs.*' => ['string', 'min:1', 'max:150'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $challenge = $this->approvals->createChallenge($actor, $this->tenantId(), $data);

        return response()->json([
            'success' => true,
            'data' => $challenge,
        ], 201);
    }

    public function execute(Request $request, string $challenge): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Authentication required.');

        $data = $request->validate([
            'signature' => ['required', 'string', 'max:256'],
            'signed_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ]);

        $result = $this->approvals->execute($actor, $this->tenantId(), $challenge, $data);

        return response()->json([
            'success' => true,
            'data' => $result,
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
