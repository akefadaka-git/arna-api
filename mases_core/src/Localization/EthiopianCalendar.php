<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;

final class EthiopianCalendar
{
    /**
     * Ethiopian month names (Amharic and English).
     */
    private array $monthNames;

    /**
     * Ethiopian day names (Amharic and English).
     */
    private array $dayNames;

    /**
     * Ethiopian year offset (difference between Gregorian and Ethiopian years).
     */
    private int $yearOffset;

    /**
     * Ethiopian months have 30 days except the last (Pagume) which has 5 or 6 days.
     */
    private const MONTH_DAYS = [
        1 => 30,  // Meskerem
        2 => 30,  // Tikimt
        3 => 30,  // Hidar
        4 => 30,  // Tahsas
        5 => 30,  // Tir
        6 => 30,  // Yekatit
        7 => 30,  // Megabit
        8 => 30,  // Miyazia
        9 => 30,  // Ginbot
        10 => 30, // Sene
        11 => 30, // Hamle
        12 => 30, // Nehasse
        13 => 5,  // Pagume (6 in leap year)
    ];

    public function __construct(?array $config = null)
    {
        // Use config values or fallback defaults
        $config = $config ?: config('mases_localization', []);
        $ethiopian = $config['ethiopian'] ?? [];
        $this->yearOffset = (int) ($ethiopian['year_offset'] ?? 7);
        $this->monthNames = $ethiopian['month_names'] ?? [
            'am' => ['መስከረም', 'ጥቅምት', 'ህዳር', 'ታህሳስ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'],
            'en' => ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehasse', 'Pagume'],
        ];
        $this->dayNames = $config['day_names'] ?? [
            'am' => ['እሑድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'አርብ', 'ቅዳሜ'],
            'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ];
    }

    /**
     * Convert a Gregorian date (Carbon/DateTime) to Ethiopian date.
     *
     * Returns an array with keys: year, month, day, month_name, day_name, weekday, is_leap_year.
     *
     * @param DateTimeInterface $date
     * @param string $locale 'am' or 'en'
     * @return array
     */
    public function toEthiopian(DateTimeInterface $date, string $locale = 'am'): array
    {
        $carbon = Carbon::instance($date);
        $gregorianYear = (int) $carbon->format('Y');
        $gregorianMonth = (int) $carbon->format('n');
        $gregorianDay = (int) $carbon->format('j');

        // Calculate Ethiopian year: Gregorian - 7 or 8 depending on month
        $ethiopianYear = $gregorianYear - $this->yearOffset;
        if ($gregorianMonth < 9) {
            $ethiopianYear--;
        }

        // Calculate day of year (1-based) in Gregorian
        $dayOfYear = (int) $carbon->format('z') + 1; // 0-based to 1-based

        // Ethiopian new year starts on September 11 (or 12 in leap years)
        // Actually, the offset is 8 months (or 7) difference, we need precise calculation.
        // We'll use a known algorithm: Compute the number of days since the start of the Ethiopian year.

        // Find Ethiopian month and day
        // We'll use the formula: Ethiopian month = (Gregorian day of year + offset) / 30
        // But we need to handle the leap year.

        // For simplicity, we'll use a proven algorithm that uses the Gregorian date.
        // Reference: https://en.wikipedia.org/wiki/Ethiopian_calendar

        // Determine if Ethiopian year is leap
        $isEthiopianLeap = $this->isEthiopianLeapYear($ethiopianYear);

        // Ethiopian New Year (Enkutatash) is Sep 11 (Gregorian) in common years, Sep 12 in leap years.
        // Actually, the Gregorian date of Enkutatash is September 11 in most years, but in leap years it's September 12.
        // The Ethiopian calendar has 13 months, last month has 5 or 6 days.

        // We can compute the Ethiopian date by counting days from a known reference point.
        // Known reference: 2000-09-11 Gregorian = Ethiopian 1993-01-01 (Meskerem 1).
        // We'll use that as a base.

        $baseGregorian = Carbon::create(2000, 9, 11);
        $baseEthiopian = ['year' => 1993, 'month' => 1, 'day' => 1];

        $diffDays = (int) $baseGregorian->diffInDays($carbon);

        // Now compute the Ethiopian date by adding $diffDays to the base Ethiopian date.
        return $this->addDaysToEthiopianDate($baseEthiopian['year'], $baseEthiopian['month'], $baseEthiopian['day'], $diffDays, $locale);
    }

    /**
     * Add a number of days to an Ethiopian date.
     */
    private function addDaysToEthiopianDate(int $year, int $month, int $day, int $days, string $locale): array
    {
        $currentYear = $year;
        $currentMonth = $month;
        $currentDay = $day;

        while ($days > 0) {
            $daysInMonth = $this->daysInMonth($currentYear, $currentMonth);
            $daysRemainingInMonth = $daysInMonth - $currentDay;

            if ($days <= $daysRemainingInMonth) {
                $currentDay += $days;
                $days = 0;
            } else {
                $days -= ($daysRemainingInMonth + 1);
                $currentDay = 1;
                $currentMonth++;
                if ($currentMonth > 13) {
                    $currentMonth = 1;
                    $currentYear++;
                }
            }
        }

        // Get month and day names
        $monthName = $this->getMonthName($currentMonth, $locale);
        $dayOfWeek = $this->getDayOfWeek($currentYear, $currentMonth, $currentDay);

        return [
            'year' => $currentYear,
            'month' => $currentMonth,
            'day' => $currentDay,
            'month_name' => $monthName,
            'month_name_en' => $this->getMonthName($currentMonth, 'en'),
            'day_name' => $this->getDayName($dayOfWeek, $locale),
            'day_name_en' => $this->getDayName($dayOfWeek, 'en'),
            'weekday' => $dayOfWeek, // 0=Sunday
            'is_leap_year' => $this->isEthiopianLeapYear($currentYear),
        ];
    }

    /**
     * Check if an Ethiopian year is a leap year.
     */
    public function isEthiopianLeapYear(int $year): bool
    {
        // In the Ethiopian calendar, leap years occur every 4 years,
        // but the calculation is based on the Coptic calendar.
        // Ethiopian leap year is when (year + 1) % 4 == 0.
        return (($year + 1) % 4) === 0;
    }

    /**
     * Get the number of days in a given Ethiopian month.
     */
    public function daysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException("Invalid Ethiopian month: {$month}");
        }
        if ($month === 13) {
            return $this->isEthiopianLeapYear($year) ? 6 : 5;
        }
        return self::MONTH_DAYS[$month] ?? 30;
    }

