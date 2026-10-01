<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * EthiopianCalendar
 *
 * Central, immutable service for Ethiopian calendar operations in the MASES
 * platform. Provides:
 *
 *   1. Ethiopian ↔ Gregorian date conversion (via Julian Day Number).
 *   2. Ethiopian leap-year rules, Pagume handling, and validation.
 *   3. Localized month/day names in Amharic and English.
 *   4. Token-based date formatting analogous to PHP's date().
 *   5. Ethiopian local time conversion (6-hour offset).
 *   6. Time-of-day phrases (ከጠዋቱ, ከሰዓት, ምሽቱ …).
 *   7. Date arithmetic (add/diff/isWeekend/getWeekday).
 *   8. Ethiopian fiscal-year and quarter ranges.
 *   9. Business-day calculations with holiday awareness.
 *  10. Full catalog of Ethiopian public and religious holidays.
 *  11. Countdown utilities for holidays and key events.
 *  12. Maale New Year anniversary metadata.
 *
 * -----------------------------------------------------------------------------
 * Ethiopian Calendar Structure
 * -----------------------------------------------------------------------------
 *   - Months 1–12: 30 days each
 *   - Month 13 (Pagume): 5 days common year / 6 days leap year
 *   - Ethiopian leap-year rule (for Pagume): (year % 4) === 3
 *   - Ethiopian Christmas rule (for Tahsas 28/29): (year % 4) === 0
 *     — because Leddet is fixed on Gregorian 7 January and the Ethiopian
 *       New Year shifts by one day in the Ethiopian year that follows a
 *       leap year.
 *
 * -----------------------------------------------------------------------------
 * Conversion Strategy
 * -----------------------------------------------------------------------------
 * All conversions use the Julian Day Number (JDN) as an intermediate
 * representation:
 *
 *     Ethiopian  →  JDN  →  Gregorian
 *     Gregorian  →  JDN  →  Ethiopian
 *
 * The Ethiopian epoch (1 Meskerem 1 EC) is JDN 1724221, corresponding to
 * 29 August 8 CE (Julian) / 27 August 8 CE (proleptic Gregorian).
 *
 * -----------------------------------------------------------------------------
 * Timezone & Gregorian Input
 * -----------------------------------------------------------------------------
 * Gregorian `DateTimeInterface` inputs are first localized to the configured
 * display timezone (default `Africa/Addis_Ababa`) before the calendar day is
 * computed. This ensures the Ethiopian day reflects the user's local day,
 * not the UTC day.
 *
 * All Ethiopian dates returned as `DateTimeImmutable` are also in the
 * configured display timezone.
 *
 * @package MaaleArna\MASES\Core\Localization\Calendar
 */
final class EthiopianCalendar
{
    // =========================================================================
    // Constants – Epoch and Calendar Names
    // =========================================================================

    /**
     * Julian Day Number of the Ethiopian epoch (1 Meskerem 1 EC).
     *
     * Corresponds to 29 August 8 CE (Julian) / 27 August 8 CE (proleptic Gregorian).
     */
    private const ETHIOPIAN_EPOCH_JDN = 1724221;

    /**
     * Amharic month names, indexed by Ethiopian month (1–13).
     *
     * @var array<int, string>
     */
    private const MONTH_NAMES_AM = [
        1  => 'መስከረም',
        2  => 'ጥቅምት',
        3  => 'ኅዳር',
        4  => 'ታኅሣሥ',
        5  => 'ጥር',
        6  => 'የካቲት',
        7  => 'መጋቢት',
        8  => 'ሚያዝያ',
        9  => 'ግንቦት',
        10 => 'ሰኔ',
        11 => 'ሐምሌ',
        12 => 'ነሐሴ',
        13 => 'ጳጉሜን',
    ];

