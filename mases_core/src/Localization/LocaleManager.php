<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\App;

final class LocaleManager
{
    public const SESSION_KEY = 'mases_locale';
    public const COOKIE_KEY = 'mases_locale';

    private array $availableLocales;
    private string $defaultLocale;
    private string $fallbackLocale;
    private ?string $currentLocale = null;

    public function __construct()
    {
        $config = config('mases_localization', []);
        $this->availableLocales = array_keys($config['locales'] ?? ['am' => 'am_ET', 'en' => 'en_US']);
        $this->defaultLocale = $config['default_locale'] ?? 'am';
        $this->fallbackLocale = $config['fallback_locale'] ?? 'en';
    }

    /**
     * Get the currently active locale.
     */
    public function getCurrentLocale(): string
    {
        if ($this->currentLocale !== null) {
            return $this->currentLocale;
        }
        // Fallback to application locale if set via Laravel
        $appLocale = App::getLocale();
        if ($appLocale && in_array($appLocale, $this->availableLocales, true)) {
            return $appLocale;
        }
        return $this->defaultLocale;
    }

    /**
     * Set the current locale for the session and application.
     */
    public function setLocale(string $locale, ?Store $session = null): void
    {
        if (!in_array($locale, $this->availableLocales, true)) {
            $locale = $this->fallbackLocale;
        }
        $this->currentLocale = $locale;
        App::setLocale($locale);
        if ($session) {
            $session->put(self::SESSION_KEY, $locale);
        }
    }

    /**
     * Detect locale from the current request.
     * Priority: 1) Request parameter, 2) Session, 3) Cookie, 4) Accept-Language header, 5) Default.
     */
    public function detectLocale(Request $request, ?Store $session = null): string
    {
        // 1. Request parameter
        $param = $request->query('locale');
        if ($param && in_array($param, $this->availableLocales, true)) {
            return $param;
        }

        // 2. Session
        if ($session && $session->has(self::SESSION_KEY)) {
            $sessionLocale = $session->get(self::SESSION_KEY);
            if (in_array($sessionLocale, $this->availableLocales, true)) {
                return $sessionLocale;
            }
        }

        // 3. Cookie
        $cookie = $request->cookie(self::COOKIE_KEY);
        if ($cookie && in_array($cookie, $this->availableLocales, true)) {
            return $cookie;
        }

        // 4. Accept-Language header
        $acceptLanguage = $request->getPreferredLanguage($this->availableLocales);
        if ($acceptLanguage) {
            return $acceptLanguage;
        }

        // 5. Default
        return $this->defaultLocale;
    }

    /**
     * Apply locale detection to the current request and set application locale.
     */
    public function applyLocale(Request $request, ?Store $session = null): void
    {
        $locale = $this->detectLocale($request, $session);
        $this->setLocale($locale, $session);
    }

    /**
     * Get all available locales.
     */
    public function getAvailableLocales(): array
    {
        return $this->availableLocales;
    }

    /**
     * Check if a locale is supported.
     */
    public function isSupported(string $locale): bool
    {
        return in_array($locale, $this->availableLocales, true);
    }

    /**
     * Get the default locale.
     */
    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Get the fallback locale.
     */
    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }
}