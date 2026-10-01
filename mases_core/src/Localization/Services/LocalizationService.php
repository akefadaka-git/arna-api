<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P3 · Unit 2 · File 1 of 13
 * =============================================================================
 *
 * File:            LocalizationService.php
 * Unit:            P3 · Unit 2 — Ethiopian calendar date rendering
 * Delivery type:   Amendment
 * Net lines:       +1 public method; +~120 docblock lines (change block,
 *                  method docblock); +2 class-docblock lines
 *
 * Before:
 *   LocalizationService exposed one Ethiopian-calendar date formatter:
 *
 *     formatEthiopianDate($date, ?string $locale): string
 *
 *   which delegates to EthiopianCalendar::formatFull() and always
 *   prefixes the result with the Ethiopian day name:
 *
 *     ሰኞ, ሐምሌ 9, 2018 ዓ.ም.
 *
 *   There was no formatter that produced the medium form the portal's
 *   table cells require — month name, day, year, era suffix, no weekday
 *   — nor any formatter that included the Gregorian equivalent in
 *   parentheses.
 *
 * After:
 *   One new public method:
 *
 *     formatEthiopianDateMedium($date, ?string $locale): string
 *
 *   which produces the medium form the whole Portal workstream uses for
 *   `_display` fields:
 *
 *     am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
 *     en:  Hamle 9, 2018 E.C. (July 16, 2026)
 *
 *   Both locales render the Ethiopian date first (in the locale's
 *   script) and the Gregorian equivalent in parentheses — the format
 *   agreed in the P3 · Unit 2 scoping. The Gregorian part uses English
 *   month names in both locales, matching the convention common in
 *   Ethiopian documents when a Gregorian reference is included.
 *
 *   The existing formatEthiopianDate() method is preserved byte-for-byte.
 *   No caller of that method changes behaviour.
 *
 * =============================================================================
 * The method's contract
 * =============================================================================
 *   · Accepts DateTimeInterface | string | int | null, matching
 *     formatEthiopianDate() and the other date helpers in this class.
 *   · Null or unparseable input returns '' (empty string) — the same
 *     convention as formatEthiopianDate() and formatDate().
 *   · Localizes the input to the calendar's display timezone before
 *     deriving either part. Both the Ethiopian and the Gregorian parts
 *     therefore describe the same localized instant, not two different
 *     days from two different timezones.
 *   · The Ethiopian part uses the current-locale month names
 *     (Amharic for am, transliterated for en) from
 *     EthiopianCalendar::monthName().
 *   · The era suffix is 'ዓ.ም.' for am and 'E.C.' for en — the same
 *     suffix pair EthiopianCalendar::formatFull() already uses.
 *   · The Gregorian part is formatted as 'F j, Y' — e.g., 'July 16,
 *     2026'. English month names in both locales.
 *
 * =============================================================================
 * Companion files in the same unit
 * =============================================================================
 * This file is the first of thirteen in P3 · Unit 2. The full sequence:
 *
 *   Group A — backend support:
 *     1. LocalizationService.php    (this file)
 *     2. RendersLocalizedDates.php  (new trait)
 *
 *   Group B — backend resources:
 *     3. ShareholderResource.php
 *     4. ShareHoldingResource.php
 *     5. ShareCertificateResource.php
 *     6. ShareClassResource.php
 *
 *   Group C — frontend types:
 *     7. features/shareholders/api.ts
 *
 *   Group D — portal pages:
 *     8. PortalHomePage.vue
 *     9. MyShareholdingsPage.vue
 *    10. MyCertificatesPage.vue
 *
 *   Group E — workspace pages:
 *    11. ShareholderListView.vue
 *    12. ShareholderDetailView.vue
 *    13. ShareClassListView.vue  (conditional — only if it renders dates)
 *
 * The trait (file 2) consumes this file's new method. Resources in
 * Group B consume the trait.
 *
 * =============================================================================
 * Native-speaker review
 * =============================================================================
 * Two points recorded for the eventual review pass:
 *
 *   1. The era suffix 'ዓ.ም.' vs 'ዓ.ም' (with vs. without the trailing
 *      period). The existing EthiopianCalendar::formatFull() uses
 *      'ዓ.ም.' with the period; this method matches that convention.
 *      The P3 · Unit 2 scoping message wrote the suffix without the
 *      trailing period. If a reviewer prefers the period-less form,
 *      changing this method's local suffix (and, for consistency,
 *      formatFull()'s) is a one-character change.
 *
 *   2. The Gregorian month names in the parenthetical are English
 *      ('July') rather than transliterated or Amharic. This matches
 *      the scoping example. If a reviewer prefers Amharic month names
 *      for the Gregorian part in the 'am' locale, the change is
 *      localized to this method's `$localized->format('F j, Y')` call.
 *
 * =============================================================================
 * Adjacent finding — not fixed by this unit
 * =============================================================================
 * CoreServiceProvider::registerCoreBindings() binds LocalizationService
 * as:
 *
 *     $this->app->singleton(
 *         LocalizationService::class,
 *         static fn($app): LocalizationService => new LocalizationService()
 *     );
 *
 * No EthiopianCalendar is passed in, so the service constructs its own
 * with the class's default timezone ('Africa/Addis_Ababa'). The
 * EthiopianCalendar singleton that CoreServiceProvider separately binds
 * with the configured timezone is therefore not the instance the service
 * uses. In practice the two coincide because both default to
 * Africa/Addis_Ababa; a deployment that sets a non-default
 * `mases.localization.timezone` would see the two diverge. This is a
 * pre-existing wiring gap, out of scope for P3 · Unit 2, and flagged
 * here so it is not lost.
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Dependencies added:        none
 * Public surface added:      formatEthiopianDateMedium()
 * Public surface removed:    none
 * Public surface modified:   none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Localization\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Localization\Calendar\EthiopianCalendar;

