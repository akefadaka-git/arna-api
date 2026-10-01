<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * EthiopianDate
 *
 * Immutable value object representing a single day in the Ethiopian
 * calendar (Amete Mihret). Holds only the three calendar components
 * (year, month, day) — time, timezone, and localization are deliberately
 * excluded from the value semantics so that two EthiopianDate instances
 * are equal if and only if they represent the same Ethiopian day.
 *
 * -----------------------------------------------------------------------------
 * Design Principles
 * -----------------------------------------------------------------------------
 *  - PURE VALUE SEMANTICS: equality, comparison, and hashing are based on
 *    (year, month, day) only. This makes the object safe to use as an array
 *    key, cache key, or DTO.
 *  - NO HIDDEN STATE: the object does not cache a timezone or an
 *    EthiopianCalendar instance. Methods that require calendar operations
 *    accept an optional EthiopianCalendar parameter; if omitted, a default
 *    instance with the `Africa/Addis_Ababa` timezone is used.
 *  - IMMUTABLE: all mutating operations (addDays) return a new instance.
 *  - VALIDATED ON CONSTRUCTION: factories call EthiopianCalendar::isValidDate
 *    so an EthiopianDate can never hold an invalid date.
 *  - STRINGABLE: `__toString()` returns the ISO-like "YYYY-MM-DD" form,
 *    making it convenient in log lines and templates.
 *
 * -----------------------------------------------------------------------------
 * Construction
 * -----------------------------------------------------------------------------
 * The constructor is private. Use one of the factory methods:
 *
 *     EthiopianDate::fromComponents(2016, 1, 1);           // explicit
 *     EthiopianDate::fromString('2016-01-01');             // ISO-like
 *     EthiopianDate::fromGregorian(new DateTimeImmutable(...));  // from Gregorian
 *     EthiopianDate::today();                              // now, Addis Ababa
 *
 * -----------------------------------------------------------------------------
 * Usage Examples
 * -----------------------------------------------------------------------------
 *     $d = EthiopianDate::fromComponents(2016, 4, 28);
 *     echo $d;                                  // "2016-04-28"
 *     echo $d->monthName();                     // "ታኅሣሥ"
 *     echo $d->formatFull();                    // "ሰኞ, ታኅሣሥ 28, 2016 ዓ.ም."
 *
 *     $later = $d->addDays(10);
 *     $later->isAfter($d);                      // true
 *     $d->diffInDays($later);                   // 10
 *
 * @package MaaleArna\MASES\Core\Localization\Calendar
 *
 * @property-read int $year  Ethiopian year (>= 1)
 * @property-read int $month Ethiopian month (1–13)
 * @property-read int $day   Ethiopian day within the month
 */
final class EthiopianDate implements JsonSerializable, Stringable
{
    /**
     * Default timezone used when an `EthiopianCalendar` is not supplied.
     */
    private const DEFAULT_TIMEZONE = 'Africa/Addis_Ababa';

    /**
     * Ethiopian year component.
     *
     * @var int
     */
    public readonly int $year;

    /**
     * Ethiopian month component (1–13).
     *
     * @var int
     */
    public readonly int $month;

    /**
     * Ethiopian day component within the month.
     *
     * @var int
     */
    public readonly int $day;

    /**
     * Private constructor — instances must be created via factory methods
     * so validation is always enforced.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     */
    private function __construct(int $year, int $month, int $day)
    {
        $this->year = $year;
        $this->month = $month;
        $this->day = $day;
    }

    // =========================================================================
    // Factories
    // =========================================================================

    /**
     * Create an EthiopianDate from its three components.
     *
     * @param int $year  Ethiopian year (>= 1)
     * @param int $month Ethiopian month (1–13)
     * @param int $day   Ethiopian day (1–30 or 1–5/6 for Pagume)
     *
     * @return self
     *
     * @throws InvalidArgumentException If the date is invalid.
     */
    public static function fromComponents(int $year, int $month, int $day): self
    {
        $calendar = new EthiopianCalendar();

        if (!$calendar->isValidDate($year, $month, $day)) {
            throw new InvalidArgumentException(
                sprintf('Invalid Ethiopian date: %04d-%02d-%02d.', $year, $month, $day)
            );
        }

        return new self($year, $month, $day);
    }