    /**
     * English transliterated month names, indexed by Ethiopian month (1–13).
     *
     * @var array<int, string>
     */
    private const MONTH_NAMES_EN = [
        1  => 'Meskerem',
        2  => 'Tikimt',
        3  => 'Hidar',
        4  => 'Tahsas',
        5  => 'Tir',
        6  => 'Yekatit',
        7  => 'Megabit',
        8  => 'Miyazya',
        9  => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    /**
     * Amharic day-of-week names, indexed 0 (Sunday) to 6 (Saturday).
     *
     * @var array<int, string>
     */
    private const DAY_NAMES_AM = [
        0 => 'እሑድ',
        1 => 'ሰኞ',
        2 => 'ማክሰኞ',
        3 => 'ረቡዕ',
        4 => 'ሐሙስ',
        5 => 'ዓርብ',
        6 => 'ቅዳሜ',
    ];

    /**
     * English day-of-week names, indexed 0 (Sunday) to 6 (Saturday).
     *
     * @var array<int, string>
     */
    private const DAY_NAMES_EN = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * Time-of-day phrases in Amharic, keyed by period name.
     *
     * Each entry: [start_hour_decimal, end_hour_decimal, phrase].
     * The end of each interval is exclusive; the last interval includes 24.0.
     *
     * @var array<string, array{0: float, 1: float, 2: string}>
     */
    private const TIME_PERIODS = [
        'midnight'      => [0.0,  4.0,  'እኩለ ሌሊት'],
        'early_morning' => [4.0,  6.0,  'ከንጋቱ'],
        'morning'       => [6.0,  9.0,  'ከጠዋቱ'],
        'late_morning'  => [9.0,  12.0, 'ከረፋዱ'],
        'noon'          => [12.0, 12.5, 'እኩለ ቀን'],
        'afternoon'     => [12.5, 17.5, 'ከሰዓት'],
        'evening'       => [17.5, 19.0, 'ከማምሻው'],
        'night'         => [19.0, 24.0, 'ምሽቱ'],
    ];

    /**
     * Human-readable holiday names in Amharic and English.
     *
     * @var array<string, array{am: string, en: string}>
     */
    private const HOLIDAY_NAMES = [
        'enkutatash'     => ['am' => 'እንቁጣጣሽ',                    'en' => 'Ethiopian New Year'],
        'meskel'         => ['am' => 'መስቀል',                       'en' => 'Finding of the True Cross'],
        'nations_day'    => ['am' => 'የብሔር ብሔረሰቦች ቀን',           'en' => 'Nations, Nationalities and Peoples Day'],
        'gena'           => ['am' => 'ገና',                         'en' => 'Christmas Eve'],
        'leddet'         => ['am' => 'ልደት',                        'en' => 'Ethiopian Christmas'],
        'maale_new_year' => ['am' => 'ዶኦማ',                        'en' => 'Maale New Year'],
        'timket'         => ['am' => 'ጥምቀት',                       'en' => 'Epiphany'],
        'martyrs_day'    => ['am' => 'የሰማዕታት ቀን',                  'en' => 'Martyrs\' Day'],
        'adwa_day'       => ['am' => 'የአድዋ ድል በዓል',               'en' => 'Adwa Victory Day'],
        'good_friday'    => ['am' => 'ስቅለት',                       'en' => 'Good Friday'],
        'fasika'         => ['am' => 'ፋሲካ',                        'en' => 'Easter'],
        'palm_sunday'    => ['am' => 'ሆሳእና',                       'en' => 'Palm Sunday'],
        'labour_day'     => ['am' => 'የሰራተኞች ቀን',                 'en' => 'International Labour Day'],
        'patriots_day'   => ['am' => 'የአርበኞች ቀን',                 'en' => 'Patriots\' Victory Day'],
        'eid_fitr'       => ['am' => 'ዒድ አል ፈጥር',                 'en' => 'Eid al-Fitr (approx.)'],
        'eid_adha'       => ['am' => 'ዒድ አል አድሐ',                 'en' => 'Eid al-Adha (approx.)'],
        'mawlid'         => ['am' => 'መውልድ',                       'en' => 'Mawlid (approx.)'],
    ];

    /**
     * Holiday type identifiers (used by `getHolidaysByType()`).
     */
    public const HOLIDAY_TYPE_NATIONAL  = 'national';
    public const HOLIDAY_TYPE_RELIGIOUS = 'religious';
    public const HOLIDAY_TYPE_CULTURAL  = 'cultural';
    public const HOLIDAY_TYPE_ISLAMIC   = 'islamic';

    /**
     * First year of Maale New Year celebrations (used for anniversary math).
     */
    private const MAALE_NEW_YEAR_BASE_YEAR = 2006;

    /**
     * Weekend day indices (Ethiopian convention: Friday and Saturday).
     *
     * @var array<int, int>
     */
    private const WEEKEND_DAYS = [5, 6];

    // =========================================================================
    // Instance State
    // =========================================================================

    /**
     * Display timezone used for all conversions.
     *
     * @var DateTimeZone
     */
    private DateTimeZone $timezone;

    /**
     * Create a new EthiopianCalendar instance.
     *
     * @param DateTimeZone|null $timezone Optional display timezone.
     *                                    Defaults to Africa/Addis_Ababa.
     */
    public function __construct(?DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new DateTimeZone('Africa/Addis_Ababa');
    }

    /**
     * Get the display timezone used by this calendar instance.
     *
     * @return DateTimeZone
     */
    public function getTimezone(): DateTimeZone
    {
        return $this->timezone;
    }

    // =========================================================================
    // Section 1 — Leap Year, Days-in-Month/Year, Validation
    // =========================================================================

    /**
     * Determine whether the given Ethiopian year is a leap year
     * (i.e., Pagume has 6 days).
     *
     * The rule is: (year % 4) === 3.
     *
     * NOTE: This rule governs Pagume, the 13th month. It does NOT govern
     * the date of Leddet (Ethiopian Christmas), which is fixed on Gregorian
     * 7 January and falls on Tahsas 28 in Ethiopian years divisible by 4.
     *
     * @param int $ethiopianYear
     *
     * @return bool
     */
    public function isLeapYear(int $ethiopianYear): bool
    {
        return ($ethiopianYear % 4) === 3;
    }

    /**
     * Determine whether Leddet (Ethiopian Christmas) falls on Tahsas 28
     * for the given Ethiopian year.
     *
     * Rule: Leddet is on Tahsas 28 when (year % 4) === 0.
     * Equivalently: Leddet is on Tahsas 28 in the year that follows an
     * Ethiopian leap year.
     *
     * @param int $ethiopianYear
     *
     * @return bool
     */
    public function isLeddetOnTahsas28(int $ethiopianYear): bool
    {
        return ($ethiopianYear % 4) === 0;
    }

    /**
     * Get the number of days in the given Ethiopian month.
     *
     * @param int $ethiopianYear
     * @param int $ethiopianMonth 1–13
     *
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public function daysInMonth(int $ethiopianYear, int $ethiopianMonth): int
    {
        if ($ethiopianMonth < 1 || $ethiopianMonth > 13) {
            throw new InvalidArgumentException(
                sprintf('Ethiopian month must be between 1 and 13, got %d.', $ethiopianMonth)
            );
        }

        if ($ethiopianMonth <= 12) {
            return 30;
        }

        return $this->isLeapYear($ethiopianYear) ? 6 : 5;
    }

    /**
     * Get the number of days in the given Ethiopian year.
     *
     * @param int $ethiopianYear
     *
     * @return int 365 for common years, 366 for leap years.
     */
    public function daysInYear(int $ethiopianYear): int
    {
        return $this->isLeapYear($ethiopianYear) ? 366 : 365;
    }

    /**
     * Validate an Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return bool
     */
    public function isValidDate(int $year, int $month, int $day): bool
    {
        if ($year < 1 || $year > 9999) {
            return false;
        }
        if ($month < 1 || $month > 13 || $day < 1) {
            return false;
        }

        return $day <= $this->daysInMonth($year, $month);
    }

    /**
     * Assert that an Ethiopian date is valid; throw otherwise.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @throws InvalidArgumentException
     * @return void
     */
    private function assertValidDate(int $year, int $month, int $day): void
    {
        if (!$this->isValidDate($year, $month, $day)) {
            throw new InvalidArgumentException(
                sprintf('Invalid Ethiopian date: %04d-%02d-%02d.', $year, $month, $day)
            );
        }
    }

    // =========================================================================
    // Section 2 — JDN Conversions
    // =========================================================================

    /**
     * Convert an Ethiopian date to a Julian Day Number.
     *
     * Formula: JDN = EPOCH + 365 * (year - 1) + intdiv(year, 4)
     *               + 30 * (month - 1) + (day - 1)
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return int
     *
     * @throws InvalidArgumentException
     */
    public function toJdn(int $year, int $month, int $day): int
    {
        $this->assertValidDate($year, $month, $day);

        return self::ETHIOPIAN_EPOCH_JDN
            + 365 * ($year - 1)
            + intdiv($year, 4)
            + 30 * ($month - 1)
            + $day
            - 1;
    }

    /**
     * Convert a Julian Day Number to an Ethiopian date.
     *
     * The algorithm:
     *   1. Compute D = JDN - EPOCH.
     *   2. Find the largest year Y such that
     *        365 * (Y - 1) + intdiv(Y, 4) <= D.
     *   3. Compute days_into_year = D - 365 * (Y - 1) - intdiv(Y, 4).
     *   4. month = intdiv(days_into_year, 30) + 1.
     *   5. day = (days_into_year % 30) + 1.
     *
     * The approximation `intdiv(4 * D + 1460, 1461)` overshoots by at most
     * one, so at most two adjustment iterations are required.
     *
     * @param int $jdn
     *
     * @return array{year: int, month: int, day: int}
     */
    public function fromJdn(int $jdn): array
    {
        $daysSinceEpoch = $jdn - self::ETHIOPIAN_EPOCH_JDN;

        // Approximate the year (may overshoot by at most one).
        $year = intdiv(4 * $daysSinceEpoch + 1460, 1461);

        // Correct downward if the approximation overshot.
        while ($year > 1 && 365 * ($year - 1) + intdiv($year, 4) > $daysSinceEpoch) {
            $year--;
        }

        // Correct upward if the approximation undershot.
        while (365 * $year + intdiv($year + 1, 4) <= $daysSinceEpoch) {
            $year++;
        }

        // Guard against underflow for dates before the Ethiopian epoch.
        if ($year < 1) {
            $year = 1;
        }

        $daysIntoYear = $daysSinceEpoch - 365 * ($year - 1) - intdiv($year, 4);
        $month = intdiv($daysIntoYear, 30) + 1;
        $day = ($daysIntoYear % 30) + 1;

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    /**
     * Convert a Gregorian (proleptic) date to a Julian Day Number.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return int
     */
    public function gregorianToJdn(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    /**
     * Convert a Julian Day Number to a Gregorian (proleptic) date.
     *
     * @param int $jdn
     *
     * @return array{year: int, month: int, day: int}
     */
    public function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    // =========================================================================
    // Section 3 — Cross-Calendar Conversion
    // =========================================================================

    /**
     * Convert a Gregorian DateTime to an Ethiopian date.
     *
     * @param DateTimeInterface $date
     *
     * @return array{year: int, month: int, day: int}
     */
    public function toEthiopian(DateTimeInterface $date): array
    {
        $localized = DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);

        $jdn = $this->gregorianToJdn(
            (int) $localized->format('Y'),
            (int) $localized->format('n'),
            (int) $localized->format('j')
        );

        return $this->fromJdn($jdn);
    }

    /**
     * Convert an Ethiopian date to a Gregorian DateTimeImmutable.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int $second
     *
     * @return DateTimeImmutable
     *
     * @throws InvalidArgumentException
     */
    public function toGregorian(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0
    ): DateTimeImmutable {
        $jdn = $this->toJdn($year, $month, $day);
        $g = $this->jdnToGregorian($jdn);

        $dateString = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $g['year'],
            $g['month'],
            $g['day'],
            $hour,
            $minute,
            $second
        );

        return new DateTimeImmutable($dateString, $this->timezone);
    }

    /**
     * Get today's Ethiopian date in the display timezone.
     *
     * @return array{year: int, month: int, day: int}
     */
    public function today(): array
    {
        return $this->toEthiopian(new DateTimeImmutable('now', $this->timezone));
    }

    // =========================================================================
    // Section 4 — Name Lookups
    // =========================================================================

    /**
     * Get the localized name of the Ethiopian month.
     *
     * @param int    $month  1–13
     * @param string $locale 'am' or 'en'
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function monthName(int $month, string $locale = 'am'): string
    {
        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException(
                sprintf('Ethiopian month must be between 1 and 13, got %d.', $month)
            );
        }

        return $locale === 'en'
            ? self::MONTH_NAMES_EN[$month]
            : self::MONTH_NAMES_AM[$month];
    }

    /**
     * Get the localized name of a day of the week.
     *
     * @param int    $dayOfWeek 0 (Sunday) – 6 (Saturday)
     * @param string $locale    'am' or 'en'
     *
     * @return string
     *
     * @throws InvalidArgumentException
     */
    public function dayName(int $dayOfWeek, string $locale = 'am'): string
    {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException(
                sprintf('Day of week must be between 0 and 6, got %d.', $dayOfWeek)
            );
        }

        return $locale === 'en'
            ? self::DAY_NAMES_EN[$dayOfWeek]
            : self::DAY_NAMES_AM[$dayOfWeek];
    }

