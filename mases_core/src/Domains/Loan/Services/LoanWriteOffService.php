<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanWrittenOff;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanWriteOff;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;

/**
 * LoanWriteOffService
 *
 * Owns the WRITE-OFF operation of a loan's lifecycle: the lender's
 * decision to forgive the loan's outstanding balance, discharge every
 * outstanding Wallet obligation linked to the loan, zero the loan's
 * projections, and transition the loan to the terminal `written_off`
 * state.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   writeOff()  — forgive the loan's outstanding balance.
 *
 * The frozen state machine (A.2) permits three paths into `written_off`:
 *
 *     active       → written_off
 *     in_arrears   → written_off
 *     restructured → written_off
 *
 * The `restructured → written_off` edge is technically reachable but
 * rarely observed in practice, because the restructure service (A.34)
 * transitions the loan from `restructured` to `active` in the same
 * transaction that creates the restructure. A loan is observed in the
 * `restructured` state only inside a transaction that is about to
 * complete. The edge exists in the frozen matrix; this service accepts
 * it; the practical reality is that write-offs are performed on `active`
 * or `in_arrears` loans.
 *
 * =============================================================================
 * The reference-id ordering fix (Phase 2C Final Reconciliation §1.3)
 * =============================================================================
 * The same correction that was applied to the restructure flow (A.34) is
 * applied here. The previous delivery's write-off flow attempted to use
 * the `loan_write_offs` row's auto-generated id as the discharge's
 * `reference_id` before the row existed. The corrected ordering is:
 *
 *   1. Validate loan state and reason.
 *   2. Capture the loan's current outstanding projections.
 *   3. Compute the `write_off_amount` as the sum of the three.
 *   4. INSERT the `loan_write_offs` row → obtain `$writeOffId`.
 *   5. Discharge every outstanding Wallet obligation linked to the loan,
 *      using `(string) $writeOffId` as the entry's `reference_id`.
 *   6. Zero the loan's three outstanding projections.
 *   7. Transition the loan to `written_off`, dispatching
 *      `LoanWrittenOff` after commit.
 *   8. Return the refreshed loan.
 *
 * Steps 4 and 5 are the reference-id ordering fix. A rollback at any
 * step after step 4 discards the write-off row, the discharge entries,
 * and the projection updates together.
 *
 * =============================================================================
 * Discharging Wallet obligations (CR-2C-WALLET-OBLIGATION-LIFECYCLE-001)
 * =============================================================================
 * Step 5 calls `ObligationService::discharge()` — the same method used
 * by the restructure service (A.34). The method:
 *
 *   - reduces the obligation's `outstanding_amount` to zero;
 *   - writes a `wallet_entries` row of type `ObligationWrittenOff` with a
 *     POSITIVE signed quantity equal to the discharged amount (crediting
 *     the member's position);
 *   - PRESERVES any existing payment allocations against the obligation
 *     (they are historical facts and are never mutated);
 *   - fires the Wallet's `ObligationWrittenOff` event after commit.
 *
 * The discharge entry's `reference_type = 'LoanWriteOff'` and
 * `reference_id = (string) $writeOffId` point back at the write-off row
 * that caused the discharge. The Wallet's
 * `assertReferenceTypePermitted()` accepts this pairing only after
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 (and the companion
 * CR-2C-WALLET-REFTYPE-001) land.
 *
 * The distinction between `ObligationCancelled` (used by restructure)
 * and `ObligationWrittenOff` (used here) is deliberate and semantically
 * meaningful: a cancellation says "these terms no longer apply; new
 * terms replace them"; a write-off says "this debt is forgiven". The two
 * are recorded as different Wallet entry types so that the Wallet's
 * audit trail preserves the distinction. See D133's "write-off does not
 * silently erase the historical arrears ledger" clause.
 *
 * =============================================================================
 * Enumerating the loan-linked obligations
 * =============================================================================
 * Step 5 enumerates every outstanding obligation linked to the loan,
 * across all three components:
 *
 *   - the single PRINCIPAL obligation, recorded at disbursement time
 *     (A.30);
 *   - the per-installment INTEREST obligations, recorded by the interest
 *     accrual service (A.32);
 *   - the per-installment PENALTY obligations, recorded by the arrears
 *     service (A.33).
 *
 * The enumeration filters `wallet_obligations` by:
 *
 *     wallet_id              = <the borrower's wallet id>
 *     reference_type         = 'Loan'
 *     reference_id           = (string) <the loan id>
 *     outstanding_amount     > 0
 *
 * The same direct-model query pattern used by `LoanRestructuringService`
 * (A.34) and `LoanRepaymentService` (A.31) is followed.
 *
 * =============================================================================
 * The write-off amount
 * =============================================================================
 * The migration A.13 declares `write_off_amount` as the total amount
 * forgiven, equal to the sum of the three `*_at_writeoff` columns at the
 * moment of write-off:
 *
 *     write_off_amount
 *       == outstanding_principal_at_writeoff
 *        + outstanding_interest_at_writeoff
 *        + outstanding_penalty_at_writeoff
 *
 * The three `*_at_writeoff` columns preserve the historical values
 * because, after the write-off completes, the loan's projections are
 * zeroed (step 6) and the values would otherwise be lost. The row is
 * self-contained for audit: a reader of `loan_write_offs` alone can
 * reconstruct what the borrower owed at the moment of forgiveness.
 *
 * The service computes the four values from the SAME source — the loan's
 * three projections read at step 2 — so the invariant
 * `write_off_amount == sum(three)` holds by construction.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry
 * point for its operation (the controller calls `writeOff()` directly).
 *
 * The transaction wraps the write-off row insert, the Wallet discharges,
 * the loan's projection resets, and the terminal status transition — all
 * as one atomic unit. There is no possibility of a partially-written-off
 * loan.
 *
 * =============================================================================
 * Lock ordering — Wallet → Loan
 * =============================================================================
 * The frozen B2 hybrid-ledger lock order is `Wallet → Payment →
 * Obligation → PaymentAllocation → Loan`. This service acquires locks in
 * exactly that order (see A.30 §"Lock ordering" for the full rationale).
 *
 * The loan is read WITHOUT a lock at the start. All loan-side writes
 * (projection reset, status transition) occur AFTER the Wallet lock is
 * acquired by the first discharge.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not write off a loan in any status other than `active`,
 *     `in_arrears`, or `restructured`. See §"Scope".
 *
 *   - It does not create new Wallet obligations. A write-off forgives
 *     existing obligations; it does not create new ones.
 *
 *   - It does not reverse the write-off. The write-off row is immutable
 *     (see A.22, §"Immutability"), and the discharges are permanent
 *     Wallet entries. A future CR that permits unwinding a write-off
 *     would introduce a dedicated reversal mechanism.
 *
 *   - It does not partially write off a loan. The frozen write-off flow
 *     forgives the entire outstanding balance. A future CR that
 *     introduces partial write-offs would extend this service, the
 *     migration's UNIQUE constraint on `loan_id` (currently absent), and
 *     the state machine's terminal-state handling.
 *
 *   - It does not delete the loan's schedule, installments, accruals, or
 *     status history. All historical records remain for audit. The
 *     schedule's installments remain with their pre-write-off statuses
 *     (they are not superseded; they simply no longer have corresponding
 *     obligations).
 *
 *   - It does not dispatch any event besides `LoanWrittenOff`. The
 *     Wallet's `ObligationWrittenOff` event is dispatched by
 *     `ObligationService::discharge()`.
 *
 *   - It does not enforce a write-off approval threshold (e.g. "a
 *     write-off above X requires supervisor authorization"). The register
 *     does not freeze such a threshold; adding one is a future CR.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — D130, A10 (no automatic
 *                                                  enforcement; write-off
 *                                                  is the accounting-side
 *                                                  terminal action), B1, B2
 *   Phase 2C Clarification Response, 2026-09-12  — C4 (state freeze),
 *                                                  C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.3 (reference-id
 *                                                  ordering), §3 (the
 *                                                  invariant that a
 *                                                  write-off preserves)
 *   Phase 2C A.2                                 — `LoanStatus`
 *   Phase 2C A.13                                — `loan_write_offs`
 *                                                  migration (the sum
 *                                                  invariant)
 *   Phase 2C A.22                                — `LoanWriteOff` (the
 *                                                  model)
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.34                                — `LoanRestructuringService`
 *                                                  (the structural
 *                                                  sibling)
 *   Phase 2B `ObligationService`                 — the Wallet write path
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001        — adds `discharge()`
 *   Step 5 (manifest) A.35                       — This file
 *   Step 6 v1.1.0 §3.13                          — `loan_write_offs`
 *                                                  table specification
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.0
 */
