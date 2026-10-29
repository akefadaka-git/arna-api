<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\CashFlowReportService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceCashFlowReportAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_keeps_unclassified_cash_movements_visible_as_undetermined(): void
    {
        [$tenant, $user, $entity, $cash, $revenue] = $this->fixture();
        LedgerAccount::query()->whereKey($cash->id)->update(['is_cash_equivalent' => true]);

        $this->postCashJournal(
            $tenant,
            $user,
            $entity,
            $cash,
            $revenue,
            'JV-CF-UNCLASSIFIED',
            'cash-flow-unclassified-acceptance',
            '25.0000',
            '2026-10-15',
            null,
        );

        $report = app(CashFlowReportService::class)->cashFlow(
            (int) $tenant->id,
            (int) $entity->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertSame('25.0000', $report['activities']['undetermined']['inflows']);
        $this->assertSame('0.0000', $report['activities']['operating']['inflows']);
        $this->assertSame(1, $report['summary']['unclassified_movement_count']);
        $this->assertTrue($report['summary']['has_unclassified_movements']);
        $this->assertSame('JV-CF-UNCLASSIFIED', $report['unclassified_movements'][0]['journal_number']);
    }

    #[Test]
    public function it_excludes_cash_accounts_until_they_are_explicitly_enabled_as_cash_equivalents(): void
    {
        [$tenant, $user, $entity, $cash, $revenue] = $this->fixture();

        $this->postCashJournal(
            $tenant,
            $user,
            $entity,
            $cash,
            $revenue,
            'JV-CF-DISABLED-CASH',
            'cash-flow-disabled-account-acceptance',
            '40.0000',
            '2026-10-15',
            'operating',
        );

        $report = app(CashFlowReportService::class)->cashFlow(
            (int) $tenant->id,
            (int) $entity->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertSame('0.0000', $report['summary']['total_inflows']);
        $this->assertSame('0.0000', $report['summary']['total_outflows']);
        $this->assertSame(0, $report['summary']['movement_count']);
    }

    #[Test]
    public function it_respects_the_inclusive_effective_date_window(): void
    {
        [$tenant, $user, $entity, $cash, $revenue] = $this->fixture();
        LedgerAccount::query()->whereKey($cash->id)->update(['is_cash_equivalent' => true]);

        $this->postCashJournal(
            $tenant,
            $user,
            $entity,
            $cash,
            $revenue,
            'JV-CF-OUTSIDE-WINDOW',
            'cash-flow-date-window-acceptance',
            '12.5000',
            '2026-10-15',
            'operating',
        );

        $report = app(CashFlowReportService::class)->cashFlow(
            (int) $tenant->id,
            (int) $entity->id,
            '2026-10-01',
            '2026-10-14',
        );

        $this->assertSame('0.0000', $report['summary']['total_inflows']);
        $this->assertSame(0, $report['summary']['movement_count']);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'CF-ENTITY',
            'name_en' => 'Cash Flow Acceptance Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);

        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => '2026-10',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-31',
            'status' => AccountingPeriod::OPEN,
        ]);

        $cash = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => 'CF-1000',
            'name_en' => 'Acceptance Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);

        $revenue = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => 'CF-4000',
            'name_en' => 'Acceptance Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);

        return [$tenant, $user, $entity, $cash, $revenue];
    }

    private function postCashJournal(
        Tenant $tenant,
        User $user,
        LegalEntity $entity,
        LedgerAccount $cash,
        LedgerAccount $revenue,
        string $journalNumber,
        string $idempotencyKey,
        string $amount,
        string $effectiveDate,
        ?string $activity,
    ): void {
        $cashLine = [
            'account_id' => $cash->id,
            'debit_amount' => $amount,
            'credit_amount' => '0.0000',
        ];
        if ($activity !== null) {
            $cashLine['cash_flow_activity'] = $activity;
            $cashLine['cash_flow_classification_note'] = 'Acceptance fixture classification';
        }

        $draft = app(JournalEntryService::class)->createDraft(
            (int) $tenant->id,
            (int) $user->id,
            [
                'legal_entity_id' => $entity->id,
                'journal_number' => $journalNumber,
                'effective_date' => $effectiveDate,
                'description' => 'Cash-flow acceptance fixture',
                'currency' => 'ETB',
                'idempotency_key' => $idempotencyKey,
            ],
            [
                $cashLine,
                [
                    'account_id' => $revenue->id,
                    'debit_amount' => '0.0000',
                    'credit_amount' => $amount,
                ],
            ],
        );

        app(JournalEntryService::class)->post(
            (int) $tenant->id,
            (int) $user->id,
            (int) $draft->id,
        );
    }
}
