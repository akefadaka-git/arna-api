<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use MaaleArna\MASES\Core\Localization\LocaleManager;

final class LocaleMiddleware
{
    private LocaleManager $localeManager;

    public function __construct(LocaleManager $localeManager)
    {
        $this->localeManager = $localeManager;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Get session from the request if available
        $session = $request->getSession();

        // Detect and apply the locale
        $this->localeManager->applyLocale($request, $session);

        // Store the locale in a cookie for future visits (optional)
        $locale = $this->localeManager->getCurrentLocale();
        $response = $next($request);

        // Optionally set a cookie with the current locale (1 year expiry)
        if ($session && $locale !== $this->localeManager->getDefaultLocale()) {
            $cookie = cookie(
                LocaleManager::COOKIE_KEY,
                $locale,
                60 * 24 * 365, // 1 year in minutes
                '/',
                null,
                false,
                false
            );
            $response->withCookie($cookie);
        }

        return $response;
    }
}