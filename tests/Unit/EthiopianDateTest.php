<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianDate;
use Tests\TestCase;

/**
 * EthiopianDateTest
 *
 * Unit tests for the EthiopianDate value object.
 *
 * Coverage:
 *   1. Factories — fromComponents, fromString, fromGregorian, today.
 *   2. Validation on construction.
 *   3. Value equality and three-way comparison.
 *   4. isBefore / isAfter.
 *   5. Date arithmetic — addDays (positive, zero, negative) and diffInDays.
 *   6. Round-trip conversion with EthiopianCalendar (to/from Gregorian).
 *   7. Formatting helpers — toIso, format, formatFull, monthName, dayName.
 *   8. Weekday / weekend helpers.
 *   9. JsonSerializable and Stringable behavior.
 *  10. Timezone-agnostic value semantics (equality across calendars).
 *
 * Reference data:
 *   - Ethiopian 2016/1/1 = Gregorian 12 Sep 2023 (Tuesday).
 *   - Ethiopian 2016/4/28 = Gregorian 7 Jan 2024 (Sunday) — Leddet.
 *   - Ethiopian 2015/4/29 = Gregorian 7 Jan 2023 (Saturday) — Leddet.
 *   - Ethiopian 2017/1/1 = Gregorian 11 Sep 2024.
 *
 * @package Tests\Unit
 */
