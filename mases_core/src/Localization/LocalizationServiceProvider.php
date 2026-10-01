<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use MaaleArna\MASES\Core\Localization\EthiopianCalendar;
use MaaleArna\MASES\Core\Localization\EthiopianFormatter;
use MaaleArna\MASES\Core\Localization\LocaleManager;
use MaaleArna\MASES\Core\Localization\LocalizationManager;

final class LocalizationServiceProvider extends ServiceProvider
{
    /**
     * Register localization services in the container.
     */
    public function register(): void
    {
        // Merge localization config if not already merged by MasesCoreServiceProvider
        // We assume MasesCoreServiceProvider already merges it, but we can ensure it here too.
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/localization.php',
            'mases_localization'
        );

        // Register EthiopianCalendar as a singleton
        $this->app->singleton(EthiopianCalendar::class, function (Application $app) {
            return new EthiopianCalendar(config('mases_localization', []));
        });

        // Register EthiopianFormatter as a singleton (depends on calendar)
        $this->app->singleton(EthiopianFormatter::class, function (Application $app) {
            return new EthiopianFormatter($app->make(EthiopianCalendar::class));
        });

        // Register LocaleManager as a singleton
        $this->app->singleton(LocaleManager::class, function (Application $app) {
            return new LocaleManager();
        });

        // Register LocalizationManager as the primary facade (depends on all above)
        $this->app->singleton(LocalizationManager::class, function (Application $app) {
            return new LocalizationManager(
                $app->make(EthiopianCalendar::class),
                $app->make(EthiopianFormatter::class),
                $app->make(LocaleManager::class)
            );
        });

        // Register an alias for easy facade-style access (optional)
        $this->app->alias(LocalizationManager::class, 'mases.localization');
    }

    /**
     * Bootstrap localization services.
     */
    public function boot(): void
    {
        // Register a request lifecycle hook to detect and apply locale
        // We'll use a middleware approach; for simplicity, we can apply it in the provider's boot.
        $this->app->booted(function () {
            $this->applyLocaleFromRequest();
        });
    }

    /**
     * Apply the locale from the current request.
     * This runs once on boot, but for each request we need a middleware.
     * To properly handle per-request locale, we should register a middleware in the kernel.
     * We'll provide a method to get the middleware class later.
     */
    protected function applyLocaleFromRequest(): void
    {
        // Since this runs only once on boot, we need actual per-request handling via middleware.
        // We'll provide the middleware in a separate file.
        // But we can also use a Laravel `booted` event with a request lifecycle.
        // For now, we'll just log that the localization service is available.
        // The actual per-request locale application will be handled by the LocaleMiddleware
        // that we'll generate next.
    }

    /**
     * Get the services provided by this provider.
     */
    public function provides(): array
    {
        return [
            EthiopianCalendar::class,
            EthiopianFormatter::class,
            LocaleManager::class,
            LocalizationManager::class,
            'mases.localization',
        ];
    }
}