<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Database\Seeders\Data\RolePermissionGrants;
use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

final class RolePermissionScopeCompatibilityTest extends TestCase
{
    use RefreshDatabase;


    public function test_finance_settlement_execution_is_explicit_and_excluded_from_scope_wildcards(): void
    {
        $this->assertContains(
            'finance.governance_settlement.execute',
            RolePermissionGrants::WILDCARD_EXCLUSIONS,
        );
        $this->assertContains(
            'finance.governance_settlement.execute.app',
            RolePermissionGrants::WILDCARD_EXCLUSIONS,
        );
        $this->assertContains(
            'finance.governance_settlement.execute',
            FinanceRolePermissionGrants::MAP['cashier'],
        );
        $this->assertContains(
            'finance.governance_settlement.execute.app',
            FinanceRolePermissionGrants::MAP['finance_manager'],
        );
        $this->assertNotContains(
            'finance.governance_settlement.execute',
            RolePermissionGrants::MAP['tenant_admin'],
        );
    }

    public function test_runtime_enforces_scope_matrix_for_roles_even_when_legacy_pivots_bypass_sync_validation(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'default_tenant_id' => $tenant->id]);
        $application = PlatformApplication::query()->create([
            'name_am' => 'Runtime test application',
            'name_en' => 'Runtime test application',
            'slug' => 'runtime-test-' . uniqid(),
            'domain' => 'runtime-' . uniqid() . '.example.test',
            'is_active' => true,
        ]);

        $global = Permission::query()->create([
            'name_am' => 'Global test permission',
            'name_en' => 'Global test permission',
            'slug' => 'scope.runtime.global.' . $tenant->id,
            'category' => 'test',
            'scope' => 'global',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $tenantPermission = Permission::query()->create([
            'name_am' => 'Tenant test permission',
            'name_en' => 'Tenant test permission',
            'slug' => 'scope.runtime.tenant.' . $tenant->id,
            'category' => 'test',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $applicationPermission = Permission::query()->create([
            'name_am' => 'Application test permission',
            'name_en' => 'Application test permission',
            'slug' => 'scope.runtime.application.' . $tenant->id,
            'category' => 'test',
            'scope' => 'application',
            'tenant_id' => $tenant->id,
            'application_id' => $application->id,
            'is_active' => true,
            'is_system' => false,
        ]);

        $tenantRole = Role::query()->create([
            'name_am' => 'Tenant runtime role',
            'name_en' => 'Tenant runtime role',
            'slug' => 'tenant-runtime-' . $tenant->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $tenantRole->permissions()->attach([$global->id, $applicationPermission->id]);
        $user->roles()->attach($tenantRole->id);

        $context = app(\MaaleArna\MASES\Core\Tenancy\Services\TenantContextService::class);
        $context->setTenantById((int) $tenant->id);
        $authorization = app(AuthorizationService::class);

        $this->assertTrue($authorization->hasPermission($global->slug, $user, $tenant->id));
        $this->assertFalse($authorization->hasPermission(
            $applicationPermission->slug,
            $user,
            $tenant->id,
            $application->slug,
        ));

        $user->roles()->detach($tenantRole->id);
        $authorization->clearUserCache($user);
        $platformRole = Role::query()->create([
            'name_am' => 'Platform runtime role',
            'name_en' => 'Platform runtime role',
            'slug' => 'platform-runtime-' . $tenant->id,
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
        ]);
        $platformRole->permissions()->attach($tenantPermission->id);
        $user->roles()->attach($platformRole->id);
        $authorization->clearUserCache($user);

        $this->assertFalse($authorization->hasPermission($tenantPermission->slug, $user, $tenant->id));

        $user->roles()->detach($platformRole->id);
        $authorization->clearUserCache($user);
        $applicationRole = Role::query()->create([
            'name_am' => 'Application runtime role',
            'name_en' => 'Application runtime role',
            'slug' => 'application-runtime-' . $tenant->id,
            'scope' => 'application',
            'tenant_id' => $tenant->id,
            'application_id' => $application->id,
            'is_active' => true,
        ]);
        $applicationRole->permissions()->attach($tenantPermission->id);
        $user->roles()->attach($applicationRole->id);
        $authorization->clearUserCache($user);

        $this->assertFalse($authorization->hasPermission(
            $tenantPermission->slug,
            $user,
            $tenant->id,
            $application->slug,
        ));

        $applicationRole->permissions()->sync([$applicationPermission->id]);
        $authorization->clearUserCache($user);
        $this->assertTrue($authorization->hasPermission(
            $applicationPermission->slug,
            $user,
            $tenant->id,
            $application->slug,
        ));
    }

    public function test_numeric_string_permission_ids_are_resolved_as_ids_not_slugs(): void
    {
        $permission = Permission::query()->create([
            'name_en' => 'Global numeric ID permission',
            'slug' => 'global.numeric.id.test',
            'category' => 'test',
            'scope' => 'global',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role = Role::query()->create([
            'name_en' => 'Platform numeric ID role',
            'slug' => 'platform-numeric-id-test',
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
        ]);

        $role->syncPermissions([(string) $permission->id]);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    public function test_tenant_role_can_use_shared_tenant_scope_catalogue_permission_template(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'name_en' => 'Tenant Catalogue Template Role',
            'slug' => 'tenant-catalogue-template-' . $tenant->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $permission = Permission::query()->create([
            'name_en' => 'Shared Tenant Permission Template',
            'slug' => 'tenant.template.view.' . $tenant->id,
            'category' => 'test',
            'scope' => 'tenant',
            'tenant_id' => null,
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

    public function test_tenant_role_can_include_a_global_permission_without_tenant_binding(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'name_en' => 'Tenant Manager',
            'slug' => 'tenant-manager-' . $tenant->id,
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'application_id' => null,
            'is_active' => true,
        ]);
        $permission = Permission::query()->create([
            'name_en' => 'View Own Profile',
            'slug' => 'user.profile.view.test.' . $tenant->id,
            'category' => 'user',
            'scope' => 'global',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => true,
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
            $this->assertStringContainsString('another tenant', $exception->getMessage());
        }

        $this->assertDatabaseMissing('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }
}
