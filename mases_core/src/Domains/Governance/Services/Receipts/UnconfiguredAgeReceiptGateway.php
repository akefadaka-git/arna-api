<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services\Receipts;

/**
 * Safe default until an external receipt authority and independent witness
 * have been selected, provisioned and reviewed.
 */
final class UnconfiguredAgeReceiptGateway implements AgeReceiptGateway
{
    public function append(array $request): array
    {
        throw new AgeReceiptUnavailable(
            'AGE external receipt authority is not configured; the event cannot be considered externally anchored.',
        );
    }

    public function challengeWitnessHead(array $request): array
    {
        throw new AgeReceiptUnavailable(
            'AGE independent witness is not configured; the witness head cannot be verified.',
        );
    }
}
