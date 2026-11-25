<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Database\Seeders\Data\RolePermissionGrants;
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
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name_en' => $slug,
                    'category' => 'task',
                    'scope' => 'tenant',
                    'tenant_id' => $tenant->id,
                    'is_active' => true,
                    'is_system' => false,
                ],
            );
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
    public function tenant_roles_receive_personal_task_permissions_without_implicit_tenant_wide_visibility(): void
    {
        foreach (['manager', 'finance_manager', 'hr_manager', 'shareholder', 'board', 'president', 'secretary', 'cashier', 'ceo'] as $role) {
            $grants = RolePermissionGrants::MAP[$role] ?? [];
            $this->assertContains('task.view', $grants, $role.' must be able to view personal or assigned tasks.');
            $this->assertContains('task.create', $grants, $role.' must be able to create personal tasks.');
            $this->assertContains('task.update', $grants, $role.' must be able to update personal tasks.');
        }

        foreach (['finance_manager', 'hr_manager', 'shareholder', 'board', 'president', 'secretary', 'cashier'] as $role) {
            $this->assertNotContains('task.view_all', RolePermissionGrants::MAP[$role], $role.' must not receive tenant-wide task visibility by default.');
            $this->assertNotContains('task.assign', RolePermissionGrants::MAP[$role], $role.' must not receive corporate reassignment authority by default.');
        }
    }

    #[Test]
    public function deadline_projection_accepts_ethiopian_ranges_and_preserves_task_visibility(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $other = $this->member($tenant);
        $this->grant($actor, $tenant, 'task.create', 'task.view');
        $this->grant($other, $tenant, 'task.create', 'task.view');

        Sanctum::actingAs($actor);
        $own = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'title' => 'My E.C. deadline',
                'due_on_ec' => ['year' => 2019, 'month' => 3, 'day' => 10],
            ])
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($other);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'title' => 'Another member deadline',
                'due_on_ec' => ['year' => 2019, 'month' => 3, 'day' => 10],
            ])
            ->assertCreated();

        Sanctum::actingAs($actor);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/tasks/deadlines?from_ec[year]=2019&from_ec[month]=3&from_ec[day]=10&to_ec[year]=2019&to_ec[month]=3&to_ec[day]=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own)
            ->assertJsonPath('data.0.due_on_ec.iso', '2019-03-10');
    }

    #[Test]
    public function personal_tasks_cannot_be_reassigned_as_corporate_work(): void
    {
        $tenant = $this->tenant();
        $actor = $this->member($tenant);
        $other = $this->member($tenant);
        $this->grant($actor, $tenant, 'task.create', 'task.assign');
        Sanctum::actingAs($actor);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'task_scope' => 'personal',
                'title' => 'Personal reminder',
            ])
            ->assertCreated();

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks/'.$created->json('data.id').'/assign', [
                'version' => 1,
                'assigned_to_user_id' => $other->id,
            ])
            ->assertNotFound();
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
    public function assignees_can_progress_tasks_and_reopening_a_completed_task_resets_progress(): void
    {
        $tenant = $this->tenant();
        $creator = $this->member($tenant);
        $assignee = $this->member($tenant);
        $this->grant($creator, $tenant, 'task.create', 'task.update', 'task.assign');
        $this->grant($assignee, $tenant, 'task.update');
        Sanctum::actingAs($creator);

        $created = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/tasks', [
                'task_scope' => 'corporate',
                'title' => 'Quarterly close',
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertCreated();

        $taskId = $created->json('data.id');
        Sanctum::actingAs($assignee);

        $progress = $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => 1,
                'status' => 'in_progress',
                'progress_percent' => 40,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percent', 40);

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => $progress->json('data.version'),
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress_percent', 100);

        Sanctum::actingAs($creator);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->patchJson('/api/tasks/'.$taskId, [
                'version' => 3,
                'status' => 'in_progress',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.progress_percent', 99)
            ->assertJsonPath('data.completed_at', null);
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
