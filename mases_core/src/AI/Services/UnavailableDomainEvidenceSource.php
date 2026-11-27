<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\AI\\Services;

use MaaleArna\\MASES\\Core\\AI\\Contracts\\DomainEvidenceSourceContract;
use Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException;

/** No cross-domain evidence is exposed until an owning-domain adapter is installed. */
final class UnavailableDomainEvidenceSource implements DomainEvidenceSourceContract
{
    public function retrieve(array $scope, array $query): array
    {
        throw new ServiceUnavailableHttpException(
            null,
            'ARNA AI retrieval source is not configured. No direct database retrieval is permitted.',
        );
    }
}
