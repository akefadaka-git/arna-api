<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Listeners;

use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Domains\Finance\Events\JournalPosted;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceOutboxMessage;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class PersistFinanceJournalPostedOutbox
{
    /**
     * Runs synchronously inside JournalEntryService::post's transaction.
     * The journal and its durable event therefore commit or roll back together.
     */
    public function handle(JournalPosted $event): void
    {
        $journal = JournalEntry::query()
            ->where('tenant_id', $event->tenantId)
            ->where('legal_entity_id', $event->legalEntityId)
            ->with(['lines' => static fn ($query) => $query->orderBy('line_number')])
            ->findOrFail($event->subjectId);

        $eventKey = 'finance.journal.posted:'.$event->tenantId.':'.$journal->id;
        $payload = [
            'schema_version' => 1,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'finance.journal.posted',
            'occurred_at' => optional($journal->posted_at)->toISOString(),
            'actor_id' => $event->actorId,
            'tenant_id' => $event->tenantId,
            'legal_entity_id' => $event->legalEntityId,
            'journal' => [
                'id' => (int) $journal->id,
                'journal_number' => (string) $journal->journal_number,
                'journal_type' => (string) $journal->journal_type->value,
                'effective_date' => $journal->effective_date->format('Y-m-d'),
                'currency' => (string) $journal->currency,
                'description' => (string) $journal->description,
                'source_type' => (string) $journal->source_type,
                'source_id' => $journal->source_id === null ? null : (string) $journal->source_id,
                'posted_at' => optional($journal->posted_at)->toISOString(),
            ],
            'lines' => $journal->lines->map(static fn ($line): array => [
                'line_number' => (int) $line->line_number,
                'account_id' => (int) $line->account_id,
                'debit_amount' => (string) $line->debit_amount,
                'credit_amount' => (string) $line->credit_amount,
                'description' => $line->description,
            ])->values()->all(),
        ];

        FinanceOutboxMessage::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'tenant_id' => $event->tenantId,
                'legal_entity_id' => $event->legalEntityId,
                'event_id' => $payload['event_id'],
                'event_type' => $event->eventType,
                'subject_type' => $event->subjectType,
                'subject_id' => $event->subjectId,
                'payload' => $payload,
                'status' => FinanceOutboxMessage::PENDING,
                'attempts' => 0,
                'available_at' => now(),
            ],
        );
    }
}
