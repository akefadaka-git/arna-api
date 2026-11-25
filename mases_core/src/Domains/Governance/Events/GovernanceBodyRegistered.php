<?php

declare(strict_types=1);

/* Emitted after commit when a Governance body is registered. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyRegistered
 *
 * Domain event dispatched when a governance body is registered.
 *
 * See the file-level change-verification block for the full rationale
 * on when the event fires, what it carries, what it deliberately
 * omits, and why it is dispatched synchronously rather than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceBodyRegistered
{
    use Dispatchable;

    /**
     * The registered governance body.
     *
     * Hydrated and persisted at the time the event is constructed.
     * The service has already run `$body->refresh()` (implicitly,
     * via `->create()` returning the freshly-inserted model) before
     * this event is instantiated, so every attribute reflects the
     * row as committed.
     *
     * Read-only. Listeners MUST treat the model as immutable — any
     * modification is visible to subsequent listeners and to the
     * caller. If a listener needs a modified copy, it should clone.
     *
     * @var GovernanceBody
     */
    public readonly GovernanceBody $body;

    /**
     * Create a new event instance.
     *
     * The constructor takes the persisted `GovernanceBody` instance.
     * It performs no validation and no side effects; the service has
     * already established every invariant that governs registration
     * before reaching this point.
     *
     * @param GovernanceBody $body  The newly-created, committed row.
     */
    public function __construct(GovernanceBody $body)
    {
        $this->body = $body;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance body.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Equivalent to `$event->body->id`, but expresses
     * intent at the call site and tolerates a caller that has not
     * yet typed the property.
     *
     * Used by the audit listener (G2a file 9) to populate the
     * `resource_id` column of the audit row.
     *
     * @return int
     */
    public function bodyId(): int
    {
        return (int) $this->body->getKey();
    }

    /**
     * The tenant id under which the governance body was registered.
     *
     * Resolved directly from the model's `tenant_id` attribute — a
     * non-nullable integer column on `governance_bodies`. The
     * nullable return type reflects the theoretical case where a
     * caller constructs an event manually with an unsaved model
     * whose `tenant_id` has not been set.
     *
     * Used by the audit listener (G2a file 9) to populate the
     * `tenant_id` column of the audit row.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->body->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}