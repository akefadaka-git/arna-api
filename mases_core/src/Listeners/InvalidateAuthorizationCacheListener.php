<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Listeners;

use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Events\RolePermissionsChanged;
use MaaleArna\MASES\Core\Authorization\Services\AuthorizationService;

/**
 * InvalidateAuthorizationCacheListener
 *
 * Listens for `RolePermissionsChanged` events and clears the authorization
 * caches of every user who currently holds the mutated role.
 *
 * =============================================================================
 * Why this listener exists
 * =============================================================================
 * The `AuthorizationService` caches permission checks for 300 seconds per
 * (user, permission, tenant, application) tuple. When a role's permission
 * set changes, every user who holds that role has at least one stale
 * cached answer for at least one permission:
 *
 *   · A role that GAINED a permission — its holders continue to be DENIED
 *     that permission until the TTL expires, despite the database
 *     granting it.
 *
 *   · A role that LOST a permission — its holders continue to be ALLOWED
 *     that permission until the TTL expires, despite the database
 *     revoking it. This is a privilege-retention window of up to five
 *     minutes.
 *
 * The second case is the security-relevant one. `Role::syncPermissions()`
 * announces the mutation by dispatching `RolePermissionsChanged`; this
 * listener reacts by asking `AuthorizationService::clearUserCache()` to
 * forget every tracked cache key belonging to each affected user.
 *
 * =============================================================================
 * Why the query lives here (not in the event payload)
 * =============================================================================
 * The event carries only `roleId`, `roleSlug`, `permissionIds`, and
 * `occurredAt`. It deliberately does NOT carry the list of affected users.
 *
 * Resolving the affected user list is a query. Performing it eagerly at
 * dispatch time — inside `Role::syncPermissions()` — would impose that
 * query's cost on every role-mutation call, including the case where no
 * listener is registered. Doing it here means the cost is paid only when
 * a listener is actually registered, and it can be chunked safely for
 * roles that are held by large numbers of users.
 *
 * =============================================================================
 * Chunking
 * =============================================================================
 * The affected users are loaded in chunks of CHUNK_SIZE via
 * `chunkById()`. The purpose is twofold:
 *
 *   · Bounded memory — a role held by thousands of users does not
 *     materialise thousands of Eloquent models simultaneously.
 *
 *   · Bounded lock/transaction window — each chunk is cleared in its own
 *     iteration, so a long-running invalidation does not hold open a
 *     single transaction across the entire set.
 *
 * CHUNK_SIZE of 500 is a deliberate compromise: small enough to keep
 * per-chunk memory bounded, large enough that the number of query
 * round-trips for typical role sizes (tens to hundreds of users) is one
 * or two.
 *
 * =============================================================================
 * Synchronous, not queued
 * =============================================================================
 * `Role::syncPermissions()` is called inside a request that is mutating
 * role state. Invalidation must complete before the response is returned
 * so that:
 *
 *   · The calling admin's next action sees the fresh authorization state
 *     rather than a stale one.
 *
 *   · A concurrent request from an affected user — one that races with
 *     the mutation — does not read a stale answer that the mutation just
 *     invalidated.
 *
 * Queuing the listener would reintroduce that race window. This mirrors
 * the synchronous decision documented on `LogAuthorizationFailureListener`.
 *
 * =============================================================================
 * Failure policy
 * =============================================================================
 * Any exception from the query or from `clearUserCache()` is caught and
 * logged at the application level, then swallowed. The listener must
 * never break the request lifecycle of the caller performing the role
 * mutation.
 *
 * The cost of swallowing is that a failed invalidation leaves stale
 * caches in place until the 300-second TTL expires — the same state the
 * platform had before this listener existed. The cost of NOT swallowing
 * would be a role-mutation request that fails at the last step despite
 * having already committed its pivot changes. The trade-off is
 * deliberate: TTL self-correction is preferred over partial failures.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 *   · This listener does NOT itself mutate the role or its permissions.
 *     It reads only the affected user set and calls the invalidation
 *     entry point.
 *
 *   · It does NOT write to `audit_logs` or `security_events`. Role
 *     permission changes are a first-class business event and their
 *     audit is the responsibility of the domain that performs the
 *     mutation, not of a cache-invalidation listener. If audit is
 *     required, a separate listener can be registered against the same
 *     event without coupling it to this one.
 *
 *   · It does NOT invalidate the application-ID lookup cache
 *     (`auth:app_id:*`). That cache is not user-specific and is not
 *     affected by role-permission mutation.
 *
 * =============================================================================
 * Registration
 * =============================================================================
 *   This listener is NOT auto-discovered because it lives outside the
 *   application's default `app/Listeners` directory. It is registered
 *   explicitly in `CoreServiceProvider::boot()`, alongside the existing
 *   `LogAuthorizationFailureListener` registration:
 *
 *       Event::listen(
 *           RolePermissionsChanged::class,
 *           InvalidateAuthorizationCacheListener::class
 *       );
 *
 *   The registration itself is delivered in D-11d.
 *
 * @package MaaleArna\MASES\Core\Listeners
 * @version 1.0.0
 */
final class InvalidateAuthorizationCacheListener
{
    /**
     * Number of users cleared per iteration of the chunked invalidation
     * loop. See the class docblock §"Chunking" for the rationale.
     */
    private const CHUNK_SIZE = 500;

    /**
     * The authorization service whose per-user caches are being cleared.
     *
     * @var AuthorizationService
     */
    private AuthorizationService $authorizationService;

    /**
     * Create a new listener instance.
     *
     * @param AuthorizationService $authorizationService
     */
    public function __construct(AuthorizationService $authorizationService)
    {
        $this->authorizationService = $authorizationService;
    }

    /**
     * Handle the event.
     *
     * Flow:
     *   1. Query the users who hold the mutated role, chunked by ID.
     *   2. For each user, ask the AuthorizationService to clear every
     *      cache key it has registered for that user.
     *   3. Swallow any exception from the above so that a failure in the
     *      invalidation path never breaks the caller's request lifecycle.
     *
     * @param RolePermissionsChanged $event
     *
     * @return void
     */
    public function handle(RolePermissionsChanged $event): void
    {
        try {
            User::query()
                ->whereHas('roles', function ($query) use ($event): void {
                    $query->where('roles.id', $event->roleId);
                })
                ->chunkById(self::CHUNK_SIZE, function ($users): void {
                    foreach ($users as $user) {
                        $this->authorizationService->clearUserCache($user);
                    }
                });
        } catch (\Throwable $e) {
            // The listener must never break the request lifecycle.
            // A failed invalidation degrades to the pre-listener state:
            // stale cache entries self-correct within the 300-second TTL.
            Log::error(
                'Failed to invalidate authorization caches after role permission change',
                [
                    'error'         => $e->getMessage(),
                    'role_id'       => $event->roleId,
                    'role_slug'     => $event->roleSlug,
                    'permission_count' => count($event->permissionIds),
                ]
            );
        }
    }
}