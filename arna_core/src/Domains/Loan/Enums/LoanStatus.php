<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Enums;

/**
 * Closed 12-state loan lifecycle vocabulary. LoanStateMachine owns transitions; changes require coordinated schema, history and test updates.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Enums
 * @version 1.0.0
 */
enum LoanStatus: string
{
    /**
     * The loan is an in-progress application that has not yet been
     * submitted for review. It may be freely edited by the applicant.
     *
     * The only outgoing transition is to Submitted.
     *
     * Entry: created by `LoanApplicationService::createDraft()`.
     * Exit:  `LoanApplicationService::submit()`.
     */
    case Draft = 'draft';

    /**
     * The applicant has submitted the loan for review. The application is
     * now read-only from the applicant's perspective; edits must go through
     * a reviewer.
     *
     * The only outgoing transition is to UnderReview.
     *
     * Entry: `LoanApplicationService::submit()`.
     * Exit:  automatic on reviewer assignment, or explicit on manual
     *        transition.
     */
    case Submitted = 'submitted';

    /**
     * A reviewer is evaluating the application. The loan is awaiting a
     * decision.
     *
     * Outgoing transitions: Approved, Rejected.
     *
     * Entry: automatic on reviewer assignment.
     * Exit:  `LoanApprovalService::approve()` or `::reject()`.
     */
    case UnderReview = 'under_review';

    /**
     * The application has been approved. The loan is ready for
     * disbursement but no money has moved yet.
     *
     * The only outgoing transition is to Disbursed.
     *
     * Entry: `LoanApprovalService::approve()`.
     * Exit:  `LoanDisbursementService::disburse()`.
     */
    case Approved = 'approved';

    /**
     * The application has been rejected. Terminal — no return to an active
     * working state, no outgoing transition.
     *
     * Entry: `LoanApprovalService::reject()`.
     * Exit:  none.
     */
    case Rejected = 'rejected';

    /**
     * The loan has been disbursed. The Wallet principal obligation has
     * been recorded against the borrower's wallet; the loan is now
     * financially active.
     *
     * The only outgoing transition is to Active, which happens
     * automatically after disbursement completes. Disbursed is a
     * transient state; in practice every loan passes through it in the
     * same transaction that records the disbursement and settles into
     * Active.
     *
     * Entry: `LoanDisbursementService::disburse()`.
     * Exit:  automatic on completion.
     */
    case Disbursed = 'disbursed';

    /**
     * The loan is active and in good standing. Installments are being
     * repaid on schedule.
     *
     * Outgoing transitions: InArrears, Settled, WrittenOff.
     *
     * Entry: automatic after Disbursed, or from Restructured.
     * Exit:  `LoanArrearsService::detect()` (to InArrears),
     *        `LoanRepaymentService::record()` (to Settled when all
     *        installments are fully settled),
     *        `LoanWriteOffService::writeOff()` (to WrittenOff).
     */
    case Active = 'active';

    /**
     * The loan has an overdue installment past its grace period. Penalty
     * obligations may be recorded against the borrower's wallet.
     *
     * Outgoing transitions: Restructured, Settled, WrittenOff.
     *
     * Entry: `LoanArrearsService::detect()`.
     * Exit:  `LoanRestructuringService::restructure()` (to Restructured),
     *        `LoanRepaymentService::record()` (to Settled when all
     *        installments, including the previously-overdue one, are fully
     *        settled),
     *        `LoanWriteOffService::writeOff()` (to WrittenOff).
     */
    case InArrears = 'in_arrears';

    /**
     * The loan's terms have been renegotiated. A `loan_restructurings` row
     * records the previous terms and the new terms; the previous schedule's
     * outstanding Wallet obligations have been discharged; a new schedule
     * has been generated. The status is transient: the loan transitions to
     * Active in the same transaction that writes the restructuring row.
     *
     * Outgoing transitions: Active, Settled, WrittenOff.
     *
     * Entry: `LoanRestructuringService::restructure()`.
     * Exit:  automatic to Active, or explicit to Settled / WrittenOff.
     */
    case Restructured = 'restructured';

    /**
     * The loan has been written off. The outstanding Wallet obligations
     * have been discharged with a `obligation_written_off` ledger entry;
     * the loan's outstanding projections are zeroed; the loan is
     * terminal.
     *
     * Entry: `LoanWriteOffService::writeOff()`.
     * Exit:  none.
     */
    case WrittenOff = 'written_off';

    /**
     * All installments have been fully settled. The loan is functionally
     * complete; its monetary obligations are discharged in the Wallet.
     * The loan remains open for final bookkeeping closure.
     *
     * The only outgoing transition is to Closed.
     *
     * Entry: automatic when the last installment is settled, from any of
     *        Active, InArrears, or Restructured.
     * Exit:  automatic closure step, or explicit operator action.
     */
    case Settled = 'settled';

