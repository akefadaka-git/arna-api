<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;

/**
 * Formats the canonical ARNA identifier: ማዓ/EE/DD/TT/YYYY/NNNN.
 */
final class DocumentNumberFormatter
{
    public const PREFIX = 'ማዓ';

    public const MAX_SEQUENCE = 9999;

    public function format(
        string $entityCode,
        string $departmentCode,
        string $documentTypeCode,
        int $ethiopianYear,
        int $sequence,
    ): string {
        $entity = $this->normalizeIssuerCode($entityCode, 'EE');
        $department = $this->normalizeIssuerCode($departmentCode, 'DD');
        $type = DocumentTypeRegistry::numericCode($documentTypeCode);

        if ($ethiopianYear < 1000 || $ethiopianYear > 9999) {
            throw new InvalidArgumentException('The full Ethiopian Calendar year must contain exactly four digits.');
        }

        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new InvalidArgumentException('Document sequence must be between 1 and 9999.');
        }

        return sprintf('%s/%s/%s/%s/%04d/%04d', self::PREFIX, $entity, $department, $type, $ethiopianYear, $sequence);
    }

    private function normalizeIssuerCode(string $code, string $segment): string
    {
        $normalized = trim($code);
        if (! preg_match('/^(0[1-9]|[1-9][0-9])$/', $normalized)) {
            throw new InvalidArgumentException($segment.' must be a registered two-digit code from 01 to 99.');
        }

        return $normalized;
    }
}
