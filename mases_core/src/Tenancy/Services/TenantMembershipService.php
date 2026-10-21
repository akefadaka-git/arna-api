<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Tenancy\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Security\Services\SecurityEventService;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use RuntimeException;

/**
 * Applies controlled lifecycle transitions to a tenant membership.
 *
 * The composite-key pivot is updated through the query builder because
 * Eloquent models do not natively support composite primary-key persistence.
 */
final class TenantMembershipService
{
    public function __construct(
        private readonly SecurityEventService $securityEvents,
    ) {
    }

    public function suspend(
        int $tenantId,
        int $userId,
        User|int|null $actor = null,
        ?string $reason = null,
    ): void {
        $this->transition($tenantId, $userId, TenantMembership::STATUS_SUSPENDED, $actor, $reason);
    }

    public function reactivate(
        int $tenantId,
        int $userId,
        User|int|null $actor = null,
        ?string $reason = null,
    ): void {
        $this->transition($tenantId, $userId, TenantMembership::STATUS_ACTIVE, $actor, $reason);
    }

    public function revoke(
        int $tenantId,
        int $userId,
        User|int|null $actor = null,
        ?string $reason = null,
    ): void {
        $this->transition($tenantId, $userId, TenantMembership::STATUS_REVOKED, $actor, $reason);
    }

    private function transition(
        int $tenantId,
        int $userId,
        string $targetStatus,
        User|int|null $actor,
        ?string $reason,
    ): void {
        if (!in_array($targetStatus, TenantMembership::STATUSES, true)) {
            throw new DomainException('Unsupported tenant membership status.');
        }

        $change = DB::transaction(function () use ($tenantId, $userId, $targetStatus): ?array {
            $membership = DB::table('tenant_memberships')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($membership === null) {
                throw new RuntimeException('Tenant membership does not exist.');
            }

            $currentStatus = (string) ($membership->status ?? TenantMembership::STATUS_ACTIVE);

            if ($currentStatus === $targetStatus) {
                return null;
            }

            $allowedTransitions = [
                TenantMembership::STATUS_ACTIVE => [
                    TenantMembership::STATUS_SUSPENDED,
                    TenantMembership::STATUS_REVOKED,
                ],
                TenantMembership::STATUS_SUSPENDED => [
                    TenantMembership::STATUS_ACTIVE,
                    TenantMembership::STATUS_REVOKED,
                ],
                TenantMembership::STATUS_REVOKED => [],
            ];

            if (!in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                throw new DomainException(
                    sprintf('Tenant membership cannot transition from "%s" to "%s".', $currentStatus, $targetStatus)
                );
            }

            DB::table('tenant_memberships')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->update([
                    'status' => $targetStatus,
                    'updated_at' => now(),
                ]);

            return [
                'previous_status' => $currentStatus,
                'new_status' => $targetStatus,
            ];
        });

        if ($change === null) {
            return;
        }

        $actorId = $actor instanceof User ? $actor->id : $actor;

        $this->securityEvents->log(
            'tenant.membership.status_changed',
            [
                'tenant_id' => $tenantId,
                'user_id' => $actorId,
                'actor_user_id' => $actorId,
                'member_user_id' => $userId,
                'previous_status' => $change['previous_status'],
                'new_status' => $change['new_status'],
                'reason' => $reason,
            ],
            'warning',
            'Tenant membership lifecycle state changed.',
        );
    }
}
