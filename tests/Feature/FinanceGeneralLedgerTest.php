<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalEntryType;
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
    public function test_balanced_journal_can_be_posted_and_is_marked_with_actor(): void
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
    public function test_identical_idempotency_request_returns_the_persisted_journal(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $header = [
            'legal_entity_id' => $entity->id, 'journal_number' => 'JV-IDEMPOTENT',
            'effective_date' => '2026-10-01', 'description' => 'Idempotency test',
            'currency' => 'ETB', 'idempotency_key' => 'same-request-key',
        ];
        $lines = [
            ['account_id' => $cash->id, 'debit_amount' => '10.0000', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10.0000'],
        ];

        $first = $service->createDraft((int) $tenant->id, (int) $user->id, $header, $lines);
        $second = $service->createDraft((int) $tenant->id, (int) $user->id, $header, $lines);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    #[Test]
    public function test_unbalanced_journal_is_rejected_before_a_draft_is_persisted(): void
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
    public function test_account_from_another_legal_entity_cannot_be_used_in_a_journal(): void
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

    #[Test]
    public function test_reusing_an_idempotency_key_for_a_different_payload_conflicts(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $header = [
            'legal_entity_id' => $entity->id, 'journal_number' => 'JV-IDEMPOTENCY-CONFLICT',
            'effective_date' => '2026-10-01', 'description' => 'First payload',
            'currency' => 'ETB', 'idempotency_key' => 'same-key-different-payload',
        ];
        $lines = [
            ['account_id' => $cash->id, 'debit_amount' => '10.0000', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10.0000'],
        ];
        $service->createDraft((int) $tenant->id, (int) $user->id, $header, $lines);

        $header['description'] = 'Changed payload';
        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->createDraft((int) $tenant->id, (int) $user->id, $header, $lines);
    }

    #[Test]
    public function test_a_draft_cannot_be_posted_after_its_period_is_closed(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id, 'journal_number' => 'JV-CLOSED-PERIOD',
            'effective_date' => '2026-10-01', 'description' => 'Closed-period test',
            'currency' => 'ETB', 'idempotency_key' => 'closed-period-test',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '20', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '20'],
        ]);

        $period->status = AccountingPeriod::CLOSED;
        $period->closed_at = now();
        $period->close_reason = 'Acceptance test closure';
        $period->save();

        try {
            $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
            $this->fail('Posting into a closed accounting period must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounting_period', $exception->errors());
        }

        $this->assertDatabaseHas('journal_entries', ['id' => $draft->id, 'status' => JournalEntry::DRAFT]);
    }

    #[Test]
    public function test_cancelled_draft_is_audited_and_no_longer_blocks_period_closure(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'CANCEL-001',
            'effective_date' => '2026-01-15',
            'currency' => 'ETB',
            'idempotency_key' => 'cancel-draft-test',
            'description' => 'Draft to cancel',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '15', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '15'],
        ]);

        $cancelled = $service->cancelDraft((int) $tenant->id, (int) $user->id, (int) $draft->id, 'Entered in error');
        $this->assertSame(JournalEntry::CANCELLED, $cancelled->status);
        $this->assertSame((int) $user->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame('Entered in error', $cancelled->cancellation_reason);
        $this->assertSame(2, $cancelled->lines->count());
        $this->assertDatabaseHas('journal_entries', ['id' => $draft->id, 'status' => JournalEntry::CANCELLED]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id, 'status' => JournalEntry::DRAFT]);
        $this->assertSame(0, JournalEntry::query()->where('accounting_period_id', $period->id)
            ->where('status', JournalEntry::DRAFT)->count());
    }

    #[Test]
    public function test_reversal_posts_an_equal_and_opposite_journal_and_preserves_the_original(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id, 'journal_number' => 'JV-REVERSAL-ORIGINAL',
            'effective_date' => '2026-10-01', 'description' => 'Original journal',
            'currency' => 'ETB', 'idempotency_key' => 'original-for-reversal',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '125.2500', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '125.2500'],
        ]);
        $original = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        $reversal = $service->reverse(
            (int) $tenant->id,
            (int) $user->id,
            (int) $original->id,
            'JV-REVERSAL-001',
            '2026-10-02',
            'Correct the original entry',
        );

        $this->assertSame(JournalEntry::REVERSED, $original->fresh()->status);
        $this->assertSame(JournalEntry::POSTED, $reversal->status);
        $this->assertSame(JournalEntryType::REVERSAL, $reversal->journal_type);
        $this->assertSame((int) $original->id, (int) $reversal->reversal_of_id);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame('0.0000', (string) $reversal->lines->where('account_id', $cash->id)->first()->debit_amount);
        $this->assertSame('125.2500', (string) $reversal->lines->where('account_id', $cash->id)->first()->credit_amount);
    }


    #[Test]
    public function test_ordinary_draft_workflow_rejects_reversal_journal_type(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id,
                'journal_number' => 'JV-UNAUTHORIZED-REVERSAL',
                'effective_date' => '2026-10-01',
                'description' => 'Must use the controlled reversal workflow',
                'currency' => 'ETB',
                'journal_type' => JournalEntryType::REVERSAL->value,
                'idempotency_key' => 'unauthorized-reversal-type',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
                ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
            ]);
            $this->fail('Ordinary draft creation must not permit reversal journal types.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal_type', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'unauthorized-reversal-type']);
    }

    #[Test]
    public function test_cancellation_requires_a_non_empty_reason_and_preserves_draft(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $draft = app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-CANCEL-REASON',
            'effective_date' => '2026-10-01',
            'description' => 'Cancellation reason validation',
            'currency' => 'ETB',
            'idempotency_key' => 'cancel-reason-required',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
        ]);

        try {
            app(JournalEntryService::class)->cancelDraft((int) $tenant->id, (int) $user->id, (int) $draft->id, '   ');
            $this->fail('Cancellation must require a meaningful reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertDatabaseHas('journal_entries', ['id' => $draft->id, 'status' => JournalEntry::DRAFT]);
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
