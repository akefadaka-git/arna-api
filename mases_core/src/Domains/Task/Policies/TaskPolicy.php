<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Task\Policies;

use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Task\Models\Task;
use MaaleArna\MASES\Core\Tenancy\Services\TenantContextService;

/**
 * D08 remains the authority for task visibility.
 * Calendar references are permitted only when this policy permits viewing.
 */
final class TaskPolicy
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly AuthorizationService $authorization,
    ) {
    }

    public function view(User $user, Task $task): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null || (string) $tenantId !== (string) $task->tenant_id) {
            return false;
        }

        if ((string) $task->created_by === (string) $user->getKey()
            || (string) $task->assigned_to_user_id === (string) $user->getKey()) {
            return true;
        }

        return $this->authorization->hasPermission('task.view_all', $user, $tenantId);
    }
}
