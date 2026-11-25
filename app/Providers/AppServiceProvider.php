<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use MaaleArna\MASES\Core\Domains\People\Models\Person;
use MaaleArna\MASES\Core\Domains\People\Policies\PersonPolicy;
use MaaleArna\MASES\Core\Domains\Task\Models\Task;
use MaaleArna\MASES\Core\Domains\Task\Policies\TaskPolicy;
use Illuminate\Support\ServiceProvider;

/** Registers application policies and API rate limiting. */
class AppServiceProvider extends ServiceProvider
{
 
    public function register(): void
    {
        //
    }

    /** Registers the API limiter and applies its middleware. */
    public function boot(): void
    {
        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);

        // Limit by authenticated user, or source IP for guests.
        RateLimiter::for('api', function (Request $request) {
            $perMinute = (int) config('mases.security.rate_limiting', 60);

            return Limit::perMinute($perMinute)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Apply throttling after the API middleware group is registered.
        $this->app->booted(function (): void {
            $this->app->make(Router::class)
                ->pushMiddlewareToGroup('api', 'throttle:api');
        });
    }
}