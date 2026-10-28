<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceGeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function balanced_journal_can_be_posted_and_is_marked_with_actor(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);

        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-0001',
            'effective_date' => '2026-10-01',
            'description' => 'Verified test journal',
            'currency' => 'ETB',
            'idempotency_key' => 'test-journal-0001',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '100.0000', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '100.0000'],
        ]);

        $this->assertSame(JournalEntry::DRAFT, $draft->status);
        $posted = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        $this->assertSame(JournalEntry::POSTED, $posted->status);
        $this->assertSame((int) $user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
    }

    #[Test]
    public function unbalanced_journal_is_rejected_before_a_draft_is_persisted(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);

        try {
            $service->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id,
                'journal_number' => 'JV-UNBALANCED',
                'effective_date' => '2026-10-01',
                'description' => 'Unbalanced test journal',
                'currency' => 'ETB',
                'idempotency_key' => 'test-journal-unbalanced',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '100.0000', 'credit_amount' => '0'],
                ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '99.9999'],
            ]);
            $this->fail('An unbalanced journal must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'test-journal-unbalanced']);
    }

    #[Test]
    public function account_from_another_legal_entity_cannot_be_used_in_a_journal(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $otherEntity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'OTHER', 'name_en' => 'Other Entity',
            'base_currency' => 'ETB', 'status' => 'active',
        ]);
        $otherAccount = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id, 'legal_entity_id' => $otherEntity->id, 'code' => '1000',
            'name_en' => 'Other Cash', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_posting_allowed' => true, 'status' => 'active',
        ]);

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id, 'journal_number' => 'JV-CROSS-ENTITY',
                'effective_date' => '2026-10-01', 'description' => 'Cross-entity rejection',
                'currency' => 'ETB', 'idempotency_key' => 'test-cross-entity',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
                ['account_id' => $otherAccount->id, 'debit_amount' => '0', 'credit_amount' => '10'],
            ]);
            $this->fail('A journal must not reference an account from another entity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.1.account_id', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'test-cross-entity']);
    }

    /** @return array{Tenant, User, LegalEntity, AccountingPeriod, LedgerAccount, LedgerAccount} */
    private function ledgerFixture(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $entity = LegalEntity::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'MAIN', 'name_en' => 'Main Entity',
            'base_currency' => 'ETB', 'status' => 'active',
        ]);
        $period = AccountingPeriod::query()->create([
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

        return [$tenant, $user, $entity, $period, $cash, $equity];
    }
}
