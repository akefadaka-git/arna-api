<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization\Calendar\Contracts;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Gregorian ISO civil date without a time or timezone.
 * All-day events and due dates must use this type, never UTC midnight.
 */
final readonly class LocalDate implements JsonSerializable, Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)) {
            throw new InvalidArgumentException('Date must use strict YYYY-MM-DD format.');
        }

        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException('Date is not a valid Gregorian calendar date.');
        }

        return new self($value);
    }

    public static function fromEthiopianDate(int $year, int $month, int $day): self
    {
        $ethiopian = \MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate::fromComponents(
            $year,
            $month,
            $day
        );
        $gregorian = $ethiopian->toGregorian();

        return self::fromString($gregorian->format('Y-m-d'));
    }

    public function value(): string
    {
        return $this->value;
    }

    /** Materialize midnight only when the caller explicitly supplies a timezone. */
    public function atStartOfDay(DateTimeZone $timezone): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $this->value, $timezone)
            ?: throw new InvalidArgumentException('Unable to materialize local date in supplied timezone.');
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