/**
 * LocalizationService
 *
 * Central service for localization and internationalization in MASES.
 *
 * -----------------------------------------------------------------------------
 * Amharic-First Policy
 * -----------------------------------------------------------------------------
 * The platform is Amharic-first. This is enforced at every layer:
 *
 *   1. The default locale is `am`, never `en`.
 *   2. The cookie / session persistence always stores the user's explicit
 *      choice; if the user has never chosen, Amharic is used.
 *   3. `Accept-Language` negotiation is Amharic-preferred: when two locales
 *      carry the same `q` value, Amharic wins.
 *   4. Ethiopian calendar conversions are available first-class via
 *      `formatEthiopianDate()`, `formatEthiopianDateMedium()`,
 *      `formatEthiopianTime()`, etc., and the `formatDate(...,
 *      $useEthiopian: true)` path delegates to the `EthiopianCalendar`
 *      engine.
 *   5. All translation helpers default to the current locale, which falls
 *      back to Amharic.
 *
 * -----------------------------------------------------------------------------
 * Features
 * -----------------------------------------------------------------------------
 *   - Locale detection (user preference → session → cookie → header → default)
 *   - Locale persistence (session, cookie, user model)
 *   - Scoped locale switching via `withLocale()`
 *   - Timezone management (Africa/Addis_Ababa default)
 *   - Gregorian and Ethiopian calendar formatting, including a medium
 *     form that pairs the Ethiopian date with the Gregorian equivalent
 *     in parentheses (`formatEthiopianDateMedium`)
 *   - Ethiopian local time (6-hour offset) and time-of-day phrases
 *   - Number and currency formatting (ETB by default)
 *   - Translation wrapper with Amharic-first defaults
 *   - Text direction detection (LTR for both supported locales)
 *
 * @package MaaleArna\MASES\Core\Localization\Services
 * @version 1.1.0
 */
final class LocalizationService
{
    // =========================================================================
    // Constants
    // =========================================================================

    /**
     * Supported locales, in Amharic-first order.
     *
     * @var array<string, string>
     */
    private const SUPPORTED_LOCALES = [
        'am' => 'አማርኛ',
        'en' => 'English',
    ];

    /**
     * Priority of each locale for tie-breaking during negotiation.
     * Lower is better.
     *
     * @var array<string, int>
     */
    private const LOCALE_PRIORITY = [
        'am' => 0,
        'en' => 1,
    ];

    /**
     * Default locale (Amharic-first).
     */
    private const DEFAULT_LOCALE = 'am';

