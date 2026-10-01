<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 7 of 17
 * =============================================================================
 *
 * File:            Events/GovernanceBodyRegistered.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceBodyService::register()` (G2a
 *   file 5 of 17) already referenced the class by name inside its
 *   after-commit closure:
 *
 *       $this->dispatchAfterCommit(new GovernanceBodyRegistered($body));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because the service's after-commit closure never
 *   ran during the deployment window (no live caller invoked
 *   `register()`), the missing file was inert. But the moment any
 *   caller — a controller (G2a file 14), an Artisan command, a
 *   seeder, or a test — invoked `GovernanceBodyService::register()`,
 *   the after-commit closure would attempt to instantiate a
 *   non-existent class and fail with a `Error: Class not found`,
 *   after the row had already been committed to the database.
 *
 * After:
 *   One file that declares the `GovernanceBodyRegistered` domain
 *   event — the class that carries the fact "a governance body was
 *   registered" from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The created `GovernanceBody` model, as a `public readonly`
 *       property. The model is the fully-hydrated instance the
 *       service just persisted and refreshed — not a snapshot,
 *       not a fresh query, not a scalar id.
 *
 *   Two convenience accessors:
 *
 *     · `bodyId(): int`    — the primary key of the registered body.
 *     · `tenantId(): ?int` — the body's owning tenant, resolved
 *                            from the model's `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does (a future CR), the
 *       actor will be added as an explicit property on the event.
 *       Until then, a listener that needs the actor reads it from
 *       `auth()->id()` — the pattern `AuditShareholderEvent` uses
 *       for its four events.
 *
 *     · A change diff. Registration is a creation; there is no
 *       prior state to compare against. The listener that writes
 *       the audit row records the full post-creation snapshot as
 *       `newValues`. `GovernanceBodyUpdated` (G2a file 8) is the
 *       event that carries a diff.
 *
 *     · The tenant id as a scalar. The tenant is available on the
 *       model (`$event->body->tenant_id`) and via the convenience
 *       accessor (`$event->tenantId()`). Duplicating it as a
 *       property would create a second source of truth.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously. See the
 *       class docblock §"No queue interface" for the reasoning.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernanceBodyService::register()`
 * (G2a file 5 of 17) inside a `DB::afterCommit()` callback. It
 * therefore fires:
 *
 *   · Only after the transaction that created the governance body
 *     has committed. If the transaction rolls back — because a
 *     validation failed after the model was created, or because
 *     of a database error — the callback is discarded and no
 *     listener sees a body that does not exist.
 *
 *   · Before the HTTP response is sent. The dispatch is
 *     synchronous: the registered listener (G2a file 9,
 *     `AuditGovernanceEvent`) runs in the same request, writes its
 *     audit row, and returns before the controller (G2a file 14)
 *     produces its response.
 *
 *   · Only once per registration. A service call that creates one
 *     body dispatches exactly one event. A caller that invokes
 *     `register()` twice dispatches two events for two distinct
 *     bodies.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely. This is the operator's switch for
 * suppressing the audit trail during a maintenance window. The
 * event class itself is unaffected by the flag; the flag controls
 * whether the service dispatches it.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceBody` model as a public
 * readonly property. A listener that needs only the id reads
 * `$event->body->id`; a listener that needs the code, the type, the
 * tenant, or any other attribute reads it directly. A listener that
 * needs a relation — the parent, the tenant record — reads it via
 * `$event->body->parent` or `$event->body->tenant`, loading lazily
 * if the relation was not eager-loaded.
 *
 * The alternative — carrying only scalar primitives (id, code,
 * tenant_id) — would force every listener to re-query the row,
 * defeating the purpose of dispatching the event after the commit
 * that created it. The model is the natural unit of interest.
 *
 * This is the platform's convention: every event in the Shareholder,
 * Wallet, and Loan domains carries the model (or the models)
 * relevant to the fact it records. See `ShareholderRegistered`,
 * `WalletOpened`, and `LoanApplicationSubmitted` for the same
 * pattern.
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
 * binding. Note that the model instance itself remains mutable by
 * its own API (`fill()`, `save()`); PHP's `readonly` applies to the
 * property binding, not to the object's internal state. Listeners
 * are expected to observe, not to mutate. If a future requirement
 * demands a post-registration side effect that writes, the correct
 * pattern is for the listener to call a service — not to edit the
 * event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point and integrates with Laravel's queued-event machinery. G2a
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future CR makes the audit listener queued — for example, to
 * move audit writes off the request path — the `Dispatchable`
 * trait's `SerializesModels` behaviour applies automatically to the
 * `$body` property. The model will be serialized as an identifier
 * on dispatch and rehydrated on the queue worker. That CR would also
 * need to decide what the listener does if the body has been
 * soft-deleted or hard-deleted by the time the queued job runs; the
 * current synchronous dispatch sidesteps that question entirely.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2a file 15 of 17):
 *
 *     Event::listen(
 *         GovernanceBodyRegistered::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (`AuditGovernanceEvent`, G2a file 9 of 17) branches
 * on the event class via a `match (true)` expression and writes an
 * audit row through the Core `AuditService` with the action slug
 * `governance.body.registered`.
 *
 * A listener MUST NOT propagate exceptions to the caller. The
 * `AuditGovernanceEvent` listener catches `Throwable`, logs it, and
 * returns — the fail-open pattern every audit listener in the
 * platform uses. If a future listener is added that cannot fail
 * open, it must not be registered on this event; the pattern is
 * reserved for listeners whose failure must not break the
 * business operation.
 *
 * =============================================================================
 * No queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are
 * synchronous by design: the audit trail must be durable before the
 * API returns success to the caller. If a future phase requires
 * asynchronous audit — for example, for high-throughput
 * registration — the event will implement `ShouldQueue` and the
 * listener will need to decide how to handle a body that has been
 * deleted between dispatch and processing. That is a CR-worthy
 * change; the current design is synchronous.
 *
 * This mirrors `ShareholderRegistered`, `WalletOpened`, and every
 * other domain event in the platform.
 *
 * =============================================================================
 * The convenience accessors
 * =============================================================================
 * Two accessors reduce boilerplate in the listener (G2a file 9) and
 * in any future listener:
 *
 *   `bodyId()`
 *       Returns the primary key of the registered body as an int.
 *       Equivalent to `$event->body->id`, but expresses intent at
 *       the call site and tolerates a caller that has not yet typed
 *       the property. Used by the audit listener to populate the
 *       `resource_id` column of the audit row.
 *
 *   `tenantId()`
 *       Returns the body's owning tenant id as a nullable int. The
 *       value is read from the model's `tenant_id` attribute, which
 *       is a non-nullable integer column on `governance_bodies`.
 *       The `?int` return type reflects the theoretical case where
 *       the attribute is null — for example, if a caller constructs
 *       an event manually with an unsaved model. In normal service
 *       flow, `tenant_id` is always set.
 *
 * The two accessors are the same pair `ShareholderRegistered` and
 * `CertificateIssued` provide. They are not required; a listener
 * can read the model's attributes directly. They are provided
 * because a listener that reads three or four scalars from the
 * model benefits from having named accessors that make the intent
 * self-describing.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody;

/**
 * GovernanceBodyRegistered
 *
 * Domain event dispatched when a governance body is registered.
 *
 * See the file-level change-verification block for the full rationale
 * on when the event fires, what it carries, what it deliberately
 * omits, and why it is dispatched synchronously rather than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernanceBodyRegistered
{
    use Dispatchable;

    /**
     * The registered governance body.
     *
     * Hydrated and persisted at the time the event is constructed.
     * The service has already run `$body->refresh()` (implicitly,
     * via `->create()` returning the freshly-inserted model) before
     * this event is instantiated, so every attribute reflects the
     * row as committed.
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
     * The constructor takes the persisted `GovernanceBody` instance.
     * It performs no validation and no side effects; the service has
     * already established every invariant that governs registration
     * before reaching this point.
     *
     * @param GovernanceBody $body  The newly-created, committed row.
     */
    public function __construct(GovernanceBody $body)
    {
        $this->body = $body;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance body.
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
     * The tenant id under which the governance body was registered.
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