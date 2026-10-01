<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Listeners;

use Illuminate\Support\Facades\Log;
use LogicException;
use MaaleArna\MASES\Core\Audit\Services\AuditService;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationRecorded;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocated;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocationReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentReceived;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletAdjusted;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletEntryReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletOpened;
use Throwable;

/**
 * AuditWalletEvent
 *
 * The single audit listener for the Wallet domain's seven business events.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * Receives every event dispatched by the Wallet domain's services
 * (2B.40–2B.46) and writes one row to the platform `audit_logs` table via
 * the Core `AuditService`. The listener distinguishes the seven event
 * classes internally and maps each to a distinct `event_type` string.
 *
 * Registration is explicit rather than by Laravel's listener
 * auto-discovery, because the listener lives under the domain tree, not
 * under `app/Listeners/`. The seven `Event::listen(...)` calls live in
 * `WalletServiceProvider::registerEventListeners()` (2B.2).
 *
 * =============================================================================
 * The seven events and their event_type strings
 * =============================================================================
 * Each event maps to a single `event_type` string. The mapping is frozen
 * by Step 7 v1.1.0 §B.5 and mirrors the 2A naming convention
 * (`shareholder.registered`, `share_holding.changed`, `certificate.issued`):
 *
 *     WalletOpened                → 'wallet.opened'
 *     ObligationRecorded          → 'wallet.obligation.recorded'
 *     PaymentReceived             → 'wallet.payment.received'
 *     PaymentAllocated            → 'wallet.payment.allocated'
 *     PaymentAllocationReversed   → 'wallet.payment.allocation_reversed'
 *     WalletAdjusted              → 'wallet.adjusted'
 *     WalletEntryReversed         → 'wallet.entry.reversed'
 *
 * Adding an event is a Change Request: the event class, the event_type
 * string, and the corresponding arm of `handle()` and its private
 * handler are extended together.
 *
 * =============================================================================
 * Failure isolation — must never propagate (Step 7 v1.1.0 §B.4.3)
 * =============================================================================
 * The entire `handle()` body is wrapped in a `try/catch (Throwable)`. A
 * failure inside the audit write — a missing `audit_logs` table, an
 * `AuditService` misconfiguration, a malformed event payload, or any
 * unexpected exception — is logged at ERROR level via the framework's
 * `Log` facade and swallowed. The listener NEVER re-throws.
 *
 * The rationale: a wallet operation that succeeded must not report
 * failure because the audit sink was unavailable. The wallet's
 * `current_balance`, its ledger entries, its obligations, payments, and
 * allocations are all committed before the listener runs (the events are
 * dispatched via `DB::afterCommit()`, 2B.15–2B.19). By the time this
 * listener fires, the business transaction is a fait accompli. Logging
 * the audit failure preserves the operational record; propagating it
 * would corrupt the caller's view of a completed operation.
 *
 * This mirrors the discipline established by the 2A listener
 * `AuditShareholderEvent` (2A.35) and codified in the Listener
 * convention (§2.10).
 *
 * =============================================================================
 * Unrecognized events
 * =============================================================================
 * The `handle()` method's `match (true)` expression has a
 * `default` arm that throws `LogicException`. This is deliberate and
 * unreachable under normal operation: the listener is registered ONLY
 * for the seven classes listed above (2B.2 §"registerEventListeners"),
 * so no other event class can reach this code path.
 *
 * The `default` arm exists as a defensive guard: if a future Change
 * Request adds a new event to the registration without adding the
 * corresponding arm to `handle()`, the listener fails loudly (via the
 * ERROR log) rather than silently dropping the audit row. The
 * `LogicException` is caught by the same outer `try/catch` that protects
 * the audit write — its message is logged, and the caller is unaffected.
 *
 * =============================================================================
 * AuditService dependency
 * =============================================================================
 * The constructor injects the platform's `AuditService`, bound as a
 * singleton by `CoreServiceProvider` (Phase 1). The service is the SAME
 * one used by the 2A listener `AuditShareholderEvent` (2A.35), so
 * wallet-domain audit rows land in the same `audit_logs` table with the
 * same metadata shape as shareholder-domain rows.
 *
 * The call pattern is:
 *
 *     $this->auditService->log(
 *         eventType: 'wallet.opened',
 *         data:      [ ...domain-specific payload... ],
 *     );
 *
 * The `eventType` argument is the string discriminator; the `data`
 * argument is the domain payload. Request metadata (IP address, user
 * agent, request id, authenticated user id, tenant id) is captured by
 * the `AuditService` itself, not by this listener — see the Phase 1
 * audit infrastructure. If the deployed `AuditService` exposes a
 * different method name or signature, this listener requires a one-line
 * correction per call site and nothing more.
 *
 * =============================================================================
 * Payload content per event
 * =============================================================================
 * Each handler extracts the identity fields that a consumer of the audit
 * trail needs to reconstruct what happened. The fields are the same ones
 * the events' convenience accessors expose (2B.40–2B.46), so the payload
 * is a faithful subset of the event's public surface.
 *
 * No model objects are passed to the audit service. Only scalars and
 * nullables are. This keeps the audit payload serialization-friendly and
 * decouples the log row's shape from the domain's model classes.
 *
 * =============================================================================
 * What this listener deliberately does NOT do
 * =============================================================================
 *   - It does not evaluate, transform, or filter events. Every event it
 *     receives is written as-is.
 *
 *   - It does not enqueue the audit write. The listener is synchronous
 *     and runs on the same process as the dispatching service. Queuing
 *     the audit trail is a future CR; the current posture matches 2A.
 *
 *   - It does not retry failed writes. A failed write is logged and
 *     dropped; the caller is unaffected. Retry logic, if ever needed,
 *     belongs in the `AuditService` itself, not in this listener.
 *
 *   - It does not read the ledger or any other table. Its only data
 *     sources are the event's accessors and the `AuditService`.
 *
 *   - It does not gate on `config('wallet.audit.emit_events')`. That gate
 *     is applied by the services at DISPATCH time (2B.15 §"dispatchAfterCommit");
 *     if the config is false, the event is never dispatched and this
 *     listener is never invoked. The listener trusts that any event it
 *     receives was intentionally dispatched.
 *
 *   - It does not use the `HandlesEvents` interface or Laravel's
 *     listener auto-registration. The registration is explicit in the
 *     provider (2B.2 §"registerEventListeners").
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3      — Domain entities
 *   Architecture v1.2.0 §4      — Ledger model
 *   Architecture v1.2.0 §5      — Wallet transaction model
 *   Architecture v1.2.0 §14     — Audit integration
 *   Architecture v1.2.0 §14.1   — Event inventory (seven events)
 *   Architecture v1.2.0 §14.2   — Listener contract
 *   Architecture v1.2.0 §14.3   — Registration and gating
 *   Phase 1                      — AuditService (Core singleton)
 *   Phase 1 Amendment A6–A8      — Login/logout audit listeners
 *   Step 5 (manifest) 2B.47      — This file
 *   Step 5 (manifest) 2B.2       — WalletServiceProvider registration
 *   Step 7 v1.1.0 §B             — Audit plan
 *   Step 7 v1.1.0 §B.1           — Event inventory
 *   Step 7 v1.1.0 §B.2           — After-commit discipline
 *   Step 7 v1.1.0 §B.4           — Listener contract
 *   Step 7 v1.1.0 §B.4.3         — Non-propagation discipline
 *   Step 7 v1.1.0 §B.5           — Event type strings
 *   Step 7 v1.1.0 §B.6           — Exclusions (no audit for reads)
 *   §2.10 (conventions)          — Listener conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Listeners
 * @version 1.0.0
 */
