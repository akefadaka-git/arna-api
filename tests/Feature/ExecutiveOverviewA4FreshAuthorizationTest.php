<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinancialReportContract;
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

    private function configuredEntity(Tenant $tenant, string $code = 'A4-ENTITY'): LegalEntity
    {
        $entity = LegalEntity::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
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
        // Reinsert a stale positive cache result to model an invalidation
        // listener/cache-store failure after the authoritative pivot changed.
        $staleKey = sprintf(
            'auth:permission:%d:finance.report.view:%s:null',
            $user->id,
            $tenant->id
        );
        Cache::put($staleKey, true, 300);
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
    public function revoked_entry_permission_fails_closed_even_when_middleware_cache_is_stale(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, $role, $permissions] = $this->financeFixture($tenant);
        $authorization = new AuthorizationService();

        $this->assertTrue($authorization->hasPermission(
            'dashboard.executive.view',
            $user,
            (int) $tenant->id
        ));

        $role->revokePermissionTo($permissions['dashboard.executive.view']);
        $staleKey = sprintf(
            'auth:permission:%d:dashboard.executive.view:%s:null',
            $user->id,
            $tenant->id
        );
        Cache::put($staleKey, true, 300);

        // The generic route middleware sees the deliberately stale positive
        // cache entry; the controller's uncached audience policy must still
        // deny the request using the current role-permission pivot.
        $this->assertTrue($authorization->hasPermission(
            'dashboard.executive.view',
            $user,
            (int) $tenant->id
        ));

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
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

    #[Test]
    public function mixed_entity_overview_returns_only_current_tenant_entities_and_keeps_each_summary_separate(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        [$user] = $this->financeFixture($tenantA);

        $entityA1 = $this->configuredEntity($tenantA, 'A4-AUTHORIZED-01');
        $entityA2 = $this->configuredEntity($tenantA, 'A4-AUTHORIZED-02');
        $foreignEntity = $this->configuredEntity($tenantB, 'A4-FOREIGN-99');

        $expectedIds = [(int) $entityA1->id, (int) $entityA2->id];
        $reports = new \Illuminate\Support\Collection([(object) ['id' => 1]]);
        $reportService = \Mockery::mock(FinancialReportContract::class);
        $reportService->shouldReceive('trialBalance')
            ->twice()
            ->withArgs(static fn (int $tenantId, int $entityId, string $asOf): bool =>
                $tenantId === (int) $tenantA->id && in_array($entityId, $expectedIds, true)
            )
            ->andReturnUsing(static function (int $tenantId, int $entityId, string $asOf) use ($reports, $expectedIds): array {
                $debit = $entityId === $expectedIds[0] ? '111.00' : '222.00';
                return [
                    'as_of' => $asOf,
                    'currency' => 'ETB',
                    'accounts' => $reports,
                    'summary' => [
                        'total_debits' => $debit,
                        'total_credits' => $debit,
                        'difference' => '0.00',
                        'is_balanced' => true,
                    ],
                ];
            });
        app()->instance(FinancialReportContract::class, $reportService);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()
            ->assertJsonCount(2, 'data.widgets')
            ->assertJsonPath('data.widgets.0.entity.code', 'A4-AUTHORIZED-01')
            ->assertJsonPath('data.widgets.0.value.total_debits', '111.00')
            ->assertJsonPath('data.widgets.1.entity.code', 'A4-AUTHORIZED-02')
            ->assertJsonPath('data.widgets.1.value.total_debits', '222.00')
            ->assertJsonMissing(['code' => 'A4-FOREIGN-99']);
    }

    #[Test]
    public function direct_finance_report_grant_is_honoured_when_the_role_grant_is_absent(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, $role, $permissions] = $this->financeFixture($tenant);
        $this->configuredEntity($tenant);

        $role->revokePermissionTo($permissions['finance.report.view']);
        $user->permissions()->attach($permissions['finance.report.view']->id, [
            'assigned_at' => now(),
            'assigned_by' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()->assertJsonCount(1, 'data.widgets');
    }

    #[Test]
    public function inactive_finance_permission_is_not_authorized_even_when_a_role_pivot_remains(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, , $permissions] = $this->financeFixture($tenant);
        $this->configuredEntity($tenant);
        $permissions['finance.report.view']->update(['is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()->assertJsonPath('data.widgets', []);
    }

    #[Test]
    public function inactive_finance_manager_role_fails_the_frozen_audience_gate(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, $role] = $this->financeFixture($tenant);
        $role->update(['is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden();
    }

    #[Test]
    public function finance_manager_role_bound_to_another_application_fails_the_frozen_audience_gate(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user, $role] = $this->financeFixture($tenant);
        $otherApplication = PlatformApplication::create([
            'name_am' => 'Human Resources',
            'name_en' => 'Human Resources',
            'slug' => 'human-resources',
            'domain' => 'hr.example.test',
            'is_active' => true,
        ]);
        $role->update(['application_id' => $otherApplication->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden();
    }

    #[Test]
    public function finance_manager_role_bound_to_another_tenant_fails_the_frozen_audience_gate(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        [$user, $role] = $this->financeFixture($tenantA);
        $role->update(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden();
    }

    #[Test]
    public function consolidation_rejects_a_foreign_tenant_entity_before_any_aggregate_is_returned(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        $entityA = $this->configuredEntity($tenantA);
        $foreignEntity = $this->configuredEntity($tenantB);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\MaaleArna\MASES\Core\Domains\Finance\Services\FinanceConsolidationService::class)
            ->trialBalance((int) $tenantA->id, [(int) $entityA->id, (int) $foreignEntity->id], now()->toDateString());
    }


    #[Test]
    public function finance_report_permission_bound_to_another_tenant_is_not_used_for_this_tenant(): void
    {
        $tenantA = Tenant::factory()->create(['is_active' => true]);
        $tenantB = Tenant::factory()->create(['is_active' => true]);
        [$user, , $permissions] = $this->financeFixture($tenantA);
        $this->configuredEntity($tenantA);
        $permissions['finance.report.view']->update(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenantA->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()->assertJsonPath('data.widgets', []);
    }

    #[Test]
    public function inactive_finance_application_fails_the_frozen_audience_gate(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        [$user] = $this->financeFixture($tenant);
        PlatformApplication::query()->where('slug', 'finance')->firstOrFail()
            ->update(['is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden();
    }

}
