<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceAuditEntry;
use MaaleArna\MASES\Core\Domains\Finance\Services\AccountingPeriodService;
use MaaleArna\MASES\Core\Domains\Finance\Services\FinancialReportService;
use MaaleArna\MASES\Core\Domains\Finance\Services\LedgerAccountService;
use MaaleArna\MASES\Core\Domains\Finance\Services\SubledgerIntegrationService;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;
use Illuminate\Support\Facades\Gate;
use MaaleArna\MASES\Core\Domains\Finance\Policies\LegalEntityPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\LedgerAccountPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\AccountingPeriodPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Policies\JournalEntryPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalEntryType;
use MaaleArna\MASES\Core\Domains\Finance\Enums\JournalSourceType;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;
use MaaleArna\MASES\Core\Domains\Finance\Contracts\JournalSourceReferenceResolver;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalEntryService;
use MaaleArna\MASES\Core\Domains\Finance\Services\JournalSourceReferenceResolverRegistry;
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
        $this->assertSame(JournalEntryType::STANDARD, $draft->journal_type);
        $posted = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        $this->assertSame(JournalEntry::POSTED, $posted->status);
        $this->assertSame((int) $user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.journal.draft_created',
            'subject_type' => 'journal_entry', 'subject_id' => $draft->id,
        ]);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.journal.posted',
            'subject_type' => 'journal_entry', 'subject_id' => $draft->id,
        ]);
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
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.journal.cancelled',
            'subject_type' => 'journal_entry', 'subject_id' => $draft->id,
        ]);
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
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.journal.reversed',
            'subject_type' => 'journal_entry', 'subject_id' => $original->id,
        ]);
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


    #[Test]
    public function test_unsupported_source_type_is_rejected(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id,
                'journal_number' => 'JV-INVALID-SOURCE',
                'effective_date' => '2026-10-01',
                'description' => 'Unsupported source type',
                'currency' => 'ETB',
                'source_type' => 'journal_reversal',
                'source_id' => '1',
                'idempotency_key' => 'unsupported-source-type',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
                ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
            ]);
            $this->fail('Unsupported source types must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_type', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'unsupported-source-type']);
    }

    #[Test]
    public function test_external_source_requires_originating_business_reference(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id,
                'journal_number' => 'JV-SOURCE-REFERENCE',
                'effective_date' => '2026-10-01',
                'description' => 'External source without reference',
                'currency' => 'ETB',
                'source_type' => JournalSourceType::SHAREHOLDER->value,
                'idempotency_key' => 'external-source-reference-required',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
                ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
            ]);
            $this->fail('External source types must require a source reference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_id', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'external-source-reference-required']);
    }

    #[Test]
    public function test_external_source_is_rejected_when_no_authoritative_resolver_is_registered(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        try {
            app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
                'legal_entity_id' => $entity->id,
                'journal_number' => 'JV-SOURCE-FAIL-CLOSED',
                'effective_date' => '2026-10-01',
                'description' => 'Unknown source must fail closed',
                'currency' => 'ETB',
                'source_type' => JournalSourceType::SHAREHOLDER->value,
                'source_id' => 'shareholder-123',
                'idempotency_key' => 'source-fail-closed',
            ], [
                ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
                ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
            ]);
            $this->fail('External source references must be rejected without an authoritative resolver.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_type', $exception->errors());
        }

        $this->assertDatabaseMissing('journal_entries', ['idempotency_key' => 'source-fail-closed']);
    }

    #[Test]
    public function test_registered_source_resolver_receives_tenant_and_legal_entity_scope(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        $resolver = new class implements JournalSourceReferenceResolver {
            public array $calls = [];

            public function supports(JournalSourceType $sourceType): bool
            {
                return $sourceType === JournalSourceType::SHAREHOLDER;
            }

            public function assertValid(
                JournalSourceType $sourceType,
                string $sourceId,
                int $tenantId,
                int $legalEntityId,
            ): void {
                $this->calls[] = [$sourceType, $sourceId, $tenantId, $legalEntityId];
            }
        };

        $registry = new JournalSourceReferenceResolverRegistry([$resolver]);
        $this->app->instance(JournalSourceReferenceResolverRegistry::class, $registry);

        $draft = app(JournalEntryService::class)->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-SOURCE-RESOLVED',
            'effective_date' => '2026-10-01',
            'description' => 'Registered resolver integration',
            'currency' => 'ETB',
            'source_type' => JournalSourceType::SHAREHOLDER->value,
            'source_id' => 'shareholder-456',
            'idempotency_key' => 'source-resolver-integrated',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '10', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '10'],
        ]);

        $this->assertSame(JournalEntry::DRAFT, $draft->status);
        $this->assertSame(1, count($resolver->calls));
        $this->assertSame(JournalSourceType::SHAREHOLDER, $resolver->calls[0][0]);
        $this->assertSame('shareholder-456', $resolver->calls[0][1]);
        $this->assertSame((int) $tenant->id, $resolver->calls[0][2]);
        $this->assertSame((int) $entity->id, $resolver->calls[0][3]);
    }

    #[Test]
    public function test_source_resolver_registry_rejects_ambiguous_resolvers(): void
    {
        $resolver = new class implements JournalSourceReferenceResolver {
            public function supports(JournalSourceType $sourceType): bool
            {
                return $sourceType === JournalSourceType::LOAN;
            }

            public function assertValid(
                JournalSourceType $sourceType,
                string $sourceId,
                int $tenantId,
                int $legalEntityId,
            ): void {
            }
        };

        $registry = new JournalSourceReferenceResolverRegistry([$resolver, $resolver]);

        try {
            $registry->assertValid(JournalSourceType::LOAN, 'loan-1', 10, 20);
            $this->fail('Ambiguous source resolvers must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_type', $exception->errors());
        }
    }

    #[Test]
    public function test_repeating_successful_reversal_returns_the_same_persisted_reversal(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-RETRY-ORIGINAL',
            'effective_date' => '2026-10-01',
            'description' => 'Original for retry-safe reversal',
            'currency' => 'ETB',
            'idempotency_key' => 'retry-original-journal',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '25', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '25'],
        ]);
        $original = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        $first = $service->reverse(
            (int) $tenant->id,
            (int) $user->id,
            (int) $original->id,
            'JV-RETRY-REVERSAL',
            '2026-10-02',
            'Retry idempotency acceptance case',
        );
        $second = $service->reverse(
            (int) $tenant->id,
            (int) $user->id,
            (int) $original->id,
            'JV-RETRY-REVERSAL',
            '2026-10-02',
            'Retry idempotency acceptance case',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(JournalEntry::POSTED, $second->status);
        $this->assertSame(JournalEntryType::REVERSAL, $second->journal_type);
        $this->assertSame((int) $original->id, (int) $second->reversal_of_id);
        $this->assertDatabaseCount('journal_entries', 2);
    }


    #[Test]
    public function test_database_rejects_a_second_reversal_for_the_same_original_journal(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-UNIQUE-REVERSAL-ORIGINAL',
            'effective_date' => '2026-10-01',
            'description' => 'Original for unique reversal constraint',
            'currency' => 'ETB',
            'idempotency_key' => 'unique-reversal-original',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '12', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '12'],
        ]);
        $original = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
        $reversal = $service->reverse(
            (int) $tenant->id,
            (int) $user->id,
            (int) $original->id,
            'JV-UNIQUE-REVERSAL-ONE',
            '2026-10-02',
            'First reversal'
        );

        $duplicate = $reversal->getAttributes();
        unset($duplicate['id'], $duplicate['created_at'], $duplicate['updated_at']);
        $duplicate['journal_number'] = 'JV-UNIQUE-REVERSAL-TWO';
        $duplicate['idempotency_key'] = 'unique-reversal-second-attempt';
        $duplicate['created_at'] = now();
        $duplicate['updated_at'] = now();

        try {
            DB::table('journal_entries')->insert($duplicate);
            $this->fail('The database must reject a second reversal for one original journal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'journal_entries_single_reversal_unique',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('journal_entries', 2);
    }

    #[Test]
    public function test_reversal_cannot_be_accessed_through_another_tenant_context(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $otherTenant = Tenant::factory()->create(['is_active' => true]);
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-TENANT-REVERSAL-ORIGINAL',
            'effective_date' => '2026-10-01',
            'description' => 'Tenant isolation original',
            'currency' => 'ETB',
            'idempotency_key' => 'tenant-isolation-original',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '18', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '18'],
        ]);
        $original = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        try {
            $service->reverse(
                (int) $otherTenant->id,
                (int) $user->id,
                (int) $original->id,
                'JV-CROSS-TENANT-REVERSAL',
                '2026-10-02',
                'Must not cross tenant boundary'
            );
            $this->fail('A tenant must not reverse another tenant\'s journal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal', $exception->errors());
        }

        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $original->id,
            'tenant_id' => $tenant->id,
            'status' => JournalEntry::POSTED,
        ]);
    }


    #[Test]
    public function test_a_posted_journal_cannot_be_posted_a_second_time(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-DOUBLE-POST',
            'effective_date' => '2026-10-01',
            'description' => 'Double posting guard',
            'currency' => 'ETB',
            'idempotency_key' => 'double-post-guard',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '30', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '30'],
        ]);

        $posted = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
        $postedAt = $posted->posted_at?->toISOString();

        try {
            $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
            $this->fail('A posted journal must not be posted again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('journal_entries', [
            'id' => $draft->id,
            'status' => JournalEntry::POSTED,
            'posted_by' => $user->id,
        ]);
        $this->assertSame($postedAt, $posted->fresh()->posted_at?->toISOString());
        $this->assertDatabaseCount('journal_entries', 1);
    }


    #[Test]
    public function test_changed_reversal_retry_is_rejected(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-RETRY-ORIGINAL',
            'effective_date' => '2026-10-01',
            'description' => 'Original journal',
            'currency' => 'ETB',
            'idempotency_key' => 'retry-original',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '40', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '40'],
        ]);
        $original = $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
        $service->reverse((int) $tenant->id, (int) $user->id, (int) $original->id,
            'JV-RETRY-REVERSAL-1', '2026-10-02', 'Approved reversal');

        try {
            $service->reverse((int) $tenant->id, (int) $user->id, (int) $original->id,
                'JV-RETRY-REVERSAL-2', '2026-10-03', 'Changed reversal request');
            $this->fail('Changed retry input must be rejected.');
        } catch (\\Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame(JournalEntry::REVERSED, $original->fresh()->status);
    }


    #[Test]
    public function test_trial_balance_includes_original_reversed_journal_and_posted_counter_entry(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $journals = app(JournalEntryService::class);
        $draft = $journals->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-TRIAL-BALANCE-REVERSAL',
            'effective_date' => '2026-10-01',
            'description' => 'Trial balance reversal regression',
            'currency' => 'ETB',
            'idempotency_key' => 'trial-balance-reversal-original',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '40', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '40'],
        ]);
        $original = $journals->post((int) $tenant->id, (int) $user->id, (int) $draft->id);
        $journals->reverse(
            (int) $tenant->id,
            (int) $user->id,
            (int) $original->id,
            'JV-TRIAL-BALANCE-REVERSAL-COUNTER',
            '2026-10-02',
            'Approved reversal for reporting regression',
        );

        $rows = app(\\MaaleArna\\MASES\\Core\\Domains\\Finance\\Services\\TrialBalanceService::class)
            ->forEntity((int) $tenant->id, (int) $entity->id, '2026-10-02');
        $cashRow = $rows->firstWhere('id', $cash->id);
        $equityRow = $rows->firstWhere('id', $equity->id);

        $this->assertNotNull($cashRow);
        $this->assertNotNull($equityRow);
        $this->assertSame('40', $this->normalizeAmount($cashRow->debit_total));
        $this->assertSame('40', $this->normalizeAmount($cashRow->credit_total));
        $this->assertSame('40', $this->normalizeAmount($equityRow->debit_total));
        $this->assertSame('40', $this->normalizeAmount($equityRow->credit_total));
    }

    private function normalizeAmount(mixed $value): string
    {
        $amount = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            self::fail('Expected a non-negative decimal database aggregate.');
        }

        if (str_contains($amount, '.')) {
            [$whole, $fraction] = explode('.', $amount, 2);
            $fraction = rtrim($fraction, '0');
            $amount = $whole.($fraction === '' ? '' : '.'.$fraction);
        }

        $whole = str_contains($amount, '.') ? explode('.', $amount, 2)[0] : $amount;
        $whole = ltrim($whole, '0') ?: '0';

        return str_contains($amount, '.') ? $whole.'.'.explode('.', $amount, 2)[1] : $whole;
    }


    #[Test]
    public function test_trial_balance_report_returns_exact_balanced_summary_and_net_account_balances(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();
        $service = app(JournalEntryService::class);
        $draft = $service->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id, 'journal_number' => 'JV-REPORT-SUMMARY',
            'effective_date' => '2026-10-10', 'description' => 'Financial report summary',
            'currency' => 'ETB', 'idempotency_key' => 'report-summary-key',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '123.4567', 'credit_amount' => '0'],
            ['account_id' => $equity->id, 'debit_amount' => '0', 'credit_amount' => '123.4567'],
        ]);
        $service->post((int) $tenant->id, (int) $user->id, (int) $draft->id);

        $report = app(FinancialReportService::class)->trialBalance(
            (int) $tenant->id, (int) $entity->id, '2026-10-10',
        );

        $this->assertTrue($report['summary']['is_balanced']);
        $this->assertSame('123.4567', $report['summary']['total_debits']);
        $this->assertSame('123.4567', $report['summary']['total_credits']);
        $this->assertSame('0.0000', $report['summary']['difference']);
        $this->assertSame('123.4567', (string) $report['accounts']->firstWhere('id', $cash->id)->net_debit);
        $this->assertSame('123.4567', (string) $report['accounts']->firstWhere('id', $equity->id)->net_credit);
    }

    #[Test]
    public function test_finance_policies_are_registered_and_period_closure_is_audited(): void
    {
        [$tenant, $user, $entity, $period] = $this->ledgerFixture();

        $this->assertInstanceOf(LegalEntityPolicy::class, Gate::getPolicyFor(LegalEntity::class));
        $this->assertInstanceOf(LedgerAccountPolicy::class, Gate::getPolicyFor(LedgerAccount::class));
        $this->assertInstanceOf(AccountingPeriodPolicy::class, Gate::getPolicyFor(AccountingPeriod::class));
        $this->assertInstanceOf(JournalEntryPolicy::class, Gate::getPolicyFor(JournalEntry::class));

        $closed = app(AccountingPeriodService::class)->close(
            (int) $tenant->id, (int) $user->id, (int) $period->id, 'Approved period close',
        );

        $this->assertSame(AccountingPeriod::CLOSED, $closed->status);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.period.closed',
            'subject_type' => 'accounting_period', 'subject_id' => $period->id,
        ]);
    }


    #[Test]
    public function test_unconfigured_subledger_integration_fails_closed(): void
    {
        [$tenant, $user, $entity] = $this->ledgerFixture();

        try {
            app(SubledgerIntegrationService::class)->postSource(
                JournalSourceType::WALLET,
                (int) $tenant->id,
                (int) $user->id,
                (int) $entity->id,
                'wallet-source-1',
            );
            $this->fail('Subledger posting must remain disabled without an authoritative adapter.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_type', $exception->errors());
        }
    }

    #[Test]
    public function test_entity_account_and_period_opening_lifecycle_events_are_audited(): void
    {
        [$tenant, $user, $entity, $period] = $this->ledgerFixture();

        $createdEntity = app(LegalEntityService::class)->create((int)$tenant->id, [
            'code' => 'AUDIT-ENTITY', 'name_en' => 'Audited Entity', 'base_currency' => 'ETB',
        ], (int)$user->id);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.entity.created',
            'subject_type' => 'legal_entity', 'subject_id' => $createdEntity->id,
        ]);

        $account = app(LedgerAccountService::class)->create((int)$tenant->id, (int)$user->id, [
            'legal_entity_id' => $entity->id, 'code' => 'AUDIT-9000',
            'name_en' => 'Audited Account', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_posting_allowed' => true,
        ]);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.account.created',
            'subject_type' => 'ledger_account', 'subject_id' => $account->id,
        ]);

        $opened = app(AccountingPeriodService::class)->create((int)$tenant->id, [
            'legal_entity_id' => $entity->id, 'code' => '2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ], (int)$user->id);
        $this->assertDatabaseHas('finance_audit_entries', [
            'tenant_id' => $tenant->id, 'event_type' => 'finance.period.opened',
            'subject_type' => 'accounting_period', 'subject_id' => $opened->id,
        ]);
    }

    /** @return array{Tenant, User, LegalEntity, AccountingPeriod, LedgerAccount, LedgerAccount} */

    #[Test]
    public function test_accounting_period_service_rejects_overlapping_periods_for_the_same_entity(): void
    {
        [$tenant, $user, $entity] = $this->ledgerFixture();

        try {
            app(AccountingPeriodService::class)->create((int) $tenant->id, [
                'legal_entity_id' => $entity->id,
                'code' => '2026-Q4',
                'starts_on' => '2026-10-15',
                'ends_on' => '2026-12-31',
            ], (int) $user->id);
            $this->fail('Overlapping accounting periods must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('starts_on', $exception->errors());
        }

        $this->assertDatabaseMissing('accounting_periods', [
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => '2026-Q4',
        ]);
    }


    #[Test]
    public function test_income_statement_aggregates_posted_revenue_and_expenses_for_the_requested_period(): void
    {
        [$tenant, $user, $entity, $period, $cash, $equity] = $this->ledgerFixture();

        $revenue = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => 'REV-TEST',
            'name_en' => 'Test Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);
        $expense = LedgerAccount::query()->create([
            'tenant_id' => $tenant->id,
            'legal_entity_id' => $entity->id,
            'code' => 'EXP-TEST',
            'name_en' => 'Test Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_posting_allowed' => true,
            'status' => 'active',
        ]);

        $journals = app(JournalEntryService::class);
        $revenueDraft = $journals->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-IS-REVENUE',
            'effective_date' => '2026-10-10',
            'description' => 'Income statement revenue fixture',
            'currency' => 'ETB',
            'idempotency_key' => 'income-statement-revenue-fixture',
        ], [
            ['account_id' => $cash->id, 'debit_amount' => '100.0000', 'credit_amount' => '0'],
            ['account_id' => $revenue->id, 'debit_amount' => '0', 'credit_amount' => '100.0000'],
        ]);
        $journals->post((int) $tenant->id, (int) $user->id, (int) $revenueDraft->id);

        $expenseDraft = $journals->createDraft((int) $tenant->id, (int) $user->id, [
            'legal_entity_id' => $entity->id,
            'journal_number' => 'JV-IS-EXPENSE',
            'effective_date' => '2026-10-12',
            'description' => 'Income statement expense fixture',
            'currency' => 'ETB',
            'idempotency_key' => 'income-statement-expense-fixture',
        ], [
            ['account_id' => $expense->id, 'debit_amount' => '40.0000', 'credit_amount' => '0'],
            ['account_id' => $cash->id, 'debit_amount' => '0', 'credit_amount' => '40.0000'],
        ]);
        $journals->post((int) $tenant->id, (int) $user->id, (int) $expenseDraft->id);

        $report = app(FinancialReportService::class)->incomeStatement(
            (int) $tenant->id,
            (int) $entity->id,
            '2026-10-01',
            '2026-10-31',
        );

        $this->assertSame('ETB', $report['currency']);
        $this->assertSame('100.0000', $report['summary']['total_revenue']);
        $this->assertSame('40.0000', $report['summary']['total_expenses']);
        $this->assertSame('60.0000', $report['summary']['net_income']);

        $balanceSheet = app(FinancialReportService::class)->balanceSheet(
            (int) $tenant->id,
            (int) $entity->id,
            '2026-10-31',
        );
        $this->assertSame('60.0000', $balanceSheet['summary']['total_assets']);
        $this->assertSame('60.0000', $balanceSheet['summary']['total_liabilities_and_equity']);
        $this->assertTrue($balanceSheet['summary']['is_balanced']);
    }

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
