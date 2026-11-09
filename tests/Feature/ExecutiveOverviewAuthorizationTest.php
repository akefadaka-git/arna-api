<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Data\PermissionCatalogue;
use Database\Seeders\Data\RoleCatalogue;
use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants;
use Database\Seeders\Data\RolePermissionGrants;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\ExecutiveOverviewAccessPolicy;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutiveOverviewAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function executive_permission_is_tenant_scoped_and_declared_for_exactly_the_frozen_roles(): void
    {
        $permission = collect(PermissionCatalogue::ROWS)
            ->firstWhere('slug', 'dashboard.executive.view');

        $this->assertNotNull($permission);
        $this->assertSame('tenant', $permission['scope']);
        $this->assertSame('dashboard', $permission['category']);
        $this->assertFalse($permission['is_system']);

        $allowed = ExecutiveOverviewAccessPolicy::ROLE_SLUGS;
        $this->assertSame(['ceo', 'president', 'finance_manager'], $allowed);

        foreach ($allowed as $slug) {
            $this->assertArrayHasKey($slug, RoleCatalogue::ROWS);
            $this->assertTrue(RoleCatalogue::ROWS[$slug]['tenant_bound']);
        }
        $this->assertSame('tenant', RoleCatalogue::ROWS['ceo']['scope']);
        $this->assertSame('tenant', RoleCatalogue::ROWS['president']['scope']);
        $this->assertSame('application', RoleCatalogue::ROWS['finance_manager']['scope']);
        $this->assertSame('finance', RoleCatalogue::ROWS['finance_manager']['application_slug']);

        $financeGrants = FinanceRolePermissionGrants::MAP;
        $this->assertContains('finance.report.view', $financeGrants['finance_manager']);
        $this->assertContains('finance.entity.view', $financeGrants['finance_manager']);
        $this->assertNotContains('finance.report.view', $financeGrants['ceo']);
        $this->assertNotContains('finance.report.view', $financeGrants['president']);
        $this->assertNotContains('finance.entity.view', $financeGrants['ceo']);
        $this->assertNotContains('finance.entity.view', $financeGrants['president']);

        foreach (RolePermissionGrants::MAP as $roleSlug => $grants) {
            $this->assertSame(
                in_array($roleSlug, $allowed, true),
                in_array('dashboard.executive.view', $grants, true),
                "Unexpected executive permission grant for role [{$roleSlug}]."
            );
        }
    }

    #[Test]
    public function wildcard_role_grants_cannot_inherit_the_executive_permission(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $roles = [];

        foreach (array_keys(RolePermissionGrants::MAP) as $slug) {
            $isPlatformRole = in_array($slug, ['super_admin', 'platform_admin'], true);
            $isApplicationRole = in_array($slug, ['finance_manager', 'hr_manager'], true);
            $roles[$slug] = Role::factory()->create([
                'slug' => $slug,
                'scope' => $isPlatformRole ? 'platform' : ($isApplicationRole ? 'application' : 'tenant'),
                'tenant_id' => $isPlatformRole ? null : $tenant->id,
                'is_active' => true,
            ]);
        }

        $permission = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'is_active' => true,
            'is_system' => false,
        ]);

        (new RolePermissionSeeder())->seed(
            $roles,
            ['dashboard.executive.view' => $permission],
        );

        foreach ($roles as $slug => $role) {
            $expected = in_array($slug, ExecutiveOverviewAccessPolicy::ROLE_SLUGS, true);
            $this->assertSame(
                $expected,
                $role->fresh()->hasPermission('dashboard.executive.view'),
                "Unexpected persisted executive permission state for role [{$slug}]."
            );
        }
    }

    #[Test]
    public function executive_audience_is_bound_to_the_current_tenant_role_assignment(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);

        $ceo = Role::factory()->create([
            'slug' => 'ceo',
            'scope' => 'tenant',
            'tenant_id' => $tenantA->id,
            'is_active' => true,
        ]);
        $user->roles()->attach($ceo->id);

        $policy = new ExecutiveOverviewAccessPolicy();

        $this->assertTrue($policy->allows($user, (int) $tenantA->id));
        $this->assertFalse($policy->allows($user, (int) $tenantB->id));
    }

    #[Test]
    public function finance_manager_must_be_bound_to_the_finance_application_and_current_tenant(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $finance = PlatformApplication::factory()->create(['slug' => 'finance']);
        $wrongApplication = PlatformApplication::factory()->create(['slug' => 'hr']);
        $user = User::factory()->create(['is_active' => true]);

        $validRole = Role::factory()->create([
            'slug' => 'finance_manager',
            'scope' => 'application',
            'tenant_id' => $tenant->id,
            'application_id' => $finance->id,
            'is_active' => true,
        ]);
        $user->roles()->attach($validRole->id);

        $policy = new ExecutiveOverviewAccessPolicy();
        $this->assertTrue($policy->allows($user, (int) $tenant->id));

        $user->roles()->detach($validRole->id);
        $wrongRole = Role::factory()->create([
            'slug' => 'finance_manager',
            'scope' => 'application',
            'tenant_id' => $tenant->id,
            'application_id' => $wrongApplication->id,
            'is_active' => true,
        ]);
        $user->roles()->attach($wrongRole->id);

        $this->assertFalse($policy->allows($user, (int) $tenant->id));
    }

    #[Test]
    public function direct_permission_or_platform_role_does_not_bypass_the_frozen_audience(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $platformAdmin = Role::factory()->create([
            'slug' => 'platform_admin',
            'scope' => 'platform',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $user->roles()->attach($platformAdmin->id);

        $directGrant = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'is_active' => true,
            'is_system' => false,
        ]);
        $user->permissions()->attach($directGrant->id);

        $this->assertFalse((new ExecutiveOverviewAccessPolicy())->allows($user, (int) $tenant->id));
    }
}
