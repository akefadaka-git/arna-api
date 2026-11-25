<?php

declare(strict_types=1);

namespace App\Data\Admin\Providers;

final readonly class ProviderExecutionResult
{
    /**
     * @param array<string, scalar|null> $sanitizedMetadata
     */
    public function __construct(
        public string $status,
        public ?string $providerReference = null,
        public ?string $summary = null,
        public array $sanitizedMetadata = [],
    ) {
    }
}
