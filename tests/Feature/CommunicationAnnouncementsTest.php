<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Data\PermissionCatalogue;
use Database\Seeders\Data\RolePermissionGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Notifications\Models\PlatformNotification;
use MaaleArna\MASES\Core\Tenancy\Middleware\TenantMembershipMiddleware;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Models\TenantMembership;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeException;
use Tests\TestCase;

final class CommunicationAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        $this->app['router']->aliasMiddleware('tenant.membership', TenantMembershipMiddleware::class);
    }

    public function test_announcement_permissions_are_registered_as_tenant_scoped_capabilities(): void
    {
        $catalogue = collect(PermissionCatalogue::ROWS)->keyBy('slug');

        // 44 core permission rows plus 13 Finance rows are the canonical 57.
        $this->assertCount(57, PermissionCatalogue::ROWS);

        foreach (['announcement.view', 'announcement.manage', 'announcement.approve', 'announcement.publish'] as $slug) {
            $this->assertTrue($catalogue->has($slug), "Missing canonical permission: {$slug}");
            $this->assertSame('announcement', $catalogue[$slug]['category']);
            $this->assertSame('tenant', $catalogue[$slug]['scope']);
            $this->assertFalse($catalogue[$slug]['is_system']);
        }
    }

    public function test_announcement_role_grants_follow_the_least_privilege_matrix(): void
    {
        $grants = RolePermissionGrants::MAP;

        $this->assertContains('announcement.view', $grants['manager']);
        $this->assertNotContains('announcement.manage', $grants['manager']);

        $this->assertContains('announcement.view', $grants['secretary']);
        $this->assertContains('announcement.manage', $grants['secretary']);
        $this->assertNotContains('announcement.approve', $grants['secretary']);
        $this->assertNotContains('announcement.publish', $grants['secretary']);

        $this->assertContains('announcement.view', $grants['president']);
        $this->assertContains('announcement.approve', $grants['president']);
        $this->assertContains('announcement.publish', $grants['president']);

        // Existing platform-wide role policy grants tenant-scoped permissions
        // to platform_admin and tenant_admin; super_admin remains wildcard.
        $this->assertContains('scope:tenant', $grants['platform_admin']);
        $this->assertContains('scope:tenant', $grants['tenant_admin']);
        $this->assertSame(['*'], $grants['super_admin']);
    }

    public function test_user_without_manage_permission_cannot_create_announcement(): void
    {
        [$tenant, $user] = $this->tenantUser();

        $this->tenantRequest($user, $tenant)->postJson('/api/announcements', $this->validPayload([$user->id]))
            ->assertForbidden();

        $this->assertSame(0, DB::table('communication_announcements')->count());
    }

    public function test_announcement_cannot_be_read_or_changed_across_tenant_boundary(): void
    {
        [$tenantA, $manager] = $this->tenantUser(['announcement.manage']);
        [$tenantB, $recipient] = $this->tenantUser();
        $announcementId = $this->insertAnnouncement($tenantB, $recipient, 'draft', [$recipient->id]);

        $this->tenantRequest($manager, $tenantA)
            ->postJson("/api/announcements/{$announcementId}/submit")
            ->assertNotFound();

        $this->assertDatabaseHas('communication_announcements', [
            'id' => $announcementId,
            'tenant_id' => $tenantB->id,
            'status' => 'draft',
        ]);
    }

    public function test_draft_creation_rejects_inactive_or_foreign_tenant_recipients(): void
    {
        [$tenant, $manager] = $this->tenantUser(['announcement.manage']);
        $inactiveRecipient = User::factory()->create();
        $inactiveRecipient->tenants()->attach($tenant->id, ['status' => 'inactive']);

        $this->tenantRequest($manager, $tenant)
            ->postJson('/api/announcements', $this->validPayload([$inactiveRecipient->id]))
            ->assertUnprocessable();

        $foreignTenant = Tenant::factory()->create();
        $foreignRecipient = User::factory()->create();
        $foreignRecipient->tenants()->attach($foreignTenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $this->tenantRequest($manager, $tenant)
            ->postJson('/api/announcements', $this->validPayload([$foreignRecipient->id]))
            ->assertUnprocessable();

        $this->assertSame(0, DB::table('communication_announcements')->count());
    }

    public function test_draft_and_recipient_inserts_roll_back_as_one_unit(): void
    {
        [$tenant, $manager] = $this->tenantUser(['announcement.manage']);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        DB::listen(static function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'communication_announcement_recipients')) {
                throw new RuntimeException('simulated recipient persistence failure');
            }
        });

        $this->tenantRequest($manager, $tenant)
            ->postJson('/api/announcements', $this->validPayload([$recipient->id]))
            ->assertServerError();

        $this->assertSame(0, DB::table('communication_announcements')->count());
        $this->assertSame(0, DB::table('communication_announcement_recipients')->count());
    }

    public function test_submitter_cannot_approve_their_own_announcement(): void
    {
        [$tenant, $author] = $this->tenantUser(['announcement.manage', 'announcement.approve']);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        $create = $this->tenantRequest($author, $tenant)
            ->postJson('/api/announcements', $this->validPayload([$recipient->id]))
            ->assertCreated();
        $id = $create->json('data.id');

        $this->tenantRequest($author, $tenant)->postJson("/api/announcements/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $this->tenantRequest($author, $tenant)->postJson("/api/announcements/{$id}/approve")
            ->assertForbidden();

        $this->assertDatabaseHas('communication_announcements', [
            'id' => $id,
            'status' => 'pending_approval',
            'submitted_by' => $author->id,
        ]);
    }

    public function test_announcement_lifecycle_publishes_only_to_explicit_active_recipients(): void
    {
        [$tenant, $author] = $this->tenantUser(['announcement.manage']);
        [, $approver] = $this->tenantUser(['announcement.approve'], $tenant);
        [, $publisher] = $this->tenantUser(['announcement.publish'], $tenant);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        $id = $this->tenantRequest($author, $tenant)
            ->postJson('/api/announcements', $this->validPayload([$recipient->id], true))
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($author, $tenant)->postJson("/api/announcements/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');
        $this->tenantRequest($approver, $tenant)->postJson("/api/announcements/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->tenantRequest($publisher, $tenant)->postJson("/api/announcements/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('communication_announcements', ['id' => $id, 'status' => 'published']);
        $this->assertDatabaseHas('communication_announcement_recipients', [
            'announcement_id' => $id,
            'user_id' => $recipient->id,
        ]);
        $this->assertSame(1, PlatformNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $recipient->id)
            ->where('event_key', 'communication.announcement.published')
            ->count());

        $this->tenantRequest($recipient, $tenant)->postJson("/api/announcements/{$id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);
        $this->assertDatabaseHas('communication_announcement_recipients', [
            'announcement_id' => $id,
            'user_id' => $recipient->id,
        ]);
        $this->assertNotNull(DB::table('communication_announcement_recipients')
            ->where('announcement_id', $id)->where('user_id', $recipient->id)->value('acknowledged_at'));
    }

    public function test_publication_revalidates_active_recipient_membership(): void
    {
        [$tenant, $author] = $this->tenantUser(['announcement.manage']);
        [, $approver] = $this->tenantUser(['announcement.approve'], $tenant);
        [, $publisher] = $this->tenantUser(['announcement.publish'], $tenant);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        $id = $this->insertAnnouncement($tenant, $author, 'approved', [$recipient->id]);
        DB::table('communication_announcement_recipients')
            ->where('announcement_id', $id)
            ->where('user_id', $recipient->id)
            ->update(['delivered_at' => null]);
        DB::table('tenant_memberships')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $recipient->id)
            ->update(['status' => 'inactive']);

        $this->tenantRequest($publisher, $tenant)->postJson("/api/announcements/{$id}/publish")
            ->assertStatus(409);

        $this->assertDatabaseHas('communication_announcements', ['id' => $id, 'status' => 'approved']);
        $this->assertSame(0, PlatformNotification::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_notification_failure_rolls_back_publication_and_delivery_markers(): void
    {
        [$tenant, $author] = $this->tenantUser(['announcement.manage']);
        [, $publisher] = $this->tenantUser(['announcement.publish'], $tenant);
        $recipient = User::factory()->create();
        $recipient->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        $id = $this->insertAnnouncement($tenant, $author, 'approved', [$recipient->id]);

        // Fail after MySQL executes the notification INSERT but before the
        // nested service transaction can commit. The outer announcement
        // transaction must roll back both publication and delivery markers.
        DB::listen(static function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'platform_notifications')) {
                throw new RuntimeException('simulated notification persistence failure');
            }
        });

        $this->tenantRequest($publisher, $tenant)->postJson("/api/announcements/{$id}/publish")
            ->assertServerError();

        $this->assertDatabaseHas('communication_announcements', ['id' => $id, 'status' => 'approved']);
        $this->assertNull(DB::table('communication_announcement_recipients')
            ->where('announcement_id', $id)->where('user_id', $recipient->id)->value('delivered_at'));
        $this->assertSame(0, PlatformNotification::query()->where('tenant_id', $tenant->id)->count());
    }

    private function tenantUser(array $permissions = [], ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);

        foreach ($permissions as $slug) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am' => $slug,
                    'name_en' => $slug,
                    'description_am' => $slug,
                    'description_en' => $slug,
                    'category' => 'announcement',
                    'scope' => 'tenant',
                    'tenant_id' => null,
                    'application_id' => null,
                    'is_active' => true,
                    'is_system' => false,
                ],
            );
            $role = Role::create([
                'name_en' => 'Test '.$slug,
                'slug' => 'test-'.$user->id.'-'.str_replace('.', '-', $slug),
                'scope' => 'tenant',
                'tenant_id' => $tenant->id,
                'application_id' => null,
                'is_active' => true,
            ]);
            $role->permissions()->attach($permission->id, ['assigned_at' => now()]);
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => $user->id]);
        }

        return [$tenant, $user];
    }

    private function tenantRequest(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id);
    }

    private function validPayload(array $recipientIds, bool $requiresAcknowledgement = false): array
    {
        return [
            'title' => ['am' => 'የሙከራ ማስታወቂያ', 'en' => 'Test announcement'],
            'content' => 'Announcement body for automated verification.',
            'content_locale' => 'en',
            'priority' => 'normal',
            'requires_acknowledgement' => $requiresAcknowledgement,
            'recipient_ids' => $recipientIds,
        ];
    }

    private function insertAnnouncement(Tenant $tenant, User $author, string $status, array $recipientIds): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('communication_announcements')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'created_by' => $author->id,
            'title' => json_encode(['am' => 'ማስታወቂያ', 'en' => 'Announcement'], JSON_THROW_ON_ERROR),
            'content' => 'Test announcement body',
            'content_locale' => 'am',
            'priority' => 'normal',
            'status' => $status,
            'requires_acknowledgement' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'submitted_by' => $status === 'approved' ? $author->id : null,
            'submitted_at' => $status === 'approved' ? now() : null,
            'approved_by' => $status === 'approved' ? $author->id + 1 : null,
            'approved_at' => $status === 'approved' ? now() : null,
        ]);
        foreach ($recipientIds as $recipientId) {
            DB::table('communication_announcement_recipients')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'announcement_id' => $id,
                'tenant_id' => $tenant->id,
                'user_id' => $recipientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return $id;
    }
}
