<?php

declare(strict_types=1);

/* Emitted after commit when a Governance term is registered. */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm;

/**
 * GovernanceTermRegistered
 *
 * Domain event dispatched when a governance term is registered.
 *
 * See the file-level change-verification block for the full rationale
 * on when the event fires, what it carries, what it deliberately
 * omits, and why it is dispatched synchronously rather than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceTermRegistered
{
    use Dispatchable;

    /**
     * The registered governance term.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceTerm
     */
    public readonly GovernanceTerm $term;

    /**
     * Create a new event instance.
     *
     * @param GovernanceTerm $term  The newly-created, committed row.
     */
    public function __construct(GovernanceTerm $term)
    {
        $this->term = $term;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance term.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2b file 25 of 27) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function termId(): int
    {
        return (int) $this->term->getKey();
    }

    /**
     * The tenant id under which the governance term was registered.
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