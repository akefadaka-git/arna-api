<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;

/**
 * LoanInterestAccrualService
 *
 * Records ONE interest obligation per installment, at the installment's due
 * date, on the borrower's Wallet.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — Added `normaliseLoanStatus()` and `normaliseInstallmentStatus()`
 *         private helpers; routed both eligibility guards through them.
 *         The helpers were introduced under the diagnosis that a
 *         string-vs-enum comparison was producing false negatives in the
 *         guards, on the theory that "whether the model casts the
 *         attribute depends on the runtime `$casts` configuration".
 *
 * 1.0.2 — B6 audit remediation. Removed both `normalise*` helpers and
 *         restored direct enum comparisons in the two guards.
 *
 *         BACKGROUND
 *         ----------
 *         The diagnosis that motivated v1.0.1 was wrong. The failures
 *         that prompted it were caused by the mass-assignment opening
 *         of `LoanInstallment::$fillable` (see the B2 remediation on
 *         that model), not by a casting ambiguity. The summary of the
 *         remediation itself acknowledged this: "The enum-value
 *         comparisons I added to `LoanInterestAccrualService` and
 *         `LoanCollateralService` are correct but not load-bearing
 *         after the `$fillable` fixes."
 *
 *         The two models involved cast their status columns directly to
 *         the domain enums — `Loan.status` → `LoanStatus::class`,
 *         `LoanInstallment.status` → `InstallmentStatus::class`. With
 *         those casts in place, `$loan->status` and
 *         `$installment->status` are always enum instances, and a
 *         direct `===` comparison against the enum case is the
 *         correct, unambiguous PHP 8 form.
 *
 *         The helpers were therefore dead defensive code. Leaving them
 *         in place would carry a false signal — that the underlying
 *         representation is uncertain — and would obscure the true
 *         cause of any future failure in the same area. The B6 audit
 *         position was to revert rather than retain.
 *
 *         EFFECT
 *         ------
 *         Behaviour-neutral. Both guards produce the same accept/reject
 *         decision for every reachable input. The only observable change
 *         is the internal shape of the check and the absence of the two
 *         private methods.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   accrue()  — record the interest obligation for a single installment.
 *
 * The operation is invoked by `AccrueInterestCommand` (A.87) — the daily
 * scheduled command that iterates over accrual-eligible loans, filters to
 * installments whose due date has arrived, and calls this service once per
 * eligible installment. The service is NOT invoked from any HTTP endpoint;
 * the interest is a scheduled background fact.
 *
 * The service is intentionally narrow: it handles ONE (loan, installment)
 * pair. The iteration, filtering, and batching are the command's
 * responsibilities. This keeps the service testable in isolation and lets
 * the command decide how to handle per-installment failures (typically: log
 * and continue).
 *
 * =============================================================================
 * The frozen accrual model (migration A.15, Final Reconciliation §4)
 * =============================================================================
 * The B3 freeze (Phase 2C Decision Register, 2026-09-12) and the
 * Phase 2C Final Reconciliation §4.2 specify the exact accrual semantics:
 *
 *     · ONE interest obligation per installment. NOT daily accrual.
 *       NOT periodic compounding. One accrual per installment, ever.
 *
 *     · The accrual is recognized AT the installment's due date. If the
 *       command runs late (e.g., after a scheduler outage), it can
 *       recognize a past-due installment's interest on a later day; the
 *       `recorded_at` timestamp reflects the recognition moment, not the
 *       due date. The due date is preserved on the Wallet obligation's
 *       `occurred_at` and `due_at` columns.
 *
 *     · The accrual is IDEMPOTENT: an installment can be accrued at most
 *       once, enforced at the database level by the UNIQUE constraint on
 *       `loan_interest_accruals.accrual_period_key`. See §"Idempotency".
 *
 * A future Change Request that introduces TRUE daily accrual (the interest
 * amount grows each day in arrears) would:
 *
 *     1. Extend the key format to include a date component
 *        (`"{loan_id}:{installment_id}:{YYYY-MM-DD}"`).
 *     2. Add a column to record the accrual date (or extract it from the
 *        key) so the daily series is queryable.
 *     3. Update this service and `AccrueInterestCommand` (A.87) to
 *        iterate by date range rather than by "installments whose due
 *        date has arrived".
 *     4. Reconcile the change with the A9 freeze (declining balance,
 *        monthly periods, no compounding of ordinary contractual
 *        interest), which currently forbids daily accrual.
 *
 * The CR's scope is NOT Phase 2C.
 *
 * =============================================================================
 * The interest amount — read from the installment, not recomputed
 * =============================================================================
 * The interest amount for the accrual is the installment's
 * `expected_interest` column (migration A.10). That value was computed at
 * schedule-generation time (by `LoanScheduleService`, A.27, using
 * `AmortizationCalculator`, A.6), against the loan's frozen
 * `loan_product_rules` policy. The schedule is fixed at disbursement and
 * does not change except through a restructuring (which creates a NEW
 * schedule; the old schedule's rows are superseded).
 *
 * This service therefore does NOT recompute the interest. Recomputing
 * would duplicate the calculator's logic and risk divergence from the
 * stored schedule. The installment's `expected_interest` is the
 * authoritative value.
 *
 * Consequence: the loan's frozen policy version is NOT read by this
 * service. The freeze happened at approval (A.29) and was applied at
 * schedule generation (A.27). By the time interest is accrued, the
 * schedule already encodes the correct amount.
 *
 * =============================================================================
 * Idempotency — the unique constraint is the mechanism
 * =============================================================================
 * The unique constraint on `loan_interest_accruals.accrual_period_key`
 * (migration A.15) is what makes this service idempotent across duplicate
 * command runs, overlapping scheduler instances, and manual invocations.
 * Because the key is deterministic from `(loan_id, installment_id)`, the
 * UNIQUE constraint IS the one-accrual-per-installment rule.
 *
 * The service's flow:
 *
 *   1. Compute the key.
 *   2. Fast-path check: does a row with this key already exist? If so,
 *      return null (already accrued).
 *   3. Attempt to INSERT the row. If a unique-constraint violation fires
 *      (a concurrent command run won the race), catch it and return null.
 *   4. Otherwise, the insert succeeded. Proceed to call the Wallet.
 *
 * The fast-path check is an optimization: it avoids the exception
 * overhead in the common "already accrued" case. The catch on the
 * unique violation is what makes the operation correct under concurrency.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry point
 * for its operation (the command calls `accrue()` once per installment).
 * One transaction per (loan, installment) pair means that a failure in one
 * installment does not affect any other — the command logs and continues.
 *
 * The transaction wraps:
 *
 *   - the accrual-row insert (the idempotency mechanism);
 *   - the Wallet obligation write (`ObligationService::record()`, which
 *     internally locks the Wallet row);
 *   - the accrual row's `wallet_obligation_id` write-back;
 *   - the loan's `outstanding_interest` update.
 *
 * If any step fails after the accrual row is inserted, the entire
 * transaction rolls back — the accrual row, the Wallet obligation, and the
 * loan projection update are all discarded together. The next command run
 * will retry the installment from a clean state.
 *
 * =============================================================================
 * Lock ordering — Wallet → Loan, and the loan projection is re-read under lock
 * =============================================================================
 * The frozen B2 hybrid-ledger lock order is `Wallet → Payment → Obligation
 * → PaymentAllocation → Loan`. This service acquires locks in exactly that
 * order:
 *
 *   - The accrual-row INSERT (step 7 of §"Idempotency") acquires a lock
 *     on the newly-inserted row only; no cross-transaction contention.
 *
 *   - `ObligationService::record()` locks the Wallet row via
 *     `WalletService::recordEntry()`'s `SELECT ... FOR UPDATE`. That is
 *     the FIRST explicit multi-transaction lock this service acquires.
 *
 *   - The loan's `outstanding_interest` update (the final step) acquires
 *     an explicit row lock on the loan via `SELECT ... FOR UPDATE`. That
 *     is the SECOND and LAST explicit lock, consistent with the frozen
 *     `Wallet → Loan` ordering.
 *
 * -----------------------------------------------------------------------------
 * Why the loan row is explicitly locked and re-read
 * -----------------------------------------------------------------------------
 * A financial projection update MUST NOT be computed from a
 * caller-supplied in-memory model. The `$loan` argument to `accrue()` was
 * loaded by the command at some earlier point in its iteration; between
 * that load and this service's transaction, any number of other
 * operations (a repayment, a restructuring, another accrual) could have
 * modified the loan's `outstanding_interest` in the database. Using the
 * stale in-memory value would silently corrupt the projection.
 *
 * The corrected invariant is therefore:
 *
 *     Wallet lock (acquired by ObligationService::record())
 *         ↓
 *     SELECT ... FOR UPDATE on the loan row  (this service, step 11)
 *         ↓
 *     read current loans.outstanding_interest from the locked row
 *         ↓
 *     BCMath addition of the installment's expected_interest
 *         ↓
 *     UPDATE loans.outstanding_interest
 *
 * The Wallet lock guarantees that no other accrual for the same borrower
 * can interleave; the explicit loan lock guarantees that this service's
 * arithmetic uses the freshly-persisted projection value regardless of
 * the state of the caller's `$loan` instance.
 *
 * The two locks together also protect against a future code path that
 * updates `outstanding_interest` without going through the Wallet — the
 * explicit loan lock is the last line of defence for the projection.
 *
 * The caller's `$loan` instance is NOT mutated by this service. If the
 * caller needs the post-accrual projection value, it must `->refresh()`
 * the loan after the transaction returns.
 *
 * See `LoanDisbursementService` (A.30) §"Lock ordering" for the full
 * rationale on the frozen order.
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
 * The obligation's component is `ObligationComponent::Interest`. The
 * Wallet's `ObligationComponent` enum (Phase 2B, 2B.3) already declares
 * this case; no Wallet change is required for the component.
 *
 * =============================================================================
 * Zero-interest installments
 * =============================================================================
 * An installment whose `expected_interest` is exactly `"0.0000"` — as is
 * the case for every installment of a zero-interest loan — is SKIPPED. The
 * service returns null without inserting an accrual row and without
 * calling the Wallet.
 *
 * The reasoning: the Wallet's `ObligationService::record()` requires a
 * strictly positive amount (Phase 2B, 2B.16); a zero-amount obligation is
 * meaningless. Rather than recording a zero-amount accrual that produces
 * no ledger effect, the service treats the installment as having no
 * interest to accrue.
 *
 * A zero-amount accrual is NOT recorded, so a future re-invocation of the
 * command for the same installment will also skip (the check runs before
 * the key is computed). This is correct: a zero-interest loan has nothing
 * to accrue, and the skip is deterministic.
 *
 * =============================================================================
 * Status comparison — direct enum identity
 * =============================================================================
 * The eligibility checks in this service compare an installment's or a
 * loan's status directly against the domain enum cases:
 *
 *     $loan->status === LoanStatus::Active
 *     $installment->status === InstallmentStatus::Settled
 *
 * Both models cast their status columns to the corresponding enum
 * (`Loan.status` → `LoanStatus::class`;
 * `LoanInstallment.status` → `InstallmentStatus::class`), so the
 * attribute access always yields an enum instance and the `===` check is
 * the correct, unambiguous form.
 *
 * v1.0.1 briefly routed these checks through a pair of `normalise*`
 * helpers under the mistaken belief that the cast configuration might be
 * uncertain at runtime. It is not — the cast is declared on each model
 * and is a compile-time fact of the model, not a runtime accident. v1.0.2
 * reverted the helpers under the B6 audit remediation. See the class
 * docblock §"Revision history" for the full account.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not iterate over loans or installments. The command owns the
 *     iteration. See §"Scope".
 *
 *   - It does not recompute the interest amount. See §"The interest amount".
 *
 *   - It does not dispatch a Loan-domain event. The frozen event list
 *     (A.38–A.50) contains no `LoanInterestAccrued` event; the accrual's
 *     audit trail is the `loan_interest_accruals` row itself, plus the
 *     Wallet's `ObligationRecorded` event (which `ObligationService::record()`
 *     dispatches after commit).
 *
 *   - It does not update `loan.outstanding_principal` or
 *     `loan.outstanding_penalty`. Only `outstanding_interest` is affected
 *     by this operation.
 *
 *   - It does not touch the loan's schedule rows or its lifecycle status.
 *     Interest accrual is orthogonal to the loan's status machine: it
 *     happens while the loan is in any of the four working states
 *     (`disbursed`, `active`, `in_arrears`, `restructured`) and never
 *     triggers a status transition on its own.
 *
 *   - It does not write to `loan_installments`. The installment's
 *     settlement status is driven by repayments (A.31), not by accrual.
 *
 *   - It does not reverse an accrual. Phase 2C does not support reversing
 *     an interest accrual; a correction would require an explicit Wallet
 *     reversal and a new accrual row, which is out of scope.
 *
 *   - It does not enforce a lower or upper date bound on `$asOf`. The
 *     command decides which installments are eligible; the service trusts
 *     the caller's determination and validates only the essential
 *     invariants (state, ownership, not superseded).
 *
 *   - It does not mutate the caller's `$loan` instance. The projection
 *     update is written to the database; the caller's object remains as
 *     loaded. See §"Why the loan row is explicitly locked and re-read".
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9 (interest method),
 *                                                  B1 (reference types),
 *                                                  B3 (interest recording)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (this table's
 *                                                  specification),
 *                                                  §4.3 (command
 *                                                  semantics), §4.4
 *                                                  (policy-data-driven
 *                                                  amounts)
 *   Phase 2C A.6                                 — `AmortizationCalculator`
 *   Phase 2C A.24                                — `LoanInterestAccrual`
 *                                                  (the model)
 *   Phase 2C A.27                                — `LoanScheduleService`
 *                                                  (source of the
 *                                                  schedule and the
 *                                                  `expected_interest`
 *                                                  value)
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *                                                  (the sibling projection
 *                                                  updater)
 *   Phase 2B 2B.16                               — `ObligationService`
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   Step 5 (manifest) A.32                       — This file
 *   Step 6 v1.1.0 §3.15                          — `loan_interest_accruals`
 *                                                  table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.2
 */