final class AuditWalletEvent
{
    /**
     * Constructor.
     *
     * One dependency: the platform's `AuditService`, used as the sole
     * writer for the audit trail. The service is bound as a singleton by
     * `CoreServiceProvider` (Phase 1). See the class docblock
     * §"AuditService dependency".
     *
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    // =========================================================================
    // handle — the single listener entry point
    // =========================================================================

    /**
     * Handle any Wallet-domain event.
     *
     * The entire body is wrapped in `try/catch (Throwable)` and NEVER
     * propagates to the caller. See the class docblock
     * §"Failure isolation — must never propagate" for the rationale.
     *
     * The dispatch is a `match (true)` on the event class. Each arm calls
     * a private `handleXxx()` method that maps the event to its
     * `event_type` string and payload. The `default` arm throws
     * `LogicException` — a defensive guard against a future registration
     * being added without a corresponding handler. See the class docblock
     * §"Unrecognized events".
     *
     * @param object $event  One of the seven Wallet-domain events.
     * @return void
     */
    public function handle(object $event): void
    {
        try {
            match (true) {
                $event instanceof WalletOpened              => $this->handleWalletOpened($event),
                $event instanceof ObligationRecorded        => $this->handleObligationRecorded($event),
                $event instanceof PaymentReceived           => $this->handlePaymentReceived($event),
                $event instanceof PaymentAllocated          => $this->handlePaymentAllocated($event),
                $event instanceof PaymentAllocationReversed => $this->handlePaymentAllocationReversed($event),
                $event instanceof WalletAdjusted            => $this->handleWalletAdjusted($event),
                $event instanceof WalletEntryReversed       => $this->handleWalletEntryReversed($event),
                default                                     => throw new LogicException(
                    sprintf(
                        'AuditWalletEvent received an unrecognized event class: %s',
                        $event::class,
                    ),
                ),
            };
        } catch (Throwable $e) {
            Log::error(
                'AuditWalletEvent listener failed',
                [
                    'event_class' => $event::class,
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ],
            );
        }
    }

