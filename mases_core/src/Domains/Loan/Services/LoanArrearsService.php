<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Support\Facades\Event;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanEnteredArrears;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanPenaltyRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;

/**
 * LoanArrearsService
 *
 * Records ONE penalty obligation per overdue installment, at the moment
 * the installment crosses its grace threshold without full settlement.
 * Transitions the loan from `active` to `in_arrears` on the first such
 * event.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — `halfUp()` off-by-one fix. The private rounding helper
 *         constructed the half-unit-in-the-last-place as
 *         `'0.' . str_repeat('0', $scale - 1) . '5'`, which at
 *         `scale = 4` produces `'0.0005'` — the half-ULP of scale 3,
 *         not scale 4. The correct construction is
 *         `'0.' . str_repeat('0', $scale) . '5'`, which at `scale = 4`
 *         produces `'0.00005'`.
 *
 *         The method is a byte-for-byte copy of
 *         `AmortizationCalculator::halfUp()` (A.6, patched in the same
 *         deployment as v1.0.1) and `LoanScheduleService::halfUp()`
 *         (A.27, patched in the same deployment as v1.0.1). All three
 *         copies are kept in lockstep pending a follow-up Change
 *         Request that extracts the helper into a shared trait —
 *         three private copies of a financial rounding primitive is a
 *         drift hazard the platform should not carry indefinitely.
 *
 *         The bug was observable in every penalty computation whose
 *         `overdue_amount · penalty_rate` hit a 4-then-5 boundary at
 *         the 5th decimal place. See `AmortizationCalculator` v1.0.1's
 *         revision note for the worked example.
 *
 * 1.0.2 — `assertInstallmentIsPenaltyEligible()` null-safe error path.
 *         The throw site dereferenced `$installment->status->value`
 *         unconditionally. When the installment's `status` attribute
 *         was null — a state reachable when a caller hands the service
 *         an instance whose status was never populated — the intended
 *         `LogicException` was never reached: the null dereference
 *         raised `ErrorException` first, and the test suite's
 *         `expectException(LogicException::class)` assertions failed
 *         with the wrong exception class.
 *
 *         The fix uses `?->` with a fallback of the literal string
 *         `'null'` in the message. The exception class remains
 *         `LogicException`, which is the semantically correct class for
 *         "the caller handed us an ineligible installment" — the
 *         message now correctly reports the anomaly (`status "null"`)
 *         instead of crashing while formatting the message.
 *
 *         This fix is defense-in-depth: the `LoanInstallment` model
 *         casts `status` to the `InstallmentStatus` enum, and the
 *         column is NOT NULL with a default of `'pending'`. A null
 *         status is an anomaly in the caller, not a state the service
 *         can produce. The service now surfaces the anomaly as a
 *         diagnosable error rather than a crash.
 *
 * 1.0.3 — `detect()` idempotency ordering fix. The key computation and
 *         fast-path idempotency check previously ran at steps 5 and 6,
 *         AFTER the installment-eligibility guard at step 2. That
 *         ordering contradicted the documented idempotency contract.
 *
 *         The contract — stated in this class docblock §"Idempotency
 *         — the unique constraint is the mechanism" — is that the
 *         service's core flow is:
 *
 *             1. Compute the key.
 *             2. Fast-path check: does a row with this key already
 *                exist? If so, return null (already penalized).
 *             3. Attempt to INSERT the row. Catch the unique-constraint
 *                violation on a concurrent run.
 *             4. Otherwise, the insert succeeded. Proceed to call the
 *                Wallet.
 *
 *         The guards (loan-state, ownership, eligibility) are meant to
 *         protect the flow, not to preempt it. But in the v1.0.0–v1.0.2
 *         ordering, the eligibility guard ran FIRST and rejected
 *         `overdue` installments before the fast-path could return
 *         null. Since the FIRST `detect()` call transitions the
 *         installment to `overdue` (step 11), a SECOND call on the
 *         same installment raised `LogicException` instead of returning
 *         null. This broke the idempotency contract and produced
 *         `test_detect_is_idempotent_for_the_same_installment` and
 *         `test_detect_does_not_double_increment_on_retry` failures in
 *         the unit suite.
 *
 *         The fix moves the key-computation + fast-path check to
 *         immediately after the ownership check and BEFORE the
 *         eligibility guard. The loan-state check and the ownership
 *         check stay ahead of both — they are cheap, and the ownership
 *         check is essential for defending the key's
 *         `(loan_id, installment_id)` structure.
 *
 *         The eligibility guard remains in place. It still rejects
 *         `settled` and `superseded` installments, and it still rejects
 *         `overdue` installments WHEN NO ACCRUAL EXISTS — the
 *         data-integrity-defect case where an `overdue` installment
 *         somehow lacks its penalty row. In that case, the fast-path
 *         finds no row, the guard's rejection stands, and the caller
 *         is told — correctly — that the installment is ineligible.
 *
 *         Behavior change versus v1.0.2: a second `detect()` call on an
 *         already-penalized installment now returns null (as
 *         documented) instead of raising `LogicException`. All other
 *         cases behave as before. See the `detect()` method's
 *         docblock for the full case analysis.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   detect()  — record the penalty obligation for a single overdue
 *               installment, transition the installment to `overdue`,
 *               and (on the first occurrence) transition the loan to
 *               `in_arrears`.
 *
 * The operation is invoked by `DetectArrearsCommand` (A.88) — the daily
 * scheduled command that iterates over arrears-eligible loans, filters to
 * installments whose grace threshold has been crossed without full
 * settlement, and calls this service once per eligible installment. The
 * service is NOT invoked from any HTTP endpoint; the arrears detection is
 * a scheduled background fact.
 *
 * The service is intentionally narrow: it handles ONE (loan, installment)
 * pair. The iteration, filtering, and batching are the command's
 * responsibilities. This keeps the service testable in isolation and lets
 * the command decide how to handle per-installment failures (typically:
 * log and continue).
 *
 * =============================================================================
 * The frozen arrears model (migration A.16, D133, Final Reconciliation §4)
 * =============================================================================
 * The D133 freeze (Phase 2C Decision Register, 2026-09-12) and the
 * Phase 2C Final Reconciliation §4.2 specify the exact arrears semantics:
 *
 *     · ONE penalty obligation per overdue installment. NOT daily
 *       penalty accrual. NOT periodic compounding. One penalty per
 *       installment, ever.
 *
 *     · Form: a discrete penalty / late-charge obligation. NOT ordinary
 *       contractual interest.
 *
 *     · Basis: the overdue installment amount (the installment's
 *       `expected_total − settled_amount`, floored at zero).
 *
 *     · Start: after the approved grace period, which is product-
 *       configurable via the frozen `loan_product_rules` policy.
 *
 *     · No compounding. No capitalization into principal. The penalty is
 *       a discrete fact recognized once.
 *
 *     · Cap: product-configurable, nullable. When non-null, the computed
 *       penalty is capped at the declared maximum.
 *
 *     · A partial payment received AFTER the penalty is recorded does
 *       NOT reduce the penalty. The penalty reflects the breach that
 *       occurred at the grace threshold; later repayment activity does
 *       not retroactively change it.
 *
 *     · Restructuring recalculates future arrears treatment but does not
 *       retroactively erase past penalties. The prior schedule's penalty
 *       rows remain.
 *
 *     · Write-off does not erase the arrears ledger.
 *
 * A future Change Request that introduces compounding or escalating
 * penalties would:
 *
 *     1. Extend the key format to include a date component
 *        (`"{loan_id}:{installment_id}:{YYYY-MM-DD}"`).
 *     2. Add a column to record the accrual date (or extract it from the
 *        key) so the daily series is queryable.
 *     3. Update this service and `DetectArrearsCommand` (A.88) to
 *        iterate by date range rather than by "installments whose grace
 *        threshold has been crossed".
 *     4. Reconcile the change with the D133 freeze, which currently
 *        forbids compounding and capitalization.
 *
 * The CR's scope is NOT Phase 2C.
 *
 * =============================================================================
 * The penalty amount — computed from the frozen policy
 * =============================================================================
 * The penalty amount is computed from three values read from the loan's
 * frozen `loan_product_rules` policy payload, all under the
 * `arrears` section:
 *
 *     arrears.enabled       — boolean; when false, the entire arrears
 *                             mechanism is disabled for the product.
 *                             This service throws `LogicException` when
 *                             called on a loan whose product has
 *                             `arrears.enabled = false` (the command
 *                             should have filtered it out).
 *
 *     arrears.basis         — string; MUST be `'overdue_installment'` in
 *                             Phase 2C (per D133). Any other value is a
 *                             `LogicException`.
 *
 *     arrears.grace_days    — integer; the number of days after the
 *                             installment's due date before the penalty
 *                             applies. The command uses this to filter
 *                             eligible installments; the service
 *                             re-reads it for defence.
 *
 *     arrears.penalty_rate  — string; the decimal-fraction rate applied
 *                             to the overdue installment amount (e.g.
 *                             `"0.05"` for 5%).
 *
 *     arrears.cap           — string|null; an optional absolute maximum
 *                             on the penalty amount.
 *
 * The policy payload is read via the keys declared on `LoanPolicyKeys`
 * (A.7). The loan's FROZEN policy version is read, not the product's
 * current version (per the B6/Extra-2 freeze).
 *
 * =============================================================================
 * Idempotency — the unique constraint is the mechanism
 * =============================================================================
 * The unique constraint on `loan_penalty_accruals.accrual_period_key`
 * (migration A.16) is what makes this service idempotent across duplicate
 * command runs, overlapping scheduler instances, and manual invocations.
 * Because the key is deterministic from `(loan_id, installment_id)`, the
 * UNIQUE constraint IS the one-penalty-per-installment rule AND the
 * structural enforcement of D133's "no compounding" clause.
 *
 * The service's flow is identical to `LoanInterestAccrualService` (A.32):
 *
 *   1. Compute the key.
 *   2. Fast-path check: does a row with this key already exist? If so,
 *      return null (already penalized).
 *   3. Attempt to INSERT the row. If a unique-constraint violation fires
 *      (a concurrent command run won the race), catch it and return null.
 *   4. Otherwise, the insert succeeded. Proceed to call the Wallet.
 *
 * The guards around this core flow (loan state, ownership, eligibility)
 * run BEFORE the fast-path check so that a caller who hands the service
 * the wrong installment is told so loudly, rather than silently
 * receiving a null. The eligibility guard in particular MUST run AFTER
 * the fast-path: an installment that has already been penalized is in
 * `overdue` status, and the guard rejects `overdue` — so the fast-path
 * must have the opportunity to short-circuit first. See v1.0.3's
 * revision note for the full ordering rationale.
 *
 * =============================================================================
 * The loan's transition to `in_arrears`
 * =============================================================================
 * The frozen state machine (A.2) allows the transition `active →
 * in_arrears` and no other path into `in_arrears`. This service performs
 * that transition ONCE per loan — on the first overdue installment it
 * detects — and dispatches `LoanEnteredArrears` (A.44) after commit.
 *
 * Subsequent overdue installments of the same loan do NOT re-transition
 * the loan (it is already in `in_arrears`). They only dispatch
 * `LoanPenaltyRecorded` (A.45).
 *
 * The check is: `$loan->status === LoanStatus::Active`. If the loan is
 * already `in_arrears`, no transition is performed. If the loan is in any
 * other status, the service throws `LogicException` (the command should
 * have filtered the loan out; see §"Eligible states").
 *
 * =============================================================================
 * Eligible states
 * =============================================================================
 *   Loan-level eligible states:      `active`, `in_arrears`.
 *   Loan-level transition-eligible:  `active` only.
 *
 *   Installment-level eligible states:      `pending`, `partial`.
 *   Installment-level transition target:    `overdue`.
 *
 * A loan in `disbursed` or `restructured` status is not arrears-eligible:
 * both are transient states that settle into `active` in the same
 * transaction that produces them, and the daily command will never
 * observe them.
 *
 * An installment in `settled`, `overdue`, or `superseded` status is not
 * penalty-eligible: `settled` is terminal, `overdue` implies a penalty
 * already exists (idempotency), and `superseded` belongs to a prior
 * schedule (the restructure service discharges its obligations).
 *
 * The `overdue` case is handled by the idempotency fast-path BEFORE the
 * eligibility guard runs: an `overdue` installment whose penalty row
 * exists returns null from the fast-path; an `overdue` installment whose
 * penalty row is missing (data-integrity defect) reaches the guard and
 * is rejected. See §"Idempotency — the unique constraint is the
 * mechanism" and v1.0.3's revision note.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry point
 * for its operation. One transaction per (loan, installment) pair means
 * that a failure in one installment does not affect any other — the
 * command logs and continues.
 *
 * The transaction wraps:
 *
 *   - the fast-path idempotency check (a read);
 *   - the penalty-row insert (the idempotency mechanism's write side);
 *   - the Wallet penalty obligation write (`ObligationService::record()`,
 *     which internally opens a nested savepoint and locks the Wallet row);
 *   - the penalty row's `wallet_obligation_id` write-back;
 *   - the installment's status transition to `overdue`;
 *   - the loan's possible transition to `in_arrears`;
 *   - the loan's `outstanding_penalty` update.
 *
 * If any step fails after the penalty row is inserted, the entire
 * transaction rolls back — the penalty row, the Wallet obligation, the
 * installment status, the loan status (if changed), and the loan
 * projection update are all discarded together. The next command run will
 * retry the installment from a clean state.
 *
 * =============================================================================
 * Lock ordering — Wallet → Loan
 * =============================================================================
 * The frozen B2 hybrid-ledger lock order is `Wallet → Payment → Obligation
 * → PaymentAllocation → Loan`. This service acquires locks in exactly that
 * order (see A.30 §"Lock ordering" for the full rationale).
 *
 * The loan is read WITHOUT a lock at the start. The loan-side writes
 * (status transition, outstanding_penalty update) occur AFTER the Wallet
 * lock is acquired.
 *
 * =============================================================================
 * Wallet integration — the required Change Request
 * =============================================================================
 * This service passes `reference_type = 'Loan'` to
 * `ObligationService::record()`. The Wallet's
 * `assertReferenceTypePermitted()` (Phase 2B) accepts this value after
 * CR-2C-WALLET-REFTYPE-001 lands. Without the CR, the Wallet throws
 * `ValidationException` on the obligation write.
 *
 * The obligation's component is `ObligationComponent::Penalty`. The
 * Wallet's `ObligationComponent` enum (Phase 2B, 2B.3) already declares
 * this case; no Wallet change is required for the component.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not iterate over loans or installments. The command owns
 *     the iteration. See §"Scope".
 *
 *   - It does not recompute the penalty after the fact. See the D133
 *     freeze: a partial payment received after the penalty is recorded
 *     does NOT reduce the penalty amount.
 *
 *   - It does not compound the penalty. See §"The frozen arrears model".
 *
 *   - It does not capitalize the penalty into principal. D133 prohibits
 *     it.
 *
 *   - It does not dispatch `LoanEnteredArrears` on every overdue
 *     installment. It dispatches that event only on the transition that
 *     moves the loan into `in_arrears`. See §"The loan's transition to
 *     `in_arrears`".
 *
 *   - It does not reverse a penalty. Phase 2C does not support reversing
 *     a penalty; a correction would require an explicit Wallet reversal
 *     and a new penalty row, which is out of scope.
 *
 *   - It does not update `loan.outstanding_principal` or
 *     `loan.outstanding_interest`. Only `outstanding_penalty` is affected
 *     by this operation.
 *
 *   - It does not touch the loan's schedule rows (the `expected_*`
 *     columns) or its lifecycle beyond the possible `active →
 *     in_arrears` transition.
 *
 *   - It does not enforce a lower or upper date bound on `$asOf`. The
 *     command decides which installments are eligible; the service trusts
 *     the caller's determination and validates only the essential
 *     invariants (state, ownership, not superseded, policy-enabled,
 *     basis-correct).
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D133 (arrears rules),
 *                                                  B1 (reference types),
 *                                                  B3 (interest recording
 *                                                  pattern that arrears
 *                                                  follows)
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (this table's
 *                                                  specification),
 *                                                  §4.3 (command
 *                                                  semantics), §4.4
 *                                                  (policy-data-driven
 *                                                  amounts)
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.7                                 — `LoanPolicyKeys`
 *   Phase 2C A.25                                — `LoanPenaltyAccrual`
 *                                                  (the model)
 *   Phase 2C A.32                                — `LoanInterestAccrualService`
 *                                                  (the structural sibling)
 *   Phase 2B 2B.16                               — `ObligationService`
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   Step 5 (manifest) A.33                       — This file
 *   Step 6 v1.1.0 §3.16                          — `loan_penalty_accruals`
 *                                                  table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.3
 */
