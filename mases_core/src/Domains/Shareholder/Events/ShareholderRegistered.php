<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareholderRegistered
 *
 * Domain event dispatched after a new Shareholder row is committed.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by ShareholderService::register() (2A.12) inside
 * a `DB::afterCommit()` callback. It therefore fires:
 *
 *   - Only after the transaction that created the shareholder has
 *     committed. If the transaction rolls back, the event never fires and
 *     no listener sees a shareholder that does not exist.
 *
 *   - Before the HTTP response is sent. The dispatch is synchronous: the
 *     registered listener (AuditShareholderEvent, 2A.35) runs in the same
 *     request, writes its audit entry, and returns before the controller
 *     produces its response.
 *
 * If the `config('shareholder.audit.emit_events')` flag is false, the
 * service skips the dispatch entirely. This is the operator's switch for
 * suppressing the audit trail during a maintenance window.
 *
 * =============================================================================
 * What this event carries
 * =============================================================================
 * A single readonly property: the persisted Shareholder model.
 *
 * The model is the FULL Eloquent model, not a snapshot. A listener can
 * read every attribute, resolve relations (lazily, at the cost of a
 * query), and inspect timestamps. Nothing is pre-extracted into the event
 * payload, because a pre-extracted value can drift from the row if the
 * row is updated before the listener runs — an unlikely scenario in a
 * synchronous dispatch, but one that is trivially avoided by carrying the
 * model itself.
 *
 * The model is not a fresh copy — it is the same instance the service
 * persisted. A listener that mutates the model (which is discouraged but
 * possible) would affect subsequent listeners. Listeners should treat the
 * model as read-only.
 *
 * =============================================================================
 * What this event does NOT carry
 * =============================================================================
 *   - The tenant id. Read it from `$event->shareholder->tenant_id`.
 *   - The shareholder type. Read it from `$event->shareholder->shareholder_type`
 *     or call `$event->shareholder->type()`.
 *   - The actor (the user who performed the registration). At the time of
 *     dispatch the actor is available via `auth()->id()`. If the business
 *     requires the actor to be recorded explicitly (e.g. for a non-HTTP
 *     import), a CR adds an `actorId` parameter. See F-2A31-2.
 *   - Any "before" state. Registration is a creation; there is no prior
 *     state.
 *
 * =============================================================================
 * Why no queue interface
 * =============================================================================
 * The event does not implement `ShouldQueue`. Audit writes are synchronous
 * by design: the audit trail must be durable before the API returns
 * success to the caller. If a future phase requires asynchronous audit
 * (e.g. for high-throughput ingestion), the event must implement
 * `ShouldQueue` and the model must either use `SerializesModels` or the
 * event must carry scalar identifiers. That is a CR-worthy change and is
 * recorded as F-2A31-1.
 *
 * =============================================================================
 * Consumer
 * =============================================================================
 * The only registered listener in Phase 2A is `AuditShareholderEvent`
 * (2A.35), which writes a row to the platform `audit_logs` table via the
 * Core `AuditService`. That listener distinguishes the four domain events
 * (ShareholderRegistered, ShareholderUpdated, ShareHoldingChanged,
 * CertificateIssued) by their class.
 *
 * Additional listeners can be added by a future CR without modifying
 * this file: the event class is immutable and its payload is the model.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Events
 */
final class ShareholderRegistered
{
    use Dispatchable;

    /**
     * The persisted shareholder.
     *
     * Read-only. Listeners MUST treat the model as immutable — any
     * modification is visible to subsequent listeners and to the caller.
     * If a listener needs a modified copy, it should clone.
     *
     * @var Shareholder
     */
    public readonly Shareholder $shareholder;

    /**
     * Create a new event instance.
     *
     * @param Shareholder $shareholder  The newly-created, committed row.
     */
    public function __construct(Shareholder $shareholder)
    {
        $this->shareholder = $shareholder;
    }
}