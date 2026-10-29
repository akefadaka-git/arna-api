<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Organization\\Providers;

use Illuminate\\Support\\Facades\\Route;
use Illuminate\\Support\\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'organization';
    private const CONFIG_PATH = __DIR__ . '/../Config/organization.php';
    private const MIGRATIONS_PATH = __DIR__ . '/../Database/Migrations';
    private const ROUTES_PATH = __DIR__ . '/../Routes/api.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);
    }

    public function boot(): void
    {
        if (! (bool) config('organization.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
        $this->loadOrganizationRoutes();
    }

    private function loadOrganizationRoutes(): void
    {
        if (! is_file(self::ROUTES_PATH)) {
            return;
        }

        Route::middleware((array) config('organization.routes.middleware', ['api', 'auth:sanctum', 'tenant.membership']))
            ->prefix((string) config('organization.routes.prefix', 'api/organization'))
            ->group(static function (): void {
                require self::ROUTES_PATH;
            });
    }
}
