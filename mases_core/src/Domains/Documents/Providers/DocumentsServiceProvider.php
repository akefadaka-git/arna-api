<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Documents\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentAuthorityService;
use MaaleArna\MASES\Core\Domains\Documents\Services\DocumentIssuanceService;
use MaaleArna\MASES\Core\Domains\Documents\Services\IncomingDocumentRegistrationService;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentAuthorityService::class);
        $this->app->singleton(DocumentIssuanceService::class);
        $this->app->singleton(IncomingDocumentRegistrationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $routesPath = __DIR__ . '/../Routes/api.php';
        if (!is_file($routesPath)) {
            return;
        }

        Route::middleware(['api', 'auth:sanctum', 'tenant.membership'])
            ->prefix('api')
            ->group($routesPath);
    }
}
