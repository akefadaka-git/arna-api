<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutiveOverviewRouteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ceo_can_open_the_overview_but_receives_no_finance_or_hidden_widget_metadata(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $entryPermission = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $ceo = Role::create([
            'name_am' => 'fixture',
            'name_en' => 'fixture',
            'slug' => 'ceo',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $ceo->permissions()->attach($entryPermission->id);
        $user->roles()->attach($ceo->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.widgets', [])
            ->assertJsonMissingPath('data.omitted_widgets')
            ->assertJsonMissingPath('data.unavailable_widgets');
        $this->assertStringNotContainsString('shareholder', $response->getContent());
        $this->assertStringNotContainsString('governance', $response->getContent());
    }

    #[Test]
    public function finance_manager_receives_only_authorized_entities_with_a_configured_ledger_and_source_lineage(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $financeApp = PlatformApplication::create([
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
            'application_id' => $financeApp->id,
            'is_active' => true,
        ]);

        $permissions = [];
        foreach ([
            'dashboard.executive.view',
            'finance.report.view',
            'finance.entity.view',
        ] as $slug) {
            $permissions[] = Permission::factory()->create([
                'slug' => $slug,
                'scope' => 'tenant',
                'tenant_id' => null,
                'is_active' => true,
                'is_system' => false,
            ]);
        }
        $role->permissions()->attach(array_map(static fn (Permission $permission): int => (int) $permission->id, $permissions));
        $user->roles()->attach($role->id);

        $configuredEntity = LegalEntity::create([
            'tenant_id' => $tenant->id,
            'code' => 'ENTITY-READY',
            'name_am' => 'የተዘጋጀ ሕጋዊ አካል',
            'name_en' => 'Configured Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
        $unconfiguredEntity = LegalEntity::create([
            'tenant_id' => $tenant->id,
            'code' => 'ENTITY-EMPTY',
            'name_am' => 'ያልተዘጋጀ ሕጋዊ አካል',
            'name_en' => 'Unconfigured Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $configuredEntity->id,
            'code' => '1000',
            'name_am' => 'ጥሬ ገንዘብ',
            'name_en' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.widgets')
            ->assertJsonPath('data.widgets.0.key', 'finance.trial_balance')
            ->assertJsonPath('data.widgets.0.source.domain', 'finance')
            ->assertJsonPath('data.widgets.0.source.report_key', 'trial_balance')
            ->assertJsonPath('data.widgets.0.source.calculation_version', '1')
            ->assertJsonPath('data.widgets.0.entity.code', 'ENTITY-READY')
            ->assertJsonPath('data.widgets.0.currency', 'ETB')
            ->assertJsonMissing(['code' => 'ENTITY-EMPTY']);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    #[Test]
    public function generic_permission_denial_does_not_disclose_the_required_permission_slug(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('hasPermission')
            ->once()
            ->with('finance.payroll.secret.view', $user)
            ->andReturnFalse();

        $request = Request::create('/api/private-resource', 'GET');
        $request->setUserResolver(static fn () => $user);

        $response = (new CheckPermission($authorization))->handle(
            $request,
            static fn () => response()->noContent(),
            'finance.payroll.secret.view'
        );

        $this->assertSame(403, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('AUTH_PERMISSION_DENIED', $payload['error']['code']);
        $this->assertSame(
            'You are not authorized to perform this action.',
            $payload['error']['message']
        );
        $this->assertStringNotContainsString('finance.payroll.secret.view', $response->getContent());
    }

    #[Test]
    public function a_direct_entry_permission_does_not_admit_an_unapproved_role(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->tenants()->attach($tenant->id);

        $entryPermission = Permission::factory()->create([
            'slug' => 'dashboard.executive.view',
            'scope' => 'tenant',
            'tenant_id' => null,
            'is_active' => true,
        ]);
        $user->permissions()->attach($entryPermission->id);

        $manager = Role::create([
            'name_am' => 'fixture',
            'name_en' => 'fixture',
            'slug' => 'manager',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $user->roles()->attach($manager->id);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->getJson('/api/dashboard/v1/overview');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }
}
