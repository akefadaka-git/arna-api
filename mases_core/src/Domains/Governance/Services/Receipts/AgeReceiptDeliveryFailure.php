<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Services\Receipts;

use RuntimeException;

final class AgeReceiptDeliveryFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly bool $quarantine = false,
        public readonly string $failureCode = 'DELIVERY_FAILURE',
    ) {
        parent::__construct($message);
    }
}
