<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanInstallmentSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRepaymentRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanSettled;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\ObligationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Services\AllocationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;

/**
 * LoanRepaymentService
 *
 * Owns the REPAYMENT phase of a loan's lifecycle: recording a payment
 * received from the borrower, attributing it to specific loan-linked
 * Wallet obligations, updating the loan's persisted projections, and —
 * when the loan's principal and every installment's interest are fully
 * settled — transitioning the loan to `settled`.
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * This service owns exactly ONE operation:
 *
 *   record()  — record a repayment, apply it to specified obligations,
 *               and update the loan's projections and lifecycle.
 *
 * Everything before repayment — draft, submission, review, decision,
 * disbursement — belongs to `LoanApplicationService` (A.28),
 * `LoanApprovalService` (A.29), and `LoanDisbursementService` (A.30).
 * Everything after — interest accrual, penalty accrual, restructure,
 * write-off, closure — belongs to its own service, EXCEPT the automatic
 * settlement transition, which this service performs.
 *
 * =============================================================================
 * Allocation policy — the caller supplies the allocation instructions
 * =============================================================================
 * The D110 freeze (Phase 2B) declines to encode a universal payment-
 * allocation priority. The Wallet's `AllocationService::allocate()`
 * (2B.18) is explicit: each call applies a specific payment amount to a
 * specific obligation. There is no automatic waterfall.
 *
 * This service honors that freeze by REQUIRING the caller to supply an
 * ordered list of allocation instructions, each naming a specific
 * obligation and an amount. The service validates the sum of the
 * instructions against the repayment amount, records the Wallet payment,
 * and applies each allocation in order.
 *
 * =============================================================================
 * Installment settlement — the frozen interpretation (A)
 * =============================================================================
 * Phase 2C records THREE kinds of loan-linked Wallet obligations:
 *
 *   - ONE principal obligation for the WHOLE loan, recorded at
 *     disbursement time by `LoanDisbursementService` (A.30).
 *
 *   - ONE interest obligation PER INSTALLMENT, recorded at each
 *     installment's due date by `LoanInterestAccrualService` (A.32).
 *
 *   - ZERO OR ONE penalty obligation PER INSTALLMENT, recorded when an
 *     installment crosses its grace threshold, by `LoanArrearsService`
 *     (A.33).
 *
 * There is NO per-installment principal obligation. The principal is a
 * single aggregate covering the whole loan.
 *
 * All three obligation kinds share the same loan-scoped reference pair:
 * `reference_type = 'Loan'` and `reference_id = (string) $loan->id`.
 * The Wallet obligation table does NOT carry a direct FK to
 * `loan_installments`; the association between an interest or penalty
 * obligation and the installment it belongs to is expressed ONLY
 * through the corresponding `loan_interest_accruals` or
 * `loan_penalty_accruals` row, whose `wallet_obligation_id` column
 * points back at the obligation.
 *
 * The settlement check reads the loan's aggregate obligation state,
 * which the `recomputeOutstandingProjections()` step has just refreshed
 * from the authoritative `wallet_obligations` table. Two interpretations
 * were considered:
 *
 *   (A) LOAN-LEVEL SETTLEMENT DRIVES INSTALLMENT SETTLEMENT.
 *
 *       An installment is considered `settled` when:
 *         · the loan has no outstanding interest obligation, AND
 *         · the loan has no outstanding penalty obligation.
 *
 *       The loan itself is considered `settled` when, in addition:
 *         · the loan's aggregate `outstanding_principal` has reached
 *           zero, AND
 *         · every non-superseded installment is `settled`.
 *
 *       Consequence: on the repayment that clears the loan's last
 *       outstanding interest (or penalty) obligation, ALL unsettled
 *       installments transition to `settled` simultaneously, including
 *       installments whose due dates lie in the future. The per-
 *       installment status history is consequently less granular than a
 *       per-installment-principal-obligation model would produce.
 *
 *       This is the interpretation adopted by Phase 2C. It is the only
 *       one consistent with the frozen data model, in which interest
 *       and penalty obligations are scoped to the loan — not to the
 *       installment — and are linked back to their installment only
 *       through the accrual tables.
 *
 *   (B) PER-INSTALLMENT PRINCIPAL OBLIGATIONS.
 *
 *       The schedule service would ALSO record a per-installment
 *       principal obligation, so that each installment's principal,
 *       interest, and penalty are separately tracked under an
 *       installment-scoped reference pair. This contradicts the frozen
 *       disbursement flow (A.30 records exactly one principal
 *       obligation) and would require regenerating A.27 and A.30.
 *
 * **Phase 2C adopts interpretation (A).**
 *
 * =============================================================================
 * The frozen repayment flow
 * =============================================================================
 * The operation performs the following steps, in this order, inside ONE
 * transaction:
 *
 *   1. Read the loan (no lock — the Wallet is locked first, per the
 *      frozen `Wallet → Loan` lock order; see A.30 §"Lock ordering").
 *   2. Assert the loan's status is `disbursed`, `active`, `in_arrears`,
 *      or `restructured`. Any other status is a `ValidationException`.
 *   3. Resolve the borrower's Wallet.
 *   4. Assert that the Wallet's currency matches the loan's currency
 *      (Q8).
 *   5. Validate the allocation instructions (well-formed, sums match,
 *      every obligation belongs to this loan).
 *   6. Record the Wallet payment via `PaymentService::record()`. The
 *      Wallet writes a `payment_received` ledger entry with a positive
 *      signed quantity equal to the amount, and increases
 *      `current_balance`.
 *   7. For each allocation instruction, call
 *      `AllocationService::allocate()`. Each call creates a
 *      `wallet_payment_allocations` row and decrements the obligation's
 *      `outstanding_amount`.
 *   8. Update the loan's `settled_amount` by the total repayment amount.
 *   9. Recompute the loan's three outstanding projections from the
 *      current state of the loan-linked Wallet obligations.
 *  10. Settle all unsettled installments if the loan's aggregate
 *      interest and penalty obligations are both zero. Collect
 *      `LoanInstallmentSettled` events for after-commit dispatch.
 *  11. Detect and record loan settlement if the aggregate principal is
 *      also zero and every installment is settled, dispatching
 *      `LoanSettled` after commit.
 *  12. Register the `LoanRepaymentRecorded` event (A.37) for
 *      after-commit dispatch.
 *  13. Return the refreshed loan.
 *
 * If any step after step 6 fails, the entire transaction rolls back.
 *
 * =============================================================================
 * Arrears recovery — a frozen-matrix limitation
 * =============================================================================
 * The frozen state machine (A.2) allows `in_arrears → settled`,
 * `in_arrears → written_off`, and `in_arrears → restructured`, but NOT
 * `in_arrears → active`. A recovered loan that is not yet fully settled
 * therefore has no path out of `in_arrears` in the current matrix.
 *
 * Consequence: **Phase 2C does NOT automatically transition a loan out
 * of `in_arrears` upon full recovery of all overdue installments.** The
 * loan remains in `in_arrears` until either (a) all installments are
 * settled (transition to `settled`) or (b) an operator restructures the
 * loan.
 *
 * A future Change Request that introduces an `in_arrears → active`
 * recovery edge would update `LoanStatus::canTransitionTo()` and this
 * service's recovery logic. The CR's scope is not Phase 2C.
 *
 * =============================================================================
 * Projection recomputation
 * =============================================================================
 * The service recomputes the loan's three outstanding projections
 * (`outstanding_principal`, `outstanding_interest`, `outstanding_penalty`)
 * from the current state of the loan-linked Wallet obligations. See
 * the class docblock of `LoanDisbursementService` (A.30) for the
 * related invariant.
 *
 * The recomputation reads the Wallet's `wallet_obligations` table,
 * grouped by component:
 *
 *     outstanding_principal = Σ(obligations WHERE component='principal'
 *                                              AND reference_type='Loan'
 *                                              AND reference_id=$loanId)
 *                                              .outstanding_amount
 *     outstanding_interest  = Σ(obligations WHERE component='interest'
 *                                              AND ...)
 *                                              .outstanding_amount
 *     outstanding_penalty   = Σ(obligations WHERE component='penalty'
 *                                              AND ...)
 *                                              .outstanding_amount
 *
 * This is the ONLY place in the Loan domain where the loan's projections
 * are RECOMPUTED rather than incrementally adjusted. The recomputation
 * is necessary because a single repayment may partially settle a
 * principal obligation, an interest obligation, and a penalty
 * obligation in the same transaction.
 *
 * =============================================================================
 * Transaction discipline
 * =============================================================================
 * This service opens its OWN transaction. It is the TOP-LEVEL entry
 * point for its operation (the controller calls `record()` directly).
 *
 * The nested savepoints inside the Wallet services do not affect the
 * outer transaction's atomicity. If any step fails, the outer
 * transaction rolls back the entire operation.
 *
 * =============================================================================
 * Lock ordering
 * =============================================================================
 * The frozen lock order is `Wallet → Payment → Obligation →
 * PaymentAllocation → Loan`. This service acquires locks in exactly that
 * order (see A.30 for the full rationale). The loan is read WITHOUT a
 * lock at step 1.
 *
 * =============================================================================
 * Wallet integration — the required Change Request
 * =============================================================================
 * This service passes `reference_type = 'LoanRepayment'` to
 * `PaymentService::record()`. The Wallet's
 * `assertReferenceTypePermitted()` (Phase 2B) accepts this value only
 * after CR-2C-WALLET-REFTYPE-001 lands. Without the CR, the Wallet
 * throws `ValidationException` on the payment write.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not compute an allocation priority. See §"Allocation
 *     policy".
 *
 *   - It does not accrue interest or penalties. Those are the concerns
 *     of `LoanInterestAccrualService` (A.32) and `LoanArrearsService`
 *     (A.33), which run as scheduled commands.
 *
 *   - It does not record per-installment principal obligations. See
 *     §"Installment settlement — the frozen interpretation (A)".
 *
 *   - It does not recover a loan from `in_arrears` state. See §"Arrears
 *     recovery".
 *
 *   - It does not reverse an allocation. Reversals use the Wallet's
 *     `AllocationService::reverse()` (2B.18); a Loan-domain operation
 *     that reverses a repayment would need to be modelled explicitly,
 *     and Phase 2C does not include one.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D133, B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — §1.2, §1.3, §3
 *   Phase 2C A.26                                — `LoanStateMachine`
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *   Phase 2B 2B.17                               — `PaymentService`
 *   Phase 2B 2B.18                               — `AllocationService`
 *   CR-2C-WALLET-REFTYPE-001                     — Wallet ref-type map
 *   Step 5 (manifest) A.31                       — This file
 *   Step 6 v1.1.0 §3.9                           — `loans` table
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
 * @version 1.0.0
 */
