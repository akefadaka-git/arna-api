<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Services;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Documents\Registries\DocumentTypeRegistry;

/**
 * Formats the approved human-readable ARNA document number.
 *
 * The full E.C. year remains persisted separately; only its final two
 * digits are rendered in the compact printed representation.
 */
final class DocumentNumberFormatter
{
    public const PREFIX = 'AR';
    public const MAX_SEQUENCE = 9999;

    public function format(
        string $documentTypeCode,
        string $departmentCode,
        string $issuingRoleCode,
        int $sequence,
        int $ethiopianYear,
    ): string {
        $type = strtoupper(trim($documentTypeCode));
        $department = strtoupper(trim($departmentCode));
        $role = strtoupper(trim($issuingRoleCode));

        if (!DocumentTypeRegistry::supports($type)) {
            throw new InvalidArgumentException('Unsupported document type code.');
        }

        if (!preg_match('/^[A-Z0-9_]{2,64}$/', $department)) {
            throw new InvalidArgumentException('Invalid department code for document numbering.');
        }

        if (!preg_match('/^[A-Z0-9_]{2,32}$/', $role)) {
            throw new InvalidArgumentException('Invalid issuing-role code for document numbering.');
        }

        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new InvalidArgumentException('Document sequence must be between 1 and 9999.');
        }

        if ($ethiopianYear < 1) {
            throw new InvalidArgumentException('A positive full Ethiopian Calendar year is required.');
        }

        return sprintf(
            '%s-%s-%s-%s-%04d/%02d',
            self::PREFIX,
            $type,
            $department,
            $role,
            $sequence,
            $ethiopianYear % 100,
        );
    }
}
