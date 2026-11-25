<?php

declare(strict_types=1);

/* Emitted after commit when a Governance term is updated or its state changes. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * GovernanceTermUpdated
 *
 * Domain event dispatched when a governance term is modified —
 * whether by a general field update, a deactivation, or a
 * reactivation.
 *
 * See the file-level change-verification block for the full rationale
 * on the three-source dispatch, the derivable change-kind design, the
 * model's role as the diff carrier, and the synchronous-dispatch
 * contract.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceTermUpdated
{
    use Dispatchable;

    /**
     * The updated governance term.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and retains its
     * pre-update values in `getOriginal()` and the diff in
     * `getChanges()` until the model is refreshed or reloaded.
     *
     * Read-only.
     *
     * @var GovernanceTerm
     */
    public readonly GovernanceTerm $term;

    public readonly array $before;
    public readonly array $changes;

    /**
     * Create a new event instance.
     *
     * @param GovernanceTerm $term  The updated, committed row.
     */
    public function __construct(GovernanceTerm $term, array $before, array $changes)
    {
        $this->term = $term;
        $this->before = $before;
        $this->changes = $changes;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance term.
     *
     * Used by the audit listener (G2b file 25 of 27) to populate the
     * `resource_id` column of the audit row.
     *
     * @return int
     */
    public function termId(): int
    {
        return (int) $this->term->getKey();
    }

    /**
     * The tenant id under which the governance term was updated.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->term->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}