    // =========================================================================
    // Per-event handlers
    // =========================================================================

    /**
     * Handle a `WalletOpened` event (2B.40).
     *
     * Event type: `wallet.opened`.
     *
     * @param WalletOpened $event
     * @return void
     */
    private function handleWalletOpened(WalletOpened $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.opened',
            data: [
                'wallet_id'      => $event->walletId(),
                'tenant_id'      => $event->tenantId(),
                'shareholder_id' => $event->shareholderId(),
                'currency'       => $event->currency(),
            ],
        );
    }

    /**
     * Handle an `ObligationRecorded` event (2B.41).
     *
     * Event type: `wallet.obligation.recorded`.
     *
     * @param ObligationRecorded $event
     * @return void
     */
    private function handleObligationRecorded(ObligationRecorded $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.obligation.recorded',
            data: [
                'obligation_id' => $event->obligationId(),
                'wallet_id'     => $event->walletId(),
                'tenant_id'     => $event->tenantId(),
                'component'     => $event->component()->value,
            ],
        );
    }

    /**
     * Handle a `PaymentReceived` event (2B.42).
     *
     * Event type: `wallet.payment.received`.
     *
     * @param PaymentReceived $event
     * @return void
     */
    private function handlePaymentReceived(PaymentReceived $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.payment.received',
            data: [
                'payment_id' => $event->paymentId(),
                'wallet_id'  => $event->walletId(),
                'tenant_id'  => $event->tenantId(),
                'amount'     => $event->amount(),
            ],
        );
    }

    /**
     * Handle a `PaymentAllocated` event (2B.43).
     *
     * Event type: `wallet.payment.allocated`.
     *
     * @param PaymentAllocated $event
     * @return void
     */
    private function handlePaymentAllocated(PaymentAllocated $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.payment.allocated',
            data: [
                'allocation_id' => $event->allocationId(),
                'wallet_id'     => $event->walletId(),
                'payment_id'    => $event->paymentId(),
                'obligation_id' => $event->obligationId(),
                'tenant_id'     => $event->tenantId(),
                'amount'        => $event->amount(),
            ],
        );
    }

    /**
     * Handle a `PaymentAllocationReversed` event (2B.44).
     *
     * Event type: `wallet.payment.allocation_reversed`.
     *
     * The `reason` is included in the payload because it is the metadata
     * that distinguishes a reversal from a mere mutation (Architecture
     * §3.6). An audit consumer that needs to reconstruct WHY a
     * correction happened reads it from this row.
     *
     * @param PaymentAllocationReversed $event
     * @return void
     */
    private function handlePaymentAllocationReversed(PaymentAllocationReversed $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.payment.allocation_reversed',
            data: [
                'reversal_id'     => $event->reversalId(),
                'allocation_id'   => $event->allocationId(),
                'wallet_id'       => $event->walletId(),
                'tenant_id'       => $event->tenantId(),
                'reversed_amount' => $event->reversedAmount(),
                'reason'          => $event->reason(),
            ],
        );
    }

    /**
     * Handle a `WalletAdjusted` event (2B.45).
     *
     * Event type: `wallet.adjusted`.
     *
     * The `signed_quantity` carries the direction of the adjustment
     * (Architecture §4.2); no separate direction field is emitted.
     *
     * @param WalletAdjusted $event
     * @return void
     */
    private function handleWalletAdjusted(WalletAdjusted $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.adjusted',
            data: [
                'entry_id'        => $event->entryId(),
                'wallet_id'       => $event->walletId(),
                'tenant_id'       => $event->tenantId(),
                'signed_quantity' => $event->signedQuantity(),
            ],
        );
    }

    /**
     * Handle a `WalletEntryReversed` event (2B.46).
     *
     * Event type: `wallet.entry.reversed`.
     *
     * The `reversed_entry_id` is included so that an audit consumer can
     * trace the reversal chain without a second query against the
     * `wallet_entries` table.
     *
     * @param WalletEntryReversed $event
     * @return void
     */
    private function handleWalletEntryReversed(WalletEntryReversed $event): void
    {
        $this->auditService->log(
            eventType: 'wallet.entry.reversed',
            data: [
                'entry_id'          => $event->entryId(),
                'reversed_entry_id' => $event->reversedEntryId(),
                'wallet_id'         => $event->walletId(),
                'tenant_id'         => $event->tenantId(),
                'signed_quantity'   => $event->signedQuantity(),
            ],
        );
    }
}