<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceDimension;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceOutboxMessage;
use MaaleArna\Arna\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\Arna\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\Arna\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\Arna\Core\Domains\Finance\Services\FinanceDimensionService;
use MaaleArna\Arna\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceJournalDimensionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_persists_dimension_assignments_on_draft_journal_lines_and_posts_them(): void
    {
        [$tenant, $actor, $entity, $cash, $equity] = $this->fixture();
        [$dimension, $value] = $this->requiredDepartment($tenant, $actor, $entity);

        $journal = app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $actor->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-DIM-001',
            'effective_date' => '2026-10-01',
            'description' => 'Dimension allocation test',
            'currency' => 'ETB',
            'idempotency_key' => 'journal-dimension-001',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '125.0000', 'credit_amount' => '0', 'dimensions' => [
                ['dimension_id' => $dimension->id, 'dimension_value_id' => $value->id],
            ]],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '125.0000', 'dimensions' => [
                ['dimension_id' => $dimension->id, 'dimension_value_id' => $value->id],
            ]],
        ]);

        self::assertCount(1, $journal->lines[0]->dimensions);
        self::assertSame((int) $value->id, (int) $journal->lines[0]->dimensions[0]->dimension_value_id);

        $this->assertDatabaseHas('journal_line_dimensions', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'journal_entry_id' => $journal->id,
            'line_number' => 1,
            'dimension_id' => $dimension->id,
            'dimension_value_id' => $value->id,
        ]);

        $posted = app(JournalEntryService::class)->post((int) $tenant->id, (int) $actor->id, (int) $journal->id);
        self::assertSame(JournalEntry::POSTED, $posted->status);

        $outbox = FinanceOutboxMessage::query()
            ->where('event_key', 'finance.journal.posted:'.$tenant->id.':'.$journal->id)
            ->firstOrFail();
        self::assertSame(2, $outbox->payload['schema_version']);
        self::assertSame('DEPT', $outbox->payload['lines'][0]['dimensions'][0]['dimension_code']);
        self::assertSame('FIN', $outbox->payload['lines'][0]['dimensions'][0]['value_code']);
    }

    #[Test]
    public function it_carries_dimension_assignments_into_system_generated_reversal_journals(): void
    {
        [$tenant, $actor, $entity, $cash, $equity] = $this->fixture();
        [$dimension, $value] = $this->requiredDepartment($tenant, $actor, $entity);
        $service = app(JournalEntryService::class);

        $original = $service->createDraft((int) $tenant->id, (int) $actor->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-DIM-REV-001',
            'effective_date' => '2026-10-01',
            'description' => 'Dimension reversal source',
            'currency' => 'ETB',
            'idempotency_key' => 'journal-dimension-reversal-source',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '15.0000', 'credit_amount' => '0', 'dimensions' => [
                ['dimension_id' => $dimension->id, 'dimension_value_id' => $value->id],
            ]],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '15.0000', 'dimensions' => [
                ['dimension_id' => $dimension->id, 'dimension_value_id' => $value->id],
            ]],
        ]);
        $service->post((int) $tenant->id, (int) $actor->id, (int) $original->id);

        $reversal = $service->reverse(
            (int) $tenant->id,
            (int) $actor->id,
            (int) $original->id,
            'JV-DIM-REV-002',
            '2026-10-01',
            'Verified dimension-preserving reversal',
        );

        self::assertSame(JournalEntry::POSTED, $reversal->status);
        self::assertSame(2, DB::table('journal_line_dimensions')
            ->where('journal_entry_id', $reversal->id)
            ->count());
    }

    #[Test]
    public function it_rejects_posting_when_a_required_dimension_is_missing_from_any_line(): void
    {
        [$tenant, $actor, $entity, $cash, $equity] = $this->fixture();
        $this->requiredDepartment($tenant, $actor, $entity);

        $journal = app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $actor->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-DIM-002',
            'effective_date' => '2026-10-01',
            'description' => 'Missing required dimension test',
            'currency' => 'ETB',
            'idempotency_key' => 'journal-dimension-002',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '50.0000', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '50.0000'],
        ]);

        try {
            app(JournalEntryService::class)->post((int) $tenant->id, (int) $actor->id, (int) $journal->id);
            self::fail('Posting must reject missing required dimensions.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('lines.1.dimensions', $exception->errors());
        }

        $this->assertDatabaseHas('journal_entries', ['id' => $journal->id, 'status' => JournalEntry::DRAFT]);
    }

    #[Test]
    public function it_rejects_dimension_values_from_another_legal_entity(): void
    {
        [$tenant, $actor, $entity, $cash, $equity] = $this->fixture();
        [$dimension, $value] = $this->requiredDepartment($tenant, $actor, $entity);

        $otherEntity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'OTHER',
            'name_en' => 'Other Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $otherEntity->id,
            'code' => '2026-10',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-31',
            'status' => AccountingPeriod::OPEN,
        ]);
        $otherCash = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $otherEntity->id, 'code' => '1000',
            'name_en' => 'Other Cash', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);
        $otherEquity = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $otherEntity->id, 'code' => '3000',
            'name_en' => 'Other Equity', 'account_type' => 'equity', 'normal_balance' => 'credit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $actor->id, [
                'legal_entity_id' => $otherEntity->id,
                'journal_number' => 'JV-DIM-003',
                'effective_date' => '2026-10-01',
                'description' => 'Cross-entity dimension rejection',
                'currency' => 'ETB',
                'idempotency_key' => 'journal-dimension-003',
            ], [
                ['account_id' => $otherCash->id, 'debit_amount' => '10.0000', 'credit_amount' => '0', 'dimensions' => [
                    ['dimension_id' => $dimension->id, 'dimension_value_id' => $value->id],
                ]],
                ['account_id' => $otherEquity->id, 'debit_amount' => '0', 'credit_amount' => '10.0000'],
            ]);
            self::fail('Cross-entity dimension references must be rejected.');
        } catch (ValidationException $exception) {
            self::assertNotEmpty($exception->errors());
        }
    }

    private function requiredDepartment(Tenant $tenant, User $actor, LegalEntity $entity): array
    {
        $service = app(FinanceDimensionService::class);
        $dimension = $service->createDimension((int) $tenant->id, (int) $entity->id, [
            'code' => 'DEPT',
            'dimension_type' => 'department',
            'name_en' => 'Department',
            'is_required_for_post' => true,
        ], (int) $actor->id);
        $value = $service->createValue((int) $tenant->id, (int) $entity->id, (int) $dimension->id, [
            'code' => 'FIN',
            'name_en' => 'Finance',
        ], (int) $actor->id);

        return [$dimension, $value];
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $actor = User::factory()->create();
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'MAIN', 'name_en' => 'Main Entity',
            'base_currency' => 'ETB', 'status' => 'active',
        ]);
        AccountingPeriod::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id,
            'code' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31',
            'status' => AccountingPeriod::OPEN,
        ]);
        $cash = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id, 'code' => '1000',
            'name_en' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);
        $equity = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $entity->id, 'code' => '3000',
            'name_en' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);

        return [$tenant, $actor, $entity, $cash, $equity];
    }
}