    // =========================================================================
    // Section 5 — Formatting
    // =========================================================================

    /**
     * Format a Gregorian DateTime using Ethiopian calendar components.
     *
     * Supported tokens: Y, y, m, n, d, j, F, M, l, D, H, i, s.
     * Backslash (\) escapes the next character.
     *
     * @param DateTimeInterface $date
     * @param string            $format
     * @param string            $locale 'am' or 'en'
     *
     * @return string
     */
    public function format(DateTimeInterface $date, string $format, string $locale = 'am'): string
    {
        $localized = DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);
        $ethiopian = $this->toEthiopian($localized);

        $replacements = [
            'Y' => sprintf('%04d', $ethiopian['year']),
            'y' => sprintf('%02d', $ethiopian['year'] % 100),
            'm' => sprintf('%02d', $ethiopian['month']),
            'n' => (string) $ethiopian['month'],
            'd' => sprintf('%02d', $ethiopian['day']),
            'j' => (string) $ethiopian['day'],
            'F' => $this->monthName($ethiopian['month'], $locale),
            'M' => mb_substr($this->monthName($ethiopian['month'], $locale), 0, 3),
            'l' => $this->dayName((int) $localized->format('w'), $locale),
            'D' => mb_substr($this->dayName((int) $localized->format('w'), $locale), 0, 3),
            'H' => $localized->format('H'),
            'i' => $localized->format('i'),
            's' => $localized->format('s'),
        ];

