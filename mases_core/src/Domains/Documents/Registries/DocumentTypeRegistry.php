<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Registries;

/**
 * Owner-approved stable numeric document-type registry.
 * Legacy identifiers remain the authorization/catalogue keys.
 */
final class DocumentTypeRegistry
{
    /** @var array<string, string> legacy identifier => numeric TT */
    public const TYPES = [
        'LET' => '01',
        'MEM' => '02',
        'LEG' => '03',
        'INV' => '04',
        'CER' => '05',
        'RES' => '06',
        'CIR' => '07',
        'RPT' => '08',
        'CON' => '09',
        'NOT' => '10',
        'POL' => '11',
    ];

    /** @var list<string> */
    public const CODES = ['LET', 'MEM', 'LEG', 'INV', 'CER', 'RES', 'CIR', 'RPT', 'CON', 'NOT', 'POL'];

    public static function supports(string $code): bool
    {
        $normalized = strtoupper(trim($code));
        return isset(self::TYPES[$normalized]) || in_array($normalized, self::TYPES, true);
    }

    public static function numericCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (isset(self::TYPES[$normalized])) {
            return self::TYPES[$normalized];
        }

        if (in_array($normalized, self::TYPES, true)) {
            return $normalized;
        }

        throw new \InvalidArgumentException('Unsupported canonical document type code.');
    }

    public static function legacyCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (isset(self::TYPES[$normalized])) {
            return $normalized;
        }

        $legacy = array_search($normalized, self::TYPES, true);
        if ($legacy === false) {
            throw new \InvalidArgumentException('Unsupported numeric document type code.');
        }

        return (string) $legacy;
    }

    public static function authorityAction(string $code): string
    {
        return 'documents.issue.' . self::legacyCode($code);
    }
}
