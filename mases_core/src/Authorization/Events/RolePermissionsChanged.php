<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * RolePermissionsChanged
 *
 * Emitted whenever a role's permission set is synchronised through
 * `Role::syncPermissions()`.
 *
 * -----------------------------------------------------------------------------
 * Why an event?
 * -----------------------------------------------------------------------------
 * The mutation of a role's permission set has a side effect that is not
 * visible at the mutation site: every user who currently holds that role
 * has a cached authorization answer that is now stale. The
 * `AuthorizationService` caches permission checks for 300 seconds per
 * (user, permission, tenant, application) tuple, and its documented
 * contract requires that callers who mutate permissions must invalidate
 * the affected caches.
 *
 * `Role::syncPermissions()` is one such caller. Rather than coupling the
 * `Role` model directly to `AuthorizationService` (which would create a
 * model → service dependency and make the model harder to test in
 * isolation), the model announces what happened and lets the
 * authorization capability decide how to react.
 *
 * This keeps the dependency direction clean:
 *
 *     Role (model)  ──dispatch──▶  RolePermissionsChanged (event)
 *                                        │
 *                                        ▼
 *                             (listener in Authorization capability)
 *                                        │
 *                                        ▼
 *                             AuthorizationService::clearUserCache()
 *
 * -----------------------------------------------------------------------------
 * When is this event emitted?
 * -----------------------------------------------------------------------------
 * The event is dispatched by `Role::syncPermissions()` immediately after
 * `$this->permissions()->sync($permissionIds)` returns successfully, and
 * before the method returns `$this`.
 *
 * It is dispatched UNCONDITIONALLY — even when the resulting permission
 * set is identical to the set that was already attached. This is
 * deliberate: the alternative is to pre-read the current pivot state and
 * compare, which adds a query and opens a race window where a concurrent
 * mutation is not observed. The cost of an unnecessary dispatch is
 * negligible (the listener is a no-op when the affected users' caches are
 * already empty), so the simpler and race-free behavior is preferred.
 *
 * If `sync()` throws — for example, on a DB error — the event is NOT
 * dispatched. No successful mutation occurred, so there is no stale cache
 * to invalidate.
 *
 * -----------------------------------------------------------------------------
 * Payload
 * -----------------------------------------------------------------------------
 * The event carries only identifiers and the post-sync permission set:
 *
 *   - roleId          — the mutated role's primary key
 *   - roleSlug        — the role's slug, for logging and debugging
 *   - permissionIds   — the complete set of permission IDs now attached
 *   - occurredAt      — the moment the mutation completed
 *
 * None of these fields can carry credentials, secrets, or request-body
 * data. The payload is intentionally narrow: it does not carry the
 * pre-sync set, the delta, the affected user list, or the tenant ID.
 *
 *   · The pre-sync set and the delta are not included because no
 *     registered listener needs them; a listener that did need them
 *     could query the pivot directly using `roleId`.
 *
 *   · The affected user list is not included because resolving it is a
 *     query, and doing that query eagerly at dispatch time would impose
 *     the cost on every `syncPermissions()` call even when no listener
 *     is registered. The listener (D-11c) resolves the affected users
 *     on demand.
 *
 *   · The tenant ID is not included because `AuthorizationService::clearUserCache()`
 *     is tenant-agnostic — it clears every cache key the user has, in
 *     every tenant context. Including the tenant ID would create the
 *     misleading impression that the listener scopes its invalidation
 *     by tenant. It does not.
 *
 * -----------------------------------------------------------------------------
 * Serialization
 * -----------------------------------------------------------------------------
 * `SerializesModels` is included for consistency with the other events
 * in the MASES codebase (see `AuthorizationFailureEvent`). The trait
 * substitutes any `Model` instances with a `ModelIdentifier` on
 * serialization and re-resolves them on deserialization. Because this
 * event carries only scalars and a plain array of integers, the trait is
 * a no-op here. It is retained so that a future change to the payload
 * that introduces a model (for example, passing the `Role` instance
 * directly) does not silently break queued dispatch.
 *
 * -----------------------------------------------------------------------------
 * Naming note
 * -----------------------------------------------------------------------------
 * The class name does not carry an `Event` suffix. This matches the
 * existing `AuthorizationFailureEvent` naming in this codebase — the
 * suffix is the word `Event`, and the noun phrase `RolePermissionsChanged`
 * is the subject. Consumers refer to the class as
 * `RolePermissionsChanged::class`, not as `RolePermissionsChangedEvent`.
 *
 * @package MaaleArna\MASES\Core\Authorization\Events
 */
final class RolePermissionsChanged
{
    use Dispatchable;
    use SerializesModels;

    /**
     * The primary key of the role whose permissions were changed.
     *
     * @var int
     */
    public readonly int $roleId;

    /**
     * The slug of the role, carried for logging and debugging.
     *
     * Slugs are effectively immutable in MASES, so this value is stable
     * for the lifetime of the role. It is included here so log lines can
     * be read without a DB lookup.
     *
     * @var string
     */
    public readonly string $roleSlug;

    /**
     * The complete set of permission IDs attached to the role AFTER the
     * sync. Values are integers.
     *
     * This is the post-sync state, not a delta. It is what
     * `Role::syncPermissions()` passed to the underlying pivot's
     * `sync()` call, canonicalised to a flat list of integers.
     *
     * @var array<int, int>
     */
    public readonly array $permissionIds;

    /**
     * The moment the mutation completed.
     *
     * @var DateTimeImmutable
     */
    public readonly DateTimeImmutable $occurredAt;

    /**
     * Create a new RolePermissionsChanged event instance.
     *
     * The `occurredAt` parameter is optional and defaults to the current
     * time. This mirrors the convention used by `AuthorizationFailureEvent`
     * and keeps the call site in `Role::syncPermissions()` a single line:
     *
     *     RolePermissionsChanged::dispatch(
     *         roleId: $this->id,
     *         roleSlug: $this->slug,
     *         permissionIds: $permissionIds,
     *     );
     *
     * The `permissionIds` array is defensively coerced to integers and
     * re-indexed so that a caller passing a `Collection`, an associative
     * array, or a mix of numeric strings and integers produces a payload
     * that matches the documented `array<int, int>` contract.
     *
     * @param int                    $roleId
     * @param string                 $roleSlug
     * @param array<int|string, int|string> $permissionIds
     * @param DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        int $roleId,
        string $roleSlug,
        array $permissionIds,
        ?DateTimeImmutable $occurredAt = null
    ) {
        $this->roleId = $roleId;
        $this->roleSlug = $roleSlug;
        $this->permissionIds = array_values(
            array_map(static fn (int|string $id): int => (int) $id, $permissionIds)
        );
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable('now');
    }

    /**
     * Return a short, human-readable description of the change.
     *
     * Intended for structured logging by the listener. It contains only
     * the role slug, the role ID, and the count of permissions — none of
     * which are sensitive.
     *
     * Example output:
     *
     *     Role permissions changed: role="finance-manager" (id=17), 12 permission(s) assigned
     *
     * @return string
     */
    public function description(): string
    {
        return sprintf(
            'Role permissions changed: role="%s" (id=%d), %d permission(s) assigned',
            $this->roleSlug,
            $this->roleId,
            count($this->permissionIds)
        );
    }
}