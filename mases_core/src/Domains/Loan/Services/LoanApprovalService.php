<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApproved;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRejected;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;

/**
 * LoanApprovalService
 *
 * Owns the DECISION phase of a loan's lifecycle: approval and rejection.
 *
 * =============================================================================
 * Scope — decision only
 * =============================================================================
 * This service owns exactly TWO operations:
 *
 *   approve()  — records an approval decision on a submitted or
 *                under-review loan; freezes the product's policy version
 *                onto the loan; transitions the loan to `approved`.
 *
 *   reject()   — records a rejection decision on a submitted or
 *                under-review loan; transitions the loan to `rejected`.
 *
 * Everything BEFORE the decision — draft creation, submission — belongs
 * to `LoanApplicationService` (A.28). Everything AFTER — disbursement,
 * repayment, interest accrual, arrears, restructure, write-off,
 * settlement, closure — belongs to its own service.
 *
 * =============================================================================
 * The intermediate `under_review` step
 * =============================================================================
 * The frozen state machine (C4, A.2) declares the decision path as:
 *
 *     submitted → under_review → approved
 *     submitted → under_review → rejected
 *
 * There is no direct edge from `submitted` to `approved` or `rejected`.
 * The transition `submitted → under_review` is documented on A.2 as
 * "automatic on reviewer assignment, or explicit on manual transition."
 *
 * Phase 2C has no separate reviewer-assignment operation or endpoint. The
 * `under_review` state therefore serves as a bookkeeping marker for "a
 * reviewer has picked up the loan and is evaluating it". This service
 * treats both `submitted` and `under_review` as the set of DECIDABLE
 * states, and — when a loan arrives in `submitted` — performs the
 * `submitted → under_review` transition silently as the first step of the
 * decision operation.
 *
 * Consequence: an approval or rejection performed on a `submitted` loan
 * produces TWO history rows (the review pickup and the decision). An
 * approval or rejection performed on an already-`under_review` loan
 * produces ONE history row (the decision only). Both cases are correct;
 * the difference reflects whether a reviewer had already been marked as
 * having picked up the loan.
 *
 * =============================================================================
 * The decision metadata written by this service
 * =============================================================================
 * The migration A.9 declares three columns that this service is the SOLE
 * writer of:
 *
 *     approved_by               — set on approval only (not on rejection).
 *     decision_date             — set on both approval and rejection.
 *     frozen_policy_version_id  — set on approval only.
 *
 * On APPROVAL, all three are written:
 *
 *   - `approved_by` = the acting user (nullable for system approvals).
 *   - `decision_date` = the moment of the decision.
 *   - `frozen_policy_version_id` = the product's current
 *     `product_rules_policy_version_id`, copied onto the loan. This is
 *     the B6/Extra-2 freeze: a subsequent publication of a new policy
 *     version does not retroactively affect the loan's terms.
 *
 * On REJECTION, only `decision_date` is written. The `approved_by` and
 * `frozen_policy_version_id` columns remain null. The rejecting actor is
 * recorded on the corresponding `loan_status_histories` row via its
 * `actor_user_id` column, not on the loan's `approved_by` column — the
 * column's name is "approved_by", not "decided_by", and putting a
 * rejection actor there would misrepresent its meaning.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * Both operations open their OWN transaction. This service is the
 * TOP-LEVEL entry point for its operations (the controller calls
 * `approve()` or `reject()` directly), so there is no outer transaction.
 *
 * The transaction wraps:
 *
 *   - the row lock on the loan;
 *   - the domain validations (product policy version presence);
 *   - the write of the decision metadata columns;
 *   - the state machine's transition (which is invoked INSIDE the
 *     transaction, satisfying its own `assertInTransaction()` guard);
 *   - the after-commit event registration.
 *
 * This mirrors the layering of `LoanApplicationService` (A.28): the
 * top-level service owns the transaction; the state machine requires one.
 *
 * =============================================================================
 * Event construction — a note on the carried model's state
 * =============================================================================
 * The events `LoanApproved` (A.39) and `LoanRejected` (A.40) are
 * constructed by this service with the loan instance as it stood
 * IMMEDIATELY BEFORE the state machine's transition. That is: with the
 * decision metadata columns already set, but with `status` still equal to
 * `under_review`.
 *
 * This is the frozen pattern for Loan-domain events (see A.28, which
 * constructs `LoanApplicationSubmitted` the same way). The event class is
 * authoritative for the event's semantic ("this loan was approved" or
 * "this loan was rejected"); the carried model is the context, not the
 * state record. A listener that needs the loan's post-transition state
 * reads the loan by id (or relies on the state machine's own history row,
 * which is written with the correct `to_status`).
 *
 * After the state machine's transition returns, this service refreshes
 * the same instance the event holds, so that by the time the after-commit
 * dispatch fires, the event's loan model reflects the loan's committed
 * state. Listeners therefore see the post-transition status without an
 * extra query. See each operation's implementation for the exact call.
 *
 * =============================================================================
 * Validation strategy
 * =============================================================================
 * The service performs THREE kinds of checks, in this order:
 *
 *   1. INPUT-LEVEL — the `reject()` operation requires a non-empty
 *      reason. This is checked explicitly before the transaction opens,
 *      so a malformed call fails fast without consuming a lock. The
 *      state machine ALSO enforces this (it is one of the five
 *      reason-required transitions), but the pre-check produces a
 *      clearer error and avoids the lock.
 *
 *   2. STATE-LEVEL — the loan must be in a decidable state
 *      (`submitted` or `under_review`). This is checked inside the
 *      transaction, against the row-locked fresh read.
 *
 *   3. POLICY-LEVEL — on approval, the product must have a policy version
 *      configured. This is checked inside the transaction. A product
 *      without a policy version cannot be approved, because the loan's
 *      financial rules would be undefined.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not create drafts or submit applications. Those belong to
 *     `LoanApplicationService` (A.28).
 *
 *   - It does not disburse approved loans. That belongs to
 *     `LoanDisbursementService` (A.30).
 *
 *   - It does not evaluate the product's `loan_product_rules` payload.
 *     The freeze copies the policy VERSION ID onto the loan; the payload
 *     is read later by `LoanScheduleService` (A.27) and the accrual
 *     services. Approval itself needs only to know that the version
 *     exists.
 *
 *   - It does not enforce an approval-authority limit (e.g. "a product
 *     whose max_principal exceeds X requires a supervisor"). The register
 *     does not freeze such a limit; adding one is a future CR.
 *
 *   - It does not require an approval reason. The state machine's
 *     `REASON_REQUIRED_TRANSITIONS` set (A.26) does not include
 *     `under_review → approved`; the transition's meaning is fully
 *     conveyed by its endpoints. A caller MAY supply a reason and it will
 *     be recorded on the history row; a null is the normal case.
 *
 *   - It does not touch the Wallet, the schedule, the collateral, the
 *     restructure records, or the write-off records. Those belong to
 *     other services.
 *
 *   - It does not unfreeze or re-freeze the policy version. The freeze
 *     happens exactly once, on approval; a subsequent restructure
 *     (`LoanRestructuringService`, A.34) writes a NEW policy version
 *     reference on the restructuring row and updates the loan, but that
 *     is the restructure service's concern.
 *
 *   - It does not dispatch events directly. The state machine registers
 *     the after-commit dispatch (gated on `config('loan.audit.emit_events')`);
 *     this service constructs the event and passes it in.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130 (product
 *                                                  catalogue), B6
 *                                                  (policy storage)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze)
 *   Phase 2C A.2                                 — `LoanStatus` (the
 *                                                  transition matrix)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.28                                — `LoanApplicationService`
 *                                                  (the sibling top-level
 *                                                  service)
 *   Phase 2A 2A.15                               — `CertificateService`
 *                                                  (the freeze-on-approval
 *                                                  precedent, in a
 *                                                  different domain)
 *   Step 5 (manifest) A.29                       — This file
 *   Step 6 v1.1.0 §3.9                           — `loans` table
 *                                                  specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.0
 */
