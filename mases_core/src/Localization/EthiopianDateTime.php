<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

final class EthiopianDateTime implements Stringable
{
    private Carbon $gregorian;
    private EthiopianCalendar $calendar;
    private array $ethiopianDate;

    private function __construct(DateTimeInterface $datetime, ?EthiopianCalendar $calendar = null)
    {
        $this->gregorian = Carbon::instance($datetime);
        $this->calendar = $calendar ?? new EthiopianCalendar();
        $this->ethiopianDate = $this->calendar->toEthiopian($this->gregorian);
    }

    /**
     * Create from a Gregorian DateTimeInterface.
     */
    public static function fromGregorian(DateTimeInterface $datetime, ?EthiopianCalendar $calendar = null): self
    {
        return new self($datetime, $calendar);
    }

    /**
     * Create from a Carbon instance.
     */
    public static function fromCarbon(Carbon $carbon, ?EthiopianCalendar $calendar = null): self
    {
        return new self($carbon, $calendar);
    }

    /**
     * Create from an Ethiopian date (year, month, day, time optional).
     */
    public static function fromEthiopian(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        ?EthiopianCalendar $calendar = null
    ): self {
        $calendar = $calendar ?? new EthiopianCalendar();
        // Convert Ethiopian to Gregorian by creating a Carbon from a known base.
        // We'll use the reference: 1993-01-01 Ethiopian = 2000-09-11 Gregorian.
        $baseEthiopian = ['year' => 1993, 'month' => 1, 'day' => 1];
        $baseGregorian = Carbon::create(2000, 9, 11, 0, 0, 0);

        // Compute difference in days from the base.
        $daysDiff = $calendar->daysBetweenEthiopianDates(
            $baseEthiopian['year'],
            $baseEthiopian['month'],
            $baseEthiopian['day'],
            $year,
            $month,
            $day
        );

        $gregorian = $baseGregorian->copy()->addDays($daysDiff)->setTime($hour, $minute, $second);
        return new self($gregorian, $calendar);
    }

    /**
     * Create from a string in Ethiopian format (e.g., "12 Meskerem 2017 15:30").
     * This is a placeholder; full parsing can be implemented using the formatter later.
     */
    public static function fromEthiopianString(string $string, ?EthiopianCalendar $calendar = null): self
    {
        // For now, we require a simpler approach; we'll implement later with formatter.
        // This method is left for future extension.
        throw new \RuntimeException('Parsing Ethiopian date strings is not yet implemented.');
    }

    /**
     * Get the underlying Carbon (Gregorian) instance.
     */
    public function toCarbon(): Carbon
    {
        return $this->gregorian->copy();
    }

    /**
     * Get the Ethiopian date as an associative array.
     */
    public function toEthiopianArray(string $locale = 'am'): array
    {
        // Recompute with locale if needed
        if ($locale !== 'am' && $locale !== 'en') {
            $locale = 'am';
        }
        // We can re-fetch with the desired locale by calling calendar->toEthiopian again.
        // But we already have the base; we can just add localized names.
        $data = $this->ethiopianDate;
        // Ensure we have names for the requested locale
        $data['month_name'] = $this->calendar->getMonthName($data['month'], $locale);
        $data['day_name'] = $this->calendar->getDayName($data['weekday'], $locale);
        return $data;
    }

    /**
     * Get the Ethiopian year.
     */
    public function getEthiopianYear(): int
    {
        return $this->ethiopianDate['year'];
    }

    /**
     * Get the Ethiopian month (1-13).
     */
    public function getEthiopianMonth(): int
    {
        return $this->ethiopianDate['month'];
    }

    /**
     * Get the Ethiopian day (1-30 or 5/6 for Pagume).
     */
    public function getEthiopianDay(): int
    {
        return $this->ethiopianDate['day'];
    }

    /**
     * Get the Ethiopian weekday (0=Sunday).
     */
    public function getEthiopianWeekday(): int
    {
        return $this->ethiopianDate['weekday'];
    }

    /**
     * Get the month name in the specified locale.
     */
    public function getMonthName(string $locale = 'am'): string
    {
        return $this->calendar->getMonthName($this->getEthiopianMonth(), $locale);
    }

    /**
     * Get the day name in the specified locale.
     */
    public function getDayName(string $locale = 'am'): string
    {
        return $this->calendar->getDayName($this->getEthiopianWeekday(), $locale);
    }

    /**
     * Check if the current Ethiopian year is a leap year.
     */
    public function isEthiopianLeapYear(): bool
    {
        return $this->ethiopianDate['is_leap_year'];
    }

    /**
     * Get the Gregorian date as a string (Y-m-d H:i:s).
     */
    public function toGregorianString(): string
    {
        return $this->gregorian->toDateTimeString();
    }

