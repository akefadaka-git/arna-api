<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\GovernanceSettlementAccountMapping;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\GovernancePenaltySettlementService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DividendReinvestmentJournalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function subscription_balance_reinvestment_debits_dividend_payable_and_credits_subscription_receivable(): void
    {
        [$tenant, $entity, $maker] = $this->fixture();
        $journal = $this->service()->postDividendReinvestment(
            (int) $tenant->id, (int) $entity->id, 9101, (int) $maker->id,
            'subscription_balance', today()->toDateString(), '250.0000'
        );

        $this->assertSame('posted', $journal->status);
        $this->assertSame('DIV-REINV-9101', $journal->journal_number);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('250.0000', (string) $lines[0]->debit_amount);
        $this->assertSame('250.0000', (string) $lines[1]->credit_amount);
        $this->assertSame('system', DB::table('journal_entries')->where('id', $journal->id)->value('source_type'));
    }

    #[Test]
    public function new_company_allotment_reinvestment_credits_share_capital(): void
    {
        [$tenant, $entity, $maker] = $this->fixture();
        $journal = $this->service()->postDividendReinvestment(
            (int) $tenant->id, (int) $entity->id, 9102, (int) $maker->id,
            'new_company_allotment', today()->toDateString(), '500.0000'
        );

        $credit = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->skip(1)->first();
        $this->assertSame('500.0000', (string) $credit->credit_amount);
        $this->assertSame('SHARE-CAPITAL', DB::table('ledger_accounts')->where('id', $credit->account_id)->value('code'));
    }

    #[Test]
    public function existing_share_purchase_recognizes_seller_consideration_payable(): void
    {
        [$tenant, $entity, $maker] = $this->fixture();
        $journal = $this->service()->postDividendReinvestment(
            (int) $tenant->id, (int) $entity->id, 9103, (int) $maker->id,
            'existing_share_transfer', today()->toDateString(), '750.0000'
        );

        $credit = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->skip(1)->first();
        $this->assertSame('750.0000', (string) $credit->credit_amount);
        $this->assertSame('SHARE-TRANSFER-PAYABLE', DB::table('ledger_accounts')->where('id', $credit->account_id)->value('code'));
    }

    #[Test]
    public function seller_bank_settlement_debits_transfer_payable_and_credits_cash_bank(): void
    {
        [$tenant, $entity, $maker] = $this->fixture();
        $journal = $this->service()->settleDividendShareTransfer(
            (int) $tenant->id, (int) $entity->id, 9104, (int) $maker->id,
            today()->toDateString(), '750.0000', 'BANK-REF-9104', 'BANK-EVIDENCE-9104'
        );

        $this->assertSame('posted', $journal->status);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->get();
        $this->assertSame('750.0000', (string) $lines[0]->debit_amount);
        $this->assertSame('750.0000', (string) $lines[1]->credit_amount);
        $this->assertSame('CASH-BANK', DB::table('ledger_accounts')->where('id', $lines[1]->account_id)->value('code'));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DIV-REINV',
            'name_en' => 'Dividend Reinvestment Entity', 'base_currency' => 'ETB', 'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'code' => 'DIV-REINV-FY', 'starts_on' => today()->subYear()->toDateString(),
            'ends_on' => today()->addYear()->toDateString(), 'status' => 'open',
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $definitions = [
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE => ['2100', 'Dividend payable', 'liability', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_SHARE_SUBSCRIPTION_RECEIVABLE => ['1200', 'Share subscription receivable', 'asset', 'debit'],
            GovernanceSettlementAccountMapping::ROLE_SHARE_CAPITAL => ['3100', 'Share capital', 'equity', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_SHARE_TRANSFER_PAYABLE => ['2200', 'Share transfer payable', 'liability', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_CASH_BANK => ['1000', 'Cash bank', 'asset', 'debit'],
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
                'configuration_reason' => 'Approved dividend reinvestment test mapping',
                'created_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(),
            ]);
        }
        return [$tenant, $entity, $maker, $checker];
    }

    private function service(): GovernancePenaltySettlementService
    {
        return new GovernancePenaltySettlementService(
            new JournalEntryService(new JournalSourceReferenceResolverRegistry([]))
        );
    }
}
