<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Registries;

use InvalidArgumentException;

/**
 * Stable Documents taxonomy. Legacy symbolic identifiers are retained for
 * permissions and integrations; numeric codes are immutable once issued.
 */
final class DocumentTypeRegistry
{
    /** @var array<string, array{number:string,category:string,name_am:string,name_en:string,direction:string}> */
    public const TYPES = [
        'LET' => ['number' => '01', 'category' => 'correspondence', 'name_am' => 'ደብዳቤ', 'name_en' => 'Letter', 'direction' => 'outgoing'],
        'MEM' => ['number' => '02', 'category' => 'correspondence', 'name_am' => 'ማስታወሻ', 'name_en' => 'Memorandum', 'direction' => 'internal'],
        'LEG' => ['number' => '03', 'category' => 'legal', 'name_am' => 'ሕጋዊ ሰነድ', 'name_en' => 'Legal instrument', 'direction' => 'other'],
        'INV' => ['number' => '04', 'category' => 'finance', 'name_am' => 'የክፍያ መጠየቂያ', 'name_en' => 'Invoice', 'direction' => 'financial'],
        'CER' => ['number' => '05', 'category' => 'certificate', 'name_am' => 'ማረጋገጫ ወረቀት', 'name_en' => 'Certificate', 'direction' => 'outgoing'],
        'RES' => ['number' => '06', 'category' => 'governance', 'name_am' => 'ውሳኔ', 'name_en' => 'Resolution', 'direction' => 'governance'],
        'CIR' => ['number' => '07', 'category' => 'correspondence', 'name_am' => 'ሰርኩላር', 'name_en' => 'Circular', 'direction' => 'outgoing'],
        'RPT' => ['number' => '08', 'category' => 'reporting', 'name_am' => 'ሪፖርት', 'name_en' => 'Report', 'direction' => 'internal'],
        'CON' => ['number' => '09', 'category' => 'legal', 'name_am' => 'ውል', 'name_en' => 'Contract', 'direction' => 'legal'],
        'NOT' => ['number' => '10', 'category' => 'correspondence', 'name_am' => 'ማስታወቂያ', 'name_en' => 'Notice', 'direction' => 'outgoing'],
        'POL' => ['number' => '11', 'category' => 'governance', 'name_am' => 'ፖሊሲ', 'name_en' => 'Policy', 'direction' => 'governance'],
        'PUR' => ['number' => '12', 'category' => 'finance', 'name_am' => 'የግዢ ትዕዛዝ', 'name_en' => 'Purchase order', 'direction' => 'financial'],
        'EXP' => ['number' => '13', 'category' => 'finance', 'name_am' => 'የወጪ ትዕዛዝ', 'name_en' => 'Expense order', 'direction' => 'financial'],
        'INC' => ['number' => '14', 'category' => 'finance', 'name_am' => 'የገቢ ትዕዛዝ', 'name_en' => 'Income order', 'direction' => 'financial'],
        'RCT' => ['number' => '15', 'category' => 'finance', 'name_am' => 'ደረሰኝ', 'name_en' => 'Receipt', 'direction' => 'financial'],
    ];

    /** @return list<string> */
    public const CODES = ['LET', 'MEM', 'LEG', 'INV', 'CER', 'RES', 'CIR', 'RPT', 'CON', 'NOT', 'POL', 'PUR', 'EXP', 'INC', 'RCT'];

    public static function supports(string $code): bool
    {
        try {
            self::numericCode($code);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function numericCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (isset(self::TYPES[$normalized])) {
            return self::TYPES[$normalized]['number'];
        }

        foreach (self::TYPES as $type) {
            if ($type['number'] === $normalized) {
                return $normalized;
            }
        }

        throw new InvalidArgumentException('Unsupported canonical document type code.');
    }

    public static function legacyCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if (isset(self::TYPES[$normalized])) {
            return $normalized;
        }

        foreach (self::TYPES as $legacy => $type) {
            if ($type['number'] === $normalized) {
                return $legacy;
            }
        }

        throw new InvalidArgumentException('Unsupported numeric document type code.');
    }

    /** @return array{number:string,category:string,name_am:string,name_en:string,direction:string} */
    public static function definition(string $code): array
    {
        return self::TYPES[self::legacyCode($code)];
    }

    public static function authorityAction(string $code): string
    {
        return 'documents.issue.' . self::legacyCode($code);
    }
}
