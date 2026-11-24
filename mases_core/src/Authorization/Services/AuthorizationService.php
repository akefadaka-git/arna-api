<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Services;

use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Exceptions\AuthorizationException;
use RuntimeException;

/**
 * =============================================================================
 * AuthorizationService
 * =============================================================================
 *
 * Central authorization service for the MASES platform.
 *
 * This class is the single entry point for every authorization decision made
 * by the platform. Middleware, policies, controllers, and other services
 * should never query the Role / Permission / PlatformApplication models
 * directly for the purposes of a permission check. They should call a method
 * on this service instead.
 *
 * -----------------------------------------------------------------------------
 * Revision history
 * -----------------------------------------------------------------------------
 * 1.0.0  — initial delivery.
 *
 * 1.1.0  — C-09 correction. Comment-only update. The service's executable
 *          code is unchanged. The class docblock's reference to tenant
 *          resolution has been updated to reflect the C-09 model.
 *
 *          Before C-09, `mases_tenant_id()` resolved the tenant in the
 *          following priority: request input (body/query) → `X-Tenant-ID`
 *          header → authenticated user's default tenant → config default.
 *          The service's docblock described the helper as "the tenant
 *          resolved for the current request."
 *
 *          After C-09, `mases_tenant_id()` behaves differently in HTTP
 *          context: it reads the tenant resolved by `TenantContextMiddleware`
 *          (via `TenantContextService`). It does NOT read request input or
 *          the header independently. In non-HTTP contexts (console, queue,
 *          scheduler), it falls back to the user's `default_tenant_id`,
 *          then the config default, then null.
 *
 *          The service's authorization behavior is unchanged — it still
 *          calls `mases_tenant_id()` for the default tenant when no explicit
 *          `$tenantId` is supplied. What changes is the value the helper
 *          returns in HTTP context when a request body carried a `tenant_id`
 *          that disagreed with the middleware-verified tenant. The service
 *          now scopes permission checks against the middleware-verified
 *          tenant, which is the correct behavior.
 *
 *          No method signature change. No caching change. No invalidation
 *          change. No behavioral change in this file beyond what
 *          `mases_tenant_id()` now returns.
 *
 * -----------------------------------------------------------------------------
 * Why a service, not a trait or a facade
 * -----------------------------------------------------------------------------
 * Authorization in MASES is deliberately centralised so that:
 *
 *   1. There is exactly one place where the rules for "who can do what" live.
 *   2. Caching strategy is applied uniformly to every check.
 *   3. Tenant and application context are handled identically everywhere.
 *   4. Cache invalidation is triggered from exactly one place.
 *
 * If permission-check logic is duplicated anywhere else in the codebase,
 * cache invalidation silently fails for that copy of the logic.
 *
 * -----------------------------------------------------------------------------
 * Context resolution
 * -----------------------------------------------------------------------------
 * Every check accepts three contexts, all optional:
 *
 *   - $user             : whose permissions are being checked. Defaults to
 *                         the currently authenticated user via auth()->user().
 *
 *   - $tenantId         : which tenant's scope. When null, the service falls
 *                         back to `mases_tenant_id()`.
 *
 *                         After the C-09 correction, that helper returns
 *                         the tenant resolved by the request middleware
 *                         pipeline in HTTP context (via
 *                         `TenantContextService`, populated by
 *                         `TenantContextMiddleware`). It does not read the
 *                         request body or query string. In non-HTTP contexts
 *                         (console, queue, scheduler), the helper falls back
 *                         to the authenticated user's `default_tenant_id`,
 *                         then to `config('mases.tenant.default_tenant_id')`,
 *                         then to null.
 *
 *                         Passing an explicit `$tenantId` bypasses the
 *                         helper entirely. Callers that need to check a
 *                         tenant other than the request's resolved tenant
 *                         must supply it explicitly.
 *
 *   - $applicationSlug  : which platform application. Defaults to null, which
 *                         means "no application scoping".
 *
 * Passing null explicitly means "use the default". Passing a concrete value
 * means "use this value even if the request context differs". This is what
 * makes the service usable both from HTTP request contexts and from
 * non-HTTP contexts (queued jobs, artisan commands, tests).
 *
 * -----------------------------------------------------------------------------
 * Caching strategy
 * -----------------------------------------------------------------------------
 * Permission checks are expensive (multiple joins). They are therefore
 * cached. The cache key embeds the full check context:
 *
 *     auth:permission:{userId}:{permissionSlug}:{tenantId}:{applicationId}
 *
 * The cached value is a boolean: `true` or `false`. Both answers are cached,
 * because a negative answer is just as expensive to compute as a positive
 * one and just as valid to cache (until a role or permission change).
 *
 * Application slug → ID lookups are cached separately:
 *
 *     auth:app_id:{slug}
 *
 * This avoids a DB round trip on every permission check that specifies an
 * application context.
 *
 * -----------------------------------------------------------------------------
 * Cache invalidation — why not cache tags
 * -----------------------------------------------------------------------------
 * Laravel's tag-based cache invalidation (`Cache::tags(...)`) is only
 * supported by stores that implement `Illuminate\Contracts\Cache\TaggableStore`.
 * On this MASES deployment the cache driver is `database`, which does NOT
 * support tags. Calling `Cache::tags(...)` on the database driver throws
 * `BadMethodCallException: This cache store does not support tagging.`
 *
 * Cache tags are therefore unavailable.
 *
 * -----------------------------------------------------------------------------
 * Cache invalidation — atomic generation keys
 * -----------------------------------------------------------------------------
 * Permission-result keys include a per-user generation plus tenant and
 * application generations where those contexts apply. Mutations advance the
 * relevant generation with the cache store's atomic increment operation.
 * This avoids a read/modify/write key registry: concurrent registrations
 * cannot overwrite one another, and any late result written under an older
 * generation is unreachable to subsequent checks. Generation counters live
 * longer than permission-result entries.
 *
 * -----------------------------------------------------------------------------
 * What this class does NOT do
 * -----------------------------------------------------------------------------
 *   - It does not create, assign, or revoke roles or permissions. That
 *     responsibility belongs to the controller or command that mutates the
 *     `user_has_roles` / `user_has_permissions` pivots, and that code MUST
 *     call `clearUserCache()` after every such mutation.
 *   - It does not verify email, active status, or account lock status.
 *     Those are authentication-time concerns, not authorization-time.
 *   - It does not enforce rate limits. Rate limiting is separate.
 *
 * -----------------------------------------------------------------------------
 * Deferred findings
 * -----------------------------------------------------------------------------
 * P1-A5-FLUSH-001
 *   `clearApplicationCache()` calls `$this->cache->flush()`, which on the
 *   database store wipes the ENTIRE application cache — including unrelated
 *   cached values such as rate-limiter counters and other services' caches.
 *   This was acknowledged as a hazard but NOT fixed in Phase 1 Amendment A5
 *   because A5's approved scope is limited to user-cache invalidation. The
 *   method is left unchanged and documented. A separate Change Request may
 *   re-scope it.
 *
 * @package MaaleArna\MASES\Core\Authorization\Services
 * @version 1.1.0
 */
