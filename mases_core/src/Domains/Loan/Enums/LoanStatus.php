<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * LoanStatus
 *
 * The closed vocabulary of lifecycle states that a MASES loan can carry.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The twelve states are frozen verbatim by the Phase 2C decision register
 * (C4, 2026-09-12), which adopted the §34 candidate list from the Phase 2
 * specification without modification:
 *
 *     draft
 *     submitted
 *     under_review
 *     approved
 *     rejected
 *     disbursed
 *     active
 *     in_arrears
 *     restructured
 *     written_off
 *     settled
 *     closed
 *
 * The set is closed. Adding a state is a Change Request that updates this
 * enum, the `loans.status` column's accepted values (documented in the
 * migration, A.9), the transition matrix in `LoanStateMachine` (A.26), the
 * `LoanStatusHistory` model's validation rules (A.21), and every test
 * vector that exercises the state machine. Removing a state is a Change
 * Request that must be accompanied by a data-migration plan for historical
 * rows.
 *
 * =============================================================================
 * Lifecycle semantics
 * =============================================================================
 * The principal forward path:
 *
 *     draft → submitted → under_review → approved → disbursed → active
 *                                                                    │
 *                                       ┌────────────────────────────┤
 *                                       │                            │
 *                                       ↓                            ↓
 *                                  in_arrears                   settled
 *                                       │                            │
 *                                       ↓                            ↓
 *                                 restructured                   closed
 *                                       │
 *                                       ↓
 *                                     active
 *
 * Side branches:
 *
 *     under_review → rejected        (terminal; no return to active)
 *     active       → written_off     (terminal)
 *     in_arrears   → written_off     (terminal)
 *     restructured → written_off     (terminal)
 *
 * The exact transition matrix is enforced by `canTransitionTo()` on this
 * enum and by `LoanStateMachine` (A.26), which layers guards, permissions,
 * event dispatch, and audit on top of the structural check.
 *
 * =============================================================================
 * Terminal vs. final
 * =============================================================================
 * The reviewer's freeze (C4) names four terminal states:
 *
 *     rejected, written_off, settled, closed
 *
 * "Terminal" here means **no path back to an active working state**. It does
 * NOT mean "no outgoing transition at all". The transition `settled → closed`
 * is explicitly permitted: a settled loan is functionally complete but
 * remains open for final bookkeeping closure until an operator or an
 * automatic post-settlement step marks it closed.
 *
 * Two classification helpers are therefore provided:
 *
 *     isTerminal()  — true for rejected, written_off, settled, closed.
 *                     No transition from a terminal state returns the loan
 *                     to an active working state.
 *
 *     isFinal()     — true for rejected, written_off, closed.
 *                     The state has no outgoing transitions at all; it is
 *                     not merely terminal, it is the end of every path
 *                     that reaches it.
 *
 * The distinction matters at two call sites:
 *
 *   - A service that needs to decide "may this loan still be transacted on
 *     (restructured, repaid, etc.)?" asks `isTerminal()`.
 *
 *   - A report or an audit review that needs to identify loans "fully done"
 *     asks `isFinal()`.
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`, and by
 * Phase 2B's `ObligationComponent` and `WalletEntryType`.
 *
 * Persisted as `loans.status VARCHAR(32)` (A.9) and
 * `loan_status_histories.from_status VARCHAR(32)` /
 * `loan_status_histories.to_status VARCHAR(32)` (A.14). The VARCHAR(32)
 * width accommodates every current value — the longest is `under_review`
 * (12 characters) and `written_off` (11 characters) — with headroom for a
 * future value that a Change Request might add.
 *
 * The column is NOT a MySQL ENUM. Adding a state is a Change Request that
 * updates this enum; a MySQL ENUM would require a schema migration for
 * every value change and would couple the migration to application-code
 * terminology. This mirrors the Phase 2A and 2B precedent.
 *
 * =============================================================================
 * No display labels
 * =============================================================================
 * This enum deliberately provides no `label()` method. Display strings
 * belong to the resource layer and are resolved through the localization
 * system's `__()` mechanism in the caller's active locale. A future
 * translation key namespace (`loan::status.*`) will render these values in
 * Amharic and English at the presentation layer; the enum itself remains a
 * technical vocabulary.
 *
 * =============================================================================
 * No guards, permissions, or event mappings
 * =============================================================================
 * This enum encodes only the state set and the structural transition
 * matrix. It does NOT encode:
 *
 *   - the guards that a transition must satisfy (e.g. "a reviewer must be
 *     assigned before submitted → under_review succeeds");
 *   - the permissions required for a transition (e.g. `loan.approve` for
 *     under_review → approved);
 *   - the events dispatched when a transition fires (e.g. `LoanApproved`);
 *   - the audit payload that a transition emits.
 *
 * Those concerns belong to `LoanStateMachine` (A.26), which is the single
 * enforcement point that the domain services use to change a loan's status.
 * The state machine asks `$from->canTransitionTo($to)` first; if the
 * structural check fails, it throws; if the structural check passes, it
 * applies the guard, permission, and event layers on top.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     LoanStatus::Draft
 *     LoanStatus::Submitted
 *     LoanStatus::UnderReview
 *     LoanStatus::Approved
 *     LoanStatus::Rejected
 *     LoanStatus::Disbursed
 *     LoanStatus::Active
 *     LoanStatus::InArrears
 *     LoanStatus::Restructured
 *     LoanStatus::WrittenOff
 *     LoanStatus::Settled
 *     LoanStatus::Closed
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `LoanStatus::tryFrom($string)`; a null return signals an unrecognised
 * value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values in
 * declaration order. The `terminalValues()` helper returns the subset of
 * terminal states, for use in query `whereNotIn` filters and administrative
 * reports.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — C4 (state freeze)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (transition matrix)
 *   Phase 2C Final Reconciliation, 2026-09-12    — terminal vs. final
 *   Step 5 (manifest) A.2                        — This file
 *   Step 6 v1.1.0 §3.9                           — loans table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Enums
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