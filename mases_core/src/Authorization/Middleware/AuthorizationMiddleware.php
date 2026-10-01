<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;
use MaaleArna\MASES\Core\Events\AuthorizationFailureEvent;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;

/**
 * AuthorizationMiddleware
 *
 * Single middleware that enforces permission, role, and application-access
 * requirements on routes. It is bound to three aliases in `bootstrap/app.php`:
 *
 *     'auth.permission'   => AuthorizationMiddleware::class
 *     'auth.role'         => AuthorizationMiddleware::class
 *     'auth.application'  => AuthorizationMiddleware::class
 *
 * -----------------------------------------------------------------------------
 * Calling conventions
 * -----------------------------------------------------------------------------
 * Two forms are supported:
 *
 *   1. Single-parameter form (used by every route in this project):
 *
 *          ->middleware('auth.permission:finance.transaction.create')
 *          ->middleware('auth.role:admin')
 *          ->middleware('auth.application:finance')
 *
 *      The type is inferred from the route's own middleware alias.
 *
 *   2. Two-parameter form (explicit, for advanced use):
 *
 *          ->middleware('auth.permission:permission,finance.transaction.create')
 *
 * -----------------------------------------------------------------------------
 * Named arguments for AuthorizationService
 * -----------------------------------------------------------------------------
 * The service calls use **named arguments**. This is deliberate — it is
 * resilient against parameter re-ordering in `AuthorizationService` and it
 * fails loudly on rename rather than silently passing the wrong value.
 *
 * -----------------------------------------------------------------------------
 * Failure handling
 * -----------------------------------------------------------------------------
 * On any denial, the middleware:
 *   1. Emits an `AuthorizationFailureEvent` via the global `event()` helper
 *      so the platform's security listener can persist a `security_events`
 *      row.
 *   2. Emits a `Log::warning` line for real-time operational visibility.
 *   3. Throws an `AuthorizationException` that the canonical error renderer
 *      converts into the standard JSON envelope.
 *
 * NOTE ON DISPATCH: `AuthorizationFailureEvent` uses the `Dispatchable`
 * trait, whose static `dispatch()` method calls `new static(...$arguments)`.
 * Passing an already-constructed event instance to `::dispatch()` therefore
 * makes PHP try to construct a *second* event with the first as `$userId`.
 * The correct pattern is to use the global `event()` helper, which accepts
 * a fully-constructed instance.
 *
 * @package MaaleArna\MASES\Core\Authorization\Middleware
 */
final class AuthorizationMiddleware
{
    /**
     * Valid check types.
     *
     * @var array<int, string>
     */
    private const VALID_TYPES = ['permission', 'role', 'application'];

    /**
     * The authorization service instance.
     *
     * @var AuthorizationService
     */
    private AuthorizationService $authService;

