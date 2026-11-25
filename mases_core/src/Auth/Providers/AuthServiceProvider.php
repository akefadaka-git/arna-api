<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

/**
 * Registers Gate::before for raw ability strings through AuthorizationService; model-aware abilities continue through domain policies.
 *
 * @package MaaleArna\MASES\Core\Auth\Providers
 * @version 1.0.0
 */
final class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * No container bindings are registered here. The platform's
     * `AuthorizationService` binding is owned by `CoreServiceProvider`;
     * this provider consumes it via lazy resolution inside the gate
     * callback.
     *
     * @return void
     */
    public function register(): void
    {
        // Nothing to register.
    }

    /**
     * Bootstrap any application services.
     *
     * Registers the platform's global permission gate — the
     * `Gate::before` callback that resolves raw ability strings through
     * the `AuthorizationService`.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->registerPermissionGate();
    }

    // =========================================================================
    // Private — gate registration
    // =========================================================================

    /**
     * Register the platform's global permission gate.
     *
     * The callback's behavior is documented in the class docblock
     * §"The `Gate::before` contract". In brief:
     *
     *   · Unauthenticated user         → no opinion (fall through).
     *   · Model-aware ability          → no opinion (policy resolves it).
     *   · Raw ability, permission held → allow.
     *   · Raw ability, permission not held → no opinion (Laravel's
     *     default deny applies, unless an explicit `Gate::define` for
     *     the ability is registered).
     *
     * @return void
     */
    private function registerPermissionGate(): void
    {
        Gate::before(
            /**
             * @param User|null    $user
             * @param string       $ability
             * @param array<mixed> $arguments
             * @return bool|null
             */
            static function (
                ?User $user,
                string $ability,
                array $arguments = [],
            ): ?bool {
                // -----------------------------------------------------------------
                // 1. Unauthenticated. Defer.
                // -----------------------------------------------------------------
                if ($user === null) {
                    return null;
                }

                // -----------------------------------------------------------------
                // 2. Model-aware ability. Defer to the policy mechanism.
                //
                //    A raw permission check has no model argument. Any
                //    argument — a model instance, a model class name — is
                //    a signal that a policy mediates the ability.
                // -----------------------------------------------------------------
                if ($arguments !== []) {
                    return null;
                }

                // -----------------------------------------------------------------
                // 3. Raw ability. Resolve through the platform's
                //    permission service.
                //
                //    The service is resolved lazily so that its constructor
                //    cost — and the cost of its cache repository binding —
                //    is paid only when a raw ability check is actually
                //    performed. See the class docblock §"Where the callback
                //    resolves the AuthorizationService".
                // -----------------------------------------------------------------
                /** @var AuthorizationService $authorization */
                $authorization = app(AuthorizationService::class);

                if ($authorization->hasPermission($ability, $user)) {
                    return true;
                }

                // -----------------------------------------------------------------
                // 4. Permission not granted. Defer.
                //
                //    Returning null (rather than false) leaves the door
                //    open for an explicit `Gate::define($ability, ...)`
                //    registration and falls through to Laravel's default
                //    deny when none exists. This keeps the callback
                //    ADDITIVE — it can only grant access, never deny it.
                // -----------------------------------------------------------------
                return null;
            },
        );
    }
}