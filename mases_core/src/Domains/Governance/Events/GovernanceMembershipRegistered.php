<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 27 of 31
 * =============================================================================
 *
 * File:            Events/GovernanceMembershipRegistered.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceMembershipService::register()`
 *   (G2c file 20 of 31) already referenced the class by name inside
 *   its after-commit closure:
 *
 *       $this->dispatchAfterCommit(new GovernanceMembershipRegistered($membership));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked `register()` during
 *   the deployment window (the routes amendment is file 31 of 31,
 *   delivered last), the missing file was inert. But the moment any
 *   caller invoked `GovernanceMembershipService::register()`, the
 *   after-commit closure would attempt to instantiate a non-existent
 *   class and fail with an `Error: Class not found`, after the row
 *   had already been committed.
 *
 * After:
 *   One file that declares the `GovernanceMembershipRegistered`
 *   domain event — the class that carries the fact "a governance
 *   membership was registered" from the service layer to any
 *   listener.
 *
 *   What the event carries:
 *
 *     · The created `GovernanceMembership` model, as a
 *       `public readonly` property. The model is the fully-hydrated
 *       instance the service just persisted and returned.
 *
 *   Two convenience accessors:
 *
 *     · `membershipId(): int` — the primary key of the registered
 *                                membership.
 *     · `tenantId(): ?int`    — the membership's owning tenant,
 *                                resolved from the model's
 *                                `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · The body's id, the position's id, or the term's id as
 *       separate scalars. Every one of those values is available on
 *       the model (`$event->membership->governance_body_id`, etc.).
 *       Duplicating them as properties would create a second source
 *       of truth.
 *
 *     · The member's identity as a separate scalar. The four
 *       columns (`user_id`, `external_reference`, `member_name_am`,
 *       `member_name_en`) together identify the member; the model
 *       carries all four. A listener that needs the resolved
 *       identity uses the model's `memberKey()` helper or the
 *       `display_name` accessor.
 *
 *     · A change diff. Registration is a creation; there is no
 *       prior state to compare against.
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does (a future CR), the
 *       actor will be added as an explicit property. Until then, a
 *       listener that needs the actor reads it from `auth()->id()`.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by
 * `GovernanceMembershipService::register()` (G2c file 20 of 31)
 * inside a `DB::afterCommit()` callback. It therefore fires:
 *
 *   · Only after the transaction that created the membership has
 *     committed. If the transaction rolls back — including when
 *     the overlap invariant (ruling B4) refuses the write — the
 *     callback is discarded and no listener sees a membership that
 *     does not exist.
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
 * The event exposes the full `GovernanceMembership` model as a
 * public readonly property. A listener that needs only the id reads
 * `$event->membership->id`; a listener that needs the member's
 * identity, the temporal bounds, or the structural FKs reads them
 * directly. A listener that needs a relation — the body, the
 * position, the term, the appointment, the user — reads it via
 * `$event->membership->body`, loading lazily if the relation was
 * not eager-loaded.
 *
 * The alternative — carrying only scalar primitives — would force
 * every listener to re-query the row. The model is the natural unit
 * of interest, matching the platform's convention
 * (`GovernanceBodyRegistered`, `GovernancePositionRegistered`,
 * `GovernanceTermRegistered`, `GovernanceAppointmentRegistered`, …).
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$membership` is `public readonly`. A listener MUST NOT attempt
 * to mutate the membership through this reference; mutations belong
 * to the service layer, and any write from a listener would run
 * outside the service's transaction boundary — the transaction that
 * closed the overlap-check concurrency window (ruling B4) has
 * already committed by the time this event fires.
 *
 * The `readonly` modifier enforces the property-binding immutability
 * at the language level. The model instance itself remains mutable
 * by its own API; PHP's `readonly` applies to the binding, not to
 * the object's internal state.
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
 * `$membership` property. That CR would also need to decide what
 * the listener does if the membership has been soft-deleted or
 * hard-deleted by the time the queued job runs.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2c file 30 of 31):
 *
 *     Event::listen(
 *         GovernanceMembershipRegistered::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2c file 29 of 31) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.membership.registered`.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design; the audit trail must be durable before the
 * API returns success to the caller.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipRegistered
 *
 * Domain event dispatched when a governance membership is
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
final class GovernanceMembershipRegistered
{
    use Dispatchable;

    /**
     * The registered governance membership.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed — with `status`
     * forced to `'active'` by the service, regardless of what the
     * caller supplied.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceMembership
     */
    public readonly GovernanceMembership $membership;

    /**
     * Create a new event instance.
     *
     * @param GovernanceMembership $membership  The newly-created,
     *                                          committed row.
     */
    public function __construct(GovernanceMembership $membership)
    {
        $this->membership = $membership;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance membership.
     *
     * Convenience accessor for listeners that need only the
     * identifier. Used by the audit listener (G2c file 29 of 31) to
     * populate the `resource_id` column of the audit row.
     *
     * @return int
     */
    public function membershipId(): int
    {
        return (int) $this->membership->getKey();
    }

    /**
     * The tenant id under which the governance membership was
     * registered.
     *
     * Resolved directly from the model's `tenant_id` attribute — a
     * non-nullable integer column on `governance_memberships`. The
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
        $tenantId = $this->membership->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        return (int) $tenantId;
    }
}