        $result = '';
        $escape = false;
        $length = strlen($format);

        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];

            if ($escape) {
                $result .= $char;
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            $result .= $replacements[$char] ?? $char;
        }

        return $result;
    }

    /**
     * Format an Ethiopian date as an ISO-like string (YYYY-MM-DD).
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return string
     */
    public function formatIso(int $year, int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Format an Ethiopian date with full Amharic (or English) names.
     *
     * Example (am): "ሰኞ, መስከረም 1, 2017 ዓ.ም."
     *
     * @param int    $year
     * @param int    $month
     * @param int    $day
     * @param int    $weekday 0–6, Sunday = 0
     * @param string $locale  'am' or 'en'
     *
     * @return string
     */
    public function formatFull(int $year, int $month, int $day, int $weekday, string $locale = 'am'): string
    {
        $suffix = $locale === 'am' ? 'ዓ.ም.' : 'E.C.';

        return sprintf(
            '%s, %s %d, %d %s',
            $this->dayName($weekday, $locale),
            $this->monthName($month, $locale),
            $day,
            $year,
            $suffix
        );
    }

    /**
     * Format a date range in a human-friendly way.
     *
     * If both dates fall in the same month and year, the month/year is only
     * printed once.
     *
     * @param array{0: int, 1: int, 2: int} $start [year, month, day]
     * @param array{0: int, 1: int, 2: int} $end   [year, month, day]
     * @param string                        $locale 'am' or 'en'
     *
     * @return string
     */
    public function formatDateRange(array $start, array $end, string $locale = 'am'): string
    {
        $this->assertValidDate($start[0], $start[1], $start[2]);
        $this->assertValidDate($end[0], $end[1], $end[2]);

        if ($start[0] === $end[0] && $start[1] === $end[1]) {
            return sprintf(
                '%s %d–%d, %d',
                $this->monthName($start[1], $locale),
                $start[2],
                $end[2],
                $start[0]
            );
        }

        return sprintf(
            '%s – %s',
            $this->formatFull($start[0], $start[1], $start[2], $this->getWeekday($start[0], $start[1], $start[2]), $locale),
            $this->formatFull($end[0], $end[1], $end[2], $this->getWeekday($end[0], $end[1], $end[2]), $locale)
        );
    }

    // =========================================================================
    // Section 6 — Ethiopian Local Time (6-hour offset)
    // =========================================================================

    /**
     * Convert a Gregorian DateTime to Ethiopian local time.
     *
     * Ethiopian time starts at Gregorian 06:00 (12:00 Ethiopian day).
     * The offset is a constant 6-hour lag.
     *
     * @param DateTimeInterface $date
     *
     * @return array{
     *     hour: int,
     *     minute: int,
     *     period: string,
     *     gregorian_hour: int,
     *     formatted: string
     * }
     */
    public function toEthiopianTime(DateTimeInterface $date): array
    {
        $localized = DateTimeImmutable::createFromInterface($date)->setTimezone($this->timezone);

        $gregorianHour = (int) $localized->format('H');
        $minute = (int) $localized->format('i');

        $ethiopianHour24 = ($gregorianHour >= 6) ? $gregorianHour - 6 : $gregorianHour + 18;
        $ethiopianHour12 = $ethiopianHour24 % 12 ?: 12;
        $period = ($ethiopianHour24 < 12) ? 'ጠዋት' : 'ቀን';

        return [
            'hour'           => $ethiopianHour12,
            'minute'         => $minute,
            'period'         => $period,
            'gregorian_hour' => $gregorianHour,
            'formatted'      => sprintf('%d:%02d %s', $ethiopianHour12, $minute, $period),
        ];
    }

    /**
     * Get the Amharic time-of-day phrase for a Gregorian hour/minute.
     *
     * @param int $gregorianHour 0–23
     * @param int $minute        0–59
     *
     * @return string
     */
    public function timeOfDayPhrase(int $gregorianHour, int $minute = 0): string
    {
        if ($gregorianHour < 0 || $gregorianHour > 23 || $minute < 0 || $minute > 59) {
            return 'ሰዓት';
        }

        $decimal = $gregorianHour + ($minute / 60);

        foreach (self::TIME_PERIODS as [$start, $end, $phrase]) {
            if ($decimal >= $start && $decimal < $end) {
                return $phrase;
            }
        }

        return 'ሰዓት';
    }

    // =========================================================================
    // Section 7 — Date Arithmetic
    // =========================================================================

    /**
     * Add a number of days to an Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $days (can be negative)
     *
     * @return array{year: int, month: int, day: int}
     */
    public function addDays(int $year, int $month, int $day, int $days): array
    {
        $jdn = $this->toJdn($year, $month, $day);

        return $this->fromJdn($jdn + $days);
    }

    /**
     * Compute the difference in days between two Ethiopian dates.
     *
     * @param array{0: int, 1: int, 2: int} $start [year, month, day]
     * @param array{0: int, 1: int, 2: int} $end   [year, month, day]
     *
     * @return int Positive if $end > $start, negative otherwise.
     */
    public function diffInDays(array $start, array $end): int
    {
        $startJdn = $this->toJdn($start[0], $start[1], $start[2]);
        $endJdn = $this->toJdn($end[0], $end[1], $end[2]);

        return $endJdn - $startJdn;
    }

    /**
     * Get the weekday index (0 = Sunday, …, 6 = Saturday) for an Ethiopian date.
     *
     * JDN mod 7 = 0 corresponds to Monday, so add 1 to align Sunday to 0.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return int
     */
    public function getWeekday(int $year, int $month, int $day): int
    {
        $jdn = $this->toJdn($year, $month, $day);

        return ($jdn + 1) % 7;
    }

    /**
     * Check if an Ethiopian date falls on a weekend (Friday or Saturday).
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return bool
     */
    public function isWeekend(int $year, int $month, int $day): bool
    {
        return in_array($this->getWeekday($year, $month, $day), self::WEEKEND_DAYS, true);
    }

    // =========================================================================
    // Section 8 — Fiscal Year & Quarters
    // =========================================================================

    /**
     * Get the Ethiopian fiscal year for a given Ethiopian date.
     *
     * The fiscal year starts on Hamle 1 (month 11).
     *
     * @param int $year
     * @param int $month
     *
     * @return int
     */
    public function getFiscalYear(int $year, int $month): int
    {
        return ($month >= 11) ? $year + 1 : $year;
    }

    /**
     * Get the Gregorian date range for a given Ethiopian fiscal year.
     *
     * Fiscal year N starts on Hamle 1 of Ethiopian year (N-1) and ends
     * on Sene 30 of Ethiopian year N.
     *
     * @param int $ethiopianYear
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function getFiscalYearRange(int $ethiopianYear): array
    {
        return [
            'start' => $this->toGregorian($ethiopianYear - 1, 11, 1),
            'end'   => $this->toGregorian($ethiopianYear, 10, 30),
        ];
    }

    /**
     * Get the Gregorian date range for a specific fiscal quarter.
     *
     * Quarters are distributed across the 13 Ethiopian months as follows:
     *   Q1: Hamle (11) to Pagume (13) of the previous year  — 3 months
     *   Q2: Meskerem (1) to Hidar (3) of the current year   — 3 months
     *   Q3: Tahsas (4) to Yekatit (6) of the current year   — 3 months
     *   Q4: Megabit (7) to Sene (10) of the current year    — 4 months
     *
     * @param int $ethiopianYear
     * @param int $quarter 1–4
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     *
     * @throws InvalidArgumentException
     */
    public function getQuarterRange(int $ethiopianYear, int $quarter): array
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException(
                sprintf('Quarter must be between 1 and 4, got %d.', $quarter)
            );
        }

        [$startYear, $startMonth, $startDay, $endYear, $endMonth, $endDay] = match ($quarter) {
            1 => [
                $ethiopianYear - 1, 11, 1,
                $ethiopianYear - 1, 13, $this->daysInMonth($ethiopianYear - 1, 13),
            ],
            2 => [$ethiopianYear, 1, 1, $ethiopianYear, 3, 30],
            3 => [$ethiopianYear, 4, 1, $ethiopianYear, 6, 30],
            4 => [$ethiopianYear, 7, 1, $ethiopianYear, 10, 30],
        };

        return [
            'start' => $this->toGregorian($startYear, $startMonth, $startDay),
            'end'   => $this->toGregorian($endYear, $endMonth, $endDay),
        ];
    }

    /**
     * Get the Gregorian date range for an entire Ethiopian year.
     *
     * @param int $ethiopianYear
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function getGregorianRangeForYear(int $ethiopianYear): array
    {
        $lastPagumeDay = $this->daysInMonth($ethiopianYear, 13);

        return [
            'start' => $this->toGregorian($ethiopianYear, 1, 1),
            'end'   => $this->toGregorian($ethiopianYear, 13, $lastPagumeDay),
        ];
    }

    // =========================================================================
    // Section 9 — Business Days
    // =========================================================================

    /**
     * Count business days between two Ethiopian dates (inclusive of both ends).
     *
     * Weekends (Friday, Saturday) and holidays are excluded.
     *
     * @param array{0: int, 1: int, 2: int} $start [year, month, day]
     * @param array{0: int, 1: int, 2: int} $end   [year, month, day]
     * @param array<int, array{0: int, 1: int, 2: int}> $holidays Optional
     *        additional holiday tuples to exclude.
     *
     * @return int
     */
    public function getBusinessDaysBetween(array $start, array $end, array $holidays = []): int
    {
        $this->assertValidDate($start[0], $start[1], $start[2]);
        $this->assertValidDate($end[0], $end[1], $end[2]);

        $total = $this->diffInDays($start, $end);
        if ($total < 0) {
            return 0;
        }

        // Build a holiday lookup keyed by "YYYY-MM-DD".
        $holidaySet = [];
        foreach ($holidays as $h) {
            $holidaySet[$this->formatIso($h[0], $h[1], $h[2])] = true;
        }

        $businessDays = 0;
        for ($i = 0; $i <= $total; $i++) {
            $current = $this->addDays($start[0], $start[1], $start[2], $i);
            if ($this->isWeekend($current['year'], $current['month'], $current['day'])) {
                continue;
            }
            $key = $this->formatIso($current['year'], $current['month'], $current['day']);
            if (isset($holidaySet[$key])) {
                continue;
            }
            $businessDays++;
        }

        return $businessDays;
    }

    /**
     * Add business days to an Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $businessDays
     * @param array<int, array{0: int, 1: int, 2: int}> $holidays
     *
     * @return array{year: int, month: int, day: int}
     */
    public function addBusinessDays(
        int $year,
        int $month,
        int $day,
        int $businessDays,
        array $holidays = []
    ): array {
        $this->assertValidDate($year, $month, $day);

        $holidaySet = [];
        foreach ($holidays as $h) {
            $holidaySet[$this->formatIso($h[0], $h[1], $h[2])] = true;
        }

        $added = 0;
        while ($added < $businessDays) {
            $date = $this->addDays($year, $month, $day, 1);
            $year = $date['year'];
            $month = $date['month'];
            $day = $date['day'];

            if ($this->isWeekend($year, $month, $day)) {
                continue;
            }
            $key = $this->formatIso($year, $month, $day);
            if (isset($holidaySet[$key])) {
                continue;
            }
            $added++;
        }

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    // =========================================================================
    // Section 10 — Holidays
    // =========================================================================

    /**
     * Get the full list of Ethiopian holidays for a given Ethiopian year.
     *
     * Each holiday is:
     *   [
     *     'key'         => string,   // internal identifier
     *     'am'          => string,   // Amharic name
     *     'en'          => string,   // English name
     *     'type'        => string,   // HOLIDAY_TYPE_* constant
     *     'ethiopian'   => array{0: int, 1: int, 2: int},
     *     'gregorian'   => DateTimeImmutable,
     *     'is_movable'  => bool,     // true for Easter-derived holidays
     *     'approximate' => bool,     // true for Islamic holidays
     *   ]
     *
     * @param int $ethiopianYear
     *
     * @return array<string, array<string, mixed>>
     */
    public function getHolidays(int $ethiopianYear): array
    {
        return [
            'enkutatash'     => $this->enkutatash($ethiopianYear),
            'meskel'         => $this->meskel($ethiopianYear),
            'nations_day'    => $this->nationsDay($ethiopianYear),
            'gena'           => $this->gena($ethiopianYear),
            'leddet'         => $this->leddet($ethiopianYear),
            'maale_new_year' => $this->maaleNewYear($ethiopianYear),
            'timket'         => $this->timket($ethiopianYear),
            'martyrs_day'    => $this->martyrsDay($ethiopianYear),
            'adwa_day'       => $this->adwaDay($ethiopianYear),
            'good_friday'    => $this->goodFriday($ethiopianYear),
            'fasika'         => $this->fasika($ethiopianYear),
            'palm_sunday'    => $this->palmSunday($ethiopianYear),
            'labour_day'     => $this->labourDay($ethiopianYear),
            'patriots_day'   => $this->patriotsDay($ethiopianYear),
            'eid_fitr'       => $this->eidFitr($ethiopianYear),
            'eid_adha'       => $this->eidAdha($ethiopianYear),
            'mawlid'         => $this->mawlid($ethiopianYear),
        ];
    }

    /**
     * Enkutatash (Ethiopian New Year) — Meskerem 1.
     */
    public function enkutatash(int $year): array
    {
        return $this->buildHoliday('enkutatash', $year, 1, 1, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Meskel — Meskerem 17.
     */
    public function meskel(int $year): array
    {
        return $this->buildHoliday('meskel', $year, 1, 17, self::HOLIDAY_TYPE_RELIGIOUS);
    }

    /**
     * Nations, Nationalities and Peoples' Day — Hidar 29.
     */
    public function nationsDay(int $year): array
    {
        return $this->buildHoliday('nations_day', $year, 3, 29, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Gena (Christmas Eve) — Tahsas 28 (or 27 when Leddet is on 28).
     */
    public function gena(int $year): array
    {
        $day = $this->isLeddetOnTahsas28($year) ? 27 : 28;

        return $this->buildHoliday('gena', $year, 4, $day, self::HOLIDAY_TYPE_RELIGIOUS);
    }

    /**
     * Leddet (Ethiopian Christmas) — Tahsas 28 or 29.
     *
     * Leddet is fixed on Gregorian 7 January. Depending on the Ethiopian
     * year, this corresponds to Tahsas 28 (when year % 4 === 0) or
     * Tahsas 29 otherwise.
     */
    public function leddet(int $year): array
    {
        $day = $this->isLeddetOnTahsas28($year) ? 28 : 29;

        return $this->buildHoliday('leddet', $year, 4, $day, self::HOLIDAY_TYPE_RELIGIOUS);
    }

    /**
     * Maale New Year (Doooma) — Tir 1.
     */
    public function maaleNewYear(int $year): array
    {
        $holiday = $this->buildHoliday('maale_new_year', $year, 5, 1, self::HOLIDAY_TYPE_CULTURAL);
        $holiday['anniversary'] = max(1, $year - self::MAALE_NEW_YEAR_BASE_YEAR + 1);

        return $holiday;
    }

    /**
     * Timket (Epiphany) — Tir 11.
     */
    public function timket(int $year): array
    {
        return $this->buildHoliday('timket', $year, 5, 11, self::HOLIDAY_TYPE_RELIGIOUS);
    }

    /**
     * Martyrs' Day — Yekatit 12.
     */
    public function martyrsDay(int $year): array
    {
        return $this->buildHoliday('martyrs_day', $year, 6, 12, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Adwa Victory Day — Yekatit 23.
     */
    public function adwaDay(int $year): array
    {
        return $this->buildHoliday('adwa_day', $year, 6, 23, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Good Friday — two days before Fasika.
     */
    public function goodFriday(int $year): array
    {
        $fasika = $this->fasika($year);
        $date = $fasika['ethiopian'];
        $jdn = $this->toJdn($date[0], $date[1], $date[2]) - 2;
        $ethDate = $this->fromJdn($jdn);

        return $this->buildHoliday(
            'good_friday',
            $ethDate['year'],
            $ethDate['month'],
            $ethDate['day'],
            self::HOLIDAY_TYPE_RELIGIOUS,
            true
        );
    }

    /**
     * Fasika (Ethiopian Easter) — movable, computed via the Meeus/Jones/Butcher
     * algorithm on the Julian calendar, then converted to the Ethiopian calendar.
     *
     * The Gregorian year used is `$year + 8`, because the Ethiopian year N
     * spans roughly September of Gregorian year (N + 7) to September of
     * (N + 8).
     */
    public function fasika(int $year): array
    {
        $gregorianYear = $year + 8;

        // Meeus/Jones/Butcher algorithm for the Julian Easter.
        $a = $gregorianYear % 4;
        $b = $gregorianYear % 7;
        $c = $gregorianYear % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        // Convert the Julian date to Gregorian, then to Ethiopian.
        $julianJdn = $this->julianToJdn($gregorianYear, $month, $day);
        $gregorian = $this->jdnToGregorian($julianJdn);
        $ethiopian = $this->fromJdn($this->gregorianToJdn(
            $gregorian['year'],
            $gregorian['month'],
            $gregorian['day']
        ));

        return $this->buildHoliday(
            'fasika',
            $ethiopian['year'],
            $ethiopian['month'],
            $ethiopian['day'],
            self::HOLIDAY_TYPE_RELIGIOUS,
            true
        );
    }

    /**
     * Palm Sunday — seven days before Fasika.
     */
    public function palmSunday(int $year): array
    {
        $fasika = $this->fasika($year);
        $date = $fasika['ethiopian'];
        $jdn = $this->toJdn($date[0], $date[1], $date[2]) - 7;
        $ethDate = $this->fromJdn($jdn);

        return $this->buildHoliday(
            'palm_sunday',
            $ethDate['year'],
            $ethDate['month'],
            $ethDate['day'],
            self::HOLIDAY_TYPE_RELIGIOUS,
            true
        );
    }

    /**
     * Labour Day — Miazia 23.
     */
    public function labourDay(int $year): array
    {
        return $this->buildHoliday('labour_day', $year, 8, 23, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Patriots' Victory Day — Miazia 27.
     */
    public function patriotsDay(int $year): array
    {
        return $this->buildHoliday('patriots_day', $year, 8, 27, self::HOLIDAY_TYPE_NATIONAL);
    }

    /**
     * Eid al-Fitr — approximate (Hamle 10).
     */
    public function eidFitr(int $year): array
    {
        return $this->buildHoliday('eid_fitr', $year, 11, 10, self::HOLIDAY_TYPE_ISLAMIC, false, true);
    }

    /**
     * Eid al-Adha — approximate (Hamle 20).
     */
    public function eidAdha(int $year): array
    {
        return $this->buildHoliday('eid_adha', $year, 11, 20, self::HOLIDAY_TYPE_ISLAMIC, false, true);
    }

    /**
     * Mawlid — approximate (Nehase 12).
     */
    public function mawlid(int $year): array
    {
        return $this->buildHoliday('mawlid', $year, 12, 12, self::HOLIDAY_TYPE_ISLAMIC, false, true);
    }

    /**
     * Filter a holiday list by type.
     *
     * @param int    $year
     * @param string $type One of HOLIDAY_TYPE_* constants, or 'all'.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getHolidaysByType(int $year, string $type = 'all'): array
    {
        $holidays = $this->getHolidays($year);
        if ($type === 'all') {
            return $holidays;
        }

        return array_filter(
            $holidays,
            fn(array $h) => ($h['type'] ?? null) === $type
        );
    }

    /**
     * Get the next N upcoming holidays starting from a given Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $limit
     *
     * @return array<int, array{holiday: array<string, mixed>, days_until: int}>
     */
    public function getUpcomingHolidays(int $year, int $month, int $day, int $limit = 5): array
    {
        $this->assertValidDate($year, $month, $day);
        $startJdn = $this->toJdn($year, $month, $day);

        $upcoming = [];

        // Scan current year and next year to cover year-boundary cases.
        foreach ([$year, $year + 1] as $y) {
            foreach ($this->getHolidays($y) as $holiday) {
                $eth = $holiday['ethiopian'];
                $holidayJdn = $this->toJdn($eth[0], $eth[1], $eth[2]);

                if ($holidayJdn >= $startJdn) {
                    $upcoming[] = [
                        'holiday'    => $holiday,
                        'days_until' => $holidayJdn - $startJdn,
                    ];
                }
            }
        }

        usort($upcoming, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

        return array_slice($upcoming, 0, $limit);
    }

    /**
     * Get the single next upcoming holiday from a given Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     *
     * @return array{holiday: array<string, mixed>, days_until: int}|null
     */
    public function getNextHoliday(int $year, int $month, int $day): ?array
    {
        $upcoming = $this->getUpcomingHolidays($year, $month, $day, 1);

        return $upcoming[0] ?? null;
    }

    // =========================================================================
    // Section 11 — Countdowns
    // =========================================================================

    /**
     * Compute a countdown from now to a target Ethiopian date.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param int $second
     *
     * @return array{
     *     days: int, hours: int, minutes: int, seconds: int,
     *     invert: bool, target_ethiopian: string, target_gregorian: string,
     *     message: string
     * }
     */
    public function countdownTo(
        int $year,
        int $month,
        int $day,
        int $hour = 0,
        int $minute = 0,
        int $second = 0
    ): array {
        $target = $this->toGregorian($year, $month, $day, $hour, $minute, $second);
        $now = new DateTimeImmutable('now', $this->timezone);

        $diff = $now->diff($target);

        return [
            'days'             => $diff->days,
            'hours'            => $diff->h,
            'minutes'          => $diff->i,
            'seconds'          => $diff->s,
            'invert'           => $diff->invert === 1,
            'target_ethiopian' => $this->formatIso($year, $month, $day),
            'target_gregorian' => $target->format('Y-m-d H:i:s'),
            'message'          => sprintf(
                '%d ቀናት %d ሰዓት %d ደቂቃ %d ሰከንድ',
                $diff->days,
                $diff->h,
                $diff->i,
                $diff->s
            ),
        ];
    }

    /**
     * Countdown to Leddet (Ethiopian Christmas) for the given Ethiopian year.
     *
     * @param int $year
     *
     * @return array<string, mixed>
     */
    public function countdownToLeddet(int $year): array
    {
        $holiday = $this->leddet($year);
        $eth = $holiday['ethiopian'];

        return $this->countdownTo($eth[0], $eth[1], $eth[2]);
    }

    /**
     * Countdown to Maale New Year (Doooma) for the given Ethiopian year.
     *
     * @param int $year
     *
     * @return array<string, mixed>
     */
    public function countdownToMaaleNewYear(int $year): array
    {
        $holiday = $this->maaleNewYear($year);
        $eth = $holiday['ethiopian'];

        return array_merge(
            $this->countdownTo($eth[0], $eth[1], $eth[2]),
            ['anniversary' => $holiday['anniversary']]
        );
    }

    // =========================================================================
    // Section 12 — Maale New Year Anniversary
    // =========================================================================

    /**
     * Get Maale New Year anniversary metadata for a given Ethiopian year.
     *
     * @param int $year
     *
     * @return array{
     *     current_anniversary: int,
     *     base_year: int,
     *     next_milestone: array{year: int, name: string, years_remaining: int}|null,
     *     name_am: string,
     *     name_en: string
     * }
     */
    public function getMaaleAnniversaryInfo(int $year): array
    {
        $anniversary = max(1, $year - self::MAALE_NEW_YEAR_BASE_YEAR + 1);

        $milestones = [
            1 => 'First Anniversary',
            5 => '5th Anniversary',
            10 => '10th Anniversary',
            15 => '15th Anniversary',
            20 => '20th Anniversary',
            25 => '25th Anniversary (Silver)',
            30 => '30th Anniversary',
            40 => '40th Anniversary',
            50 => '50th Anniversary (Golden)',
            75 => '75th Anniversary (Diamond Jubilee)',
            100 => '100th Anniversary (Centennial)',
        ];

        $nextMilestone = null;
        foreach ($milestones as $milestoneYear => $name) {
            if ($anniversary < $milestoneYear) {
                $nextMilestone = [
                    'year'            => $milestoneYear,
                    'name'            => $name,
                    'years_remaining' => $milestoneYear - $anniversary,
                ];
                break;
            }
        }

        return [
            'current_anniversary' => $anniversary,
            'base_year'           => self::MAALE_NEW_YEAR_BASE_YEAR,
            'next_milestone'      => $nextMilestone,
            'name_am'             => 'ዶኦማ',
            'name_en'             => 'Maale New Year',
        ];
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Build a holiday array entry from raw components.
     */
    private function buildHoliday(
        string $key,
        int $year,
        int $month,
        int $day,
        string $type,
        bool $movable = false,
        bool $approximate = false
    ): array {
        $this->assertValidDate($year, $month, $day);

        return [
            'key'         => $key,
            'am'          => self::HOLIDAY_NAMES[$key]['am'] ?? $key,
            'en'          => self::HOLIDAY_NAMES[$key]['en'] ?? $key,
            'type'        => $type,
            'ethiopian'   => [$year, $month, $day],
            'gregorian'   => $this->toGregorian($year, $month, $day),
            'is_movable'  => $movable,
            'approximate' => $approximate,
        ];
    }

    /**
     * Convert a Julian calendar date to a Julian Day Number.
     *
     * Used only for Easter computations, where the Meeus/Jones/Butcher
     * algorithm works in the Julian calendar.
     */
    private function julianToJdn(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - 32083;
    }
}