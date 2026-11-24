<?php

declare(strict_types=1);

namespace Tests\\Feature;

use DomainException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Permission;
use MaaleArna\\MASES\\Core\\Authorization\\Models\\Role;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;
use Tests\\TestCase;

final class RolePermissionScopeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_role_accepts_permission_bound_to_the_same_tenant(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'name_en' => 'Tenant Operator',
            'slug' => 'tenant-operator-' . $tenant->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $permission = Permission::query()->create([
            'name_en' => 'View Tenant Records',
            'slug' => 'tenant.records.view.' . $tenant->id,
            'category' => 'test',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);

        $role->syncPermissions([$permission->id]);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_tenant_role_rejects_permission_bound_to_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'name_en' => 'Tenant A Operator',
            'slug' => 'tenant-a-operator-' . $tenantA->id,
            'scope' => 'tenant',
            'tenant_id' => $tenantA->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $permission = Permission::query()->create([
            'name_en' => 'Tenant B Records',
            'slug' => 'tenant-b.records.view.' . $tenantB->id,
            'category' => 'test',
            'scope' => 'tenant',
            'tenant_id' => $tenantB->id,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);

        try {
            $role->syncPermissions([$permission->id]);
            $this->fail('Cross-tenant role-permission assignment must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('same tenant', $exception->getMessage());
        }

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }
}
