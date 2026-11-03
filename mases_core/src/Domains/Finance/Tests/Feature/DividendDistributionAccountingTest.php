<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

final class DividendDistributionAccountingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dividend_accrual_posts_balanced_gross_net_and_withholding_liabilities_and_replays_idempotently(): void
    {
        [$tenant, $entity, $maker, $checker] = $this->fixture();
        $service = $this->service();

        $journal = $service->accrueDividendDistribution(
            (int) $tenant->id, (int) $entity->id, 901,
            'GA-DIV-2026-901', today()->toDateString(),
            '1000.0000', '900.0000', '100.0000', (int) $maker->id,
        );

        $this->assertSame('posted', $journal->status);
        $this->assertSame('system', $journal->source_type->value ?? $journal->source_type);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $journal->id)->orderBy('line_number')->get();
        $this->assertCount(3, $lines);
        $this->assertSame('1000.0000', (string) $lines->sum('debit_amount'));
        $this->assertSame('1000.0000', (string) $lines->sum('credit_amount'));

        $replay = $service->accrueDividendDistribution(
            (int) $tenant->id, (int) $entity->id, 901,
            'GA-DIV-2026-901', today()->toDateString(),
            '1000.0000', '900.0000', '100.0000', (int) $checker->id,
        );
        $this->assertSame((int) $journal->id, (int) $replay->id);
        $this->assertSame(1, DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('idempotency_key', 'dividend-accrual:'.$tenant->id.':'.$entity->id.':901')->count());
    }

    #[Test]
    public function dividend_accrual_rejects_unbalanced_allocation_totals(): void
    {
        [$tenant, $entity, $maker] = $this->fixture();
        try {
            $this->service()->accrueDividendDistribution(
                (int) $tenant->id, (int) $entity->id, 902,
                'GA-DIV-2026-902', today()->toDateString(),
                '1000.0000', '901.0000', '100.0000', (int) $maker->id,
            );
            $this->fail('Unbalanced dividend allocation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('distribution', $exception->errors());
        }
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DIV-ACCT',
            'name_en' => 'Dividend Accounting Entity', 'base_currency' => 'ETB', 'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'code' => 'DIV-FY', 'starts_on' => today()->subYear()->toDateString(),
            'ends_on' => today()->addYear()->toDateString(), 'status' => 'open',
        ]);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $definitions = [
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_EQUITY => ['3100', 'Dividend distribution equity', 'equity', 'debit'],
            GovernanceSettlementAccountMapping::ROLE_DIVIDEND_PAYABLE => ['2100', 'Shareholder dividend payable', 'liability', 'credit'],
            GovernanceSettlementAccountMapping::ROLE_WITHHOLDING_TAX_PAYABLE => ['2200', 'Dividend withholding tax payable', 'liability', 'credit'],
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
                'configuration_reason' => 'Approved dividend accounting test mapping',
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
