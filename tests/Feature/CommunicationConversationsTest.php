<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Tests\TestCase;

final class CommunicationConversationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
        Config::set('mases.tenant.isolation_enabled', true);
        $this->app['router']->aliasMiddleware('tenant.membership', TenantMembershipMiddleware::class);
    }

    public function test_conversation_creation_requires_permission_and_active_tenant_participants(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send']);
        $recipient = $this->member($tenant);

        $response = $this->tenantRequest($sender, $tenant)->postJson('/api/communication/conversations', [
            'type' => 'direct',
            'participant_ids' => [$recipient->id],
        ])->assertCreated();

        $conversationId = $response->json('data.id');
        $this->assertDatabaseHas('communication_conversations', [
            'id' => $conversationId,
            'tenant_id' => $tenant->id,
            'type' => 'direct',
        ]);
        $this->assertSame(2, DB::table('communication_conversation_participants')
            ->where('conversation_id', $conversationId)->count());
    }

    public function test_foreign_or_inactive_users_cannot_be_added_to_a_conversation(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send']);
        $foreignTenant = Tenant::factory()->create();
        $foreign = $this->member($foreignTenant);

        $this->tenantRequest($sender, $tenant)->postJson('/api/communication/conversations', [
            'type' => 'direct',
            'participant_ids' => [$foreign->id],
        ])->assertUnprocessable();

        $this->assertSame(0, DB::table('communication_conversations')->count());
    }

    public function test_only_active_conversation_participants_can_read_messages(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send', 'communication.view']);
        [$otherTenant, $outsider] = $this->tenantUser(['communication.view']);
        $recipient = $this->member($tenant);
        $conversationId = $this->createConversation($sender, $tenant, $recipient);

        $this->tenantRequest($sender, $tenant)->postJson("/api/communication/conversations/{$conversationId}/messages", [
            'body' => 'Tenant-private message',
            'idempotency_key' => 'message-key-0001',
        ])->assertCreated();

        $this->tenantRequest($outsider, $otherTenant)
            ->getJson("/api/communication/conversations/{$conversationId}/messages")
            ->assertNotFound();

        $this->tenantRequest($recipient, $tenant)
            ->getJson("/api/communication/conversations/{$conversationId}/messages")
            ->assertForbidden();
    }

    public function test_message_idempotency_returns_existing_message_and_rejects_payload_reuse(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send', 'communication.view']);
        $recipient = $this->member($tenant);
        $conversationId = $this->createConversation($sender, $tenant, $recipient);
        $payload = ['body' => 'Hello ARNA', 'idempotency_key' => 'message-key-0002'];

        $first = $this->tenantRequest($sender, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/messages", $payload)
            ->assertCreated();
        $second = $this->tenantRequest($sender, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/messages", $payload)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('communication_messages')->count());
        $this->assertSame(1, PlatformNotification::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_key', 'communication.message.created')
            ->count());

        $this->tenantRequest($sender, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/messages", [
                'body' => 'Different content',
                'idempotency_key' => 'message-key-0002',
            ])->assertStatus(409);
    }

    public function test_read_receipt_is_scoped_to_the_recipient_and_conversation(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send', 'communication.view']);
        $recipient = $this->tenantUser(['communication.view'], $tenant)[1];
        $conversationId = $this->createConversation($sender, $tenant, $recipient);

        $message = $this->tenantRequest($sender, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/messages", [
                'body' => 'Please confirm receipt',
                'idempotency_key' => 'message-key-0003',
            ])->assertCreated();

        $this->tenantRequest($recipient, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/read")
            ->assertOk()
            ->assertJsonPath('data.read_count', 1);

        $this->assertDatabaseHas('communication_message_receipts', [
            'message_id' => $message->json('data.id'),
            'conversation_id' => $conversationId,
            'tenant_id' => $tenant->id,
            'user_id' => $recipient->id,
        ]);
        $this->assertNotNull(DB::table('communication_message_receipts')
            ->where('message_id', $message->json('data.id'))
            ->where('user_id', $recipient->id)
            ->value('read_at'));
    }

    public function test_notification_failure_rolls_back_message_and_notification_as_one_unit(): void
    {
        [$tenant, $sender] = $this->tenantUser(['communication.message.send', 'communication.view']);
        $recipient = $this->member($tenant);
        $conversationId = $this->createConversation($sender, $tenant, $recipient);

        DB::listen(static function (QueryExecuted $query): void {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'platform_notifications')) {
                throw new RuntimeException('simulated communication notification failure');
            }
        });

        $this->tenantRequest($sender, $tenant)
            ->postJson("/api/communication/conversations/{$conversationId}/messages", [
                'body' => 'This must roll back with notification failure',
                'idempotency_key' => 'message-key-fail-0004',
            ])->assertServerError();

        $this->assertSame(0, DB::table('communication_messages')->count());
        $this->assertSame(0, PlatformNotification::query()->where('tenant_id', $tenant->id)->count());
    }

    private function tenantUser(array $permissions = [], ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $user = $this->member($tenant);

        foreach ($permissions as $slug) {
            $permission = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am' => $slug, 'name_en' => $slug,
                    'description_am' => $slug, 'description_en' => $slug,
                    'category' => 'communication', 'scope' => 'tenant',
                    'tenant_id' => null, 'application_id' => null,
                    'is_active' => true, 'is_system' => false,
                ],
            );
            $role = Role::create([
                'name_en' => 'Test '.$slug,
                'slug' => 'test-'.$user->id.'-'.str_replace('.', '-', $slug),
                'scope' => 'tenant', 'tenant_id' => $tenant->id,
                'application_id' => null, 'is_active' => true,
            ]);
            $role->permissions()->attach($permission->id, ['assigned_at' => now()]);
            $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => $user->id]);
        }

        return [$tenant, $user];
    }

    private function member(Tenant $tenant): User
    {
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['status' => TenantMembership::STATUS_ACTIVE]);
        return $user;
    }

    private function createConversation(User $sender, Tenant $tenant, User $recipient): string
    {
        return $this->tenantRequest($sender, $tenant)
            ->postJson('/api/communication/conversations', [
                'type' => 'direct',
                'participant_ids' => [$recipient->id],
            ])->assertCreated()->json('data.id');
    }

    private function tenantRequest(User $user, Tenant $tenant): static
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id);
    }
}
