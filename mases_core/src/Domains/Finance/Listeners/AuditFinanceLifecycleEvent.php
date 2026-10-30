<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Listeners;

use Illuminate\Support\Facades\DB;
use LogicException;
use MaaleArna\MASES\Core\Domains\Finance\Events\FinanceLifecycleEvent;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceAuditEntry;
use MaaleArna\MASES\Core\Domains\Finance\Models\JournalEntry;

final class AuditFinanceLifecycleEvent
{
    public function handle(FinanceLifecycleEvent $event): void
    {
        if (str_starts_with($event->eventType, 'finance.journal.')) {
            $journalConnection = (new JournalEntry())->getConnection();
            $auditConnection = (new FinanceAuditEntry())->getConnection();

            if ($journalConnection !== $auditConnection
                || $journalConnection !== DB::connection()
                || $journalConnection->transactionLevel() < 1) {
                throw new LogicException(
                    'Journal lifecycle audit records must use the active journal transaction connection.'
                );
            }
        }

        FinanceAuditEntry::query()->create($event->auditPayload());
    }
}
