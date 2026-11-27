<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Events;

use MaaleArna\Arna\Core\Domains\Finance\Models\JournalEntry;

final class JournalCancelled extends FinanceLifecycleEvent
{
    public function __construct(JournalEntry $journal, int $actorId, string $reason)
    {
        parent::__construct((int)$journal->tenant_id, (int)$journal->legal_entity_id, $actorId,
            'finance.journal.cancelled', 'journal_entry', (int)$journal->id, ['status' => JournalEntry::DRAFT],
            ['status' => $journal->status, 'cancelled_by' => (int)$journal->cancelled_by,
             'cancelled_at' => optional($journal->cancelled_at)->toISOString()], ['reason' => trim($reason)]);
    }
}
