<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutiveOverviewA4FreshAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function financeFixture(Tenant $tenant): array
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $application = PlatformApplication::create([
            'name_am' => 'Finance',
            'name_en' => 'Finance',
            'slug' => 'finance',
            'domain' => 'finance.example.test',
            'is_active' => true,
        ]);
        $role = Role::create([
            'name_am' => 'Finance Manager',
            'name_en' => 'Finance Manager',
            'slug' => 'finance_manager',
            'scope' => 'application',
            'tenant_id' => $tenant->id,
            'application_id' => $application->id,
            'is_active' => true,
        ]);

        $permissions = [];
        foreach (['dashboard.executive.view', 'finance.report.view', 'finance.entity.view'] as $slug) {
            $permissions[$slug] = Permission::factory()->create([
                'slug' => $slug,
                'scope' => 'tenant',
                'tenant_id' => null,
                'is_active' => true,
                'is_system' => false,
            ]);
        }
        $role->permissions()->attach(array_map(
            static fn (Permission $permission): int => (int) $permission->id,
            array_values($permissions)
        ));
        $user->roles()->attach($role->id);

        return [$user, $role, $permissions];
    }

    private function configuredEntity(Tenant $tenant): LegalEntity
    {
        $entity = LegalEntity::create([
            'tenant_id' => $tenant->id,
            'code' => 'A4-ENTITY',
            'name_am' => 'የሙከራ ሕጋዊ አካል',
            'name_en' => 'A4 Test Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
        LedgerAccount::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => '1000',
            'name_am' => 'ጥሬ ገንዘብ',
            'name_en' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);
        return $entity;
    }

    #[Test]
    public function finance_report_revocation_is_effective_even_when_a_stale_positive_cache_entry_exists(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, $role, $permissions] = $this->financeFixture($tenant);
        $this->configuredEntity($tenant);

        $authorization = new AuthorizationService();
        $this->assertTrue($authorization->hasPermission(
            'finance.report.view',
            $user,
            (int) $tenant->id
        ));

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');
        $first->assertOk()->assertJsonCount(1, 'data.widgets');

        // Deliberately demonstrate that the legacy general-purpose cache
        // still contains a positive answer, then prove the Executive
        // Overview path rechecks authoritative Finance pivots.
        $role->revokePermissionTo($permissions['finance.report.view']);
        $this->assertTrue($authorization->hasPermission(
            'finance.report.view',
            $user,
            (int) $tenant->id
        ));

        $afterRevocation = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $afterRevocation->assertOk()->assertJsonPath('data.widgets', []);
    }

    #[Test]
    public function tenant_a_overview_never_includes_a_legal_entity_from_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        [$user] = $this->financeFixture($tenantA);
        $foreignEntity = $this->configuredEntity($tenantB);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()
            ->assertJsonPath('data.widgets', [])
            ->assertJsonMissing(['code' => $foreignEntity->code]);
    }
}
