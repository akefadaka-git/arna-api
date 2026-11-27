<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Enums;

/**
 * Closed installment lifecycle. Overdue follows due date plus grace period; restructuring supersedes prior schedule rows.
 *
 * @package MaaleArna\Arna\Core\Domains\Loan\Enums
 * @version 1.0.0
 */
enum InstallmentStatus: string
{
    /**
     * The installment has received no settlement. Its `settled_amount` is
     * zero. It may be before its due date, on its due date, or within its
     * grace period.
     *
     * Outgoing transitions: Partial, Settled, Overdue, Superseded.
     *
     * Entry: every new installment is created in this state by
     *        `LoanScheduleService::generate()` (A.23).
     * Exit:
     *   - `LoanRepaymentService::record()` (A.27) — a partial settlement
     *     drives the transition to Partial; a full settlement drives the
     *     transition to Settled.
     *   - `LoanArrearsService::detect()` (A.29) — when the due date plus
     *     the grace period elapses without full settlement, the
     *     installment transitions to Overdue and a penalty obligation is
     *     recorded against the borrower's Wallet.
     *   - `LoanRestructuringService::restructure()` (A.30) — when the
     *     loan is restructured before this installment comes due, the
     *     installment transitions directly to Superseded.
     */
    case Pending = 'pending';

    /**
     * The installment has received a partial settlement. Its
     * `settled_amount` is greater than zero and strictly less than
     * `expected_total`. It may be before its due date, on its due date, or
     * within its grace period.
     *
     * Outgoing transitions: Settled, Overdue, Superseded.
     *
     * Entry: `LoanRepaymentService::record()` (A.27) applies a partial
     *        payment against a Pending installment.
     * Exit:
     *   - A subsequent partial or full payment brings `settled_amount` to
     *     `expected_total`, transitioning to Settled.
     *   - The grace period elapsing without full settlement transitions
     *     to Overdue (and a penalty obligation is recorded).
     *   - A restructure transitions to Superseded.
     */
    case Partial = 'partial';

    /**
     * The installment has been fully settled. Its `settled_amount` equals
     * `expected_total`. Terminal — no outgoing transitions.
     *
     * Entry: `LoanRepaymentService::record()` (A.27) brings `settled_amount`
     *        to `expected_total` from either Pending or Partial.
     * Exit:  none.
     *
     * A Settled installment is excluded from the reconciliation service's
     * active-schedule enumeration and from every accrual / detection pass.
     * It remains in the record for audit history and for the loan's
     * settlement summary.
     */
    case Settled = 'settled';

    /**
     * The installment has passed its due date plus the applicable grace
     * period and is not fully settled. It may carry a partial settlement
     * or none. The grace period is product-configurable (per D133).
     *
     * Outgoing transitions: Settled, Superseded.
     *
     * Entry: `LoanArrearsService::detect()` (A.29) transitions a Pending
     *        or Partial installment to Overdue when the grace threshold
     *        is crossed, and records a penalty obligation against the
     *        borrower's Wallet in the same transaction.
     * Exit:
     *   - `LoanRepaymentService::record()` (A.27) applies enough payment
     *     to bring `settled_amount` to `expected_total` — the installment
     *     becomes Settled even though it has spent time in Overdue. The
     *     historical fact of the arrearage remains recorded in the
     *     penalty accrual table and in the `loan_status_histories` log.
     *   - `LoanRestructuringService::restructure()` (A.30) transitions
     *     the installment to Superseded.
     *
     * An Overdue installment is NOT a terminal state: it can still be
     * settled (via payment) or superseded (via restructure). It is the
     * "in_arrears" state at the installment granularity, mirroring the
     * loan-level `LoanStatus::InArrears` (A.2).
     */
    case Overdue = 'overdue';