    /**
     * Create a new AuthorizationMiddleware instance.
     *
     * @param AuthorizationService $authService
     */
    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  ...$params  Either one parameter (value) or two
     *                            (type, value).
     *
     * @return mixed
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$params): mixed
    {
        // ---------------------------------------------------------------------
        // 1. Normalize parameters.
        // ---------------------------------------------------------------------
        if (count($params) >= 2) {
            [$type, $value] = $params;
        } elseif (count($params) === 1) {
            $value = $params[0];
            $type = $this->inferCheckType($request);
        } else {
            throw new AuthorizationException(
                'Authorization middleware requires at least one parameter (a permission, role, or application slug).',
                'AUTH_CONFIGURATION_ERROR',
                500
            );
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new AuthorizationException(
                sprintf('Unsupported authorization check type "%s".', $type),
                'AUTH_CONFIGURATION_ERROR',
                500
            );
        }

        // ---------------------------------------------------------------------
        // 2. Resolve the authenticated user.
        // ---------------------------------------------------------------------
        $user = auth()->user();

        if (!$user) {
            $this->emitFailureEvent(
                type: $type,
                value: $value,
                applicationSlug: $this->resolveApplicationSlug($request),
            );

            throw new AuthorizationException(
                'Authentication required for authorization check.',
                'AUTH_UNAUTHENTICATED',
                401
            );
        }

        // ---------------------------------------------------------------------
        // 3. Perform the check (named arguments — see class docblock).
        // ---------------------------------------------------------------------
        $tenantId = mases_tenant_id();
        $applicationSlug = $this->resolveApplicationSlug($request);

        $allowed = match ($type) {
            'permission' => $this->authService->hasPermission(
                permissionSlug:  $value,
                user:            $user,
                tenantId:        $tenantId,
                applicationSlug: $applicationSlug,
            ),
            'role' => $this->authService->hasRole(
                roleSlug:        $value,
                user:            $user,
                tenantId:        $tenantId,
                applicationSlug: $applicationSlug,
            ),
            'application' => $this->authService->canAccessApplication(
                applicationSlug: $value,
                user:            $user,
            ),
            default => false,
        };

        if ($allowed) {
            return $next($request);
        }

        // ---------------------------------------------------------------------
        // 4. Denied — audit, log, throw.
        // ---------------------------------------------------------------------
        $this->emitFailureEvent(
            type: $type,
            value: $value,
            applicationSlug: $applicationSlug,
        );

        Log::warning('Authorization check failed', [
            'user_id'          => $user->id,
            'type'             => $type,
            'value'            => $value,
            'tenant_id'        => $tenantId,
            'application_slug' => $applicationSlug,
            'ip'               => $request->ip(),
        ]);

        throw new AuthorizationException(
            sprintf('Required %s "%s" not granted.', $type, $value),
            'AUTH_' . strtoupper($type) . '_DENIED',
            403
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Infer the check type from the route's middleware alias.
     *
     * @param Request $request
     *
     * @return string One of 'permission', 'role', 'application'.
     */
    private function inferCheckType(Request $request): string
    {
        $route = $request->route();

        if ($route !== null) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (!is_string($middleware)) {
                    continue;
                }

                if (str_starts_with($middleware, 'auth.permission')) {
                    return 'permission';
                }
                if (str_starts_with($middleware, 'auth.role')) {
                    return 'role';
                }
                if (str_starts_with($middleware, 'auth.application')) {
                    return 'application';
                }
            }
        }

        // Safe default: the most common check type in the platform.
        return 'permission';
    }

    /**
     * Emit an AuthorizationFailureEvent via the global `event()` helper.
     *
     * We use the global helper (rather than the static `::dispatch()`) because
     * `Dispatchable::dispatch(...)` treats its arguments as **constructor
     * arguments** — passing a fully-constructed event to `::dispatch()` would
     * cause PHP to attempt `new AuthorizationFailureEvent($event)`, which
     * fails on the typed `?int $userId` constructor parameter.
     *
     * The global `event()` helper accepts an already-constructed instance.
     *
     * @param string      $type
     * @param string      $value
     * @param string|null $applicationSlug
     *
     * @return void
     */
    private function emitFailureEvent(
        string $type,
        string $value,
        ?string $applicationSlug
    ): void {
        try {
            $action = $type === 'permission'
                ? $this->inferActionFromPermission($value)
                : null;

            $resourceType = $type === 'permission'
                ? $this->inferResourceFromPermission($value)
                : null;

            event(AuthorizationFailureEvent::fromRequest(
                checkType:    $type,
                checkValue:   $value,
                application:  $applicationSlug,
                resourceType: $resourceType,
                resourceId:   null,
                action:       $action,
            ));
        } catch (\Throwable $e) {
            // The middleware must never break the request lifecycle
            // because of an audit failure. Log and continue; the
            // AuthorizationException below still drives the response.
            Log::error('Failed to emit AuthorizationFailureEvent', [
                'error'      => $e->getMessage(),
                'check_type' => $type,
                'check_value' => $value,
            ]);
        }
    }

    /**
     * Infer a short action verb from a permission slug.
     *
     * @param string $permissionSlug
     *
     * @return string|null
     */
    private function inferActionFromPermission(string $permissionSlug): ?string
    {
        $parts = explode('.', $permissionSlug);

        if (count($parts) < 2) {
            return null;
        }

        $last = $parts[count($parts) - 1];

        return $last !== '' ? $last : null;
    }

    /**
     * Infer the resource type from a permission slug.
     *
     * @param string $permissionSlug
     *
     * @return string|null
     */
    private function inferResourceFromPermission(string $permissionSlug): ?string
    {
        $parts = explode('.', $permissionSlug);

        if (count($parts) < 3) {
            return null;
        }

        $middle = $parts[count($parts) - 2];

        return $middle !== '' ? $middle : null;
    }

    /**
     * Resolve the application slug from the request.
     *
     * @param Request $request
     *
     * @return string|null
     */
    private function resolveApplicationSlug(Request $request): ?string
    {
        // 1. Route parameter.
        $routeApplication = $request->route('application');
        if (is_string($routeApplication) && $routeApplication !== '') {
            return $routeApplication;
        }

        // 2. Header.
        $headerApp = $request->header('X-Application');
        if (is_string($headerApp) && $headerApp !== '') {
            return $headerApp;
        }

        // 3. Subdomain, when it matches a configured application slug.
        $host = $request->getHost();
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            $subdomain = $parts[0];
            $applicationSlugs = config('mases.applications', []);
            $slugs = array_column($applicationSlugs, 'slug');
            if (in_array($subdomain, $slugs, true)) {
                return $subdomain;
            }
        }

        return null;
    }
}