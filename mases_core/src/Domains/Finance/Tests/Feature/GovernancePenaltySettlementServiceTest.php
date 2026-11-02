<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GovernancePenaltySettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function account_mapping_requires_valid_entity_scoped_posting_account_and_independent_approval(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'SETTLE',
            'name_en' => 'Settlement Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $accountId = (int) DB::table('ledger_accounts')->insertGetId([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => '1100',
            'name_en' => 'Fine Receivable',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_posting_allowed' => true,
            'status' => 'active',
            'is_control_account' => false,
            'is_system_account' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(GovernancePenaltySettlementService::class);
        $mapping = $service->requestAccountMapping(
            (int) $tenant->id,
            (int) $entity->id,
            GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE,
            $accountId,
            (int) $maker->id,
            'Approved chart-of-accounts mapping request.',
        );

        $this->assertSame(GovernanceSettlementAccountMapping::STATUS_PENDING, $mapping->status);
        try {
            $service->approveAccountMapping((int) $tenant->id, (int) $mapping->id, (int) $maker->id);
            $this->fail('The maker must not approve their own account mapping.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mapping', $exception->errors());
        }

        $approved = $service->approveAccountMapping((int) $tenant->id, (int) $mapping->id, (int) $checker->id);
        $this->assertSame(GovernanceSettlementAccountMapping::STATUS_APPROVED, $approved->status);
        $this->assertSame((int) $checker->id, (int) $approved->approved_by);
    }

    #[Test]
    public function dividend_payable_requires_a_real_tenant_shareholder_and_independent_approval(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'DIVIDEND',
            'name_en' => 'Dividend Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
        $shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id,
            'shareholder_number' => 'SH-DIV-001',
            'shareholder_type' => 'individual',
            'first_name_en' => 'Dividend Shareholder',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $service = app(GovernancePenaltySettlementService::class);
        $payable = $service->createDividendPayable(
            (int) $tenant->id,
            (int) $entity->id,
            $shareholderId,
            'GA-DIV-2026-001',
            today()->subDays(10)->toDateString(),
            today()->subDay()->toDateString(),
            '1250.0000',
            (int) $maker->id,
        );

        $this->assertSame('draft', $payable->status);
        try {
            $service->approveDividendPayable((int) $tenant->id, (int) $payable->id, (int) $maker->id, 'GA resolution 001');
            $this->fail('The payable creator must not approve their own dividend payable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payable', $exception->errors());
        }

        $approved = $service->approveDividendPayable((int) $tenant->id, (int) $payable->id, (int) $checker->id, 'GA resolution 001');
        $this->assertSame('payable', $approved->status);
        $this->assertTrue($approved->isEligibleForPenaltyOffset());
        $this->assertSame('1250.0000', $approved->remainingPayable());
    }

    #[Test]
    public function account_mapping_rejects_accounts_from_another_legal_entity(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entityA = LegalEntity::query()->create(['tenant_id'=>$tenant->id,'code'=>'ENTITY-A','name_en'=>'Entity A','base_currency'=>'ETB','status'=>'active']);
        $entityB = LegalEntity::query()->create(['tenant_id'=>$tenant->id,'code'=>'ENTITY-B','name_en'=>'Entity B','base_currency'=>'ETB','status'=>'active']);
        $actor = User::factory()->create();
        $accountId = (int) DB::table('ledger_accounts')->insertGetId([
            'tenant_id'=>$tenant->id,'legal_entity_id'=>$entityB->id,'code'=>'1101','name_en'=>'Other Entity Receivable',
            'account_type'=>'asset','normal_balance'=>'debit','is_posting_allowed'=>true,'status'=>'active',
            'is_control_account'=>false,'is_system_account'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
        try {
            app(GovernancePenaltySettlementService::class)->requestAccountMapping(
                (int)$tenant->id,(int)$entityA->id,GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE,
                $accountId,(int)$actor->id,'Must reject cross-entity account.',
            );
            $this->fail('Cross-entity ledger accounts must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ledger_account_id',$exception->errors());
        }
    }
}
