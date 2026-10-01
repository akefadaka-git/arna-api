<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanRejected
 *
 * Domain event dispatched when a loan transitions from `under_review` to
 * `rejected` — the moment the lender's decision to decline the loan is
 * recorded, along with the operator's reason for declining.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanApprovalService::reject()` (A.29) AFTER
 * the transaction that moved the loan into the `rejected` state has
 * committed. Two layers cooperate:
 *
 *   1. `LoanApprovalService::reject()` constructs the event instance
 *      (because it has the domain-specific data — the loan, whose
 *      `decision_date` was just written in the same transaction, and the
 *      operator's reason) and passes it to
 *      `LoanStateMachine::transition()` (A.26) via its
 *      `$afterCommitEvent` parameter.
 *
 *   2. `LoanStateMachine::transition()` registers the event for
 *      after-commit dispatch via `DB::afterCommit()`, gated on
 *      `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful
 * `under_review → rejected` transition. If the surrounding transaction
 * rolls back, the event is never dispatched.
 *
 * Note on the transition's inbound shape: the frozen state machine (C4,
 * A.2) does not permit a direct `submitted → rejected` edge. A rejection
 * performed on a loan that is still in `submitted` first triggers a
 * silent `submitted → under_review` transition inside the same
 * transaction (via `LoanApprovalService::ensureUnderReview()`), and then
 * the `under_review → rejected` decision. The `LoanRejected` event fires
 * exactly once per decision operation, regardless of whether the loan
 * entered the operation in `submitted` or `under_review`.
 *
 * =============================================================================
 * The reason requirement
 * =============================================================================
 * The `under_review → rejected` transition is one of the five entries in
 * `LoanStateMachine::REASON_REQUIRED_TRANSITIONS` (A.26). A rejection
 * without a reason is structurally incomplete: the business needs to know
 * WHY a loan was declined, both for regulatory audit and for a future
 * re-application. `LoanApprovalService::reject()` (A.29) enforces the
 * requirement twice — once in a pre-transaction check (fast failure
 * without acquiring a row lock) and once implicitly via the state
 * machine's own guard.
 *
 * The reason is therefore a REQUIRED, non-nullable property of this
 * event. Unlike `LoanApplicationSubmitted` (A.38) and `LoanApproved`
 * (A.39), whose supplementary scalars are optional, `LoanRejected`'s
 * `$reason` is load-bearing: a listener may reasonably rely on its
 * presence without a null check.
 *
 * The reason is also written to the `loan_status_histories` row that the
 * state machine appends for the transition (column
 * `transition_reason`), so it is reconstructible from the loan's
 * lifecycle log without replaying events.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Two properties:
 *
 *   $loan    — the loan instance held by `LoanApprovalService::reject()`
 *              at the moment the event was constructed.
 *
 *   $reason  — the operator's non-empty explanation of the rejection.
 *
 * Under the frozen flow, `LoanApprovalService::reject()` performs the
 * following steps BEFORE constructing the event:
 *
 *   1. Validates that the reason is non-empty (pre-transaction check).
 *   2. Locks the loan row and validates its decidable state.
 *   3. If the loan is still in `submitted`, transitions it to
 *      `under_review` silently.
 *   4. Writes `decision_date` only. The `approved_by` and
 *      `frozen_policy_version_id` columns remain null — the column name
 *      `approved_by` explicitly forbids recording a rejection actor
 *      there (see §"Where the rejecting actor is found").
 *   5. Constructs the event.
 *   6. Calls the state machine to perform the
 *      `under_review → rejected` transition.
 *   7. REFRESHES the same instance the event holds, so that the carried
 *      loan reflects the post-transition state.
 *
 * By the time the after-commit dispatch fires, the event's `$loan`
 * therefore has:
 *
 *   - `status = LoanStatus::Rejected`;
 *   - `decision_date = <the rejection moment>`;
 *   - `approved_by = null`;
 *   - `frozen_policy_version_id = null`.
 *
 * The `$reason` parameter matches the value supplied by the operator at
 * the service boundary, after trim-based validation.
 *
 * =============================================================================
 * Why the event carries the Loan model, not an id
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the decision date, or any other
 * attribute reads the corresponding property on `$event->loan` — or calls
 * `loadMissing()` to resolve a relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened` and
 * by the sibling events A.38 and A.39), the constructor's primary
 * parameter is the model; supplementary scalars are added as additional
 * typed parameters.
 *
 * =============================================================================
 * Where the rejecting actor is found — and why this event does NOT carry it
 * =============================================================================
 * This event deliberately does NOT carry an `$actorUserId` property.
 *
 * The rejecting user is recorded on the `loan_status_histories` row that
 * `LoanStateMachine::transition()` (A.26) appends for the
 * `under_review → rejected` transition, in its `actor_user_id` column.
 * The loan row itself does NOT record the rejecting actor: the only
 * actor column available on `loans` is `approved_by`, whose name
 * explicitly forbids recording a rejection actor against it. Putting the
 * rejector's id in `approved_by` would misrepresent the column's meaning
 * and corrupt downstream reads (e.g. "list loans approved by user X").
 *
 * This is a deliberate divergence from `LoanApplicationSubmitted` (A.38),
 * which carries `$actorUserId` because the submitting actor has NO
 * persisted home on the loan row (the row records only `created_by`).
 * The full actor-persistence rule across the three events is:
 *
 *   A.38 LoanApplicationSubmitted  — actor NOT persisted on loan row
 *                                    (only `created_by`, which may be a
 *                                    different user); event carries it.
 *   A.39 LoanApproved              — actor IS persisted on loan row
 *                                    (`approved_by`); event omits it.
 *   A.40 LoanRejected              — actor IS persisted, but on
 *                                    `loan_status_histories.actor_user_id`,
 *                                    NOT on the loan row; event omits it.
 *
 * A listener that needs the rejecting actor MUST query the loan's most
 * recent `loan_status_histories` row for the transition. This is a
 * deliberate trade-off: the event stays a pure DTO of the loan's
 * post-rejection state plus the reason, and the actor's authoritative
 * home remains the append-only history log — consistent with the
 * platform's discipline that every lifecycle actor is recorded in
 * exactly one place.
 *
 * Note: `LoanApprovalService::reject()` (A.29) passes the actor to
 * `LoanStateMachine::transition()` as its `actorUserId:` argument, so the
 * history row is guaranteed to carry it (or `null` for a system
 * rejection). The actor is therefore always recoverable, even though it
 * is not on the event itself.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * Both properties are `public readonly`. A listener MUST NOT attempt to
 * mutate the loan through this reference; mutations belong to the service
 * layer, and any write from a listener would run outside the service's
 * transaction boundary. The `readonly` modifier enforces this at the
 * language level: PHP raises an `Error` on any attempted reassignment.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-rejection side effect
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
 *         LoanRejected::class,
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
 * `'loan.rejected'`.
 *
 * =============================================================================
 * Terminal-state note
 * =============================================================================
 * `rejected` is a terminal AND final state in the frozen twelve-state
 * vocabulary (A.2). It has no outgoing transitions — a rejected loan
 * cannot be revived, resubmitted, or reopened; a new application must be
 * filed as a new loan. This is why no "un-reject" event exists and why
 * the audit listener may treat `LoanRejected` as a terminal decision
 * marker.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *                                                  (REASON_REQUIRED_TRANSITIONS,
 *                                                  the status history write)
 *   Phase 2C A.29                                — `LoanApprovalService`
 *                                                  (the sole constructor
 *                                                  caller)
 *   Phase 2C A.38                                — `LoanApplicationSubmitted`
 *                                                  (the sibling event that
 *                                                  DOES carry the actor)
 *   Phase 2C A.39                                — `LoanApproved` (the
 *                                                  sibling event of the
 *                                                  same service)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.40                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanRejected
{
    use Dispatchable;

    /**
     * The loan that entered the `rejected` state.
     *
     * Under the frozen flow, this instance was constructed by
     * `LoanApprovalService::reject()` (A.29) after `decision_date` was
     * written and after the state machine performed the transition; the
     * service then refreshed the instance, so the carried loan reflects
     * the post-transition state. See the class docblock §"What the event
     * carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The operator's reason for rejecting the loan.
     *
     * Non-empty by construction: `LoanApprovalService::reject()` (A.29)
     * refuses to reject a loan without a reason, and the state machine's
     * `REASON_REQUIRED_TRANSITIONS` set (A.26) enforces the same rule
     * structurally. A listener may rely on this property being non-empty
     * without a `trim()`-based guard of its own.
     *
     * The same value is written to the corresponding
     * `loan_status_histories.transition_reason` row, so the reason is
     * reconstructible from the loan's lifecycle log independent of this
     * event.
     *
     * Read-only.
     *
     * @var string
     */
    public readonly string $reason;

    /**
     * Create a new event instance.
     *
     * The constructor takes the loan and the rejection reason. The
     * rejecting actor is intentionally NOT a parameter — see the class
     * docblock §"Where the rejecting actor is found" for the rationale
     * and for how to recover the actor from the loan's status history.
     *
     * @param Loan   $loan    The loan that entered the `rejected` state.
     * @param string $reason  The operator's non-empty rejection reason.
     */
    public function __construct(Loan $loan, string $reason)
    {
        $this->loan   = $loan;
        $this->reason = $reason;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the rejected loan.
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
     * The tenant id under which the loan was rejected.
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
     * The id of the borrower (shareholder) whose loan was rejected.
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
     * The id of the loan product under which the rejected application was
     * filed.
     *
     * Read directly from the loan's `loan_product_id` column. Note that a
     * rejected loan has NO frozen policy version — the freeze happens
     * only on approval, and a rejection leaves
     * `frozen_policy_version_id` null.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The operator's rejection reason.
     *
     * Mirrors the public readonly `$reason` property; provided so that
     * listeners that prefer a method call over a property read have a
     * consistent entry point.
     *
     * @return string
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The moment the rejection decision was recorded, in ISO 8601 form,
     * or null if the decision date is unset.
     *
     * Read directly from the loan's `decision_date` column, which
     * `LoanApprovalService::reject()` (A.29) writes before constructing
     * this event. The value is guaranteed to be non-null by the time the
     * event is dispatched; the null branch is defensive.
     *
     * @return string|null
     */
    public function decisionDate(): ?string
    {
        return $this->loan->decision_date?->toIso8601String();
    }
}