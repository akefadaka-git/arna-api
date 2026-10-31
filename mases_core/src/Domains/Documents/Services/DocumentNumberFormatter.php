<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;

/** Formats the canonical Owner-approved Amharic document number. */
final class DocumentNumberFormatter
{
    public const PREFIX = 'ዓርና';
    public const MAX_SEQUENCE = 9999;

    public function format(
        string $bbCode,
        string $documentTypeCode,
        int $sequence,
        int $ethiopianYear,
    ): string {
        $bb = trim($bbCode);
        if (!preg_match('/^[1-9][1-9]$/', $bb)) {
            throw new InvalidArgumentException('BB must contain exactly two digits, each from 1 to 9.');
        }

        try {
            $type = DocumentTypeRegistry::numericCode($documentTypeCode);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('Unsupported document type code.', previous: $exception);
        }

        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new InvalidArgumentException('Document sequence must be between 1 and 9999.');
        }

        if ($ethiopianYear < 1) {
            throw new InvalidArgumentException('A positive full Ethiopian Calendar year is required.');
        }

        return sprintf('%s/%s/%s/%04d/%02d', self::PREFIX, $bb, $type, $sequence, $ethiopianYear % 100);
    }
}
