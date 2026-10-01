<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\CertificateIssued;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareHoldingChanged;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderRegistered;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareholderUpdated;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use Throwable;

/**
 * AuditShareholderEvent
 *
 * Sole listener for the four Shareholder domain events. Writes a row to
 * the platform `audit_logs` table via the Core AuditService.
 *
 * =============================================================================
 * Events handled
 * =============================================================================
 *   ShareholderRegistered  →  action `shareholder.registered`
 *   ShareholderUpdated     →  action `shareholder.updated`
 *   ShareHoldingChanged    →  action `share_holding.changed`
 *   CertificateIssued      →  action `certificate.issued`
 *
 * All four events are dispatched by the domain's services (2A.12, 2A.14,
 * 2A.15) inside `DB::afterCommit(...)` callbacks, so a listener runs only
 * after the corresponding transaction has committed. This listener is
 * registered once per event by ShareholderServiceProvider (2A.2); the
 * framework routes each dispatched event to the same `handle()` method.
 *
 * =============================================================================
 * Action slug vocabulary
 * =============================================================================
 * Each event maps to a distinct action slug. The slugs are English
 * identifiers used by audit reviewers and reporting tools; they are NOT
 * user-facing strings. The vocabulary is deliberately stable:
 *
 *   shareholder.registered
 *   shareholder.updated
 *   share_holding.changed
 *   certificate.issued
 *
 * Changing a slug would break historical audit queries and is a CR-worthy
 * change.
 *
 * =============================================================================
 * old_values / new_values
 * =============================================================================
 * For creation-like events (registration, ledger entry, certificate
 * issuance), `old_values` is null and `new_values` is a snapshot of the
 * newly-created resource's key fields.
 *
 * For updates (`ShareholderUpdated`), both `old_values` and `new_values`
 * are populated from the model's change tracking:
 *
 *   old_values  —  the subset of `getOriginal()` corresponding to keys
 *                  present in `getChanges()`.
 *   new_values  —  `getChanges()`, the exact set of attributes that
 *                  differed before and after the update.
 *
 * The change-set approach means an update that touches only the
 * `is_active` flag produces an audit row whose `new_values` contains only
 * `is_active`. This keeps the audit trail precise and avoids recording
 * unchanged columns as if they had moved.
 *
 * If a caller invokes an update service method that results in a no-op
 * (the model's `getChanges()` is empty), the listener still writes an
 * audit row with empty `old_values` and `new_values`. See F-2A32-3 for
 * the rationale and the future CR that could suppress such rows.
 *
 * =============================================================================
 * Tenant resolution
 * =============================================================================
 * Each event's tenant context is derived from the affected resource:
 *
 *   ShareholderRegistered  →  shareholder.tenant_id
 *   ShareholderUpdated     →  shareholder.tenant_id
 *   ShareHoldingChanged    →  entry.shareHolding.shareholder.tenant_id
 *   CertificateIssued      →  certificate.shareholder.tenant_id
 *
 * The relation traversal in the last two cases may trigger a lazy load if
 * the chain was not eager-loaded by the service. This is acceptable — the
 * listener runs once per write, and the extra query cost is bounded.
 * AuditService is passed a concrete `$tenantId` in every call, so it does
 * not fall back to the request's tenant context (which could be null in a
 * command or test context).
 *
 * =============================================================================
 * Actor resolution
 * =============================================================================
 * The event does not carry the actor (F-2A31-2). The listener reads
 * `auth()->id()` at dispatch time. In an HTTP request this is the
 * authenticated caller; in an Artisan command or a test with no
 * authenticated user it is null, and the audit row is attributed to the
 * system.
 *
 * If a future CR requires an explicit actor on the event (e.g. a bulk
 * import attributing each row to a supervisor), the event constructor
 * gains an `?int $actorId` parameter and this listener reads it instead
 * of `auth()->id()`.
 *
 * =============================================================================
 * Fail-open semantics
 * =============================================================================
 * Every `handle()` call is wrapped in `try` / `catch (Throwable)`. Any
 * exception raised while writing the audit row is caught, logged via
 * `Log::error`, and swallowed. The domain operation that triggered the
 * event has already committed — the caller has already received (or will
 * imminently receive) a successful response. An audit-write failure must
 * not turn a successful business operation into a 500.
 *
 * This is the same fail-open policy used by the Phase 1 audit listeners
 * (`LogSuccessfulLogin`, `LogFailedLogin`, `LogLogout`). If the audit
 * table is unavailable, the fact of the operation is still durably
 * recorded in the domain tables; the audit trail simply lags until the
 * next successful write. A future phase can add a retry queue if the
 * business requires stronger delivery guarantees for audit writes.
 *
 * =============================================================================
 * No database queries beyond what the events require
 * =============================================================================
 * The listener performs no direct DB queries. It reads attributes from
 * the model instances carried by the events and, when a tenant id must be
 * traversed via relations, relies on the model's relation methods — each
 * of which may issue at most one query if the relation is not already
 * loaded.
 *
 * =============================================================================
 * Amharic-first
 * =============================================================================
 * The listener emits no user-facing strings. Action slugs and field keys
 * are English identifiers used by audit tooling. The values it records
 * are raw column values — bilingual name fields are stored under their
 * own column names (`first_name_am`, `first_name_en`, etc.) so the audit
 * trail preserves both languages as the schema does.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Listeners
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