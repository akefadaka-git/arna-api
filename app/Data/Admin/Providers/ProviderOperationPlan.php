<?php

declare(strict_types=1);

namespace App\Data\Admin\Providers;

use InvalidArgumentException;

final readonly class ProviderOperationPlan
{
    public string $payloadSha256;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $providerKey,
        public string $adapterVersion,
        public string $operation,
        public string $resourceType,
        public string $resourceIdentifier,
        public array $payload,
        public string $riskTier,
    ) {
        if ($providerKey === '' || $adapterVersion === '' || $operation === '' || $resourceIdentifier === '') {
            throw new InvalidArgumentException('Provider operation identity fields are required.');
        }

        $this->payloadSha256 = self::digest([
            'provider_key' => $providerKey,
            'adapter_version' => $adapterVersion,
            'operation' => $operation,
            'resource_type' => $resourceType,
            'resource_identifier' => $resourceIdentifier,
            'payload' => $payload,
            'risk_tier' => $riskTier,
        ]);
    }

    /**
     * Canonical digest: associative keys are sorted recursively; list order is preserved.
     *
     * @param array<string, mixed> $value
     */
    public static function digest(array $value): string
    {
        $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (array_is_list($item)) {
                return array_map($canonicalize, $item);
            }

            ksort($item);
            foreach ($item as $key => $nested) {
                $item[$key] = $canonicalize($nested);
            }

            return $item;
        };

        $json = json_encode($canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }
}