    /**
     * Fallback locale (used only when a translation is missing in Amharic).
     */
    private const FALLBACK_LOCALE = 'en';

    /**
     * Default timezone.
     */
    private const DEFAULT_TIMEZONE = 'Africa/Addis_Ababa';

    /**
     * Session key for the locale preference.
     */
    private const SESSION_LOCALE_KEY = 'mases_locale';

    /**
     * Cookie name for the locale preference.
     */
    private const COOKIE_LOCALE_NAME = 'mases_locale';

    /**
     * Cookie lifetime in minutes (1 year).
     */
    private const COOKIE_LOCALE_LIFETIME = 60 * 24 * 365;

    /**
     * Accept-Language header name.
     */
    private const ACCEPT_LANGUAGE_HEADER = 'Accept-Language';

    /**
     * Era suffix for the Ethiopian calendar, by locale.
     *
     * `ዓ.ም.` is the Amharic abbreviation of `ዓመተ ምሕረት` ("Year of Grace"),
     * the era under which the Ethiopian calendar counts years.
     *
     * `E.C.` is the English transliteration, `Ethiopian Calendar`.
     *
     * This constant is the single source for both the medium-form
     * formatter added in revision 1.1.0 and (if a future CR chooses to
     * extract it) `EthiopianCalendar::formatFull()`, which currently
     * inlines the same two strings.
     *
     * @var array<string, string>
     */
    private const ERA_SUFFIX = [
        'am' => 'ዓ.ም.',
        'en' => 'E.C.',
    ];

    // =========================================================================
    // Dependencies
    // =========================================================================

    /**
     * The Ethiopian calendar engine used for date conversions.
     *
     * @var EthiopianCalendar
     */
    private EthiopianCalendar $calendar;

    /**
     * Create a new LocalizationService instance.
     *
     * The Ethiopian calendar dependency is injected so the same
     * Amharic-first configuration (timezone, locale) is honoured across
     * the entire platform.
     *
     * @param EthiopianCalendar|null $calendar
     */
    public function __construct(?EthiopianCalendar $calendar = null)
    {
        $this->calendar = $calendar ?? new EthiopianCalendar();
    }

    // =========================================================================
    // Locale Detection
    // =========================================================================

    /**
     * Get the currently active locale.
     *
     * Resolution order (highest priority first):
     *   1. Authenticated user's saved `locale`.
     *   2. Session value.
     *   3. Cookie value.
     *   4. `Accept-Language` header (Amharic-preferred tiebreak).
     *   5. Amharic (default).
     *
     * @return string
     */
    public function getCurrentLocale(): string
    {
        // 1. User preference.
        $user = auth()->user();
        if ($user instanceof User && !empty($user->locale)) {
            $validated = $this->validateLocale($user->locale);
            if ($validated !== null) {
                return $validated;
            }
        }

        // 2. Session.
        $sessionLocale = Session::get(self::SESSION_LOCALE_KEY);
        if ($sessionLocale !== null && is_string($sessionLocale)) {
            $validated = $this->validateLocale($sessionLocale);
            if ($validated !== null) {
                return $validated;
            }
        }

        // 3. Cookie.
        $cookieLocale = Cookie::get(self::COOKIE_LOCALE_NAME);
        if ($cookieLocale !== null && is_string($cookieLocale)) {
            $validated = $this->validateLocale($cookieLocale);
            if ($validated !== null) {
                return $validated;
            }
        }

        // 4. Accept-Language header.
        $headerLocale = $this->detectFromHeader();
        if ($headerLocale !== null) {
            return $headerLocale;
        }

        // 5. Amharic-first default.
        return self::DEFAULT_LOCALE;
    }

