<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanApplicationSubmitted
 *
 * Domain event dispatched when a loan transitions from `draft` to
 * `submitted` — the moment the applicant turns their working draft into a
 * formal application awaiting review.
 *
 * =============================================================================
 * When this event fires
 * =============================================================================
 * The event is dispatched by `LoanApplicationService::submit()` (A.28)
 * AFTER the transaction that moved the loan into the `submitted` state has
 * committed. Two layers cooperate:
 *
 *   1. `LoanApplicationService::submit()` constructs the event instance
 *      (because it has the domain-specific data — the loan and the acting
 *      user) and passes it to `LoanStateMachine::transition()` (A.26) via
 *      its `$afterCommitEvent` parameter.
 *
 *   2. `LoanStateMachine::transition()` registers the event for
 *      after-commit dispatch via `DB::afterCommit()`, gated on
 *      `config('loan.audit.emit_events')` (A.1, default `true`).
 *
 * Consequence: the event fires once, and only once, per successful
 * `draft → submitted` transition. If the surrounding transaction rolls
 * back — for any reason, whether a validation failure inside the state
 * machine or a downstream failure in a subsequent step of a larger
 * operation — the event is never dispatched.
 *
 * =============================================================================
 * What the event carries, and the state of the loan at dispatch time
 * =============================================================================
 * Two properties:
 *
 *   $loan         — the loan instance the service held at the moment the
 *                   event was constructed.
 *
 *   $actorUserId  — the user who submitted the application, or null for a
 *                   system-initiated submission.
 *
 * The loan instance the event carries was constructed by
 * `LoanApplicationService::submit()` BEFORE the state machine performed
 * the transition. That instance therefore has `status = LoanStatus::Draft`,
 * not `LoanStatus::Submitted`. This is the frozen pattern for every
 * Loan-domain event dispatched through the state machine's
 * `$afterCommitEvent` parameter — see the class docblocks of the sibling
 * events and of `LoanApprovalService` (A.29) §"Event construction" for the
 * rationale.
 *
 * The event's semantic — "the application was submitted" — is carried by
 * the event CLASS, not by the state of the loan it holds. A listener that
 * needs the loan's post-transition state reads the loan by id (`Loan::find($event->loanId())`)
 * or relies on the corresponding `loan_status_histories` row, which the
 * state machine writes with the correct `to_status = 'submitted'`.
 *
 * =============================================================================
 * Why the actor is a separate property, not derived from the loan
 * =============================================================================
 * The loan's `created_by` column records the user who CREATED THE DRAFT.
 * The user who SUBMITTED it may be a different user (for example, an
 * operator submitting on behalf of an applicant, or an automated process
 * finishing a partially-prepared application). The event therefore carries
 * the submitting actor explicitly, via the `$actorUserId` property.
 *
 * The same reasoning applies to the loan's `loan_status_histories` row
 * written by `LoanStateMachine` (A.26): its `actor_user_id` column
 * records the submitting user, not the creating user.
 *
 * If a future business requirement disallows submission by a user other
 * than the draft's creator, that is a Change Request against
 * `LoanApplicationService::submit()` (A.28), not a change to this event.
 *
 * =============================================================================
 * Why the event carries the Loan model, not an id
 * =============================================================================
 * The event exposes the full `Loan` model as a public readonly property.
 * A listener that needs only the id reads `$event->loanId()`; a listener
 * that needs the borrower, the product, the principal, or any other
 * attribute reads the corresponding property on `$event->loan` — or calls
 * `loadMissing()` to resolve a relation.
 *
 * The alternative — carrying only scalar primitives — would force every
 * listener to re-query the row, defeating the purpose of dispatching the
 * event. Per the Event convention (established by Phase 2A's
 * `ShareholderRegistered` and followed by Phase 2B's `WalletOpened`), the
 * constructor's primary parameter is the model; supplementary scalars are
 * added as additional typed parameters.
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
 * mutate; if a future requirement demands a post-submission side effect
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
 * for that scenario — established by Phase 2A's event classes — is to
 * use `SerializesModels` so that Eloquent's model serialization
 * survives the queue boundary. Phase 2C does not adopt that trait,
 * because no listener is queued. A future CR that queues a listener
 * would add the trait to every event class that listener handles.
 *
 * =============================================================================
 * Listener registration
 * =============================================================================
 * Registered in `LoanServiceProvider::registerEventListeners()` (A.85)
 * against the single `AuditLoanEvent` listener (A.51):
 *
 *     Event::listen(
 *         LoanApplicationSubmitted::class,
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
 * `'loan.application.submitted'`.
 *
 * =============================================================================
 * Immutability note
 * =============================================================================
 * There is NO corresponding `LoanApplicationWithdrawn` event. Phase 2C
 * does not implement withdrawal — the frozen twelve-state vocabulary
 * (A.2) contains no `withdrawn` case, and `LoanApplicationService` (A.28)
 * owns draft creation and submission only. A future Change Request that
 * introduces withdrawal would add the `withdrawn` state, its transitions,
 * a `LoanApplicationWithdrawn` event, and the wiring. That CR's scope is
 * not Phase 2C.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.28                                — `LoanApplicationService`
 *   Phase 2C A.51                                — `AuditLoanEvent`
 *   Phase 2C A.85                                — `LoanServiceProvider`
 *   Phase 2A `ShareholderRegistered`             — the event-class template
 *   Phase 2B `WalletOpened`                      — the dispatch pattern
 *   Step 5 (manifest) A.38                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Events
 * @version 1.0.0
 */
final class LoanApplicationSubmitted
{
    use Dispatchable;

    /**
     * The loan whose draft-to-submitted transition produced this event.
     *
     * The instance was constructed by `LoanApplicationService::submit()`
     * (A.28) BEFORE the state machine performed the transition. Its
     * `status` property therefore carries `LoanStatus::Draft`, not
     * `LoanStatus::Submitted`. See the class docblock §"What the event
     * carries" for the rationale and for the correct way to read the
     * loan's post-transition state.
     *
     * Read-only. Listeners MUST treat the model as immutable.
     *
     * @var Loan
     */
    public readonly Loan $loan;

    /**
     * The user who submitted the application, or null for a
     * system-initiated submission.
     *
     * This is NOT the same user as the loan's `created_by` column, which
     * records the user who created the draft. See the class docblock
     * §"Why the actor is a separate property" for the rationale.
     *
     * Read-only.
     *
     * @var int|null
     */
    public readonly ?int $actorUserId;

    /**
     * Create a new event instance.
     *
     * @param Loan     $loan         The loan that entered the `submitted`
     *                               state.
     * @param int|null $actorUserId  The submitting user, or null.
     */
    public function __construct(Loan $loan, ?int $actorUserId = null)
    {
        $this->loan        = $loan;
        $this->actorUserId = $actorUserId;
    }

    // =========================================================================
    // Convenience accessors
    // =========================================================================

    /**
     * The primary key of the submitted loan.
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
     * The tenant id under which the loan was submitted.
     *
     * Read directly from the loan's `tenant_id` column — no relation
     * traversal is required, because the `loans` table carries the
     * tenant scope directly (unlike the `wallet_*` tables from Phase 2B,
     * whose tenant scope is inherited through the wallet).
     *
     * @return int
     */
    public function tenantId(): int
    {
        return (int) $this->loan->tenant_id;
    }

    /**
     * The id of the borrower (shareholder) the loan was submitted for.
     *
     * Read directly from the loan's `borrower_shareholder_id` column.
     * Provided as a convenience for listeners that audit submissions at
     * the borrower level without loading the loan's `borrower` relation.
     *
     * @return int
     */
    public function borrowerId(): int
    {
        return (int) $this->loan->borrower_shareholder_id;
    }

    /**
     * The id of the loan product the application was submitted against.
     *
     * Read directly from the loan's `loan_product_id` column. Provided
     * as a convenience for listeners that audit submissions at the
     * product level without loading the loan's `product` relation.
     *
     * @return int
     */
    public function loanProductId(): int
    {
        return (int) $this->loan->loan_product_id;
    }

    /**
     * The user who submitted the application, or null for a
     * system-initiated submission.
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