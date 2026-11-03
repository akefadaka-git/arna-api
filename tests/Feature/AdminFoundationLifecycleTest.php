<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Config;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Role;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\TenantMembership;
use MaaleArna\\MASES\\Core\\Tenancy\\Services\\RequestTenantResolver;
use Tests\\TestCase;

final class AdminFoundationLifecycleTest extends TestCase
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

    public function test_admin_can_create_central_identity_with_active_tenant_membership_and_audit(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('user.create');

        $response = $this->asTenantAdmin()->postJson('/api/admin/identity/users', [
            'email' => 'new.member@example.test',
            'first_name_am' => 'አዲስ',
            'password' => 'A-Strong-Temporary-Password-2026',
            'password_confirmation' => 'A-Strong-Temporary-Password-2026',
        ]);

        $response->assertCreated()
            ->assertJsonMissingPath('data.password');

        $userId = (int) $response->json('data.id');

        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $userId,
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.identity.user_created',
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->actor->id,
        ]);
    }

    public function test_admin_can_assign_only_roles_whose_permissions_the_actor_holds(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('user.assign_roles');
        $permission = $this->grant('finance.report.view');

        $member = User::factory()->create(['is_active' => true]);
        $member->tenants()->attach($this->tenant->id, [
            'joined_at' => now(),
            'status' => TenantMembership::STATUS_ACTIVE,
        ]);

        $role = $this->tenantRole('finance-report-reader');
        $role->permissions()->attach($permission->id, ['assigned_at' => now()]);

        $this->asTenantAdmin()
            ->putJson('/api/admin/identity/users/' . $member->id . '/roles', [
                'role_ids' => [$role->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.role_ids.0', $role->id);

        $this->assertDatabaseHas('user_has_roles', [
            'user_id' => $member->id,
            'role_id' => $role->id,
            'assigned_by' => $this->actor->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.rbac.user_roles_synced',
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->actor->id,
        ]);
    }

    public function test_admin_cannot_grant_role_permissions_the_actor_does_not_hold(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('role.assign_permissions');
        $permission = Permission::factory()->create([
            'slug' => 'finance.payroll.approve',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role = $this->tenantRole('finance-payroll-approver');

        $this->asTenantAdmin()
            ->putJson('/api/admin/authorization/roles/' . $role->id . '/permissions', [
                'permission_ids' => [$permission->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_admin_can_sync_role_permissions_and_audit_the_before_and_after_sets(): void
    {
        $this->grant('app.admin.access', 'global');
        $this->grant('role.assign_permissions');
        $permission = $this->grant('finance.report.view');
        $role = $this->tenantRole('finance-report-reviewer');

        $this->asTenantAdmin()
            ->putJson('/api/admin/authorization/roles/' . $role->id . '/permissions', [
                'permission_ids' => [$permission->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.permission_ids.0', $permission->id);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.rbac.role_permissions_synced',
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->actor->id,
        ]);
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

    private function tenantRole(string $slug): Role
    {
        return Role::query()->create([
            'slug' => $slug,
            'name_en' => ucwords(str_replace('-', ' ', $slug)),
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
    }
}
