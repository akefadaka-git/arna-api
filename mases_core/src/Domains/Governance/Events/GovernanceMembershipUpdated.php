<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 28 of 31
 * =============================================================================
 *
 * File:            Events/GovernanceMembershipUpdated.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceMembershipService` (G2c
 *   file 20 of 31) already referenced the class by name inside
 *   seven after-commit closures:
 *
 *       update():    $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       end():       $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       suspend():   $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       resume():    $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       resign():    $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       remove():    $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *       expire():    $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *
 *   The `transitionTo()` private helper that the six lifecycle
 *   methods delegate to also dispatches this class:
 *
 *       $this->dispatchAfterCommit(new GovernanceMembershipUpdated($membership));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked any of the seven
 *   write methods during the deployment window (the routes amendment
 *   is file 31 of 31, delivered last), the missing file was inert.
 *   But the moment any caller invoked `update()`, `end()`,
 *   `suspend()`, `resume()`, `resign()`, `remove()`, or `expire()`,
 *   the after-commit closure would attempt to instantiate a
 *   non-existent class and fail with an `Error: Class not found`,
 *   after the row had already been committed.
 *
 * After:
 *   One file that declares the `GovernanceMembershipUpdated` domain
 *   event — the class that carries the fact "a governance
 *   membership was modified" from the service layer to any
 *   listener.
 *
 *   What the event carries:
 *
 *     · The updated `GovernanceMembership` model, as a
 *       `public readonly` property. The model is the same instance
 *       the service modified and persisted. Its attributes reflect
 *       the post-update state; its `getOriginal()` and
 *       `getChanges()` methods retain the pre-update state until
 *       the model is refreshed or reloaded.
 *
 *   Two convenience accessors:
 *
 *     · `membershipId(): int` — the primary key of the updated
 *                                membership.
 *     · `tenantId(): ?int`    — the membership's owning tenant,
 *                                resolved from the model's
 *                                `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · A pre-extracted change diff. The model retains its original
 *       attribute values in memory (`getOriginal()`) and the exact
 *       set of attributes that differed (`getChanges()`) for the
 *       duration of the request.
 *
 *     · A change-kind discriminator. A caller might want to know
 *       "was this a field update, an end, a suspension, a resume, a
 *       resignation, a removal, or an expiry?". The event does not
 *       encode the answer; it is derivable from `getChanges()` and
 *       `$membership->status`. See the class docblock §"The
 *       seven-source dispatch".
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
 * The event is dispatched by `GovernanceMembershipService` (G2c
 * file 20 of 31) from seven methods:
 *
 *     update()   — after a field-level modification.
 *     end()      — after setting `status = 'ended'`.
 *     suspend()  — after setting `status = 'suspended'`.
 *     resume()   — after setting `status = 'active'` (from suspended).
 *     resign()   — after setting `status = 'resigned'`.
 *     remove()   — after setting `status = 'removed'`.
 *     expire()   — after setting `status = 'expired'`.
 *
 * Each dispatch is wrapped in `DB::afterCommit()`, so the event
 * fires only if the transaction commits.
 *
 * The event fires ONCE per successful mutation. A service call that
 * modifies two columns of the same row dispatches one event, not
 * one per column. A caller that invokes `suspend()` and then
 * `resume()` as separate service calls dispatches two events.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * The seven-source dispatch — why one event class, not seven
 * =============================================================================
 * The alternative design is seven event classes:
 *
 *     GovernanceMembershipUpdated
 *     GovernanceMembershipEnded
 *     GovernanceMembershipSuspended
 *     GovernanceMembershipResumed
 *     GovernanceMembershipResigned
 *     GovernanceMembershipRemoved
 *     GovernanceMembershipExpired
 *
 * That design is rejected for the same reasons documented in
 * `GovernancePositionUpdated` (G2b file 11 of 27),
 * `GovernanceTermUpdated` (G2b file 23 of 27), and
 * `GovernanceAppointmentUpdated` (G2c file 15 of 31): every one of
 * the six lifecycle transitions is a STATE CHANGE on the `status`
 * column, not a distinct business operation with its own
 * independent semantics. They share the same audit action slug and
 * the same listener behaviour as a general update — the audit
 * trail records "the membership was updated", and the change set
 * records what specifically moved (and to which status).
 *
 * Six additional event classes would require six additional
 * listener registrations and six additional branches in the
 * listener — all for facts that are already expressible by the one
 * event plus the change set. A future CR that adds a new
 * state-changing method to the service dispatches the same event
 * without a new registration.
 *
 * The one-event design is the platform's convention. It mirrors
 * `GovernanceBodyUpdated` (G2a file 8 of 18), which is dispatched
 * by `GovernanceBodyService::update()`, `::deactivate()`, and
 * `::reactivate()` — three methods, one event.
 *
 * =============================================================================
 * Deriving the change kind from the event
 * =============================================================================
 * A listener that needs to distinguish a field update from a
 * lifecycle transition reads the change set:
 *
 *     $changes = $event->membership->getChanges();
 *
 *     if (array_key_exists('status', $changes)) {
 *         // This was a lifecycle transition. The new value in
 *         // $changes['status'] (or $event->membership->status)
 *         // identifies which of the six transitions ran.
 *         $transition = $changes['status'];
 *     } else {
 *         // This was a field-level update.
 *     }
 *
 * The `ended_reason` column may also be present in the change set
 * for the four TERMINAL transitions (end, resign, remove, expire),
 * because those methods accept a reason that populates the column.
 * `resume` does not accept a reason (the column is for terminal
 * transitions only), so a resume's change set contains only
 * `status`.
 *
 * The discriminator is derivable, so the event does not carry it as
 * a property. This keeps the event payload minimal and avoids a
 * second source of truth for a fact the model already exposes.
 *
 * =============================================================================
 * The action slug — one slug for the seven sources
 * =============================================================================
 * The audit listener (G2c file 29 of 31) writes every one of the
 * seven dispatches under the single action slug
 * `governance.membership.updated`. It does NOT distinguish between
 * a field-level update and a state transition at the action-slug
 * level.
 *
 * A future CR that decides the audit trail should distinguish the
 * transitions would either introduce additional action slugs
 * (e.g. `governance.membership.ended`,
 * `governance.membership.resigned`, …) and derive the slug from
 * the change set, or split this event into seven. Either approach
 * is a CR-worthy change, because it changes the vocabulary of the
 * audit trail and would require a corresponding update to any
 * reporting that consumes the current slugs. This event does not
 * anticipate that change.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceMembership` model. A
 * listener that needs the diff reads
 * `$event->membership->getChanges()`; a listener that needs a
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
 * `$membership` is `public readonly`. A listener MUST NOT attempt
 * to mutate the membership through this reference; mutations
 * belong to the service layer.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point. G2c dispatches this event synchronously; the event is not
 * queued. Note that a queued `GovernanceMembershipUpdated` would
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
 *         GovernanceMembershipUpdated::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2c file 29 of 31) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.membership.updated`.
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceMembership;

/**
 * GovernanceMembershipUpdated
 *
 * Domain event dispatched when a governance membership is modified
 * — whether by a field-level update, an end, a suspension, a
 * resume, a resignation, a removal, or an expiry.
 *
 * See the file-level change-verification block for the full
 * rationale on the seven-source dispatch, the derivable change-kind
 * design, the model's role as the diff carrier, and the
 * synchronous-dispatch contract.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceMembershipUpdated
{
    use Dispatchable;

    /**
     * The updated governance membership.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the diff
     * in `getChanges()` until the model is refreshed or reloaded.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernanceMembership
     */
    public readonly GovernanceMembership $membership;

    /**
     * Create a new event instance.
     *
     * @param GovernanceMembership $membership  The updated,
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
     * The primary key of the updated governance membership.
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
     * updated.
     *
     * Resolved directly from the model's `tenant_id` attribute.
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