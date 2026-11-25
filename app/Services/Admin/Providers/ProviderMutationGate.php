<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use RuntimeException;

final class ProviderMutationGate
{
    public function enabled(): bool
    {
        return config('services.arna_provider_mutations.enabled', false) === true;
    }

    public function sandboxEnabled(): bool
    {
        return $this->enabled()
            && config('services.arna_provider_mutations.sandbox_enabled', false) === true;
    }

    public function assertSandboxEnabled(string $environment): void
    {
        if ($environment !== 'sandbox' || ! $this->sandboxEnabled()) {
            throw new RuntimeException('Provider execution is restricted to explicitly enabled isolated sandboxes.');
        }
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('External provider mutations are disabled by the platform safety gate.');
        }
    }
}
