<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Services;

use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Finance\Models\FinanceExchangeRate;
use MaaleArna\MASES\Core\Domains\Finance\Support\FinanceCurrencyPolicy;
use MaaleArna\MASES\Core\Domains\Finance\Support\MoneyArithmetic;

/**
 * Deterministic, non-posting FX conversion using an approved, resolved rate.
 * Rate direction is quote-currency units per one base-currency unit.
 */
final class FinanceCurrencyConverter
{
    /**
     * @return array{base_amount:string,base_currency:string,quote_amount:string,quote_currency:string,rate:string,rate_id:int,effective_on:string,rate_source:string,rounding_mode:string}
     */
    public function convert(string $baseAmount, FinanceExchangeRate $rate): array
    {
        if ($rate->status !== FinanceExchangeRate::APPROVED) {
            throw new InvalidArgumentException('Only an approved exchange rate can be used for conversion.');
        }

        $base = strtoupper((string) $rate->base_currency);
        $quote = strtoupper((string) $rate->quote_currency);
        if ($base === $quote) {
            throw new InvalidArgumentException('FX conversion requires distinct base and quote currencies.');
        }

        FinanceCurrencyPolicy::assertAmountPrecision($baseAmount, $base);
        $quotePrecision = FinanceCurrencyPolicy::minorUnitsFor($quote);
        $rateValue = trim((string) $rate->rate);
        if (!preg_match('/^(?:0|[1-9]\d{0,13})(?:\.\d{1,10})?$/', $rateValue) || preg_match('/^0(?:\.0+)?$/', $rateValue)) {
            throw new InvalidArgumentException('Approved exchange rate has an invalid decimal representation.');
        }

        $amountUnits = MoneyArithmetic::toMinorUnits(trim($baseAmount));
        $rateUnits = self::scaledInteger($rateValue, 10);
        $product = self::multiplyIntegers($amountUnits, $rateUnits);

        // amountUnits is scaled by 10^4 and rateUnits by 10^10.
        // Divide by 10^(14 - target precision), rounding half up.
        $discardDigits = 14 - $quotePrecision;
        $padded = str_pad($product, $discardDigits + 1, '0', STR_PAD_LEFT);
        $minorUnits = substr($padded, 0, -$discardDigits);
        $discarded = substr($padded, -$discardDigits);
        if ($discarded !== '' && $discarded[0] >= '5') {
            $minorUnits = MoneyArithmetic::add($minorUnits, '1');
        }

        $effectiveOn = $rate->effective_on;
        $effectiveDate = is_object($effectiveOn) && method_exists($effectiveOn, 'format')
            ? $effectiveOn->format('Y-m-d')
            : (string) $effectiveOn;

        return [
            'base_amount' => self::formatCurrency($amountUnits, FinanceCurrencyPolicy::minorUnitsFor($base)),
            'base_currency' => $base,
            'quote_amount' => self::formatCurrency($minorUnits, $quotePrecision),
            'quote_currency' => $quote,
            'rate' => $rateValue,
            'rate_id' => (int) $rate->id,
            'effective_on' => $effectiveDate,
            'rate_source' => (string) $rate->source,
            'rounding_mode' => 'HALF_UP',
        ];
    }

    private static function scaledInteger(string $decimal, int $scale): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        return ltrim($whole.str_pad($fraction, $scale, '0'), '0') ?: '0';
    }

    /** Arbitrary-length unsigned integer multiplication without floats or extensions. */
    private static function multiplyIntegers(string $left, string $right): string
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $a = strlen($left);
        $b = strlen($right);
        $result = array_fill(0, $a + $b, 0);
        for ($i = $a - 1; $i >= 0; $i--) {
            for ($j = $b - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $value = ((int) $left[$i] * (int) $right[$j]) + $result[$position];
                $result[$position] = $value % 10;
                $result[$position - 1] += intdiv($value, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private static function formatCurrency(string $units, int $precision): string
    {
        $units = ltrim($units, '0') ?: '0';
        if ($precision === 0) {
            return $units;
        }

        $units = str_pad($units, $precision + 1, '0', STR_PAD_LEFT);
        return substr($units, 0, -$precision).'.'.substr($units, -$precision);
    }
}
