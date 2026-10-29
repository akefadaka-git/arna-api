<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class JournalPosted extends FinanceLifecycleEvent
{
    public function __construct(JournalEntry $journal, int $actorId)
    {
        parent::__construct((int)$journal->tenant_id, (int)$journal->legal_entity_id, $actorId,
            'finance.journal.posted', 'journal_entry', (int)$journal->id, ['status' => JournalEntry::DRAFT],
            ['status' => $journal->status, 'posted_by' => (int)$journal->posted_by,
             'posted_at' => optional($journal->posted_at)->toISOString()]);
    }
}
