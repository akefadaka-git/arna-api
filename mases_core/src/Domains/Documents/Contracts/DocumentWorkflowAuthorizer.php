<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Contracts;

use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Narrow authorization port consumed by the Documents aggregate.
 * The concrete adapter retains the A3.3.1 RBAC + Governance checks.
 */
interface DocumentWorkflowAuthorizer
{
    public function requirePermission(User $actor, int $tenantId, string $permission): void;

    public function requireGovernedAction(
        User $actor,
        int $tenantId,
        int $governanceBodyId,
        int $governancePositionId,
        string $action,
        ?int $documentAuthorUserId = null,
    ): void;
}
