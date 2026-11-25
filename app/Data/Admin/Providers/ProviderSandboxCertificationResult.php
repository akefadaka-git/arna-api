<?php

declare(strict_types=1);

namespace App\Data\Admin\Providers;

final readonly class ProviderSandboxCertificationResult
{
    /**
     * @param list<array{name: string, status: string, summary: string}> $checks
     */
    public function __construct(
        public bool $passed,
        public string $adapterVersion,
        public string $reference,
        public array $checks,
    ) {
    }
}
