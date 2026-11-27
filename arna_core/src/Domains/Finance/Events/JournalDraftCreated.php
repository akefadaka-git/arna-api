<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Events;

use MaaleArna\Arna\Core\Domains\Finance\Models\JournalEntry;

final class JournalDraftCreated extends FinanceLifecycleEvent
{
    public function __construct(JournalEntry $journal)
    {
        parent::__construct((int)$journal->tenant_id, (int)$journal->legal_entity_id, (int)$journal->created_by,
            'finance.journal.draft_created', 'journal_entry', (int)$journal->id, null,
            ['status' => $journal->status, 'journal_number' => $journal->journal_number,
             'effective_date' => (string)$journal->effective_date, 'currency' => $journal->currency],
            ['accounting_period_id' => (int)$journal->accounting_period_id]);
    }
}
