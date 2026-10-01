<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mockery;
use RuntimeException;
use Exception;
use ReflectionClass;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\Services\LocalizationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LocalizationServiceTest
 *
 * Unit tests for the Amharic-first LocalizationService.
 *
 * Coverage:
 *   - Locale detection precedence (user → session → cookie → header → default).
 *   - Amharic-first defaults and tiebreaks.
 *   - Locale setting with optional persistence.
 *   - Scoped locale switching via withLocale().
 *   - Locale metadata (supported, default, fallback, isAmharic / isEnglish).
 *   - Timezone management (validation included).
 *   - Date formatting: Gregorian and Ethiopian.
 *   - Ethiopian time (6-hour offset) and time-of-day phrases.
 *   - Number and currency formatting (ETB default).
 *   - Translation and text direction.
 *
 * @package Tests\Unit
 */
final class LocalizationServiceTest extends TestCase
{
    private LocalizationService $service;
    private EthiopianCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new EthiopianCalendar(
            new DateTimeZone('Africa/Addis_Ababa')
        );
        $this->service = new LocalizationService($this->calendar);

        Config::set('app.locale', 'am');
        Config::set('app.fallback_locale', 'en');
        Config::set('app.timezone', 'Africa/Addis_Ababa');
        Config::set('mases.localization.timezone', 'Africa/Addis_Ababa');

        Auth::logout();
        Session::flush();

