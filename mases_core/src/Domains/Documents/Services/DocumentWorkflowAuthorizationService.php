<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use Illuminate\Auth\Access\AuthorizationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Documents\Contracts\DocumentWorkflowAuthorizer;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\Governance\Services\GovernanceAuthorityGrantService;

/**
 * Combines tenant-scoped RBAC with explicit Governance authority.
 *
 * RBAC is always required. Governance authority is an additional
 * requirement for decisions that exercise a governance office; it never
 * replaces tenant membership or the normal permission check.
 */
final class DocumentWorkflowAuthorizationService implements DocumentWorkflowAuthorizer
{
    /** @var list<string> */
    public const GOVERNED_ACTIONS = [
        'documents.review',
        'documents.return',
        'documents.reject',
        'documents.approve',
        'documents.sign',
        'documents.issue',
        'documents.withdraw',
        'documents.revoke',
        'documents.supersede',
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly GovernanceAuthorityGrantService $authorityGrants,
    ) {}

    public function hasPermission(User $actor, int $tenantId, string $permission): bool
    {
        if (!$actor->is_active || $tenantId < 1 || trim($permission) === '') {
            return false;
        }

        // AuthorizationService rejects an explicit tenant that differs from
        // the middleware-validated tenant context. Never trust request input.
        return $this->authorization->hasPermission($permission, $actor, $tenantId);
    }

    public function allowsGovernedAction(
        User $actor,
        int $tenantId,
        int $governanceBodyId,
        int $governancePositionId,
        string $action,
        ?int $documentAuthorUserId = null,
    ): bool {
        if (!in_array($action, self::GOVERNED_ACTIONS, true)) {
            return false;
        }

        // Submitter/author and approver must be different users.
        if ($action === 'documents.approve'
            && $documentAuthorUserId !== null
            && $actor->getKey() === $documentAuthorUserId) {
            return false;
        }

        if (!$this->hasPermission($actor, $tenantId, $action)) {
            return false;
        }

        return $this->authorityGrants->allows(
            $actor,
            $tenantId,
            $governanceBodyId,
            $governancePositionId,
            $action,
        );
    }

    public function requirePermission(User $actor, int $tenantId, string $permission): void
    {
        if (!$this->hasPermission($actor, $tenantId, $permission)) {
            throw new AuthorizationException('The actor lacks the required document permission in the validated tenant.');
        }
    }

    public function requireGovernedAction(
        User $actor,
        int $tenantId,
        int $governanceBodyId,
        int $governancePositionId,
        string $action,
        ?int $documentAuthorUserId = null,
    ): void {
        if (!$this->allowsGovernedAction(
            $actor,
            $tenantId,
            $governanceBodyId,
            $governancePositionId,
            $action,
            $documentAuthorUserId,
        )) {
            throw new AuthorizationException('The actor lacks the required tenant permission or explicit Governance authority for this document action.');
        }
    }
}
