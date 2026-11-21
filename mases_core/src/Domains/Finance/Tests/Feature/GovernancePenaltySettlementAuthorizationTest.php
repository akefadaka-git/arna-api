<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GovernancePenaltySettlementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $financeViewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'=>'finance-settlement-auth',
            'name_am'=>'የፋይናንስ ሙከራ',
            'name_en'=>'Finance Settlement Auth',
            'domain'=>'finance-settlement-auth.test',
            'is_active'=>true,
        ]);

        $permission = Permission::create([
            'slug'=>'finance.account.view',
            'name_am'=>'Finance account view',
            'name_en'=>'Finance account view',
            'description_am'=>'Finance account view',
            'description_en'=>'Finance account view',
            'category'=>'finance',
            'scope'=>'tenant',
            'tenant_id'=>null,
            'application_id'=>null,
            'is_active'=>true,
            'is_system'=>true,
        ]);

        $this->financeViewer = User::factory()->create();
        $this->financeViewer->tenants()->attach($this->tenant->id,['joined_at'=>now()]);
        $this->financeViewer->permissions()->attach($permission->id);
        $this->financeViewer->load('permissions');

        $this->app->instance('tenant',$this->tenant);
        $this->app->instance('mases.tenant',$this->tenant);
        $this->app->instance('current_tenant',$this->tenant);
    }

    #[Test]
    public function finance_account_view_permission_allows_settlement_configuration_read(): void
    {
        Sanctum::actingAs($this->financeViewer,['*']);

        $this->withHeaders(['X-Tenant-ID'=>(string)$this->tenant->id])
            ->getJson(route('api.finance.governance-settlement.account-mappings.index'))
            ->assertOk()
            ->assertJsonPath('success',true);
    }

    #[Test]
    public function legacy_account_manage_permission_does_not_grant_settlement_configuration_approval_or_execution(): void
    {
        $permissions = [];
        foreach (['finance.account.manage', 'finance.entity.manage', 'finance.journal.post'] as $slug) {
            $permissions[] = Permission::create([
                'slug'=>$slug,
                'name_am'=>$slug,
                'name_en'=>$slug,
                'description_am'=>$slug,
                'description_en'=>$slug,
                'category'=>'finance',
                'scope'=>'tenant',
                'tenant_id'=>null,
                'application_id'=>null,
                'is_active'=>true,
                'is_system'=>true,
            ]);
        }
        $legacyManager = User::factory()->create();
        $legacyManager->tenants()->attach($this->tenant->id,['joined_at'=>now()]);
        $legacyManager->permissions()->attach(array_map(static fn ($p) => $p->id, $permissions));
        $legacyManager->load('permissions');
        Sanctum::actingAs($legacyManager,['*']);

        $headers=['X-Tenant-ID'=>(string)$this->tenant->id];
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.store'),[])
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.approve',['mappingId'=>999]),[])
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.settlements.cash',['settlementId'=>999]),[])
            ->assertForbidden();
    }

    #[Test]
    public function settlement_configuration_approval_and_execution_permissions_are_independent(): void
    {
        $configure = Permission::create([
            'slug'=>'finance.governance_settlement.configure','name_am'=>'Configure','name_en'=>'Configure',
            'description_am'=>'Configure','description_en'=>'Configure','category'=>'finance','scope'=>'tenant',
            'tenant_id'=>null,'application_id'=>null,'is_active'=>true,'is_system'=>true,
        ]);
        $approve = Permission::create([
            'slug'=>'finance.governance_settlement.approve','name_am'=>'Approve','name_en'=>'Approve',
            'description_am'=>'Approve','description_en'=>'Approve','category'=>'finance','scope'=>'tenant',
            'tenant_id'=>null,'application_id'=>null,'is_active'=>true,'is_system'=>true,
        ]);
        $execute = Permission::create([
            'slug'=>'finance.governance_settlement.execute','name_am'=>'Execute','name_en'=>'Execute',
            'description_am'=>'Execute','description_en'=>'Execute','category'=>'finance','scope'=>'tenant',
            'tenant_id'=>null,'application_id'=>null,'is_active'=>true,'is_system'=>true,
        ]);
        $headers=['X-Tenant-ID'=>(string)$this->tenant->id];

        $configurator=User::factory()->create();
        $configurator->tenants()->attach($this->tenant->id,['joined_at'=>now()]);
        $configurator->permissions()->attach($configure->id);
        $configurator->load('permissions');
        Sanctum::actingAs($configurator,['*']);
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.store'),[])
            ->assertUnprocessable();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.approve',['mappingId'=>999]),[])
            ->assertForbidden();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.settlements.cash',['settlementId'=>999]),[])
            ->assertForbidden();

        $approver=User::factory()->create();
        $approver->tenants()->attach($this->tenant->id,['joined_at'=>now()]);
        $approver->permissions()->attach($approve->id);
        $approver->load('permissions');
        Sanctum::actingAs($approver,['*']);
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.approve',['mappingId'=>999]),[])
            ->assertNotFound();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.store'),[])
            ->assertForbidden();

        $executor=User::factory()->create();
        $executor->tenants()->attach($this->tenant->id,['joined_at'=>now()]);
        $executor->permissions()->attach($execute->id);
        $executor->load('permissions');
        Sanctum::actingAs($executor,['*']);
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.settlements.cash',['settlementId'=>999]),[])
            ->assertUnprocessable();
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.approve',['mappingId'=>999]),[])
            ->assertForbidden();
    }

    #[Test]
    public function finance_account_viewer_cannot_create_or_approve_account_mappings(): void
    {
        Sanctum::actingAs($this->financeViewer,['*']);

        $headers=['X-Tenant-ID'=>(string)$this->tenant->id];
        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.store'),[])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson(route('api.finance.governance-settlement.account-mappings.approve',['mappingId'=>999]),[])
            ->assertForbidden();
    }
}