        // Clean request: no headers, no cookies.
        $request = Request::create('/test', 'GET');
        $request->headers->remove('Accept-Language');
        $request->cookies->replace([]);
        $this->app->instance('request', $request);
    }

    // =========================================================================
    // 1. Locale Detection
    // =========================================================================

    #[Test]
    public function it_returns_amharic_default_when_no_preference_is_set(): void
    {
        $this->assertSame('am', $this->service->getCurrentLocale());
        $this->assertSame('am', $this->service->getLocale());
        $this->assertSame('am', $this->service->getDefaultLocale());
        $this->assertSame('en', $this->service->getFallbackLocale());
    }

    #[Test]
    public function it_returns_user_preference_when_authenticated(): void
    {
        $user = User::factory()->make(['locale' => 'en']);
        Auth::shouldReceive('user')->once()->andReturn($user);

        $this->assertSame('en', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_returns_session_locale_when_set(): void
    {
        Session::put('mases_locale', 'en');

        $this->assertSame('en', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_returns_cookie_locale_when_set(): void
    {
        $request = Request::create('/test', 'GET');
        $request->cookies->set('mases_locale', 'en');
        $this->app->instance('request', $request);

        $this->assertSame('en', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_detects_locale_from_accept_language_header(): void
    {
        $request = Request::create('/test', 'GET');
        // Explicit quality: English preferred.
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9,am;q=0.5');
        $this->app->instance('request', $request);

        $this->assertSame('en', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_prefers_amharic_on_quality_tie(): void
    {
        $request = Request::create('/test', 'GET');
        // Both locales have the same q; Amharic must win (Amharic-first).
        $request->headers->set('Accept-Language', 'en;q=0.9, am;q=0.9');
        $this->app->instance('request', $request);

        $this->assertSame('am', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_prefers_user_preference_over_header(): void
    {
        $user = User::factory()->make(['locale' => 'am']);
        Auth::shouldReceive('user')->once()->andReturn($user);

        $request = Request::create('/test', 'GET');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $this->app->instance('request', $request);

        $this->assertSame('am', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_prefers_session_over_cookie_and_header(): void
    {
        Session::put('mases_locale', 'am');

        $request = Request::create('/test', 'GET');
        $request->cookies->set('mases_locale', 'en');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
        $this->app->instance('request', $request);

        $this->assertSame('am', $this->service->getCurrentLocale());
    }

    #[Test]
    public function it_ignores_invalid_header_and_falls_back_to_amharic(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR,fr;q=0.9,de;q=0.8');
        $this->app->instance('request', $request);

        $this->assertSame('am', $this->service->getCurrentLocale());
    }

    // =========================================================================
    // 2. Setting the Locale
    // =========================================================================

    #[Test]
    public function it_can_set_the_locale_without_cookie_persistence(): void
    {
        Cookie::shouldReceive('queue')->never();

        $this->service->setLocale('en', false);

        $this->assertSame('en', App::getLocale());
        $this->assertSame('en', Session::get('mases_locale'));
    }

    #[Test]
    public function it_can_set_the_locale_and_persist_to_cookie(): void
    {
        Cookie::shouldReceive('queue')
            ->once()
            ->with('mases_locale', 'en', 60 * 24 * 365)
            ->andReturnNull();

        $this->service->setLocale('en', true);

        $this->assertSame('en', App::getLocale());
        $this->assertSame('en', Session::get('mases_locale'));
    }

    #[Test]
    public function it_saves_locale_to_user_when_provided(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->locale = 'am';
        $user->shouldReceive('save')->once();

        $this->service->setLocale('en', false, $user);
        $this->assertSame('en', $user->locale);
    }

    #[Test]
    public function it_does_not_persist_locale_to_user_when_unchanged(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->locale = 'en';
        $user->shouldReceive('save')->never();

        $this->service->setLocale('en', false, $user);
        $this->assertSame('en', $user->locale);
    }

    #[Test]
    public function it_ignores_invalid_locale_when_setting(): void
    {
        Cookie::shouldReceive('queue')->never();

        $this->service->setLocale('klingon', false);

        // Application locale unchanged; nothing persisted.
        $this->assertSame('am', App::getLocale());
        $this->assertNull(Session::get('mases_locale'));
    }

    // =========================================================================
    // 3. Scoped Locale Switching
    // =========================================================================

    #[Test]
    public function with_locale_runs_callback_under_temporary_locale(): void
    {
        $observed = null;

        $result = $this->service->withLocale('en', function () use (&$observed) {
            $observed = App::getLocale();
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame('en', $observed);
        // Restored afterwards.
        $this->assertSame('am', App::getLocale());
    }

    #[Test]
    public function with_locale_restores_previous_locale_even_on_exception(): void
    {
        try {
            $this->service->withLocale('en', function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame('am', App::getLocale());
    }

    // =========================================================================
    // 4. Locale Metadata
    // =========================================================================

    #[Test]
    public function it_returns_supported_locales_in_amharic_first_order(): void
    {
        $locales = $this->service->getSupportedLocales();

        $this->assertArrayHasKey('am', $locales);
        $this->assertArrayHasKey('en', $locales);
        $this->assertSame('አማርኛ', $locales['am']);
        $this->assertSame('English', $locales['en']);
        $this->assertCount(2, $locales);

        // Amharic-first: 'am' is the first key.
        $this->assertSame('am', array_key_first($locales));
    }

    #[Test]
    public function it_reports_locale_support(): void
    {
        $this->assertTrue($this->service->isSupported('am'));
        $this->assertTrue($this->service->isSupported('en'));
        $this->assertTrue($this->service->isSupported('am-ET'));
        $this->assertTrue($this->service->isSupported('en-US'));
        $this->assertFalse($this->service->isSupported('fr'));
        $this->assertFalse($this->service->isSupported('de'));
    }

    #[Test]
    public function it_reports_amharic_or_english_current_locale(): void
    {
        // Default is Amharic.
        $this->assertTrue($this->service->isAmharic());
        $this->assertFalse($this->service->isEnglish());

        Session::put('mases_locale', 'en');

        $this->assertFalse($this->service->isAmharic());
        $this->assertTrue($this->service->isEnglish());
    }

    // =========================================================================
    // 5. Timezone
    // =========================================================================

    #[Test]
    public function it_gets_current_timezone(): void
    {
        $this->assertSame('Africa/Addis_Ababa', $this->service->getCurrentTimezone());
    }

    #[Test]
    public function it_sets_timezone_and_updates_config_and_php_default(): void
    {
        $this->service->setTimezone('America/New_York');

        $this->assertSame('America/New_York', Config::get('app.timezone'));
        $this->assertSame('America/New_York', date_default_timezone_get());

        // Restore for subsequent tests.
        $this->service->setTimezone('Africa/Addis_Ababa');
    }

    #[Test]
    public function it_throws_exception_for_invalid_timezone(): void
    {
        $this->expectException(Exception::class);
        $this->service->setTimezone('Invalid/Timezone');
    }

    // =========================================================================
    // 6. Gregorian Date Formatting
    // =========================================================================

    #[Test]
    public function it_formats_date_from_datetime_object(): void
    {
        $date = new DateTimeImmutable('2026-09-09 14:30:00');
        $this->assertSame('2026-09-09 14:30:00', $this->service->formatDate($date, 'Y-m-d H:i:s'));
    }

    #[Test]
    public function it_formats_date_from_timestamp(): void
    {
        $timestamp = (new DateTimeImmutable('2026-09-09 14:30:00'))->getTimestamp();
        $this->assertSame('2026-09-09', $this->service->formatDate($timestamp, 'Y-m-d'));
    }

    #[Test]
    public function it_formats_date_from_string(): void
    {
        $this->assertSame('2026-09-09', $this->service->formatDate('2026-09-09', 'Y-m-d'));
    }

    #[Test]
    public function it_handles_invalid_date_strings_gracefully(): void
    {
        $this->assertSame('', $this->service->formatDate('not-a-date'));
    }

    // =========================================================================
    // 7. Ethiopian Calendar Formatting
    // =========================================================================

    #[Test]
    public function it_formats_ethiopian_date_with_amharic_month_and_weekday(): void
    {
        // 2024-01-07 = Ethiopian 2016/04/28 (Sunday, Tahsas) — Leddet.
        $date = new DateTimeImmutable('2024-01-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $formatted = $this->service->formatEthiopianDate($date, 'am');

        $this->assertStringContainsString('እሑድ', $formatted);         // Sunday
        $this->assertStringContainsString('ታኅሣሥ', $formatted);        // Tahsas
        $this->assertStringContainsString('28', $formatted);
        $this->assertStringContainsString('2016', $formatted);
        $this->assertStringContainsString('ዓ.ም.', $formatted);
    }

    #[Test]
    public function it_formats_ethiopian_date_in_english_when_requested(): void
    {
        $date = new DateTimeImmutable('2024-01-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $formatted = $this->service->formatEthiopianDate($date, 'en');

        $this->assertStringContainsString('Sunday', $formatted);
        $this->assertStringContainsString('Tahsas', $formatted);
        $this->assertStringContainsString('E.C.', $formatted);
    }

    #[Test]
    public function format_date_with_use_ethiopian_returns_real_ethiopian_output(): void
    {
        // 2024-01-07 = Ethiopian 2016-04-28.
        $date = new DateTimeImmutable('2024-01-07 12:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $formatted = $this->service->formatDate($date, 'Y-m-d', true);

        $this->assertSame('2016-04-28', $formatted);
        // No placeholder suffix remains.
        $this->assertStringNotContainsString('(ET)', $formatted);
    }

    #[Test]
    public function it_formats_ethiopian_time_with_period_suffix(): void
    {
        // Gregorian 18:00 = Ethiopian 12:00 ቀን (afternoon).
        $date = new DateTimeImmutable('2024-01-07 18:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $formatted = $this->service->formatEthiopianTime($date);

        $this->assertSame('12:00 ቀን', $formatted);
    }

    #[Test]
    public function it_formats_combined_ethiopian_date_and_time(): void
    {
        $date = new DateTimeImmutable('2024-01-07 18:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        $formatted = $this->service->formatEthiopianDateTime($date, 'am');

        $this->assertStringContainsString('ታኅሣሥ', $formatted);
        $this->assertStringContainsString('12:00 ቀን', $formatted);
    }

    // =========================================================================
    // 8. Ethiopian Calendar Accessors
    // =========================================================================

    #[Test]
    public function it_exposes_the_underlying_ethiopian_calendar(): void
    {
        $this->assertInstanceOf(EthiopianCalendar::class, $this->service->getEthiopianCalendar());
    }

    #[Test]
    public function it_returns_current_ethiopian_date_components(): void
    {
        $today = $this->service->getEthiopianToday();

        $this->assertGreaterThan(2000, $today['year']);
        $this->assertGreaterThanOrEqual(1, $today['month']);
        $this->assertLessThanOrEqual(13, $today['month']);
        $this->assertGreaterThanOrEqual(1, $today['day']);

        // The individual getters must agree with the composite.
        $this->assertSame($today['year'], $this->service->getEthiopianYear());
        $this->assertSame($today['month'], $this->service->getEthiopianMonth());
        $this->assertSame($today['day'], $this->service->getEthiopianDay());
    }

    #[Test]
    public function it_returns_localized_current_ethiopian_month_name(): void
    {
        $amharicName = $this->service->getEthiopianMonthName('am');
        $englishName = $this->service->getEthiopianMonthName('en');

        $this->assertNotSame('', $amharicName);
        $this->assertNotSame('', $englishName);
        $this->assertNotSame($amharicName, $englishName);
    }

    #[Test]
    public function it_returns_localized_current_ethiopian_weekday(): void
    {
        $amharic = $this->service->getEthiopianWeekday('am');
        $english = $this->service->getEthiopianWeekday('en');

        $this->assertNotSame('', $amharic);
        $this->assertNotSame('', $english);
        $this->assertNotSame($amharic, $english);
    }

    #[Test]
    public function it_returns_time_of_day_phrase_for_known_hour(): void
    {
        // 2024-01-07 07:00 → morning → 'ከጠዋቱ'
        $morning = new DateTimeImmutable('2024-01-07 07:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $this->assertSame('ከጠዋቱ', $this->service->timeOfDayPhrase($morning));

        // 2024-01-07 15:00 → afternoon → 'ከሰዓት'
        $afternoon = new DateTimeImmutable('2024-01-07 15:00:00', new DateTimeZone('Africa/Addis_Ababa'));
        $this->assertSame('ከሰዓት', $this->service->timeOfDayPhrase($afternoon));
    }

    #[Test]
    public function it_returns_time_of_day_phrase_for_current_time(): void
    {
        $phrase = $this->service->timeOfDayPhrase();

        // Must always return one of the canonical phrases (never empty).
        $this->assertNotSame('', $phrase);
    }

    // =========================================================================
    // 9. Number & Currency Formatting
    // =========================================================================

    #[Test]
    public function it_formats_numbers_with_decimals(): void
    {
        $this->assertSame('1,234.57', $this->service->formatNumber(1234.567, 2));
        $this->assertSame('1,234.567', $this->service->formatNumber(1234.567, 3));
    }

    #[Test]
    public function it_formats_integers_with_no_decimals(): void
    {
        $this->assertSame('1,234', $this->service->formatNumber(1234, 0));
    }

    #[Test]
    public function it_formats_currency_with_etb_symbol_by_default(): void
    {
        $formatted = $this->service->formatCurrency(1234.56);

        $this->assertStringContainsString('ብር', $formatted);
        $this->assertStringContainsString('1,234.56', $formatted);
    }

    #[Test]
    public function it_formats_currency_with_usd_symbol(): void
    {
        $formatted = $this->service->formatCurrency(1234.56, 'USD', true);

        $this->assertStringContainsString('$', $formatted);
        $this->assertStringContainsString('1,234.56', $formatted);
    }

    #[Test]
    public function it_formats_currency_without_symbol(): void
    {
        $formatted = $this->service->formatCurrency(1234.56, 'ETB', false);

        $this->assertStringNotContainsString('ብር', $formatted);
        $this->assertStringContainsString('1,234.56 ETB', $formatted);
    }

    #[Test]
    public function it_handles_unknown_currency_gracefully(): void
    {
        $formatted = $this->service->formatCurrency(1234.56, 'XYZ', true);

        $this->assertStringContainsString('1,234.56 XYZ', $formatted);
    }

    #[Test]
    public function it_uppercases_currency_codes(): void
    {
        $formatted = $this->service->formatCurrency(1234.56, 'etb', false);

        $this->assertStringContainsString('1,234.56 ETB', $formatted);
    }

    // =========================================================================
    // 10. Translation & Direction
    // =========================================================================

    #[Test]
    public function it_translates_strings_with_placeholders(): void
    {
        $this->assertSame(
            'Hello, World',
            $this->service->translate('Hello, :name', ['name' => 'World'])
        );
    }

    #[Test]
    public function it_returns_original_key_if_translation_not_found(): void
    {
        $this->assertSame('some.missing.key', $this->service->translate('some.missing.key'));
    }

    #[Test]
    public function it_returns_ltr_direction_for_supported_locales(): void
    {
        $this->assertSame('ltr', $this->service->getDirection());
    }

    // =========================================================================
    // 11. Private helper coverage (via reflection)
    // =========================================================================

    #[Test]
    public function it_validates_locale_and_returns_null_for_invalid(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('validateLocale');
        $method->setAccessible(true);

        $this->assertSame('am', $method->invoke($this->service, 'am'));
        $this->assertSame('en', $method->invoke($this->service, 'en'));
        $this->assertNull($method->invoke($this->service, 'fr'));
        $this->assertSame('am', $method->invoke($this->service, 'am-ET'));
        $this->assertSame('en', $method->invoke($this->service, 'en-US'));
        // Underscored tags are not accepted (we only accept '-').
        $this->assertNull($method->invoke($this->service, 'am_ET'));
    }
}