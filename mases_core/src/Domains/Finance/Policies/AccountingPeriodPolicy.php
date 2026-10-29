<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\AccountingPeriod;

final class AccountingPeriodPolicy extends FinancePolicy
{
    public function viewAny(User $user): bool { return $this->allows($user, 'finance.period.view'); }

    public function view(User $user, AccountingPeriod $period): bool { return $this->belongsToCurrentTenant($period) && $this->allows($user, 'finance.period.view', (int) $period->tenant_id); }

    public function create(User $user): bool { return $this->allows($user, 'finance.period.manage'); }

    public function close(User $user, AccountingPeriod $period): bool { return $this->belongsToCurrentTenant($period) && $this->allows($user, 'finance.period.manage', (int) $period->tenant_id); }
}
