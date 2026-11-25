<?php

declare(strict_types=1);

/* Emitted after commit when a Governance position is registered. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionRegistered
 *
 * Domain event dispatched when a governance position is registered.
 *
 * See the file-level change-verification block for the full
 * rationale on when the event fires, what it carries, what it
 * deliberately omits, and why it is dispatched synchronously rather
 * than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernancePositionRegistered
{
    use Dispatchable;

    /**
     * The registered governance position.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernancePosition
     */
    public readonly GovernancePosition $position;

    /**
     * Create a new event instance.
     *
     * @param GovernancePosition $position  The newly-created, committed row.
     */
    public function __construct(GovernancePosition $position)
    {
        $this->position = $position;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance position.
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
     * The tenant id under which the governance position was
     * registered.
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