final class EthiopianDateTest extends TestCase
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
    // 1. Factories
    // =========================================================================
    #[Test]
    public function from_components_creates_a_valid_date(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);

        $this->assertSame(2016, $date->year);
        $this->assertSame(1, $date->month);
        $this->assertSame(1, $date->day);
    }

    #[Test]
    public function from_components_rejects_invalid_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromComponents(2016, 14, 1);
    }

    #[Test]
    public function from_components_rejects_invalid_day_for_regular_month(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromComponents(2016, 1, 31);
    }

    #[Test]
    public function from_components_rejects_invalid_pagume_day_in_common_year(): void
    {
        // 2016 is a common year, so Pagume has 5 days.
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromComponents(2016, 13, 6);
    }

    #[Test]
    public function from_components_accepts_pagume_day_6_in_leap_year(): void
    {
        $date = EthiopianDate::fromComponents(2015, 13, 6);

        $this->assertSame(2015, $date->year);
        $this->assertSame(13, $date->month);
        $this->assertSame(6, $date->day);
    }

    #[Test]
    public function from_components_rejects_year_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromComponents(0, 1, 1);
    }

    #[Test]
    public function from_string_parses_zero_padded_format(): void
    {
        $date = EthiopianDate::fromString('2016-04-28');

        $this->assertSame(2016, $date->year);
        $this->assertSame(4, $date->month);
        $this->assertSame(28, $date->day);
    }

    #[Test]
    public function from_string_parses_unpadded_format(): void
    {
        $date = EthiopianDate::fromString('2016-4-8');

        $this->assertSame(2016, $date->year);
        $this->assertSame(4, $date->month);
        $this->assertSame(8, $date->day);
    }

    #[Test]
    public function from_string_rejects_malformed_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromString('2016/04/28');
    }

    #[Test]
    public function from_string_rejects_invalid_dates_after_parsing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::fromString('2016-13-06'); // 2016 is not a leap year
    }

    #[Test]
    public function from_gregorian_converts_a_known_date(): void
    {
        $gregorian = new DateTimeImmutable(
            '2023-09-12 12:00:00',
            new DateTimeZone('Africa/Addis_Ababa')
        );

        $date = EthiopianDate::fromGregorian($gregorian, $this->calendar);

        $this->assertSame(2016, $date->year);
        $this->assertSame(1, $date->month);
        $this->assertSame(1, $date->day);
    }

    #[Test]
    public function from_gregorian_uses_default_calendar_when_none_provided(): void
    {
        $gregorian = new DateTimeImmutable(
            '2024-01-07 12:00:00',
            new DateTimeZone('Africa/Addis_Ababa')
        );

        $date = EthiopianDate::fromGregorian($gregorian);

        // 7 Jan 2024 = Ethiopian 2016/4/28 (Leddet)
        $this->assertSame(2016, $date->year);
        $this->assertSame(4, $date->month);
        $this->assertSame(28, $date->day);
    }

    #[Test]
    public function today_returns_current_ethiopian_date(): void
    {
        $today = EthiopianDate::today($this->calendar);

        // Sanity check: year is within a plausible range for the current era.
        $this->assertGreaterThan(2000, $today->year);
        $this->assertLessThan(2100, $today->year);
        $this->assertGreaterThanOrEqual(1, $today->month);
        $this->assertLessThanOrEqual(13, $today->month);
        $this->assertGreaterThanOrEqual(1, $today->day);
        $this->assertLessThanOrEqual(30, $today->day);
    }

    // =========================================================================
    // 2. Conversion
    // =========================================================================
    #[Test]
    public function to_gregorian_maps_a_known_date_correctly(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $gregorian = $date->toGregorian($this->calendar);

        $this->assertSame('2023-09-12', $gregorian->format('Y-m-d'));
        $this->assertSame('Africa/Addis_Ababa', $gregorian->getTimezone()->getName());
    }

    #[Test]
    public function to_gregorian_round_trips_through_from_gregorian(): void
    {
        $original = EthiopianDate::fromComponents(2016, 4, 28);
        $gregorian = $original->toGregorian($this->calendar);
        $reconstructed = EthiopianDate::fromGregorian($gregorian, $this->calendar);

        $this->assertTrue($original->equals($reconstructed));
    }

    #[Test]
    public function to_iso_returns_zero_padded_string(): void
    {
        $this->assertSame(
            '2016-01-01',
            EthiopianDate::fromComponents(2016, 1, 1)->toIso()
        );
        $this->assertSame(
            '2016-04-28',
            EthiopianDate::fromComponents(2016, 4, 28)->toIso()
        );
        $this->assertSame(
            '2016-13-05',
            EthiopianDate::fromComponents(2016, 13, 5)->toIso()
        );
    }

    #[Test]
    public function to_array_returns_the_three_components(): void
    {
        $date = EthiopianDate::fromComponents(2016, 4, 28);

        $this->assertSame(
            ['year' => 2016, 'month' => 4, 'day' => 28],
            $date->toArray()
        );
    }

    // =========================================================================
    // 3. Equality and Comparison
    // =========================================================================
    #[Test]
    public function equals_returns_true_for_identical_dates(): void
    {
        $a = EthiopianDate::fromComponents(2016, 1, 1);
        $b = EthiopianDate::fromComponents(2016, 1, 1);

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    #[Test]
    public function equals_returns_false_for_different_dates(): void
    {
        $a = EthiopianDate::fromComponents(2016, 1, 1);
        $b = EthiopianDate::fromComponents(2016, 1, 2);
        $c = EthiopianDate::fromComponents(2016, 2, 1);
        $d = EthiopianDate::fromComponents(2017, 1, 1);

        $this->assertFalse($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertFalse($a->equals($d));
    }

    #[Test]
    public function compare_to_orders_by_year_then_month_then_day(): void
    {
        $a = EthiopianDate::fromComponents(2016, 1, 1);
        $b = EthiopianDate::fromComponents(2016, 1, 2);
        $c = EthiopianDate::fromComponents(2016, 2, 1);
        $d = EthiopianDate::fromComponents(2017, 1, 1);

        $this->assertSame(-1, $a->compareTo($b));
        $this->assertSame(1, $b->compareTo($a));
        $this->assertSame(0, $a->compareTo($a));
        $this->assertSame(-1, $a->compareTo($c));
        $this->assertSame(-1, $a->compareTo($d));
    }

    #[Test]
    public function is_before_and_is_after_are_consistent_with_compare_to(): void
    {
        $early = EthiopianDate::fromComponents(2016, 1, 1);
        $late = EthiopianDate::fromComponents(2016, 4, 28);

        $this->assertTrue($early->isBefore($late));
        $this->assertFalse($early->isAfter($late));
        $this->assertTrue($late->isAfter($early));
        $this->assertFalse($late->isBefore($early));
        $this->assertFalse($early->isBefore($early));
        $this->assertFalse($early->isAfter($early));
    }

    // =========================================================================
    // 4. Arithmetic
    // =========================================================================
    #[Test]
    public function add_days_returns_a_new_instance(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $later = $date->addDays(1, $this->calendar);

        $this->assertNotSame($date, $later);
        $this->assertSame('2016-01-01', $date->toIso()); // Original unchanged.
        $this->assertSame('2016-01-02', $later->toIso());
    }

    #[Test]
    public function add_days_zero_returns_the_same_instance(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $same = $date->addDays(0, $this->calendar);

        $this->assertSame($date, $same); // Same instance, no copy.
    }

    #[Test]
    public function add_days_crosses_a_month_boundary(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 30);
        $later = $date->addDays(1, $this->calendar);

        $this->assertSame('2016-02-01', $later->toIso());
    }

    #[Test]
    public function add_days_crosses_the_year_boundary_through_pagume(): void
    {
        // 2016 is a common year, so Pagume has 5 days.
        $date = EthiopianDate::fromComponents(2016, 13, 5);
        $later = $date->addDays(1, $this->calendar);

        $this->assertSame('2017-01-01', $later->toIso());
    }

    #[Test]
    public function add_days_handles_pagume_in_a_leap_year(): void
    {
        // 2015 is a leap year, so Pagume has 6 days.
        $date = EthiopianDate::fromComponents(2015, 13, 6);
        $later = $date->addDays(1, $this->calendar);

        $this->assertSame('2016-01-01', $later->toIso());
    }

    #[Test]
    public function add_days_accepts_negative_offsets(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $earlier = $date->addDays(-1, $this->calendar);

        $this->assertSame('2015-13-06', $earlier->toIso());
    }

    #[Test]
    public function diff_in_days_returns_positive_for_later_dates(): void
    {
        $early = EthiopianDate::fromComponents(2016, 1, 1);
        $late = EthiopianDate::fromComponents(2016, 1, 11);

        $this->assertSame(10, $late->diffInDays($early, $this->calendar));
    }

    #[Test]
    public function diff_in_days_returns_negative_for_earlier_dates(): void
    {
        $early = EthiopianDate::fromComponents(2016, 1, 1);
        $late = EthiopianDate::fromComponents(2016, 1, 11);

        $this->assertSame(-10, $early->diffInDays($late, $this->calendar));
    }

    #[Test]
    public function diff_in_days_is_zero_for_the_same_date(): void
    {
        $a = EthiopianDate::fromComponents(2016, 1, 1);
        $b = EthiopianDate::fromComponents(2016, 1, 1);

        $this->assertSame(0, $a->diffInDays($b, $this->calendar));
    }

    #[Test]
    public function add_days_and_diff_in_days_are_inverses(): void
    {
        $original = EthiopianDate::fromComponents(2016, 4, 28);
        $moved = $original->addDays(42, $this->calendar);
        $diff = $moved->diffInDays($original, $this->calendar);

        $this->assertSame(42, $diff);
    }

    // =========================================================================
    // 5. Formatting
    // =========================================================================
    #[Test]
    public function format_with_tokens_returns_expected_output(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);

        $this->assertSame('2016-01-01', $date->format('Y-m-d', 'am', $this->calendar));
        $this->assertSame('2016/1/1', $date->format('Y/n/j', 'am', $this->calendar));
    }

    #[Test]
    public function format_full_returns_amharic_string_with_suffix(): void
    {
        // Ethiopian 2016/1/1 = Tuesday (ማክሰኞ), Meskerem (መስከረም)
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $result = $date->formatFull('am', $this->calendar);

        $this->assertStringContainsString('ማክሰኞ', $result);
        $this->assertStringContainsString('መስከረም', $result);
        $this->assertStringContainsString('2016', $result);
        $this->assertStringContainsString('ዓ.ም.', $result);
    }

    #[Test]
    public function format_full_returns_english_string_when_requested(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);
        $result = $date->formatFull('en', $this->calendar);

        $this->assertStringContainsString('Tuesday', $result);
        $this->assertStringContainsString('Meskerem', $result);
        $this->assertStringContainsString('E.C.', $result);
    }

    #[Test]
    public function month_name_is_localized(): void
    {
        $meskerem = EthiopianDate::fromComponents(2016, 1, 1);
        $tahsas = EthiopianDate::fromComponents(2016, 4, 28);
        $pagume = EthiopianDate::fromComponents(2016, 13, 5);

        $this->assertSame('መስከረም', $meskerem->monthName('am', $this->calendar));
        $this->assertSame('Meskerem', $meskerem->monthName('en', $this->calendar));
        $this->assertSame('ታኅሣሥ', $tahsas->monthName('am', $this->calendar));
        $this->assertSame('ጳጉሜን', $pagume->monthName('am', $this->calendar));
    }

    #[Test]
    public function day_name_is_localized(): void
    {
        // Ethiopian 2016/1/1 = Tuesday
        $tuesday = EthiopianDate::fromComponents(2016, 1, 1);
        $this->assertSame('ማክሰኞ', $tuesday->dayName('am', $this->calendar));
        $this->assertSame('Tuesday', $tuesday->dayName('en', $this->calendar));

        // Ethiopian 2016/4/28 = Sunday
        $sunday = EthiopianDate::fromComponents(2016, 4, 28);
        $this->assertSame('እሑድ', $sunday->dayName('am', $this->calendar));
        $this->assertSame('Sunday', $sunday->dayName('en', $this->calendar));
    }

    // =========================================================================
    // 6. Weekday and Weekend
    // =========================================================================
    #[Test]
    public function weekday_returns_sunday_as_zero(): void
    {
        // 2016/1/1 = Tuesday → 2
        $this->assertSame(
            2,
            EthiopianDate::fromComponents(2016, 1, 1)->weekday($this->calendar)
        );

        // 2016/4/28 = Sunday → 0
        $this->assertSame(
            0,
            EthiopianDate::fromComponents(2016, 4, 28)->weekday($this->calendar)
        );

        // 2015/4/29 = Saturday → 6
        $this->assertSame(
            6,
            EthiopianDate::fromComponents(2015, 4, 29)->weekday($this->calendar)
        );
    }

    #[Test]
    public function is_weekend_returns_true_for_friday_and_saturday(): void
    {
        // Saturday → true
        $this->assertTrue(
            EthiopianDate::fromComponents(2015, 4, 29)->isWeekend($this->calendar)
        );

        // Sunday → false
        $this->assertFalse(
            EthiopianDate::fromComponents(2016, 4, 28)->isWeekend($this->calendar)
        );

        // Tuesday → false
        $this->assertFalse(
            EthiopianDate::fromComponents(2016, 1, 1)->isWeekend($this->calendar)
        );
    }

    // =========================================================================
    // 7. Serialization
    // =========================================================================
    #[Test]
    public function json_serialize_returns_the_components_array(): void
    {
        $date = EthiopianDate::fromComponents(2016, 4, 28);

        $this->assertSame(
            ['year' => 2016, 'month' => 4, 'day' => 28],
            $date->jsonSerialize()
        );
    }

    #[Test]
    public function json_encode_produces_expected_json(): void
    {
        $date = EthiopianDate::fromComponents(2016, 4, 28);

        $this->assertSame(
            '{"year":2016,"month":4,"day":28}',
            json_encode($date)
        );
    }

    #[Test]
    public function to_string_returns_the_iso_form(): void
    {
        $date = EthiopianDate::fromComponents(2016, 4, 28);

        $this->assertSame('2016-04-28', (string) $date);
        $this->assertSame('2016-04-28', $date->__toString());
    }

    #[Test]
    public function string_interpolation_uses_iso_form(): void
    {
        $date = EthiopianDate::fromComponents(2016, 1, 1);

        $message = sprintf('Date: %s', $date);

        $this->assertSame('Date: 2016-01-01', $message);
    }

    // =========================================================================
    // 8. Timezone-Agnostic Value Semantics
    // =========================================================================
    #[Test]
    public function equality_is_independent_of_the_calendar_timezone(): void
    {
        $addis = new EthiopianCalendar(new DateTimeZone('Africa/Addis_Ababa'));
        $london = new EthiopianCalendar(new DateTimeZone('Europe/London'));

        $a = EthiopianDate::fromComponents(2016, 1, 1);
        $b = EthiopianDate::fromComponents(2016, 1, 1);

        // Both are equal regardless of the calendar used to compare them.
        $this->assertTrue($a->equals($b));

        // The value object itself is unchanged after conversion with either calendar.
        $a->toGregorian($addis);
        $a->toGregorian($london);

        $this->assertSame('2016-01-01', $a->toIso());
    }

    #[Test]
    public function conversion_with_different_timezones_may_produce_different_gregorian_days(): void
    {
        $addis = new EthiopianCalendar(new DateTimeZone('Africa/Addis_Ababa'));
        $london = new EthiopianCalendar(new DateTimeZone('Europe/London'));

        $date = EthiopianDate::fromComponents(2016, 1, 1);

        $gregorianAddis = $date->toGregorian($addis);
        $gregorianLondon = $date->toGregorian($london);

        // The Ethiopian date maps to the same Gregorian calendar day,
        // but the timezone attached to the resulting DateTimeImmutable differs.
        $this->assertSame(
            $gregorianAddis->format('Y-m-d'),
            $gregorianLondon->format('Y-m-d')
        );
        $this->assertNotSame(
            $gregorianAddis->getTimezone()->getName(),
            $gregorianLondon->getTimezone()->getName()
        );
    }

    // =========================================================================
    // 9. Copy / Immutability
    // =========================================================================
    #[Test]
    public function copy_returns_an_equivalent_value_object(): void
    {
        $original = EthiopianDate::fromComponents(2016, 4, 28);
        $copy = $original->copy();

        $this->assertNotSame($original, $copy);
        $this->assertTrue($original->equals($copy));
    }
}