    /**
     * Format the Ethiopian date using a format string.
     * Supported formats: Y (year), m (month number), d (day), F (month name), D (day name), etc.
     * This is a basic implementation; full formatting will be in EthiopianFormatter.
     */
    public function format(string $format, string $locale = 'am'): string
    {
        $result = '';
        $eth = $this->toEthiopianArray($locale);
        $replacements = [
            'Y' => (string) $eth['year'],
            'm' => str_pad((string) $eth['month'], 2, '0', STR_PAD_LEFT),
            'd' => str_pad((string) $eth['day'], 2, '0', STR_PAD_LEFT),
            'F' => $eth['month_name'],
            'M' => substr($eth['month_name'], 0, 3), // simple abbreviation
            'D' => $eth['day_name'],
            'l' => $eth['day_name'], // full day name
            'H' => $this->gregorian->format('H'),
            'i' => $this->gregorian->format('i'),
            's' => $this->gregorian->format('s'),
            'A' => $this->gregorian->format('A'),
        ];
        // Simple token replacement; we could use strtr but we need to handle escaping.
        // For now, we'll just do basic replacement.
        $tokens = ['Y', 'm', 'd', 'F', 'M', 'D', 'l', 'H', 'i', 's', 'A'];
        foreach ($tokens as $token) {
            if (str_contains($format, $token)) {
                $format = str_replace($token, $replacements[$token] ?? '', $format);
            }
        }
        return $format;
    }

    /**
     * Add days to the Ethiopian date and return a new instance.
     */
    public function addDays(int $days): self
    {
        $newGregorian = $this->gregorian->copy()->addDays($days);
        return new self($newGregorian, $this->calendar);
    }

    /**
     * Subtract days.
     */
    public function subDays(int $days): self
    {
        return $this->addDays(-$days);
    }

    /**
     * Add months (Ethiopian months).
     */
    public function addMonths(int $months): self
    {
        // Convert to Ethiopian, add months, convert back.
        $eth = $this->ethiopianDate;
        $year = $eth['year'];
        $month = $eth['month'] + $months;
        while ($month > 13) {
            $month -= 13;
            $year++;
        }
        while ($month < 1) {
            $month += 13;
            $year--;
        }
        // Ensure day is valid in the target month.
        $day = min($eth['day'], $this->calendar->daysInMonth($year, $month));
        // Use fromEthiopian to create new instance.
        $time = $this->gregorian->format('H:i:s');
        [$hour, $minute, $second] = explode(':', $time);
        return self::fromEthiopian($year, $month, $day, (int)$hour, (int)$minute, (int)$second, $this->calendar);
    }

    /**
     * Add years.
     */
    public function addYears(int $years): self
    {
        $eth = $this->ethiopianDate;
        $year = $eth['year'] + $years;
        // Ensure day is valid in the target year (leap year might affect Pagume).
        $day = min($eth['day'], $this->calendar->daysInMonth($year, $eth['month']));
        $time = $this->gregorian->format('H:i:s');
        [$hour, $minute, $second] = explode(':', $time);
        return self::fromEthiopian($year, $eth['month'], $day, (int)$hour, (int)$minute, (int)$second, $this->calendar);
    }

    /**
     * Compare this date to another EthiopianDateTime.
     * Returns -1, 0, 1.
     */
    public function compareTo(EthiopianDateTime $other): int
    {
        return $this->gregorian->compareTo($other->gregorian);
    }

    /**
     * Check if this date is before another.
     */
    public function isBefore(EthiopianDateTime $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Check if this date is after another.
     */
    public function isAfter(EthiopianDateTime $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Check if this date equals another.
     */
    public function equals(EthiopianDateTime $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /**
     * Get the difference in days between this and another date.
     */
    public function diffInDays(EthiopianDateTime $other): int
    {
        return $this->gregorian->diffInDays($other->gregorian);
    }

    /**
     * Get the difference in Ethiopian years, months, days as an array.
     */
    public function diffInEthiopianUnits(EthiopianDateTime $other): array
    {
        // Convert both to days from reference and compute.
        $days1 = $this->calendar->daysBetweenEthiopianDates(
            0, 1, 1, // epoch: year 0, month 1, day 1 (we'll use toDays method)
            $this->getEthiopianYear(),
            $this->getEthiopianMonth(),
            $this->getEthiopianDay()
        );
        $days2 = $this->calendar->daysBetweenEthiopianDates(
            0, 1, 1,
            $other->getEthiopianYear(),
            $other->getEthiopianMonth(),
            $other->getEthiopianDay()
        );
        $diffDays = abs($days2 - $days1);
        // Convert days to years, months, days (approximate).
        $years = intdiv($diffDays, 365);
        $remaining = $diffDays % 365;
        // Rough month conversion (each month ~30 days)
        $months = intdiv($remaining, 30);
        $days = $remaining % 30;
        return ['years' => $years, 'months' => $months, 'days' => $days];
    }

    /**
     * Get the current Ethiopian date/time.
     */
    public static function now(?EthiopianCalendar $calendar = null): self
    {
        return new self(Carbon::now(), $calendar);
    }

    /**
     * Convert to string (Ethiopian date in default format).
     */
    public function __toString(): string
    {
        return $this->format('d F Y', 'am');
    }
}