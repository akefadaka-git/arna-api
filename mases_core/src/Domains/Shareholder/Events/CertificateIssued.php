<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareCertificate;

/**
 * CertificateIssued
 *
 * Domain event dispatched when a share certificate is issued.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * CertificateService::issue() (2A.15) dispatches this event after the
 * transaction that created the certificate has committed, via
 * `DB::afterCommit()`. Two call sites produce it:
 *
 *   - The `store()` action of CertificateController (2A.24), when a caller
 *     issues a new certificate through POST /api/shareholders/certificates.
 *
 *   - The `replace()` method of CertificateService, which creates a new
 *     certificate and marks the original as replaced in the same
 *     transaction. The new certificate is the subject of the event; the
 *     linkage from the original to the new one is recorded on the original
 *     row via `replaced_by_id` and can be queried by any interested
 *     listener.
 *
 * `cancel()` does NOT dispatch a domain event in Phase 2A — see the
 * class docblock of CertificateService, section "Event dispatch", for the
 * reasoning and the future Change Request that would add
 * CertificateCancelled.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `ShareCertificate` model as a public readonly
 * property. A listener that needs only the id reads `$event->certificate->id`;
 * a listener that needs the shareholder, share class, holding, or status
 * reads the corresponding attribute or calls `load()`/`loadMissing()` on
 * the model as needed.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event after commit. Per the Event convention (§2.9), constructor
 * parameters are models or scalar primitives, never arrays of arrays; the
 * model is the natural unit of interest here.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$certificate` is `public readonly`. A listener MUST NOT attempt to
 * mutate the certificate through this reference; mutations belong to the
 * service layer, and any write from a listener would run outside the
 * service's transaction boundary. The `readonly` modifier enforces this at
 * the language level: PHP raises an `Error` on any attempted reassignment.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-issuance side effect that
 * writes, the correct pattern is for the listener to call a service, not
 * to edit the event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2A dispatches
 * this event synchronously through the synchronous Event dispatcher; the
 * event is not queued. If a future CR makes the audit listener queued, the
 * trait's `SerializesModels` behaviour applies automatically to the
 * `$certificate` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in ShareholderServiceProvider (2A.2):
 *
 *     Event::listen(
 *         CertificateIssued::class,
 *         AuditShareholderEvent::class,
 *     );
 *
 * The listener, AuditShareholderEvent (2A.35), branches on the event class
 * via `match (true)` and writes an audit row through the Core AuditService.
 * A listener MUST NOT propagate exceptions to the caller; the AuditShareholderEvent
 * listener catches `Throwable` and logs, per the Listener convention (§2.10).
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Events
 * @version 1.0.0
 */
final class CertificateIssued
{
    use Dispatchable;

    /**
     * The certificate that was issued.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$certificate->refresh()` before this event
     * is instantiated, so every attribute reflects the row as committed.
     *
     * @var ShareCertificate
     */
    public readonly ShareCertificate $certificate;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `ShareCertificate` instance. It
     * performs no validation and no side effects; the service has already
     * established every invariant that governs issuance before reaching
     * this point.
     *
     * @param ShareCertificate $certificate  The newly-issued certificate.
     */
    public function __construct(ShareCertificate $certificate)
    {
        $this->certificate = $certificate;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the issued certificate.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->certificate->id`, but expresses intent at the
     * call site and tolerates a caller that has not yet typed the property.
     *
     * @return int
     */
    public function certificateId(): int
    {
        return (int) $this->certificate->getKey();
    }

    /**
     * The tenant id under which the certificate was issued.
     *
     * Resolved through the certificate's shareholder, because a certificate
     * does not carry `tenant_id` directly — tenant scoping is derived from
     * the shareholder, per the domain's data model.
     *
     * Returns `null` if the shareholder relation is not loaded and cannot
     * be resolved. Listeners that need the value reliably should load the
     * relation first (`$event->certificate->loadMissing('shareholder')`)
     * or call this accessor and handle the null case.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $shareholder = $this->certificate->shareholder;

        if ($shareholder === null) {
            return null;
        }

        return (int) $shareholder->tenant_id;
    }
}