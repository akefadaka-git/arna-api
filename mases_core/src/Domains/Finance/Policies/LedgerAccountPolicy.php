<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\LedgerAccount;

final class LedgerAccountPolicy extends FinancePolicy
{
    public function viewAny(User $user): bool { return $this->allows($user, 'finance.account.view'); }

    public function view(User $user, LedgerAccount $account): bool { return $this->belongsToCurrentTenant($account) && $this->allows($user, 'finance.account.view', (int) $account->tenant_id); }

    public function create(User $user): bool { return $this->allows($user, 'finance.account.manage'); }

    public function update(User $user, LedgerAccount $account): bool { return $this->belongsToCurrentTenant($account) && $this->allows($user, 'finance.account.manage', (int) $account->tenant_id); }
}