final class LoanArrearsService
{
    /**
     * The `reference_type` value this service writes to the Wallet for
     * the penalty obligation.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The scale at which monetary comparisons and computations are
     * performed.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * The scale at which intermediate penalty computations are held
     * before rounding.
     *
     * Matches the intermediate scale used by `AmortizationCalculator`
     * (A.6). The choice of 20 is dictated by the same rationale: enough
     * headroom that no digit is lost before the final rounding.
     *
     * @var int
     */
    private const INTERMEDIATE_SCALE = 20;

    /**
     * The only `arrears.basis` value the Phase 2C service supports.
     *
     * Per D133: the penalty is computed against the overdue installment
     * amount. Any other basis is a data defect.
     *
     * @var string
     */
    private const SUPPORTED_BASIS = 'overdue_installment';

    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution for the
     *     borrower.
     *
     *   - `ObligationService` — the Wallet domain's write path for
     *     obligations. Records the penalty obligation and writes the
     *     corresponding `wallet_entries` ledger row.
     *
     * The service does NOT inject `LoanStateMachine`. The `active →
     * in_arrears` transition is performed via the state machine, which is
     * injected as a third dependency.
     *
     * @param WalletRepository  $walletRepository
     * @param ObligationService $obligationService
     * @param LoanStateMachine  $stateMachine
     */
    public function __construct(
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
        private readonly LoanStateMachine $stateMachine,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================
    /**
     * Record the penalty obligation for a single overdue installment.
     *
     * See the class docblock §"The frozen arrears model" and
     * §"Idempotency" for the semantics.
     *
     * Returns the created `LoanPenaltyAccrual` on success, or null when
     * the penalty was skipped (already penalized, or the computed penalty
     * is zero). A null return is not an error; it is the idempotency
     * mechanism working as designed.
     *
     * ------------------------------------------------------------------
     * Order of operations — read this before editing.
     * ------------------------------------------------------------------
     * The order of the guards and the fast-path check is load-bearing:
     *
     *   1. Loan state check.
     *   2. Installment ownership check.
     *   3. Fast-path idempotency check (key computed here).
     *   4. Installment eligibility guard.
     *   5. Policy payload resolution and validation.
     *   6. Penalty computation.
     *   7. Insert.
     *   8. Wallet resolution.
     *   9. Wallet obligation write.
     *  10. Write-back.
     *  11. Installment status transition to `overdue`.
     *  12. Loan `outstanding_penalty` update.
     *  13. Loan transition to `in_arrears` (if currently `active`).
     *  14. Register `LoanPenaltyRecorded` for after-commit dispatch.
     *
     * Steps 3 and 4 must NOT be swapped. Step 4 rejects `overdue`
     * installments, and an installment that has already been penalized
     * IS in `overdue` status (step 11 of the first call). If step 4 ran
     * before step 3, a second call on the same installment would raise
     * `LogicException` instead of returning null, breaking the
     * documented idempotency contract. See the class docblock
     * §"Revision history (1.0.3)" for the full rationale.
     *
     * ------------------------------------------------------------------
     * Caller-shape matrix (post-v1.0.3)
     * ------------------------------------------------------------------
     *   installment state | penalty row exists | behavior
     *   ------------------+---------------------+--------------------
     *   pending/partial   | no                  | proceeds; penalty recorded
     *   pending/partial   | yes                 | returns null (idempotent)
     *   overdue           | yes                 | returns null (idempotent)
     *   overdue           | no                  | guard rejects (data-integrity defect)
     *   settled           | yes                 | returns null (idempotent)
     *   settled           | no                  | guard rejects
     *   superseded        | yes                 | returns null (idempotent)
     *   superseded        | no                  | guard rejects
     *
     * ------------------------------------------------------------------
     *
     * @param Loan                 $loan           The loan whose
     *                                              installment is being
     *                                              penalized.
     * @param LoanInstallment      $installment    The overdue installment.
     * @param CarbonInterface|null $asOf           The moment of detection;
     *                                              defaults to `now()`.
     *                                              Used as the
     *                                              `recorded_at`
     *                                              timestamp.
     * @param int|null             $actorUserId    The acting user, or null
     *                                              for a system-initiated
     *                                              detection (the normal
     *                                              case for the daily
     *                                              command).
     * @return LoanPenaltyAccrual|null
     *
     * @throws LogicException        When a required relation is missing,
     *                               the loan state is ineligible, the
     *                               installment state is ineligible
     *                               (and no penalty has been recorded),
     *                               or the policy payload is malformed.
     * @throws QueryException On an unexpected
     *                               database error.
     */
    public function detect(
        Loan $loan,
        LoanInstallment $installment,
        ?CarbonInterface $asOf = null,
        ?int $actorUserId = null,
    ): ?LoanPenaltyAccrual {
        if (!$loan->exists) {
            throw new LogicException(
                'LoanArrearsService::detect() requires a persisted loan.'
            );
        }

        if (!$installment->exists) {
            throw new LogicException(
                'LoanArrearsService::detect() requires a persisted '
                . 'installment.'
            );
        }

        return DB::transaction(function () use (
            $loan,
            $installment,
            $asOf,
            $actorUserId,
        ): ?LoanPenaltyAccrual {
            // -----------------------------------------------------------------
            // 1. Validate the loan's state.
            //
            // Cheap and authoritative. Runs before anything else so that a
            // clearly-ineligible loan is rejected without any further work.
            // -----------------------------------------------------------------
            $this->assertLoanIsArrearsEligible($loan);

            // -----------------------------------------------------------------
            // 2. Validate the installment's ownership.
            //
            // The `(loan_id, installment_id)` pair on which the
            // idempotency key is built must be a legitimate pair; the
            // ownership check defends that invariant.
            // -----------------------------------------------------------------
            $this->assertInstallmentBelongsToLoan($loan, $installment);

            // -----------------------------------------------------------------
            // 3. Compute the idempotency key and run the fast-path check.
            //
            // Runs BEFORE the eligibility guard (step 4). An installment
            // that has already been penalized IS in `overdue` status —
            // which step 4 rejects. The fast-path must run first so that
            // a second call on the same installment short-circuits with
            // `return null` instead of raising `LogicException`.
            //
            // See the class docblock §"Revision history (1.0.3)".
            // -----------------------------------------------------------------
            $key = sprintf('%d:%d', $loan->id, $installment->id);

            $existing = LoanPenaltyAccrual::query()
                ->where('accrual_period_key', $key)
                ->exists();

            if ($existing) {
                return null;
            }

            // -----------------------------------------------------------------
            // 4. Validate the installment's eligibility.
            //
            // At this point, no penalty has been recorded for this
            // (loan, installment) pair. An `overdue` installment reaching
            // this point therefore has a data-integrity defect (an
            // `overdue` installment without its penalty row), and the
            // guard correctly rejects it.
            // -----------------------------------------------------------------
            $this->assertInstallmentIsPenaltyEligible($installment);

            // -----------------------------------------------------------------
            // 5. Read the frozen policy payload.
            // -----------------------------------------------------------------
            $payload = $this->resolvePolicyPayload($loan);

            $this->assertArrearsEnabled($payload);
            $this->assertBasisIsSupported($payload);

            $penaltyRate = $this->extractPenaltyRate($payload);
            $cap         = $this->extractCap($payload);

            // -----------------------------------------------------------------
            // 6. Compute the penalty from the installment's overdue
            //    amount (per D133: the overdue installment amount).
            // -----------------------------------------------------------------
            $overdueAmount = $installment->remainingTotal();

            if (bccomp($overdueAmount, '0', self::FINAL_SCALE) === 0) {
                // The installment is fully settled but was somehow not
                // marked as such. Defensive skip.
                return null;
            }

            $penaltyAmount = $this->computePenalty($overdueAmount, $penaltyRate);

            if ($cap !== null) {
                $penaltyAmount = $this->applyCap($penaltyAmount, $cap);
            }

            if (bccomp($penaltyAmount, '0', self::FINAL_SCALE) === 0) {
                // A zero-amount penalty (e.g., a zero rate) produces no
                // ledger effect and is skipped.
                return null;
            }

            $now = $asOf !== null
                ? Carbon::instance($asOf)
                : Carbon::now();

            // -----------------------------------------------------------------
            // 7. Insert the penalty row.
            //
            // The unique constraint on `accrual_period_key` is the
            // structural enforcement of the one-penalty-per-installment
            // rule. The fast-path check at step 3 handles the common case;
            // this try/catch handles the concurrent-command race.
            // -----------------------------------------------------------------
            try {
                /** @var LoanPenaltyAccrual $accrual */
                $accrual = LoanPenaltyAccrual::create([
                    'loan_id'              => $loan->id,
                    'installment_id'       => $installment->id,
                    'accrual_period_key'   => $key,
                    'penalty_amount'       => $penaltyAmount,
                    'wallet_obligation_id' => null,
                    'recorded_at'          => $now,
                    'created_at'           => $now,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    // A concurrent detection won the race. Treat as
                    // already penalized.
                    return null;
                }

                throw $e;
            }

            // -----------------------------------------------------------------
            // 8. Resolve the borrower's Wallet.
            // -----------------------------------------------------------------
            $wallet = $this->resolveWallet($loan);

            // -----------------------------------------------------------------
            // 9. Record the Wallet penalty obligation.
            //
            // The penalty has no separate due date — it is immediately
            // due when recorded — so `due_at` is null. The obligation's
            // `reference_type = 'Loan'` and `reference_id = (string)
            // $loan->id` link it back to the loan.
            //
            // The Wallet row is locked for the duration of the
            // transaction. FIRST lock acquired (Wallet → Loan order).
            // -----------------------------------------------------------------
            $obligation = $this->obligationService->record(
                wallet: $wallet,
                component: ObligationComponent::Penalty,
                amount: $penaltyAmount,
                occurredAt: $now,
                dueAt: null,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN,
                referenceId: (string) $loan->id,
                description: sprintf(
                    'Arrears penalty for loan #%d installment #%d',
                    $loan->id,
                    $installment->installment_number,
                ),
                createdBy: $actorUserId,
            );

            // -----------------------------------------------------------------
            // 10. Write back the Wallet obligation id.
            // -----------------------------------------------------------------
            $accrual->wallet_obligation_id = $obligation->id;
            $accrual->save();

            // -----------------------------------------------------------------
            // 11. Transition the installment to `overdue`.
            //
            // The installment's status change is part of the same
            // transaction as the penalty recording. If the transaction
            // rolls back, the installment's status is not changed.
            // -----------------------------------------------------------------
            $installment->status = InstallmentStatus::Overdue;
            $installment->save();

            // -----------------------------------------------------------------
            // 12. Update the loan's `outstanding_penalty`.
            // -----------------------------------------------------------------
            $loan->outstanding_penalty = bcadd(
                (string) $loan->outstanding_penalty,
                $penaltyAmount,
                self::FINAL_SCALE,
            );
            $loan->save();

            // -----------------------------------------------------------------
            // 13. Transition the loan to `in_arrears` if it is currently
            //     `active`.
            //
            // On the first overdue installment, the loan moves from
            // `active` to `in_arrears` and dispatches `LoanEnteredArrears`
            // after commit. On subsequent overdue installments, the loan
            // is already in `in_arrears` and no transition occurs.
            // -----------------------------------------------------------------
            $loanEnteredArrearsEvent = null;

            if ($loan->status === LoanStatus::Active) {
                $loanEnteredArrearsEvent = new LoanEnteredArrears(
                    $loan,
                    $installment,
                    $actorUserId,
                );

                $loan = $this->stateMachine->transition(
                    loan: $loan,
                    nextStatus: LoanStatus::InArrears,
                    reason: null,
                    actorUserId: $actorUserId,
                    afterCommitEvent: $loanEnteredArrearsEvent,
                );
            }

            // -----------------------------------------------------------------
            // 14. Register the `LoanPenaltyRecorded` event for
            //     after-commit dispatch.
            // -----------------------------------------------------------------
            $this->registerAfterCommitEvent(
                new LoanPenaltyRecorded(
                    $loan,
                    $installment,
                    $accrual,
                    $actorUserId,
                )
            );

            return $accrual->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the loan is in a state that permits arrears detection.
     *
     * Eligible states: `active`, `in_arrears`.
     *
     * @param Loan $loan
     * @return void
     *
     * @throws LogicException
     */
    private function assertLoanIsArrearsEligible(Loan $loan): void
    {
        $eligible = [
            LoanStatus::Active,
            LoanStatus::InArrears,
        ];

        foreach ($eligible as $status) {
            if ($loan->status === $status) {
                return;
            }
        }

        throw new LogicException(
            sprintf(
                'LoanArrearsService::detect() called on a loan in status '
                . '"%s", which is not arrears-eligible. The command '
                . 'should have filtered this loan out before calling the '
                . 'service.',
                $loan->status->value,
            )
        );
    }

    /**
     * Assert that the installment belongs to the given loan.
     *
     * @param Loan            $loan
     * @param LoanInstallment $installment
     * @return void
     *
     * @throws LogicException
     */
    private function assertInstallmentBelongsToLoan(
        Loan $loan,
        LoanInstallment $installment,
    ): void {
        if ((int) $installment->loan_id !== (int) $loan->id) {
            throw new LogicException(
                sprintf(
                    'LoanArrearsService::detect() called with installment '
                    . 'id=%d belonging to loan id=%d, but invoked for '
                    . 'loan id=%d. This is a caller defect.',
                    $installment->id,
                    $installment->loan_id,
                    $loan->id,
                )
            );
        }
    }

    /**
     * Assert that the installment is eligible for penalty detection.
     *
     * Eligible states: `pending`, `partial`. Ineligible: `settled`,
     * `overdue`, `superseded`.
     *
     * ------------------------------------------------------------------
     * Ordering invariant — read this before moving this method.
     * ------------------------------------------------------------------
     * This guard MUST run AFTER the idempotency fast-path check in
     * `detect()` (see v1.0.3's revision note on the class docblock).
     * An installment that has already been penalized IS in `overdue`
     * status, so an `overdue` installment reaching this guard means
     * that NO penalty row exists for the `(loan_id, installment_id)`
     * pair — a data-integrity defect, correctly rejected here.
     *
     * If this guard ran before the fast-path check, the service's
     * documented idempotency contract would be broken: a second
     * `detect()` call on an already-penalized installment would raise
     * `LogicException` instead of returning null.
     * ------------------------------------------------------------------
     *
     * The status is rendered null-safely in the error message. A null
     * status on the passed installment is not a state the service can
     * produce (the column is NOT NULL with a `pending` default, and the
     * model casts it to `InstallmentStatus`), but it IS a state a caller
     * can hand us — typically when the caller has constructed the model
     * without persisting or re-reading it. The null-safe `?->` chain
     * ensures the intended `LogicException` is raised with a diagnosable
     * message instead of the null dereference crashing first. See the
     * class docblock §"Revision history (1.0.2)".
     *
     * @param LoanInstallment $installment
     * @return void
     *
     * @throws LogicException
     */
    private function assertInstallmentIsPenaltyEligible(
        LoanInstallment $installment,
    ): void {
        $eligible = [
            InstallmentStatus::Pending,
            InstallmentStatus::Partial,
        ];

        foreach ($eligible as $status) {
            if ($installment->status === $status) {
                return;
            }
        }

        throw new LogicException(
            sprintf(
                'LoanArrearsService::detect() called on installment '
                . 'id=%d in status "%s", which is not penalty-eligible. '
                . 'The command should have filtered this installment '
                . 'out.',
                $installment->id,
                $installment->status?->value ?? 'null',
            )
        );
    }

    // =========================================================================
    // Private — policy resolution
    // =========================================================================

    /**
     * Resolve and validate the loan's frozen policy payload.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     *
     * @throws LogicException
     */
    private function resolvePolicyPayload(Loan $loan): array
    {
        $loan->loadMissing('policyVersion');

        /** @var VersionedPolicy|null $policy */
        $policy = $loan->policyVersion;

        if ($policy === null) {
            throw new LogicException(
                sprintf(
                    'Loan id=%d has no frozen policy version. Arrears '
                    . 'detection cannot proceed without the product\'s '
                    . 'arrears rules. Data-integrity defect in the '
                    . 'approval flow.',
                    $loan->id,
                )
            );
        }

        $payload = $policy->payload;

        if (!is_array($payload)) {
            throw new LogicException(
                sprintf(
                    'Policy version id=%d has a non-array payload.',
                    $policy->id,
                )
            );
        }

        return $payload;
    }

    /**
     * Assert that the arrears section is present and enabled.
     *
     * @param array<string, mixed> $payload
     * @return void
     *
     * @throws LogicException
     */
    private function assertArrearsEnabled(array $payload): void
    {
        $arrearsSection = $payload[LoanPolicyKeys::ARREARS_SECTION] ?? null;

        if (!is_array($arrearsSection)) {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the "%s" '
                    . 'section, or the section is not an object.',
                    LoanPolicyKeys::ARREARS_SECTION,
                )
            );
        }

        if (($arrearsSection[LoanPolicyKeys::ARREARS_ENABLED] ?? false) !== true) {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload has arrears disabled '
                    . '(%s.%s = false). The command should have filtered '
                    . 'the loan out.',
                    LoanPolicyKeys::ARREARS_SECTION,
                    LoanPolicyKeys::ARREARS_ENABLED,
                )
            );
        }
    }

    /**
     * Assert that the `arrears.basis` value is the supported one.
     *
     * @param array<string, mixed> $payload
     * @return void
     *
     * @throws LogicException
     */
    private function assertBasisIsSupported(array $payload): void
    {
        $arrearsSection = $payload[LoanPolicyKeys::ARREARS_SECTION];
        $basis          = $arrearsSection[LoanPolicyKeys::ARREARS_BASIS] ?? null;

        if ($basis !== self::SUPPORTED_BASIS) {
            throw new LogicException(
                sprintf(
                    'The arrears basis "%s" is not supported by Phase 2C. '
                    . 'The only supported basis is "%s" (per D133). A '
                    . 'future Change Request would extend the reader to '
                    . 'support additional bases.',
                    is_string($basis) ? $basis : get_debug_type($basis),
                    self::SUPPORTED_BASIS,
                )
            );
        }
    }

    /**
     * Extract the penalty rate from the arrears section.
     *
     * @param array<string, mixed> $payload
     * @return string
     *
     * @throws LogicException
     */
    private function extractPenaltyRate(array $payload): string
    {
        $arrearsSection = $payload[LoanPolicyKeys::ARREARS_SECTION];
        $rate           = $arrearsSection[LoanPolicyKeys::ARREARS_PENALTY_RATE] ?? null;

        if (!is_string($rate) || $rate === '') {
            throw new LogicException(
                sprintf(
                    'The "%s.%s" value must be a non-empty decimal '
                    . 'string; got a value of type %s.',
                    LoanPolicyKeys::ARREARS_SECTION,
                    LoanPolicyKeys::ARREARS_PENALTY_RATE,
                    get_debug_type($rate),
                )
            );
        }

        if (!preg_match('/^\d+(\.\d{1,20})?$/', $rate)) {
            throw new LogicException(
                sprintf(
                    'The "%s.%s" value "%s" is not a non-negative '
                    . 'decimal string with at most 20 decimal places.',
                    LoanPolicyKeys::ARREARS_SECTION,
                    LoanPolicyKeys::ARREARS_PENALTY_RATE,
                    $rate,
                )
            );
        }

        return $rate;
    }

    /**
     * Extract the optional cap from the arrears section.
     *
     * Returns null when the cap is absent, null, or an empty string.
     *
     * @param array<string, mixed> $payload
     * @return string|null
     *
     * @throws LogicException
     */
    private function extractCap(array $payload): ?string
    {
        $arrearsSection = $payload[LoanPolicyKeys::ARREARS_SECTION];
        $cap            = $arrearsSection[LoanPolicyKeys::ARREARS_CAP] ?? null;

        if ($cap === null || $cap === '') {
            return null;
        }

        if (!is_string($cap)) {
            throw new LogicException(
                sprintf(
                    'The "%s.%s" value must be a decimal string or null; '
                    . 'got a value of type %s.',
                    LoanPolicyKeys::ARREARS_SECTION,
                    LoanPolicyKeys::ARREARS_CAP,
                    get_debug_type($cap),
                )
            );
        }

        if (!preg_match('/^\d+(\.\d{1,20})?$/', $cap)) {
            throw new LogicException(
                sprintf(
                    'The "%s.%s" value "%s" is not a non-negative '
                    . 'decimal string with at most 20 decimal places.',
                    LoanPolicyKeys::ARREARS_SECTION,
                    LoanPolicyKeys::ARREARS_CAP,
                    $cap,
                )
            );
        }

        return $cap;
    }

    // =========================================================================
    // Private — penalty computation
    // =========================================================================

    /**
     * Compute the penalty amount from the overdue amount and the rate.
     *
     * The result is `halfUp(overdue_amount · rate, 4)`.
     *
     * @param string $overdueAmount
     * @param string $rate
     * @return string  Decimal string at scale 4.
     */
    private function computePenalty(string $overdueAmount, string $rate): string
    {
        $raw = bcmul($overdueAmount, $rate, self::INTERMEDIATE_SCALE);

        return $this->halfUp($raw, self::FINAL_SCALE);
    }

    /**
     * Apply the cap to the computed penalty.
     *
     * The result is `min(penalty, cap)` at scale 4.
     *
     * @param string $penalty
     * @param string $cap
     * @return string  Decimal string at scale 4.
     */
    private function applyCap(string $penalty, string $cap): string
    {
        if (bccomp($penalty, $cap, self::FINAL_SCALE) > 0) {
            return bcadd($cap, '0', self::FINAL_SCALE);
        }

        return $penalty;
    }

    /**
     * Round a decimal string to a target scale using half-up rounding.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * This method is a byte-for-byte copy of
     * `AmortizationCalculator::halfUp()` (A.6, patched in the same
     * deployment as v1.0.1) and `LoanScheduleService::halfUp()` (A.27,
     * patched in the same deployment as v1.0.1).
     *
     * The previous construction of the half-ULP was
     * `'0.' . str_repeat('0', $scale - 1) . '5'`, which at `scale = 4`
     * produces `'0.0005'` (the half-ULP of scale 3, not scale 4). The
     * corrected construction is `'0.' . str_repeat('0', $scale) . '5'`,
     * which at `scale = 4` produces `'0.00005'`. See the
     * `AmortizationCalculator` v1.0.1 revision note for a worked
     * example.
     *
     * All three copies MUST be kept in lockstep pending a follow-up
     * Change Request that extracts the helper into a shared trait.
     *
     * @param string $value
     * @param int    $scale
     * @return string
     */
    private function halfUp(string $value, int $scale): string
    {
        $sign = '';

        if (str_starts_with($value, '-')) {
            $sign  = '-';
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        $halfUlp = $scale === 0
            ? '0.5'
            : '0.' . str_repeat('0', $scale) . '5';

        $shifted   = bcadd($value, $halfUlp, self::INTERMEDIATE_SCALE);
        $truncated = bcadd($shifted, '0', $scale);

        return $sign . $truncated;
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================
    /**
     * Resolve the borrower's Wallet.
     *
     * @param Loan $loan
     * @return Wallet
     *
     * @throws LogicException
     */
    private function resolveWallet(Loan $loan): Wallet
    {
        $loan->loadMissing('borrower');

        $borrower = $loan->borrower;

        if ($borrower === null) {
            throw new LogicException(
                sprintf(
                    'Loan id=%d has no borrower. Data-integrity defect.',
                    $loan->id,
                )
            );
        }

        $wallet = $this->walletRepository->findForShareholder(
            (int) $loan->tenant_id,
            (int) $borrower->id,
        );

        if ($wallet === null) {
            throw new LogicException(
                sprintf(
                    'Borrower id=%d has no Wallet, yet loan id=%d is in '
                    . 'an arrears-eligible state. Data-integrity defect.',
                    $borrower->id,
                    $loan->id,
                )
            );
        }

        return $wallet;
    }

    // =========================================================================
    // Private — exception classification
    // =========================================================================

    /**
     * Whether a `QueryException` represents a UNIQUE-constraint violation.
     *
     * @param QueryException $e
     * @return bool
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    // =========================================================================
    // Private — event registration
    // =========================================================================

    /**
     * Register an event for after-commit dispatch, gated on
     * `config('loan.audit.emit_events')`.
     *
     * The `LoanPenaltyRecorded` event is registered here directly; it is
     * not tied to a status transition, so the state machine does not
     * handle it. The `LoanEnteredArrears` event IS tied to a status
     * transition and is registered by the state machine (see step 13).
     *
     * @param object $event
     * @return void
     */
    private function registerAfterCommitEvent(object $event): void
    {
        if (!config('loan.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}