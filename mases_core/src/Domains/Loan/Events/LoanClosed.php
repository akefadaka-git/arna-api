<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanClosed
 *
 * Domain event reserved for the `settled → closed` transition — the
 * moment a settled loan is administratively closed for all purposes and
 * reaches the final state of its lifecycle.
 *
 * =============================================================================
 * RESERVED — NO PHASE 2C DISPATCHER
 * =============================================================================
 * IMPORTANT: This event is DELIVERED AS A RESERVED ARTEFACT. No Phase 2C
 * code path constructs or dispatches it.
 *
 * The frozen manifest (Step 5) lists this file among the thirteen
 * Loan-domain events, and the frozen C4 state machine (A.2) declares
 * `settled → closed` as a legal transition. However, the frozen Phase 2C
 * scope does NOT include a closure operation:
 *
 *   - No service performs the `settled → closed` transition. The
 *     `LoanStateMachine` (A.26) provides the primitive; no caller
 *     invokes it for this transition.
 *
 *   - No HTTP endpoint exposes closure. The frozen Stage 6 §4.16 API
 *     surface has no `POST /loans/{loan}/close` route.
 *
 *   - No console command performs closure. The two scheduled commands
 *     in the manifest (`AccrueInterestCommand`, A.87;
 *     `DetectArrearsCommand`, A.88) do not touch settled loans.
 *
 *   - No listener registered in `LoanServiceProvider` (A.85) handles
 *     this event, because no such event can fire.
 *
 * The file is delivered for three reasons:
 *
 *   1. **Manifest completeness.** The frozen file manifest is
 *      authoritative. Every file it names is delivered, even when the
 *      file's runtime path is currently unreachable.
 *
 *   2. **Forward compatibility.** A future Change Request that
 *      introduces loan closure (an operator-initiated close, an
 *      end-of-fiscal-year batch closure, or an automatic
 *      close-after-settled sweep) needs a canonical event class. This
 *      file is that class. The CR adds the dispatcher; it does not
 *      author the event.
 *
 *   3. **Registration symmetry.** `LoanServiceProvider` (A.85) registers
 *      all thirteen event classes against `AuditLoanEvent` (A.51). A
 *      listener registered for an event that never fires is inert but
 *      not incorrect; leaving the registration out would create an
 *      asymmetric provider whose registration set silently depends on
 *      which events are "live". The registration is uniform by design.
 *
 * A listener registered for `LoanClosed` under Phase 2C will never
 * receive an invocation. A deployment operator observing an audit trail
 * that lacks a `'loan.closed'` entry is observing correct Phase 2C
 * behaviour, not a defect.
 *
 * =============================================================================
 * When this event WILL fire (future CR)
 * =============================================================================
 * When a future Change Request introduces closure, the transition path
 * will be:
 *
 *     settled → closed
 *
 * with no other inbound path. `settled` is the ONLY status from which
 * `closed` is reachable (per the frozen C4 matrix, A.2); `closed` is a
 * FINAL state with no outgoing transitions.
 *
 * The transition does not require a `transition_reason` per A.26's
 * `REASON_REQUIRED_TRANSITIONS`: the semantic meaning is fully conveyed
 * by its endpoints. A CR that introduces an actor-attributed closure
 * (e.g. "the closing operator") would either:
 *
 *   - Carry the actor as a supplementary `?int $actorUserId` parameter
 *     in this event's constructor (a breaking change to the constructor
 *     signature, requiring a versioned regeneration), or
 *
 *   - Rely on the state machine's `loan_status_histories.actor_user_id`
 *     recording and read the actor from that table.
 *
 * Phase 2C's design choice is the latter: the constructor takes only
 * the loan. The CR is free to revise that choice.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * One property:
 *
 *   $loan  — the loan that entered the `closed` state.
 *
 * When the event eventually fires (post-CR), the carried `$loan` will
 * reflect the state of the loan at event-construction time, consistent
 * with the pattern of every prior Loan event:
 *
 *   - `status` will be the PRE-transition value — `LoanStatus::Settled`
 *     — because the event is constructed before the state machine
 *     performs the `settled → closed` transition.
 *
 *   - All monetary projections will remain at their post-settlement
 *     values (`settled_amount` equal to `total_scheduled_repayment`;
 *     `outstanding_*` all zero).
 *
 * A listener that needs the loan's post-transition state (which will be
 * `LoanStatus::Closed` by the time the after-commit dispatch fires) reads
 * the loan by id, or relies on the `loan_status_histories` row that the
 * state machine appends for the transition.
 *
 * =============================================================================
 * Why the event does NOT carry the actor
 * =============================================================================
 * The reasoning is the same as documented on `LoanApproved` (A.39),
 * `LoanDisbursed` (A.41), and `LoanSettled` (A.48):
 *
 *   - The loan row's actor columns (`approved_by`, `disbursed_by`,
 *     `created_by`) do not describe the closure actor.
 *
 *   - The state machine records the closure actor (if any) on the
 *     corresponding `loan_status_histories.actor_user_id` row for the
 *     `settled → closed` transition. A listener that needs the actor
 *     reads it from the history table.
 *
 *   - There is no persisted Wallet-side artefact for closure (closure
 *     has no monetary consequence — the loan is already fully repaid),
 *     so unlike `LoanSettled` (A.48), the actor is NOT recoverable from
 *     a Wallet Payment or Allocation. The history table is the ONLY
 *     recovery path, and it is authoritative.
 *
 * If a future CR introduces a closure operation whose actor must be
 * available on the event directly (without a history-table query), that
 * CR revises this constructor to accept `?int $actorUserId` as a
 * supplementary parameter — a deliberate breaking change, not an
 * accidental omission.
 *
 * =============================================================================
 * Why the event carries the Loan model
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the principal, the settled
 * amount, the maturity date, or any other attribute reads the
 * corresponding property on `$event->loan` — or calls `loadMissing()` to
 * resolve a relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened` and
 * by the sibling events A.38–A.48), the constructor's primary parameter
 * is the model.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$loan` is `public readonly`. A listener MUST NOT attempt to mutate the
 * loan through this reference; mutations belong to the service layer, and
 * any write from a listener would run outside the service's transaction
 * boundary. The `readonly` modifier enforces this at the language level.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-closure side effect that
 * writes, the correct pattern is for the listener to call a service, not
 * to edit the event's model.
 *
 * =============================================================================
 * Serialization
 * =============================================================================
 * The `Dispatchable` trait supplies the static `dispatch()` entry point
 * and integrates with Laravel's queued-event machinery. When the event
 * eventually fires (post-CR), Phase 2C's dispatch pattern will apply: the
 * event is dispatched synchronously through the synchronous Event
 * dispatcher; the event is not queued.
 *
 * If a future Change Request makes the audit listener queued (or any
 * other listener queued), the model must be serialized. The convention
 * for that scenario is to use `SerializesModels` so that Eloquent's model
 * serialization survives the queue boundary. Phase 2C does not adopt that
 * trait; a future CR would add it.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanClosed::class,
 *         AuditLoanEvent::class,
 *     );
 *
 * The registration is unconditional, even though no Phase 2C code path
 * dispatches the event. See §"RESERVED — NO PHASE 2C DISPATCHER" for the
 * rationale on registering an inert listener.
 *
 * The listener branches on the event class via `match (true)` and writes
 * an audit row through the Core `AuditService`. A listener MUST NOT
 * propagate exceptions to the caller; per the convention established by
 * `AuditShareholderEvent` (2A.35) and `AuditWalletEvent` (2B.47), the
 * listener catches `Throwable`, logs, and returns.
 *
 * The event's audit event-type string, frozen by the manifest, is
 * `'loan.closed'`.
 *
 * =============================================================================
 * The lifecycle terminus
 * =============================================================================
 * In the frozen twelve-state vocabulary (A.2), `closed` is the FINAL
 * state of the loan lifecycle: it has no outgoing transitions. A loan
 * that reaches `closed` is administratively complete; no further
 * lifecycle action is possible without a Change Request.
 *
 * The sequence of lifecycle-milestone events a loan fires across its
 * life is therefore:
 *
 *     LoanApplicationSubmitted   (draft → submitted)
 *     LoanApproved               (under_review → approved)
 *     LoanRejected               (under_review → rejected)    [alternate]
 *     LoanDisbursed              (approved → disbursed)
 *     LoanEnteredArrears         (active → in_arrears)         [optional]
 *     LoanPenaltyRecorded        (per overdue installment)     [optional]
 *     LoanRepaymentRecorded      (per repayment)
 *     LoanInstallmentSettled     (per settled installment)
 *     LoanRestructured           (in_arrears → restructured)   [optional]
 *     LoanWrittenOff             (→ written_off)               [alternate]
 *     LoanSettled                (→ settled)
 *     LoanClosed                 (settled → closed)            [reserved]
 *     LoanCollateralReleased     (per collateral release)      [optional]
 *
 * `LoanClosed` is the last non-optional lifecycle event a loan will fire
 * once a future CR introduces its dispatcher. Under Phase 2C, the last
 * event a loan actually fires is `LoanSettled` (A.48); the loan remains
 * in `settled` indefinitely.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (the primitive the
 *                                                  future CR will invoke)
 *   Phase 2C A.48                                — `LoanSettled` (the
 *                                                  immediate-predecessor
 *                                                  event in the lifecycle)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.49                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanClosed
{
    use Dispatchable;

    /**
     * The loan that entered the `closed` state.
     *
     * When the event eventually fires (post-CR), this instance will be
     * the loan as held by the future closing service immediately BEFORE
     * the state machine performs the `settled → closed` transition. Its
     * `status` property will therefore carry `LoanStatus::Settled`, not
     * `LoanStatus::Closed`. See the class docblock §"What the event
     * carries" for the rationale.
     *
     * Under Phase 2C, the event never fires; the property is documented
     * for the future CR's benefit.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * Create a new event instance.
     *
     * The constructor takes only the loan; the closure actor is not
     * carried. See the class docblock §"Why the event does NOT carry the
     * actor" for the recovery path.
     *
     * Under Phase 2C, no caller constructs this event. The constructor is
     * defined for the future closure CR.
     *
     * @param Loan $loan  The loan that entered the `closed` state.
     */
    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the closed loan.
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
     * The tenant id under which the loan was closed.
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
     * The id of the borrower (shareholder) whose loan was closed.
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

    /**
     * The loan's principal amount, as a decimal string at scale 4.
     *
     * Read directly from the loan's `principal_amount` column.
     *
     * @return string
     */
    public function principalAmount(): string
    {
        return (string) $this->loan->principal_amount;
    }

    /**
     * The total amount the borrower repaid over the life of the loan, as
     * a decimal string at scale 4.
     *
     * Read directly from the loan's `settled_amount` column. By the time
     * the loan reaches `closed`, this value equals
     * `total_scheduled_repayment` (modulo any penalties accrued during
     * the loan's life).
     *
     * @return string
     */
    public function settledAmount(): string
    {
        return (string) $this->loan->settled_amount;
    }

    /**
     * The moment the loan was closed, in ISO 8601 form, or null if the
     * `closed_at` timestamp is unset.
     *
     * Read directly from the loan's `closed_at` column, which the
     * migration A.9 declares nullable and which the state machine (A.26)
     * is the sole writer of. The value is guaranteed to be non-null once
     * the `settled → closed` transition has been recorded; the null
     * branch is defensive for the case where this event is read from a
     * context that constructs it before the transition completes.
     *
     * @return string|null
     */
    public function closedAt(): ?string
    {
        return $this->loan->closed_at?->toIso8601String();
    }
}