    /**
     * Get the name of an Ethiopian month.
     */
    public function getMonthName(int $month, string $locale = 'am'): string
    {
        if (!isset($this->monthNames[$locale][$month - 1])) {
            throw new InvalidArgumentException("Unknown month or locale: {$month}, {$locale}");
        }
        return $this->monthNames[$locale][$month - 1];
    }

    /**
     * Get the name of a day of the week (0=Sunday).
     */
    public function getDayName(int $weekday, string $locale = 'am'): string
    {
        if (!isset($this->dayNames[$locale][$weekday])) {
            throw new InvalidArgumentException("Unknown weekday or locale: {$weekday}, {$locale}");
        }
        return $this->dayNames[$locale][$weekday];
    }

    /**
     * Compute the day of week for an Ethiopian date (0=Sunday).
     * This is based on known reference: 1993-01-01 (Meskerem 1) was a Monday (1).
     */
    private function getDayOfWeek(int $year, int $month, int $day): int
    {
        // Known: 1993-01-01 is Monday (1)
        $baseYear = 1993;
        $baseMonth = 1;
        $baseDay = 1;
        $baseWeekday = 1; // Monday

        $days = $this->daysBetweenEthiopianDates($baseYear, $baseMonth, $baseDay, $year, $month, $day);
        return ($baseWeekday + $days) % 7;
    }

    /**
     * Calculate total days between two Ethiopian dates (inclusive? exclusive?).
     * Here we compute difference in days.
     */
    private function daysBetweenEthiopianDates(int $y1, int $m1, int $d1, int $y2, int $m2, int $d2): int
    {
        // Compute day count from epoch (year 0, month 1, day 1)
        $days1 = $this->toDays($y1, $m1, $d1);
        $days2 = $this->toDays($y2, $m2, $d2);
        return $days2 - $days1;
    }

    /**
     * Convert Ethiopian date to total days since a reference epoch (e.g., year 0).
     */
    private function toDays(int $year, int $month, int $day): int
    {
        $days = 0;
        for ($y = 0; $y < $year; $y++) {
            $days += $this->isEthiopianLeapYear($y) ? 366 : 365;
        }
        for ($m = 1; $m < $month; $m++) {
            $days += $this->daysInMonth($year, $m);
        }
        $days += $day - 1;
        return $days;
    }
}