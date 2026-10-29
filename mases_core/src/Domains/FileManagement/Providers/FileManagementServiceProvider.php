<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\FileManagement\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Domains\FileManagement\Services\FileService;

/**
 * Registers the FileManagement domain's service and tenant-scoped API routes.
 *
 * The existing API prefix, route names, Sanctum authentication, and tenant
 * membership requirements are preserved. File-specific behavior belongs to
 * this domain; shared audit, authorization, and tenancy services remain Core
 * platform dependencies.
 */
final class FileManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            FileService::class,
            static fn ($app): FileService => new FileService(
                $app->make(AuditService::class),
                $app->make(AuthorizationService::class)
            )
        );
    }

    public function boot(): void
    {
        // File schema is owned by this domain. The migration filename remains
        // unchanged so already-migrated installations do not rerun it.
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $routesPath = __DIR__ . '/../Routes/api.php';

        if (is_file($routesPath)) {
            Route::middleware(['api', 'auth:sanctum', 'tenant.membership'])
                ->prefix('api')
                ->group($routesPath);
        }

        // Public file access is deliberately separate from authenticated,
        // tenant-member routes. The controller still enforces is_public and
        // published state; a route being public never makes a private file public.
        $publicRoutesPath = __DIR__ . '/../Routes/public.php';
        if (is_file($publicRoutesPath)) {
            Route::middleware(['api'])
                ->prefix('api')
                ->group($publicRoutesPath);
        }
    }
}
