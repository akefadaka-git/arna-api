<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Enums;

/**
 * InstallmentStatus
 *
 * The closed vocabulary of settlement states that a single loan
 * installment can carry during its participation in the active schedule.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * The status set is determined by three frozen decisions taken together:
 *
 *   D135 (Amortization) — the schedule is a deterministic list of
 *                          equal-installment rows, each with a due date
 *                          and an expected principal / interest split.
 *
 *   D133 (Arrears)      — an installment that is not settled by its due
 *                          date plus the applicable grace period is
 *                          overdue. Arrears is detected at the installment
 *                          granularity; the loan-level `in_arrears`
 *                          status (A.2) is derived from the presence of
 *                          at least one overdue installment.
 *
 *   B3 / B4 (Interest recording, restructuring) — when a loan is
 *                          restructured, the previous schedule's
 *                          outstanding Wallet obligations are discharged
 *                          (per the frozen restructure flow) and a new
 *                          schedule is generated. The prior schedule's
 *                          installments must be marked so that they are
 *                          excluded from the active schedule's
 *                          reconciliation scope and from any future
 *                          accrual / detection pass. A dedicated
 *                          `superseded` status carries this fact.
 *
 * The set is closed. Adding a state is a Change Request that updates this
 * enum, the `loan_installments.status` column's accepted values
 * (documented in the migration, A.10), the `LoanInstallment` model (A.17),
 * `LoanRepaymentService` (A.27), `LoanArrearsService` (A.29),
 * `LoanRestructuringService` (A.30), `LoanReconciliationService` (A.32),
 * and the corresponding tests.
 *
 * =============================================================================
 * The five states
 * =============================================================================
 *   pending     — the installment has received no settlement. Its
 *                 `settled_amount` is zero. It may be before its due
 *                 date, on its due date, or within its grace period.
 *
 *   partial     — the installment has received a partial settlement.
 *                 Its `settled_amount` is greater than zero and strictly
 *                 less than `expected_total`. It may be before its due
 *                 date, on its due date, or within its grace period.
 *
 *   settled     — the installment has been fully settled. Its
 *                 `settled_amount` equals `expected_total`. Terminal.
 *
 *   overdue     — the installment has passed its due date plus the
 *                 applicable grace period and is not fully settled. It
 *                 may carry a partial settlement or none. Once overdue,
 *                 the installment remains overdue until it is either
 *                 fully settled or superseded by a restructuring.
 *
 *   superseded  — the installment belonged to a schedule that has been
 *                 replaced by a restructuring. Its corresponding Wallet
 *                 obligation was discharged (per the frozen restructure
 *                 flow, referencing the `loan_restructurings` row id).
 *                 Terminal.
 *
 * =============================================================================
 * Why `pending` and `partial` are distinct states (and not derived)
 * =============================================================================
 * The `loan_installments` row carries the `settled_amount`,
 * `settled_principal`, and `settled_interest` columns (A.10). The three
 * values are sufficient to DERIVE `pending` (settled_amount == 0) versus
 * `partial` (0 < settled_amount < expected_total) at read time.
 *
 * The two are nonetheless declared as distinct states in the persisted
 * `status` column, mirroring the Phase 2A precedent: `CertificateStatus`
 * distinguished `draft` from `issued` even though the distinction was
 * derivable from `issued_at`. Persisting the state gives:
 *
 *   1. A single source of truth for query filters that do not want to
 *      reimplement the derivation on every call site.
 *
 *   2. A faster path for the reconciliation service (A.32), which reads
 *      the status column rather than re-deriving from three decimal
 *      comparisons.
 *
 *   3. An explicit state-transition audit: the `InstallmentStatus`
 *      history (if a future CR introduces a per-installment history table,
 *      analogous to `loan_status_histories`) becomes meaningful because
 *      the status column has a stable vocabulary.
 *
 * The cost is a small redundancy: an operator who manually updates
 * `settled_amount` without updating `status` would create an inconsistent
 * row. This is prevented by the service layer: only
 * `LoanRepaymentService::record()` (A.27) and
 * `LoanRestructuringService::restructure()` (A.30) write either field, and
 * both always write them together inside one transaction.
 *
 * =============================================================================
 * Why `overdue` is a persisted state (and not derived from the due date)
 * =============================================================================
 * A naive design might classify "overdue" purely as a computation:
 * `status != settled && due_date + grace < today`. This file rejects that
 * design for three reasons:
 *
 *   1. The grace period is PRODUCT-CONFIGURABLE (per D133). Different
 *      products have different grace thresholds; a single derived
 *      predicate cannot express this without reading the product's policy
 *      on every query.
 *
 *   2. The transition into `overdue` is an EVENT, not a pure function of
 *      time. Per D133, when an installment first crosses its grace
 *      threshold, the domain may record a penalty obligation against the
 *      borrower's Wallet. That side effect must fire exactly once. A
 *      derived predicate would fire on every read, which is unacceptable.
 *
 *   3. The `DetectArrearsCommand` (A.88) transitions installments from
 *      `pending` or `partial` to `overdue` and records the penalty
 *      obligation in the same transaction. The `overdue` status is the
 *      marker that the penalty has already been recorded (its idempotency
 *      is further enforced by the unique constraint on
 *      `loan_penalty_accruals.accrual_period_key`).
 *
 * The result: `overdue` is a persisted state, set by
 * `LoanArrearsService::detect()` (A.29), and readable directly by any
 * consumer that needs to enumerate overdue installments.
 *
 * =============================================================================
 * Why `superseded` exists
 * =============================================================================
 * When a loan is restructured (per the frozen flow, referencing B4 / C5):
 *
 *   1. A `loan_restructurings` row is created.
 *   2. Each outstanding Wallet obligation linked to the loan is
 *      discharged (an `obligation_cancelled` ledger entry).
 *   3. A new schedule is generated and stored as new `loan_installments`
 *      rows.
 *   4. The loan transitions back to `active`.
 *
 * Step 3 creates new installment rows. What happens to the OLD rows?
 *
 * Three candidate approaches were considered:
 *
 *   (a) Delete the old rows. Rejected: financial history must not be
 *       destructively mutated (per the platform-wide financial-history
 *       discipline).
 *
 *   (b) Leave the old rows untouched with their original statuses.
 *       Rejected: the reconciliation service (A.32) would see the old
 *       installments as still part of the active schedule and would
 *       produce false divergences against the loan's projections.
 *
 *   (c) Mark the old rows with a dedicated terminal status.
 *       Adopted.
 *
 * `superseded` is that status. It is a final state: a superseded
 * installment has no outgoing transitions. Its `settled_amount`,
 * `settled_principal`, and `settled_interest` columns are preserved as
 * they were at the moment of the restructure — the historical record of
 * how far the borrower got on the previous schedule is not lost.
 *
 * The reconciliation service (A.32) filters by
 * `status NOT IN ('superseded', 'settled')` when enumerating the active
 * schedule's expected-vs-actual divergence. Superseded installments are
 * excluded from that population.
 *
 * =============================================================================
 * Storage
 * =============================================================================
 * The enum is string-backed. Values are snake_case; case names are
 * PascalCase. This mirrors the convention used by Phase 2A's
 * `ShareholderType`, `HoldingDirection`, and `CertificateStatus`, by
 * Phase 2B's `ObligationComponent` and `WalletEntryType`, and by the
 * sibling Loan enums `LoanStatus` (A.2), `CollateralStatus` (A.3), and
 * `CollateralType` (A.4).
 *
 * Persisted as `loan_installments.status VARCHAR(16)` (A.10). The
 * VARCHAR(16) width accommodates every current value — the longest is
 * `superseded` (10 characters) — with headroom for a future value that a
 * Change Request might add.
 *
 * The column is NOT a MySQL ENUM. Adding a state is a Change Request that
 * updates this enum; a MySQL ENUM would require a schema migration for
 * every value change and would couple the migration to application-code
 * terminology.
 *
 * =============================================================================
 * No display labels
 * =============================================================================
 * This enum deliberately provides no `label()` method. Display strings
 * belong to the resource layer and are resolved through the localization
 * system's `__()` mechanism in the caller's active locale. A future
 * translation key namespace (`loan::installment_status.*`) will render
 * these values in Amharic and English at the presentation layer; the enum
 * itself remains a technical vocabulary.
 *
 * =============================================================================
 * No guards, permissions, or event mappings
 * =============================================================================
 * This enum encodes only the state set and the structural transition
 * matrix. It does NOT encode:
 *
 *   - the guards that a transition must satisfy (e.g. "the grace period
 *     must have elapsed before `pending → overdue` is permitted");
 *   - the permissions required for a transition (installments themselves
 *     are not user-facing resources; the transitions are driven by
 *     `LoanRepaymentService`, `LoanArrearsService`, and
 *     `LoanRestructuringService`, none of which is directly callable by a
 *     caller acting on an installment alone);
 *   - the events dispatched when a transition fires
 *     (`LoanInstallmentSettled`, `LoanPenaltyRecorded`,
 *     `LoanRestructured`).
 *
 * Those concerns belong to the services that drive the transitions. The
 * services consult `$from->canTransitionTo($to)` first; if the structural
 * check fails, they throw; if the structural check passes, they apply the
 * guard and event layers on top.
 *
 * =============================================================================
 * Usage
 * =============================================================================
 * Consumers reference the enum by its case:
 *
 *     InstallmentStatus::Pending
 *     InstallmentStatus::Partial
 *     InstallmentStatus::Settled
 *     InstallmentStatus::Overdue
 *     InstallmentStatus::Superseded
 *
 * Persistence writes `->value` (the snake_case string). Deserialization
 * uses `InstallmentStatus::tryFrom($string)`; a null return signals an
 * unrecognised value — which, for a well-formed row, should never occur.
 *
 * The `values()` helper returns the full list of accepted string values
 * in declaration order. The `activeValues()` helper returns the subset of
 * non-terminal states, for use in query `whereIn` filters (e.g. "list the
 * installments still being serviced on this loan"). The
 * `unsettledValues()` helper returns the states that represent an
 * outstanding amount still owed to the lender (pending, partial,
 * overdue); it is used by the reconciliation service (A.32) and by any
 * report that needs "what is still owed?" at the installment granularity.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D132, D133, D135, B3, B4
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation
 *                                                  combination), C4 (state
 *                                                  freeze at loan level)
 *   Phase 2C Final Reconciliation, 2026-09-12    — reconciliation invariant
 *                                                  (loan-linked obligations)
 *   Step 5 (manifest) A.5                        — This file
 *   Step 6 v1.1.0 §3.10                          — loan_installments table
 *                                                  specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Enums
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