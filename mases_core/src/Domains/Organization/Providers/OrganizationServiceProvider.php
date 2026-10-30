<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Organization\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationAssignment;
use MaaleArna\MASES\Core\Domains\Organization\Models\OrganizationUnit;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationAssignmentPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Policies\OrganizationUnitPolicy;
use MaaleArna\MASES\Core\Domains\Organization\Services\OrganizationNumberingRegistryService;

final class OrganizationServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../Config/organization.php';

    public function register(): void
    {
        $this->app->singleton(\\MaaleArna\\MASES\\Core\\Domains\\Organization\\Services\\OrganizationNumberingRegistryService::class);
        $this->mergeConfigFrom(self::CONFIG_PATH, 'organization');
        $this->app->singleton(OrganizationNumberingRegistryService::class);
    }

    public function boot(): void
    {
        Gate::policy(OrganizationUnit::class, OrganizationUnitPolicy::class);
        Gate::policy(OrganizationAssignment::class, OrganizationAssignmentPolicy::class);

        if (!config('organization.enabled', true)) {
            return;
        }

        $migrationPath = config('organization.database.migrations');
        if (is_string($migrationPath) && is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }

        $routePath = __DIR__.'/../Routes/api.php';
        if (!is_file($routePath)) {
            return;
        }

        Route::middleware(config('organization.routes.middleware', ['api', 'auth:sanctum', 'tenant.membership']))
            ->prefix(config('organization.routes.prefix', 'api/organization'))
            ->name(config('organization.routes.name', 'api.organization.'))
            ->group(static function () use ($routePath): void {
                require $routePath;
            });
    }
}
