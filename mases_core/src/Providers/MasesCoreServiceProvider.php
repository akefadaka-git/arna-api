<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Providers;

use Illuminate\Support\ServiceProvider;

final class MasesCoreServiceProvider extends ServiceProvider
{
    /**
     * Register any Core services.
     */
    public function register(): void
    {
        // Merge the primary Core configuration file
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/mases.php',
            'mases'
        );

        // Merge the localization configuration file under its own key
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/localization.php',
            'mases_localization'
        );

        // Additional service bindings will be added in later phases
        // Example: $this->app->singleton(TenantManager::class, TenantManager::class);
    }

    /**
     * Bootstrap any Core services.
     */
    public function boot(): void
    {
        // Load all Core migrations from the central directories
        $this->loadMigrationsFrom([
            __DIR__ . '/../Migrations/Platform',
            __DIR__ . '/../Migrations/Shareholder',
            __DIR__ . '/../Migrations/Finance',
            __DIR__ . '/../Migrations/Hr',
            __DIR__ . '/../Migrations/Arna',
            __DIR__ . '/../Migrations/Ai',
            __DIR__ . '/../Migrations/File',
        ]);

        // Load translations (optional – we can publish them later)
        $this->loadTranslationsFrom(
            __DIR__ . '/../../resources/lang',
            'mases'
        );

        // Load error views (for localized error pages)
        $this->loadViewsFrom(
            __DIR__ . '/../../views/errors',
            'mases'
        );

        // Publish configuration files
        $this->publishes([
            __DIR__ . '/../../config/mases.php' => config_path('mases.php'),
            __DIR__ . '/../../config/localization.php' => config_path('localization.php'),
        ], 'mases-config');

        // Publish translations
        $this->publishes([
            __DIR__ . '/../../resources/lang' => resource_path('lang/vendor/mases'),
        ], 'mases-translations');

        // Publish error views
        $this->publishes([
            __DIR__ . '/../../views/errors' => resource_path('views/vendor/mases'),
        ], 'mases-views');

        // Publish migrations (if needed for customization, but we keep them central)
        $this->publishes([
            __DIR__ . '/../Migrations' => database_path('migrations/mases'),
        ], 'mases-migrations');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            // Future bindings will be listed here
        ];
    }
}