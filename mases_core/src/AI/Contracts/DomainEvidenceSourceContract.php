<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\AI\\Contracts;

interface DomainEvidenceSourceContract
{
    /**
     * Retrieve evidence through the owning domain's authorized service.
     *
     * @param array<string,mixed> $scope Must include tenant and actor context.
     * @param array<string,mixed> $query
     * @return array<int,array<string,mixed>> Each item includes a stable source reference.
     */
    public function retrieve(array $scope, array $query): array;
}