    /**
     * Create an EthiopianDate from the current instant in the given calendar's
     * timezone (default: Africa/Addis_Ababa).
     *
     * @param EthiopianCalendar|null $calendar
     *
     * @return self
     */
    public static function today(?EthiopianCalendar $calendar = null): self
    {
        $calendar ??= new EthiopianCalendar();
        $components = $calendar->today();

        return new self(
            $components['year'],
            $components['month'],
            $components['day']
        );
    }

    /**
     * Create an EthiopianDate from a Gregorian DateTimeInterface.
     *
     * @param DateTimeInterface      $date
     * @param EthiopianCalendar|null $calendar
     *
     * @return self
     */
    public static function fromGregorian(
        DateTimeInterface $date,
        ?EthiopianCalendar $calendar = null
    ): self {
        $calendar ??= new EthiopianCalendar();
        $components = $calendar->toEthiopian($date);

        return new self(
            $components['year'],
            $components['month'],
            $components['day']
        );
    }

    /**
     * Create an EthiopianDate from a string in the ISO-like "YYYY-MM-DD"
     * (or "Y-M-D") format.
     *
     * @param string $date
     *
     * @return self
     *
     * @throws InvalidArgumentException If the string is malformed or the
     *                                  resulting date is invalid.
     */
    public static function fromString(string $date): self
    {
        $trimmed = trim($date);

        // Match "Y-M-D" with optional leading zeros.
        if (preg_match('/^(\d{1,4})-(\d{1,2})-(\d{1,2})$/', $trimmed, $m) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid Ethiopian date string: "%s". Expected "YYYY-MM-DD".', $date)
            );
        }

