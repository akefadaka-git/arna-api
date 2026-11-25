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
 * Normalize enum-backed loan and installment states before accrual checks.
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