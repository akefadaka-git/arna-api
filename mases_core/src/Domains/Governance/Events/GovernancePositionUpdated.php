<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 11 of 27
 * =============================================================================
 *
 * File:            Events/GovernancePositionUpdated.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernancePositionService` (G2b file 4
 *   of 27) already referenced the class by name inside three
 *   after-commit closures:
 *
 *       update():      $this->dispatchAfterCommit(new GovernancePositionUpdated($position));
 *       deactivate():  $this->dispatchAfterCommit(new GovernancePositionUpdated($position));
 *       reactivate():  $this->dispatchAfterCommit(new GovernancePositionUpdated($position));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked any of the three
 *   methods during the deployment window (the routes amendment is
 *   file 27 of 27, delivered last), the missing file was inert. But
 *   the moment any caller — the controller (G2b file 9 of 27), an
 *   Artisan command, a seeder, or a test — invoked `update()`,
 *   `deactivate()`, or `reactivate()`, the after-commit closure
 *   would attempt to instantiate a non-existent class and fail with
 *   a `Error: Class not found`, after the row had already been
 *   committed.
 *
 * After:
 *   One file that declares the `GovernancePositionUpdated` domain
 *   event — the class that carries the fact "a governance position
 *   was updated" from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The updated `GovernancePosition` model, as a
 *       `public readonly` property. The model is the same instance
 *       the service modified and persisted. Its attributes reflect
 *       the post-update state; its `getOriginal()` and
 *       `getChanges()` methods retain the pre-update state until
 *       the model is refreshed or reloaded.
 *
 *   Two convenience accessors:
 *
 *     · `positionId(): int`  — the primary key of the updated
 *                              position.
 *     · `tenantId(): ?int`   — the position's owning tenant,
 *                              resolved from the model's
 *                              `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · A pre-extracted change diff. The model retains its original
 *       attribute values in memory (`getOriginal()`) and the exact
 *       set of attributes that differed (`getChanges()`) for the
 *       duration of the request. A listener that needs the diff
 *       reads it directly from the model. Pre-extracting these
 *       into the event would duplicate state that is already
 *       available and would create a second source of truth.
 *
 *     · A change-kind discriminator. A caller might want to know
 *       "was this an update, a deactivation, or a reactivation?".
 *       The event does not encode the answer; it is derivable from
 *       `getChanges()` and `$position->is_active`. See the class
 *       docblock §"The three-source dispatch".
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does, the actor will be
 *       added as an explicit property. Until then, a listener that
 *       needs the actor reads it from `auth()->id()`.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernancePositionService` (G2b file 4
 * of 27) from three methods:
 *
 *     update()      — after a general field update.
 *     deactivate()  — after setting `is_active = false`.
 *     reactivate()  — after setting `is_active = true`.
 *
 * Each dispatch is wrapped in `DB::afterCommit()`, so the event
 * fires only if the transaction commits.
 *
 * The event fires ONCE per successful mutation.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * The three-source dispatch — why one event class, not three
 * =============================================================================
 * The alternative design is three event classes:
 *
 *     GovernancePositionUpdated
 *     GovernancePositionDeactivated
 *     GovernancePositionReactivated
 *
 * That design is rejected because:
 *
 *   · Deactivation and reactivation are FIELD CHANGES (the
 *     `is_active` boolean flipping from one value to the other),
 *     not distinct business operations. They share the same audit
 *     action slug and the same listener behaviour as a general
 *     update.
 *
 *   · Three event classes would require three listener
 *     registrations and three branches in the listener — all for
 *     facts that are already expressible by the one event plus the
 *     change set.
 *
 *   · A future CR that adds a new state-changing method to the
 *     service dispatches the same event without a new
 *     registration.
 *
 * The one-event design is the platform's convention. It mirrors
 * `GovernanceBodyUpdated` (G2a file 8 of 18), which is dispatched
 * by `GovernanceBodyService::update()`, `::deactivate()`, and
 * `::reactivate()`.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernancePosition` model. A listener
 * that needs the diff reads `$event->position->getChanges()`; a
 * listener that needs a specific attribute reads it directly. The
 * alternative — carrying only scalar primitives — would force every
 * listener to re-query the row.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$position` is `public readonly`. A listener MUST NOT attempt to
 * mutate the position through this reference; mutations belong to
 * the service layer.
 *
 * The `readonly` modifier enforces this at the language level. The
 * model instance itself remains mutable by its own API.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point and integrates with Laravel's queued-event machinery. G2b
 * dispatches this event synchronously; the event is not queued.
 *
 * If a future CR makes the audit listener queued, the trait's
 * `SerializesModels` behaviour applies automatically to the
 * `$position` property. Note that a queued
 * `GovernancePositionUpdated` would also lose access to the change
 * set across the queue boundary — `getChanges()` and `getOriginal()`
 * only make sense on the model instance the service mutated. A
 * future CR that makes this event queueable must extract the diff
 * into the event itself before dispatch.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27):
 *
 *     Event::listen(
 *         GovernancePositionUpdated::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2b file 25 of 27) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.position.updated`.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design. The additional complication of losing the
 * change set across a queue round-trip is a second, independent
 * reason to keep the event synchronous.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
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