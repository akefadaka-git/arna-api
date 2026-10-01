<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 14 of 31
 * =============================================================================
 *
 * File:            Events/GovernanceAppointmentRegistered.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceAppointmentService::register()`
 *   (G2c file 7 of 31) already referenced the class by name inside
 *   its after-commit closure:
 *
 *       $this->dispatchAfterCommit(new GovernanceAppointmentRegistered($appointment));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked `register()` during
 *   the deployment window (the routes amendment is file 31 of 31,
 *   delivered last), the missing file was inert. But the moment any
 *   caller invoked `GovernanceAppointmentService::register()`, the
 *   after-commit closure would attempt to instantiate a non-existent
 *   class and fail with an `Error: Class not found`, after the row
 *   had already been committed.
 *
 * After:
 *   One file that declares the `GovernanceAppointmentRegistered`
 *   domain event — the class that carries the fact "a governance
 *   appointment was registered" from the service layer to any
 *   listener.
 *
 *   What the event carries:
 *
 *     · The created `GovernanceAppointment` model, as a
 *       `public readonly` property. The model is the fully-hydrated
 *       instance the service just persisted and returned.
 *
 *   Two convenience accessors:
 *
 *     · `appointmentId(): int` — the primary key of the registered
 *                                appointment.
 *     · `tenantId(): ?int`     — the appointment's owning tenant,
 *                                resolved from the model's
 *                                `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · The body's id as a separate scalar. The value is available
 *       on the model (`$event->appointment->governance_body_id`).
 *       Duplicating it as a property would create a second source
 *       of truth.
 *
 *     · The instrument's reference, type, or dates as separate
 *       scalars. Every one of those values is available on the
 *       model. A listener that needs the resolved display label
 *       uses the model's `display_label` accessor; a listener that
 *       needs the resolved enum cases uses the model's
 *       `instrumentType()`, `mode()`, and `statusEnum()` resolvers.
 *
 *     · A change diff. Registration is a creation; there is no
 *       prior state to compare against. The listener that writes
 *       the audit row records the full post-creation snapshot as
 *       `newValues`. `GovernanceAppointmentUpdated` (G2c file 15
 *       of 31) is the event that carries a diff.
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does (a future CR), the
 *       actor will be added as an explicit property on the event.
 *       Until then, a listener that needs the actor reads it from
 *       `auth()->id()` — the pattern every sibling event uses.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously. See the
 *       class docblock §"No queue interface" for the reasoning.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by
 * `GovernanceAppointmentService::register()` (G2c file 7 of 31)
 * inside a `DB::afterCommit()` callback. It therefore fires:
 *
 *   · Only after the transaction that created the appointment has
 *     committed. If the transaction rolls back — including when a
 *     service-layer invariant refuses the write — the callback is
 *     discarded and no listener sees an appointment that does not
 *     exist.
 *
 *   · Before the HTTP response is sent. The dispatch is
 *     synchronous: the registered listener (G2c file 29 of 31, the
 *     amended `AuditGovernanceEvent`) runs in the same request,
 *     writes its audit row, and returns before the controller
 *     produces its response.
 *
 *   · Only once per registration.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceAppointment` model as a
 * public readonly property. A listener that needs only the id reads
 * `$event->appointment->id`; a listener that needs the instrument
 * type, the body id, the tenant id, the effective date, or the
 * resolved title reads them directly. A listener that needs a
 * relation — the body, the position, the term, the memberships the
 * appointment creates — reads it via `$event->appointment->body`,
 * loading lazily if the relation was not eager-loaded.
 *
 * The alternative — carrying only scalar primitives — would force
 * every listener to re-query the row, defeating the purpose of
 * dispatching the event after the commit that created it. The model
 * is the natural unit of interest, matching the platform's
 * convention (`GovernanceBodyRegistered`,
 * `GovernancePositionRegistered`, `GovernanceTermRegistered`, …).
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$appointment` is `public readonly`. A listener MUST NOT attempt
 * to mutate the appointment through this reference; mutations
 * belong to the service layer, and any write from a listener would
 * run outside the service's transaction boundary.
 *
 * The `readonly` modifier enforces this at the language level: PHP
 * raises an `Error` on any attempted reassignment of the property
 * binding. The model instance itself remains mutable by its own API
 * (`fill()`, `save()`); PHP's `readonly` applies to the property
 * binding, not to the object's internal state.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point and integrates with Laravel's queued-event machinery. G2c
 * dispatches this event synchronously; the event is not queued.
 *
 * If a future CR makes the audit listener queued, the trait's
 * `SerializesModels` behaviour applies automatically to the
 * `$appointment` property. That CR would also need to decide what
 * the listener does if the appointment has been soft-deleted or
 * hard-deleted by the time the queued job runs.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2c file 30 of 31):
 *
 *     Event::listen(
 *         GovernanceAppointmentRegistered::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2c file 29 of 31) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.appointment.registered`.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design; the audit trail must be durable before the
 * API returns success to the caller.
 *
 * This mirrors every other domain event in the platform.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment;

/**
 * GovernanceAppointmentRegistered
 *
 * Domain event dispatched when a governance appointment is
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
final class GovernanceAppointmentRegistered
{
    use Dispatchable;

    /**
     * The registered governance appointment.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed — with `status`
     * forced to `'draft'` by the service, regardless of what the
     * caller supplied.
     *
     * Read-only. Listeners MUST treat the model as immutable — any
     * modification is visible to subsequent listeners and to the
     * caller. If a listener needs a modified copy, it should clone.
     *
     * @var GovernanceAppointment
     */
    public readonly GovernanceAppointment $appointment;

    /**
     * Create a new event instance.
     *
     * The constructor takes the persisted `GovernanceAppointment`
     * instance. It performs no validation and no side effects; the
     * service has already established every invariant that governs
     * registration before reaching this point.
     *
     * @param GovernanceAppointment $appointment  The newly-created,
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
     * The primary key of the registered governance appointment.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Equivalent to `$event->appointment->id`, but
     * expresses intent at the call site and tolerates a caller that
     * has not yet typed the property.
     *
     * Used by the audit listener (G2c file 29 of 31) to populate the
     * `resource_id` column of the audit row.
     *
     * @return int
     */
    public function appointmentId(): int
    {
        return (int) $this->appointment->getKey();
    }

    /**
     * The tenant id under which the governance appointment was
     * registered.
     *
     * Resolved directly from the model's `tenant_id` attribute — a
     * non-nullable integer column on `governance_appointments`. The
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
        $tenantId = $this->appointment->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}