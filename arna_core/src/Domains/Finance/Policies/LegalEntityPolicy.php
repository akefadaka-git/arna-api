<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Finance\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Finance\Models\LegalEntity;

final class LegalEntityPolicy extends FinancePolicy
{
    public function viewAny(User $user): bool { return $this->allows($user, 'finance.entity.view'); }

    public function view(User $user, LegalEntity $entity): bool { return $this->belongsToCurrentTenant($entity) && $this->allows($user, 'finance.entity.view', (int) $entity->tenant_id); }

    public function create(User $user): bool { return $this->allows($user, 'finance.entity.manage'); }

    public function update(User $user, LegalEntity $entity): bool { return $this->belongsToCurrentTenant($entity) && $this->allows($user, 'finance.entity.manage', (int) $entity->tenant_id); }
}