final class LoanWriteOffService
{
    /**
     * The `reference_type` value written to the discharge entries in the
     * Wallet.
     *
     * Frozen by CR-2C-WALLET-OBLIGATION-LIFECYCLE-001.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN_WRITE_OFF = 'LoanWriteOff';

    /**
     * The `reference_type` value on loan-linked Wallet obligations.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * Monetary scale.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * Constructor.
     *
     * Three dependencies:
     *
     *   - `LoanStateMachine` — for the terminal status transition.
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution.
     *
     *   - `ObligationService` — for the discharge calls.
     *
     * @param LoanStateMachine  $stateMachine
     * @param WalletRepository  $walletRepository
     * @param ObligationService $obligationService
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Write off a loan.
     *
     * See the class docblock for the frozen sequence of operations.
     *
     * @param Loan        $loan
     * @param string      $reason       Required, non-empty justification.
     * @param Carbon|null $asOf         The write-off moment; defaults to
     *                                   now.
     * @param int|null    $actorUserId
     * @return Loan  The refreshed loan in `written_off` state.
     *
     * @throws ValidationException
     * @throws LogicException
     */
    public function writeOff(
        Loan $loan,
        string $reason,
        ?Carbon $asOf = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use (
            $loan,
            $reason,
            $asOf,
            $actorUserId,
        ): Loan {
            // -----------------------------------------------------------------
            // 1. Validate the reason.
            // -----------------------------------------------------------------
            $this->assertReasonIsProvided($reason);

            // -----------------------------------------------------------------
            // 2. Read the loan (no lock; see the class docblock
            //    §"Lock ordering").
            // -----------------------------------------------------------------
            /** @var Loan $current */
            $current = Loan::query()
                ->whereKey($loan->id)
                ->firstOrFail();

            // -----------------------------------------------------------------
            // 3. Assert the loan is in a write-off-eligible state.
            // -----------------------------------------------------------------
            $this->assertLoanIsWriteOffEligible($current);

            // -----------------------------------------------------------------
            // 4. Load the borrower and resolve the Wallet.
            // -----------------------------------------------------------------
            $current->loadMissing('borrower');

            $borrower = $current->borrower;

            if ($borrower === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no borrower. Data-integrity defect.',
                        $current->id,
                    )
                );
            }

