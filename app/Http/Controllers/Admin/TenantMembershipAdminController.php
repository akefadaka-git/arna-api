<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Exceptions\\AuthenticationException;
use MaaleArna\\MASES\\Core\\Exceptions\\TenantException;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\TenantMembership;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\TenantContextService;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\TenantMembershipService;

final class TenantMembershipAdminController extends Controller
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly TenantMembershipService $memberships,
    ) {
    }

    public function attach(Request $request, int $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->tenantId();
        $this->memberships->attach(
            $tenantId,
            $user,
            $this->actor($request),
            $validated['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'user_id' => $user,
                'status' => TenantMembership::STATUS_ACTIVE,
            ],
        ], 201);
    }

    public function suspend(Request $request, int $user): JsonResponse
    {
        return $this->transition($request, $user, TenantMembership::STATUS_SUSPENDED);
    }

    public function reactivate(Request $request, int $user): JsonResponse
    {
        return $this->transition($request, $user, TenantMembership::STATUS_ACTIVE);
    }

    public function revoke(Request $request, int $user): JsonResponse
    {
        return $this->transition($request, $user, TenantMembership::STATUS_REVOKED);
    }

    private function transition(Request $request, int $user, string $status): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->tenantId();
        $actor = $this->actor($request);

        match ($status) {
            TenantMembership::STATUS_SUSPENDED => $this->memberships->suspend(
                $tenantId, $user, $actor, $validated['reason'] ?? null,
            ),
            TenantMembership::STATUS_ACTIVE => $this->memberships->reactivate(
                $tenantId, $user, $actor, $validated['reason'] ?? null,
            ),
            TenantMembership::STATUS_REVOKED => $this->memberships->revoke(
                $tenantId, $user, $actor, $validated['reason'] ?? null,
            ),
        };

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'user_id' => $user,
                'status' => $status,
            ],
        ]);
    }

    private function tenantId(): int
    {
        $tenant = $this->tenantContext->getTenant();

        if ($tenant === null) {
            throw TenantException::required();
        }

        return (int) $tenant->id;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (!$actor instanceof User) {
            throw AuthenticationException::unauthenticated();
        }

        return $actor;
    }
}
