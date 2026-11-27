<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MaaleArna\Arna\Core\Localization\EthiopianFormatter;
use MaaleArna\Arna\Core\Localization\LocaleManager;
use MaaleArna\Arna\Core\Localization\LocalizationManager;

/**
 * LocalizationServiceProvider
 *
 * Binds the secondary-cluster localization services that
 * CoreServiceProvider does not own:
 *
 *   - EthiopianFormatter
 *   - LocaleManager
 *   - LocalizationManager
 *   - the 'arna.localization' container alias
 *
 * -----------------------------------------------------------------------------
 * Status
 * -----------------------------------------------------------------------------
 * The registration of this provider in bootstrap/providers.php was
 * removed in P1-G2.2 because the class was not resolvable under the
 * installed Composer autoloader. The PSR-4-mapped file for
 * MaaleArna\Arna\Core\Providers\LocalizationServiceProvider is
 * arna_core/src/Providers/LocalizationServiceProvider.php, which
 * does not exist at the current HEAD. The class body currently lives
 * at arna_core/src/Localization/LocalizationServiceProvider.php.
 *
 * This class is a **proposal target** for P1-G2.4 (Secondary
 * Localization Migration / Deprecation). Its continued existence is
 * contingent on the outcome of that gate. Its useful classes
 * (EthiopianFormatter, LocaleManager, LocalizationManager) may be
 * migrated into the canonical architecture before this provider is
 * retired.
 *
 * -----------------------------------------------------------------------------
 * Non-responsibilities
 * -----------------------------------------------------------------------------
 * This provider does NOT bind EthiopianCalendar. The canonical
 * calendar (MaaleArna\Arna\Core\Localization\Calendar\EthiopianCalendar)
 * is bound by CoreServiceProvider and is the configured instance
 * for the platform.
 *
 * This provider does NOT merge configuration. The canonical
 * localization configuration is delivered under arna.localization.*
 * by CoreServiceProvider's merge of arna_core/src/Config/arna.php.
 *
 * This provider does NOT install per-request locale application.
 * That responsibility belongs to
 * MaaleArna\Arna\Core\Localization\Middleware\LocalizationMiddleware.
 *
 * @package MaaleArna\Arna\Core\Providers
 *
 * @deprecated 0.x Pending P1-G2.4 classification. Do not extend or
 *             rely on this provider until its scope is confirmed.
 */
final class LocalizationServiceProvider extends ServiceProvider
{
    /**
     * Register the secondary-cluster localization services.
     *
     * Each class resolves with its own inline defaults. No calendar
     * is injected — the classes that need one accept an optional
     * instance and fall back to a default internally. Whether this
     * fallback is replaced by the canonical calendar is a P1-G2.4
     * decision.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(
            EthiopianFormatter::class,
            static fn (Application $app): EthiopianFormatter => new EthiopianFormatter()
        );

        $this->app->singleton(
            LocaleManager::class,
            static fn (Application $app): LocaleManager => new LocaleManager()
        );

        $this->app->singleton(
            LocalizationManager::class,
            static fn (Application $app): LocalizationManager => new LocalizationManager(
                null,
                $app->make(EthiopianFormatter::class),
                $app->make(LocaleManager::class)
            )
        );

        $this->app->alias(LocalizationManager::class, 'arna.localization');
    }

    /**
     * Get the services provided by this provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            EthiopianFormatter::class,
            LocaleManager::class,
            LocalizationManager::class,
            'arna.localization',
        ];
    }
}