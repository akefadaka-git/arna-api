<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Tenancy\Services\TenantMembershipService;
use RuntimeException;
use Tests\TestCase;

final class TenantMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaching_a_membership_and_its_security_event_commit_together(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);

        app(TenantMembershipService::class)->attach(
            (int) $tenant->id,
            (int) $member->id,
            $actor,
            'Approved tenant onboarding',
        );

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $event = SecurityEvent::query()
            ->where('event_type', 'tenant.membership.created')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($member->id, $event->event_data['member_user_id']);
        $this->assertSame($actor->id, $event->event_data['actor_user_id']);
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $event->event_data['new_status']);
    }

    public function test_attaching_a_membership_rolls_back_when_security_event_cannot_be_persisted(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);

        try {
            app(TenantMembershipService::class)->attach(
                (int) $tenant->id,
                (int) $member->id,
                999999999,
                'Invalid actor must not create an unaudited membership',
            );

            $this->fail('A membership without durable security evidence must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('security event could not be persisted', $exception->getMessage());
        }

        $this->assertDatabaseMissing('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseMissing('security_events', [
            'event_type' => 'tenant.membership.created',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_duplicate_membership_attachment_is_rejected_without_a_second_event(): void
    {
        [$tenant, $member, $actor] = $this->createMembership();

        try {
            app(TenantMembershipService::class)->attach(
                (int) $tenant->id,
                (int) $member->id,
                $actor,
                'Duplicate must not create another membership',
            );

            $this->fail('A duplicate membership must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame(
            0,
            SecurityEvent::query()
                ->where('event_type', 'tenant.membership.created')
                ->where('tenant_id', $tenant->id)
                ->count(),
        );
    }

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

        $event = SecurityEvent::query()
            ->where('event_type', 'tenant.membership.status_changed')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(TenantMembership::STATUS_ACTIVE, $event->event_data['previous_status']);
        $this->assertSame(TenantMembership::STATUS_SUSPENDED, $event->event_data['new_status']);
        $this->assertSame($member->id, $event->event_data['member_user_id']);
        $this->assertSame($actor->id, $event->event_data['actor_user_id']);
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

    public function test_membership_transition_rolls_back_when_security_event_cannot_be_persisted(): void
    {
        [$tenant, $member] = $this->createMembership();

        // The security_events.user_id foreign key rejects this nonexistent
        // actor. SecurityEventService catches the DB error and returns null;
        // the lifecycle service must then abort the enclosing transaction.
        try {
            app(TenantMembershipService::class)->suspend(
                (int) $tenant->id,
                (int) $member->id,
                999999999,
                'Invalid actor must not produce an unaudited transition',
            );

            $this->fail('A transition without a durable security event must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('security event could not be persisted', $exception->getMessage());
        }

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseMissing('security_events', [
            'event_type' => 'tenant.membership.status_changed',
            'tenant_id' => $tenant->id,
        ]);
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
