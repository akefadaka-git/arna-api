<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\DividendPaymentService;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DividendPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function payment_batch_requires_approval_posts_bank_journal_and_reconciles_against_statement(): void
    {
        [$tenant, $entity, $maker, $checker, $shareholderId, $distributionId] = $this->fixture();
        $settlements = new GovernancePenaltySettlementService(
            new JournalEntryService(new JournalSourceReferenceResolverRegistry([]))
        );
        $payable = $settlements->createDividendPayable(
            (int) $tenant->id, (int) $entity->id, $shareholderId,
            'GA-DIV-2026-001', today()->subDays(5)->toDateString(),
            today()->addDays(30)->toDateString(), '900.0000', (int) $maker->id
        );
        $settlements->approveDividendPayable((int) $tenant->id, (int) $payable->id, (int) $checker->id, 'Approved GA distribution');
        DB::table('finance_dividend_distribution_items')->insert([
            'tenant_id' => $tenant->id, 'distribution_id' => $distributionId,
            'shareholder_id' => $shareholderId, 'eligible_share_quantity' => '90.0000',
            'gross_amount' => '1000.0000', 'withholding_amount' => '100.0000',
            'net_amount' => '900.0000', 'payable_id' => $payable->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = $this->paymentService();
        $batch = $service->createBatch(
            (int) $tenant->id, (int) $entity->id, $distributionId,
            (int) $maker->id, 'DIV-PAY-BATCH-001', [(int) $payable->id]
        );
        $this->assertSame('draft', $batch->status);
        $approved = $service->approveBatch((int) $tenant->id, (int) $batch->id, (int) $checker->id);
        $this->assertSame('approved', $approved->status);

        $itemId = (int) DB::table('finance_dividend_payment_batch_items')
            ->where('tenant_id', $tenant->id)->where('batch_id', $batch->id)->value('id');
        $result = $service->confirmPayment(
            (int) $tenant->id, (int) $batch->id, $itemId, (int) $checker->id,
            'BANK-REF-001', 'bank-statement-row-001', today()->toDateString()
        );
        $this->assertSame('paid', $result['status']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame('settled', $payable->fresh()->status ?? DB::table('finance_shareholder_dividend_payables')->where('id', $payable->id)->value('status'));
        $this->assertSame('paid', DB::table('finance_dividend_payment_batches')->where('id', $batch->id)->value('status'));

        $replay = $service->confirmPayment(
            (int) $tenant->id, (int) $batch->id, $itemId, (int) $checker->id,
            'BANK-REF-001', 'bank-statement-row-001', today()->toDateString()
        );
        $this->assertTrue($replay['idempotent_replay']);

        $reconciled = $service->reconcileBatch(
            (int) $tenant->id, (int) $batch->id, (int) $maker->id,
            'BANK-STATEMENT-001', '900.0000'
        );
        $this->assertSame('reconciled', $reconciled->reconciliation_status);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DIV-PAY',
            'name_en' => 'Dividend Payment Entity', 'base_currency' => 'ETB', 'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'code' => 'DIV-PAY-FY', 'starts_on' => today()->subYear()->toDateString(),
            'ends_on' => today()->addYear()->toDateString(), 'status' => 'open',
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $definitions = [
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE => ['2100', 'Shareholder dividend payable', 'liability', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_CASH_BANK => ['1000', 'Cash at bank', 'asset', 'debit'],
        ];
        foreach ($definitions as $role => [$code, $name, $type, $normal]) {
            $account = LedgerAccount::query()->create([
                'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
                'code' => $code, 'name_en' => $name, 'account_type' => $type,
                'normal_balance' => $normal, 'is_posting_allowed' => true, 'status' => 'active',
            ]);
            GovernanceSettlementAccountMapping::query()->create([
                'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
                'account_role' => $role, 'ledger_account_id' => $account->id,
                'status' => GovernanceSettlementAccountMapping::STATUS_APPROVED,
                'configuration_reason' => 'Approved dividend payment test mapping',
                'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
            ]);
        }
        $shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-DIV-PAY-001',
            'shareholder_type' => 'individual', 'first_name_en' => 'Payment Test Shareholder',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $distributionId = (int) DB::table('finance_dividend_distributions')->insertGetId([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'distribution_reference' => 'GA-DIV-2026-001', 'financial_year' => '2026',
            'resolution_reference' => 'GA-RES-001', 'resolution_date' => today()->subDays(10)->toDateString(),
            'declared_on' => today()->subDays(9)->toDateString(), 'record_date' => today()->subDays(8)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(), 'currency' => 'ETB',
            'declared_amount' => '1000.0000', 'allocation_method' => 'pro_rata_shares',
            'withholding_rate_bps' => 0, 'status' => 'approved', 'snapshot_row_count' => 1,
            'eligible_share_quantity' => '90.0000', 'gross_total' => '1000.0000',
            'withholding_total' => '100.0000', 'net_total' => '900.0000',
            'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$tenant, $entity, $maker, $checker, $shareholderId, $distributionId];
    }

    private function paymentService(): DividendPaymentService
    {
        return new DividendPaymentService(
            new JournalEntryService(new JournalSourceReferenceResolverRegistry([]))
        );
    }
}
