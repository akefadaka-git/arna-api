<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;

/**
 * WalletAdjusted
 *
 * Domain event dispatched when an operator-initiated balance adjustment
 * is recorded against a wallet.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `WalletService::adjust()` (2B.15) dispatches this event after the
 * transaction that created the adjustment's ledger entry has committed,
 * via `DB::afterCommit()`. The single call site is the `adjust()` action
 * of `WalletController` (2B.26), which handles route #4 of Step 7 v1.1.0
 * §C.1 (`POST /wallets/{wallet}/adjust`).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the ledger entry and the wallet's updated
 * `current_balance` are still written; only the event dispatch is
 * suppressed. See the class docblock of `WalletService` §"Event
 * dispatch".
 *
 * =============================================================================
 * What the event carries — a ledger entry, not a parent model
 * =============================================================================
 * One property:
 *
 *     $entry  — the WalletEntry row created by the adjustment.
 *
 * This is the FIRST event in the Wallet domain whose payload is a
 * `WalletEntry` rather than a scoped business aggregate. The difference
 * is structural and reflects the adjustment operation's data model:
 *
 *   - `ObligationRecorded` (2B.41) carries an `Obligation` because the
 *     operation creates both an Obligation row and a ledger entry.
 *
 *   - `PaymentReceived` (2B.42) carries a `Payment` because the operation
 *     creates both a Payment row and a ledger entry.
 *
 *   - `WalletAdjusted` carries a `WalletEntry` because the adjustment
 *     operation creates ONLY a ledger entry. There is no `Adjustment`
 *     model — the adjustment IS a ledger entry, of type
 *     `WalletEntryType::Adjustment` (2B.4).
 *
 * The reference pair on the entry (`reference_type = 'Wallet'`,
 * `reference_id = wallet.id`) points at the wallet itself: the source of
 * an adjustment is the wallet, not a separate business object (2B.15
 * §"adjust").
 *
 * =============================================================================
 * The event is about the ledger entry
 * =============================================================================
 * The entry is hydrated and persisted at the time the event is
 * constructed. `WalletService::adjust()` calls `$entry->refresh()` before
 * instantiating this event, so every attribute reflects the row as
 * committed — including the database-assigned `id`, the
 * `balance_before` and `balance_after` values computed under the wallet
 * row lock, the `signed_quantity` as supplied by the operator, and the
 * `occurred_at` timestamp.
 *
 * The wallet's `current_balance` was updated to equal the entry's
 * `balance_after` inside the same transaction (2B.15 §"recordEntry"),
 * before this event was dispatched. A listener that reads
 * `$event->entry->balance_after` reads the wallet's post-adjustment
 * balance without a second query.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `WalletEntry` model as a public readonly
 * property. A listener that needs only the id reads `$event->entry->id`;
 * a listener that needs the balance transition, the signed quantity, or
 * the description reads the corresponding property. The alternative —
 * carrying only scalar primitives — would force every listener to
 * re-query the row, defeating the purpose of dispatching the event after
 * commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * Immutability
 * =============================================================================
 * `$entry` is `public readonly`. A listener MUST NOT attempt to mutate
 * the entry through this reference; mutations belong to the service
 * layer, and the model's `booted()` guard (2B.10 §"Lifecycle") rejects
 * ANY update or delete attempt — the ledger is append-only by
 * construction (Architecture §3.7).
 *
 * A listener that needs to correct the adjustment issues a new reversal
 * entry via `WalletService::reverseEntry()` (2B.15). That correction is
 * announced by the sibling event `WalletEntryReversed` (2B.46), not by
 * re-dispatching this one.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2B
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$entry` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2):
 *
 *     Event::listen(
 *         WalletAdjusted::class,
 *         AuditWalletEvent::class,
 *     );
 *
 * The listener, `AuditWalletEvent` (2B.47), branches on the event class
 * via `match (true)` and writes an audit row through the Core
 * `AuditService`. A listener MUST NOT propagate exceptions to the
 * caller; the listener catches `Throwable` and logs, per the Listener
 * convention (§2.10).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.7    — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.3    — Corrections
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §14.1   — Event inventory (WalletAdjusted)
 *   Architecture v1.2.0 §19     — Q3 resolution (adjustment privileged)
 *   Step 5 (manifest) 2B.45     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs (wallet.adjust)
 *   Step 7 v1.1.0 §A.2.1        — Rationale for wallet.adjust scope
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #4)
 *   §2.9 (conventions)          — Event conventions
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