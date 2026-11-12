<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Finance\\Support;

/**
 * Explicit currency precision policy for Finance's initial supported set.
 *
 * Unknown codes fail closed. Extending this catalogue requires a reviewed
 * policy change; a syntactically valid three-letter code is not sufficient.
 */
final class FinanceCurrencyPolicy
{
    /** @var array<string, int> */
    private const MINOR_UNITS = [
        'ETB' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CAD' => 2,
        'AUD' => 2, 'CHF' => 2, 'CNY' => 2, 'INR' => 2, 'KES' => 2,
        'TZS' => 2, 'UGX' => 0, 'JPY' => 0, 'KRW' => 0,
        'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JOD' => 3, 'IQD' => 3,
    ];

    public static function minorUnitsFor(string $currency): int
    {
        $code = strtoupper(trim($currency));
        if (!isset(self::MINOR_UNITS[$code])) {
            throw new \\InvalidArgumentException('Currency is not enabled in the Finance precision policy.');
        }

        return self::MINOR_UNITS[$code];
    }

    public static function assertAmountPrecision(string $amount, string $currency): void
    {
        $precision = self::minorUnitsFor($currency);
        if (!preg_match('/^\\d+(?:\\.\\d{1,4})?$/', trim($amount))) {
            throw new \\InvalidArgumentException('Amount must be a non-negative decimal with at most four fractional digits.');
        }

        $fraction = explode('.', trim($amount), 2)[1] ?? '';
        if (strlen($fraction) > $precision) {
            throw new \\InvalidArgumentException('Amount exceeds the configured minor-unit precision for its currency.');
        }
    }
}
