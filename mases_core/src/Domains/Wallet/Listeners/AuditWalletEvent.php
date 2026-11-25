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
 * Writes tenant-attributed Wallet event records through AuditService; sensitive payload fields must remain filtered.
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