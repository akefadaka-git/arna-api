<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Authorization\Events;

use DateTimeImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted after role permissions change so affected users' authorization caches can be invalidated.
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