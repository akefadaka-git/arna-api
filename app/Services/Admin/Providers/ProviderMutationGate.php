<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

final class ProviderMutationGate
{
    public function enabled(): bool
    {
        return config('services.arna_provider_mutations.enabled', false) === true;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new \RuntimeException('External provider mutations are disabled by the platform safety gate.');
        }
    }
}
