<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanApproved
 *
 * Domain event dispatched when a loan transitions from `under_review` to
 * `approved` — the moment the lender's decision to grant the loan is
 * recorded, the product's policy version is frozen onto the loan, and the
 * loan becomes eligible for disbursement.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanApprovalService::approve()` (A.29) AFTER
 * the transaction that moved the loan into the `approved` state has
 * committed. Two layers cooperate:
 *
 *   1. `LoanApprovalService::approve()` constructs the event instance
 *      (because it has the domain-specific data — the loan, whose
 *      approval metadata and frozen policy version reference were
 *      written earlier in the same transaction) and passes it to
 *      `LoanStateMachine::transition()` (A.26) via its
 *      `$afterCommitEvent` parameter.
 *
 *   2. `LoanStateMachine::transition()` registers the event for
 *      after-commit dispatch via `DB::afterCommit()`, gated on
 *      `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful
 * `under_review → approved` transition. If the surrounding transaction
 * rolls back, the event is never dispatched.
 *
 * Note on the transition's inbound shape: the frozen state machine (C4,
 * A.2) does not permit a direct `submitted → approved` edge. An approval
 * performed on a loan that is still in `submitted` first triggers a
 * silent `submitted → under_review` transition inside the same
 * transaction (via `LoanApprovalService::ensureUnderReview()`), and then
 * the `under_review → approved` decision. The `LoanApproved` event fires
 * exactly once per decision operation, regardless of whether the loan
 * entered the operation in `submitted` or `under_review`.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * One property:
 *
 *   $loan  — the loan instance held by `LoanApprovalService::approve()`
 *            at the moment the event was constructed.
 *
 * Under the frozen flow, `LoanApprovalService::approve()` performs the
 * following steps BEFORE constructing the event:
 *
 *   1. Locks the loan row and validates its decidable state.
 *   2. If the loan is still in `submitted`, transitions it to
 *      `under_review` silently.
 *   3. Freezes the product's policy version onto the loan, writes
 *      `approved_by`, and writes `decision_date`.
 *   4. Constructs the event.
 *   5. Calls the state machine to perform the
 *      `under_review → approved` transition.
 *   6. REFRESHES the same instance the event holds, so that the carried
 *      loan reflects the post-transition state.
 *
 * By the time the after-commit dispatch fires, the event's `$loan`
 * therefore has:
 *
 *   - `status = LoanStatus::Approved`;
 *   - `approved_by = <the acting user id>`;
 *   - `decision_date = <the approval moment>`;
 *   - `frozen_policy_version_id = <the product's policy version id>`.
 *
 * This is different from `LoanApplicationSubmitted` (A.38), whose
 * carried loan retains its pre-transition `status = Draft` because
 * `LoanApplicationService::submit()` does not refresh the model after
 * the transition. The two events reflect the same principle — the
 * event's meaning comes from the class, not from the carried model's
 * state — but they happen to observe different moments of the model's
 * lifecycle. A listener that needs a specific status reading reads it
 * explicitly (via `$event->loan->status`), or queries the loan by id
 * for a fresh read.
 *
 * =============================================================================
 * Why the event carries the Loan model, not an id
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the approved amount, the frozen
 * policy version, or any other attribute reads the corresponding
 * property on `$event->loan` — or calls `loadMissing()` to resolve a
 * relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened` and
 * by the sibling `LoanApplicationSubmitted`, A.38), the constructor's
 * primary parameter is the model.
 *
 * =============================================================================
 * Where the approving actor is found
 * =============================================================================
 * The event does NOT carry a separate `$actorUserId` property. The
 * approving user is available on the loan itself, via the
 * `approved_by` column that `LoanApprovalService::approve()` writes
 * BEFORE constructing the event. Listeners read
 * `$event->loan->approved_by` (or call the `approvedByUserId()`
 * convenience accessor).
 *
 * This differs from `LoanApplicationSubmitted` (A.38), which carries a
 * separate `$actorUserId` parameter. The distinction is deliberate:
 *
 *   - A submission actor is a transient fact — the submitting user is
 *     not persisted on the loan row (which only records the draft's
 *     creator via `created_by`), so the event must carry it.
 *
 *   - An approval actor is a persisted fact — the `approved_by` column
 *     records it on the loan row permanently, so the event can rely on
 *     the model.
 *
 * The same reasoning applies to `LoanRejected` (A.40), except that a
 * rejection does NOT set `approved_by` (the column's name forbids it).
 * The rejection actor is therefore carried explicitly by that event.
 *
 * =============================================================================
 * Read-only contract
 * =============================================================================
 * `$loan` is `public readonly`. A listener MUST NOT attempt to mutate the
 * loan through this reference; mutations belong to the service layer, and
 * any write from a listener would run outside the service's transaction
 * boundary. The `readonly` modifier enforces this at the language level:
 * PHP raises an `Error` on any attempted reassignment.
 *
 * The model instance itself remains mutable by its own API (fill/save),
 * because PHP's `readonly` applies to the property binding, not to the
 * object's internal state. Listeners are expected to observe, not to
 * mutate; if a future requirement demands a post-approval side effect
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
 *         LoanApproved::class,
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
 * `'loan.approved'`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.29                                — `LoanApprovalService`
 *   Phase 2C A.38                                — `LoanApplicationSubmitted`
 *                                                  (the sibling event with
 *                                                  a different actor
 *                                                  convention)
 *   Phase 2C A.40                                — `LoanRejected` (the
 *                                                  sibling event of the
 *                                                  same service)
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.39                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanApproved
{
    use Dispatchable;

    /**
     * The loan that entered the `approved` state.
     *
     * Under the frozen flow, this instance was constructed by
     * `LoanApprovalService::approve()` (A.29) after the approval
     * metadata was written and after the state machine performed the
     * transition; the service then refreshed the instance, so the
     * carried loan reflects the post-transition state. See the class
     * docblock §"What the event carries" for the full sequence.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * Create a new event instance.
     *
     * The constructor takes only the loan; the approving actor is read
     * from the loan's `approved_by` column by any listener that needs
     * it. See the class docblock §"Where the approving actor is found".
     *
     * @param Loan $loan  The loan that entered the `approved` state.
     */
    public function __construct(Loan $loan)
    {
        $this->loan = $loan;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the approved loan.
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
     * The tenant id under which the loan was approved.
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
     * The id of the borrower (shareholder) whose loan was approved.
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
     * The id of the loan product the approved loan belongs to.
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
     * The id of the versioned `loan_product_rules` policy frozen onto the
     * loan at approval.
     *
     * Read directly from the loan's `frozen_policy_version_id` column. The
     * value is guaranteed to be non-null by the time this event is
     * dispatched, because `LoanApprovalService::approve()` (A.29) refuses
     * to approve a loan whose product has no policy version.
     *
     * @return int
     */
    public function frozenPolicyVersionId(): int
    {
        return (int) $this->loan->frozen_policy_version_id;
    }

    /**
     * The user who approved the loan, or null for a system approval.
     *
     * Read directly from the loan's `approved_by` column, which
     * `LoanApprovalService::approve()` (A.29) writes before constructing
     * this event. See the class docblock §"Where the approving actor is
     * found".
     *
     * @return int|null
     */
    public function approvedByUserId(): ?int
    {
        $approvedBy = $this->loan->approved_by;

        return $approvedBy !== null ? (int) $approvedBy : null;
    }

    /**
     * The moment the approval was recorded, in ISO 8601 form, or null if
     * the decision date is unset.
     *
     * Read directly from the loan's `decision_date` column. The value is
     * guaranteed to be non-null by the time this event is dispatched,
     * because `LoanApprovalService::approve()` (A.29) writes it before
     * constructing this event. The null branch is defensive.
     *
     * @return string|null
     */
    public function decisionDate(): ?string
    {
        return $this->loan->decision_date?->toIso8601String();
    }
}