final class LoanRepaymentService
{
    /**
     * The `reference_type` value this service writes to the Wallet for
     * the repayment payment.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN_REPAYMENT = 'LoanRepayment';

    /**
     * The `reference_type` value on loan-linked Wallet obligations.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The scale at which monetary comparisons are performed.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * Constructor.
     *
     * @param LoanStateMachine     $stateMachine
     * @param WalletRepository     $walletRepository
     * @param ObligationRepository $obligationRepository
     * @param PaymentService       $paymentService
     * @param AllocationService    $allocationService
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly WalletRepository $walletRepository,
        private readonly ObligationRepository $obligationRepository,
        private readonly PaymentService $paymentService,
        private readonly AllocationService $allocationService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================
    /**
     * Record a repayment against a loan.
     *
     * See the class docblock §"The frozen repayment flow" for the full
     * sequence.
     *
     * The `$allocations` parameter is an ordered list of allocation
     * instructions, each of the shape:
     *
     *     [
     *         'obligation_id' => int,
     *         'amount'        => string,  // decimal string, at scale 4
     *     ]
     *
     * The sum of the instruction amounts MUST equal `$amount`.
     *
     * @param Loan                                       $loan
     * @param string                                     $amount
     * @param array<int, array{obligation_id: int, amount: string}> $allocations
     * @param Carbon|null                                $receivedAt
     * @param string|null                                $referenceType
     * @param string|null                                $referenceId
     * @param string|null                                $description
     * @param int|null                                   $actorUserId
     * @return Loan
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function record(
        Loan $loan,
        string $amount,
        array $allocations,
        string $idempotencyKey,
        ?Carbon $receivedAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?int $actorUserId = null,
    ): Loan {
        $requestHash = $this->hashRepaymentRequest(
            $amount,
            $allocations,
            $receivedAt,
            $referenceType,
            $referenceId,
            $description,
        );

        try {
            return DB::transaction(function () use (
            $loan,
            $amount,
            $allocations,
            $idempotencyKey,
            $requestHash,
            $receivedAt,
            $referenceType,
            $referenceId,
            $description,
            $actorUserId,
        ): Loan {
            // -----------------------------------------------------------------
            // 1. Read the loan (no lock).
            // -----------------------------------------------------------------
            /** @var Loan $current */
            $current = Loan::query()
                ->whereKey($loan->id)
                ->firstOrFail();

