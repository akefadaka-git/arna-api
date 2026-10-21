<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserPermissionScopeTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $authorization;
    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorization = new AuthorizationService();
        $this->tenant = Tenant::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->user->tenants()->attach($this->tenant->id);

        // Each ordinary scoped-permission test runs with an explicit trusted
        // tenant context. Tests that exercise missing context clear it locally.
        app(TenantContextService::class)->setTenantById($this->tenant->id);
    }

    #[Test]
    public function tenant_scoped_permission_is_granted_only_for_its_tenant(): void
    {
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        $this->assertTrue(
            $this->authorization->hasPermission('records.view', $this->user, $this->tenant->id)
        );
        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $otherTenant->id)
        );
    }

    #[Test]
    public function explicit_tenant_scope_is_denied_when_it_differs_from_trusted_context(): void
    {
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'tenant',
            'tenant_id' => $otherTenant->id,
            'is_active' => true,
        ]);
        $role = Role::create([
            'slug' => 'tenant-admin',
            'name_en' => 'Tenant Admin',
            'scope' => 'tenant',
            'tenant_id' => $otherTenant->id,
            'is_active' => true,
        ]);
        $this->user->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $otherTenant->id)
        );
        $this->assertFalse(
            $this->authorization->hasRole('tenant-admin', $this->user, $otherTenant->id)
        );
    }

    private function simulateRoutedHttpRequestWithoutTenantContext(): void
    {
        $request = app('request');
        $route = new \Illuminate\Routing\Route(['GET'], '/authorization-scope-test', static fn () => null);

        $request->setRouteResolver(static fn () => $route);
    }

    #[Test]
    public function explicit_tenant_scope_is_denied_when_trusted_context_is_missing(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $role = Role::create([
            'slug' => 'tenant-admin',
            'name_en' => 'Tenant Admin',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $this->user->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);

        app(TenantContextService::class)->clearTenant();
        $this->simulateRoutedHttpRequestWithoutTenantContext();

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $this->tenant->id)
        );
        $this->assertFalse(
            $this->authorization->hasRole('tenant-admin', $this->user, $this->tenant->id)
        );
    }

    #[Test]
    public function tenant_scoped_permission_is_not_granted_without_tenant_context(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        app(TenantContextService::class)->clearTenant();
        $this->simulateRoutedHttpRequestWithoutTenantContext();

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user)
        );
    }

    #[Test]
    public function tenant_scoped_role_is_not_granted_without_tenant_context(): void
    {
        $role = Role::create([
            'slug' => 'tenant-admin',
            'name_en' => 'Tenant Admin',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $this->user->roles()->attach($role->id);
        app(TenantContextService::class)->clearTenant();
        $this->simulateRoutedHttpRequestWithoutTenantContext();

        $this->assertFalse(
            $this->authorization->hasRole('tenant-admin', $this->user),
            'A tenant-scoped role must not grant access when trusted tenant context is absent.'
        );
    }

    #[Test]
    public function tenant_scoped_permission_inherited_from_role_is_denied_without_tenant_context(): void
    {
        $role = Role::create([
            'slug' => 'tenant-records-viewer',
            'name_en' => 'Tenant Records Viewer',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);
        app(TenantContextService::class)->clearTenant();
        $this->simulateRoutedHttpRequestWithoutTenantContext();

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user)
        );
    }

    #[Test]
    public function inactive_permissions_and_roles_do_not_grant_access(): void
    {
        $role = Role::create([
            'slug' => 'inactive-records-viewer',
            'name_en' => 'Inactive Records Viewer',
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
        ]);
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => false,
        ]);

        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $this->tenant->id)
        );
    }

    #[Test]
    public function inactive_direct_permission_does_not_grant_access(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => false,
        ]);
        $this->user->permissions()->attach($permission->id);

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $this->tenant->id)
        );
    }

    #[Test]
    public function inactive_role_does_not_grant_an_active_permission(): void
    {
        $role = Role::create([
            'slug' => 'inactive-records-viewer',
            'name_en' => 'Inactive Records Viewer',
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => false,
        ]);
        $permission = Permission::factory()->create([
            'slug' => 'records.view',
            'scope' => 'global',
            'tenant_id' => null,
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id);
        $this->user->roles()->attach($role->id);

        $this->assertFalse(
            $this->authorization->hasPermission('records.view', $this->user, $this->tenant->id)
        );
    }
}
