<?php

declare(strict_types=1);

/* Emitted after commit when a Governance position is updated or its state changes. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionUpdated
 *
 * Domain event dispatched when a governance position is modified —
 * whether by a general field update, a deactivation, or a
 * reactivation.
 *
 * See the file-level change-verification block for the full
 * rationale on the three-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernancePositionUpdated
{
    use Dispatchable;

    /**
     * The updated governance position.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the
     * diff in `getChanges()` until the model is refreshed or
     * reloaded.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernancePosition
     */
    public readonly GovernancePosition $position;

    public readonly array $before;
    public readonly array $changes;

    /**
     * Create a new event instance.
     *
     * @param GovernancePosition $position  The updated, committed row.
     */
    public function __construct(GovernancePosition $position, array $before, array $changes)
    {
        $this->position = $position;
        $this->before = $before;
        $this->changes = $changes;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance position.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2b file 25 of 27) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function positionId(): int
    {
        return (int) $this->position->getKey();
    }

    /**
     * The tenant id under which the governance position was updated.
     *
     * Resolved directly from the model's `tenant_id` attribute.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->position->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}