<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Shareholder\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MaaleArna\Arna\Core\Audit\Services\AuditService;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\CertificateIssued;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareHoldingChanged;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareholderRegistered;
use MaaleArna\Arna\Core\Domains\Shareholder\Events\ShareholderUpdated;
use MaaleArna\Arna\Core\Domains\Shareholder\Models\Shareholder;
use Throwable;

/**
 * Writes shareholder-domain events to audit_logs after commit through AuditService; retain tenant attribution and sensitive-field filtering.
 *
 * @package MaaleArna\Arna\Core\Domains\Shareholder\Listeners
 */
final class AuditShareholderEvent
{
    /**
     * Construct the listener with its audit dependency.
     *
     * AuditService is a Phase 1 singleton registered by CoreServiceProvider.
     * The container resolves it when the listener is instantiated by the
     * event dispatcher.
     *
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // Entry point
    // =========================================================================

    /**
     * Handle a dispatched domain event.
     *
     * Branches on the concrete event class. Unknown event types are
     * silently ignored — Laravel will not dispatch a class that was not
     * registered, so this branch is purely defensive.
     *
     * @param object $event
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            match (true) {
                $event instanceof ShareholderRegistered => $this->handleShareholderRegistered($event),
                $event instanceof ShareholderUpdated    => $this->handleShareholderUpdated($event),
                $event instanceof ShareHoldingChanged   => $this->handleShareHoldingChanged($event),
                $event instanceof CertificateIssued     => $this->handleCertificateIssued($event),
                default                                  => null,
            };
        } catch (Throwable $e) {
            // Fail-open. The domain transaction has already committed.
            // Record the failure in the application log; do not re-throw.
            Log::error('AuditShareholderEvent listener failed', [
                'event_class' => $event::class,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Event handlers
    // =========================================================================

    /**
     * Record a shareholder registration.
     *
     * @param ShareholderRegistered $event
     * @return void
     */
    private function handleShareholderRegistered(ShareholderRegistered $event): void
    {
        $shareholder = $event->shareholder;

        $this->auditService->log(
            action: 'shareholder.registered',
            data: [
                'source' => 'event-listener',
                'event'  => ShareholderRegistered::class,
            ],
            user: Auth::id(),
            tenantId: (int) $shareholder->tenant_id,
            resourceType: 'Shareholder',
            resourceId: (string) $shareholder->id,
            oldValues: null,
            newValues: $this->shareholderSnapshot($shareholder),
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a shareholder update.
     *
     * The old and new values are derived from the model's change tracking.
     * If the update was a no-op, both arrays are empty.
     *
     * @param ShareholderUpdated $event
     * @return void
     */
    private function handleShareholderUpdated(ShareholderUpdated $event): void
    {
        $shareholder = $event->shareholder;

        $changes = $shareholder->getChanges();
        $original = $shareholder->getOriginal();

        // Filter the original snapshot to only the keys that changed.
        $oldValues = [];
        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $original)) {
                $oldValues[$key] = $original[$key];
            }
        }