            // The unique tenant/loan/key constraint arbitrates concurrent retries.
            // This reservation is in the same transaction as every financial write.
            DB::table('loan_repayment_idempotencies')->insert([
                'tenant_id'       => (int) $current->tenant_id,
                'loan_id'         => (int) $current->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash'    => $requestHash,
                'created_at'      => Carbon::now(),
            ]);

            // -----------------------------------------------------------------
            // 2. Assert the loan is in a repayable state.
            // -----------------------------------------------------------------
            $this->assertRepayableStatus($current);

            // -----------------------------------------------------------------
            // 3. Resolve the borrower and Wallet.
            // -----------------------------------------------------------------
            $current->loadMissing('borrower');

            $borrower = $current->borrower;

            if ($borrower === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no borrower. Data-integrity defect.',
                        $current->id,
                    ),
                );
            }

            $wallet = $this->walletRepository->findForShareholder(
                (int) $current->tenant_id,
                (int) $borrower->id,
            );

            if ($wallet === null) {
                throw new LogicException(
                    sprintf(
                        'Borrower id=%d has no Wallet yet loan id=%d is '
                        . 'in a repayable state. Data-integrity defect.',
                        $borrower->id,
                        $current->id,
                    ),
                );
            }

            // -----------------------------------------------------------------
            // 4. Assert currency equality (Q8).
            // -----------------------------------------------------------------
            if ($current->currency === null) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d has no currency yet is repayable. '
                        . 'Data-integrity defect.',
                        $current->id,
                    ),
                );
            }

            if ($current->currency !== $wallet->currency) {
                throw new LogicException(
                    sprintf(
                        'Loan id=%d is denominated in %s but the Wallet '
                        . 'is in %s. Q8 invariant violated.',
                        $current->id,
                        $current->currency,
                        $wallet->currency,
                    ),
                );
            }

            // -----------------------------------------------------------------
            // 5. Validate the allocation instructions.
            // -----------------------------------------------------------------
            $this->assertAmountIsWellFormed($amount);
            $this->assertAllocationsWellFormed($current, $wallet, $amount, $allocations);

            $now = $receivedAt ?? Carbon::now();

            // -----------------------------------------------------------------
            // 6. Record the Wallet payment.
            // -----------------------------------------------------------------
            $payment = $this->paymentService->record(
                wallet: $wallet,
                amount: $amount,
                receivedAt: $now,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT,
                referenceId: (string) $current->id,
                description: $description,
                createdBy: $actorUserId,
            );

            // -----------------------------------------------------------------
            // 7. Apply each allocation instruction.
            // -----------------------------------------------------------------
            /** @var list<PaymentAllocation> $allocationRecords */
            $allocationRecords = [];

            foreach ($allocations as $instruction) {
                $obligation = $this->resolveObligation(
                    (int) $wallet->id,
                    (int) $instruction['obligation_id'],
                );

                $allocationRecords[] = $this->allocationService->allocate(
                    wallet: $wallet,
                    paymentId: (int) $payment->id,
                    obligationId: (int) $obligation->id,
                    amount: (string) $instruction['amount'],
                    createdBy: $actorUserId,
                    policyVersionId: null,
                );
            }

            // Persist the payment link before commit; the idempotency row and
            // financial writes commit or roll back as one unit.
            DB::table('loan_repayment_idempotencies')
                ->where('tenant_id', (int) $current->tenant_id)
                ->where('loan_id', (int) $current->id)
                ->where('idempotency_key', $idempotencyKey)
                ->update(['payment_id' => (int) $payment->id]);

            // -----------------------------------------------------------------
            // 8. Acquire the loan lock only after Wallet -> Payment ->
            //    Obligation -> PaymentAllocation locks have been acquired.
            //    Re-read under lock so settled_amount is never derived from
            //    a stale snapshot. Re-check status after waiting for the lock.
            // -----------------------------------------------------------------
            $current = Loan::query()
                ->whereKey($current->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertRepayableStatus($current);

            $current->settled_amount = bcadd(
                (string) $current->settled_amount,
                $amount,
                self::FINAL_SCALE,
            );
            $current->save();

            // -----------------------------------------------------------------
            // 9. Recompute the loan's outstanding projections from the
            //    authoritative Wallet obligation state. This runs BEFORE
            //    the settlement detection so that the loan's
            //    `outstanding_*` fields reflect the current allocation.
            // -----------------------------------------------------------------
            $this->recomputeOutstandingProjections($current, (int) $wallet->id);

            // -----------------------------------------------------------------
            // 10. Settle all unsettled installments if the loan's
            //     aggregate interest and penalty obligations are both
            //     zero. Collect the `LoanInstallmentSettled` events for
            //     after-commit dispatch.
            // -----------------------------------------------------------------
            $settledInstallmentEvents = $this->settleAffectedInstallments(
                $current,
                $actorUserId,
            );

            // -----------------------------------------------------------------
            // 11. Detect loan-level settlement: aggregate principal is
            //     zero AND every non-superseded installment is settled.
            //     The transition dispatches `LoanSettled` after commit.
            // -----------------------------------------------------------------
            $current = $this->maybeSettleLoan($current, $actorUserId);

            // -----------------------------------------------------------------
            // 12. Register the `LoanRepaymentRecorded` event for
            //     after-commit dispatch.
            // -----------------------------------------------------------------
            $this->registerAfterCommitEvent(
                new LoanRepaymentRecorded(
                    $current,
                    $payment,
                    $allocationRecords,
                    $amount,
                ),
            );

            // -----------------------------------------------------------------
            // 13. Register the collected installment-settled events.
            // -----------------------------------------------------------------
            foreach ($settledInstallmentEvents as $event) {
                $this->registerAfterCommitEvent($event);
            }

            $current->refresh();

            return $current;
            });
        } catch (QueryException $exception) {
            // A duplicate unique key means another request with this key committed
            // (the database waits for that transaction before raising the conflict).
            $existing = DB::table('loan_repayment_idempotencies')
                ->where('tenant_id', (int) $loan->tenant_id)
                ->where('loan_id', (int) $loan->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new ConflictHttpException(
                    'This Idempotency-Key was already used with a different repayment payload.',
                    $exception,
                );
            }

            return Loan::query()->whereKey($loan->id)->firstOrFail();
        }
    }

    /**
     * Hash the normalized business payload. Allocation order is preserved because
     * it determines the ordered allocation records and repayment event payload.
     */
    private function hashRepaymentRequest(
        string $amount,
        array $allocations,
        ?Carbon $receivedAt,
        ?string $referenceType,
        ?string $referenceId,
        ?string $description,
    ): string {
        return hash('sha256', json_encode([
            'amount' => trim($amount),
            'allocations' => array_values($allocations),
            'received_at' => $receivedAt?->toIso8601String(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ], JSON_THROW_ON_ERROR));
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the loan is in a repayable state.
     *
     * @param Loan $loan
     * @return void
     *
     * @throws ValidationException
     */
    private function assertRepayableStatus(Loan $loan): void
    {
        $repayable = [
            LoanStatus::Disbursed,
            LoanStatus::Active,
            LoanStatus::InArrears,
            LoanStatus::Restructured,
        ];

        foreach ($repayable as $status) {
            if ($loan->status === $status) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A loan in status "%s" cannot receive a repayment. '
                    . 'Repayments are accepted only for loans in '
                    . '"disbursed", "active", "in_arrears", or '
                    . '"restructured" state.',
                    $loan->status->value,
                ),
            ],
        ]);
    }

    /**
     * Assert that the amount is a well-formed positive decimal string.
     *
     * @param string $amount
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAmountIsWellFormed(string $amount): void
    {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The repayment amount must be a positive decimal '
                    . 'string with at most four decimal places.',
                ],
            ]);
        }

        if (bccomp($amount, '0', self::FINAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The repayment amount must be strictly greater than '
                    . 'zero.',
                ],
            ]);
        }
    }

    /**
     * Assert that the allocation instructions are well-formed.
     *
     * @param Loan                                       $loan
     * @param Wallet                                     $wallet
     * @param string                                     $amount
     * @param array<int, array{obligation_id: int, amount: string}> $allocations
     * @return void
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    private function assertAllocationsWellFormed(
        Loan $loan,
        Wallet $wallet,
        string $amount,
        array $allocations,
    ): void {
        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => [
                    'At least one allocation instruction is required. '
                    . 'A repayment must be attributed to one or more '
                    . 'loan-linked obligations explicitly; the domain '
                    . 'does not enforce a default allocation priority '
                    . '(D110).',
                ],
            ]);
        }

        $sum = '0.0000';

        foreach ($allocations as $index => $instruction) {
            $obligationId      = (int) ($instruction['obligation_id'] ?? 0);
            $instructionAmount = (string) ($instruction['amount'] ?? '');

            if (!preg_match('/^\d+(\.\d{1,4})?$/', $instructionAmount)) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => [
                        'Each allocation amount must be a positive '
                        . 'decimal string with at most four decimal '
                        . 'places.',
                    ],
                ]);
            }

            if (bccomp($instructionAmount, '0', self::FINAL_SCALE) <= 0) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.amount" => [
                        'Each allocation amount must be strictly '
                        . 'greater than zero.',
                    ],
                ]);
            }

            $obligation = $this->resolveObligation((int) $wallet->id, $obligationId);

            if (
                (string) $obligation->reference_type !== self::WALLET_REFERENCE_TYPE_LOAN
                || (string) $obligation->reference_id !== (string) $loan->id
            ) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.obligation_id" => [
                        sprintf(
                            'Obligation id=%d does not belong to loan '
                            . 'id=%d. Every allocation instruction must '
                            . 'name an obligation whose reference_type '
                            . 'is "%s" and whose reference_id is the '
                            . 'loan\'s id.',
                            $obligationId,
                            $loan->id,
                            self::WALLET_REFERENCE_TYPE_LOAN,
                        ),
                    ],
                ]);
            }

            $sum = bcadd($sum, $instructionAmount, self::FINAL_SCALE);
        }

        if (bccomp($sum, $amount, self::FINAL_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'allocations' => [
                    sprintf(
                        'The sum of allocation amounts (%s) must equal '
                        . 'the repayment amount (%s).',
                        $sum,
                        $amount,
                    ),
                ],
            ]);
        }
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================
    /**
     * Resolve a Wallet obligation by id, scoped to the Wallet.
     *
     * @param int $walletId
     * @param int $obligationId
     * @return Obligation
     *
     * @throws ModelNotFoundException
     */
    private function resolveObligation(int $walletId, int $obligationId): Obligation
    {
        $obligation = $this->obligationRepository->findForWallet(
            $walletId,
            $obligationId,
        );

        if ($obligation === null) {
            throw (new ModelNotFoundException())
                ->setModel(Obligation::class, [$obligationId]);
        }

        return $obligation;
    }

    // =========================================================================
    // Private — installment settlement (interpretation A)
    // =========================================================================

    /**
     * Settle all unsettled installments when the loan's aggregate
     * interest and penalty obligations are both zero.
     *
     * Under interpretation (A) (see the class docblock §"Installment
     * settlement — the frozen interpretation (A)"), the frozen data
     * model links interest and penalty obligations to their installment
     * only through the accrual tables. The repayment transaction has
     * already applied its allocations and recomputed the loan's
     * projections, so the loan's `outstanding_interest` and
     * `outstanding_penalty` columns are the authoritative aggregate
     * state of those obligations.
     *
     * When both aggregates are zero, every non-superseded installment
     * that has not yet reached `Settled` transitions to `Settled` in
     * this step. This is the documented batch-settlement behavior of
     * interpretation (A): the final interest repayment clears the
     * aggregate, and all remaining installments settle together.
     *
     * Returns the list of `LoanInstallmentSettled` events to be
     * registered for after-commit dispatch. The service does NOT
     * dispatch them here; the caller registers them at the end of the
     * outer transaction.
     *
     * @param Loan     $loan
     * @param int|null $actorUserId
     * @return list<LoanInstallmentSettled>
     */
    private function settleAffectedInstallments(
        Loan $loan,
        ?int $actorUserId,
    ): array {
        // The loan's aggregate projections were just recomputed by the
        // caller from the authoritative wallet_obligations table.
        // Refresh to be certain we read the freshly persisted values.
        $loan->refresh();

        $hasOutstandingInterest = bccomp(
            (string) ($loan->outstanding_interest ?? '0'),
            '0',
            self::FINAL_SCALE,
        ) !== 0;

        $hasOutstandingPenalty = bccomp(
            (string) ($loan->outstanding_penalty ?? '0'),
            '0',
            self::FINAL_SCALE,
        ) !== 0;

        if ($hasOutstandingInterest || $hasOutstandingPenalty) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [
                InstallmentStatus::Settled->value,
                InstallmentStatus::Superseded->value,
            ])
            ->get();

        if ($installments->isEmpty()) {
            return [];
        }

        $events = [];

        foreach ($installments as $installment) {
            $installment->status = InstallmentStatus::Settled;
            $installment->save();

            $events[] = new LoanInstallmentSettled(
                $loan,
                $installment,
                $actorUserId,
            );
        }

        return $events;
    }

    // =========================================================================
    // Private — projection recomputation
    // =========================================================================

    /**
     * Recompute the loan's three outstanding projections from the
     * current state of the loan-linked Wallet obligations.
     *
     * See the class docblock §"Projection recomputation".
     *
     * @param Loan $loan
     * @param int  $walletId
     * @return void
     */
    private function recomputeOutstandingProjections(Loan $loan, int $walletId): void
    {
        $componentMap = [
            ObligationComponent::Principal->value => 'outstanding_principal',
            ObligationComponent::Interest->value  => 'outstanding_interest',
            ObligationComponent::Penalty->value   => 'outstanding_penalty',
        ];

        foreach ($componentMap as $componentValue => $column) {
            $sum = Obligation::query()
                ->where('wallet_id', $walletId)
                ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN)
                ->where('reference_id', (string) $loan->id)
                ->where('component', $componentValue)
                ->sum('outstanding_amount');

            // `sum()` returns a string or null; normalise to a scale-4
            // decimal string via bcmath so that no floating point
            // arithmetic enters the financial pipeline.
            $normalised = $sum === null
                ? '0.0000'
                : bcadd('0', (string) $sum, self::FINAL_SCALE);

            $loan->{$column} = $normalised;
        }

        $loan->save();
    }

    // =========================================================================
    // Private — settlement detection
    // =========================================================================

    /**
     * Detect and record full settlement of the loan.
     *
     * The loan is settled iff:
     *
     *   1. its aggregate `outstanding_principal` has reached zero; AND
     *   2. every non-superseded installment is `InstallmentStatus::Settled`.
     *
     * When both hold, the method transitions the loan to
     * `LoanStatus::Settled` and registers a `LoanSettled` event for
     * after-commit dispatch.
     *
     * The principal check lives here — not in the installment-
     * settlement step — because the invariant it protects ("no loan
     * settles while principal is outstanding") is a loan-level
     * concern. Individual installments are free to reach `settled` as
     * soon as the loan's aggregate interest and penalty obligations
     * are cleared.
     *
     * @param Loan     $loan
     * @param int|null $actorUserId
     * @return Loan
     */
    private function maybeSettleLoan(Loan $loan, ?int $actorUserId): Loan
    {
        if (
            $loan->status === LoanStatus::Settled
            || $loan->status === LoanStatus::Closed
        ) {
            return $loan;
        }

        // Refresh so that the just-recomputed `outstanding_principal`
        // and the just-persisted installment statuses are both visible
        // to the checks below.
        $loan->refresh();

        // Condition 1 — the aggregate principal must be fully repaid.
        if (
            bccomp(
                (string) ($loan->outstanding_principal ?? '0'),
                '0',
                self::FINAL_SCALE,
            ) !== 0
        ) {
            return $loan;
        }

        // Condition 2 — every non-superseded installment must be settled.
        $hasUnsettled = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [
                InstallmentStatus::Settled->value,
                InstallmentStatus::Superseded->value,
            ])
            ->exists();

        if ($hasUnsettled) {
            return $loan;
        }

        $event = new LoanSettled($loan);

        return $this->stateMachine->transition(
            loan: $loan,
            nextStatus: LoanStatus::Settled,
            reason: null,
            actorUserId: $actorUserId,
            afterCommitEvent: $event,
        );
    }

    // =========================================================================
    // Private — event registration
    // =========================================================================

    /**
     * Register an event for after-commit dispatch, gated on
     * `config('loan.audit.emit_events')`.
     *
     * Unlike the state-machine-driven events, the
     * `LoanRepaymentRecorded` and `LoanInstallmentSettled` events are
     * not tied to a status transition. They are registered here
     * directly.
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