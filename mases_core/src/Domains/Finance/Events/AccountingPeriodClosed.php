<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;

final class AccountingPeriodClosed extends FinanceLifecycleEvent
{
    public function __construct(AccountingPeriod $period, int $actorId, string $reason)
    {
        parent::__construct((int)$period->tenant_id, (int)$period->legal_entity_id, $actorId,
            'finance.period.closed', 'accounting_period', (int)$period->id, ['status' => AccountingPeriod::OPEN],
            ['status' => $period->status, 'closed_at' => optional($period->closed_at)->toISOString()],
            ['reason' => trim($reason)]);
    }
}
