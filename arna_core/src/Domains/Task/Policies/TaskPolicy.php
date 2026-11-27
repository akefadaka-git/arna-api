<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Task\Policies;

use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Authorization\Services\AuthorizationService;
use MaaleArna\Arna\Core\Domains\Task\Models\Task;
use MaaleArna\Arna\Core\Tenancy\Services\TenantContextService;

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
