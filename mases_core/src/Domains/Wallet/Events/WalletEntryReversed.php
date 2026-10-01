<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;

/**
 * WalletEntryReversed
 *
 * Domain event dispatched when a previously-posted wallet ledger entry is
 * reversed.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `WalletService::reverseEntry()` (2B.15) dispatches this event after the
 * transaction that created the reversal entry has committed, via
 * `DB::afterCommit()`.
 *
 * Unlike every other event in the Wallet domain, this one has NO route
 * handler in the Phase 2B manifest. The Step 7 v1.1.0 §C.1 nine-route
 * structure (routes #1–#9) does not include an HTTP endpoint for
 * reversing a ledger entry. The operation is reachable from three call
 * sites, none of which is HTTP:
 *
 *   1. Tests — the unit test suite for `WalletService`
 *      (2B.57 `WalletServiceTest`) exercises `reverseEntry()` directly.
 *
 *   2. Artisan scripts — an operator-initiated corrective action
 *      executed through a `tinker` session or a one-off command.
 *
 *   3. A future CR — a dedicated HTTP endpoint
 *      (`POST /wallets/{wallet}/entries/{entry}/reverse`) added when
 *      the business requires operator-facing entry reversal through the
 *      API.
 *
 * The event is registered with `AuditWalletEvent` (2B.47) regardless of
 * its current call sites. When a future CR adds the HTTP path, the
 * audit trail already covers the operation; no change to this file is
 * required.
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1). When
 * the config is false, the reversal entry and the wallet's updated
 * `current_balance` are still written; only the event dispatch is
 * suppressed. See the class docblock of `WalletService` §"Event
 * dispatch".
 *
 * =============================================================================
 * Relationship to the sibling WalletAdjusted (2B.45)
 * =============================================================================
 * This event is the CORRECTIVE counterpart to `WalletAdjusted` (2B.45)
 * and, more broadly, to every event that produces a ledger entry
 * (`ObligationRecorded` 2B.41, `PaymentReceived` 2B.42, and
 * `WalletAdjusted` itself). The three forward-flow events announce a
 * ledger entry that CHANGES the wallet's balance; this event announces a
 * ledger entry that UNDOES a prior change. Together they form the
 * wallet's complete ledger-audit stream.
 *
 * Both this event and `WalletAdjusted` carry a `WalletEntry` — the same
 * payload shape, since both operations produce the same row type. The
 * distinction is the entry's `entry_type`:
 *
 *     WalletAdjusted            → entry_type = 'adjustment'
 *     WalletEntryReversed       → entry_type = 'reversal'
 *
 * A listener that needs to distinguish the two reads
 * `$event->entry->entry_type` (or the derived `$event->entry->isReversal()`
 * boolean on the model, 2B.10 §"Domain helpers").
 *
 * The reversal entry ALSO carries `reversed_entry_id`, pointing at the
 * original entry that it undoes. A listener that audits the full
 * correction chain reads that column (or the `reversed_entry` relation,
 * when loaded) to link back to the original.
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $entry  — the WalletEntry row created by the reversal.
 *
 * The entry is hydrated and persisted at the time the event is
 * constructed. `WalletService::reverseEntry()` calls `$entry->refresh()`
 * before instantiating this event, so every attribute reflects the row
 * as committed — including the database-assigned `id`, the
 * `balance_before` and `balance_after` values computed under the wallet
 * row lock, the `signed_quantity` (the numeric negation of the original
 * entry's, per Architecture §4.3), the `reversed_entry_id`, and the
 * `occurred_at` timestamp.
 *
 * The wallet's `current_balance` was updated to equal the reversal's
 * `balance_after` inside the same transaction (2B.15 §"recordEntry"),
 * before this event was dispatched. A listener that reads
 * `$event->entry->balance_after` reads the wallet's post-reversal
 * balance without a second query.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `WalletEntry` model as a public readonly
 * property. A listener that needs only the id reads `$event->entry->id`;
 * a listener that needs the reversal chain, the balance transition, or
 * the signed quantity reads the corresponding property. The alternative
 * — carrying only scalar primitives — would force every listener to
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
 * A reversal entry may not itself be reversed (Architecture §4.3); the
 * model exposes no `reversals` path for a reversal entry, and
 * `WalletService::reverseEntry()` (2B.15) rejects any attempt to reverse
 * an entry whose `entry_type` is `Reversal`. A listener that needs a
 * further correction writes a new adjustment or a new obligation — it
 * does not reverse this reversal.
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
 *         WalletEntryReversed::class,
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
 *   Architecture v1.2.0 §4.3    — Corrections (reversal semantics)
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §14.1   — Event inventory (WalletEntryReversed)
 *   Step 5 (manifest) 2B.46     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (no entry-
 *                                 reversal route in 2B)
 *   §2.9 (conventions)          — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class WalletEntryReversed
{
    use Dispatchable;

    /**
     * The reversal ledger entry that was created.
     *
     * Hydrated and persisted at the time the event is constructed — the
     * service has already run `$entry->refresh()` before this event is
     * instantiated, so every attribute reflects the row as committed.
     *
     * The entry's `entry_type` is `WalletEntryType::Reversal`; its
     * `component` is null (reversals are not component-tagged); its
     * `reference_type` is `'WalletEntry'` and its `reference_id` is the
     * original entry's id, per the §5.3 reference mapping; its
     * `reversed_entry_id` is the same original entry's id.
     *
     * @var WalletEntry
     */
    public readonly WalletEntry $entry;

    /**
     * Construct the event.
     *
     * The constructor takes the persisted `WalletEntry` (reversal)
     * instance. It performs no validation and no side effects; the
     * service has already established every invariant that governs
     * reversal before reaching this point.
     *
     * @param WalletEntry $entry  The reversal's ledger entry.
     */
    public function __construct(WalletEntry $entry)
    {
        $this->entry = $entry;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the reversal ledger entry.
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
     * The primary key of the original entry that this reversal undoes.
     *
     * Read directly from the reversal's `reversed_entry_id` column, which
     * is set only on reversal entries and is required by the service
     * (2B.15 §"assertReversalConsistency"). The value is non-null for
     * every event dispatched by this class — a `WalletEntryReversed`
     * event is by construction the announcement of a reversal, and a
     * reversal always carries a `reversed_entry_id`.
     *
     * The returned id links this event to whichever forward-flow event
     * announced the original entry: `ObligationRecorded` (2B.41),
     * `PaymentReceived` (2B.42), or `WalletAdjusted` (2B.45). A listener
     * that reconstructs the full lifecycle of a ledger movement joins
     * the two events on the original entry's id.
     *
     * @return int
     */
    public function reversedEntryId(): int
    {
        return (int) $this->entry->reversed_entry_id;
    }

    /**
     * The id of the wallet the reversal was recorded against.
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
     * The tenant id under which the reversal was recorded.
     *
     * Resolved transitively through the entry's parent wallet:
     * `entry.wallet_id → wallet.tenant_id`. The `WalletEntry` model does
     * not carry a `tenant_id` column of its own (2B.10); tenant scope is
     * inherited through the wallet.
     *
     * Returns `null` if the wallet relation is not loaded and cannot be
     * resolved. In every legitimate Phase 2B dispatch, the relation is
     * reachable: `WalletService::reverseEntry()` holds the `Wallet`
     * instance in hand when it constructs this event, and the entry's
     * `wallet_id` points at it. A listener that needs a non-null tenant
     * should load the relation first
     * (`$event->entry->loadMissing('wallet')`) or handle the null case,
     * mirroring the pattern on the sibling events `ObligationRecorded`
     * (2B.41), `PaymentReceived` (2B.42), `PaymentAllocated` (2B.43),
     * `PaymentAllocationReversed` (2B.44), and `WalletAdjusted` (2B.45),
     * and on the 2A `CertificateIssued` event.
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
     * The signed quantity of the reversal.
     *
     * Read directly from the ledger entry's `signed_quantity` column,
     * which is the SIGNED change applied to the wallet by the reversal:
     *
     *     positive — credit movement (a debit was reversed)
     *     negative — debit movement  (a credit was reversed)
     *
     * The value is a decimal string at scale 4. By Architecture §4.3,
     * the reversal's signed quantity is the NUMERIC NEGATION of the
     * original entry's. A listener that needs the amount the original
     * entry moved reads the negation of this value; a listener that
     * needs the direction of the ORIGINAL movement reads the opposite
     * sign from this one.
     *
     * Provided as a convenience for listeners that audit reversals by
     * amount without inspecting the model. Equivalent to
     * `$event->entry->signed_quantity`.
     *
     * @return string
     */
    public function signedQuantity(): string
    {
        return (string) $this->entry->signed_quantity;
    }
}