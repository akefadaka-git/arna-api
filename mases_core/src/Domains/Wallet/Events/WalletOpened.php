<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * WalletOpened
 *
 * Domain event dispatched when a wallet is opened.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `WalletService::open()` (2B.15) dispatches this event after the
 * transaction that created the wallet has committed, via
 * `DB::afterCommit()`. The single call site is the `store()` action of
 * `WalletController` (2B.26), which handles route #2 of Step 7 v1.1.0
 * §C.1 (`POST /wallets`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the wallet is still created; only the event
 * dispatch is suppressed. See the class docblock of `WalletService`
 * §"Event dispatch" for the gating rationale.
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $wallet  — the newly-created Wallet model.
 *
 * The wallet is hydrated and persisted at the time the event is
 * constructed. `WalletService::open()` calls `$wallet->refresh()` before
 * instantiating this event, so every attribute reflects the row as
 * committed — including the database-assigned `id`, the `current_balance`
 * default of `0.0000`, and the `opened_at` timestamp written by the
 * service.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `Wallet` model as a public readonly property.
 * A listener that needs only the id reads `$event->wallet->id`; a listener
 * that needs the shareholder, currency, or any other attribute reads the
 * corresponding property. The alternative — carrying only scalar
 * primitives — would force every listener to re-query the row, defeating
 * the purpose of dispatching the event after commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$wallet` is `public readonly`. A listener MUST NOT attempt to mutate
 * the wallet through this reference; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level: PHP raises an `Error` on any attempted reassignment.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-open side effect that
 * writes, the correct pattern is for the listener to call a service, not
 * to edit the event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B dispatches
 * this event synchronously through the synchronous Event dispatcher; the
 * event is not queued. If a future CR makes the audit listener queued, the
 * trait's `SerializesModels` behaviour applies automatically to the
 * `$wallet` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         WalletOpened::class,
 *         AuditWalletEvent::class,
 *     );
 *
 * The listener, `AuditWalletEvent` (2B.47), branches on the event class
 * via `match (true)` and writes an audit row through the Core
 * `AuditService`. A listener MUST NOT propagate exceptions to the caller;
 * the listener catches `Throwable` and logs, per the Listener convention
 * (§2.10).
 *
 * =============================================================================
 * No ledger entry produced
 * =============================================================================
 * Opening a wallet is a structural act, not a monetary movement. The
 * wallet ledger (`wallet_entries`) is untouched by this operation — the
 * entry-type vocabulary (2B.4) has no `wallet_opened` case, and none is
 * added. The wallet's initial `current_balance` is the database default
 * of `0.0000`, which is consistent with the projection invariant for an
 * empty ledger (2B.5 §"Balance projection").
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §14.1   — Event inventory (WalletOpened)
 *   Architecture v1.2.0 §19     — Q4 resolution (explicit opening)
 *   Step 5 (manifest) 2B.40     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #2)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
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