    /**
     * The installment belonged to a schedule that has been replaced by a
     * restructuring. Its corresponding Wallet obligation was discharged
     * via an `obligation_cancelled` ledger entry, referencing the
     * `loan_restructurings` row id. Terminal — no outgoing transitions.
     *
     * Entry: `LoanRestructuringService::restructure()` (A.30) transitions
     *        every unsettled installment on the previous schedule to
     *        Superseded as part of the restructure workflow. Installments
     *        that were already Settled are NOT transitioned; their status
     *        remains Settled because the borrower did pay them and that
     *        historical fact is preserved.
     * Exit:  none.
     *
     * A Superseded installment is excluded from the active schedule's
     * reconciliation scope (A.32), from the interest-accrual command
     * (A.87), and from the arrears-detection command (A.88). Its
     * `settled_amount` and its principal / interest split are preserved
     * as they stood at the moment of the restructure, providing an audit
     * trail of how far the borrower got on the previous schedule.
     */
    case Superseded = 'superseded';

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
     * The order matches the order in which the states are presented in
     * this file: pending, partial, settled, overdue, superseded. The order
     * is presentation-only; no logic depends on it.
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
     * Return the string values of all non-terminal states, in declaration
     * order.
     *
     * Non-terminal states in Phase 2C: pending, partial, overdue. The
     * terminal states (settled, superseded) are excluded.
     *
     * Intended for query filters (e.g. "list the installments still being
     * serviced on this loan"). Note that "active" here means "not
     * terminal" — an overdue installment IS returned by this helper,
     * because it is still being serviced (it can still be paid or
     * superseded). Callers that need "not yet due or within grace, not
     * overdue" should filter explicitly to
     * `[InstallmentStatus::Pending->value, InstallmentStatus::Partial->value]`.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(
                self::cases(),
                static fn (self $case): bool => !$case->isTerminal(),
            ),
        ));
    }

    /**
     * Return the string values of all states that represent an outstanding
     * amount still owed to the lender, in declaration order.
     *
     * These are: pending, partial, overdue. Settled and superseded are
     * excluded because in both cases the lender is no longer owed anything
     * on the installment — settled because the borrower paid, superseded
     * because the loan was restructured and the obligation was discharged.
     *
     * Intended for the reconciliation service (A.32) and for reports that
     * answer "what is still owed?" at the installment granularity.
     *
     * @return list<string>
     */
    public static function unsettledValues(): array
    {
        return [
            self::Pending->value,
            self::Partial->value,
            self::Overdue->value,
        ];
    }

    /**
     * Whether this status represents a terminal state — one from which
     * there is no outgoing transition.
     *
     * True for Settled and Superseded. False for Pending, Partial, and
     * Overdue.
     *
     * For this enum, `isTerminal()` and `isFinal()` would be identical, so
     * only `isTerminal()` is provided. Every terminal state of an
     * installment is also a final state.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Settled,
            self::Superseded => true,

            self::Pending,
            self::Partial,
            self::Overdue    => false,
        };
    }

    /**
     * Whether this status represents an installment that has been fully
     * settled by the borrower.
     *
     * Equivalent to `$status === InstallmentStatus::Settled`. Provided so
     * that call sites that ask "is this installment fully paid?" read as
     * such, and so that future state additions can update the predicate in
     * one place if the semantics of "settled" ever widen.
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return $this === self::Settled;
    }

    /**
     * Whether this status represents an overdue (arrears) installment.
     *
     * Equivalent to `$status === InstallmentStatus::Overdue`. Provided for
     * the same expressiveness reasons as `isSettled()`.
     *
     * Note: this predicate does NOT identify installments that are "past
     * their due date but still within grace". Those installments remain in
     * `Pending` or `Partial`; the domain does not classify them as
     * "overdue" until the grace period elapses.
     *
     * @return bool
     */
    public function isOverdue(): bool
    {
        return $this === self::Overdue;
    }

    /**
     * Whether this status represents an installment that has been
     * superseded by a loan restructuring.
     *
     * Equivalent to `$status === InstallmentStatus::Superseded`. Provided
     * for the same expressiveness reasons as `isSettled()`.
     *
     * @return bool
     */
    public function isSuperseded(): bool
    {
        return $this === self::Superseded;
    }

    /**
     * Whether this status represents an outstanding amount still owed to
     * the lender.
     *
     * True for Pending, Partial, and Overdue. False for Settled and
     * Superseded.
     *
     * Uses `unsettledValues()` as the single source of truth, so a future
     * Change Request that adjusts the "still owed" set updates both the
     * helper and this predicate in lockstep.
     *
     * @return bool
     */
    public function isUnsettled(): bool
    {
        return in_array($this->value, self::unsettledValues(), true);
    }

    /**
     * Determine whether a transition from this status to the given status
     * is structurally legal.
     *
     * This method encodes the exact transition matrix implied by the
     * frozen decisions:
     *
     *     pending    → partial | settled | overdue | superseded
     *     partial    → settled | overdue | superseded
     *     overdue    → settled | superseded
     *     settled    → (none)
     *     superseded → (none)
     *
     * Self-transitions (`Pending → Pending`, etc.) are never permitted.
     * Every state change is an event; a "transition" that leaves the state
     * unchanged is not a transition and would pollute any status history
     * the domain chooses to record.
     *
     * The check is STRUCTURAL only. It answers "is this a legal edge in
     * the state graph?" — it does NOT answer "is this transition permitted
     * for this caller, on this loan, in this context?" Those questions are
     * answered by the service that drives the transition.
     *
     * Callers MUST consult the appropriate service before persisting a
     * status change:
     *
     *   - `LoanRepaymentService::record()` (A.27) for payments.
     *   - `LoanArrearsService::detect()` (A.29) for arrearage.
     *   - `LoanRestructuringService::restructure()` (A.30) for
     *     supersession.
     *
     * Direct use of `canTransitionTo()` outside the services is intended
     * for tests and for read-only diagnostics, not for production writes.
     *
     * @param self $next  The proposed new status.
     * @return bool       True if the transition is structurally permitted.
     */
    public function canTransitionTo(self $next): bool
    {
        // Self-transitions are never permitted. Every state change is an
        // event; a transition that leaves the state unchanged is not a
        // transition and would pollute any status history the domain
        // chooses to record.
        if ($this === $next) {
            return false;
        }

        return match ($this) {
            self::Pending    => $next === self::Partial
                             || $next === self::Settled
                             || $next === self::Overdue
                             || $next === self::Superseded,

            self::Partial    => $next === self::Settled
                             || $next === self::Overdue
                             || $next === self::Superseded,

            self::Overdue    => $next === self::Settled
                             || $next === self::Superseded,

            self::Settled    => false,

            self::Superseded => false,
        };
    }
}