    /**
     * Alias for getCurrentLocale().
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->getCurrentLocale();
    }

    /**
     * Set the active locale.
     *
     * Invalid locales are silently ignored (no exception) so a malformed
     * input never breaks the request lifecycle.
     *
     * @param string    $locale
     * @param bool      $persist  Queue a cookie so the choice survives sessions.
     * @param User|null $user     If provided, persists the choice on the user.
     *
     * @return void
     */
    public function setLocale(string $locale, bool $persist = true, ?User $user = null): void
    {
        $validated = $this->validateLocale($locale);
        if ($validated === null) {
            return;
        }

        // Update Laravel's application locale.
        App::setLocale($validated);

        // Persist in session.
        Session::put(self::SESSION_LOCALE_KEY, $validated);

        // Queue the cookie if requested.
        if ($persist) {
            Cookie::queue(
                self::COOKIE_LOCALE_NAME,
                $validated,
                self::COOKIE_LOCALE_LIFETIME
            );
        }

        // Persist on the user model.
        if ($user instanceof User) {
            if ($user->locale !== $validated) {
                $user->locale = $validated;
                $user->save();
            }
        } elseif (auth()->check()) {
            $currentUser = auth()->user();
            if ($currentUser instanceof User && $currentUser->locale !== $validated) {
                $currentUser->locale = $validated;
                $currentUser->save();
            }
        }
    }

