<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\RequestTenantResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class D08TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mases.tenant.isolation_enabled', true);
        Config::set('mases.tenant.context_resolver', RequestTenantResolver::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::factory()->create(['is_active' => true]);
    }

    private function member(Tenant $tenant): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->tenants()->attach($tenant->id, ['status' => 'active']);

        return $user;
    }

    private function grant(User $user, Tenant $tenant, string ...$slugs): void
    {
        foreach ($slugs as $slug) {
            $permission = Permission::query()->create([
                'name_en' => $slug,
                'slug' => $slug,
                'category' => 'task',
                'scope' => 'tenant',
                'tenant_id' => $tenant->id,
                'is_active' => true,
                'is_system' => false,
            ]);
            $user->permissions()->attach($permission->id, [
                'assigned_at' => now(),
                'assigned_by' => $user->id,
            ]);
        }
    }

    #[Test]
    public function personal_task_accepts_ethiopian_due_date_and_is_visible_to_its_owner(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $this->grant($actor, $tenant, 'task.create', 'task.view');
        Sanctum::actingAs($actor);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'task_scope' => 'personal',
                'title' => 'የሳምንቱ ሥራ ዕቅድ',
                'priority' => 'high',
                'due_on_ec' => ['year' => 2019, 'month' => 3, 'day' => 10],
            ])
            ->assertCreated()
            ->assertJsonPath('data.task_scope', 'personal')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.due_on_ec.iso', '2019-03-10');

        $taskId = $created->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $taskId);
    }

    #[Test]
    public function corporate_task_assignment_requires_active_membership_in_the_same_tenant(): void
    {
        $tenant = $this->tenant();
        $foreignTenant = $this->tenant();
        $actor = $this->member($tenant);
        $foreignMember = $this->member($foreignTenant);
        $this->grant($actor, $tenant, 'task.create', 'task.assign');
        Sanctum::actingAs($actor);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'task_scope' => 'corporate',
                'title' => 'Prepare monthly report',
                'assigned_to_user_id' => $actor->id,
            ])
            ->assertCreated();

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks/'.$created->json('data.id').'/assign', [
                'version' => 1,
                'assigned_to_user_id' => $foreignMember->id,
            ])
            ->assertStatus(400);
    }

    #[Test]
    public function task_updates_use_optimistic_versioning_and_assignees_cannot_edit_task_metadata(): void
    {
        $tenant = $this->tenant();
        $creator = $this->member($tenant);
        $assignee = $this->member($tenant);
        $this->grant($creator, $tenant, 'task.create', 'task.update', 'task.assign');
        $this->grant($assignee, $tenant, 'task.update', 'task.view');
        Sanctum::actingAs($creator);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'task_scope' => 'corporate',
                'title' => 'Board pack',
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertCreated();

        $taskId = $created->json('data.id');

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => 1,
                'title' => 'Updated board pack',
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => 1,
                'title' => 'Stale update',
            ])
            ->assertStatus(409);

        Sanctum::actingAs($assignee);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => 2,
                'title' => 'Unauthorized title edit',
            ])
            ->assertNotFound();
    }
}
