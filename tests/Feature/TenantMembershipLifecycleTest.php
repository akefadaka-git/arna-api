<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\TenantMembershipService;
use RuntimeException;
use Tests\TestCase;

final class TenantMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspending_a_membership_removes_tenant_access_and_records_security_event(): void
    {
        [$tenant, $member, $actor] = $this->createMembership();

        app(TenantMembershipService::class)->suspend(
            (int) $tenant->id,
            (int) $member->id,
            $actor,
            'Owner-directed suspension',
        );

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => TenantMembership::STATUS_SUSPENDED,
        ]);

        $this->assertFalse(
            $member->activeTenants()->where('tenants.id', $tenant->id)->exists(),
        );

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'tenant.membership.status_changed',
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_suspended_membership_can_be_reactivated(): void
    {
        [$tenant, $member, $actor] = $this->createMembership(TenantMembership::STATUS_SUSPENDED);

        app(TenantMembershipService::class)->reactivate(
            (int) $tenant->id,
            (int) $member->id,
            $actor,
            'Suspension reviewed',
        );

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $this->assertTrue(
            $member->activeTenants()->where('tenants.id', $tenant->id)->exists(),
        );
    }

    public function test_revoked_membership_cannot_be_reactivated(): void
    {
        [$tenant, $member, $actor] = $this->createMembership(TenantMembership::STATUS_REVOKED);

        $this->expectException(DomainException::class);

        app(TenantMembershipService::class)->reactivate(
            (int) $tenant->id,
            (int) $member->id,
            $actor,
            'Attempted reactivation',
        );
    }

    public function test_transition_fails_when_membership_does_not_exist(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);

        $this->expectException(RuntimeException::class);

        app(TenantMembershipService::class)->suspend(
            (int) $tenant->id,
            (int) $member->id,
        );
    }

    /** @return array{Tenant, User, User} */
    private function createMembership(string $status = TenantMembership::STATUS_ACTIVE): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);

        $member->tenants()->attach($tenant->id, [
            'joined_at' => now(),
            'status' => $status,
        ]);

        return [$tenant, $member, $actor];
    }
}
