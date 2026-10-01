<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use Tests\TestCase;

/**
 * EthiopianCalendarTest
 *
 * Comprehensive unit test suite for the EthiopianCalendar service.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery.
 *
 * 1.1.0  — CR-3 corrections. Two assertions fixed. No production code touched.
 *
 *          (a) format_handles_escaped_characters — assertion corrected.
 *
 *              The test's inline comment states the intent:
 *
 *                  // "Y" is a token, but "\Y" should output literal "Y".
 *
 *              The production contract (EthiopianCalendar::format() docblock:
 *              "Backslash (\) escapes the next character") matches this. The
 *              method, on encountering a backslash, sets an escape flag and
 *              then emits the next character verbatim, dropping the backslash
 *              itself. That is also the standard PHP date() semantic.
 *
 *              The prior assertion expected `'2016 \Y'` — a seven-character
 *              PHP single-quoted string containing a literal backslash
 *              between the space and the 'Y'. That expectation contradicts
 *              both the comment above it and the production contract.
 *
 *              The corrected assertion expects `'2016 Y'` — six characters,
 *              no backslash — which is the value the contract requires and
 *              which the production code already produces.
 *
 *          (b) get_upcoming_holidays_returns_sorted_list — assertion order
 *              corrected.
 *
 *              Test name says "sorted list". Lines 898–900 assert that
 *              $upcoming[0] is `enkutatash` with `days_until = 0` — the
 *              smallest possible value, which establishes that the list is
 *              ascending.
 *
 *              PHPUnit's assertLessThanOrEqual($expected, $actual) asserts
 *              `$actual <= $expected`. The prior line therefore asserted
 *              `$upcoming[2]['days_until'] <= $upcoming[1]['days_until']`,
 *              i.e. `88 <= 16`, which is false. The arguments were reversed.
 *
 *              The corrected line asserts `$upcoming[1]['days_until']
 *              <= $upcoming[2]['days_until']`, i.e. `16 <= 88`, which
 *              matches the test's name and the two prior assertions.
 *
 *              Production code (EthiopianCalendar::getUpcomingHolidays())
 *              ends with usort() in ascending order and is correct. Only the
 *              assertion was wrong.
 *
 * Verified against known reference data:
 *   - Wikipedia Ethiopian calendar article (epoch and modern conversion).
 *   - Ethiopian Orthodox Church calendar for Fasika (Easter) 2023–2025.
 *   - Ethiopian Christmas (Leddet) always on Gregorian 7 January.
 *
 * Coverage:
 *   1. Leap-year rule (Pagume) and Leddet leap-year rule.
 *   2. Days in month/year, validation.
 *   3. JDN round-trips and known conversion vectors.
 *   4. Localized names, formatting (tokens, ISO, full, ranges).
 *   5. Ethiopian local time and time-of-day phrases.
 *   6. Date arithmetic (addDays, diffInDays, weekday, weekend).
 *   7. Fiscal year and quarters.
 *   8. Business days (with holidays).
 *   9. Every holiday: date, type, and known real-world verification.
 *  10. Countdowns and Maale New Year anniversary.
 *
 * @package Tests\Unit
 * @version 1.1.0
 */
