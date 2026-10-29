<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Finance\Models\LegalEntity;

final class LegalEntityPolicy extends FinancePolicy
{
    public function viewAny(User $user): bool { return $this->allows($user, 'finance.entity.view'); }

    public function view(User $user, LegalEntity $entity): bool { return $this->belongsToCurrentTenant($entity) && $this->allows($user, 'finance.entity.view', (int) $entity->tenant_id); }

    public function create(User $user): bool { return $this->allows($user, 'finance.entity.manage'); }

    public function update(User $user, LegalEntity $entity): bool { return $this->belongsToCurrentTenant($entity) && $this->allows($user, 'finance.entity.manage', (int) $entity->tenant_id); }
}
