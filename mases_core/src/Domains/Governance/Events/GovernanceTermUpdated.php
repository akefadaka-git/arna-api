<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 23 of 27
 * =============================================================================
 *
 * File:            Events/GovernanceTermUpdated.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceTermService` (G2b file 16 of
 *   27) already referenced the class by name inside three after-commit
 *   closures:
 *
 *       update():      $this->dispatchAfterCommit(new GovernanceTermUpdated($term));
 *       deactivate():  $this->dispatchAfterCommit(new GovernanceTermUpdated($term));
 *       reactivate():  $this->dispatchAfterCommit(new GovernanceTermUpdated($term));
 *
 *   The class reference resolved at compile time as an import alias
 *   only. Because no live caller invoked any of the three methods
 *   during the deployment window (the routes amendment is file 27 of
 *   27, delivered last), the missing file was inert. But the moment
 *   any caller invoked `update()`, `deactivate()`, or `reactivate()`,
 *   the after-commit closure would attempt to instantiate a
 *   non-existent class and fail with a `Error: Class not found`, after
 *   the row had already been committed.
 *
 * After:
 *   One file that declares the `GovernanceTermUpdated` domain event —
 *   the class that carries the fact "a governance term was updated"
 *   from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The updated `GovernanceTerm` model, as a `public readonly`
 *       property. The model retains its pre-update state in
 *       `getOriginal()` and the diff in `getChanges()` for the
 *       duration of the request.
 *
 *   Two convenience accessors:
 *
 *     · `termId(): int`    — the primary key of the updated term.
 *     · `tenantId(): ?int` — the term's owning tenant.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · A pre-extracted change diff. The model retains it — see the
 *       file-level change-verification block §"The three-source
 *       dispatch".
 *
 *     · A change-kind discriminator. Derivable from `getChanges()`.
 *
 *     · The actor's user id. Same reasoning as the sibling events.
 *
 *     · Serialization metadata. Synchronous dispatch.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernanceTermService` (G2b file 16 of
 * 27) from three methods:
 *
 *     update()      — after a general field update.
 *     deactivate()  — after setting `is_active = false`.
 *     reactivate()  — after setting `is_active = true`.
 *
 * Each dispatch is wrapped in `DB::afterCommit()`.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * The three-source dispatch — why one event class, not three
 * =============================================================================
 * The alternative design is three event classes:
 *
 *     GovernanceTermUpdated
 *     GovernanceTermDeactivated
 *     GovernanceTermReactivated
 *
 * That design is rejected for the same reasons documented in
 * `GovernancePositionUpdated` (G2b file 11 of 27) and
 * `GovernanceBodyUpdated` (G2a file 8 of 18): deactivation and
 * reactivation are FIELD CHANGES, not distinct business operations.
 * One event class with a derivable change set is the platform's
 * convention.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceTerm` model. A listener that
 * needs the diff reads `$event->term->getChanges()`; a listener that
 * needs a specific attribute reads it directly.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$term` is `public readonly`.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point. G2b dispatches this event synchronously; the event is not
 * queued. Note that a queued `GovernanceTermUpdated` would lose the
 * change set across the queue boundary — `getChanges()` only makes
 * sense on the model instance the service mutated. A future CR that
 * makes this event queueable must extract the diff into the event
 * before dispatch.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27):
 *
 *     Event::listen(
 *         GovernanceTermUpdated::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2b file 25 of 27) writes an audit row with
 * the action slug `governance.term.updated`.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
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

    /**
     * Create a new event instance.
     *
     * @param GovernanceTerm $term  The updated, committed row.
     */
    public function __construct(GovernanceTerm $term)
    {
        $this->term = $term;
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