    /**
     * The loan is closed for all purposes. Terminal; no outgoing
     * transitions. This is the end of every path that reaches it.
     *
     * Entry: automatic after Settled.
     * Exit:  none.
     */
    case Closed = 'closed';

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return the full list of accepted string values, in declaration order.
     *
     * Intended for validation rules and for administrative iteration. The
     * returned array is `list<string>` — a numerically-indexed array whose
     * order matches the enum's case declaration.
     *
     * The order matches the order in which the states are presented in the
     * C4 freeze (draft first, closed last). Consumers that need a different
     * order should sort the result themselves; the enum does not impose a
     * semantic ordering beyond the declaration sequence.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Return the string values of all terminal states, in declaration
     * order.
     *
     * "Terminal" per the C4 freeze: rejected, written_off, settled,
     * closed. A terminal state has no path back to an active working
     * state. Note that Settled still permits the transition to Closed;
     * "terminal" here means "not a working state", not "no outgoing
     * transition".
     *
     * Intended for query filters (e.g.
     * `whereNotIn('status', LoanStatus::terminalValues())` to list loans
     * still under management).
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(
                self::cases(),
                static fn (self $case): bool => $case->isTerminal(),
            ),
        ));
    }

    /**
     * Whether this status represents a terminal state — one from which
     * there is no path back to an active working state.
     *
     * True for Rejected, WrittenOff, Settled, Closed. False for every
     * other state.
     *
     * This method does NOT mean "no outgoing transition". Settled has one
     * outgoing transition (to Closed). Callers that need "the state has no
     * outgoing transitions at all" use `isFinal()`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected,
            self::WrittenOff,
            self::Settled,
            self::Closed      => true,

            self::Draft,
            self::Submitted,
            self::UnderReview,
            self::Approved,
            self::Disbursed,
            self::Active,
            self::InArrears,
            self::Restructured => false,
        };
    }

    /**
     * Whether this status represents a state with no outgoing transitions
     * at all — the state is the end of every path that reaches it.
     *
     * True for Rejected, WrittenOff, Closed. False for Settled (which
     * transitions to Closed) and for every non-terminal state.
     *
     * This is a stricter classification than `isTerminal()`. It is used by
     * reports and audit reviews that need to identify loans that are fully
     * done — no further state-changing action is possible without a Change
     * Request.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Rejected,
            self::WrittenOff,
            self::Closed      => true,

            self::Draft,
            self::Submitted,
            self::UnderReview,
            self::Approved,
            self::Disbursed,
            self::Active,
            self::InArrears,
            self::Restructured,
            self::Settled     => false,
        };
    }

    /**
     * Determine whether a transition from this status to the given status
     * is structurally legal.
     *
     * This method encodes the exact transition matrix frozen in the Phase 2C
     * clarification response (C4, 2026-09-12):
     *
     *     draft         → submitted
     *     submitted     → under_review
     *     under_review  → approved | rejected
     *     approved      → disbursed
     *     disbursed     → active
     *     active        → in_arrears | settled | written_off
     *     in_arrears    → restructured | settled | written_off
     *     restructured  → active | settled | written_off
     *     settled       → closed
     *
     * Rejected, WrittenOff, and Closed have no outgoing transitions.
     *
     * The check is STRUCTURAL only. It answers "is this a legal edge in
     * the state graph?" — it does NOT answer "is this transition permitted
     * for this caller, on this loan, in this context?" Those questions are
     * answered by `LoanStateMachine::transition()` (A.26), which composes
     * this check with guards, permission enforcement, and event dispatch.
     *
     * Callers MUST consult `LoanStateMachine` before persisting a status
     * change. Direct use of `canTransitionTo()` outside the state machine
     * is intended for tests and for read-only diagnostics, not for
     * production writes.
     *
     * Self-transitions (`Active → Active`, `Settled → Settled`, etc.) are
     * never permitted. Every state change is an event; a "transition" that
     * leaves the state unchanged is not a transition and would pollute the
     * `loan_status_histories` log.
     *
     * @param self $next  The proposed new status.
     * @return bool       True if the transition is structurally permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        // Self-transitions are never permitted. Every state change is an
        // event; a transition that leaves the state unchanged is not a
        // transition and would pollute the status history log.
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Draft         => $next === self::Submitted,

            self::Submitted     => $next === self::UnderReview,

            self::UnderReview   => $next === self::Approved
                                || $next === self::Rejected,

            self::Approved      => $next === self::Disbursed,

            self::Disbursed     => $next === self::Active,

            self::Active        => $next === self::InArrears
                                || $next === self::Settled
                                || $next === self::WrittenOff,

            self::InArrears     => $next === self::Restructured
                                || $next === self::Settled
                                || $next === self::WrittenOff,

            self::Restructured  => $next === self::Active
                                || $next === self::Settled
                                || $next === self::WrittenOff,

            self::Settled       => $next === self::Closed,

            self::Rejected      => false,
            self::WrittenOff    => false,
            self::Closed        => false,
        };
    }
}