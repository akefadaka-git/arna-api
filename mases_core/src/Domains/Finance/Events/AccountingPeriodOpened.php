<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;

final class AccountingPeriodOpened extends FinanceLifecycleEvent
{
    public function __construct(AccountingPeriod $period, int $actorId)
    {
        parent::__construct((int)$period->tenant_id, (int)$period->legal_entity_id, $actorId,
            'finance.period.opened', 'accounting_period', (int)$period->id, null,
            ['status' => $period->status, 'code' => $period->code,
             'starts_on' => (string)$period->starts_on, 'ends_on' => (string)$period->ends_on]);
    }
}
