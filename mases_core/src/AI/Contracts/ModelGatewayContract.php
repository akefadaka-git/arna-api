<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\AI\Contracts;

interface ModelGatewayContract
{
    /**
     * Execute a provider-neutral inference request.
     *
     * Implementations must enforce the supplied policy envelope and must
     * never acquire capabilities not explicitly listed in the request.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function generate(array $request): array;

    public function isEnabled(): bool;
}
