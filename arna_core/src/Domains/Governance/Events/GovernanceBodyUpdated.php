<?php

declare(strict_types=1);

/* Emitted after commit when a Governance body is updated or its state changes. */
namespace MaaleArna\Arna\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyUpdated
 *
 * Domain event dispatched when a governance body is modified —
 * whether by a general field update, a deactivation, or a
 * reactivation.
 *
 * See the file-level change-verification block for the full
 * rationale on the three-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceBodyUpdated
{
    use Dispatchable;
    /**
     * The updated governance body model.
     */
    public readonly GovernanceBody $body;

    /**
     * Attribute values before the mutation.
     *
     * @var array<string, mixed>
     */
    public readonly array $before;

    /**
     * Persisted attribute values changed by the save operation.
     *
     * @var array<string, mixed>
     */
    public readonly array $changes;

    /**
     * Create an event for a persisted governance-body mutation.
     *
     * @param GovernanceBody       $body
     * @param array<string, mixed> $before
     * @param array<string, mixed> $changes
     */
    public function __construct(
        GovernanceBody $body,
        array $before,
        array $changes,
    ) {
        $this->body = $body;
        $this->before = $before;
        $this->changes = $changes;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance body.
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
     * The tenant id under which the governance body was updated.
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