<?php

declare(strict_types=1);

/* Emitted after commit when a Governance membership is registered. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipRegistered
 *
 * Domain event dispatched when a governance membership is
 * registered.
 *
 * See the file-level change-verification block for the full
 * rationale on when the event fires, what it carries, what it
 * deliberately omits, and why it is dispatched synchronously rather
 * than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceMembershipRegistered
{
    use Dispatchable;

    /**
     * The registered governance membership.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed — with `status`
     * forced to `'active'` by the service, regardless of what the
     * caller supplied.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceMembership
     */
    public readonly GovernanceMembership $membership;

    /**
     * Create a new event instance.
     *
     * @param GovernanceMembership $membership  The newly-created,
     *                                          committed row.
     */
    public function __construct(GovernanceMembership $membership)
    {
        $this->membership = $membership;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance membership.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2c file 29 of 31) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function membershipId(): int
    {
        return (int) $this->membership->getKey();
    }

    /**
     * The tenant id under which the governance membership was
     * registered.
     *
     * Resolved directly from the model's `tenant_id` attribute — a
     * non-nullable integer column on `governance_memberships`. The
     * nullable return type reflects the theoretical case where a
     * caller constructs an event manually with an unsaved model
     * whose `tenant_id` has not been set.
     *
     * Used by the audit listener (G2c file 29 of 31) to populate the
     * `tenant_id` column of the audit row.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->membership->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}