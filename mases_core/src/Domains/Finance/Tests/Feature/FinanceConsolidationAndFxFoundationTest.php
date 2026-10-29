<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Validation\\ValidationException;
use MaaleArna\\MASES\\Core\\Auth\\Models\\User;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\FinanceConsolidationAccountMapping;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\FinanceExchangeRate;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LedgerAccount;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Models\\LegalEntity;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Services\\FinanceConsolidationMappingService;
use MaaleArna\\MASES\\Core\\Domains\\Finance\\Services\\FinanceExchangeRateService;
use MaaleArna\\MASES\\Core\\Tenancy\\Models\\Tenant;
use PHPUnit\\Framework\\Attributes\\Test;
use Tests\\TestCase;

final class FinanceConsolidationAndFxFoundationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_finance_migrations_converge_without_duplicate_journal_type_and_expose_proposal_versions(): void
    {
        $this->assertTrue(Schema::hasColumn('journal_entries', 'journal_type'));
        $this->assertTrue(Schema::hasColumn('finance_consolidation_account_mappings', 'proposal_version'));
        $this->assertTrue(Schema::hasColumn('finance_exchange_rates', 'proposal_version'));
    }

    #[Test]
    public function test_rejected_mapping_can_be_resubmitted_without_overwriting_history(): void
    {
        [$tenant, $proposer, $reviewer, $entity, $account] = $this->fixture();
        $service = app(FinanceConsolidationMappingService::class);

        $first = $service->propose((int) $tenant->id, (int) $proposer->id, (int) $entity->id, (int) $account->id, 'CASH', 'Initial mapping');
        $rejected = $service->reject((int) $tenant->id, (int) $reviewer->id, (int) $first->id, 'Code needs reconciliation');
        $second = $service->propose((int) $tenant->id, (int) $proposer->id, (int) $entity->id, (int) $account->id, 'CASH', 'Corrected mapping');

        $this->assertSame(FinanceConsolidationAccountMapping::REJECTED, $rejected->status);
        $this->assertSame(1, (int) $first->proposal_version);
        $this->assertSame(2, (int) $second->proposal_version);
        $this->assertDatabaseCount('finance_consolidation_account_mappings', 2);
    }

    #[Test]
    public function test_mapping_requires_independent_reviewer_and_blocks_second_pending_proposal(): void
    {
        [$tenant, $proposer, $reviewer, $entity, $account] = $this->fixture();
        $service = app(FinanceConsolidationMappingService::class);
        $first = $service->propose((int) $tenant->id, (int) $proposer->id, (int) $entity->id, (int) $account->id, 'CASH', 'Mapping proposal');

        try {
            $service->approve((int) $tenant->id, (int) $proposer->id, (int) $first->id, 'Self approval');
            $this->fail('A proposer must not approve their own mapping.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewer', $exception->errors());
        }

        try {
            $service->propose((int) $tenant->id, (int) $reviewer->id, (int) $entity->id, (int) $account->id, 'CASH-2', 'Competing mapping');
            $this->fail('A second pending mapping must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ledger_account_id', $exception->errors());
        }
    }

    #[Test]
    public function test_rejected_fx_rate_can_be_resubmitted_and_only_approved_rates_resolve(): void
    {
        [$tenant, $proposer, $reviewer] = $this->fixture();
        $service = app(FinanceExchangeRateService::class);
        $payload = [
            'base_currency' => 'ETB', 'quote_currency' => 'USD', 'rate' => '0.0075000000',
            'effective_on' => '2026-10-01', 'source' => 'Treasury register',
            'source_reference' => 'FX-001', 'proposal_reason' => 'Daily close rate',
        ];

        $first = $service->propose((int) $tenant->id, (int) $proposer->id, $payload);
        $service->reject((int) $tenant->id, (int) $reviewer->id, (int) $first->id, 'Source evidence insufficient');
        $second = $service->propose((int) $tenant->id, (int) $proposer->id, $payload);

        $this->assertSame(2, (int) $second->proposal_version);
        try {
            $service->resolveApproved((int) $tenant->id, 'ETB', 'USD', '2026-10-01');
            $this->fail('A pending or rejected rate must never resolve as an approved rate.');
        } catch (\\Illuminate\\Database\\Eloquent\\ModelNotFoundException) {
            $this->assertDatabaseCount('finance_exchange_rates', 2);
        }

        $approved = $service->approve((int) $tenant->id, (int) $reviewer->id, (int) $second->id, 'Verified treasury evidence');
        $resolved = $service->resolveApproved((int) $tenant->id, 'ETB', 'USD', '2026-10-02');
        $this->assertSame(FinanceExchangeRate::APPROVED, $approved->status);
        $this->assertSame($second->id, $resolved->id);
    }

    #[Test]
    public function test_exchange_rate_rejects_invalid_dates_and_self_approval(): void
    {
        [$tenant, $proposer, $reviewer] = $this->fixture();
        $service = app(FinanceExchangeRateService::class);
        $payload = [
            'base_currency' => 'ETB', 'quote_currency' => 'USD', 'rate' => '0.0075',
            'effective_on' => '2026-02-30', 'source' => 'Treasury register',
            'proposal_reason' => 'Date validation',
        ];

        try {
            $service->propose((int) $tenant->id, (int) $proposer->id, $payload);
            $this->fail('Impossible calendar dates must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_on', $exception->errors());
        }

        $payload['effective_on'] = '2026-10-01';
        $rate = $service->propose((int) $tenant->id, (int) $proposer->id, $payload);
        try {
            $service->approve((int) $tenant->id, (int) $proposer->id, (int) $rate->id, 'Self approval');
            $this->fail('A rate proposer must not approve their own proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reviewer', $exception->errors());
        }

        $this->assertSame(FinanceExchangeRate::PENDING, $rate->fresh()->status);
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $proposer = User::factory()->create();
        $reviewer = User::factory()->create();
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'MAIN', 'name_en' => 'Main Entity',
            'base_currency' => 'ETB', 'status' => 'active',
        ]);
        $account = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id, 'code' => '1000',
            'name_en' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);

        return [$tenant, $proposer, $reviewer, $entity, $account];
    }
}
