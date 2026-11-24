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

    public function attach(
        int $tenantId,
        int $userId,
        User|int|null $actor,
        ?string $reason = null,
    ): void {
        $actorId = $actor instanceof User ? $actor->id : $actor;

        if ($actorId === null) {
            throw new RuntimeException('A recorded actor is required to create a tenant membership.');
        }

        DB::transaction(function () use ($tenantId, $userId, $actorId, $reason): void {
            $tenant = DB::table('tenants')
                ->where('id', $tenantId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($tenant === null) {
                throw new RuntimeException('Active tenant does not exist.');
            }

            $member = DB::table('users')
                ->where('id', $userId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw new RuntimeException('Active user does not exist.');
            }

            $exists = DB::table('tenant_memberships')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new DomainException('Tenant membership already exists; use the lifecycle transition operation.');
            }

            $now = now();
            DB::table('tenant_memberships')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'joined_at' => $now,
                'status' => TenantMembership::STATUS_ACTIVE,
                'settings' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Membership authority and its mandatory security evidence commit
            // together. A null event means SecurityEventService could not persist.
            $event = $this->securityEvents->log(
                'tenant.membership.created',
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $actorId,
                    'actor_user_id' => $actorId,
                    'member_user_id' => $userId,
                    'previous_status' => null,
                    'new_status' => TenantMembership::STATUS_ACTIVE,
                    'reason' => $reason,
                ],
                'warning',
                'Tenant membership created.',
            );

            if ($event === null) {
                throw new RuntimeException(
                    'Tenant membership creation was rolled back because its security event could not be persisted.'
                );
            }
        });
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

        $actorId = $actor instanceof User ? $actor->id : $actor;

        DB::transaction(function () use ($tenantId, $userId, $targetStatus, $actorId, $reason): void {
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
                return;
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

            // Persist the security event on the same database transaction as
            // the membership state change. SecurityEventService deliberately
            // returns null when persistence fails; treating that as fatal here
            // prevents an unrecorded lifecycle transition from committing.
            $event = $this->securityEvents->log(
                'tenant.membership.status_changed',
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $actorId,
                    'actor_user_id' => $actorId,
                    'member_user_id' => $userId,
                    'previous_status' => $currentStatus,
                    'new_status' => $targetStatus,
                    'reason' => $reason,
                ],
                'warning',
                'Tenant membership lifecycle state changed.',
            );

            if ($event === null) {
                throw new RuntimeException(
                    'Tenant membership transition was rolled back because its security event could not be persisted.'
                );
            }
        });
    }
}