        return self::fromComponents((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    // =========================================================================
    // Conversion
    // =========================================================================

    /**
     * Convert to a Gregorian DateTimeImmutable.
     *
     * The returned instance is placed at midnight in the given calendar's
     * timezone.
     *
     * @param EthiopianCalendar|null $calendar
     *
     * @return DateTimeImmutable
     */
    public function toGregorian(?EthiopianCalendar $calendar = null): DateTimeImmutable
    {
        $calendar ??= new EthiopianCalendar();

        return $calendar->toGregorian($this->year, $this->month, $this->day);
    }

    /**
     * Return the ISO-like "YYYY-MM-DD" representation (no calendar needed).
     *
     * @return string
     */
    public function toIso(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    public function toArray(): array
    {
        return [
            'year'  => $this->year,
            'month' => $this->month,
            'day'   => $this->day,
        ];
    }

    // =========================================================================
    // Formatting (delegates to EthiopianCalendar)
    // =========================================================================

    /**
     * Format the Ethiopian date using the token-based format.
     *
     * @see EthiopianCalendar::format() for supported tokens.
     *
     * @param string                 $format
     * @param string                 $locale
     * @param EthiopianCalendar|null $calendar
     *
     * @return string
     */
    public function format(
        string $format,
        string $locale = 'am',
        ?EthiopianCalendar $calendar = null
    ): string {
        $calendar ??= new EthiopianCalendar();

        return $calendar->format($this->toGregorian($calendar), $format, $locale);
    }

    /**
     * Format the date as a human-readable string with full names.
     *
     * Example (am): "ሰኞ, መስከረም 1, 2016 ዓ.ም."
     *
     * @param string                 $locale
     * @param EthiopianCalendar|null $calendar
     *
     * @return string
     */
    public function formatFull(
        string $locale = 'am',
        ?EthiopianCalendar $calendar = null
    ): string {
        $calendar ??= new EthiopianCalendar();

        return $calendar->formatFull(
            $this->year,
            $this->month,
            $this->day,
            $this->weekday($calendar),
            $locale
        );
    }

    /**
     * Get the localized month name.
     *
     * @param string                 $locale
     * @param EthiopianCalendar|null $calendar
     *
     * @return string
     */
    public function monthName(
        string $locale = 'am',
        ?EthiopianCalendar $calendar = null
    ): string {
        $calendar ??= new EthiopianCalendar();

        return $calendar->monthName($this->month, $locale);
    }

    /**
     * Get the localized day-of-week name for this date.
     *
     * @param string                 $locale
     * @param EthiopianCalendar|null $calendar
     *
     * @return string
     */
    public function dayName(
        string $locale = 'am',
        ?EthiopianCalendar $calendar = null
    ): string {
        $calendar ??= new EthiopianCalendar();

        return $calendar->dayName($this->weekday($calendar), $locale);
    }

    // =========================================================================
    // Comparison
    // =========================================================================

    /**
     * Value equality — two EthiopianDate instances are equal when they
     * represent the same Ethiopian day.
     *
     * @param self $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }

    /**
     * Three-way comparison.
     *
     * @param self $other
     *
     * @return int -1 if $this < $other, 0 if equal, 1 if $this > $other.
     */
    public function compareTo(self $other): int
    {
        if ($this->year !== $other->year) {
            return $this->year <=> $other->year;
        }
        if ($this->month !== $other->month) {
            return $this->month <=> $other->month;
        }

        return $this->day <=> $other->day;
    }

    /**
     * @param self $other
     *
     * @return bool
     */
    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * @param self $other
     *
     * @return bool
     */
    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    // =========================================================================
    // Arithmetic
    // =========================================================================

    /**
     * Return a new EthiopianDate `$days` calendar days later (or earlier if
     * `$days` is negative).
     *
     * @param int                    $days
     * @param EthiopianCalendar|null $calendar
     *
     * @return self
     */
    public function addDays(int $days, ?EthiopianCalendar $calendar = null): self
    {
        if ($days === 0) {
            return $this;
        }

        $calendar ??= new EthiopianCalendar();
        $result = $calendar->addDays($this->year, $this->month, $this->day, $days);

        return new self($result['year'], $result['month'], $result['day']);
    }

    /**
     * Compute the signed number of calendar days between this date and $other.
     *
     * Positive if $this is later than $other; negative otherwise.
     *
     * @param self                   $other
     * @param EthiopianCalendar|null $calendar
     *
     * @return int
     */
    public function diffInDays(self $other, ?EthiopianCalendar $calendar = null): int
    {
        $calendar ??= new EthiopianCalendar();

        return $calendar->diffInDays(
            [$other->year, $other->month, $other->day],
            [$this->year, $this->month, $this->day]
        );
    }

    /**
     * Get the weekday index (0 = Sunday, …, 6 = Saturday).
     *
     * @param EthiopianCalendar|null $calendar
     *
     * @return int
     */
    public function weekday(?EthiopianCalendar $calendar = null): int
    {
        $calendar ??= new EthiopianCalendar();

        return $calendar->getWeekday($this->year, $this->month, $this->day);
    }

    /**
     * Whether this date falls on an Ethiopian weekend (Friday or Saturday).
     *
     * @param EthiopianCalendar|null $calendar
     *
     * @return bool
     */
    public function isWeekend(?EthiopianCalendar $calendar = null): bool
    {
        $calendar ??= new EthiopianCalendar();

        return $calendar->isWeekend($this->year, $this->month, $this->day);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return a copy of this date with the given calendar's timezone used
     * for subsequent conversions. Since the value object itself is
     * timezone-agnostic, this is purely a convenience helper that returns
     * the same value — it exists to make call sites more readable.
     *
     * @return self
     */
    public function copy(): self
    {
        return new self($this->year, $this->month, $this->day);
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->toIso();
    }

    /**
     * Convenience factory used when the caller already has a timezone but
     * no EthiopianCalendar — kept private to avoid API bloat; callers
     * should construct EthiopianCalendar explicitly.
     *
     * @param string $timezone
     *
     * @return EthiopianCalendar
     */
    private static function makeCalendar(string $timezone): EthiopianCalendar
    {
        return new EthiopianCalendar(new DateTimeZone($timezone));
    }

    /**
     * Default calendar factory used internally when no calendar is supplied.
     *
     * @return EthiopianCalendar
     */
    private static function defaultCalendar(): EthiopianCalendar
    {
        return self::makeCalendar(self::DEFAULT_TIMEZONE);
    }
}