final class LoanApprovalService
{
    /**
     * Constructor.
     *
     * One dependency: the state machine. This service does NOT inject the
     * product repository or any other repository — the product is loaded
     * through the loan's `product` relation, and the state machine is the
     * sole writer of the status column and the history rows.
     *
     * @param LoanStateMachine $stateMachine
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
    ) {
    }

    // =========================================================================
    // Public API — approve
    // =========================================================================
    /**
     * Approve a loan.
     *
     * The method:
     *
     *   1. Opens a transaction.
     *   2. Locks the loan row and verifies it is decidable.
     *   3. If the loan is still in `submitted`, transitions it to
     *      `under_review` silently (no event, no reason).
     *   4. Validates that the product has a policy version configured.
     *   5. Writes the decision metadata (`frozen_policy_version_id`,
     *      `approved_by`, `decision_date`).
     *   6. Constructs the `LoanApproved` event.
     *   7. Delegates the state transition to `LoanStateMachine`.
     *   8. Refreshes the same instance the event holds, so that the
     *      after-commit dispatch sees the loan's committed state.
     *   9. Returns the refreshed loan.
     *
     * The `under_review → approved` transition does NOT require a reason
     * (per the state machine's `REASON_REQUIRED_TRANSITIONS`). The
     * `$reason` parameter is therefore optional and is usually null.
     *
     * @param Loan        $loan
     * @param string|null $reason       Optional reason; stored on the
     *                                   history row if supplied.
     * @param int|null    $actorUserId  The user approving the loan, or
     *                                   null for a system approval.
     * @return Loan  The refreshed loan in `approved` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function approve(
        Loan $loan,
        ?string $reason = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use ($loan, $reason, $actorUserId): Loan {
            $current = $this->lockAndAssertDecidable($loan);
            $current = $this->ensureUnderReview($current, $actorUserId);
            $current = $this->freezePolicyAndRecordApproval($current, $actorUserId);

            $event = new LoanApproved($current);

            $result = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Approved,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            // Refresh the same instance the event holds, so that when the
            // after-commit dispatch fires, $event->loan reflects the
            // loan's post-transition state. See the class docblock
            // §"Event construction".
            $current->refresh();

            return $result;
        });
    }

    // =========================================================================
    // Public API — reject
    // =========================================================================
    /**
     * Reject a loan.
     *
     * The method:
     *
     *   1. Validates that the reason is non-empty (pre-transaction check).
     *   2. Opens a transaction.
     *   3. Locks the loan row and verifies it is decidable.
     *   4. If the loan is still in `submitted`, transitions it to
     *      `under_review` silently.
     *   5. Writes the decision metadata (`decision_date` only;
     *      `approved_by` and `frozen_policy_version_id` remain null).
     *   6. Constructs the `LoanRejected` event.
     *   7. Delegates the state transition to `LoanStateMachine`.
     *   8. Refreshes the same instance the event holds.
     *   9. Returns the refreshed loan.
     *
     * The `under_review → rejected` transition REQUIRES a non-empty
     * reason (per the state machine's `REASON_REQUIRED_TRANSITIONS`, A.26).
     * The state machine enforces this; the pre-transaction check in this
     * method produces a faster, clearer failure for a malformed call.
     *
     * @param Loan        $loan
     * @param string      $reason       The rejection reason. Required.
     * @param int|null    $actorUserId  The user rejecting the loan, or
     *                                   null for a system rejection.
     * @return Loan  The refreshed loan in `rejected` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function reject(
        Loan $loan,
        string $reason,
        ?int $actorUserId = null,
    ): Loan {
        // ---------------------------------------------------------------------
        // Pre-transaction reason check.
        //
        // The state machine also enforces the reason requirement (via
        // REASON_REQUIRED_TRANSITIONS), but the check is performed here
        // first so that a malformed call fails fast without consuming a
        // row lock or opening a transaction.
        // ---------------------------------------------------------------------
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'transition_reason' => [
                    'A rejection reason is required. Supply a non-empty '
                    . 'explanation of why the loan is being rejected.',
                ],
            ]);
        }

        return DB::transaction(function () use ($loan, $reason, $actorUserId): Loan {
            $current = $this->lockAndAssertDecidable($loan);
            $current = $this->ensureUnderReview($current, $actorUserId);

            // Record the decision date only. See the class docblock
            // §"The decision metadata written by this service" for the
            // rationale on why `approved_by` and
            // `frozen_policy_version_id` remain null on rejection.
            $current->decision_date = Carbon::now();
            $current->save();

            $event = new LoanRejected($current, $reason);

            $result = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Rejected,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            $current->refresh();

            return $result;
        });
    }

    // =========================================================================
    // Private — resolution and validation
    // =========================================================================
    /**
     * Lock the loan row and assert that its status is decidable.
     *
     * "Decidable" means the loan is in `submitted` or `under_review`. Any
     * other status — including terminal states and pre-submission states
     * — cannot have a decision recorded against it.
     *
     * The lock is acquired via `lockForUpdate()`. Two concurrent decision
     * attempts against the same loan serialize: the second caller blocks
     * on the lock until the first commits, then reads the updated status.
     * Without the lock, two callers could both read `under_review`, both
     * pass the check, and both attempt the write — one would succeed, the
     * other would produce a history row whose `from_status` disagreed
     * with the loan's actual prior status.
     *
     * @param Loan $loan
     * @return Loan  The locked, refreshed loan instance.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    private function lockAndAssertDecidable(Loan $loan): Loan
    {
        /** @var Loan $current */
        $current = Loan::query()
            ->whereKey($loan->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $current->status !== LoanStatus::Submitted
            && $current->status !== LoanStatus::UnderReview
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    sprintf(
                        'A loan in status "%s" cannot be approved or '
                        . 'rejected; only a loan in "submitted" or '
                        . '"under_review" state may have its decision '
                        . 'recorded. This loan is in "%s".',
                        $current->status->value,
                        $current->status->value,
                    ),
                ],
            ]);
        }

        return $current;
    }

    /**
     * Ensure the loan is in `under_review` state, transitioning it from
     * `submitted` if necessary.
     *
     * Phase 2C has no separate reviewer-assignment operation, so this
     * service performs the `submitted → under_review` transition
     * implicitly as the first step of the decision operation. See the
     * class docblock §"The intermediate `under_review` step" for the
     * full rationale.
     *
     * The transition is performed silently: no event is dispatched (the
     * state machine's `$afterCommitEvent` parameter is null), and no
     * reason is supplied (the transition does not require one).
     *
     * A loan that is already in `under_review` is returned unchanged —
     * the method is a no-op in that case.
     *
     * @param Loan     $loan
     * @param int|null $actorUserId
     * @return Loan
     *
     * @throws ValidationException
     */
    private function ensureUnderReview(Loan $loan, ?int $actorUserId): Loan
    {
        if ($loan->status !== LoanStatus::Submitted) {
            return $loan;
        }

        return $this->stateMachine->transition(
            loan: $loan,
            nextStatus: LoanStatus::UnderReview,
            reason: null,
            actorUserId: $actorUserId,
            afterCommitEvent: null,
        );
    }

    /**
     * Freeze the product's policy version onto the loan and record the
     * approval metadata.
     *
     * The method:
     *
     *   1. Loads the loan's `product` relation.
     *   2. Asserts the product is present (it always is; a null would
     *      indicate a data-integrity defect that the FK prevented).
     *   3. Asserts the product has a `loan_product_rules` policy version
     *      configured. A product without one cannot be approved — the
     *      loan's financial rules would be undefined.
     *   4. Copies the product's `product_rules_policy_version_id` onto
     *      the loan's `frozen_policy_version_id`.
     *   5. Sets `approved_by` and `decision_date`.
     *   6. Saves the loan.
     *
     * @param Loan     $loan
     * @param int|null $actorUserId
     * @return Loan  The same instance, after save.
     *
     * @throws ValidationException
     * @throws LogicException
     */
    private function freezePolicyAndRecordApproval(Loan $loan, ?int $actorUserId): Loan
    {
        $loan->loadMissing('product');

        $product = $loan->product;

        if ($product === null) {
            throw new LogicException(
                sprintf(
                    'Loan id=%d has no product. This is a data-integrity '
                    . 'defect: the FK `loans.loan_product_id` is '
                    . 'declared non-null and `RESTRICT`; a null value '
                    . 'here indicates the row was corrupted by an '
                    . 'out-of-band edit that bypassed the database '
                    . 'constraint.',
                    $loan->id,
                ),
            );
        }

        if (!$product->hasPolicyVersion()) {
            throw ValidationException::withMessages([
                'loan_product_id' => [
                    sprintf(
                        'The loan product "%s" has no policy version '
                        . 'configured. A loan under this product cannot '
                        . 'be approved until an administrator attaches '
                        . 'a loan_product_rules policy version to the '
                        . 'product.',
                        $product->code,
                    ),
                ],
            ]);
        }

        $loan->frozen_policy_version_id = $product->product_rules_policy_version_id;
        $loan->approved_by              = $actorUserId;
        $loan->decision_date            = Carbon::now();
        $loan->save();

        return $loan;
    }
}