final class EthiopianCalendarTest extends TestCase
{
    private EthiopianCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new EthiopianCalendar(
            new DateTimeZone('Africa/Addis_Ababa')
        );
    }

    // =========================================================================
    // 1. Leap Year and Leddet Rules
    // =========================================================================
    #[Test]
    public function ethiopian_leap_years_follow_the_year_mod_4_eq_3_rule(): void
    {
        // Ethiopian leap years: 2015, 2019, 2023, 2027
        $this->assertTrue($this->calendar->isLeapYear(2015));
        $this->assertTrue($this->calendar->isLeapYear(2019));
        $this->assertTrue($this->calendar->isLeapYear(2023));
        $this->assertTrue($this->calendar->isLeapYear(2027));

        // Common years
        $this->assertFalse($this->calendar->isLeapYear(2016));
        $this->assertFalse($this->calendar->isLeapYear(2017));
        $this->assertFalse($this->calendar->isLeapYear(2018));
        $this->assertFalse($this->calendar->isLeapYear(2020));
    }

    #[Test]
    public function leddet_falls_on_tahsas_28_when_year_is_divisible_by_4(): void
    {
        // Year 2016 → Leddet on Tahsas 28
        $this->assertTrue($this->calendar->isLeddetOnTahsas28(2016));
        $this->assertTrue($this->calendar->isLeddetOnTahsas28(2020));
        $this->assertTrue($this->calendar->isLeddetOnTahsas28(2024));

        // Year 2015 → Leddet on Tahsas 29
        $this->assertFalse($this->calendar->isLeddetOnTahsas28(2015));
        $this->assertFalse($this->calendar->isLeddetOnTahsas28(2017));
        $this->assertFalse($this->calendar->isLeddetOnTahsas28(2019));
    }

    // =========================================================================
    // 2. Days in Month/Year and Validation
    // =========================================================================
    #[Test]
    public function days_in_month_returns_30_for_months_1_to_12(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->assertSame(30, $this->calendar->daysInMonth(2016, $month));
            $this->assertSame(30, $this->calendar->daysInMonth(2015, $month));
        }
    }

    #[Test]
    public function days_in_pagume_is_5_in_common_years_and_6_in_leap_years(): void
    {
        // 2015 is leap → 6 days
        $this->assertSame(6, $this->calendar->daysInMonth(2015, 13));

        // 2016 is common → 5 days
        $this->assertSame(5, $this->calendar->daysInMonth(2016, 13));

        // 2019 is leap → 6 days
        $this->assertSame(6, $this->calendar->daysInMonth(2019, 13));
    }

    #[Test]
    public function days_in_month_throws_for_invalid_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar->daysInMonth(2016, 14);
    }

    #[Test]
    public function days_in_year_is_365_or_366(): void
    {
        $this->assertSame(365, $this->calendar->daysInYear(2016));
        $this->assertSame(366, $this->calendar->daysInYear(2015));
        $this->assertSame(366, $this->calendar->daysInYear(2019));
        $this->assertSame(365, $this->calendar->daysInYear(2017));
    }

    #[Test]
    public function date_validation_accepts_valid_and_rejects_invalid_dates(): void
    {
        // Valid
        $this->assertTrue($this->calendar->isValidDate(2016, 1, 1));
        $this->assertTrue($this->calendar->isValidDate(2016, 13, 5));
        $this->assertTrue($this->calendar->isValidDate(2015, 13, 6)); // Leap year Pagume

        // Invalid
        $this->assertFalse($this->calendar->isValidDate(2016, 13, 6)); // 2016 is not leap
        $this->assertFalse($this->calendar->isValidDate(2016, 1, 31)); // Months have 30 days
        $this->assertFalse($this->calendar->isValidDate(2016, 14, 1)); // Invalid month
        $this->assertFalse($this->calendar->isValidDate(2016, 0, 1));  // Invalid month
        $this->assertFalse($this->calendar->isValidDate(2016, 1, 0));  // Invalid day
        $this->assertFalse($this->calendar->isValidDate(0, 1, 1));     // Invalid year
    }

    // =========================================================================
    // 3. JDN Conversion and Round-Trips
    // =========================================================================
    #[Test]
    public function ethiopian_epoch_maps_to_expected_jdn(): void
    {
        // Ethiopian 1/1/1 = JDN 1724221
        $this->assertSame(1724221, $this->calendar->toJdn(1, 1, 1));
    }

    #[Test]
    public function from_jdn_reconstructs_the_ethiopian_epoch(): void
    {
        $date = $this->calendar->fromJdn(1724221);

        $this->assertSame(1, $date['year']);
        $this->assertSame(1, $date['month']);
        $this->assertSame(1, $date['day']);
    }

    #[Test]
    public function jdn_round_trip_preserves_ethiopian_date(): void
    {
        $testDates = [
            [1, 1, 1],
            [1, 1, 2],
            [1, 1, 30],
            [1, 2, 1],
            [1, 13, 5],
            [4, 1, 1],
            [100, 1, 1],
            [1000, 6, 15],
            [2015, 13, 6],   // Leap year Pagume
            [2016, 1, 1],
            [2016, 4, 28],   // Leddet 2024
            [2016, 13, 5],   // Pagume common year
            [2017, 1, 1],
            [2019, 13, 6],   // Leap year Pagume
            [2025, 12, 30],
        ];

        foreach ($testDates as [$y, $m, $d]) {
            $jdn = $this->calendar->toJdn($y, $m, $d);
            $reconstructed = $this->calendar->fromJdn($jdn);

            $this->assertSame(
                [$y, $m, $d],
                [$reconstructed['year'], $reconstructed['month'], $reconstructed['day']],
                sprintf('Round-trip failed for %d-%d-%d (JDN %d)', $y, $m, $d, $jdn)
            );
        }
    }

    #[Test]
    public function gregorian_to_jdn_matches_known_vectors(): void
    {
        // 1 Jan 2000 = JDN 2451545 (well-known reference)
        $this->assertSame(2451545, $this->calendar->gregorianToJdn(2000, 1, 1));

        // 24 May 2024 = JDN 2460455
        $this->assertSame(2460455, $this->calendar->gregorianToJdn(2024, 5, 24));
    }

    #[Test]
    public function jdn_to_gregorian_matches_known_vectors(): void
    {
        $date = $this->calendar->jdnToGregorian(2451545);
        $this->assertSame(['year' => 2000, 'month' => 1, 'day' => 1], $date);
    }

    // =========================================================================
    // 4. Gregorian ↔ Ethiopian Known Vectors
    // =========================================================================
    #[Test]
    public function ethiopian_2016_new_year_maps_to_12_september_2023(): void
    {
        $date = $this->calendar->toGregorian(2016, 1, 1);

        $this->assertSame('2023-09-12', $date->format('Y-m-d'));
    }

    #[Test]
    public function ethiopian_2017_new_year_maps_to_11_september_2024(): void
    {
        $date = $this->calendar->toGregorian(2017, 1, 1);

        $this->assertSame('2024-09-11', $date->format('Y-m-d'));
    }

    #[Test]
    public function ethiopian_2018_new_year_maps_to_11_september_2025(): void
    {
        $date = $this->calendar->toGregorian(2018, 1, 1);

        $this->assertSame('2025-09-11', $date->format('Y-m-d'));
    }

    #[Test]
    public function gregorian_12_september_2023_maps_to_ethiopian_2016_new_year(): void
    {
        $date = new DateTimeImmutable('2023-09-12 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $eth = $this->calendar->toEthiopian($date);

        $this->assertSame(2016, $eth['year']);
        $this->assertSame(1, $eth['month']);
        $this->assertSame(1, $eth['day']);
    }

    #[Test]
    public function leddet_2024_maps_to_7_january_2024(): void
    {
        // Ethiopian 2016/4/28 (Leddet) = 7 January 2024
        $date = $this->calendar->toGregorian(2016, 4, 28);

        $this->assertSame('2024-01-07', $date->format('Y-m-d'));
    }

    #[Test]
    public function leddet_2023_maps_to_7_january_2023(): void
    {
        // Ethiopian 2015/4/29 (Leddet) = 7 January 2023
        $date = $this->calendar->toGregorian(2015, 4, 29);

        $this->assertSame('2023-01-07', $date->format('Y-m-d'));
    }

    #[Test]
    public function leddet_2025_maps_to_7_january_2025(): void
    {
        // Ethiopian 2017/4/29 (Leddet) = 7 January 2025
        $date = $this->calendar->toGregorian(2017, 4, 29);

        $this->assertSame('2025-01-07', $date->format('Y-m-d'));
    }

    #[Test]
    public function gregorian_7_january_2024_maps_to_ethiopian_tahsas_28_2016(): void
    {
        $date = new DateTimeImmutable('2024-01-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $eth = $this->calendar->toEthiopian($date);

        $this->assertSame(2016, $eth['year']);
        $this->assertSame(4, $eth['month']);
        $this->assertSame(28, $eth['day']);
    }

    #[Test]
    public function gregorian_7_january_2023_maps_to_ethiopian_tahsas_29_2015(): void
    {
        $date = new DateTimeImmutable('2023-01-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $eth = $this->calendar->toEthiopian($date);

        $this->assertSame(2015, $eth['year']);
        $this->assertSame(4, $eth['month']);
        $this->assertSame(29, $eth['day']);
    }

    // =========================================================================
    // 5. Localized Names
    // =========================================================================
    #[Test]
    public function month_names_are_localized(): void
    {
        $this->assertSame('መስከረም', $this->calendar->monthName(1));
        $this->assertSame('ጥር', $this->calendar->monthName(5));
        $this->assertSame('ጳጉሜን', $this->calendar->monthName(13));
        $this->assertSame('Meskerem', $this->calendar->monthName(1, 'en'));
        $this->assertSame('Tir', $this->calendar->monthName(5, 'en'));
        $this->assertSame('Pagume', $this->calendar->monthName(13, 'en'));
    }

    #[Test]
    public function month_name_throws_for_invalid_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar->monthName(0);
    }

    #[Test]
    public function day_names_are_localized(): void
    {
        $this->assertSame('እሑድ', $this->calendar->dayName(0));
        $this->assertSame('ሰኞ', $this->calendar->dayName(1));
        $this->assertSame('ቅዳሜ', $this->calendar->dayName(6));
        $this->assertSame('Sunday', $this->calendar->dayName(0, 'en'));
        $this->assertSame('Monday', $this->calendar->dayName(1, 'en'));
    }

    #[Test]
    public function day_name_throws_for_invalid_weekday(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar->dayName(7);
    }

    // =========================================================================
    // 6. Formatting
    // =========================================================================
    #[Test]
    public function format_with_tokens_outputs_ethiopian_components(): void
    {
        $date = new DateTimeImmutable('2023-09-12 14:30:45', new DateTimeZone('Africa/Addis_Ababa'));

        $this->assertSame('2016-01-01', $this->calendar->format($date, 'Y-m-d'));
        $this->assertSame('2016/1/1', $this->calendar->format($date, 'Y/n/j'));
        $this->assertSame('14:30:45', $this->calendar->format($date, 'H:i:s'));
    }

    #[Test]
    public function format_with_month_names_works(): void
    {
        $date = new DateTimeImmutable('2023-09-12 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        // 12 Sep 2023 was a Tuesday (ማክሰኞ). Ethiopian date is Meskerem 1, 2016.
        $this->assertSame('መስከረም', $this->calendar->format($date, 'F'));
        $this->assertSame('ማክሰኞ', $this->calendar->format($date, 'l'));
        $this->assertSame('Meskerem', $this->calendar->format($date, 'F', 'en'));
    }

    #[Test]
    public function format_handles_escaped_characters(): void
    {
        $date = new DateTimeImmutable('2023-09-12 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        // "Y" is a token, but "\Y" should output literal "Y".
        //
        // The format string argument 'Y \\Y' is four characters at the PHP
        // level: 'Y', ' ', '\', 'Y'. (The double backslash is PHP's
        // single-quote escape for one literal backslash.)
        //
        // The production contract — EthiopianCalendar::format() docblock,
        // "Backslash (\) escapes the next character" — therefore requires:
        //   · 'Y'  → emitted as the year component  → "2016"
        //   · ' '  → emitted verbatim               → " "
        //   · '\Y' → backslash marks escape; 'Y' is
        //            emitted as a literal          → "Y"
        // Result: "2016 Y".
        //
        // CR-3 corrected the assertion below from '2016 \Y' to '2016 Y'.
        // The prior expectation contained a literal backslash between the
        // space and the 'Y' — a typo, not a contract.
        $this->assertSame('2016 Y', $this->calendar->format($date, 'Y \\Y'));
    }

    #[Test]
    public function format_iso_produces_zero_padded_string(): void
    {
        $this->assertSame('2016-01-01', $this->calendar->formatIso(2016, 1, 1));
        $this->assertSame('2016-04-28', $this->calendar->formatIso(2016, 4, 28));
        $this->assertSame('2016-13-05', $this->calendar->formatIso(2016, 13, 5));
    }

    #[Test]
    public function format_full_returns_amharic_date_with_suffix(): void
    {
        // Ethiopian 2016/1/1 = 12 Sep 2023 (Tuesday)
        $result = $this->calendar->formatFull(2016, 1, 1, 2);

        $this->assertStringContainsString('ማክሰኞ', $result);
        $this->assertStringContainsString('መስከረም', $result);
        $this->assertStringContainsString('2016', $result);
        $this->assertStringContainsString('ዓ.ም.', $result);
    }

    #[Test]
    public function format_date_range_collapses_same_month_and_year(): void
    {
        $result = $this->calendar->formatDateRange([2016, 1, 1], [2016, 1, 15]);

        $this->assertSame('መስከረም 1–15, 2016', $result);
    }

    #[Test]
    public function format_date_range_expands_different_months(): void
    {
        $result = $this->calendar->formatDateRange([2016, 1, 1], [2016, 2, 5]);

        $this->assertStringContainsString('መስከረም', $result);
        $this->assertStringContainsString('ጥቅምት', $result);
    }

    // =========================================================================
    // 7. Ethiopian Local Time
    // =========================================================================
    #[Test]
    public function ethiopian_time_shift_is_6_hours_behind_gregorian(): void
    {
        // Gregorian 06:00 = Ethiopian 12:00 (day)
        $morning = new DateTimeImmutable('2024-01-01 06:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $result = $this->calendar->toEthiopianTime($morning);

        $this->assertSame(12, $result['hour']);
        $this->assertSame(0, $result['minute']);
        $this->assertSame('ጠዋት', $result['period']);
        $this->assertSame('12:00 ጠዋት', $result['formatted']);

        // Gregorian 18:00 = Ethiopian 12:00 (afternoon → ቀን)
        $evening = new DateTimeImmutable('2024-01-01 18:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $result = $this->calendar->toEthiopianTime($evening);

        $this->assertSame(12, $result['hour']);
        $this->assertSame('ቀን', $result['period']);
        $this->assertSame('12:00 ቀን', $result['formatted']);
    }

    #[Test]
    public function ethiopian_time_at_gregorian_midnight(): void
    {
        // Gregorian 00:00 = Ethiopian 18:00 (previous day's afternoon)
        $midnight = new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $result = $this->calendar->toEthiopianTime($midnight);

        $this->assertSame(6, $result['hour']);
        $this->assertSame('ቀን', $result['period']);
    }

    #[Test]
    public function time_of_day_phrase_matches_expected_periods(): void
    {
        $this->assertSame('እኩለ ሌሊት', $this->calendar->timeOfDayPhrase(0, 0));
        $this->assertSame('ከንጋቱ', $this->calendar->timeOfDayPhrase(5, 0));
        $this->assertSame('ከጠዋቱ', $this->calendar->timeOfDayPhrase(7, 30));
        $this->assertSame('ከረፋዱ', $this->calendar->timeOfDayPhrase(10, 0));
        $this->assertSame('እኩለ ቀን', $this->calendar->timeOfDayPhrase(12, 15));
        $this->assertSame('ከሰዓት', $this->calendar->timeOfDayPhrase(15, 0));
        $this->assertSame('ከማምሻው', $this->calendar->timeOfDayPhrase(18, 0));
        $this->assertSame('ምሽቱ', $this->calendar->timeOfDayPhrase(21, 0));
    }

    #[Test]
    public function time_of_day_phrase_returns_generic_for_invalid_hour(): void
    {
        $this->assertSame('ሰዓት', $this->calendar->timeOfDayPhrase(25, 0));
        $this->assertSame('ሰዓት', $this->calendar->timeOfDayPhrase(-1, 0));
    }

    // =========================================================================
    // 8. Date Arithmetic
    // =========================================================================
    #[Test]
    public function add_days_crosses_month_boundary(): void
    {
        // 2016-01-30 + 1 day = 2016-02-01
        $result = $this->calendar->addDays(2016, 1, 30, 1);
        $this->assertSame(['year' => 2016, 'month' => 2, 'day' => 1], $result);
    }

    #[Test]
    public function add_days_crosses_year_boundary_through_pagume(): void
    {
        // 2016-13-05 + 1 day = 2017-01-01 (2016 is a common year)
        $result = $this->calendar->addDays(2016, 13, 5, 1);
        $this->assertSame(['year' => 2017, 'month' => 1, 'day' => 1], $result);
    }

    #[Test]
    public function add_days_handles_pagume_leap_year(): void
    {
        // 2015-13-06 + 1 day = 2016-01-01 (2015 is leap)
        $result = $this->calendar->addDays(2015, 13, 6, 1);
        $this->assertSame(['year' => 2016, 'month' => 1, 'day' => 1], $result);
    }

    #[Test]
    public function add_days_accepts_negative_values(): void
    {
        // 2016-01-01 - 1 day = 2015-13-06 (previous year's last Pagume day)
        $result = $this->calendar->addDays(2016, 1, 1, -1);
        $this->assertSame(['year' => 2015, 'month' => 13, 'day' => 6], $result);
    }

    #[Test]
    public function diff_in_days_computes_signed_difference(): void
    {
        // Same date → 0
        $this->assertSame(0, $this->calendar->diffInDays([2016, 1, 1], [2016, 1, 1]));

        // 10 days forward
        $this->assertSame(10, $this->calendar->diffInDays([2016, 1, 1], [2016, 1, 11]));

        // Backwards
        $this->assertSame(-10, $this->calendar->diffInDays([2016, 1, 11], [2016, 1, 1]));
    }

    #[Test]
    public function get_weekday_returns_sunday_as_zero(): void
    {
        // Ethiopian 2016/1/1 = Gregorian 12 Sep 2023 = Tuesday → weekday 2
        $this->assertSame(2, $this->calendar->getWeekday(2016, 1, 1));

        // Ethiopian 2016/4/28 = Gregorian 7 Jan 2024 = Sunday → weekday 0
        $this->assertSame(0, $this->calendar->getWeekday(2016, 4, 28));

        // Ethiopian 2015/4/29 = Gregorian 7 Jan 2023 = Saturday → weekday 6
        $this->assertSame(6, $this->calendar->getWeekday(2015, 4, 29));
    }

    #[Test]
    public function is_weekend_returns_true_for_friday_and_saturday(): void
    {
        // Ethiopian 2015/4/29 = Saturday → weekend
        $this->assertTrue($this->calendar->isWeekend(2015, 4, 29));

        // Ethiopian 2016/4/28 = Sunday → not weekend
        $this->assertFalse($this->calendar->isWeekend(2016, 4, 28));
    }

    // =========================================================================
    // 9. Fiscal Year and Quarters
    // =========================================================================
    #[Test]
    public function fiscal_year_starts_on_hamle_1(): void
    {
        // Months before Hamle → same Ethiopian year
        $this->assertSame(2016, $this->calendar->getFiscalYear(2016, 1));
        $this->assertSame(2016, $this->calendar->getFiscalYear(2016, 10));

        // Hamle (11) and after → next fiscal year
        $this->assertSame(2017, $this->calendar->getFiscalYear(2016, 11));
        $this->assertSame(2017, $this->calendar->getFiscalYear(2016, 12));
        $this->assertSame(2017, $this->calendar->getFiscalYear(2016, 13));
    }

    #[Test]
    public function fiscal_year_range_spans_hamle_to_sene(): void
    {
        $range = $this->calendar->getFiscalYearRange(2017);

        // Start = Hamle 1, 2016 (previous year's Hamle)
        $startEth = $this->calendar->toEthiopian($range['start']);
        $this->assertSame(2016, $startEth['year']);
        $this->assertSame(11, $startEth['month']);
        $this->assertSame(1, $startEth['day']);

        // End = Sene 30, 2017
        $endEth = $this->calendar->toEthiopian($range['end']);
        $this->assertSame(2017, $endEth['year']);
        $this->assertSame(10, $endEth['month']);
        $this->assertSame(30, $endEth['day']);
    }

    #[Test]
    public function quarter_range_q1_covers_hamle_to_pagume(): void
    {
        $range = $this->calendar->getQuarterRange(2017, 1);

        $startEth = $this->calendar->toEthiopian($range['start']);
        $endEth = $this->calendar->toEthiopian($range['end']);

        $this->assertSame([2016, 11, 1], [$startEth['year'], $startEth['month'], $startEth['day']]);
        $this->assertSame(2016, $endEth['year']);
        $this->assertSame(13, $endEth['month']);
    }

    #[Test]
    public function quarter_range_q2_covers_meskerem_to_hidar(): void
    {
        $range = $this->calendar->getQuarterRange(2017, 2);

        $startEth = $this->calendar->toEthiopian($range['start']);
        $endEth = $this->calendar->toEthiopian($range['end']);

        $this->assertSame([2017, 1, 1], [$startEth['year'], $startEth['month'], $startEth['day']]);
        $this->assertSame([2017, 3, 30], [$endEth['year'], $endEth['month'], $endEth['day']]);
    }

    #[Test]
    public function quarter_range_q3_covers_tahsas_to_yekatit(): void
    {
        $range = $this->calendar->getQuarterRange(2017, 3);

        $startEth = $this->calendar->toEthiopian($range['start']);
        $endEth = $this->calendar->toEthiopian($range['end']);

        $this->assertSame([2017, 4, 1], [$startEth['year'], $startEth['month'], $startEth['day']]);
        $this->assertSame([2017, 6, 30], [$endEth['year'], $endEth['month'], $endEth['day']]);
    }

    #[Test]
    public function quarter_range_q4_covers_megabit_to_sene(): void
    {
        $range = $this->calendar->getQuarterRange(2017, 4);

        $startEth = $this->calendar->toEthiopian($range['start']);
        $endEth = $this->calendar->toEthiopian($range['end']);

        $this->assertSame([2017, 7, 1], [$startEth['year'], $startEth['month'], $startEth['day']]);
        $this->assertSame([2017, 10, 30], [$endEth['year'], $endEth['month'], $endEth['day']]);
    }

    #[Test]
    public function quarter_range_throws_for_invalid_quarter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calendar->getQuarterRange(2017, 5);
    }

    #[Test]
    public function gregorian_range_for_year_starts_and_ends_at_correct_dates(): void
    {
        $range = $this->calendar->getGregorianRangeForYear(2016);

        // 2016 EC starts 12 Sep 2023, ends at the last Pagume day.
        $this->assertSame('2023-09-12', $range['start']->format('Y-m-d'));

        $endEth = $this->calendar->toEthiopian($range['end']);
        $this->assertSame([2016, 13, 5], [$endEth['year'], $endEth['month'], $endEth['day']]);
    }

    // =========================================================================
    // 10. Business Days
    // =========================================================================
    #[Test]
    public function business_days_between_excludes_weekends(): void
    {
        // Ethiopian 2016/1/1 (Tue) to 2016/1/7 (Mon): 7 days inclusive.
        // Weekend = Fri (5th), Sat (6th). Business days = 5.
        $count = $this->calendar->getBusinessDaysBetween([2016, 1, 1], [2016, 1, 7]);
        $this->assertSame(5, $count);
    }

    #[Test]
    public function business_days_between_excludes_given_holidays(): void
    {
        // Exclude 2016/1/1 (Meskerem 1, Enkutatash) from the same range.
        // Business days become 4.
        $count = $this->calendar->getBusinessDaysBetween(
            [2016, 1, 1],
            [2016, 1, 7],
            [[2016, 1, 1]]
        );
        $this->assertSame(4, $count);
    }

    #[Test]
    public function business_days_between_returns_zero_for_negative_range(): void
    {
        $count = $this->calendar->getBusinessDaysBetween([2016, 1, 10], [2016, 1, 1]);
        $this->assertSame(0, $count);
    }

    #[Test]
    public function add_business_days_skips_weekends(): void
    {
        // Ethiopian 2016/1/1 (Tue) + 5 business days → 2016/1/8 (Tue).
        // Dates: 1 Tue, 2 Wed, 3 Thu, 4 Fri (weekend), 5 Sat (weekend),
        // 6 Sun, 7 Mon, 8 Tue.
        $result = $this->calendar->addBusinessDays(2016, 1, 1, 5);
        $this->assertSame([2016, 1, 8], [$result['year'], $result['month'], $result['day']]);
    }

    #[Test]
    public function add_business_days_skips_holidays(): void
    {
        // Ethiopian 2016/1/1 (Tue) + 5 business days, skipping 2016/1/2.
        $result = $this->calendar->addBusinessDays(2016, 1, 1, 5, [[2016, 1, 2]]);
        $this->assertSame([2016, 1, 9], [$result['year'], $result['month'], $result['day']]);
    }

    // =========================================================================
    // 11. Holidays
    // =========================================================================
    #[Test]
    public function get_holidays_returns_all_17_holidays(): void
    {
        $holidays = $this->calendar->getHolidays(2016);

        $this->assertCount(17, $holidays);
        $this->assertArrayHasKey('enkutatash', $holidays);
        $this->assertArrayHasKey('meskel', $holidays);
        $this->assertArrayHasKey('leddet', $holidays);
        $this->assertArrayHasKey('fasika', $holidays);
        $this->assertArrayHasKey('eid_fitr', $holidays);
    }

    #[Test]
    public function enkutatash_is_on_meskerem_1_and_maps_correctly(): void
    {
        $holiday = $this->calendar->enkutatash(2016);

        $this->assertSame('enkutatash', $holiday['key']);
        $this->assertSame([2016, 1, 1], $holiday['ethiopian']);
        $this->assertSame('2023-09-12', $holiday['gregorian']->format('Y-m-d'));
        $this->assertSame(EthiopianCalendar::HOLIDAY_TYPE_NATIONAL, $holiday['type']);
    }

    #[Test]
    public function meskel_is_on_meskerem_17(): void
    {
        $holiday = $this->calendar->meskel(2016);

        $this->assertSame([2016, 1, 17], $holiday['ethiopian']);
        $this->assertSame(EthiopianCalendar::HOLIDAY_TYPE_RELIGIOUS, $holiday['type']);
    }

    #[Test]
    public function leddet_uses_tahsas_28_in_year_2016(): void
    {
        $holiday = $this->calendar->leddet(2016);

        $this->assertSame([2016, 4, 28], $holiday['ethiopian']);
        $this->assertSame('2024-01-07', $holiday['gregorian']->format('Y-m-d'));
    }

    #[Test]
    public function leddet_uses_tahsas_29_in_year_2015(): void
    {
        $holiday = $this->calendar->leddet(2015);

        $this->assertSame([2015, 4, 29], $holiday['ethiopian']);
        $this->assertSame('2023-01-07', $holiday['gregorian']->format('Y-m-d'));
    }

    #[Test]
    public function gena_is_the_day_before_leddet(): void
    {
        $gena2016 = $this->calendar->gena(2016);
        $leddet2016 = $this->calendar->leddet(2016);

        $this->assertSame([2016, 4, 27], $gena2016['ethiopian']);
        $this->assertSame([2016, 4, 28], $leddet2016['ethiopian']);

        $gena2015 = $this->calendar->gena(2015);
        $leddet2015 = $this->calendar->leddet(2015);

        $this->assertSame([2015, 4, 28], $gena2015['ethiopian']);
        $this->assertSame([2015, 4, 29], $leddet2015['ethiopian']);
    }

    #[Test]
    public function timket_is_on_tir_11(): void
    {
        $holiday = $this->calendar->timket(2016);

        $this->assertSame([2016, 5, 11], $holiday['ethiopian']);
    }

    #[Test]
    public function maale_new_year_is_on_tir_1_and_includes_anniversary(): void
    {
        $holiday = $this->calendar->maaleNewYear(2017);

        $this->assertSame([2017, 5, 1], $holiday['ethiopian']);
        $this->assertSame(12, $holiday['anniversary']); // 2017 - 2006 + 1 = 12
        $this->assertSame(EthiopianCalendar::HOLIDAY_TYPE_CULTURAL, $holiday['type']);
    }

    #[Test]
    public function nations_day_is_on_hidar_29(): void
    {
        $holiday = $this->calendar->nationsDay(2016);

        $this->assertSame([2016, 3, 29], $holiday['ethiopian']);
    }

    #[Test]
    public function martyrs_day_is_on_yekatit_12(): void
    {
        $holiday = $this->calendar->martyrsDay(2016);

        $this->assertSame([2016, 6, 12], $holiday['ethiopian']);
        $this->assertSame(EthiopianCalendar::HOLIDAY_TYPE_NATIONAL, $holiday['type']);
    }

    #[Test]
    public function adwa_day_is_on_yekatit_23(): void
    {
        $holiday = $this->calendar->adwaDay(2016);

        $this->assertSame([2016, 6, 23], $holiday['ethiopian']);
    }

    #[Test]
    public function labour_day_is_on_miazia_23(): void
    {
        $holiday = $this->calendar->labourDay(2016);

        $this->assertSame([2016, 8, 23], $holiday['ethiopian']);
    }

    #[Test]
    public function patriots_day_is_on_miazia_27(): void
    {
        $holiday = $this->calendar->patriotsDay(2016);

        $this->assertSame([2016, 8, 27], $holiday['ethiopian']);
    }

    #[Test]
    public function fasika_2016_matches_orthodox_easter_5_may_2024(): void
    {
        $holiday = $this->calendar->fasika(2016);

        $this->assertSame('2024-05-05', $holiday['gregorian']->format('Y-m-d'));
        $this->assertTrue($holiday['is_movable']);
    }

    #[Test]
    public function fasika_2017_matches_orthodox_easter_20_april_2025(): void
    {
        $holiday = $this->calendar->fasika(2017);

        $this->assertSame('2025-04-20', $holiday['gregorian']->format('Y-m-d'));
    }

    #[Test]
    public function good_friday_is_two_days_before_fasika(): void
    {
        $fasika = $this->calendar->fasika(2016);
        $goodFriday = $this->calendar->goodFriday(2016);

        $diff = $this->calendar->diffInDays(
            $goodFriday['ethiopian'],
            $fasika['ethiopian']
        );

        $this->assertSame(2, $diff);
    }

    #[Test]
    public function palm_sunday_is_seven_days_before_fasika(): void
    {
        $fasika = $this->calendar->fasika(2016);
        $palmSunday = $this->calendar->palmSunday(2016);

        $diff = $this->calendar->diffInDays(
            $palmSunday['ethiopian'],
            $fasika['ethiopian']
        );

        $this->assertSame(7, $diff);
    }

    #[Test]
    public function islamic_holidays_are_flagged_as_approximate(): void
    {
        $eidFitr = $this->calendar->eidFitr(2016);
        $eidAdha = $this->calendar->eidAdha(2016);
        $mawlid = $this->calendar->mawlid(2016);

        $this->assertTrue($eidFitr['approximate']);
        $this->assertTrue($eidAdha['approximate']);
        $this->assertTrue($mawlid['approximate']);

        $this->assertSame(EthiopianCalendar::HOLIDAY_TYPE_ISLAMIC, $eidFitr['type']);
    }

    #[Test]
    public function get_holidays_by_type_filters_correctly(): void
    {
        $national = $this->calendar->getHolidaysByType(2016, EthiopianCalendar::HOLIDAY_TYPE_NATIONAL);

        $this->assertArrayHasKey('enkutatash', $national);
        $this->assertArrayHasKey('adwa_day', $national);
        $this->assertArrayNotHasKey('fasika', $national);
    }

    #[Test]
    public function get_upcoming_holidays_returns_sorted_list(): void
    {
        $upcoming = $this->calendar->getUpcomingHolidays(2016, 1, 1, 3);

        $this->assertCount(3, $upcoming);
        $this->assertSame('enkutatash', $upcoming[0]['holiday']['key']);
        $this->assertSame(0, $upcoming[0]['days_until']);

        // Assert that the list is ordered ascending by days_until:
        //   $upcoming[1]['days_until'] <= $upcoming[2]['days_until'].
        //
        // PHPUnit's assertLessThanOrEqual($expected, $actual) asserts
        // `$actual <= $expected`. The prior version passed the arguments
        // in the reverse order, asserting `$upcoming[2] <= $upcoming[1]`
        // (i.e. 88 <= 16), which contradicted the test name and the two
        // preceding assertions. CR-3 corrected the argument order.
        $this->assertLessThanOrEqual(
            $upcoming[2]['days_until'],
            $upcoming[1]['days_until']
        );
    }

    #[Test]
    public function get_next_holiday_returns_closest_holiday(): void
    {
        $next = $this->calendar->getNextHoliday(2016, 1, 1);

        $this->assertNotNull($next);
        $this->assertSame('enkutatash', $next['holiday']['key']);
        $this->assertSame(0, $next['days_until']);
    }

    // =========================================================================
    // 12. Countdowns
    // =========================================================================
    #[Test]
    public function countdown_to_returns_complete_structure(): void
    {
        $countdown = $this->calendar->countdownTo(2100, 1, 1);

        $this->assertArrayHasKey('days', $countdown);
        $this->assertArrayHasKey('hours', $countdown);
        $this->assertArrayHasKey('minutes', $countdown);
        $this->assertArrayHasKey('seconds', $countdown);
        $this->assertArrayHasKey('invert', $countdown);
        $this->assertArrayHasKey('target_ethiopian', $countdown);
        $this->assertArrayHasKey('target_gregorian', $countdown);
        $this->assertArrayHasKey('message', $countdown);

        $this->assertFalse($countdown['invert']); // future date
        $this->assertSame('2100-01-01', $countdown['target_ethiopian']);
    }

    #[Test]
    public function countdown_to_past_date_is_inverted(): void
    {
        $countdown = $this->calendar->countdownTo(2000, 1, 1);

        $this->assertTrue($countdown['invert']);
    }

    #[Test]
    public function countdown_to_leddet_targets_tahsas_28_or_29(): void
    {
        $countdown = $this->calendar->countdownToLeddet(2100);

        // 2100 is divisible by 4 → Tahsas 28
        $this->assertSame('2100-04-28', $countdown['target_ethiopian']);
    }

    #[Test]
    public function countdown_to_maale_new_year_includes_anniversary(): void
    {
        $countdown = $this->calendar->countdownToMaaleNewYear(2100);

        $this->assertArrayHasKey('anniversary', $countdown);
        $this->assertSame(2100 - 2006 + 1, $countdown['anniversary']);
    }

    // =========================================================================
    // 13. Maale New Year Anniversary
    // =========================================================================
    #[Test]
    public function maale_anniversary_calculates_correctly(): void
    {
        $info = $this->calendar->getMaaleAnniversaryInfo(2017);

        $this->assertSame(12, $info['current_anniversary']); // 2017 - 2006 + 1
        $this->assertSame(2006, $info['base_year']);
        $this->assertSame('ዶኦማ', $info['name_am']);
        $this->assertSame('Maale New Year', $info['name_en']);
    }

    #[Test]
    public function maale_anniversary_reports_next_milestone(): void
    {
        // 2018 EC → anniversary 13 → next milestone is 15
        $info = $this->calendar->getMaaleAnniversaryInfo(2018);

        $this->assertSame(13, $info['current_anniversary']);
        $this->assertNotNull($info['next_milestone']);
        $this->assertSame(15, $info['next_milestone']['year']);
        $this->assertSame(2, $info['next_milestone']['years_remaining']);
    }

    #[Test]
    public function maale_anniversary_before_base_year_defaults_to_one(): void
    {
        $info = $this->calendar->getMaaleAnniversaryInfo(2000);

        $this->assertSame(1, $info['current_anniversary']);
    }

    // =========================================================================
    // 14. Timezone Awareness
    // =========================================================================
    #[Test]
    public function timezone_is_preserved_in_conversions(): void
    {
        $calendar = new EthiopianCalendar(new DateTimeZone('Europe/London'));
        $gregorian = $calendar->toGregorian(2016, 1, 1);

        $this->assertSame('Europe/London', $gregorian->getTimezone()->getName());
    }

    #[Test]
    public function default_timezone_is_addis_ababa(): void
    {
        $calendar = new EthiopianCalendar();
        $this->assertSame('Africa/Addis_Ababa', $calendar->getTimezone()->getName());
    }
}