<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanWriteOff;

/**
 * LoanWrittenOff
 *
 * Domain event dispatched when a loan's outstanding balance is forgiven —
 * the moment the lender exercises its terminal accounting-side action,
 * every outstanding Wallet obligation linked to the loan is discharged
 * as a write-off, the loan's projections are zeroed, and the loan
 * transitions to the terminal `written_off` state.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanWriteOffService::writeOff()` (A.35)
 * AFTER the transaction that inserted the `loan_write_offs` row,
 * discharged every outstanding Wallet obligation linked to the loan,
 * zeroed the loan's projections, and performed the `written_off` status
 * transition has committed.
 *
 * Because the write-off is tied to a state transition (one of `active →
 * written_off`, `in_arrears → written_off`, or `restructured →
 * written_off`), the event is registered via `LoanStateMachine::transition()`
 * (A.26)'s `$afterCommitEvent` parameter. The state machine defers the
 * `Event::dispatch()` call until the outermost transaction commits,
 * gated on `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful
 * write-off. If the surrounding transaction rolls back — for any reason,
 * including a failure in the Wallet discharge loop or the projection
 * reset — the event is never dispatched.
 *
 * A loan can be written off AT MOST ONCE across its lifetime, because
 * `written_off` is a FINAL state in the frozen C4 matrix (A.2): it has no
 * outgoing transitions. A subsequent write-off attempt is structurally
 * illegal, and `LoanStateMachine` will throw `ValidationException` before
 * any event is constructed.
 *
 * =============================================================================
 * The three inbound paths
 * =============================================================================
 * The frozen state machine (C4, A.2) permits three transitions INTO
 * `written_off`:
 *
 *     active        → written_off
 *     in_arrears    → written_off
 *     restructured  → written_off
 *
 * The third is technically reachable but rarely observed in practice:
 * the restructure service (A.34) transitions the loan from `restructured`
 * to `active` in the same transaction that writes the restructure, so no
 * external reader observes a loan in `restructured` state. The write-off
 * service still accepts it for completeness; the practical cases are the
 * first two.
 *
 * All three inbound transitions require a non-empty `transition_reason`,
 * per A.26's `REASON_REQUIRED_TRANSITIONS`. The reason is enforced twice:
 * once by `LoanWriteOffService::writeOff()` (A.35) in a pre-transaction
 * check, and once by the state machine's own guard.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Three properties:
 *
 *   $loan         — the loan that was written off.
 *
 *   $writeOff     — the `LoanWriteOff` row that A.35 inserted, recording
 *                   the loan's outstanding state at the moment of the
 *                   write-off, the amount forgiven, the reason, the
 *                   moment, and the actor.
 *
 *   $actorUserId  — the user who performed the write-off, or null for a
 *                   system-initiated write-off.
 *
 * Under the frozen A.35 flow, by the time this event is constructed:
 *
 *   - The `loan_write_offs` row has been INSERTED (A.35, step 6), and its
 *     primary key is available as `$writeOff->getKey()`. The row's four
 *     amount columns capture the loan's outstanding at the moment of the
 *     write-off: `write_off_amount`, `outstanding_principal_at_writeoff`,
 *     `outstanding_interest_at_writeoff`, `outstanding_penalty_at_writeoff`.
 *     By construction, `write_off_amount` equals the sum of the other
 *     three.
 *
 *   - Every outstanding Wallet obligation linked to the loan has been
 *     DISCHARGED via `ObligationService::discharge()` with
 *     `reference_type = 'LoanWriteOff'` and
 *     `reference_id = (string) $writeOff->getKey()` (A.35, step 7). This
 *     is the reference-id ordering fix (Phase 2C Final Reconciliation
 *     §1.3): the write-off row is inserted BEFORE the discharges so that
 *     each discharge entry has a valid reference id to point at.
 *
 *   - The loan's three outstanding projections have been ZEROED (A.35,
 *     step 8).
 *
 * The carried `$loan` reflects the projection reset, but its `status`
 * property is still whatever it was before the transition (typically
 * `LoanStatus::Active` or `LoanStatus::InArrears`). The transition to
 * `written_off` is performed by the state machine AFTER the event is
 * constructed, on a fresh row-locked instance returned by the transition;
 * the event holds the pre-transition instance.
 *
 * This mirrors the pattern of every prior event that is tied to a state
 * transition (A.38, A.39, A.40, A.41, A.44, A.46): the carried loan
 * retains its pre-transition status. A listener that needs the loan's
 * post-transition state reads the loan by id (which will be `written_off`
 * by the time the after-commit dispatch fires), or relies on the
 * `loan_status_histories` row that the state machine appended.
 *
 * =============================================================================
 * The write-off distinction — `ObligationWrittenOff` vs. `ObligationCancelled`
 * =============================================================================
 * The write-off discharges Wallet obligations with a DIFFERENT entry type
 * than the restructure flow uses. This is deliberate and semantically
 * meaningful:
 *
 *   - The restructure (A.34) discharges with `ObligationCancelled`. The
 *     semantic is "these terms no longer apply; new terms replace them".
 *     The borrower still owes the (recomputed) debt.
 *
 *   - The write-off (A.35) discharges with `ObligationWrittenOff`. The
 *     semantic is "this debt is forgiven". The borrower owes nothing
 *     further on the loan.
 *
 * The two entry types are recorded distinctly on the Wallet's
 * `wallet_entries` ledger (per CR-2C-WALLET-OBLIGATION-LIFECYCLE-001),
 * so the audit trail preserves the distinction. A listener that needs to
 * correlate the Loan-side write-off with the Wallet-side discharges looks
 * for `ObligationWrittenOff` entries (not `ObligationCancelled`) whose
 * `reference_id` equals `$event->writeOffId()`.
 *
 * See D133's "write-off does not silently erase the historical arrears
 * ledger" clause: any `loan_penalty_accruals` rows recorded before the
 * write-off remain in the database. The write-off zeroes the loan's
 * `outstanding_penalty` projection, but the historical fact that a
 * penalty was once recorded is preserved.
 *
 * =============================================================================
 * Why the event carries the LoanWriteOff model
 * =============================================================================
 * The `LoanWriteOff` instance is exposed as a full model so that a
 * listener can read the complete write-off record without re-querying.
 * The attributes a listener is likely to need are:
 *
 *   - `write_off_amount` — the total amount forgiven;
 *   - `outstanding_principal_at_writeoff`,
 *   - `outstanding_interest_at_writeoff`,
 *   - `outstanding_penalty_at_writeoff` — the loan's outstanding at the
 *     moment of the write-off, preserved because the loan's projections
 *     are zeroed immediately afterwards;
 *   - `reason` — the operator's justification (required by A.26's
 *     `REASON_REQUIRED_TRANSITIONS`);
 *   - `written_off_at`, `written_off_by` — the moment and actor of the
 *     write-off.
 *
 * All are available on the model without additional I/O. The model also
 * exposes read-only helpers (`outstandingAtWriteOffTotal()`,
 * `isFullWriteOff()`, `isPartialWriteOff()`, `isExcessWriteOff()`) that a
 * listener can use to classify the write-off.
 *
 * =============================================================================
 * Why the event carries the actor
 * =============================================================================
 * The reasoning follows the pattern of `LoanRejected` (A.40),
 * `LoanInstallmentSettled` (A.43), `LoanEnteredArrears` (A.44),
 * `LoanPenaltyRecorded` (A.45), and `LoanRestructured` (A.46):
 *
 *   - The loan row's actor columns (`approved_by`, `disbursed_by`,
 *     `created_by`) do not describe the write-off actor.
 *
 *   - The `LoanWriteOff` model DOES carry a `written_off_by` column and a
 *     `writtenOffBy()` relation (per A.22). The actor is therefore
 *     recoverable from the carried `$writeOff` model.
 *
 *   - The state machine ALSO records the actor on the corresponding
 *     `loan_status_histories.actor_user_id` row.
 *
 *   - The event nonetheless carries `$actorUserId` EXPLICITLY so that
 *     listeners do not have to reach through the model or the history
 *     table. Uniformity across the event family — matching A.43–A.46 —
 *     is worth the small duplication.
 *
 * The property is nullable, mirroring the domain-wide convention. The
 * common case is a human operator; the null case would arise from a
 * future CR that introduces an automated write-off workflow (e.g. an
 * end-of-fiscal-year policy that forgives sub-threshold balances).
 *
 * =============================================================================
 * The reference-id ordering fix (Phase 2C Final Reconciliation §1.3)
 * =============================================================================
 * A note for listeners and for the audit trail: the `LoanWriteOff` row's
 * primary key is the FIRST value produced in the transaction, and it is
 * used as the `reference_id` on every Wallet discharge entry. A listener
 * that correlates the Wallet-side `ObligationWrittenOff` events with this
 * Loan-side event uses the write-off row's primary key as the join key:
 *
 *     Loan-side:    $event->writeOffId()
 *     Wallet-side:  $writtenOffEvent->referenceId()
 *
 * The two MUST match. This is a property of the frozen reference-id
 * ordering, not an incidental correlation.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * All three properties are `public readonly`. A listener MUST NOT attempt
 * to mutate any of the carried models; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level.
 *
 * The model instances themselves remain mutable by their own API
 * (fill/save), because PHP's `readonly` applies to the property binding,
 * not to the object's internal state. Listeners are expected to observe,
 * not to mutate; if a future requirement demands a post-write-off side
 * effect that writes, the correct pattern is for the listener to call a
 * service, not to edit the event's models.
 *
 * Note that `LoanWriteOff` enforces strict immutability at the model
 * level: its `booted()` guard throws `LogicException` on any `updating`
 * or `deleting` event. A listener that attempts to mutate the carried
 * write-off receives a loud failure, not silent corruption.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. Phase 2C
 * dispatches this event synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future Change Request makes the audit listener queued (or any
 * other listener queued), the two carried models must be serialized. The
 * convention for that scenario is to use `SerializesModels` so that
 * Eloquent's model serialization survives the queue boundary. Phase 2C
 * does not adopt that trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanWrittenOff::class,
 *         AuditLoanEvent::class,
 *     );
 *
 * The listener branches on the event class via `match (true)` and writes
 * an audit row through the Core `AuditService`. A listener MUST NOT
 * propagate exceptions to the caller; per the convention established by
 * `AuditShareholderEvent` (2A.35) and `AuditWalletEvent` (2B.47), the
 * listener catches `Throwable`, logs, and returns.
 *
 * The event's audit event-type string, frozen by the manifest, is
 * `'loan.written_off'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, A10, B1, B2,
 *                                                  B6
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.3 (reference-id
 *                                                  ordering), §3 (the
 *                                                  invariant preserved
 *                                                  by the write-off)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.13                                — `loan_write_offs`
 *                                                  migration
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.22                                — `LoanWriteOff` (the
 *                                                  model)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.35                                — `LoanWriteOffService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.46                                — `LoanRestructured`
 *                                                  (the structurally
 *                                                  analogous event)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001        — adds `discharge()` and
 *                                                  the `ObligationWrittenOff`
 *                                                  entry type
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.47                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanWrittenOff
{
    use Dispatchable;

    /**
     * The loan that was written off.
     *
     * The instance was constructed by `LoanWriteOffService::writeOff()`
     * (A.35) BEFORE the state machine performed the terminal
     * `→ written_off` transition. Its `status` property therefore carries
     * the pre-transition value (typically `LoanStatus::Active` or
     * `LoanStatus::InArrears`). Its three outstanding projections have
     * been zeroed (A.35, step 8), reflecting the post-write-off state of
     * the loan's financial position. See the class docblock §"What the
     * event carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The `LoanWriteOff` row that A.35 inserted, recording the loan's
     * outstanding state at the moment of the write-off and the amount
     * forgiven.
     *
     * The row is immutable per the `LoanWriteOff` model's `booted()` guard
     * (A.22). Its primary key is the reference id used on every Wallet
     * discharge entry created by the same transaction
     * (`reference_type = 'LoanWriteOff'`).
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var LoanWriteOff
     */
    public readonly LoanWriteOff $writeOff;

    /**
     * The user who performed the write-off, or null for a system-initiated
     * write-off.
     *
     * The actor is also preserved on the `writeOff.written_off_by` column
     * and on the corresponding `loan_status_histories` row; the event
     * carries it explicitly so that listeners do not have to reach
     * through either. See the class docblock §"Why the event carries the
     * actor".
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan         $loan         The loan that was written off.
     * @param LoanWriteOff $writeOff     The write-off row that was
     *                                   recorded.
     * @param int|null     $actorUserId  The user who performed the
     *                                   write-off, or null for a
     *                                   system write-off.
     */
    public function __construct(
        Loan $loan,
        LoanWriteOff $writeOff,
        ?int $actorUserId = null,
    ) {
        $this->loan        = $loan;
        $this->writeOff    = $writeOff;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors — loan
    // =========================================================================

    /**
     * The primary key of the written-off loan.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan belongs.
     *
     * Read directly from the loan's `tenant_id` column.
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->loan->tenant_id;
    }

    /**
     * The id of the borrower (shareholder) whose loan was written off.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product under which the loan was originated.
     *
     * Read directly from the loan's `loan_product_id` column.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    // =========================================================================
    // Convenience accessors — write-off
    // =========================================================================

    /**
     * The primary key of the `LoanWriteOff` row.
     *
     * This is the JOIN KEY between this Loan-domain event and every
     * Wallet-side `ObligationWrittenOff` event that fired from the same
     * transaction. Each written-off event's `reference_id` equals this
     * value.
     *
     * @return int
     */
    public function writeOffId(): int
    {
        return (int) $this->writeOff->getKey();
    }

    /**
     * The total amount forgiven by the write-off, as a decimal string at
     * scale 4.
     *
     * Read directly from the write-off's `write_off_amount` column. Under
     * the frozen write-off flow, this equals
     * `outstandingAtWriteOffTotal()` — the sum of the three
     * `*_at_writeoff` columns.
     *
     * @return string
     */
    public function writeOffAmount(): string
    {
        return (string) $this->writeOff->write_off_amount;
    }

    /**
     * The loan's `outstanding_principal` at the moment of the write-off,
     * as a decimal string at scale 4.
     *
     * Read directly from the write-off's
     * `outstanding_principal_at_writeoff` column. The value is preserved
     * on the write-off row because the loan's own projection is zeroed
     * by the same transaction (A.35, step 8).
     *
     * @return string
     */
    public function outstandingPrincipalAtWriteOff(): string
    {
        return (string) $this->writeOff->outstanding_principal_at_writeoff;
    }

    /**
     * The loan's `outstanding_interest` at the moment of the write-off, as
     * a decimal string at scale 4.
     *
     * Read directly from the write-off's
     * `outstanding_interest_at_writeoff` column.
     *
     * @return string
     */
    public function outstandingInterestAtWriteOff(): string
    {
        return (string) $this->writeOff->outstanding_interest_at_writeoff;
    }

    /**
     * The loan's `outstanding_penalty` at the moment of the write-off, as
     * a decimal string at scale 4.
     *
     * Read directly from the write-off's
     * `outstanding_penalty_at_writeoff` column.
     *
     * @return string
     */
    public function outstandingPenaltyAtWriteOff(): string
    {
        return (string) $this->writeOff->outstanding_penalty_at_writeoff;
    }

    /**
     * The sum of the three `*_at_writeoff` columns, as a decimal string at
     * scale 4.
     *
     * Delegates to `LoanWriteOff::outstandingAtWriteOffTotal()` (A.22) so
     * that the helper's implementation stays the single source of truth.
     * Under the frozen write-off flow, this equals `writeOffAmount()`.
     *
     * @return string
     */
    public function outstandingAtWriteOffTotal(): string
    {
        return $this->writeOff->outstandingAtWriteOffTotal();
    }

    /**
     * The operator's reason for the write-off, as recorded on the
     * write-off row.
     *
     * Read directly from the write-off's `reason` column. The value is
     * guaranteed non-empty: every transition into `written_off` is in
     * A.26's `REASON_REQUIRED_TRANSITIONS`, and
     * `LoanWriteOffService::writeOff()` (A.35) enforces the requirement
     * before persisting.
     *
     * @return string
     */
    public function reason(): string
    {
        return (string) $this->writeOff->reason;
    }

    /**
     * The moment the write-off was recorded, in ISO 8601 form.
     *
     * Read directly from the write-off's `written_off_at` column, which
     * the migration A.13 declares NOT NULL.
     *
     * @return string
     */
    public function writtenOffAt(): string
    {
        return $this->writeOff->written_off_at->toIso8601String();
    }

    /**
     * Whether the write-off was a full write-off (the entire outstanding
     * balance was forgiven).
     *
     * Delegates to `LoanWriteOff::isFullWriteOff()` (A.22). Under the
     * frozen Phase 2C flow, this is always `true`; the helper exists for
     * forward compatibility with a future CR that introduces partial
     * write-offs.
     *
     * @return bool
     */
    public function isFullWriteOff(): bool
    {
        return $this->writeOff->isFullWriteOff();
    }

    // =========================================================================
    // Convenience accessors — actor
    // =========================================================================

    /**
     * The user who performed the write-off, or null for a system
     * write-off.
     *
     * Mirrors the public readonly `$actorUserId` property; provided so
     * that listeners that prefer a method call over a property read have
     * a consistent entry point.
     *
     * @return int|null
     */
    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }
}