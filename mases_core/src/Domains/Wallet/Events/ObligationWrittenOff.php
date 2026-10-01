<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * ObligationWrittenOff
 *
 * Domain event dispatched when an outstanding obligation is forgiven.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * `ObligationService::discharge()` (CR-2C-WALLET-OBLIGATION-LIFECYCLE-001)
 * dispatches this event after the transaction that zeroed the
 * obligation's outstanding amount and wrote the corresponding ledger
 * entry has committed, via `DB::afterCommit()`. The single call site is
 * the Phase 2C loan write-off path:
 *
 *     LoanWriteOffService::writeOff() (A.35)
 *
 * When a loan is written off, its outstanding Wallet obligations
 * (principal, interest, penalty) are discharged with
 * `entry_type = WalletEntryType::ObligationWrittenOff` and
 * `reference_type = 'LoanWriteOff'`. The discharge's `reference_id` is
 * the primary key of the `loan_write_offs` row that produced the
 * discharge (the reference-id ordering fix per Phase 2C Final
 * Reconciliation §1.3).
 *
 * The event is gated on `config('wallet.audit.emit_events')` (2B.1),
 * which is read by the private `dispatchAfterCommit()` helper on
 * `ObligationService`. When the config is false, the obligation's
 * outstanding amount is still zeroed and the ledger entry is still
 * written; only the event dispatch is suppressed.
 *
 * =============================================================================
 * What the event carries
 * =============================================================================
 * One property:
 *
 *     $obligation  — the discharged Obligation model.
 *
 * The obligation is hydrated and persisted at the time the event is
 * constructed. `ObligationService::discharge()` calls
 * `$obligation->refresh()` before instantiating this event, so every
 * attribute reflects the row as committed — including the
 * `outstanding_amount` value of `0.0000` written by the discharge.
 *
 * A listener that needs the discharged obligation's pre-discharge
 * outstanding amount reads it from the corresponding discharge ledger
 * entry's `signed_quantity` column (which equals the magnitude of the
 * discharged amount, per the sign convention in Architecture §4.2). The
 * ledger entry is reachable by querying `wallet_entries` for
 * `entry_type = 'obligation_written_off'` with the Loan-side reference
 * pair that the caller supplied — see the class docblock
 * §"Reaching the ledger entry" below.
 *
 * =============================================================================
 * The event is about the obligation, not the ledger entry
 * =============================================================================
 * Writing off an obligation produces TWO mutations inside one
 * transaction:
 *
 *   1. The `Obligation` row's `outstanding_amount` is set to `0.0000`.
 *   2. A `WalletEntry` row of type `obligation_written_off` is written by
 *      `WalletService::recordEntry()` (2B.15).
 *
 * This event carries the OBLIGATION, not the ledger entry. The
 * convention mirrors the sibling `ObligationRecorded` (2B.41): the
 * domain fact the caller asked for is the obligation; the ledger entry
 * is the wallet-domain's internal record of the monetary movement.
 *
 * -----------------------------------------------------------------------------
 * Reaching the ledger entry
 * -----------------------------------------------------------------------------
 * Unlike `ObligationRecorded`, whose ledger entry is reachable from the
 * obligation through the generic `reference_type = 'Obligation'` +
 * `reference_id = obligation.id` pair, this event's corresponding
 * ledger entry is referenced through the CALLER's Loan-side reference
 * vocabulary:
 *
 *     entry_type     = 'obligation_written_off'
 *     reference_type = 'LoanWriteOff'
 *     reference_id   = (string) $writeOffId
 *
 * The `$writeOffId` is not carried on this event. A listener that needs
 * the ledger entry must obtain the id from the Loan-domain context that
 * caused the discharge (e.g. by listening also to `LoanWrittenOff`, or
 * by querying `loan_write_offs` by loan).
 *
 * This is the current contract. If a future CR determines that the
 * ledger entry must be reachable from the event alone, that CR extends
 * the payload — it does not change this class silently.
 *
 * =============================================================================
 * Why the event carries the model, not an id
 * =============================================================================
 * The event exposes the full `Obligation` model as a public readonly
 * property. A listener that needs only the id reads
 * `$event->obligation->id`; a listener that needs the component, the
 * wallet id, or any other attribute reads the corresponding property.
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event after commit.
 *
 * Per the Event convention (§2.9), constructor parameters are models or
 * scalar primitives, never arrays of arrays; the model is the natural
 * unit of interest here.
 *
 * =============================================================================
 * The distinction from ObligationCancelled (CR-2C companion)
 * =============================================================================
 * The Wallet domain carries two discharge events:
 *
 *     ObligationCancelled    — the obligation's terms are being replaced.
 *                              Produced by the loan-restructure path
 *                              (A.34). The borrower still owes the
 *                              recomputed debt under the new terms.
 *
 *     ObligationWrittenOff   — the obligation is forgiven. Produced by
 *                              the loan write-off path (A.35). The
 *                              borrower owes nothing further on the
 *                              loan.
 *
 * Recording the two as DIFFERENT event classes preserves the semantic
 * distinction on the audit stream, mirroring the corresponding
 * distinction between the two `WalletEntryType` cases
 * (`obligation_cancelled` and `obligation_written_off`) established by
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001.
 *
 * The distinction is load-bearing for D133's clause that a write-off
 * does NOT erase the historical arrears ledger: a penalty obligation
 * that was discharged by a WRITE-OFF fires `ObligationWrittenOff`; the
 * corresponding `loan_penalty_accruals` row remains in the database
 * regardless. A reader of the audit stream can answer "was this
 * obligation replaced, or was it forgiven?" from the event class alone.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$obligation` is `public readonly`. A listener MUST NOT attempt to
 * mutate the obligation through this reference; mutations belong to the
 * service layer, and the model's `booted()` guard (2B.6
 * §"Immutability") rejects mutations of the five immutable facts
 * (component, currency, original_amount, occurred_at, wallet_id)
 * anyway. The `readonly` modifier enforces the binding-level constraint
 * at the language level.
 *
 * A listener that needs to record a correction writes a NEW obligation
 * plus a reversal of the ledger entry — it does not edit this one.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2C
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued. If a future CR makes the audit
 * listener queued, the trait's `SerializesModels` behaviour applies
 * automatically to the `$obligation` property.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `WalletServiceProvider` (2B.2) as a listener target for
 * `AuditWalletEvent` (2B.47):
 *
 *     Event::listen(
 *         ObligationWrittenOff::class,
 *         AuditWalletEvent::class,
 *     );
 *
 * The registration is NOT added by
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001, whose scope is three files. A
 * follow-up CR (or an amendment to this one) extends the provider to
 * add the two `Event::listen` lines. Until then, the event dispatches
 * with no listener attached; no audit row is written for write-offs.
 * The obligation mutation and the ledger entry are unaffected.
 *
 * A listener MUST NOT propagate exceptions to the caller; the listener
 * catches `Throwable` and logs, per the Listener convention (§2.10).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   D133                         — Write-off preserves historical arrears
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention (positive for discharge)
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §14.1   — Event inventory
 *   Phase 2C Decision Register   — B1 (reference types), B2 (hybrid
 *                                  ledger), D133
 *   Phase 2C Final Reconciliation — §1.3 (reference-id ordering)
 *   Phase 2C A.35                — `LoanWriteOffService` (the sole
 *                                  producer)
 *   CR-2C-WALLET-REFTYPE-001     — Wallet ref-type map (accepted
 *                                  'LoanWriteOff' for
 *                                  obligation_written_off)
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 — this CR; introduces
 *                                  `ObligationService::discharge()` and
 *                                  this event
 *   Step 5 (manifest) 2B.41      — Structural reference (`ObligationRecorded`)
 *   Step 7 v1.1.0 §C.1           — Nine-route API structure
 *   §2.9 (conventions)           — Event conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Events
 * @version 1.0.0
 */
final class ObligationWrittenOff
{
    use Dispatchable;

    /**
     * The obligation that was written off.
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
     * @param Obligation $obligation  The written-off obligation.
     */
    public function __construct(Obligation $obligation)
    {
        $this->obligation = $obligation;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the written-off obligation.
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
     * The id of the wallet the written-off obligation belongs to.
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
     * The tenant id under which the write-off was recorded.
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
     * The economic component of the written-off obligation.
     *
     * Read directly from the obligation's `component` column, which the
     * model casts to the `ObligationComponent` enum (2B.6 §"Enum cast").
     * The component is an immutable fact of the row and is set at
     * creation time. The discharge service carries the component onto
     * the corresponding ledger entry's `component` column, so a reader
     * of the ledger can see WHICH component was forgiven.
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