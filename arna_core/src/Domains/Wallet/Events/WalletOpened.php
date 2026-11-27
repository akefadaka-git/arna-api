<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;

/**
 * Dispatched after wallet creation commits; event emission is configurable and does not gate wallet creation.
 *
 * @package MaaleArna\Arna\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class WalletOpened
{
    use Dispatchable;

    /**
     * The wallet that was opened.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$wallet->refresh()` before this event is
     * instantiated, so every attribute reflects the row as committed.
     *
     * @var Wallet
     */
    public readonly Wallet $wallet;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `Wallet` instance. It performs
     * no validation and no side effects; the service has already
     * established every invariant that governs opening before reaching
     * this point.
     *
     * @param Wallet $wallet  The newly-opened wallet.
     */
    public function __construct(Wallet $wallet)
    {
        $this->wallet = $wallet;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the opened wallet.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->wallet->id`, but expresses intent at the
     * call site.
     *
     * @return int
     */
    public function walletId(): int
    {
        return (int) $this->wallet->getKey();
    }

    /**
     * The tenant id under which the wallet was opened.
     *
     * Read directly from the wallet's `tenant_id` column — unlike the
     * analogous accessor on the 2A `CertificateIssued` event, no
     * transitive resolution through a relation is needed, because the
     * wallet carries `tenant_id` directly (2B.5 §"$fillable").
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->wallet->tenant_id;
    }

    /**
     * The shareholder id the wallet belongs to.
     *
     * Read directly from the wallet's `shareholder_id` column. Provided
     * as a convenience for listeners that audit openings at the
     * shareholder level without loading the wallet relation.
     *
     * @return int
     */
    public function shareholderId(): int
    {
        return (int) $this->wallet->shareholder_id;
    }

    /**
     * The currency the wallet was opened in.
     *
     * Read directly from the wallet's `currency` column. The currency is
     * immutable for the wallet's lifetime (A10, enforced by the Wallet
     * model's `booted()` guard, 2B.5), so a listener that records this
     * value records a fixed property of the wallet.
     *
     * @return string
     */
    public function currency(): string
    {
        return (string) $this->wallet->currency;
    }
}