            $wallet = $this->walletRepository->findForShareholder(
                (int) $current->tenant_id,
                (int) $borrower->id,
            );

            if ($wallet === null) {
                throw new LogicException(
                    sprintf(
                        'Borrower id=%d has no Wallet, yet loan id=%d is '
                        . 'in a write-off-eligible state. Data-integrity '
                        . 'defect.',
                        $borrower->id,
                        $current->id,
                    )
                );
            }

            // -----------------------------------------------------------------
            // 5. Capture the loan's current outstanding projections and
            //    compute the write-off amount.
            //
            // The three `*_at_writeoff` columns and the `write_off_amount`
            // column are all derived from the SAME three projection reads,
            // so the invariant
            //     write_off_amount == sum(three)
            // holds by construction.
            // -----------------------------------------------------------------
            $outstandingPrincipal = (string) ($current->outstanding_principal ?? '0');
            $outstandingInterest  = (string) ($current->outstanding_interest  ?? '0');
            $outstandingPenalty   = (string) $current->outstanding_penalty;

            $writeOffAmount = bcadd(
                bcadd($outstandingPrincipal, $outstandingInterest, self::FINAL_SCALE),
                $outstandingPenalty,
                self::FINAL_SCALE,
            );

            $moment = $asOf ?? Carbon::now();

