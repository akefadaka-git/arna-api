<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Localization\Services\LocalizationService;

/**
 * LocalizationMiddleware
 *
 * Middleware that detects and sets the active locale for each request.
 *
 * This middleware should be placed after the TenantContextMiddleware in the
 * middleware stack to ensure tenant context is available, but before any
 * business logic that might require localization.
 *
 * The locale is resolved using the LocalizationService, which checks:
 * 1. Authenticated user's stored locale preference.
 * 2. Session locale.
 * 3. Cookie locale.
 * 4. Accept-Language header.
 * 5. Default locale ('am').
 *
 * @package MaaleArna\MASES\Core\Localization\Middleware
 */
final class LocalizationMiddleware
{
    /**
     * The localization service instance.
     *
     * @var LocalizationService
     */
    private LocalizationService $localizationService;

    /**
     * Create a new LocalizationMiddleware instance.
     *
     * @param LocalizationService $localizationService
     */
    public function __construct(LocalizationService $localizationService)
    {
        $this->localizationService = $localizationService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Resolve the current locale using the service.
        $locale = $this->localizationService->getCurrentLocale();

        // Set the locale for the request.
        // The setLocale method will persist to session/cookie/user if appropriate.
        $this->localizationService->setLocale($locale);

        // Log the locale for debugging.
        Log::debug('Locale set', [
            'locale' => $locale,
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
        ]);

        // Continue the request.
        return $next($request);
    }
}