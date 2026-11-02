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
