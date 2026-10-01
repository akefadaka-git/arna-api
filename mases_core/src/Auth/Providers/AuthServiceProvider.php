<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Auth\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

/**
 * AuthServiceProvider
 *
 * Registers the platform's global authorization gate: a `Gate::before`
 * callback that resolves RAW ability strings — abilities dispatched
 * without a model argument, such as `Gate::authorize('loan.apply')` —
 * through the Core `AuthorizationService`.
 *
 * =============================================================================
 * Why this provider exists
 * =============================================================================
 * The platform's authorization surface has two distinct shapes:
 *
 *   1. MODEL-AWARE abilities — dispatched with a model instance or a
 *      model class name as their second argument:
 *
 *          Gate::authorize('view', $loan);
 *          Gate::authorize('viewAny', Loan::class);
 *          Gate::authorize('approve', $loan);
 *
 *      These resolve through Laravel's policy mechanism. Each domain's
 *      service provider registers its policies via `Gate::policy(...)`;
 *      the platform's existing domain providers already do this (e.g.
 *      `LoanServiceProvider` registers `LoanPolicy`, `LoanProductPolicy`,
 *      and `LoanCollateralPolicy`).
 *
 *   2. RAW abilities — dispatched without a second argument, as a
 *      PERMISSION-ONLY check:
 *
 *          Gate::authorize('loan.apply');
 *
 *      These have no model and therefore no policy. They express "does
 *      this user hold this permission in this tenant?" — the exact
 *      question `AuthorizationService::hasPermission()` answers.
 *
 * Without a handler for (2), Laravel's Gate finds no definition for the
 * raw ability, denies by default, and `Gate::authorize()` throws
 * `AccessDeniedHttpException`. The platform's exception renderer maps
 * that to the canonical `AUTH_PERMISSION_DENIED` envelope. The
 * observable symptom is a 403 for a caller who demonstrably holds the
 * permission in the database.
 *
 * This provider closes that gap: it registers a single `Gate::before`
 * callback that consults `AuthorizationService::hasPermission()` for
 * every raw ability check.
 *
 * =============================================================================
 * The `Gate::before` contract
 * =============================================================================
 * Laravel invokes `Gate::before` before any ability-specific handler.
 * Its return value has three meanings:
 *
 *   · `true`  — allow, and short-circuit further checks.
 *   · `false` — deny, and short-circuit further checks.
 *   · `null`  — no opinion; continue to ability-specific handlers, or
 *               fall through to Laravel's default (deny, if no other
 *               handler exists).
 *
 * The callback registered by this provider is:
 *
 *   ┌─────────────────────────────────────────────────────────────────┐
 *   │ 1. If the user is not authenticated, return null — defer to    │
 *   │    the default handler chain.                                   │
 *   │                                                                 │
 *   │ 2. If the ability was dispatched with arguments (a model        │
 *   │    instance or class name), return null — this is a policy-     │
 *   │    mediated check and belongs to the policy mechanism, not to   │
 *   │    the permission service.                                      │
 *   │                                                                 │
 *   │ 3. Otherwise (a raw ability string), consult                   │
 *   │    `AuthorizationService::hasPermission()`.                     │
 *   │                                                                 │
 *   │      · If the permission is granted, return true.               │
 *   │      · If not, return null — fall through so any explicit      │
 *   │        `Gate::define(...)` for this ability (if one is ever     │
 *   │        registered) still resolves, and Laravel's default       │
 *   │        deny applies otherwise.                                  │
 *   └─────────────────────────────────────────────────────────────────┘
 *
 * The callback is deliberately ADDITIVE, not RESTRICTIVE. It grants
 * access when `hasPermission()` returns true; it does not deny when
 * `hasPermission()` returns false. Denial is left to Laravel's default
 * behavior and to any explicit `Gate::define`. This makes the callback
 * safe to install alongside existing and future policy registrations:
 * a `Gate::define('something', ...)` that a future CR adds will still
 * resolve for callers that do not hold a same-named permission.
 *
 * =============================================================================
 * Where the callback resolves the AuthorizationService
 * =============================================================================
 * The callback resolves the service lazily, inside the closure, via
 * `app(AuthorizationService::class)`. The service is bound as a
 * singleton by `CoreServiceProvider::registerCoreBindings()`.
 *
 * Resolving lazily — rather than injecting the service into the
 * provider's constructor — matters because:
 *
 *   · The provider may be booted before any authorization check is
 *     performed. Resolving the service at boot time would impose its
 *     constructor cost on every request, including those that never
 *     perform a gate check.
 *
 *   · The service's constructor accepts an optional cache repository,
 *     which resolves through the container. Lazy resolution lets the
 *     container resolve that dependency at the moment it is needed,
 *     after the application is fully bootstrapped.
 *
 * =============================================================================
 * Interaction with domain policies
 * =============================================================================
 * The callback is a strict no-op for model-aware abilities. Its second
 * guard (`$arguments !== []`) ensures that a policy-mediated check like
 * `Gate::authorize('view', $loan)` never reaches the permission service.
 * The consequence:
 *
 *   · `Gate::authorize('view', $loan)` resolves through `LoanPolicy::view()`
 *     — the delegation chain the Loan domain already documents.
 *
 *   · `Gate::authorize('loan.apply')` resolves through this provider's
 *     callback → `AuthorizationService::hasPermission('loan.apply', $user)`.
 *
 * The two paths do not overlap and do not interfere.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 * This provider is registered in `bootstrap/providers.php`, ordered
 * immediately after `CoreServiceProvider`. It must run AFTER Core so
 * that the `AuthorizationService` binding is available, and BEFORE
 * every domain provider so that the global gate is installed before any
 * domain code is booted.
 *
 * Provider order in `bootstrap/providers.php`:
 *
 *   1. App\Providers\AppServiceProvider
 *   2. MaaleArna\MASES\Core\Providers\CoreServiceProvider
 *   3. MaaleArna\MASES\Core\Auth\Providers\AuthServiceProvider   ← this file
 *   4. MaaleArna\MASES\Core\Domains\Shareholder\Providers\ShareholderServiceProvider
 *   5. MaaleArna\MASES\Core\Policy\Providers\PolicyServiceProvider
 *   6. MaaleArna\MASES\Core\Domains\Wallet\Providers\WalletServiceProvider
 *   7. MaaleArna\MASES\Core\Domains\Loan\Providers\LoanServiceProvider
 *
 * After this file is registered, `php artisan optimize:clear` (or, if
 * config caching is enabled, `php artisan config:cache`) must be run on
 * the server to pick up the new provider list from
 * `bootstrap/providers.php`.
 *
 * =============================================================================
 * What this provider deliberately does NOT do
 * =============================================================================
 *   · It does NOT define a `Gate::define()` for each permission slug.
 *     Doing so would couple the provider to the 60+ item permission
 *     vocabulary, require a rebuild on every vocabulary change, and
 *     duplicate the `AuthorizationService`'s own single source of
 *     truth. A single `Gate::before` callback is the correct mechanism:
 *     the ability NAME is the permission SLUG, resolved at request time
 *     by the service.
 *
 *   · It does NOT register any policies. Each domain's service provider
 *     registers its own policies (the Shareholder, Wallet, and Loan
 *     domain providers already do). This provider is the platform's
 *     CAPABILITY, not a DOMAIN.
 *
 *   · It does NOT install a `Gate::after` callback. The `after` hook is
 *     for observation, not decision — the platform's
 *     `AuthorizationFailureEvent` and its listener cover the
 *     observation need (see `CoreServiceProvider`).
 *
 *   · It does NOT check `hasRole()` or any role-based shortcut. Role
 *     membership is a mechanism of the RBAC matrix; the matrix's
 *     effects are already reflected in the permissions a user holds.
 *     A raw ability is a permission question, not a role question.
 *
 *   · It does NOT consult any application-scoped context
 *     (`$applicationSlug`). The `AuthorizationService::hasPermission()`
 *     call is made without the fourth argument, which the service
 *     treats as "no application scoping". This is the correct default
 *     for the raw-ability path: an ability slug like `loan.apply` has
 *     no application dimension. A future CR that introduces
 *     application-scoped raw abilities would extend the callback at
 *     that time.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 1 Amendment A5                           — AuthorizationService
 *                                                    (the caching and
 *                                                    invalidation
 *                                                    discipline)
 *   Phase 1 Amendment A9                           — Core event
 *                                                    registration
 *                                                    (the sibling
 *                                                    concern of this
 *                                                    provider)
 *   Core Service Provider                          — registers
 *                                                    `AuthorizationService`
 *                                                    as a singleton
 *   Phase 2C A.52                                  — `LoanPolicy` (the
 *                                                    delegation chain
 *                                                    that the raw-
 *                                                    ability path
 *                                                    bypasses)
 *   Phase 2C A.77                                  — `LoanController::store()`
 *                                                    (the call site that
 *                                                    first exposed the
 *                                                    missing provider)
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