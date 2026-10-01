<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 15 of 31
 * =============================================================================
 *
 * File:            Events/GovernanceAppointmentUpdated.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceAppointmentService` (G2c
 *   file 7 of 31) already referenced the class by name inside four
 *   after-commit closures:
 *
 *       update():     $this->dispatchAfterCommit(new GovernanceAppointmentUpdated($appointment));
 *       activate():   $this->dispatchAfterCommit(new GovernanceAppointmentUpdated($appointment));
 *       revoke():     $this->dispatchAfterCommit(new GovernanceAppointmentUpdated($appointment));
 *       supersede():  $this->dispatchAfterCommit(new GovernanceAppointmentUpdated($appointment));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked any of the four
 *   methods during the deployment window (the routes amendment is
 *   file 31 of 31, delivered last), the missing file was inert. But
 *   the moment any caller invoked `update()`, `activate()`,
 *   `revoke()`, or `supersede()`, the after-commit closure would
 *   attempt to instantiate a non-existent class and fail with an
 *   `Error: Class not found`, after the row had already been
 *   committed.
 *
 * After:
 *   One file that declares the `GovernanceAppointmentUpdated` domain
 *   event — the class that carries the fact "a governance
 *   appointment was modified" from the service layer to any
 *   listener.
 *
 *   What the event carries:
 *
 *     · The updated `GovernanceAppointment` model, as a
 *       `public readonly` property. The model is the same instance
 *       the service modified and persisted. Its attributes reflect
 *       the post-update state; its `getOriginal()` and
 *       `getChanges()` methods retain the pre-update state until
 *       the model is refreshed or reloaded.
 *
 *   Two convenience accessors:
 *
 *     · `appointmentId(): int` — the primary key of the updated
 *                                appointment.
 *     · `tenantId(): ?int`     — the appointment's owning tenant,
 *                                resolved from the model's
 *                                `tenant_id` attribute.
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
 *       "was this a field update, an activation, a revocation, or
 *       a supersession?". The event does not encode the answer; it
 *       is derivable from `getChanges()` and
 *       `$appointment->status`. See the class docblock §"The
 *       four-source dispatch".
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
 * The event is dispatched by `GovernanceAppointmentService` (G2c
 * file 7 of 31) from four methods:
 *
 *     update()     — after a field-level modification.
 *     activate()   — after setting `status = 'active'`.
 *     revoke()     — after setting `status = 'revoked'`.
 *     supersede()  — after setting `status = 'superseded'`.
 *
 * Each dispatch is wrapped in `DB::afterCommit()`, so the event
 * fires only if the transaction commits. If the transaction rolls
 * back — because a validation failed after the model was modified,
 * or because of a database error — the event never fires.
 *
 * The event fires ONCE per successful mutation. A service call that
 * modifies two columns of the same row dispatches one event, not
 * one per column. A caller that invokes `activate()` and then
 * `revoke()` as separate service calls dispatches two events.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * The four-source dispatch — why one event class, not four
 * =============================================================================
 * The alternative design is four event classes:
 *
 *     GovernanceAppointmentUpdated
 *     GovernanceAppointmentActivated
 *     GovernanceAppointmentRevoked
 *     GovernanceAppointmentSuperseded
 *
 * That design is rejected for the same reasons documented in
 * `GovernancePositionUpdated` (G2b file 11 of 27) and
 * `GovernanceTermUpdated` (G2b file 23 of 27): activation,
 * revocation, and supersession are STATE CHANGES on the `status`
 * column, not distinct business operations. They share the same
 * audit action slug and the same listener behaviour as a general
 * update — the audit trail records "the appointment was updated",
 * and the change set records what specifically moved.
 *
 * Three additional event classes would require three additional
 * listener registrations and three additional branches in the
 * listener — all for facts that are already expressible by the one
 * event plus the change set. A future CR that adds a new
 * state-changing method to the service dispatches the same event
 * without a new registration.
 *
 * The one-event design is the platform's convention. It mirrors
 * `GovernanceBodyUpdated` (G2a file 8 of 18), which is dispatched
 * by `GovernanceBodyService::update()`, `::deactivate()`, and
 * `::reactivate()`.
 *
 * =============================================================================
 * The action slug — one slug for the four sources
 * =============================================================================
 * The audit listener (G2c file 29 of 31) writes every one of the
 * four dispatches under the single action slug
 * `governance.appointment.updated`. It does NOT distinguish between
 * a field-level update and a state transition at the action-slug
 * level.
 *
 * A future CR that decides the audit trail should distinguish the
 * two would either:
 *
 *   · Introduce additional action slugs (e.g.
 *     `governance.appointment.activated`,
 *     `governance.appointment.revoked`,
 *     `governance.appointment.superseded`) and either add a
 *     discriminator to this event or split it into four events.
 *
 *   · Or, more likely, derive the slug from the change set: an
 *     update whose `getChanges()` contains only `status` is a
 *     transition, and the new value determines which slug applies.
 *
 * Either approach is a CR-worthy change, because it changes the
 * vocabulary of the audit trail and would require a corresponding
 * update to any reporting that consumes the current slugs. This
 * event does not anticipate that change.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceAppointment` model. A
 * listener that needs the diff reads
 * `$event->appointment->getChanges()`; a listener that needs a
 * specific attribute reads it directly.
 *
 * The alternative — carrying only scalar primitives — would force
 * every listener to re-query the row, defeating the purpose of
 * dispatching the event after the commit that updated it. The model
 * is the natural unit of interest, and it carries both the
 * pre-update and post-update state.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$appointment` is `public readonly`. A listener MUST NOT attempt
 * to mutate the appointment through this reference; mutations
 * belong to the service layer.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point. G2c dispatches this event synchronously; the event is not
 * queued. Note that a queued `GovernanceAppointmentUpdated` would
 * lose the change set across the queue boundary — `getChanges()`
 * only makes sense on the model instance the service mutated. A
 * future CR that makes this event queueable must extract the diff
 * into the event before dispatch.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2c file 30 of 31):
 *
 *     Event::listen(
 *         GovernanceAppointmentUpdated::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2c file 29 of 31) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.appointment.updated`.
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * GovernanceAppointmentUpdated
 *
 * Domain event dispatched when a governance appointment is modified
 * — whether by a field-level update, an activation, a revocation, or
 * a supersession.
 *
 * See the file-level change-verification block for the full
 * rationale on the four-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceAppointmentUpdated
{
    use Dispatchable;

    /**
     * The updated governance appointment.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the diff
     * in `getChanges()` until the model is refreshed or reloaded.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceAppointment
     */
    public readonly GovernanceAppointment $appointment;

    /**
     * Create a new event instance.
     *
     * @param GovernanceAppointment $appointment  The updated,
     *                                            committed row.
     */
    public function __construct(GovernanceAppointment $appointment)
    {
        $this->appointment = $appointment;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the updated governance appointment.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2c file 29 of 31) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function appointmentId(): int
    {
        return (int) $this->appointment->getKey();
    }

    /**
     * The tenant id under which the governance appointment was
     * updated.
     *
     * Resolved directly from the model's `tenant_id` attribute.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $tenantId = $this->appointment->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}