<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\DividendTaxRemittanceService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DividendTaxRemittanceServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tax_remittance_is_capped_by_outstanding_liability_and_posts_balanced_clearing_journal(): void
    {
        [$tenant, $entity, $maker, $checker, $distributionId, $shareholderId] = $this->fixture();
        $settlements = new GovernancePenaltySettlementService(new JournalEntryService(new JournalSourceReferenceResolverRegistry([])));
        $payable = $settlements->createDividendPayable((int) $tenant->id, (int) $entity->id, $shareholderId,
            'GA-DIV-TAX-REM-001', today()->subDays(5)->toDateString(), today()->addDays(30)->toDateString(), '900.0000', (int) $maker->id);
        $settlements->approveDividendPayable((int) $tenant->id, (int) $payable->id, (int) $checker->id, 'Approved dividend payable');
        DB::table('finance_dividend_distribution_items')->insert([
            'tenant_id' => $tenant->id, 'distribution_id' => $distributionId, 'shareholder_id' => $shareholderId,
            'eligible_share_quantity' => '100.0000', 'weighted_share_quantity' => '100.0000',
            'gross_amount' => '1000.0000', 'withholding_amount' => '100.0000', 'net_amount' => '900.0000',
            'payable_id' => $payable->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $batchId = (int) DB::table('finance_dividend_payment_batches')->insertGetId([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id, 'distribution_id' => $distributionId,
            'batch_reference' => 'DIV-TAX-PAID-BATCH-001', 'currency' => 'ETB', 'total_amount' => '900.0000',
            'status' => 'paid', 'reconciliation_status' => 'reconciled', 'created_by' => $maker->id,
            'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('finance_dividend_payment_batch_items')->insert([
            'tenant_id' => $tenant->id, 'batch_id' => $batchId, 'payable_id' => $payable->id,
            'shareholder_id' => $shareholderId, 'amount' => '900.0000', 'status' => 'paid',
            'bank_reference' => 'BANK-DIV-TAX-001', 'evidence_reference' => 'BANK-EVIDENCE-001',
            'journal_reference' => 'DIV-PAY-001', 'paid_on' => today()->toDateString(),
            'confirmed_by' => $checker->id, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = new DividendTaxRemittanceService(
            new JournalEntryService(new JournalSourceReferenceResolverRegistry([]))
        );

        $row = $service->create(
            (int) $tenant->id, (int) $entity->id, $distributionId,
            (int) $maker->id, 'DIV-TAX-REM-001', 'TAX-AUTH-RECEIPT-001', '75.0000'
        );
        $service->approve((int) $tenant->id, (int) $row->id, (int) $checker->id);
        $paid = $service->confirm(
            (int) $tenant->id, (int) $row->id, (int) $checker->id,
            'BANK-TAX-REF-001', 'TAX-RECEIPT-EVIDENCE-001', today()->toDateString()
        );

        $this->assertSame('paid', $paid->status);
        $this->assertNotEmpty($paid->journal_reference);
        $totals = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.tenant_id', $tenant->id)
            ->where('journal_entries.journal_number', $paid->journal_reference)
            ->selectRaw('SUM(journal_lines.debit_amount) AS debits, SUM(journal_lines.credit_amount) AS credits')
            ->first();
        $this->assertSame(0, bccomp((string) $totals->debits, (string) $totals->credits, 4));

        try {
            $service->create(
                (int) $tenant->id, (int) $entity->id, $distributionId,
                (int) $maker->id, 'DIV-TAX-REM-002', 'TAX-AUTH-RECEIPT-002', '30.0000'
            );
            $this->fail('A remittance must not exceed the remaining withholding liability.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DIV-TAX-REM',
            'name_en' => 'Dividend Tax Remittance Entity', 'base_currency' => 'ETB', 'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'code' => 'DIV-TAX-REM-FY', 'starts_on' => today()->subYear()->toDateString(),
            'ends_on' => today()->addYear()->toDateString(), 'status' => 'open',
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        foreach ([
            GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE => ['2200', 'Dividend withholding payable', 'liability', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_CASH_BANK => ['1000', 'Cash at bank', 'asset', 'debit'],
        ] as $role => [$code, $name, $type, $normal]) {
            $account = LedgerAccount::query()->create([
                'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
                'code' => $code, 'name_en' => $name, 'account_type' => $type,
                'normal_balance' => $normal, 'is_posting_allowed' => true, 'status' => 'active',
            ]);
            GovernanceSettlementAccountMapping::query()->create([
                'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
                'account_role' => $role, 'ledger_account_id' => $account->id,
                'status' => GovernanceSettlementAccountMapping::STATUS_APPROVED,
                'configuration_reason' => 'Approved tax remittance test mapping',
                'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
            ]);
        }
        $distributionId = (int) DB::table('finance_dividend_distributions')->insertGetId([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'distribution_reference' => 'GA-DIV-TAX-REM-001', 'financial_year' => '2026',
            'resolution_reference' => 'GA-RES-TAX-REM-001',
            'resolution_date' => today()->subDays(10)->toDateString(),
            'declared_on' => today()->subDays(9)->toDateString(),
            'record_date' => today()->subDays(8)->toDateString(),
            'payment_due_on' => today()->addDays(30)->toDateString(),
            'currency' => 'ETB', 'declared_amount' => '1000.0000',
            'allocation_method' => 'pro_rata_shares', 'withholding_rate_bps' => 0,
            'status' => 'paid', 'snapshot_row_count' => 1,
            'eligible_share_quantity' => '100.0000', 'gross_total' => '1000.0000',
            'withholding_total' => '100.0000', 'net_total' => '900.0000',
            'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $shareholderId = (int) DB::table('shareholders')->insertGetId([
            'tenant_id' => $tenant->id, 'shareholder_number' => 'SH-DIV-TAX-REM-001',
            'shareholder_type' => 'individual', 'first_name_en' => 'Tax Remittance Holder',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$tenant, $entity, $maker, $checker, $distributionId, $shareholderId];
    }
}
