<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Audit\Models\AuditLog;
use MaaleArna\MASES\Core\Security\Models\SecurityEvent;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use Tests\TestCase;

final class PlatformAuditSecurityReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_audit_and_security_routes_are_global_permission_gated_without_tenant_middleware(): void
    {
        $expected = [
            'api.admin.platform.audit-logs.index' => 'auth.permission:platform.audit.read',
            'api.admin.platform.security-events.index' => 'auth.permission:platform.security.read',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($permission, $route->gatherMiddleware());
            $this->assertNotContains('tenant.membership', $route->gatherMiddleware());
        }
    }

    public function test_platform_audit_feed_returns_only_tenantless_records_and_redacts_secrets(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->grant($user, 'platform.audit.read');

        AuditLog::query()->create([
            'user_id' => $user->id,
            'tenant_id' => null,
            'action' => 'platform.settings.changed',
            'resource_type' => 'platform_setting',
            'resource_id' => 'locale',
            'new_values' => ['locale' => 'am', 'api_key' => 'never-return-this'],
        ]);
        AuditLog::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant.member.updated',
            'resource_type' => 'membership',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/platform/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.action', 'platform.settings.changed')
            ->assertJsonPath('data.data.0.new_values.api_key', '[REDACTED]');
    }

    public function test_platform_security_feed_returns_only_tenantless_events_and_redacts_secrets(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $this->grant($user, 'platform.security.read');

        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'tenant_id' => null,
            'event_type' => 'platform.owner.approval.failed',
            'severity' => 'critical',
            'description' => 'Invalid platform approval signature',
            'event_data' => ['token' => 'never-return-this', 'reason' => 'invalid_signature'],
        ]);
        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'event_type' => 'tenant.login.failed',
            'severity' => 'warning',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/platform/security-events')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.event_type', 'platform.owner.approval.failed')
            ->assertJsonPath('data.data.0.event_data.token', '[REDACTED]');
    }

    public function test_platform_audit_feed_denies_users_without_global_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/platform/audit-logs')
            ->assertForbidden();
    }

    private function grant(User $user, string $slug): void
    {
        $permission = Permission::factory()->create([
            'slug' => $slug,
            'scope' => 'global',
            'is_active' => true,
        ]);
        $user->permissions()->attach($permission->id);
    }
}
