<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 22 of 27
 * =============================================================================
 *
 * File:            Events/GovernanceTermRegistered.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (event)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. `GovernanceTermService::register()` (G2b
 *   file 16 of 27) already referenced the class by name inside its
 *   after-commit closure:
 *
 *       $this->dispatchAfterCommit(new GovernanceTermRegistered($term));
 *
 *   The class reference resolved at compile time as an import alias
 *   only; PHP does not load the class file until the `new` expression
 *   actually runs. Because no live caller invoked `register()` during
 *   the deployment window (the routes amendment is file 27 of 27,
 *   delivered last), the missing file was inert. But the moment any
 *   caller invoked `GovernanceTermService::register()`, the
 *   after-commit closure would attempt to instantiate a non-existent
 *   class and fail with a `Error: Class not found`, after the row had
 *   already been committed.
 *
 * After:
 *   One file that declares the `GovernanceTermRegistered` domain event
 *   — the class that carries the fact "a governance term was
 *   registered" from the service layer to any listener.
 *
 *   What the event carries:
 *
 *     · The created `GovernanceTerm` model, as a `public readonly`
 *       property. The model is the fully-hydrated instance the
 *       service just persisted and refreshed.
 *
 *   Two convenience accessors:
 *
 *     · `termId(): int`    — the primary key of the registered term.
 *     · `tenantId(): ?int` — the term's owning tenant, resolved from
 *                            the model's `tenant_id` attribute.
 *
 *   What the event deliberately does NOT carry:
 *
 *     · The body's id as a separate scalar. The value is available on
 *       the model (`$event->term->governance_body_id`).
 *
 *     · The actor's user id. The service does not currently accept an
 *       actor as an argument; when it does (a future CR), the actor
 *       will be added as an explicit property on the event.
 *
 *     · A change diff. Registration is a creation; there is no prior
 *       state to compare against.
 *
 *     · Serialization metadata. The event does not implement
 *       `ShouldQueue`; it is dispatched synchronously.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `GovernanceTermService::register()` (G2b
 * file 16 of 27) inside a `DB::afterCommit()` callback. It therefore
 * fires:
 *
 *   · Only after the transaction that created the term has committed.
 *
 *   · Before the HTTP response is sent. The dispatch is synchronous:
 *     the registered listener (G2b file 25 of 27, the amended
 *     `AuditGovernanceEvent`) runs in the same request, writes its
 *     audit row, and returns before the controller produces its
 *     response.
 *
 *   · Only once per registration.
 *
 * If `config('governance.audit.emit_events')` is false, the service
 * skips the dispatch entirely.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `GovernanceTerm` model as a public
 * readonly property. A listener that needs only the id reads
 * `$event->term->id`; a listener that needs the label, the body id,
 * the tenant id, or the effective dates reads them directly.
 *
 * The alternative — carrying only scalar primitives — would force
 * every listener to re-query the row. The model is the natural unit
 * of interest, matching the platform's convention
 * (`GovernanceBodyRegistered`, `GovernancePositionRegistered`, …).
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$term` is `public readonly`. A listener MUST NOT attempt to mutate
 * the term through this reference; mutations belong to the service
 * layer, and any write from a listener would run outside the
 * service's transaction boundary.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry
 * point and integrates with Laravel's queued-event machinery. G2b
 * dispatches this event synchronously; the event is not queued.
 *
 * If a future CR makes the audit listener queued, the trait's
 * `SerializesModels` behaviour applies automatically to the `$term`
 * property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * The event is wired to its listener by the amended
 * `GovernanceServiceProvider` (G2b file 26 of 27):
 *
 *     Event::listen(
 *         GovernanceTermRegistered::class,
 *         AuditGovernanceEvent::class,
 *     );
 *
 * The listener (amended by G2b file 25 of 27) branches on the event
 * class via a `match (true)` expression and writes an audit row
 * through the Core `AuditService` with the action slug
 * `governance.term.registered`.
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