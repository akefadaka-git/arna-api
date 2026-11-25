<?php

declare(strict_types=1);

namespace App\Services\Admin\Providers;

use App\Contracts\Admin\Providers\ProviderAdapter;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class ProviderAdapterRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters = [];

    public function register(ProviderAdapter $adapter): void
    {
        $key = $adapter->providerKey();

        if (! preg_match('/^[a-z][a-z0-9_-]{1,99}$/', $key)) {
            throw new InvalidArgumentException('Provider adapter key is invalid.');
        }

        if (isset($this->adapters[$key])) {
            throw new LogicException('A provider adapter is already registered for this key.');
        }

        $this->adapters[$key] = $adapter;
    }

    public function resolve(string $providerKey): ProviderAdapter
    {
        return $this->adapters[$providerKey]
            ?? throw new RuntimeException('No reviewed provider adapter is registered for this provider.');
    }

    public function has(string $providerKey): bool
    {
        return isset($this->adapters[$providerKey]);
    }

    /** @return list<string> */
    public function registeredKeys(): array
    {
        $keys = array_keys($this->adapters);
        sort($keys);

        return $keys;
    }
}
