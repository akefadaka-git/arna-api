<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Listeners;

use MaaleArna\MASES\Core\Domains\Finance\Events\FinanceLifecycleEvent;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceAuditEntry;

final class AuditFinanceLifecycleEvent
{
    public function handle(FinanceLifecycleEvent $event): void
    {
        FinanceAuditEntry::query()->create($event->auditPayload());
    }
}
