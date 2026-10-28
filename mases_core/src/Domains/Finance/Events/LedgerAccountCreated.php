<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Events;

use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;

final class LedgerAccountCreated extends FinanceLifecycleEvent
{
    public function __construct(LedgerAccount $account, int $actorId)
    {
        parent::__construct((int)$account->tenant_id, (int)$account->legal_entity_id, $actorId,
            'finance.account.created', 'ledger_account', (int)$account->id, null,
            ['code'=>$account->code,'name_en'=>$account->name_en,'name_am'=>$account->name_am,
             'account_type'=>$account->account_type,'normal_balance'=>$account->normal_balance,
             'is_posting_allowed'=>(bool)$account->is_posting_allowed]);
    }
}
