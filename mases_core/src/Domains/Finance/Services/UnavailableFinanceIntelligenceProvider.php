<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use MaaleArna\MASES\Core\Domains\Finance\Contracts\FinanceIntelligenceProviderContract;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/** Safe default until an Owner-approved provider adapter and credentials are configured. */
final class UnavailableFinanceIntelligenceProvider implements FinanceIntelligenceProviderContract
{
    public function generateProposal(array $task, array $evidence): array
    {
        throw new ServiceUnavailableHttpException(
            null,
            'Finance intelligence provider is not configured. Evidence retrieval remains available; proposal generation is disabled.',
        );
    }
}
