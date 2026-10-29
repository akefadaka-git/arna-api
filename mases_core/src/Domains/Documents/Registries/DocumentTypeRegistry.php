<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Registries;

/**
 * Canonical supported document-type codes for the Documents domain.
 *
 * Type-specific issuance authority is always represented as
 * "documents.issue.{TYPE}" and must be granted explicitly by Governance.
 */
final class DocumentTypeRegistry
{
    /** @var list<string> */
    public const CODES = ['LET', 'MEM', 'LEG', 'INV', 'CER', 'RES', 'CIR', 'RPT', 'CON', 'NOT', 'POL'];

    public static function supports(string $code): bool
    {
        return in_array(strtoupper(trim($code)), self::CODES, true);
    }

    public static function authorityAction(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (!self::supports($normalized)) {
            throw new \InvalidArgumentException('Unsupported canonical document type code.');
        }

        return 'documents.issue.' . $normalized;
    }
}
