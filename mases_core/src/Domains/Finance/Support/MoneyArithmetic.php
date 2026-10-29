<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Support;

/**
 * Exact non-negative decimal arithmetic at four fractional digits.
 * Monetary values remain decimal strings; no binary floating point is used.
 */
final class MoneyArithmetic
{
    public static function toMinorUnits(string $amount): string
    {
        $amount = trim($amount);
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $amount)) {
            throw new \InvalidArgumentException('Amount must be a non-negative decimal with at most four fractional digits.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $units = ltrim($whole.str_pad($fraction, 4, '0'), '0');
        return $units === '' ? '0' : $units;
    }

    public static function add(string $left, string $right): string
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $carry = 0;
        $result = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = ($i >= 0 ? (int) $left[$i--] : 0)
                + ($j >= 0 ? (int) $right[$j--] : 0) + $carry;
            $result = (string) ($sum % 10).$result;
            $carry = intdiv($sum, 10);
        }
        return self::normalizeInteger($result);
    }

    public static function compare(string $left, string $right): int
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        if (strlen($left) !== strlen($right)) return strlen($left) <=> strlen($right);
        return strcmp($left, $right) <=> 0;
    }

    /** Subtract right from left; left must be greater than or equal to right. */
    public static function subtract(string $left, string $right): string
    {
        $left = self::normalizeInteger($left);
        $right = self::normalizeInteger($right);
        if (self::compare($left, $right) < 0) {
            throw new \InvalidArgumentException('Subtraction result must not be negative.');
        }
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $borrow = 0;
        $result = '';
        while ($i >= 0) {
            $digit = (int) $left[$i--] - $borrow - ($j >= 0 ? (int) $right[$j--] : 0);
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $result = (string) $digit.$result;
        }
        return self::normalizeInteger($result);
    }

    public static function format(string $minorUnits): string
    {
        $minorUnits = self::normalizeInteger($minorUnits);
        $minorUnits = str_pad($minorUnits, 5, '0', STR_PAD_LEFT);
        return substr($minorUnits, 0, -4).'.'.substr($minorUnits, -4);
    }

    private static function normalizeInteger(string $value): string
    {
        if ($value === '' || !ctype_digit($value)) {
            throw new \InvalidArgumentException('Expected a non-negative integer string.');
        }
        return ltrim($value, '0') ?: '0';
    }
}
