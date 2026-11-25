<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;

/**
 * Dispatched after allocation commits; disabling event emission never suppresses the allocation or projection updates.
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class PaymentAllocated
{
    use Dispatchable;

    /**
     * The allocation that was recorded.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$allocation->refresh()` before this event
     * is instantiated, so every attribute reflects the row as committed.
     *
     * @var PaymentAllocation
     */
    public readonly PaymentAllocation $allocation;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `PaymentAllocation` instance.
     * It performs no validation and no side effects; the service has
     * already established every invariant that governs allocation before
     * reaching this point.
     *
     * @param PaymentAllocation $allocation  The newly-recorded allocation.
     */
    public function __construct(PaymentAllocation $allocation)
    {
        $this->allocation = $allocation;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the recorded allocation.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->allocation->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function allocationId(): int
    {
        return (int) $this->allocation->getKey();
    }

    /**
     * The id of the wallet the allocation belongs to.
     *
     * Read directly from the allocation's `wallet_id` column, which is a
     * denormalized column populated at insert time (2B.8 §"Relations").
     * No query is required.
     *
     * The `wallet_id` column on `PaymentAllocation` is denormalized from
     * the parent payment (which itself equals the obligation's wallet by
     * the §6.3 invariant). It enables direct wallet-scoped queries and
     * gives listeners a single, unambiguous wallet reference.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->allocation->wallet_id;
    }

    /**
     * The id of the payment being applied.
     *
     * Read directly from the allocation's `payment_id` column, which is
     * an immutable fact of the row (2B.8 §"Immutability"). No query is
     * required.
     *
     * The payment id joins this event to the sibling `PaymentReceived`
     * event (2B.42) for the same payment, allowing an audit listener to
     * correlate "the payment arrived" with "the payment was applied to
     * obligation X".
     *
     * @return int
     */
    public function paymentId(): int
    {
        return (int) $this->allocation->payment_id;
    }

    /**
     * The id of the obligation being settled.
     *
     * Read directly from the allocation's `obligation_id` column, which
     * is an immutable fact of the row (2B.8 §"Immutability"). No query
     * is required.
     *
     * The obligation id joins this event to the sibling
     * `ObligationRecorded` event (2B.41) for the same obligation,
     * allowing an audit listener to correlate "the obligation was
     * recorded" with "the obligation was settled by payment Y".
     *
     * @return int
     */
    public function obligationId(): int
    {
        return (int) $this->allocation->obligation_id;
    }

    /**
     * The tenant id under which the allocation was recorded.
     *
     * Resolved transitively through the allocation's parent wallet:
     * `allocation.wallet_id → wallet.tenant_id`. The `PaymentAllocation`
     * model does not carry a `tenant_id` column of its own (2B.8
     * §"No currency column" and the model's general dependency on its
     * parents for scope).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `AllocationService::allocate()` holds the `Wallet`
     * instance in hand when it constructs this event, and the
     * allocation's `wallet_id` points at it. A listener that needs a
     * non-null tenant should load the relation first
     * (`$event->allocation->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the sibling events
     * `ObligationRecorded` (2B.41) and `PaymentReceived` (2B.42), and on
     * the 2A `CertificateIssued` event.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->allocation->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The amount that was allocated.
     *
     * Read directly from the allocation's `amount` column, which is an
     * immutable fact of the row and is set at creation time (2B.18
     * §"allocate"). The value is a positive decimal string at scale 4.
     *
     * Provided as a convenience for listeners that audit allocations by
     * amount without inspecting the model. Equivalent to
     * `$event->allocation->amount`.
     *
     * @return string
     */
    public function amount(): string
    {
        return (string) $this->allocation->amount;
    }
}