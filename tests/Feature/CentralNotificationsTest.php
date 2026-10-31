<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Notifications\Services\NotificationService;
use MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Tests\TestCase;

final class CentralNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        $this->app['router']->aliasMiddleware('tenant.membership', TenantMembershipMiddleware::class);
    }

    public function test_inbox_is_scoped_to_authenticated_user_and_active_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $recipient = User::factory()->create();
        $otherUser = User::factory()->create();

        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $otherUser->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $otherUser->tenants()->attach($otherTenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        app(NotificationService::class)->publish($tenant, [$recipient, $otherUser], [
            'event_key' => 'test.event',
            'category' => 'system',
            'title' => ['am' => 'ሙከራ', 'en' => 'Test'],
            'dedupe_key' => 'test-event-1',
        ]);

        $response = $this->actingAs($recipient, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.title.en', 'Test');
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $owner->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $intruder->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        $notification = app(NotificationService::class)->publish($tenant, [$owner], [
            'event_key' => 'test.private',
            'category' => 'system',
            'title' => ['en' => 'Private'],
        ])[0];

        $this->actingAs($intruder, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/notifications/'.$notification->id.'/read')
            ->assertNotFound();
    }

    public function test_notification_endpoints_require_a_resolved_tenant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TENANT_REQUIRED');
    }
}