final class AuthorizationService
{
    /**
     * Cache duration for permission and application-ID lookups, in seconds.
     *
     * Five minutes is a deliberate compromise:
     *
     *   - Long enough that the DB load from permission checks is negligible
     *     under normal traffic.
     *   - Short enough that even if a caller forgets to invoke
     *     `clearUserCache()` after a mutation, the stale value self-corrects
     *     within a bounded window.
     *
     * Correct callers always call `clearUserCache()` after mutation, so in
     * practice the TTL is only a safety net.
     *
     * @var int
     */
    private const CACHE_DURATION = 300;

    /**
     * Cache duration for the per-user key registry, in seconds.
     *
     * MUST be greater than CACHE_DURATION. See the class docblock section
     * "Registry lifetime" for the reasoning.
     *
     * @var int
     */
    private const REGISTRY_DURATION = 600;

    /** Persistent generation counters outlive permission-result entries. */
    private const VERSION_DURATION = 31536000;

    /**
     * The cache repository instance.
     *
     * Injected via constructor so tests can substitute an in-memory or
     * fake implementation. When null, resolves the default cache store
     * from the framework (which on this deployment is `database`).
     *
     * @var CacheContract
     */
    private CacheContract $cache;

    /**
     * Create a new AuthorizationService instance.
     *
     * @param CacheContract|null $cache Optional cache repository. When null,
     *                                  the framework's default cache store is
     *                                  used. Production code normally passes
     *                                  null and lets the container provide the
     *                                  default. Tests inject a fake.
     */
    public function __construct(?CacheContract $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    // =========================================================================
    // PUBLIC API — Permission checks
    // =========================================================================

    /**
     * Check whether a user has a specific permission.
     *
     * The permission may be held either directly (via the `user_has_permissions`
     * pivot) or indirectly (via any role the user holds, through `roles.permissions`).
     * Both paths are considered. The method returns true if either path matches.
     *
     * Tenant scoping:
     *   When `$tenantId` is supplied and non-null, the check matches
     *   permissions whose `tenant_id` equals that value OR whose
     *   `tenant_id` is NULL. A NULL tenant on a permission means "global"
     *   — it applies in every tenant. A non-NULL tenant means "scoped to
     *   that tenant only".
     *
     *   When `$tenantId` is null (the default for callers that do not
     *   supply one), the value is resolved through `mases_tenant_id()`.
     *   In HTTP context, that helper returns the tenant resolved by the
     *   middleware pipeline (via `TenantContextService`); it does not
     *   read the request body, query string, or header. In non-HTTP
     *   context it falls back to the user default, then the config
     *   default, then null. See `mases_tenant_id()`'s own docblock for
     *   the full contract.
     *
     *   Resolving the tenant through this fallback does NOT verify the
     *   caller's membership in that tenant. Membership enforcement is a
     *   separate concern handled by `TenantMembershipMiddleware` on
     *   routes whose middleware stack includes the `tenant.membership`
     *   alias. This service evaluates the caller's permission set within
     *   the resolved tenant; it does not authorize the caller's right to
     *   that tenant.
     *
     * Application scoping:
     *   When $applicationSlug is provided, the application is resolved to an
     *   ID, and only permissions whose `application_id` equals that ID OR
     *   whose `application_id` is NULL are considered. A NULL application
     *   means "not specific to one application".
     *
     * Cache behaviour:
     *   The result is cached under a key that embeds user, permission, tenant,
     *   and application — so different contexts do not collide. The key is
     *   registered with the per-user registry so `clearUserCache()` can find
     *   and remove it later. See the class docblock.
     *
     * @param string          $permissionSlug    Permission slug, e.g. 'finance.transaction.create'.
     * @param User|null       $user              User to check. Null = current authenticated user.
     * @param int|string|null $tenantId          Tenant context. When null (the default), the
     *                                          tenant is resolved through `mases_tenant_id()`.
     *                                          When a concrete value is supplied, it is used
     *                                          verbatim and the helper is not consulted.
     *                                          Resolving the tenant does not verify the caller's
     *                                          membership; see the paragraph above.
     * @param string|null     $applicationSlug   Application slug, e.g. 'finance'. Null = no scoping.
     *
     * @return bool  True if the user holds the permission (directly or via a role).
     */
    public function hasPermission(
        string $permissionSlug,
        ?User $user = null,
        int|string|null $tenantId = null,
        ?string $applicationSlug = null
    ): bool {
        $user = $this->resolveUser($user);
        if (!$user) {
            return false;
        }

        // A caller-supplied tenant is a scope selector, not authority to
        // switch away from the trusted request tenant. Validate it before
        // resolving permissions or consulting the permission cache.
        if ($tenantId !== null && !$this->explicitTenantMatchesTrustedContext($tenantId)) {
            return false;
        }

        $tenantId      = $tenantId ?? mases_tenant_id();
        $applicationId = $applicationSlug
            ? $this->resolveApplicationId($applicationSlug)
            : null;

        // Versioned keys avoid a mutable key registry. Concurrent invalidation
        // advances a generation atomically, so a stale cache write cannot be
        // reached by any subsequent permission check.
        $userVersion = $this->cacheVersion($this->userVersionKey($user));
        $tenantVersion = $tenantId === null
            ? 0
            : $this->cacheVersion($this->tenantVersionKey($user, $tenantId));
        $applicationWildcardVersion = $applicationId === null
            ? 0
            : $this->cacheVersion($this->applicationVersionKey($user, $applicationId, null));
        $applicationTenantVersion = ($applicationId === null || $tenantId === null)
            ? 0
            : $this->cacheVersion($this->applicationVersionKey($user, $applicationId, $tenantId));

        $cacheKey = sprintf(
            'auth:permission:%d:%d:%d:%d:%d:%s:%s:%s',
            $user->id,
            $userVersion,
            $tenantVersion,
            $applicationWildcardVersion,
            $applicationTenantVersion,
            $permissionSlug,
            $tenantId ?: 'null',
            $applicationId ?: 'null'
        );

        return $this->cache->remember($cacheKey, self::CACHE_DURATION, function () use (
            $user,
            $permissionSlug,
            $tenantId,
            $applicationId
        ) {
            // ------------------------------------------------------------
            // Path A — direct permissions.
            // A user may hold a permission without it being attached to
            // any of their roles. Check the direct pivot first.
            // ------------------------------------------------------------
            $hasDirect = $user->permissions()
                ->where('slug', $permissionSlug)
                ->where('is_active', true)
                ->when(
                    $tenantId !== null,
                    function ($query) use ($tenantId) {
                        $query->where(function ($q) use ($tenantId) {
                            $q->where('tenant_id', $tenantId)
                                ->orWhereNull('tenant_id');
                        });
                    },
                    fn ($query) => $query->whereNull('tenant_id')
                )
                ->when($applicationId !== null, function ($query) use ($applicationId) {
                    $query->where(function ($q) use ($applicationId) {
                        $q->where('application_id', $applicationId)
                            ->orWhereNull('application_id');
                    });
                })
                ->exists();

            if ($hasDirect) {
                return true;
            }

            // ------------------------------------------------------------
            // Path B — permissions inherited via roles.
            // For every role the user holds, check if any of its attached
            // permissions matches the requested slug, subject to the same
            // tenant and application scoping rules as Path A.
            // ------------------------------------------------------------
            return $user->roles()
                ->where('roles.is_active', true)
                ->where(function ($roleScope) use ($tenantId, $applicationId): void {
                    $roleScope->where(function ($query): void {
                        $query->where('roles.scope', 'platform')
                            ->whereNull('roles.tenant_id')
                            ->whereNull('roles.application_id');
                    });

                    if ($tenantId !== null) {
                        $roleScope->orWhere(function ($query) use ($tenantId): void {
                            $query->where('roles.scope', 'tenant')
                                ->where('roles.tenant_id', $tenantId)
                                ->whereNull('roles.application_id');
                        });
                    }

                    if ($applicationId !== null) {
                        $roleScope->orWhere(function ($query) use ($tenantId, $applicationId): void {
                            $query->where('roles.scope', 'application')
                                ->where('roles.application_id', $applicationId)
                                ->where(function ($tenantQuery) use ($tenantId): void {
                                    $tenantQuery->whereNull('roles.tenant_id');
                                    if ($tenantId !== null) {
                                        $tenantQuery->orWhere('roles.tenant_id', $tenantId);
                                    }
                                });
                        });
                    }
                })
                ->whereHas('permissions', function ($query) use ($permissionSlug, $tenantId, $applicationId): void {
                    $query->where('permissions.slug', $permissionSlug)
                        ->where('permissions.is_active', true)
                        ->where(function ($scopeQuery) use ($tenantId, $applicationId): void {
                            $scopeQuery->where(function ($global): void {
                                $global->where('permissions.scope', 'global')
                                    ->whereNull('permissions.tenant_id')
                                    ->whereNull('permissions.application_id');
                            });

                            if ($tenantId !== null) {
                                $scopeQuery->orWhere(function ($tenant): void {
                                    $tenant->where('permissions.scope', 'tenant')
                                        ->whereColumn('permissions.tenant_id', 'roles.tenant_id')
                                        ->whereNull('permissions.application_id');
                                });
                            }

                            if ($applicationId !== null) {
                                $scopeQuery->orWhere(function ($application): void {
                                    $application->where('permissions.scope', 'application')
                                        ->whereColumn('permissions.application_id', 'roles.application_id')
                                        ->where(function ($tenantBinding): void {
                                            $tenantBinding->whereNull('permissions.tenant_id')
                                                ->orWhereColumn('permissions.tenant_id', 'roles.tenant_id');
                                        });
                                });
                            }
                        });
                })
                ->exists();
        });
    }

    /**
     * Check whether a user has a specific role.
     *
     * Role scoping follows the same tenant/application rules as
     * `hasPermission()`. Unlike `hasPermission()`, this method is currently
     * NOT cached — see "Known limitations" note in the class docblock if
     * one is added in future. The reason: roles are queried less frequently
     * than permissions on the hot path, and caching them consistently with
     * `clearUserCache()` would require extending the registry to track both
     * kinds of keys. That is out of A5's approved scope.
     *
     * @param string          $roleSlug
     * @param User|null       $user
     * @param int|string|null $tenantId
     * @param string|null     $applicationSlug
     *
     * @return bool
     */
    public function hasRole(
        string $roleSlug,
        ?User $user = null,
        int|string|null $tenantId = null,
        ?string $applicationSlug = null
    ): bool {
        $user = $this->resolveUser($user);
        if (!$user) {
            return false;
        }

        // Keep role checks aligned with permission checks: an explicit
        // tenant must not bypass the trusted tenant context.
        if ($tenantId !== null && !$this->explicitTenantMatchesTrustedContext($tenantId)) {
            return false;
        }

        $tenantId      = $tenantId ?? mases_tenant_id();
        $applicationId = $applicationSlug
            ? $this->resolveApplicationId($applicationSlug)
            : null;

        return $user->roles()
            ->where('slug', $roleSlug)
            ->where('is_active', true)
            ->when(
                $tenantId !== null,
                function ($query) use ($tenantId) {
                    $query->where(function ($q) use ($tenantId) {
                        $q->where('tenant_id', $tenantId)
                            ->orWhereNull('tenant_id');
                    });
                },
                fn ($query) => $query->whereNull('tenant_id')
            )
            ->when($applicationId !== null, function ($query) use ($applicationId) {
                $query->where(function ($q) use ($applicationId) {
                    $q->where('application_id', $applicationId)
                        ->orWhereNull('application_id');
                });
            })
            ->exists();
    }

    /**
     * Check whether a user can access a specific platform application.
     *
     * Application access is defined by convention as the permission with
     * the slug `app.{applicationSlug}.access`. The application must also
     * exist and be active; an inactive application denies everyone,
     * regardless of assigned permissions.
     *
     * This is deliberately a thin wrapper around `hasPermission()` so that
     * the same caching and invalidation rules apply.
     *
     * @param string          $applicationSlug  e.g. 'finance'.
     * @param User|null       $user
     * @param int|string|null $tenantId
     *
     * @return bool
     */
    public function canAccessApplication(
        string $applicationSlug,
        ?User $user = null,
        int|string|null $tenantId = null
    ): bool {
        $user = $this->resolveUser($user);
        if (!$user) {
            return false;
        }

        // The application must exist and be active. An inactive application
        // is treated as "does not exist" for access purposes: nobody can
        // reach it, even with a permission grant.
        $application = PlatformApplication::where('slug', $applicationSlug)
            ->where('is_active', true)
            ->first();

        if (!$application) {
            return false;
        }

        $permissionSlug = sprintf('app.%s.access', $applicationSlug);

        return $this->hasPermission($permissionSlug, $user, $tenantId, $applicationSlug);
    }

    /**
     * Return every active platform application the user can access.
     *
     * Iterates the active applications and includes those for which the
     * user holds the corresponding `app.{slug}.access` permission. Ordering
     * follows the underlying `PlatformApplication` query (default: insertion
     * order). No pagination — the set of platform applications is small
     * and static; if that ever changes, this method should be revisited.
     *
     * @param User|null       $user
     * @param int|string|null $tenantId
     *
     * @return \Illuminate\Support\Collection<int, PlatformApplication>
     */
    public function getAccessibleApplications(
        ?User $user = null,
        int|string|null $tenantId = null
    ): \Illuminate\Support\Collection {
        $user = $this->resolveUser($user);
        if (!$user) {
            return collect();
        }

        $tenantId = $tenantId ?? mases_tenant_id();

        $applications = PlatformApplication::where('is_active', true)->get();

        return $applications->filter(function (PlatformApplication $application) use ($user, $tenantId) {
            $permissionSlug = sprintf('app.%s.access', $application->slug);
            return $this->hasPermission($permissionSlug, $user, $tenantId, $application->slug);
        })->values();
    }

    // =========================================================================
    // PUBLIC API — Enforcing variants (throw on denial)
    // =========================================================================

    /**
     * Assert that a user holds a permission. Throws on denial.
     *
     * Semantically identical to `hasPermission()` except that it throws an
     * `AuthorizationException` (HTTP 403, code `AUTH_PERMISSION_DENIED`) on
     * failure. Use this when a denied check must abort the request rather
     * than branch in caller code.
     *
     * The exception is caught by the canonical renderer in
     * `bootstrap/app.php` and converted into the MASES error envelope.
     *
     * @throws AuthorizationException
     */
    public function requirePermission(
        string $permissionSlug,
        ?User $user = null,
        int|string|null $tenantId = null,
        ?string $applicationSlug = null
    ): void {
        if (!$this->hasPermission($permissionSlug, $user, $tenantId, $applicationSlug)) {
            throw new AuthorizationException(
                sprintf('Permission "%s" is required.', $permissionSlug),
                'AUTH_PERMISSION_DENIED',
                403
            );
        }
    }

    /**
     * Assert that a user holds a role. Throws on denial.
     *
     * @throws AuthorizationException
     */
    public function requireRole(
        string $roleSlug,
        ?User $user = null,
        int|string|null $tenantId = null,
        ?string $applicationSlug = null
    ): void {
        if (!$this->hasRole($roleSlug, $user, $tenantId, $applicationSlug)) {
            throw new AuthorizationException(
                sprintf('Role "%s" is required.', $roleSlug),
                'AUTH_ROLE_DENIED',
                403
            );
        }
    }

    /**
     * Assert that a user can access an application. Throws on denial.
     *
     * @throws AuthorizationException
     */
    public function requireApplicationAccess(
        string $applicationSlug,
        ?User $user = null,
        int|string|null $tenantId = null
    ): void {
        if (!$this->canAccessApplication($applicationSlug, $user, $tenantId)) {
            throw new AuthorizationException(
                sprintf('Access to application "%s" is required.', $applicationSlug),
                'AUTH_APPLICATION_DENIED',
                403
            );
        }
    }

    // =========================================================================
    // PUBLIC API — Cache invalidation
    // =========================================================================

    /**
     * Clear every authorization cache entry belonging to a specific user.
     *
     * This method MUST be called by any code that changes what a user can
     * do. That includes, but is not limited to:
     *
     *   - Attaching or detaching a role from a user.
     *   - Attaching or detaching a direct permission from a user.
     *   - Modifying a role's permission set (which affects every user who
     *     holds that role — those users' caches must be cleared too; this
     *     method alone is not sufficient for that case).
     *   - Deactivating or deleting a user.
     *   - Moving a user between tenants.
     *
     * Invalidation algorithm:
     *
     *   1. Read the registry of cache keys for this user.
     *   2. Delete every key in the registry in one `deleteMultiple` call.
     *   3. Forget the registry key itself, so it does not accumulate stale
     *      entries over time.
     *
     * If the registry is empty (e.g. the user has never had a permission
     * check performed against them), step 2 is skipped and only step 3 runs.
     * Forgetting a non-existent key is a no-op on every Laravel cache driver.
     *
     * @param User $user
     */
    public function clearUserCache(User $user): void
    {
        $this->bumpCacheVersion($this->userVersionKey($user));
    }

    /**
     * Invalidate permission results for one user and tenant using an atomic
     * generation increment rather than a read/modify/write key registry.
     */
    public function clearUserTenantCache(User $user, int|string $tenantId): void
    {
        $this->bumpCacheVersion($this->tenantVersionKey($user, $tenantId));
    }

    /**
     * Invalidate one user's application permission results. A null tenant
     * advances the wildcard generation; a concrete tenant advances only
     * that application/tenant intersection.
     */
    public function clearUserApplicationCache(
        User $user,
        int|string $applicationId,
        int|string|null $tenantId = null
    ): void {
        $this->bumpCacheVersion($this->applicationVersionKey($user, $applicationId, $tenantId));
    }

    public function clearApplicationCache(): void
    {
        // We can't easily wildcard delete with all cache drivers, but we can
        // flush the entire cache or rely on expiration. For simplicity,
        // we'll leave this method as a placeholder; implement as needed.
        // A possible approach is to keep a list of known application slugs.
        // For now, we simply flush all cached app IDs.
        $this->cache->flush(); // Heavy-handed; use with caution. See P1-A5-FLUSH-001.
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Validate an explicit tenant selector against trusted tenant context.
     *
     * In a routed HTTP request, trusted context must already have been set by
     * the tenant middleware. Missing context or a mismatch denies the check.
     * Non-HTTP execution retains the existing explicit-tenant behavior for
     * commands and queued work that deliberately supplies its tenant scope.
     */
    private function explicitTenantMatchesTrustedContext(int|string $tenantId): bool
    {
        try {
            $trustedTenantId = app(\MaaleArna\MASES\Core\Tenancy\Services\TenantContextService::class)
                ->getTenantId();
        } catch (\Throwable) {
            return false;
        }

        if ($trustedTenantId !== null) {
            return (string) $trustedTenantId === (string) $tenantId;
        }

        // A matched route indicates an HTTP authorization path. Do not let an
        // explicit argument fill a missing middleware-established context.
        if (app()->bound('request') && app('request')->route() !== null) {
            return false;
        }

        // Preserve the existing explicit-scope contract for non-HTTP jobs and
        // console commands, where callers are responsible for choosing scope.
        return app()->runningInConsole();
    }

    /**
     * Resolve the user instance to check against.
     *
     * When null, falls back to the currently authenticated user via
     * `auth()->user()`. Returns null if no user is authenticated and none
     * was provided. Callers of the public methods treat a null return as
     * "deny everything" — the standard fail-closed behaviour for
     * authorization.
     *
     * @param User|null $user
     *
     * @return User|null
     */
    private function resolveUser(?User $user): ?User
    {
        return $user ?? auth()->user();
    }

    /**
     * Resolve an application slug to its platform application ID, caching
     * the result.
     *
     * Application slugs are effectively immutable in MASES. Caching the
     * lookup avoids one DB round trip on every permission check that scopes
     * to an application. The cache key is NOT registered with the user
     * registry, because the application-ID mapping is not user-specific —
     * it is shared across every check. Invalidation of these entries is the
     * responsibility of `clearApplicationCache()`.
     *
     * Returns null if no application exists with that slug. A null
     * application ID passed into `hasPermission()` means "no application
     * scoping", which is not equivalent to "any application" — the caller
     * should decide how to interpret an unknown slug.
     *
     * @param string $slug
     *
     * @return int|null
     */
    private function resolveApplicationId(string $slug): ?int
    {
        $cacheKey = sprintf('auth:app_id:%s', $slug);

        return $this->cache->remember($cacheKey, self::CACHE_DURATION, function () use ($slug) {
            return PlatformApplication::where('slug', $slug)->value('id');
        });
    }

    private function userVersionKey(User $user): string
    {
        return sprintf('auth:user_version:%d', $user->id);
    }

    private function tenantVersionKey(User $user, int|string $tenantId): string
    {
        return sprintf('auth:user_tenant_version:%d:%s', $user->id, (string) $tenantId);
    }

    private function applicationVersionKey(
        User $user,
        int|string $applicationId,
        int|string|null $tenantId
    ): string {
        return sprintf(
            'auth:user_application_version:%d:%s:%s',
            $user->id,
            (string) $applicationId,
            $tenantId === null ? '*' : (string) $tenantId,
        );
    }

    private function cacheVersion(string $key): int
    {
        $version = $this->cache->get($key, 0);

        return is_numeric($version) ? (int) $version : 0;
    }

    private function bumpCacheVersion(string $key): void
    {
        // add() initializes the counter atomically; increment() is an atomic
        // cache-store operation, preventing lost updates under concurrency.
        $this->cache->add($key, 0, self::VERSION_DURATION);
        $version = $this->cache->increment($key);

        if ($version === false) {
            throw new RuntimeException('Authorization cache generation could not be advanced.');
        }
    }

}