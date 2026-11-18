<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Database\Seeders;

/**
 * Least-privilege role grants for document workflow capabilities.
 *
 * These RBAC grants only establish access to attempt an operation.
 * Governance-controlled actions still require an active, matching
 * GovernanceAuthorityGrant in the same validated tenant/body/position.
 */
final class DocumentsRolePermissionGrants
{
    /** @var array<string, list<string>> */
    public const MAP = [
        'manager' => [
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.submit',
        ],
        'secretary' => [
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.submit',
            'documents.review',
            'documents.return',
        ],
        'board' => [
            'documents.view',
            'documents.review',
            'documents.return',
            'documents.reject',
            'documents.approve',
            'documents.sign',
        ],
        'president' => [
            'documents.view',
            'documents.review',
            'documents.return',
            'documents.reject',
            'documents.approve',
            'documents.sign',
            'documents.issue',
            'documents.withdraw',
            'documents.revoke',
            'documents.supersede',
        ],
        'ceo' => [
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.submit',
            'documents.review',
            'documents.return',
            'documents.reject',
            'documents.approve',
            'documents.sign',
            'documents.issue',
            'documents.withdraw',
            'documents.revoke',
            'documents.supersede',
        ],
    ];
}
