<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Tests\TestCase;

final class AdminAuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set('mases.features.audit_enabled', true);
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->tenants()->attach($this->tenant->id, [
            'joined_at' => now(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_audit_log_search_is_tenant_scoped_and_read_only(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('audit.view');
        $own = AuditLog::query()->create([
            'user_id' => $this->actor->id,
            'tenant_id' => $this->tenant->id,
            'action' => 'identity.profile.updated',
            'resource_type' => 'user',
            'resource_id' => '42',
            'new_values' => [
                'locale' => 'am',
                'password' => 'must-not-leak',
                'nested' => ['access_token' => 'must-not-leak-either'],
            ],
        ]);
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $foreign = AuditLog::query()->create([
            'user_id' => $this->actor->id,
            'tenant_id' => $otherTenant->id,
            'action' => 'finance.payment.approved',
            'resource_type' => 'payment',
            'resource_id' => '99',
        ]);

        $response = $this->asTenantAdmin()->getJson('/api/admin/audit-logs?q=identity');

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $own->id)
            ->assertJsonPath('data.data.0.new_values.password', '[REDACTED]')
            ->assertJsonPath('data.data.0.new_values.nested.access_token', '[REDACTED]')
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->asTenantAdmin()->getJson('/api/admin/audit-logs/' . $foreign->id)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['id' => $own->id, 'action' => 'identity.profile.updated']);
    }

    public function test_security_events_can_be_filtered_and_resolved_with_a_separate_audit_record(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('security-event.view');
        $this->grant('security-event.resolve');
        $event = SecurityEvent::query()->create([
            'user_id' => $this->actor->id,
            'tenant_id' => $this->tenant->id,
            'event_type' => 'login.failed',
            'severity' => 'warning',
            'description' => 'Repeated failed sign-in attempts',
            'event_data' => ['attempt_count' => 5, 'otp_code' => '123456'],
            'ip_address' => '192.0.2.10',
            'request_id' => 'req-security-001',
            'created_at' => now(),
        ]);

        $this->asTenantAdmin()
            ->getJson('/api/admin/security-events?severity=warning&status=open')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $event->id)
            ->assertJsonPath('data.data.0.event_data.otp_code', '[REDACTED]');

        $this->asTenantAdmin()
            ->postJson('/api/admin/security-events/' . $event->id . '/resolve', [
                'resolution_notes' => 'Reviewed sign-in pattern and confirmed legitimate user recovery.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolved_by', $this->actor->id);

        $this->assertDatabaseHas('security_events', [
            'id' => $event->id,
            'resolved_by' => $this->actor->id,
            'resolution_notes' => 'Reviewed sign-in pattern and confirmed legitimate user recovery.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'security.event.resolved',
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->actor->id,
            'resource_type' => 'security_event',
            'resource_id' => (string) $event->id,
        ]);

        $this->asTenantAdmin()
            ->postJson('/api/admin/security-events/' . $event->id . '/resolve', [
                'resolution_notes' => 'Attempt to resolve a second time.',
            ])
            ->assertUnprocessable();
    }

    public function test_audit_and_security_endpoints_require_explicit_permissions(): void
    {
        $this->grant('app.admin.access', 'global');

        $this->asTenantAdmin()->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->asTenantAdmin()->getJson('/api/admin/security-events')->assertForbidden();
    }

    private function asTenantAdmin(): self
    {
        return $this->actingAs($this->actor, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $this->tenant->id);
    }

    private function grant(string $slug, string $scope = 'tenant'): Permission
    {
        $permission = Permission::factory()->create([
            'slug' => $slug,
            'scope' => $scope,
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => $slug === 'app.admin.access',
        ]);
        $this->actor->permissions()->attach($permission->id, [
            'assigned_at' => now(),
            'assigned_by' => $this->actor->id,
        ]);

        return $permission;
    }
}
