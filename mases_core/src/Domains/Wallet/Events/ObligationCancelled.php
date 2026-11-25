<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * Dispatched after commit when a loan restructure discharges an old obligation and records the corresponding ledger entry.
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class ObligationCancelled
{
    use Dispatchable;

    /**
     * The obligation that was cancelled.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$obligation->refresh()` before this event
     * is instantiated, so every attribute reflects the row as committed,
     * including the `outstanding_amount` value of `0.0000` written by
     * the discharge.
     *
     * @var Obligation
     */
    public readonly Obligation $obligation;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `Obligation` instance. It
     * performs no validation and no side effects; the service has
     * already established every invariant that governs discharge before
     * reaching this point.
     *
     * @param Obligation $obligation  The cancelled obligation.
     */
    public function __construct(Obligation $obligation)
    {
        $this->obligation = $obligation;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the cancelled obligation.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->obligation->id`, but expresses intent at
     * the call site.
     *
     * @return int
     */
    public function obligationId(): int
    {
        return (int) $this->obligation->getKey();
    }

    /**
     * The id of the wallet the cancelled obligation belongs to.
     *
     * Read directly from the obligation's `wallet_id` column, which is
     * an immutable fact of the row (2B.6 §"Immutability"). No query is
     * required.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->obligation->wallet_id;
    }

    /**
     * The tenant id under which the cancellation was recorded.
     *
     * Resolved transitively through the obligation's parent wallet:
     * `obligation.wallet_id → wallet.tenant_id`. The `Obligation` model
     * does not carry a `tenant_id` column of its own (2B.6 §"What this
     * model deliberately does NOT do" — tenant scope is inherited
     * through the wallet).
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2C dispatch, the relation is
     * reachable: `ObligationService::discharge()` holds the `Wallet`
     * instance in hand when it constructs this event, and the
     * obligation's `wallet_id` points at it. A listener that needs a
     * non-null tenant should load the relation first
     * (`$event->obligation->loadMissing('wallet')`) or handle the null
     * case, mirroring the pattern on the sibling `ObligationRecorded`
     * event.
     *
     * @return int|null
     */
    public function tenantId(): ?int
    {
        $wallet = $this->obligation->wallet;

        if ($wallet === null) {
            return null;
        }

        return (int) $wallet->tenant_id;
    }

    /**
     * The economic component of the cancelled obligation.
     *
     * Read directly from the obligation's `component` column, which the
     * model casts to the `ObligationComponent` enum (2B.6 §"Enum cast").
     * The component is an immutable fact of the row and is set at
     * creation time. The discharge service carries the component onto
     * the corresponding ledger entry's `component` column, so a reader
     * of the ledger can see WHICH component was cancelled.
     *
     * Provided as a convenience for listeners that audit obligations by
     * category without inspecting the model. Equivalent to
     * `$event->obligation->component`.
     *
     * @return ObligationComponent
     */
    public function component(): ObligationComponent
    {
        return $this->obligation->component;
    }
}