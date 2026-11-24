<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizationService $service;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthorizationService();

        $this->tenant = Tenant::factory()->create(['is_active' => true]);

        $this->user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->user->tenants()->attach($this->tenant->id);
    }

    // =========================================================================
    // hasPermission – correct order: slug first, user second
    // =========================================================================

    #[Test]
    public function it_returns_true_when_user_has_permission_globally(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'files.upload',
            'scope' => 'global',
            'tenant_id' => null,
        ]);

        $this->user->permissions()->attach($permission->id);

        $has = $this->service->hasPermission('files.upload', $this->user);
        $this->assertTrue($has);
    }

    #[Test]
    public function it_returns_true_when_user_has_permission_tenant_scoped(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'files.upload',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        $has = $this->service->hasPermission(
            'files.upload',
            $this->user,
            $this->tenant->id
        );
        $this->assertTrue($has);
    }

    #[Test]
    public function it_returns_false_when_user_lacks_permission(): void
    {
        $has = $this->service->hasPermission('non.existent', $this->user);
        $this->assertFalse($has);
    }

    #[Test]
    public function it_returns_false_when_permission_exists_but_not_assigned(): void
    {
        Permission::factory()->create(['slug' => 'files.upload']);

        $has = $this->service->hasPermission('files.upload', $this->user);
        $this->assertFalse($has);
    }

    #[Test]
    public function it_checks_tenant_scoped_permission_with_wrong_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();

        $permission = Permission::factory()->create([
            'slug' => 'files.upload',
            'scope' => 'tenant',
            'tenant_id' => $otherTenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        $has = $this->service->hasPermission(
            'files.upload',
            $this->user,
            $this->tenant->id
        );
        $this->assertFalse($has);
    }

    #[Test]
    public function it_returns_true_when_user_has_permission_via_role(): void
    {
        // Create role manually (no factory needed)
        $role = Role::create(['slug' => 'editor', 'name' => 'Editor']);
        $permission = Permission::factory()->create(['slug' => 'files.upload']);
        $role->permissions()->attach($permission->id);

        $this->user->roles()->attach($role->id);

        $has = $this->service->hasPermission('files.upload', $this->user);
        $this->assertTrue($has);
    }

    #[Test]
    public function role_permission_scope_matrix_rejects_incompatible_grants(): void
    {
        $application = PlatformApplication::factory()->create();
        $tenantRole = Role::factory()->tenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'application_id' => null,
        ]);
        $applicationRole = Role::factory()->applicationScope()->create([
            'tenant_id' => null,
            'application_id' => $application->id,
        ]);
        $platformRole = Role::factory()->create([
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
        ]);

        $global = Permission::factory()->create([
            'scope' => 'global', 'tenant_id' => null, 'application_id' => null,
        ]);
        $tenantPermission = Permission::factory()->create([
            'scope' => 'tenant', 'tenant_id' => $this->tenant->id, 'application_id' => null,
        ]);
        $applicationPermission = Permission::factory()->create([
            'scope' => 'application', 'tenant_id' => null, 'application_id' => $application->id,
        ]);

        $tenantRole->syncPermissions([$global->id, $tenantPermission->id]);
        $applicationRole->syncPermissions([$global->id, $applicationPermission->id]);
        $platformRole->syncPermissions([$global->id]);

        $this->expectException(\DomainException::class);
        $tenantRole->syncPermissions([$applicationPermission->id]);
    }

    #[Test]
    public function numeric_string_permission_ids_are_normalized_as_ids(): void
    {
        $permission = Permission::factory()->create([
            'scope' => 'global', 'tenant_id' => null, 'application_id' => null,
        ]);
        $role = Role::factory()->create([
            'scope' => 'platform', 'tenant_id' => null, 'application_id' => null,
        ]);

        $role->syncPermissions([(string) $permission->id]);

        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
    }

    #[Test]
    public function clearing_user_cache_advances_generation_and_removes_stale_positive_result(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'cache.revocation.test',
            'scope' => 'global',
            'tenant_id' => null,
            'application_id' => null,
        ]);
        $this->user->permissions()->attach($permission->id);

        $this->assertTrue($this->service->hasPermission('cache.revocation.test', $this->user));
        $this->user->permissions()->detach($permission->id);
        $this->service->clearUserCache($this->user);

        $this->assertFalse($this->service->hasPermission('cache.revocation.test', $this->user));
    }

    #[Test]
    public function role_scope_matrix_is_enforced_again_at_runtime_for_legacy_pivots(): void
    {
        $application = PlatformApplication::factory()->create();

        $applicationPermission = Permission::factory()->create([
            'slug' => 'scope.application.permission',
            'scope' => 'application',
            'tenant_id' => null,
            'application_id' => $application->id,
        ]);
        $tenantPermission = Permission::factory()->create([
            'slug' => 'scope.tenant.permission',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
            'application_id' => null,
        ]);

        $tenantRole = Role::factory()->tenantScope()->create([
            'tenant_id' => $this->tenant->id,
            'application_id' => null,
        ]);
        $tenantRole->permissions()->attach($applicationPermission->id);
        $this->user->roles()->attach($tenantRole->id);

        $this->assertFalse($this->service->hasPermission(
            'scope.application.permission',
            $this->user,
            $this->tenant->id,
            $application->slug,
        ));

        $this->user->roles()->detach($tenantRole->id);
        $platformRole = Role::factory()->create([
            'scope' => 'platform',
            'tenant_id' => null,
            'application_id' => null,
        ]);
        $platformRole->permissions()->attach($tenantPermission->id);
        $this->user->roles()->attach($platformRole->id);

        $this->assertFalse($this->service->hasPermission(
            'scope.tenant.permission',
            $this->user,
            $this->tenant->id,
        ));

        $this->user->roles()->detach($platformRole->id);
        $applicationRole = Role::factory()->applicationScope()->create([
            'tenant_id' => null,
            'application_id' => $application->id,
        ]);
        $applicationRole->permissions()->attach($tenantPermission->id);
        $this->user->roles()->attach($applicationRole->id);

        $this->assertFalse($this->service->hasPermission(
            'scope.tenant.permission',
            $this->user,
            $this->tenant->id,
            $application->slug,
        ));

        $applicationRole->permissions()->sync([$applicationPermission->id]);
        $this->assertTrue($this->service->hasPermission(
            'scope.application.permission',
            $this->user,
            $this->tenant->id,
            $application->slug,
        ));
    }

    // =========================================================================
    // requirePermission – correct order: slug first, user second
    // =========================================================================

    #[Test]
    public function it_does_not_throw_exception_when_user_has_permission(): void
    {
        $permission = Permission::factory()->create(['slug' => 'files.upload']);
        $this->user->permissions()->attach($permission->id);

        $this->service->requirePermission('files.upload', $this->user);
        $this->assertTrue(true);
    }

    #[Test]
    public function it_throws_authorization_exception_when_user_lacks_permission(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service->requirePermission('files.upload', $this->user);
    }

    #[Test]
    public function it_throws_exception_with_tenant_context_when_missing(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'files.upload',
            'scope' => 'tenant',
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user->permissions()->attach($permission->id);

        // Correct tenant – no exception.
        $this->service->requirePermission('files.upload', $this->user, $this->tenant->id);
        $this->assertTrue(true);

        // Wrong tenant – should throw.
        $this->expectException(AuthorizationException::class);
        $this->service->requirePermission('files.upload', $this->user, 99999);
    }

    // =========================================================================
    // hasRole – correct order: slug first, user second
    // =========================================================================

    #[Test]
    public function it_returns_true_when_user_has_role(): void
    {
        $role = Role::create(['slug' => 'admin', 'name' => 'Admin']);
        $this->user->roles()->attach($role->id);

        $has = $this->service->hasRole('admin', $this->user);
        $this->assertTrue($has);
    }

    #[Test]
    public function it_returns_false_when_user_lacks_role(): void
    {
        $has = $this->service->hasRole('admin', $this->user);
        $this->assertFalse($has);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    #[Test]
    public function it_handles_null_tenant_gracefully_for_global_permissions(): void
    {
        $permission = Permission::factory()->create([
            'slug' => 'files.upload',
            'scope' => 'global',
            'tenant_id' => null,
        ]);

        $this->user->permissions()->attach($permission->id);

        $has = $this->service->hasPermission('files.upload', $this->user, null);
        $this->assertTrue($has);

        $has = $this->service->hasPermission('files.upload', $this->user, $this->tenant->id);
        $this->assertTrue($has);
    }

    #[Test]
    public function it_handles_missing_user_gracefully(): void
    {
        $has = $this->service->hasPermission('files.upload', null);
        $this->assertFalse($has);

        $this->expectException(AuthorizationException::class);
        $this->service->requirePermission('files.upload', null);
    }
}