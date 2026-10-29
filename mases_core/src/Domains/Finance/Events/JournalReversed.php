<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class JournalReversed extends FinanceLifecycleEvent
{
    public function __construct(JournalEntry $original, JournalEntry $reversal, int $actorId, string $reason)
    {
        parent::__construct((int)$original->tenant_id, (int)$original->legal_entity_id, $actorId,
            'finance.journal.reversed', 'journal_entry', (int)$original->id, ['status' => JournalEntry::POSTED],
            ['status' => $original->status, 'reversed_at' => optional($original->reversed_at)->toISOString()],
            ['reason' => trim($reason), 'reversal_journal_id' => (int)$reversal->id,
             'reversal_journal_number' => (string)$reversal->journal_number]);
    }
}
