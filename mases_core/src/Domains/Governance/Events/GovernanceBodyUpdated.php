<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 8 of 17
 * =============================================================================
 *
 * File:            Events/GovernanceBodyUpdated.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceBodyService` (G2a file 5 of
 *   17) already referenced the class by name inside three after-
 *   commit closures:
 *
 *       update():      $this->dispatchAfterCommit(new GovernanceBodyUpdated($body));
 *       deactivate():  $this->dispatchAfterCommit(new GovernanceBodyUpdated($body));
 *       reactivate():  $this->dispatchAfterCommit(new GovernanceBodyUpdated($body));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked any of the three
 *   methods during the deployment window (the controller is
 *   delivered last, at G2a file 14), the missing file was inert. But
 *   the moment any caller — a controller, an Artisan command, a
 *   seeder, or a test — invoked `update()`, `deactivate()`, or
 *   `reactivate()`, the after-commit closure would attempt to
 *   instantiate a non-existent class and fail with a
 *   `Error: Class not found`, after the row had already been
 *   committed to the database.
 *
 * After:
 *   One file that declares the `GovernanceBodyUpdated` domain event —
 *   the class that carries the fact "a governance body was updated"
 *   from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The updated `GovernanceBody` model, as a `public readonly`
 *       property. The model is the same instance the service
 *       modified and persisted — not a snapshot, not a fresh
 *       query, not a scalar id. Its attributes reflect the
 *       post-update state.
 *
 *   Two convenience accessors:
 *
 *     · `bodyId(): int`    — the primary key of the updated body.
 *     · `tenantId(): ?int` — the body's owning tenant, resolved
 *                            from the model's `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · A pre-extracted change diff. The model itself retains its
 *       original attribute values in memory (`getOriginal()`) and
 *       the exact set of attributes that differed
 *       (`getChanges()`) for the duration of the request, because
 *       Eloquent preserves both until the model is refreshed or
 *       reloaded. A listener that needs the diff reads it directly
 *       from the model:
 *
 *           $before  = $event->body->getOriginal();
 *           $after   = $event->body->getAttributes();
 *           $changed = $event->body->getChanges();
 *
 *       Pre-extracting these into the event would duplicate state
 *       that is already available and would create a second source
 *       of truth for the diff. The audit listener (G2a file 9) uses
 *       `getChanges()` to populate the `new_values` column of the
 *       audit row.
 *
 *     · A change-kind discriminator. A caller might want to know
 *       "was this an update, a deactivation, or a reactivation?".
 *       The event does not encode the answer, because it is
 *       derivable:
 *
 *         · Deactivation   → `in_array('is_active', $changed)`
 *                            and `$body->is_active === false`.
 *         · Reactivation   → `in_array('is_active', $changed)`
 *                            and `$body->is_active === true`.
 *         · General update → any other change set.
 *
 *       Encoding the change kind as a separate property would
 *       require the service to compute it (adding coupling) and
 *       would create a second source of truth for the same fact.
 *       Leaving it derivable keeps the event payload minimal.
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does (a future CR), the
 *       actor will be added as an explicit property on the event.
 *       Until then, a listener that needs the actor reads it from
 *       `auth()->id()` — the pattern `AuditShareholderEvent` uses
 *       for its four events.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously. See the
 *       class docblock §"No queue interface" for the reasoning.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernanceBodyService` (G2a file 5 of
 * 17) from three methods:
 *
 *     update()      — after a general field update.
 *     deactivate()  — after setting `is_active = false`.
 *     reactivate()  — after setting `is_active = true`.
 *
 * Each dispatch is wrapped in `DB::afterCommit()`, so the event
 * fires only if the transaction commits. If the transaction rolls
 * back — because a validation failed after the model was
 * modified, or because of a database error — the event never
 * fires.
 *
 * The event fires ONCE per successful mutation. A service call that
 * modifies two columns of the same row dispatches one event, not
 * one per column. A caller that invokes `deactivate()` and then
 * `reactivate()` as separate service calls dispatches two events.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * The three-source dispatch — why one event class, not three
 * =============================================================================
 * The alternative design is three event classes:
 *
 *     GovernanceBodyUpdated
 *     GovernanceBodyDeactivated
 *     GovernanceBodyReactivated
 *
 * That design is rejected because:
 *
 *   · Deactivation and reactivation are FIELD CHANGES (`is_active`
 *     flipping from one boolean value to the other), not distinct
 *     business operations. They share the same audit action slug
 *     convention and the same listener behaviour as a general
 *     update.
 *
 *   · Three event classes would require three listener
 *     registrations in the provider (G2a file 15) and three
 *     branches in the listener (G2a file 9) — all for facts that
 *     are already expressible by the one event plus the change
 *     set.
 *
 *   · A future CR that adds a new state-changing method to the
 *     service (e.g. a `moveToParent()` operation) dispatches the
 *     same event without a new registration. The audit trail
 *     records the fact; the change set records the specifics.
 *
 * The one-event design is the platform's convention. The
 * Shareholder domain's `ShareholderService` dispatches a single
 * `ShareholderUpdated` event from `update()`, `deactivate()`,
 * `reactivate()`, `assignRepresentative()`, and
 * `clearRepresentative()` — five methods, one event, five
 * derivable change kinds.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceBody` model as a public
 * readonly property. A listener that needs the diff reads
 * `$event->body->getChanges()`; a listener that needs a specific
 * attribute reads it directly. A listener that needs a relation
 * loads it lazily.
 *
 * The alternative — carrying only scalar primitives (id, code,
 * tenant_id) — would force every listener to re-query the row,
 * defeating the purpose of dispatching the event after the commit
 * that updated it. The model is the natural unit of interest, and
 * it carries both the pre-update and post-update state.
 *
 * This is the platform's convention. See `ShareholderUpdated`,
 * `WalletAdjusted`, and `LoanRestructured` for the same pattern.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$body` is `public readonly`. A listener MUST NOT attempt to
 * mutate the body through this reference; mutations belong to the
 * service layer, and any write from a listener would run outside
 * the service's transaction boundary.
 *
 * The `readonly` modifier enforces this at the language level: PHP
 * raises an `Error` on any attempted reassignment of the property
 * binding. The model instance itself remains mutable by its own
 * API, because PHP's `readonly` applies to the property binding,
 * not to the object's internal state. Listeners are expected to
 * observe, not to mutate; if a future requirement demands a
 * post-update side effect that writes, the correct pattern is for
 * the listener to call a service — not to edit the event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point and integrates with Laravel's queued-event machinery. G2a
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future CR makes the audit listener queued, the `Dispatchable`
 * trait's `SerializesModels` behaviour applies automatically to the
 * `$body` property. The model will be serialized as an identifier
 * on dispatch and rehydrated on the queue worker. That CR would also
 * need to decide what the listener does if the body has been
 * soft-deleted or hard-deleted by the time the queued job runs; the
 * current synchronous dispatch sidesteps that question entirely.
 *
 * Note that a queued `GovernanceBodyUpdated` would also need to
 * preserve the change set across the queue boundary, because
 * `getChanges()` and `getOriginal()` only make sense on the model
 * instance the service mutated. The `SerializesModels` trait
 * rehydrates the model from the database, at which point
 * `getChanges()` returns an empty array — the pre-update state is
 * gone. A future CR that makes this event queueable must
 * consequently extract the diff into the event itself before
 * dispatch. This is a substantial design change and is out of scope
 * for the current synchronous dispatch model.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2a file 15 of 17):
 *
 *     Event::listen(
 *         GovernanceBodyUpdated::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (`AuditGovernanceEvent`, G2a file 9 of 17) branches
 * on the event class via a `match (true)` expression and writes an
 * audit row through the Core `AuditService` with the action slug
 * `governance.body.updated`.
 *
 * A listener MUST NOT propagate exceptions to the caller. The
 * `AuditGovernanceEvent` listener catches `Throwable`, logs it, and
 * returns — the fail-open pattern every audit listener in the
 * platform uses.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design: the audit trail must be durable before
 * the API returns success to the caller. The additional
 * complication described in the §"Serialization" section above —
 * that `getChanges()` does not survive a queue round-trip — is a
 * second, independent reason to keep the event synchronous.
 *
 * This mirrors `ShareholderUpdated`, `WalletAdjusted`, and every
 * other domain event in the platform.
 *
 * =============================================================================
 * The convenience accessors
 * =============================================================================
 * Two accessors reduce boilerplate in the listener (G2a file 9) and
 * in any future listener:
 *
 *   `bodyId()`
 *       Returns the primary key of the updated body as an int.
 *       Equivalent to `$event->body->id`, but expresses intent at
 *       the call site. Used by the audit listener to populate the
 *       `resource_id` column of the audit row.
 *
 *   `tenantId()`
 *       Returns the body's owning tenant id as a nullable int. The
 *       value is read from the model's `tenant_id` attribute,
 *       which is a non-nullable integer column on
 *       `governance_bodies`. The `?int` return type reflects the
 *       theoretical case where the attribute is null. In normal
 *       service flow, `tenant_id` is always set.
 *
 * The two accessors are the same pair `GovernanceBodyRegistered`
 * (G2a file 7) provides, byte-for-byte identical in behaviour.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceBodyUpdated
{
    use Dispatchable;

    /**
     * The updated governance body.
     *
     * The same instance the service modified and persisted. It
     * carries the post-update attribute values and — critically —
     * retains its pre-update values in `getOriginal()` and the
     * diff in `getChanges()` until the model is refreshed or
     * reloaded.
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
     * The constructor takes the persisted `GovernanceBody` instance
     * as the service left it — with the diff still accessible via
     * `getOriginal()` and `getChanges()`. The constructor performs
     * no validation and no side effects; the service has already
     * established every invariant that governs the update before
     * reaching this point.
     *
     * @param GovernanceBody $body  The updated, committed row.
     */
    public function __construct(GovernanceBody $body)
    {
        $this->body = $body;
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