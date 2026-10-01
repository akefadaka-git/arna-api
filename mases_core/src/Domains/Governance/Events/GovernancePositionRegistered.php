<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 10 of 27
 * =============================================================================
 *
 * File:            Events/GovernancePositionRegistered.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernancePositionService::register()`
 *   (G2b file 4 of 27) already referenced the class by name inside
 *   its after-commit closure:
 *
 *       $this->dispatchAfterCommit(new GovernancePositionRegistered($position));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked `register()`
 *   during the deployment window (the routes amendment is file 27
 *   of 27, delivered last), the missing file was inert. But the
 *   moment any caller — the controller (G2b file 9 of 27), an
 *   Artisan command, a seeder, or a test — invoked
 *   `GovernancePositionService::register()`, the after-commit
 *   closure would attempt to instantiate a non-existent class and
 *   fail with a `Error: Class not found`, after the row had already
 *   been committed.
 *
 * After:
 *   One file that declares the `GovernancePositionRegistered` domain
 *   event — the class that carries the fact "a governance position
 *   was registered" from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The created `GovernancePosition` model, as a
 *       `public readonly` property. The model is the fully-hydrated
 *       instance the service just persisted and refreshed.
 *
 *   Two convenience accessors:
 *
 *     · `positionId(): int`  — the primary key of the registered
 *                              position.
 *     · `tenantId(): ?int`   — the position's owning tenant,
 *                              resolved from the model's
 *                              `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · The body's id as a separate scalar. The value is available
 *       on the model (`$event->position->governance_body_id`).
 *       Duplicating it as a property would create a second source
 *       of truth.
 *
 *     · The actor's user id. The service does not currently accept
 *       an actor as an argument; when it does (a future CR), the
 *       actor will be added as an explicit property on the event.
 *       Until then, a listener that needs the actor reads it from
 *       `auth()->id()`.
 *
 *     · A change diff. Registration is a creation; there is no
 *       prior state to compare against.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernancePositionService::register()`
 * (G2b file 4 of 27) inside a `DB::afterCommit()` callback. It
 * therefore fires:
 *
 *   · Only after the transaction that created the position has
 *     committed. If the transaction rolls back, the callback is
 *     discarded and no listener sees a position that does not
 *     exist.
 *
 *   · Before the HTTP response is sent. The dispatch is
 *     synchronous: the registered listener (G2b file 25 of 27, the
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
 * The event exposes the full `GovernancePosition` model as a public
 * readonly property. A listener that needs only the id reads
 * `$event->position->id`; a listener that needs the code, the
 * body id, the tenant id, or any other attribute reads it directly.
 * A listener that needs a relation — the owning body — reads it
 * via `$event->position->body`, loading lazily if the relation was
 * not eager-loaded.
 *
 * The alternative — carrying only scalar primitives — would force
 * every listener to re-query the row. The model is the natural unit
 * of interest, matching the platform's convention
 * (`GovernanceBodyRegistered`, `ShareholderRegistered`, …).
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$position` is `public readonly`. A listener MUST NOT attempt to
 * mutate the position through this reference; mutations belong to
 * the service layer, and any write from a listener would run
 * outside the service's transaction boundary.
 *
 * The `readonly` modifier enforces this at the language level.
 * The model instance itself remains mutable by its own API
 * (`fill()`, `save()`); PHP's `readonly` applies to the property
 * binding, not to the object's internal state.
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
 * `$position` property. That CR would also need to decide what the
 * listener does if the position has been soft-deleted or
 * hard-deleted by the time the queued job runs.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27):
 *
 *     Event::listen(
 *         GovernancePositionRegistered::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2b file 25 of 27) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.position.registered`.
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
use MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition;

/**
 * GovernancePositionRegistered
 *
 * Domain event dispatched when a governance position is registered.
 *
 * See the file-level change-verification block for the full
 * rationale on when the event fires, what it carries, what it
 * deliberately omits, and why it is dispatched synchronously rather
 * than queued.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Events
 * @version 1.0.0
 */
final class GovernancePositionRegistered
{
    use Dispatchable;

    /**
     * The registered governance position.
     *
     * Hydrated and persisted at the time the event is constructed.
     * Every attribute reflects the row as committed.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var GovernancePosition
     */
    public readonly GovernancePosition $position;

    /**
     * Create a new event instance.
     *
     * @param GovernancePosition $position  The newly-created, committed row.
     */
    public function __construct(GovernancePosition $position)
    {
        $this->position = $position;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the registered governance position.
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
     * The tenant id under which the governance position was
     * registered.
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