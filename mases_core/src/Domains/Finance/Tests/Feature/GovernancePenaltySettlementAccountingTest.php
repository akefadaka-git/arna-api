<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalSourceEntityMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceEntityMappingResolver;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GovernancePenaltySettlementAccountingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function partial_cash_settlements_post_balanced_accrual_and_receipt_journals_and_replay_idempotently(): void
    {
        [$tenant,$entity,$maker,$checker,$accounts,$meetingId,$attendanceId,$penaltyId] = $this->fixture();
        $service = $this->settlementService();

        $first = $this->createSettlement((int)$tenant->id,$penaltyId,(int)$maker->id,2000,'cash');
        $this->approveSource((int)$tenant->id,(int)$entity->id,$first,(int)$checker->id);
        $firstResult = $service->executeCashSettlement((int)$tenant->id,$first,(int)$checker->id,today()->toDateString(),'cash-settlement-first');
        $this->assertFalse($firstResult['idempotent_replay']);
        $this->assertSame('assessed',DB::table('governance_meeting_penalties')->where('id',$penaltyId)->value('status'));

        $replay = $service->executeCashSettlement((int)$tenant->id,$first,(int)$checker->id,today()->toDateString(),'cash-settlement-first');
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($firstResult['journal_id'],$replay['journal_id']);

        $second = $this->createSettlement((int)$tenant->id,$penaltyId,(int)$maker->id,3000,'cash');
        $this->approveSource((int)$tenant->id,(int)$entity->id,$second,(int)$checker->id);
        $secondResult = $service->executeCashSettlement((int)$tenant->id,$second,(int)$checker->id,today()->toDateString(),'cash-settlement-second');

        $this->assertSame('settled',DB::table('governance_meeting_penalties')->where('id',$penaltyId)->value('status'));
        $this->assertSame(5000,(int)DB::table('governance_penalty_settlements')->where('penalty_id',$penaltyId)->where('status','settled')->sum('amount_cents'));

        $journals = DB::table('journal_entries')
            ->where('tenant_id',$tenant->id)
            ->where('source_type',JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value)
            ->whereIn('source_id',[(string)$first,(string)$second])
            ->where('status','posted')->get();
        $this->assertCount(4,$journals);

        foreach ($journals as $journal) {
            $totals = DB::table('journal_lines')->where('journal_entry_id',$journal->id)
                ->selectRaw('SUM(debit_amount) AS debits, SUM(credit_amount) AS credits')->first();
            $this->assertSame(0,bccomp((string)$totals->debits,(string)$totals->credits,4));
            $this->assertSame(0,bccomp((string)$totals->debits,'20.0000',4) === 0 || bccomp((string)$totals->debits,'30.0000',4) === 0 ? 0 : 1);
        }

        $reportPermission = Permission::query()->create([
            'slug'=>'finance.report.view','name_am'=>'Finance report view','name_en'=>'Finance report view',
            'description_am'=>'Finance report view','description_en'=>'Finance report view','category'=>'finance',
            'scope'=>'tenant','tenant_id'=>null,'application_id'=>null,'is_active'=>true,'is_system'=>true,
        ]);
        $maker->tenants()->attach($tenant->id,['joined_at'=>now()]);
        $maker->permissions()->attach($reportPermission->id);
        $maker->load('permissions');
        $this->app->instance('tenant',$tenant);
        $this->app->instance('mases.tenant',$tenant);
        $this->app->instance('current_tenant',$tenant);
        Sanctum::actingAs($maker,['*']);
        $reconciliation = $this->withHeaders(['X-Tenant-ID'=>(string)$tenant->id])
            ->getJson(route('api.finance.governance-settlement.reconciliation').'?legal_entity_id='.$entity->id)
            ->assertOk()->assertJsonCount(4,'data');
        foreach ($reconciliation->json('data') as $row) {
            $this->assertSame('VERIFIED',$row['reconciliation_status']);
            $this->assertTrue($row['journal_balanced']);
            $this->assertTrue($row['journal_amount_matches']);
            $this->assertSame(2,(int)$row['source_journal_count']);
        }
    }

    private function fixture(): array
    {
        $tenant=Tenant::factory()->create(['is_active'=>true]);
        $maker=User::factory()->create();
        $checker=User::factory()->create();
        $entity=LegalEntity::query()->create(['tenant_id'=>$tenant->id,'code'=>'GOV-FIN','name_en'=>'Governance Finance','base_currency'=>'ETB','status'=>'active']);
        AccountingPeriod::query()->create(['tenant_id'=>$tenant->id,'legal_entity_id'=>$entity->id,'code'=>'FY-TEST','starts_on'=>today()->subYear()->toDateString(),'ends_on'=>today()->addYear()->toDateString(),'status'=>'open']);

        $accounts=[];
        $definitions=[
            GovernanceSettlementAccountMapping::ROLE_FINE_RECEIVABLE=>['1200','Fine receivable','asset','debit'],
            GovernanceSettlementAccountMapping::ROLE_CASH_BANK=>['1000','Cash at bank','asset','debit'],
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE=>['2100','Dividend payable','liability','credit'],
            GovernanceSettlementAccountMapping::ROLE_PENALTY_INCOME=>['4900','Penalty income','revenue','credit'],
        ];
        foreach($definitions as $role=>$definition){
            $account=LedgerAccount::query()->create([
                'tenant_id'=>$tenant->id,'legal_entity_id'=>$entity->id,'code'=>$definition[0],
                'name_en'=>$definition[1],'account_type'=>$definition[2],'normal_balance'=>$definition[3],
                'is_posting_allowed'=>true,'status'=>'active',
            ]);
            $accounts[$role]=$account;
            GovernanceSettlementAccountMapping::query()->create([
                'tenant_id'=>$tenant->id,'legal_entity_id'=>$entity->id,'account_role'=>$role,
                'ledger_account_id'=>$account->id,'status'=>GovernanceSettlementAccountMapping::STATUS_APPROVED,
                'configuration_reason'=>'Approved accounting fixture','created_by'=>$maker->id,
                'approved_by'=>$checker->id,'approved_at'=>now(),
            ]);
        }

        $body=GovernanceBody::query()->create([
            'tenant_id'=>$tenant->id,'code'=>'FIN-BOARD','body_type'=>'board',
            'name_am'=>'የፋይናንስ ቦርድ','name_en'=>'Finance Board','is_active'=>true,
        ]);
        $meetingId=(int)DB::table('governance_meetings')->insertGetId([
            'tenant_id'=>$tenant->id,'governance_body_id'=>$body->id,'meeting_code'=>'FIN-MTG-001',
            'meeting_kind'=>'board','starts_at'=>now()->subDay()->toDateTimeString(),'status'=>'completed',
            'created_by'=>$maker->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $shareholderId=(int)DB::table('shareholders')->insertGetId([
            'tenant_id'=>$tenant->id,'shareholder_number'=>'SH-FIN-001','shareholder_type'=>'individual',
            'first_name_en'=>'Settlement Shareholder','is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $attendanceId=(int)DB::table('governance_meeting_attendance')->insertGetId([
            'tenant_id'=>$tenant->id,'meeting_id'=>$meetingId,'subject_type'=>'shareholder','subject_id'=>$shareholderId,
            'attendance_status'=>'late','recorded_by'=>$maker->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $penaltyId=(int)DB::table('governance_meeting_penalties')->insertGetId([
            'tenant_id'=>$tenant->id,'meeting_id'=>$meetingId,'attendance_id'=>$attendanceId,
            'penalty_type'=>'late','amount_cents'=>5000,'currency'=>'ETB','status'=>'assessed',
            'assessed_by'=>$maker->id,'assessed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);

        return [$tenant,$entity,$maker,$checker,$accounts,$meetingId,$attendanceId,$penaltyId];
    }

    private function createSettlement(int $tenantId,int $penaltyId,int $actorId,int $cents,string $method): int
    {
        return (int)DB::table('governance_penalty_settlements')->insertGetId([
            'tenant_id'=>$tenantId,'penalty_id'=>$penaltyId,'method'=>$method,'amount_cents'=>$cents,
            'currency'=>'ETB','status'=>'pending_finance','recorded_by'=>$actorId,'recorded_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function approveSource(int $tenantId,int $entityId,int $settlementId,int $checkerId): void
    {
        DB::table('journal_source_entity_mappings')->insert([
            'tenant_id'=>$tenantId,'legal_entity_id'=>$entityId,
            'source_type'=>JournalSourceType::GOVERNANCE_PENALTY_SETTLEMENT->value,'source_id'=>(string)$settlementId,
            'status'=>JournalSourceEntityMapping::STATUS_APPROVED,'approved_by'=>$checkerId,'approved_at'=>now(),
            'mapping_reason'=>'Approved settlement source mapping','created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function settlementService(): GovernancePenaltySettlementService
    {
        $resolver=app(JournalSourceEntityMappingResolver::class);
        $registry=new JournalSourceReferenceResolverRegistry([$resolver]);
        return new GovernancePenaltySettlementService(new JournalEntryService($registry));
    }
}