final class LoanInterestAccrualService
{
    /**
     * The `reference_type` value this service writes to the Wallet for
     * the interest obligation.
     *
     * Frozen by the B1 decision and by the Phase 2C Final Reconciliation
     * §1.2. The Wallet's `assertReferenceTypePermitted()` (Phase 2B)
     * accepts this value after CR-2C-WALLET-REFTYPE-001 lands.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The scale at which monetary comparisons are performed.
     *
     * Matches the DECIMAL(20,4) columns on both domains.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution for the
     *     borrower.
     *
     *   - `ObligationService` — the Wallet domain's write path for
     *     obligations. Records the interest obligation and writes the
     *     corresponding `wallet_entries` ledger row.
     *
     * The service does NOT inject `LoanStateMachine`. Interest accrual
     * does not change the loan's lifecycle status; see the class docblock
     * §"What this service deliberately does NOT do".
     *
     * @param WalletRepository  $walletRepository
     * @param ObligationService $obligationService
     */
    public function __construct(
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Accrue the interest obligation for a single installment.
     *
     * See the class docblock §"The frozen accrual model" and
     * §"Idempotency" for the semantics.
     *
     * Returns the created `LoanInterestAccrual` on success, or null when
     * the accrual was skipped (zero interest, or already accrued). A null
     * return is not an error; it is the idempotency mechanism working as
     * designed.
     *
     * The caller's `$loan` instance is not mutated. If the caller needs
     * the post-accrual projection value, it must `->refresh()` the loan
     * after this method returns.
     *
     * @param Loan                 $loan           The loan whose
     *                                              installment is being
     *                                              accrued.
     * @param LoanInstallment      $installment    The installment whose
     *                                              due date has arrived.
     * @param CarbonInterface|null $asOf           The moment of accrual;
     *                                              defaults to `now()`.
     *                                              Used as the
     *                                              `recorded_at`
     *                                              timestamp.
     * @param int|null             $actorUserId    The acting user, or null
     *                                              for a system-initiated
     *                                              accrual (the normal
     *                                              case for the daily
     *                                              command).
     * @return LoanInterestAccrual|null
     *
     * @throws LogicException        When a required relation is missing.
     * @throws QueryException        On an unexpected database error.
     */
    public function accrue(
        Loan $loan,
        LoanInstallment $installment,
        ?CarbonInterface $asOf = null,
        ?int $actorUserId = null,
    ): ?LoanInterestAccrual {
        if (!$loan->exists) {
            throw new LogicException(
                'LoanInterestAccrualService::accrue() requires a '
                . 'persisted loan.'
            );
        }

        if (!$installment->exists) {
            throw new LogicException(
                'LoanInterestAccrualService::accrue() requires a '
                . 'persisted installment.'
            );
        }

        return DB::transaction(function () use (
            $loan,
            $installment,
            $asOf,
            $actorUserId,
        ): ?LoanInterestAccrual {
            // -----------------------------------------------------------------
            // 1. Validate the loan's state.
            // -----------------------------------------------------------------
            $this->assertLoanIsAccrualEligible($loan);

            // -----------------------------------------------------------------
            // 2. Validate the installment.
            // -----------------------------------------------------------------
            $this->assertInstallmentBelongsToLoan($loan, $installment);
            $this->assertInstallmentIsAccrualEligible($installment);

            // -----------------------------------------------------------------
            // 3. Read the interest amount from the installment.
            // -----------------------------------------------------------------
            $interestAmount = (string) $installment->expected_interest;

            // -----------------------------------------------------------------
            // 4. Skip zero-interest installments.
            // -----------------------------------------------------------------
            if (bccomp($interestAmount, '0', self::FINAL_SCALE) === 0) {
                return null;
            }

            // -----------------------------------------------------------------
            // 5. Compute the deterministic idempotency key.
            // -----------------------------------------------------------------
            $key = sprintf('%d:%d', $loan->id, $installment->id);

            // -----------------------------------------------------------------
            // 6. Fast path: skip if already accrued.
            // -----------------------------------------------------------------
            $existing = LoanInterestAccrual::query()
                ->where('accrual_period_key', $key)
                ->exists();

            if ($existing) {
                return null;
            }

            $now = $asOf !== null
                ? Carbon::instance($asOf)
                : Carbon::now();

            // -----------------------------------------------------------------
            // 7. Insert the accrual row.
            //
            // The UNIQUE constraint on `accrual_period_key` is what makes
            // the insert idempotent under concurrency. A duplicate key
            // surfaces as a `QueryException` with SQLSTATE 23000; the
            // catch below converts it to a null return.
            // -----------------------------------------------------------------
            try {
                /** @var LoanInterestAccrual $accrual */
                $accrual = LoanInterestAccrual::create([
                    'loan_id'              => $loan->id,
                    'installment_id'       => $installment->id,
                    'accrual_period_key'   => $key,
                    'interest_amount'      => $interestAmount,
                    'wallet_obligation_id' => null,
                    'recorded_at'          => $now,
                    'created_at'           => $now,
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    // A concurrent accrual won the race. Treat as
                    // already accrued.
                    return null;
                }

                throw $e;
            }

            // -----------------------------------------------------------------
            // 8. Resolve the borrower's Wallet.
            // -----------------------------------------------------------------
            $wallet = $this->resolveWallet($loan);

            // -----------------------------------------------------------------
            // 9. Record the Wallet obligation.
            //
            // The obligation's `occurred_at` and `due_at` are the
            // installment's due date: the obligation is FOR the period
            // ending on that date. The obligation's `reference_type =
            // 'Loan'` and `reference_id = (string) $loan->id` link it
            // back to the loan; the Wallet writes a
            // `obligation_recorded` ledger entry with a negative signed
            // quantity equal to the interest amount.
            //
            // The Wallet row is locked for the duration of the
            // transaction. FIRST multi-transaction lock acquired
            // (Wallet → Loan order).
            // -----------------------------------------------------------------
            $dueDate = $installment->due_date;

            $obligation = $this->obligationService->record(
                wallet: $wallet,
                component: ObligationComponent::Interest,
                amount: $interestAmount,
                occurredAt: $dueDate,
                dueAt: $dueDate,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN,
                referenceId: (string) $loan->id,
                description: sprintf(
                    'Interest for loan #%d installment #%d',
                    $loan->id,
                    $installment->installment_number,
                ),
                createdBy: $actorUserId,
            );

            // -----------------------------------------------------------------
            // 10. Write back the Wallet obligation id.
            //
            // The `LoanInterestAccrual` model permits exactly this
            // one-time write-back inside the inserting transaction (see
            // A.24, §"Partial mutability").
            // -----------------------------------------------------------------
            $accrual->wallet_obligation_id = $obligation->id;
            $accrual->save();

            // -----------------------------------------------------------------
            // 11. Update the loan's `outstanding_interest`.
            //
            // The projection update is performed with an EXPLICIT row
            // lock on the loan, and the arithmetic uses the value read
            // from that locked row — NOT the possibly-stale
            // `$loan->outstanding_interest` from the caller's in-memory
            // instance.
            //
            // This is the SECOND and LAST explicit lock acquired by this
            // method. The order is Wallet → Loan, matching the frozen
            // B2 lock order. The Wallet lock (acquired in step 9) and
            // this loan lock together guarantee that no other Phase 2C
            // operation can interleave a change to this loan's
            // outstanding_interest between the read and the write.
            //
            // A financial projection update MUST NOT be computed from a
            // caller-supplied in-memory value. See the class docblock
            // §"Why the loan row is explicitly locked and re-read".
            // -----------------------------------------------------------------
            /** @var Loan $lockedLoan */
            $lockedLoan = Loan::query()
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLoan->outstanding_interest = bcadd(
                (string) $lockedLoan->outstanding_interest,
                $interestAmount,
                self::FINAL_SCALE,
            );
            $lockedLoan->save();

            return $accrual->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the loan is in a state that permits interest accrual.
     *
     * Eligible states: `disbursed`, `active`, `in_arrears`, `restructured`.
     * Every other status — including the terminal states and the pre-
     * disbursement states — cannot have interest accrued against it.
     *
     * The comparison is performed directly against the `LoanStatus` enum
     * cases. The `Loan` model casts `status` to the enum, so the attribute
     * access yields an enum instance and the `===` check is correct. See
     * the class docblock §"Status comparison — direct enum identity".
     *
     * @param Loan $loan
     * @return void
     *
     * @throws LogicException
     */
    private function assertLoanIsAccrualEligible(Loan $loan): void
    {
        $eligible = [
            LoanStatus::Disbursed,
            LoanStatus::Active,
            LoanStatus::InArrears,
            LoanStatus::Restructured,
        ];

        foreach ($eligible as $status) {
            if ($loan->status === $status) {
                return;
            }
        }

        throw new LogicException(
            sprintf(
                'LoanInterestAccrualService::accrue() called on a loan '
                . 'in status "%s", which is not accrual-eligible. The '
                . 'command should have filtered this loan out before '
                . 'calling the service.',
                $loan->status->value,
            )
        );
    }

    /**
     * Assert that the installment belongs to the given loan.
     *
     * A mismatch is a programming defect in the caller: the command
     * should never pass an installment that belongs to a different loan.
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
                    'LoanInterestAccrualService::accrue() called with '
                    . 'installment id=%d belonging to loan id=%d, but '
                    . 'invoked for loan id=%d. This is a caller defect.',
                    $installment->id,
                    $installment->loan_id,
                    $loan->id,
                )
            );
        }
    }

    /**
     * Assert that the installment is eligible for accrual.
     *
     * The installment must not be in a terminal state. `settled`
     * installments have already had their interest paid; `superseded`
     * installments belong to a prior schedule and are excluded.
     *
     * The comparison is performed directly against the
     * `InstallmentStatus` enum cases. The `LoanInstallment` model casts
     * `status` to the enum, so the attribute access yields an enum
     * instance and the `===` check is correct. See the class docblock
     * §"Status comparison — direct enum identity".
     *
     * @param LoanInstallment $installment
     * @return void
     *
     * @throws LogicException
     */
    private function assertInstallmentIsAccrualEligible(
        LoanInstallment $installment,
    ): void {
        if ($installment->status === InstallmentStatus::Settled) {
            throw new LogicException(
                sprintf(
                    'LoanInterestAccrualService::accrue() called on '
                    . 'installment id=%d, which is already settled. The '
                    . 'command should have filtered this installment out.',
                    $installment->id,
                )
            );
        }

        if ($installment->status === InstallmentStatus::Superseded) {
            throw new LogicException(
                sprintf(
                    'LoanInterestAccrualService::accrue() called on '
                    . 'installment id=%d, which was superseded by a '
                    . 'restructuring. The command should have filtered '
                    . 'this installment out.',
                    $installment->id,
                )
            );
        }
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================
    /**
     * Resolve the borrower's Wallet.
     *
     * A missing Wallet at accrual time is a data-integrity defect: the
     * disbursement service (A.30) requires a Wallet before transitioning
     * a loan to `active`. The check throws `LogicException` rather than
     * returning null, because a null return would silently drop the
     * accrual and leave the loan's schedule without a corresponding
     * obligation.
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
                    . 'an accrual-eligible state. This is a '
                    . 'data-integrity defect: disbursement requires a '
                    . 'Wallet.',
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
     * SQLSTATE 23000 covers all integrity-constraint violations on both
     * MySQL and SQLite. Because the only UNIQUE constraint this INSERT
     * can violate is `loan_interest_accruals_key_unique` (the FK targets
     * are validated to exist before the insert), the classification is
     * unambiguous.
     *
     * @param QueryException $e
     * @return bool
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}