    /**
     * Run a callback with a temporary locale, restoring the previous one
     * afterwards. Useful for jobs, notifications, and tests.
     *
     * The temporary locale is NOT persisted to session, cookie, or user.
     *
     * @template T
     * @param string   $locale
     * @param callable(): T $callback
     *
     * @return T
     */
    public function withLocale(string $locale, callable $callback): mixed
    {
        $validated = $this->validateLocale($locale) ?? self::DEFAULT_LOCALE;
        $previous = App::getLocale();

        App::setLocale($validated);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    // =========================================================================
    // Locale Metadata
    // =========================================================================

    /**
     * Get the supported locales with display names (Amharic-first order).
     *
     * @return array<string, string>
     */
    public function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Get the default locale (Amharic).
     *
     * @return string
     */
    public function getDefaultLocale(): string
    {
        return self::DEFAULT_LOCALE;
    }

    /**
     * Get the fallback locale (English, used only when a translation is
     * missing in Amharic).
     *
     * @return string
     */
    public function getFallbackLocale(): string
    {
        return self::FALLBACK_LOCALE;
    }

    /**
     * Whether the given (or current) locale is supported.
     *
     * @param string|null $locale
     *
     * @return bool
     */
    public function isSupported(?string $locale = null): bool
    {
        $locale = $locale ?? $this->getCurrentLocale();

        return $this->validateLocale($locale) !== null;
    }

    /**
     * Whether the current locale is Amharic.
     *
     * @return bool
     */
    public function isAmharic(): bool
    {
        return $this->getCurrentLocale() === 'am';
    }

    /**
     * Whether the current locale is English.
     *
     * @return bool
     */
    public function isEnglish(): bool
    {
        return $this->getCurrentLocale() === 'en';
    }

    // =========================================================================
    // Timezone
    // =========================================================================

    /**
     * Get the current application timezone.
     *
     * @return string
     */
    public function getCurrentTimezone(): string
    {
        return Config::get(
            'app.timezone',
            (string) Config::get('mases.localization.timezone', self::DEFAULT_TIMEZONE)
        );
    }

    /**
     * Set the application timezone (updates config and PHP default).
     *
     * @param string $timezone
     *
     * @return void
     *
     * @throws \Exception If the timezone identifier is invalid.
     */
    public function setTimezone(string $timezone): void
    {
        // Validate first so invalid identifiers never reach the runtime.
        new \DateTimeZone($timezone);

        Config::set('app.timezone', $timezone);
        date_default_timezone_set($timezone);
    }

    // =========================================================================
    // Date Formatting (Gregorian and Ethiopian)
    // =========================================================================

    /**
     * Format a date for display.
     *
     * When `$useEthiopian` is true the output is produced by the
     * EthiopianCalendar engine using the given format tokens (Y, m, d, F, …)
     * and the currently active locale.
     *
     * @param DateTimeInterface|string|int $date
     * @param string                       $format       PHP date format tokens.
     * @param bool                         $useEthiopian Use Ethiopian calendar.
     *
     * @return string
     */
    public function formatDate($date, string $format = 'Y-m-d H:i:s', bool $useEthiopian = false): string
    {
        $dateTime = $this->ensureDateTime($date);
        if ($dateTime === null) {
            return '';
        }

        if ($useEthiopian) {
            return $this->calendar->format(
                $dateTime,
                $format,
                $this->getCurrentLocale()
            );
        }

        return $dateTime->format($format);
    }

    /**
     * Convenience: format an Ethiopian date with full Amharic (or English)
     * month and day names.
     *
     * Example (am): "ሰኞ, ታኅሣሥ 28, 2016 ዓ.ም."
     *
     * @param DateTimeInterface|string|int $date
     * @param string|null                  $locale
     *
     * @return string
     */
    public function formatEthiopianDate($date, ?string $locale = null): string
    {
        $dateTime = $this->ensureDateTime($date);
        if ($dateTime === null) {
            return '';
        }

        $locale = $this->validateLocale($locale ?? $this->getCurrentLocale())
            ?? self::DEFAULT_LOCALE;

        $ethiopian = $this->calendar->toEthiopian($dateTime);
        $weekday = $this->calendar->getWeekday(
            $ethiopian['year'],
            $ethiopian['month'],
            $ethiopian['day']
        );

        return $this->calendar->formatFull(
            $ethiopian['year'],
            $ethiopian['month'],
            $ethiopian['day'],
            $weekday,
            $locale
        );
    }

    /**
     * Format an Ethiopian date in the medium form: month name, day,
     * year, era suffix — no weekday — followed by the Gregorian
     * equivalent in parentheses.
     *
     * Examples:
     *
     *   am:  ሐምሌ 9, 2018 ዓ.ም. (July 16, 2026)
     *   en:  Hamle 9, 2018 E.C. (July 16, 2026)
     *
     * The Ethiopian part uses the current-locale month names from
     * EthiopianCalendar::monthName() — Amharic script for 'am',
     * transliterated Latin for 'en'. The era suffix is 'ዓ.ም.' for
     * 'am' and 'E.C.' for 'en'.
     *
     * The Gregorian part always uses English month names ('F j, Y'),
     * in both locales. This is the convention common in Ethiopian
     * documents that pair an Ethiopian date with a Gregorian
     * reference. See the file-level change-verification block for the
     * note on native-speaker review.
     *
     * Both parts are derived from the same localized instant: the input
     * is first converted to the calendar's display timezone, and both
     * the Ethiopian conversion and the Gregorian format consume that
     * localized value. The two parts therefore always describe the same
     * calendar day, never two days from two different zones.
     *
     * This method is the source of every `_display` field the
     * Shareholder domain's resources emit. It is consumed by
     * `RendersLocalizedDates` (P3 · Unit 2, file 2).
     *
     * @param DateTimeInterface|string|int|null $date
     * @param string|null                       $locale
     *
     * @return string  Empty string for null or unparseable input.
     */
    public function formatEthiopianDateMedium($date, ?string $locale = null): string
    {
        $dateTime = $this->ensureDateTime($date);
        if ($dateTime === null) {
            return '';
        }

        $locale = $this->validateLocale($locale ?? $this->getCurrentLocale())
            ?? self::DEFAULT_LOCALE;

        /*
         * Localize to the calendar's display timezone first. Both the
         * Ethiopian conversion and the Gregorian format below consume
         * this localized instant, so the two parts cannot describe
         * different calendar days.
         */
        $localized = DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone($this->calendar->getTimezone());

        // --- Ethiopian part -------------------------------------------------
        $ethiopian = $this->calendar->toEthiopian($localized);

        $ethiopianPart = sprintf(
            '%s %d, %d %s',
            $this->calendar->monthName($ethiopian['month'], $locale),
            $ethiopian['day'],
            $ethiopian['year'],
            self::ERA_SUFFIX[$locale] ?? self::ERA_SUFFIX[self::DEFAULT_LOCALE]
        );

        // --- Gregorian part -------------------------------------------------
        // English month names in both locales; day without leading zero;
        // four-digit year.
        $gregorianPart = $localized->format('F j, Y');

        return sprintf('%s (%s)', $ethiopianPart, $gregorianPart);
    }

    /**
     * Format a date as Ethiopian local time (12-hour with ጠዋት / ቀን).
     *
     * Example: "3:45 ቀን"
     *
     * @param DateTimeInterface|string|int $date
     *
     * @return string
     */
    public function formatEthiopianTime($date): string
    {
        $dateTime = $this->ensureDateTime($date);
        if ($dateTime === null) {
            return '';
        }

        return $this->calendar->toEthiopianTime($dateTime)['formatted'];
    }

    /**
     * Format a date as an Ethiopian date + time string.
     *
     * Example: "ሰኞ, ታኅሣሥ 28, 2016 ዓ.ም. 3:45 ቀን"
     *
     * @param DateTimeInterface|string|int $date
     * @param string|null                  $locale
     *
     * @return string
     */
    public function formatEthiopianDateTime($date, ?string $locale = null): string
    {
        $dateTime = $this->ensureDateTime($date);
        if ($dateTime === null) {
            return '';
        }

        return $this->formatEthiopianDate($dateTime, $locale)
            . ' '
            . $this->formatEthiopianTime($dateTime);
    }

    // =========================================================================
    // Ethiopian Calendar Accessors
    // =========================================================================

    /**
     * Get the underlying EthiopianCalendar engine.
     *
     * @return EthiopianCalendar
     */
    public function getEthiopianCalendar(): EthiopianCalendar
    {
        return $this->calendar;
    }

    /**
     * Get today's Ethiopian date as [year, month, day].
     *
     * @return array{year: int, month: int, day: int}
     */
    public function getEthiopianToday(): array
    {
        return $this->calendar->today();
    }

    /**
     * Get the current Ethiopian year.
     *
     * @return int
     */
    public function getEthiopianYear(): int
    {
        return $this->getEthiopianToday()['year'];
    }

    /**
     * Get the current Ethiopian month (1–13).
     *
     * @return int
     */
    public function getEthiopianMonth(): int
    {
        return $this->getEthiopianToday()['month'];
    }

    /**
     * Get the current Ethiopian day within the month.
     *
     * @return int
     */
    public function getEthiopianDay(): int
    {
        return $this->getEthiopianToday()['day'];
    }

    /**
     * Get the localized name of the current Ethiopian month.
     *
     * @param string|null $locale
     *
     * @return string
     */
    public function getEthiopianMonthName(?string $locale = null): string
    {
        $locale = $this->validateLocale($locale ?? $this->getCurrentLocale())
            ?? self::DEFAULT_LOCALE;

        return $this->calendar->monthName($this->getEthiopianMonth(), $locale);
    }

    /**
     * Get the localized name of the current Ethiopian weekday.
     *
     * @param string|null $locale
     *
     * @return string
     */
    public function getEthiopianWeekday(?string $locale = null): string
    {
        $today = $this->getEthiopianToday();
        $weekday = $this->calendar->getWeekday(
            $today['year'],
            $today['month'],
            $today['day']
        );

        $locale = $this->validateLocale($locale ?? $this->getCurrentLocale())
            ?? self::DEFAULT_LOCALE;

        return $this->calendar->dayName($weekday, $locale);
    }

    /**
     * Get the Amharic time-of-day phrase for the current moment.
     *
     * Examples: ከጠዋቱ, ከሰዓት, ምሽቱ, እኩለ ሌሊት.
     *
     * @param DateTimeInterface|string|int|null $date
     *
     * @return string
     */
    public function timeOfDayPhrase($date = null): string
    {
        $dateTime = $date !== null
            ? $this->ensureDateTime($date)
            : new DateTimeImmutable('now', $this->calendar->getTimezone());

        if ($dateTime === null) {
            return '';
        }

        $localized = DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone($this->calendar->getTimezone());

        return $this->calendar->timeOfDayPhrase(
            (int) $localized->format('H'),
            (int) $localized->format('i')
        );
    }

    // =========================================================================
    // Number Formatting
    // =========================================================================

    /**
     * Format a number using standard Arabic numerals (as used in Ethiopia).
     *
     * @param float|int $number
     * @param int       $decimalPlaces
     *
     * @return string
     */
    public function formatNumber(float|int $number, int $decimalPlaces = 0): string
    {
        return number_format($number, $decimalPlaces);
    }

    // =========================================================================
    // Currency Formatting
    // =========================================================================

    /**
     * Format a currency amount.
     *
     * Ethiopian Birr (ETB) is the default currency. The symbol is placed
     * before the amount for ETB, USD, and EUR; unsupported currencies fall
     * back to "amount CODE".
     *
     * @param float  $amount
     * @param string $currency      'ETB' (default), 'USD', 'EUR'
     * @param bool   $includeSymbol If false, the ISO code is used instead.
     *
     * @return string
     */
    public function formatCurrency(
        float $amount,
        string $currency = 'ETB',
        bool $includeSymbol = true
    ): string {
        $formatted = $this->formatNumber($amount, 2);
        $currency = strtoupper($currency);

        $symbols = [
            'ETB' => 'ብር',
            'USD' => '$',
            'EUR' => '€',
        ];

        if ($includeSymbol && isset($symbols[$currency])) {
            return $symbols[$currency] . ' ' . $formatted;
        }

        return $formatted . ' ' . $currency;
    }

    // =========================================================================
    // Translation
    // =========================================================================

    /**
     * Translate a string using the given (or current) locale.
     *
     * The current locale already defaults to Amharic, so this method is
     * Amharic-first by construction. When a translation is missing in the
     * current locale, Laravel falls back to `fallback_locale` (English).
     *
     * @param string               $key
     * @param array<string, mixed> $replacements
     * @param string|null          $locale
     *
     * @return string
     */
    public function translate(
        string $key,
        array $replacements = [],
        ?string $locale = null
    ): string {
        $locale = $this->validateLocale($locale ?? $this->getCurrentLocale())
            ?? self::DEFAULT_LOCALE;

        return __($key, $replacements, $locale);
    }

    // =========================================================================
    // Text Direction
    // =========================================================================

    /**
     * Get the text direction for the current locale.
     *
     * Both Amharic and English are LTR, so this always returns 'ltr'.
     * The method exists to make adding an RTL locale a one-line change.
     *
     * @return string 'ltr' or 'rtl'
     */
    public function getDirection(): string
    {
        return 'ltr';
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Parse the Accept-Language header and return the best supported locale.
     *
     * Amharic-first tiebreak: when two locales share the same quality
     * value, the one with the lower `LOCALE_PRIORITY` index wins — i.e.
     * Amharic beats English on equal q.
     *
     * @return string|null
     */
    private function detectFromHeader(): ?string
    {
        $header = Request::header(self::ACCEPT_LANGUAGE_HEADER);
        if (empty($header) || !is_string($header)) {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';q=', $part, 2);
            $rawLocale = trim($segments[0]);
            $quality = isset($segments[1]) ? (float) $segments[1] : 1.0;

            $validated = $this->validateLocale($rawLocale);
            if ($validated === null) {
                continue;
            }

            $candidates[] = [
                'locale'   => $validated,
                'quality'  => $quality,
                'priority' => self::LOCALE_PRIORITY[$validated] ?? PHP_INT_MAX,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        // Sort by quality DESC, then by Amharic-first priority ASC.
        usort($candidates, static function (array $a, array $b): int {
            if ($a['quality'] !== $b['quality']) {
                return $b['quality'] <=> $a['quality'];
            }

            return $a['priority'] <=> $b['priority'];
        });

        return $candidates[0]['locale'];
    }

    /**
     * Validate and normalize a locale identifier.
     *
     * Accepts exact matches ('am', 'en') and locale-with-region strings
     * ('am-ET', 'en-US'). Returns null for unsupported locales.
     *
     * @param string $locale
     *
     * @return string|null
     */
    private function validateLocale(string $locale): ?string
    {
        $normalized = strtolower(trim($locale));

        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, self::SUPPORTED_LOCALES)) {
            return $normalized;
        }

        $primary = explode('-', $normalized)[0];
        if (array_key_exists($primary, self::SUPPORTED_LOCALES)) {
            return $primary;
        }

        return null;
    }

    /**
     * Convert various date inputs to a DateTimeInterface.
     *
     * @param DateTimeInterface|string|int|null $date
     *
     * @return DateTimeInterface|null
     */
    private function ensureDateTime($date): ?DateTimeInterface
    {
        if ($date instanceof DateTimeInterface) {
            return $date;
        }

        if (is_int($date)) {
            $dateTime = new DateTimeImmutable();
            return $dateTime->setTimestamp($date);
        }

        if (is_string($date) && $date !== '') {
            try {
                return new DateTimeImmutable($date);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}