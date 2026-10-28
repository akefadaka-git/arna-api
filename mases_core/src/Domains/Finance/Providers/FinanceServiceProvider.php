<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Providers;

use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Finance\Repositories\LegalEntityRepository;
use MaaleArna\MASES\Core\Domains\Finance\Services\LegalEntityService;

/**
 * Finance domain integration point.
 *
 * Registers the Finance translation namespace and domain services. Finance
 * routes and migrations remain centrally registered in this foundation slice;
 * moving them is a separate migration-safe restructuring step.
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LegalEntityRepository::class);
        $this->app->singleton(LegalEntityService::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'finance');
    }
}
