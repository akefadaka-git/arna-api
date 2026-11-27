<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Listeners;

use MaaleArna\Arna\Core\Domains\Finance\Events\FinanceLifecycleEvent;
use MaaleArna\Arna\Core\Domains\Finance\Models\FinanceAuditEntry;

final class AuditFinanceLifecycleEvent
{
    public function handle(FinanceLifecycleEvent $event): void
    {
        FinanceAuditEntry::query()->create($event->auditPayload());
    }
}
