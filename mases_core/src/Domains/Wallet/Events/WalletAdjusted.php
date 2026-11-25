<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;

/**
 * Dispatched after the adjustment transaction commits; event emission is configurable, but ledger and balance writes are not.
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class WalletAdjusted
{
    use Dispatchable;

    /**
     * The ledger entry created by the adjustment.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$entry->refresh()` before this event is
     * instantiated, so every attribute reflects the row as committed.
     *
     * The entry's `entry_type` is `WalletEntryType::Adjustment`; its
     * `component` is null (adjustments are not component-tagged); its
     * `reference_type` is `'Wallet'` and its `reference_id` is the
     * wallet's id, per the §5.3 reference mapping.
     *
     * @var WalletEntry
     */
    public readonly WalletEntry $entry;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `WalletEntry` instance. It
     * performs no validation and no side effects; the service has already
     * established every invariant that governs the adjustment before
     * reaching this point.
     *
     * @param WalletEntry $entry  The adjustment's ledger entry.
     */
    public function __construct(WalletEntry $entry)
    {
        $this->entry = $entry;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the adjustment's ledger entry.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->entry->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function entryId(): int
    {
        return (int) $this->entry->getKey();
    }

    /**
     * The id of the wallet the adjustment was recorded against.
     *
     * Read directly from the ledger entry's `wallet_id` column, which is
     * an immutable fact of the row (2B.10 §"Full immutability"). No query
     * is required.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->entry->wallet_id;
    }

    /**
     * The tenant id under which the adjustment was recorded.
     *
     * Resolved transitively through the entry's parent wallet:
     * `entry.wallet_id → wallet.tenant_id`. The `WalletEntry` model does
     * not carry a `tenant_id` column of its own (2B.10); tenant scope is
     * inherited through the wallet.
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `WalletService::adjust()` holds the `Wallet` instance
     * in hand when it constructs this event, and the entry's `wallet_id`
     * points at it. A listener that needs a non-null tenant should load
     * the relation first (`$event->entry->loadMissing('wallet')`) or
     * handle the null case, mirroring the pattern on the sibling events
     * `ObligationRecorded` (2B.41), `PaymentReceived` (2B.42),
     * `PaymentAllocated` (2B.43), and `PaymentAllocationReversed`
     * (2B.44), and on the 2A `CertificateIssued` event.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->entry->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The signed quantity of the adjustment.
     *
     * Read directly from the ledger entry's `signed_quantity` column,
     * which is the SIGNED change applied to the wallet:
     *
     *     positive — credit movement (the organization credits the member)
     *     negative — debit movement (the member owes the organization)
     *
     * The value is a decimal string at scale 4. The sign carries the
     * direction of the adjustment (Architecture §4.2); there is no
     * separate direction column or field.
     *
     * Provided as a convenience for listeners that audit adjustments by
     * amount without inspecting the model. Equivalent to
     * `$event->entry->signed_quantity`.
     *
     * A listener that needs the direction as a boolean reads
     * `$event->entry->isCredit()` or `$event->entry->isDebit()` on the
     * model (2B.10 §"Domain helpers"); the two are mutually exclusive and
     * exhaustive for a well-formed entry.
     *
     * @return string
     */
    public function signedQuantity(): string
    {
        return (string) $this->entry->signed_quantity;
    }
}