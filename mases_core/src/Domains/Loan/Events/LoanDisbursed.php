<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanDisbursed
 *
 * Domain event dispatched when a loan transitions from `approved` to
 * `disbursed` — the moment the lender records the principal obligation
 * against the borrower's Wallet and the loan becomes financially live.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanDisbursementService::disburse()` (A.30)
 * AFTER the transaction that recorded the Wallet principal obligation,
 * generated the schedule, wrote the disbursement metadata, and performed
 * the two state transitions (`approved → disbursed`, then the transient
 * `disbursed → active`) has committed. Two layers cooperate:
 *
 *   1. `LoanDisbursementService::disburse()` constructs the event
 *      instance (because it has the domain-specific data — the loan, the
 *      Wallet's primary key, and the Wallet obligation's primary key) and
 *      passes it to `LoanStateMachine::transition()` (A.26) via the
 *      `$afterCommitEvent` parameter of the FIRST transition
 *      (`approved → disbursed`).
 *
 *   2. `LoanStateMachine::transition()` registers the event for
 *      after-commit dispatch via `DB::afterCommit()`, gated on
 *      `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful
 * disbursement. If the surrounding transaction rolls back — for any
 * reason, including a failure in the SECOND transition (`disbursed →
 * active`) — the event is never dispatched, because `DB::afterCommit()`
 * fires only after the OUTERMOST transaction commits.
 *
 * The event is attached to the FIRST transition (`approved → disbursed`),
 * not the second. The first is the audit-relevant milestone: the moment
 * the disbursement is recorded. The second (`disbursed → active`) is a
 * bookkeeping step that settles the loan into its working state; it
 * carries no event because the milestone it would mark is the same one
 * the first transition already marked.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Three properties:
 *
 *   $loan                — the loan instance held by
 *                          `LoanDisbursementService::disburse()` at the
 *                          moment the event was constructed.
 *
 *   $walletId            — the primary key of the borrower's Wallet, the
 *                          target of the principal obligation.
 *
 *   $walletObligationId  — the primary key of the Wallet obligation that
 *                          was recorded for the loan's principal.
 *
 * The carried `$loan` is the instance the service held immediately BEFORE
 * calling `LoanStateMachine::transition()`. Under the frozen A.30 flow,
 * that instance has had the following writes performed on it in the same
 * transaction:
 *
 *   - `currency` — copied from the borrower's Wallet (Q8 invariant).
 *   - `disbursement_date` — set to the moment of the operation.
 *   - `outstanding_principal` — set to the loan's principal amount.
 *   - All four schedule summary columns — written by the schedule
 *     generation step (`monthly_installment_amount`,
 *     `total_scheduled_interest`, `total_scheduled_repayment`,
 *     `maturity_date`).
 *
 * IMPORTANT — the carried loan's `status` value:
 *
 *     By construction of A.30, the event's `$loan->status` remains
 *     `LoanStatus::Approved` at dispatch time, NOT `Disbursed` and NOT
 *     `Active`. The state machine performs the two transitions on a
 *     freshly-loaded instance and reassigns the service's `$current`
 *     local; the event holds the PRE-transition instance. This is a
 *     deliberate property of the frozen A.30 flow (see that file's
 *     §"Event construction"); it is documented here so listeners are not
 *     surprised.
 *
 *     A listener that needs the loan's post-transition state (which will
 *     be `Active` by the time the after-commit dispatch fires) reads the
 *     loan by id, or relies on the `loan_status_histories` rows the state
 *     machine appended for the two transitions.
 *
 * =============================================================================
 * The Wallet linkage (why this event carries two Wallet ids)
 * =============================================================================
 * Disbursement is the FIRST Loan-domain operation that produces a
 * monetary fact on the Wallet (per the B2 hybrid-ledger freeze). The event
 * therefore carries the two Wallet-side identifiers that the operation
 * produced, so that a listener can correlate the Loan-domain event with
 * the Wallet-domain facts without an extra query:
 *
 *   $walletId            — the borrower's Wallet, resolvable by
 *                          `Wallet::find($event->walletId())`.
 *
 *   $walletObligationId  — the specific obligation the disbursement
 *                          recorded, resolvable by
 *                          `Obligation::find($event->walletObligationId())`.
 *
 * The Wallet domain ALSO dispatches its own after-commit
 * `ObligationRecorded` event for the same obligation. The two events are
 * independent and complementary: the Wallet event records "an obligation
 * was recorded on Wallet X"; this Loan event records "loan Y was
 * disbursed, producing obligation Z on Wallet X". A listener that needs
 * to stitch the two audit streams together uses the obligation id as the
 * join key.
 *
 * =============================================================================
 * Why the event carries the Loan model, not an id
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the principal, the schedule
 * summary, the disbursement date, or any other attribute reads the
 * corresponding property on `$event->loan` — or calls `loadMissing()` to
 * resolve a relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened` and
 * by the sibling events A.38, A.39, A.40), the constructor's primary
 * parameter is the model; supplementary scalars are added as additional
 * typed parameters.
 *
 * =============================================================================
 * Why the event does NOT carry the actor
 * =============================================================================
 * Unlike `LoanInstallmentSettled` (A.43), `LoanEnteredArrears` (A.44),
 * `LoanPenaltyRecorded` (A.45), and `LoanWrittenOff` (A.47), this event
 * does NOT carry an `$actorUserId` property.
 *
 * The disbursement actor is preserved in the Wallet's audit trail:
 * `LoanDisbursementService::disburse()` (A.30) passes `$actorUserId` to
 * `ObligationService::record(..., createdBy: $actorUserId)`, which
 * records it on the Wallet obligation and on the corresponding
 * `wallet_entries` row. A listener that needs the actor reads it from
 * either of those Wallet-side records, indexed by the
 * `$walletObligationId` this event carries.
 *
 * This mirrors the pattern established by `LoanApproved` (A.39), whose
 * actor is preserved on the loan row (`approved_by`) and is therefore
 * not carried separately. In both cases, the actor has a persisted home
 * elsewhere; the event does not duplicate it.
 *
 * Note: A.30's `disburse()` currently does NOT write to
 * `loans.disbursed_by`. That column exists in the migration (A.9) and is
 * intended to record the disbursing user, but the frozen A.30 flow does
 * not populate it. A listener that needs the actor MUST read it from the
 * Wallet side, not from the loan row.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * All three properties are `public readonly`. A listener MUST NOT attempt
 * to mutate the loan through this reference; mutations belong to the
 * service layer, and any write from a listener would run outside the
 * service's transaction boundary. The `readonly` modifier enforces this
 * at the language level: PHP raises an `Error` on any attempted
 * reassignment.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-disbursement side effect
 * that writes, the correct pattern is for the listener to call a service,
 * not to edit the event's model.
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
 * other listener queued), the model must be serialized. The convention
 * for that scenario is to use `SerializesModels` so that Eloquent's
 * model serialization survives the queue boundary. Phase 2C does not
 * adopt that trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanDisbursed::class,
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
 * `'loan.disbursed'`.
 *
 * =============================================================================
 * The transient-disbursed note
 * =============================================================================
 * The frozen state machine (C4, A.2) places the loan in `disbursed` only
 * momentarily: the `disbursed → active` transition fires in the SAME
 * transaction as the `approved → disbursed` transition, so no external
 * reader observes the loan in `disbursed` state. The event is dispatched
 * for the FIRST transition (into `disbursed`); by the time the
 * after-commit callback fires, the loan in the database is `active`.
 *
 * The event class is named for the transition that produced it, not for
 * the loan's transient status. A listener that reads
 * `$event->loan->status` on the carried instance will see `approved`
 * (per §"What the event carries"); a listener that re-queries the loan
 * by id will see `active`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D129, D135, B1,
 *                                                  B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (called during
 *                                                  disbursement)
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (the sibling
 *                                                  top-level service)
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.39                                — `LoanApproved` (the
 *                                                  preceding event in
 *                                                  the lifecycle)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2B `ObligationRecorded`                — the Wallet-side
 *                                                  companion event
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.41                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanDisbursed
{
    use Dispatchable;

    /**
     * The loan whose disbursement produced this event.
     *
     * The instance was constructed by
     * `LoanDisbursementService::disburse()` (A.30) BEFORE the state
     * machine performed the `approved → disbursed` transition. Its
     * `status` property therefore carries `LoanStatus::Approved`, not
     * `LoanStatus::Disbursed` or `LoanStatus::Active`. See the class
     * docblock §"What the event carries" for the rationale and for the
     * correct way to read the loan's post-transition state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The primary key of the borrower's Wallet — the target of the
     * principal obligation recorded by the disbursement.
     *
     * Passed explicitly by `LoanDisbursementService::disburse()` (A.30)
     * because it has just resolved the Wallet and holds its id in the
     * local `$wallet` variable. The event carries it so that listeners
     * can correlate the Loan-domain event with the Wallet-domain facts
     * without a re-query.
     *
     * Read-only.
     *
     * @var int
     */
    public readonly int $walletId;

    /**
     * The primary key of the Wallet obligation recorded for the loan's
     * principal.
     *
     * Passed explicitly by `LoanDisbursementService::disburse()` (A.30)
     * because it holds the obligation instance returned by
     * `ObligationService::record()` in the local `$obligation` variable.
     * The event carries it as the join key between this Loan-domain event
     * and the Wallet domain's own `ObligationRecorded` event, which fires
     * for the same obligation in the same transaction.
     *
     * Read-only.
     *
     * @var int
     */
    public readonly int $walletObligationId;

    /**
     * Create a new event instance.
     *
     * The constructor takes the loan and the two Wallet-side identifiers
     * that the disbursement produced. The disbursing actor is
     * intentionally NOT a parameter — see the class docblock §"Why the
     * event does NOT carry the actor".
     *
     * @param Loan $loan                The loan that entered the
     *                                  `disbursed` state.
     * @param int  $walletId            The borrower's Wallet primary key.
     * @param int  $walletObligationId  The Wallet obligation's primary
     *                                  key.
     */
    public function __construct(
        Loan $loan,
        int $walletId,
        int $walletObligationId,
    ) {
        $this->loan               = $loan;
        $this->walletId           = $walletId;
        $this->walletObligationId = $walletObligationId;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the disbursed loan.
     *
     * Convenience accessor for listeners that need only the identifier.
     * Equivalent to `$event->loan->getKey()`.
     *
     * @return int
     */
    public function loanId(): int
    {
        return (int) $this->loan->getKey();
    }

    /**
     * The tenant id under which the loan was disbursed.
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
     * The id of the borrower (shareholder) whose loan was disbursed.
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
     * The id of the loan product under which the disbursed loan was
     * originated.
     *
     * Read directly from the loan's `loan_product_id` column.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The primary key of the borrower's Wallet.
     *
     * Mirrors the public readonly `$walletId` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return int
     */
    public function walletId(): int
    {
        return $this->walletId;
    }

    /**
     * The primary key of the Wallet obligation recorded by the
     * disbursement.
     *
     * Mirrors the public readonly `$walletObligationId` property. The
     * value is the join key between this Loan-domain event and the Wallet
     * domain's `ObligationRecorded` event for the same obligation.
     *
     * @return int
     */
    public function walletObligationId(): int
    {
        return $this->walletObligationId;
    }

    /**
     * The moment the disbursement was recorded, in ISO 8601 form, or null
     * if the disbursement date is unset.
     *
     * Read directly from the loan's `disbursement_date` column, which
     * `LoanDisbursementService::disburse()` (A.30) writes before
     * constructing this event. The value is guaranteed to be non-null by
     * the time the event is dispatched; the null branch is defensive.
     *
     * @return string|null
     */
    public function disbursementDate(): ?string
    {
        return $this->loan->disbursement_date?->toIso8601String();
    }
}