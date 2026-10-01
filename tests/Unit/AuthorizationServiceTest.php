<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
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