            // -----------------------------------------------------------------
            // 6. INSERT the write-off row.
            //
            // The insert produces the `$writeOffId` used by every discharge
            // in step 7.
            // -----------------------------------------------------------------
            /** @var LoanWriteOff $writeOff */
            $writeOff = LoanWriteOff::create([
                'loan_id'                            => $current->id,
                'write_off_amount'                   => $writeOffAmount,
                'outstanding_principal_at_writeoff'  => $outstandingPrincipal,
                'outstanding_interest_at_writeoff'   => $outstandingInterest,
                'outstanding_penalty_at_writeoff'    => $outstandingPenalty,
                'reason'                             => $reason,
                'written_off_at'                     => $moment,
                'written_off_by'                     => $actorUserId,
            ]);

            $writeOffId = (int) $writeOff->getKey();

            // -----------------------------------------------------------------
            // 7. Discharge every outstanding Wallet obligation linked to
            //    the loan.
            //
            // The discharge uses `(string) $writeOffId` as the entry's
            // reference_id. See the class docblock §"Discharging Wallet
            // obligations".
            // -----------------------------------------------------------------
            $this->dischargeLoanObligations(
                wallet:       $wallet,
                loan:         $current,
                writeOffId:   $writeOffId,
                reason:       $reason,
                actorUserId:  $actorUserId,
            );

            // -----------------------------------------------------------------
            // 8. Zero the loan's three outstanding projections.
            //
            // After the discharges, every loan-linked obligation has zero
            // outstanding. The loan's projections are set to zero
            // explicitly, matching the post-discharge Wallet state.
            // -----------------------------------------------------------------
            $current->outstanding_principal = '0.0000';
            $current->outstanding_interest  = '0.0000';
            $current->outstanding_penalty   = '0.0000';
            $current->save();

            // -----------------------------------------------------------------
            // 9. Transition the loan to `written_off`, dispatching
            //    `LoanWrittenOff` after commit.
            // -----------------------------------------------------------------
            $event = new LoanWrittenOff(
                $current,
                $writeOff,
                $actorUserId,
            );

            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::WrittenOff,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            $current->refresh();

            return $current;
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the reason is non-empty.
     *
     * @param string $reason
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReasonIsProvided(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => [
                    'A write-off reason is required. Supply a non-empty '
                    . 'explanation of why the loan is being written off.',
                ],
            ]);
        }
    }

    /**
     * Assert that the loan is in a write-off-eligible state.
     *
     * Eligible states: `active`, `in_arrears`, `restructured`. See the
     * class docblock §"Scope".
     *
     * @param Loan $loan
     * @return void
     *
     * @throws ValidationException
     */
    private function assertLoanIsWriteOffEligible(Loan $loan): void
    {
        $eligible = [
            LoanStatus::Active,
            LoanStatus::InArrears,
            LoanStatus::Restructured,
        ];

        foreach ($eligible as $status) {
            if ($loan->status === $status) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A loan in status "%s" cannot be written off. The '
                    . 'frozen transition matrix permits only "active → '
                    . 'written_off", "in_arrears → written_off", and '
                    . '"restructured → written_off".',
                    $loan->status->value,
                ),
            ],
        ]);
    }

    // =========================================================================
    // Private — discharge
    // =========================================================================
    /**
     * Discharge every outstanding Wallet obligation linked to the loan.
     *
     * See the class docblock §"Discharging Wallet obligations".
     *
     * @param Wallet $wallet
     * @param Loan     $loan
     * @param int      $writeOffId
     * @param string   $reason
     * @param int|null $actorUserId
     * @return void
     *
     * @throws LogicException
     */
    private function dischargeLoanObligations(
        Wallet $wallet,
        Loan $loan,
        int $writeOffId,
        string $reason,
        ?int $actorUserId,
    ): void {
        /** @var Collection<int, Obligation> $obligations */
        $obligations = Obligation::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN)
            ->where('reference_id', (string) $loan->id)
            ->where('outstanding_amount', '>', 0)
            ->orderBy('id')
            ->get();

        foreach ($obligations as $obligation) {
            $this->obligationService->discharge(
                wallet: $wallet,
                obligation: $obligation,
                entryType: WalletEntryType::ObligationWrittenOff,
                reason: $reason,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN_WRITE_OFF,
                referenceId: (string) $writeOffId,
                createdBy: $actorUserId,
            );
        }
    }
}