        $this->auditService->log(
            action: 'shareholder.updated',
            data: [
                'source' => 'event-listener',
                'event'  => ShareholderUpdated::class,
            ],
            user: Auth::id(),
            tenantId: (int) $shareholder->tenant_id,
            resourceType: 'Shareholder',
            resourceId: (string) $shareholder->id,
            oldValues: $oldValues,
            newValues: $changes,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a share-holding ledger entry.
     *
     * The audit row records the entry's identity, its signed quantity,
     * the before and after balances, and the reference metadata. These
     * four values are what a reviewer needs to reconstruct the change
     * without loading the holding.
     *
     * @param ShareHoldingChanged $event
     * @return void
     */
    private function handleShareHoldingChanged(ShareHoldingChanged $event): void
    {
        $entry   = $event->entry;
        $holding = $entry->shareHolding;

        // Resolve the tenant via the holding's shareholder. The service
        // (2A.14) may or may not have eager-loaded this chain; the lazy
        // load is bounded and acceptable.
        $tenantId = null;
        if ($holding !== null) {
            $holding->loadMissing('shareholder');
            $tenantId = $holding->shareholder?->tenant_id;
        }

        $this->auditService->log(
            action: 'share_holding.changed',
            data: [
                'source'         => 'event-listener',
                'event'          => ShareHoldingChanged::class,
                'entry_type'     => $entry->entry_type,
                'balance_before' => $event->balanceBefore,
                'balance_after'  => $event->balanceAfter,
            ],
            user: Auth::id(),
            tenantId: $tenantId !== null ? (int) $tenantId : null,
            resourceType: 'ShareHoldingEntry',
            resourceId: (string) $entry->id,
            oldValues: null,
            newValues: [
                'share_holding_id'  => $entry->share_holding_id,
                'entry_type'        => $entry->entry_type,
                'quantity'          => (string) $entry->quantity,
                'balance_before'    => (string) $entry->balance_before,
                'balance_after'     => (string) $entry->balance_after,
                'reference_type'    => $entry->reference_type,
                'reference_id'      => $entry->reference_id,
                'description'       => $entry->description,
                'occurred_at'       => $entry->occurred_at?->toIso8601String(),
                'reversed_entry_id' => $entry->reversed_entry_id,
            ],
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    /**
     * Record a certificate issuance.
     *
     * @param CertificateIssued $event
     * @return void
     */
    private function handleCertificateIssued(CertificateIssued $event): void
    {
        $certificate = $event->certificate;

        // Resolve the tenant via the certificate's shareholder.
        $certificate->loadMissing('shareholder');
        $tenantId = $certificate->shareholder?->tenant_id;

        $this->auditService->log(
            action: 'certificate.issued',
            data: [
                'source' => 'event-listener',
                'event'  => CertificateIssued::class,
            ],
            user: Auth::id(),
            tenantId: $tenantId !== null ? (int) $tenantId : null,
            resourceType: 'ShareCertificate',
            resourceId: (string) $certificate->id,
            oldValues: null,
            newValues: [
                'shareholder_id'     => $certificate->shareholder_id,
                'share_class_id'     => $certificate->share_class_id,
                'share_holding_id'   => $certificate->share_holding_id,
                'certificate_number' => $certificate->certificate_number,
                'status'             => $certificate->status->value,
                'issued_at'          => $certificate->issued_at?->toIso8601String(),
                'replaced_by_id'     => $certificate->replaced_by_id,
            ],
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            requestId: $this->resolveRequestId(),
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a snapshot of a shareholder's identity fields for auditing.
     *
     * The snapshot includes every column the audit trail needs to
     * reconstruct the registered identity without querying the row. It
     * deliberately excludes computed accessors (`full_name`, `name`,
     * `display_name`) because those are derived and would duplicate the
     * underlying columns.
     *
     * It also excludes any KYC-adjacent field — none exist on the
     * shareholders table in Phase 2A (D12 narrow-block), and the snapshot
     * would not include them if they did.
     *
     * @param Shareholder $shareholder
     * @return array<string, mixed>
     */
    private function shareholderSnapshot(Shareholder $shareholder): array
    {
        return [
            'tenant_id'              => $shareholder->tenant_id,
            'shareholder_type'       => $shareholder->shareholder_type,
            'first_name_am'          => $shareholder->first_name_am,
            'first_name_en'          => $shareholder->first_name_en,
            'father_name_am'         => $shareholder->father_name_am,
            'father_name_en'         => $shareholder->father_name_en,
            'grandfather_name_am'    => $shareholder->grandfather_name_am,
            'grandfather_name_en'    => $shareholder->grandfather_name_en,
            'entity_name_am'         => $shareholder->entity_name_am,
            'entity_name_en'         => $shareholder->entity_name_en,
            'user_id'                => $shareholder->user_id,
            'representative_user_id' => $shareholder->representative_user_id,
            'is_active'              => $shareholder->is_active,
        ];
    }

    /**
     * Resolve the request id for the current request, if available.
     *
     * Priority:
     *   1. `X-Request-ID` header (set by upstream proxies / clients).
     *   2. `X-Correlation-ID` header (alternative common name).
     *   3. A freshly generated UUID v4.
     *
     * Mirrors the resolution order used by the canonical error renderer
     * in `bootstrap/app.php` and by the Phase 1 audit listeners. This lets
     * an audit row and an error envelope from the same request share the
     * same `request_id`.
     *
     * @return string
     */
    private function resolveRequestId(): string
    {
        $request = request();

        foreach (['X-Request-ID', 'X-Correlation-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}