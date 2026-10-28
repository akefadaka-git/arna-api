<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAuditEvent;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationDomainTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unit_creation_is_tenant_scoped_and_audited(): void
    {
        [$tenant, $user] = $this->manager();
        Sanctum::actingAs($user);
        $response = $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'code' => 'hq', 'unit_type' => 'company', 'name_am' => 'ዋና መሥሪያ ቤት',
            'reason' => 'Initial organizational registration',
        ]);
        $response->assertCreated()->assertJsonPath('data.code', 'HQ');
        $this->assertDatabaseHas('organization_units', ['tenant_id' => $tenant->id, 'code' => 'HQ']);
        $this->assertDatabaseHas('organization_audit_events', ['tenant_id' => $tenant->id, 'action' => 'created', 'subject_type' => 'unit']);
    }

    #[Test]
    public function parent_from_another_tenant_is_not_resolvable(): void
    {
        [$tenant, $user] = $this->manager();
        $other = Tenant::factory()->create(['is_active' => true]);
        $parentId = DB::table('organization_units')->insertGetId([
            'tenant_id' => $other->id, 'parent_id' => null, 'code' => 'OTHER', 'unit_type' => 'company',
            'name_en' => 'Other', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'parent_id' => $parentId, 'code' => 'DIV', 'unit_type' => 'division',
            'name_en' => 'Division', 'reason' => 'Create division under parent',
        ])->assertNotFound();
    }

    #[Test]
    public function missing_permission_denies_unit_creation(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->withHeader('X-Tenant-ID', (string) $tenant->id)->postJson('/api/organization/units', [
            'code' => 'HQ', 'unit_type' => 'company', 'name_en' => 'HQ',
            'reason' => 'Initial organizational registration',
        ])->assertForbidden();
    }

    #[Test]
    public function audit_events_reject_model_updates_and_deletes(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $event = OrganizationAuditEvent::query()->create([
            'tenant_id' => $tenant->id,
            'subject_type' => 'unit',
            'subject_id' => 999,
            'action' => 'created',
            'reason' => 'Test append-only audit record',
            'before_state' => null,
            'after_state' => ['status' => 'active'],
            'request_id' => 'audit-immutability-test',
            'created_at' => now(),
        ]);

        try {
            $event->reason = 'tampered';
            $event->save();
            $this->fail('Updating an audit event should throw.');
        } catch (\LogicException $exception) {
            $this->assertSame('Organization audit events are append-only.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Deleting an audit event should throw.');
        } catch (\LogicException $exception) {
            $this->assertSame('Organization audit events are append-only.', $exception->getMessage());
        }
    }

    private function manager(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = Role::query()->firstOrCreate(['slug' => 'organization-test-manager'], ['name_en' => 'Organization Test Manager', 'scope' => 'tenant', 'tenant_id' => $tenant->id]);
        foreach (['organization.unit.view', 'organization.unit.manage', 'organization.assignment.view', 'organization.assignment.manage'] as $slug) {
            $permission = Permission::query()->firstOrCreate(['slug' => $slug], ['name_en' => $slug, 'scope' => 'tenant', 'tenant_id' => $tenant->id, 'category' => 'organization']);
            DB::table('role_has_permissions')->updateOrInsert([
                'role_id' => $role->id, 'permission_id' => $permission->id,
            ], []);
        }
        DB::table('user_has_roles')->updateOrInsert(['user_id' => $user->id, 'role_id' => $role->id], ['assigned_at' => now(), 'assigned_by' => $user->id]);
        return [$tenant, $user];
    }
}