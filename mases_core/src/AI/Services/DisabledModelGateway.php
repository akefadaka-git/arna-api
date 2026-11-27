<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Services;

use MaaleArna\MASES\Core\AI\Contracts\ModelGatewayContract;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Fail-closed default. No provider, credential, network client or model is
 * selected or activated by the ARNA AI platform foundation.
 */
final class DisabledModelGateway implements ModelGatewayContract
{
    public function generate(array $request): array
    {
        throw new ServiceUnavailableHttpException(
            null,
            'ARNA AI model execution is disabled. Provider activation requires a separate Owner approval.',
        );
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
