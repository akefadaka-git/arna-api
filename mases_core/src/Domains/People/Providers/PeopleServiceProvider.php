<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\People\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Domains\People\Contracts\PersonProfileRepositoryContract;
use MaaleArna\MASES\Core\Domains\People\Events\PersonProfileLifecycleChanged;
use MaaleArna\MASES\Core\Domains\People\Listeners\AuditPersonProfileLifecycle;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Policies\PersonPolicy;
use MaaleArna\MASES\Core\Domains\People\Repositories\EloquentPersonProfileRepository;

final class PeopleServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'people';
    private const CONFIG_PATH = __DIR__ . '/../Config/people.php';
    private const MIGRATIONS_PATH = __DIR__ . '/../Database/Migrations';
    private const ROUTES_PATH = __DIR__ . '/../Routes/api.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, self::CONFIG_KEY);
        $this->app->singleton(PersonProfileRepositoryContract::class, EloquentPersonProfileRepository::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'people');
        Gate::policy(Person::class, PersonPolicy::class);
        Event::listen(PersonProfileLifecycleChanged::class, AuditPersonProfileLifecycle::class);

        if (! (bool) config('people.enabled', true)) {
            return;
        }

        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);
        $this->loadPeopleRoutes();
    }

    private function loadPeopleRoutes(): void
    {
        if (! is_file(self::ROUTES_PATH)) {
            return;
        }

        Route::middleware((array) config('people.routes.middleware', ['api', 'auth:sanctum', 'tenant.membership']))
            ->prefix((string) config('people.routes.prefix', 'api/people'))
            ->group(static function (): void {
                require self::ROUTES_PATH;
            });
    }
}
