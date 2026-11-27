<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\AI\\Services;

use InvalidArgumentException;
use MaaleArna\\MASES\\Core\\AI\\Contracts\\DomainEvidenceSourceContract;
use MaaleArna\\MASES\\Core\\AI\\Contracts\\ToolPermissionContract;

final class PermissionAwareRetrievalService
{
    public function __construct(
        private readonly DomainEvidenceSourceContract $source,
        private readonly ToolPermissionContract $permissions,
    ) {
    }

    /**
     * Retrieval requires an authenticated actor and tenant scope, and an
     * explicit read-tool grant. Every returned item must carry a source URI
     * and stable evidence identifier; untraceable material is discarded.
     *
     * @return list<array<string,mixed>>
     */
    public function retrieve(string $tool, array $scope, array $query): array
    {
        if (!isset($scope['tenant_id'], $scope['actor_id'])
            || !is_numeric($scope['tenant_id'])
            || !is_numeric($scope['actor_id'])) {
            throw new InvalidArgumentException('Tenant and actor scope are required for AI retrieval.');
        }
        if (!$this->permissions->allows($tool, $scope)) {
            throw new \\Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException(
                'The current actor is not authorized for this ARNA AI retrieval tool.',
            );
        }

        $items = $this->source->retrieve($scope, $query);
        $cited = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || empty($item['source_ref'])
                || empty($item['evidence_id'])
                || !isset($item['content'])) {
                continue;
            }
            $cited[] = $item;
        }

        return $cited;
    }
}
