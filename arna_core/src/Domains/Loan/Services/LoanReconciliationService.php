<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use Carbon\CarbonInterface;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Payment;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;

/**
 * LoanReconciliationService
 *
 * Verifies the frozen reconciliation invariant between a loan's persisted
 * projections and the loan-linked Wallet obligation population.
 *
 * =============================================================================
 * Normalize obligation enums and preserve wallet-ledger reconciliation invariants.
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
 * @version 1.0.1
 */
final class LoanReconciliationService
{
    /**
     * The `reference_type` value on loan-linked Wallet obligations.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The `reference_type` value on loan-linked Wallet payments.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN_REPAYMENT = 'LoanRepayment';

    /**
     * Monetary scale.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * The class name of this reconciler, recorded in the report's
     * provenance block.
     *
     * @var string
     */
    private const RECONCILER = self::class;

    /**
     * The version of the reconciler, recorded in the report's provenance
     * block.
     *
     * @var string
     */
    private const RECONCILER_VERSION = '1.0.1';

    /**
     * Constructor.
     *
     * One dependency: the WalletRepository, used to resolve the
     * borrower's Wallet. The reconciler does NOT depend on any other
     * repository or service — it reads the Wallet's obligations and
     * payments directly, following the read-only pattern of the sibling
     * services.
     *
     * @param WalletRepository $walletRepository
     */
    public function __construct(
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // Public API — full report
    // =========================================================================
    /**
     * Produce the full reconciliation report for a loan.
     *
     * See the class docblock for the report's shape and the reasoning on
     * each of its sections. The report is deterministic for a given loan
     * and moment.
     *
     * The report is read-only. It does not modify any state, does not open
     * a transaction, and does not dispatch any event.
     *
     * @param Loan                 $loan
     * @param CarbonInterface|null $asOf The moment of the report;
     *                                             defaults to `now()`.
     * @return array<string, mixed>
     */
    public function reconcile(Loan $loan, ?CarbonInterface $asOf = null): array
    {
        $moment = $asOf !== null
            ? Carbon::instance($asOf)
            : Carbon::now();

        // ---------------------------------------------------------------------
        // Resolve the Wallet, if any.
        // ---------------------------------------------------------------------
        $wallet = $this->resolveWalletFor($loan);

        // ---------------------------------------------------------------------
        // Loan-side values.
        // ---------------------------------------------------------------------
        $loanProjected = [
            'outstanding_principal' => $this->normalizeAmount($loan->outstanding_principal ?? '0'),
            'outstanding_interest'  => $this->normalizeAmount($loan->outstanding_interest  ?? '0'),
            'outstanding_penalty'   => $this->normalizeAmount($loan->outstanding_penalty),
            'settled_amount'        => $this->normalizeAmount($loan->settled_amount),
        ];

        $loanProjected['outstanding_total'] = bcadd(
            bcadd(
                $loanProjected['outstanding_principal'],
                $loanProjected['outstanding_interest'],
                self::FINAL_SCALE,
            ),
            $loanProjected['outstanding_penalty'],
            self::FINAL_SCALE,
        );

        // ---------------------------------------------------------------------
        // Wallet-side obligations.
        // ---------------------------------------------------------------------
        [$walletObligations, $walletObligationCount, $walletSettledCount] =
            $this->collectWalletObligations($loan, $wallet?->id);

        $walletLinked = [
            'wallet_id'             => $wallet?->id,
            'obligation_count'      => $walletObligationCount,
            'settled_obligation_count' => $walletSettledCount,
            'outstanding_principal' => $walletObligations['principal'],
            'outstanding_interest'  => $walletObligations['interest'],
            'outstanding_penalty'   => $walletObligations['penalty'],
        ];

        $walletLinked['outstanding_total'] = bcadd(
            bcadd(
                $walletLinked['outstanding_principal'],
                $walletLinked['outstanding_interest'],
                self::FINAL_SCALE,
            ),
            $walletLinked['outstanding_penalty'],
            self::FINAL_SCALE,
        );

        // ---------------------------------------------------------------------
        // Wallet-side payments.
        // ---------------------------------------------------------------------
        $walletPayments = $this->collectWalletPayments($loan, $wallet?->id);

        // ---------------------------------------------------------------------
        // Contractual schedule totals.
        // ---------------------------------------------------------------------
        $contractual = $this->collectContractualTotals($loan);

        // ---------------------------------------------------------------------
        // Per-installment breakdown.
        // ---------------------------------------------------------------------
        $perInstallment = $this->collectPerInstallmentBreakdown($loan);

        // ---------------------------------------------------------------------
        // Divergence — informational; per-component.
        // ---------------------------------------------------------------------
        $divergence = [
            'principal' => bcsub(
                $walletLinked['outstanding_principal'],
                $loanProjected['outstanding_principal'],
                self::FINAL_SCALE,
            ),
            'interest'  => bcsub(
                $walletLinked['outstanding_interest'],
                $loanProjected['outstanding_interest'],
                self::FINAL_SCALE,
            ),
            'penalty'   => bcsub(
                $walletLinked['outstanding_penalty'],
                $loanProjected['outstanding_penalty'],
                self::FINAL_SCALE,
            ),
        ];

        $divergence['total'] = bcsub(
            $walletLinked['outstanding_total'],
            $loanProjected['outstanding_total'],
            self::FINAL_SCALE,
        );

        $isBalanced = bccomp(
            $walletLinked['outstanding_total'],
            $loanProjected['outstanding_total'],
            self::FINAL_SCALE,
        ) === 0;

        // ---------------------------------------------------------------------
        // Assemble the report.
        // ---------------------------------------------------------------------
        return [
            'loan_id'      => (int) $loan->id,
            'currency'     => $loan->currency,
            'status'       => $loan->status->value,
            'reconciled_at' => $moment->toIso8601String(),

            'contractual'    => $contractual,
            'loan_projected' => $loanProjected,
            'wallet_linked'  => $walletLinked,
            'wallet_payments' => $walletPayments,

            'per_installment' => $perInstallment,

            'divergence'   => $divergence,
            'is_balanced'  => $isBalanced,

            'provenance' => [
                'wallet_id'         => $wallet?->id,
                'reconciler'        => self::RECONCILER,
                'reconciler_version' => self::RECONCILER_VERSION,
            ],
        ];
    }

    // =========================================================================
    // Public API — fast check and assertion
    // =========================================================================

    /**
     * Whether the loan's projections are in balance with its Wallet-linked
     * obligations.
     *
     * Performs ONLY the aggregate check from the frozen invariant. It does
     * not produce a full report, and it does not attempt to locate the
     * source of any divergence. A caller that needs the diagnostic should
     * use `reconcile()`.
     *
     * @param Loan $loan
     * @return bool
     */
    public function isBalanced(Loan $loan): bool
    {
        $wallet = $this->resolveWalletFor($loan);

        $walletTotal = $this->sumWalletOutstandingTotal($loan, $wallet?->id);

        $loanTotal = bcadd(
            bcadd(
                $this->normalizeAmount($loan->outstanding_principal ?? '0'),
                $this->normalizeAmount($loan->outstanding_interest  ?? '0'),
                self::FINAL_SCALE,
            ),
            $this->normalizeAmount($loan->outstanding_penalty),
            self::FINAL_SCALE,
        );

        return bccomp($walletTotal, $loanTotal, self::FINAL_SCALE) === 0;
    }

    /**
     * Assert that the loan's projections are in balance with its
     * Wallet-linked obligations.
     *
     * Throws `LogicException` when the invariant is violated. Intended to
     * be called by the financial services at their transaction
     * boundaries, inside the enclosing transaction, so that a divergence
     * rolls back the operation that produced it.
     *
     * The thrown `LogicException` is NOT a `ValidationException`: a
     * divergence indicates a programming defect (the service that wrote
     * one side of the invariant did not write the other), not a caller
     * input error. The class docblock §"A note on the 'mandatory internal
     * reconciliation' wiring" documents the current state of the wiring.
     *
     * @param Loan $loan
     * @return void
     *
     * @throws LogicException  When the loan is not in balance with its
     *                         Wallet-linked obligations.
     */
    public function assertBalanced(Loan $loan): void
    {
        if ($this->isBalanced($loan)) {
            return;
        }

        $wallet = $this->resolveWalletFor($loan);

        $walletTotal = $this->sumWalletOutstandingTotal($loan, $wallet?->id);

        $loanTotal = bcadd(
            bcadd(
                $this->normalizeAmount($loan->outstanding_principal ?? '0'),
                $this->normalizeAmount($loan->outstanding_interest  ?? '0'),
                self::FINAL_SCALE,
            ),
            $this->normalizeAmount($loan->outstanding_penalty),
            self::FINAL_SCALE,
        );

        throw new LogicException(
            sprintf(
                'Loan reconciliation invariant violated for loan id=%d. '
                . 'Wallet-linked outstanding total = %s; loan projected '
                . 'outstanding total = %s; divergence = %s. This '
                . 'indicates a programming defect in the service that '
                . 'wrote one side of the invariant without writing the '
                . 'other. Investigate before committing.',
                $loan->id,
                $walletTotal,
                $loanTotal,
                bcsub($walletTotal, $loanTotal, self::FINAL_SCALE),
            )
        );
    }

    // =========================================================================
    // Private — Wallet resolution
    // =========================================================================
    /**
     * Resolve the borrower's Wallet, if any.
     *
     * Returns null when the loan has no borrower relation, or when the
     * borrower has no Wallet. Both are valid states for a
     * pre-disbursement loan, and neither is a defect for the reconciler's
     * purposes. See the class docblock §"What happens when the loan has
     * no Wallet".
     *
     * @param Loan $loan
     * @return Wallet|null
     */
    private function resolveWalletFor(Loan $loan): ?Wallet
    {
        $loan->loadMissing('borrower');

        $borrower = $loan->borrower;

        if ($borrower === null) {
            return null;
        }

        return $this->walletRepository->findForShareholder(
            (int) $loan->tenant_id,
            (int) $borrower->id,
        );
    }

    // =========================================================================
    // Private — Wallet-side collection
    // =========================================================================

    /**
     * Collect the loan-linked Wallet obligations and their aggregate
     * outstanding per component.
     *
     * Returns a three-element array:
     *
     *   [0] — per-component aggregate: ['principal' => string,
     *                                    'interest'  => string,
     *                                    'penalty'   => string]
     *   [1] — total obligation count (all loan-linked obligations,
     *         regardless of outstanding status)
     *   [2] — settled obligation count (obligations with zero
     *         outstanding)
     *
     * @param Loan   $loan
     * @param int|null $walletId
     * @return array{0: array<string, string>, 1: int, 2: int}
     */
    private function collectWalletObligations(Loan $loan, ?int $walletId): array
    {
        $empty = [
            'principal' => '0.0000',
            'interest'  => '0.0000',
            'penalty'   => '0.0000',
        ];

        if ($walletId === null) {
            return [$empty, 0, 0];
        }

        /** @var Collection<int, Obligation> $obligations */
        $obligations = Obligation::query()
            ->where('wallet_id', $walletId)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN)
            ->where('reference_id', (string) $loan->id)
            ->get();

        $byComponent = [
            'principal' => '0.0000',
            'interest'  => '0.0000',
            'penalty'   => '0.0000',
        ];

        $settledCount = 0;

        foreach ($obligations as $obligation) {
            $component = $obligation->component->value;
            $outstanding = $this->normalizeAmount((string) $obligation->outstanding_amount);

            if (array_key_exists($component, $byComponent)) {
                $byComponent[$component] = bcadd(
                    $byComponent[$component],
                    $outstanding,
                    self::FINAL_SCALE,
                );
            }

            if (bccomp($outstanding, '0', self::FINAL_SCALE) === 0) {
                $settledCount++;
            }
        }

        return [$byComponent, $obligations->count(), $settledCount];
    }

    /**
     * Compute the aggregate Wallet-linked outstanding total for the loan.
     *
     * A thin, efficient wrapper that avoids materialising the full
     * per-component breakdown when only the total is needed (used by
     * `isBalanced()` and `assertBalanced()`).
     *
     * @param Loan   $loan
     * @param int|null $walletId
     * @return string  Decimal string at scale 4.
     */
    private function sumWalletOutstandingTotal(Loan $loan, ?int $walletId): string
    {
        if ($walletId === null) {
            return '0.0000';
        }

        $sum = Obligation::query()
            ->where('wallet_id', $walletId)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN)
            ->where('reference_id', (string) $loan->id)
            ->sum('outstanding_amount');

        return $this->normalizeAmount((string) ($sum ?? '0'));
    }

    /**
     * Collect the loan-linked Wallet payments and their aggregate totals.
     *
     * @param Loan   $loan
     * @param int|null $walletId
     * @return array{payment_count: int, total_received: string,
     *               total_allocated: string, total_unapplied: string}
     */
    private function collectWalletPayments(Loan $loan, ?int $walletId): array
    {
        $empty = [
            'payment_count'   => 0,
            'total_received'  => '0.0000',
            'total_allocated' => '0.0000',
            'total_unapplied' => '0.0000',
        ];

        if ($walletId === null) {
            return $empty;
        }

        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->where('wallet_id', $walletId)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT)
            ->where('reference_id', (string) $loan->id)
            ->get();

        $totalReceived  = '0.0000';
        $totalUnapplied = '0.0000';

        foreach ($payments as $payment) {
            $totalReceived = bcadd(
                $totalReceived,
                $this->normalizeAmount((string) $payment->amount),
                self::FINAL_SCALE,
            );
            $totalUnapplied = bcadd(
                $totalUnapplied,
                $this->normalizeAmount((string) $payment->unapplied_amount),
                self::FINAL_SCALE,
            );
        }

        $totalAllocated = bcsub($totalReceived, $totalUnapplied, self::FINAL_SCALE);

        return [
            'payment_count'   => $payments->count(),
            'total_received'  => $totalReceived,
            'total_allocated' => $totalAllocated,
            'total_unapplied' => $totalUnapplied,
        ];
    }

    // =========================================================================
    // Private — Loan-side collection
    // =========================================================================

    /**
     * Collect the loan's contractual schedule totals from its active
     * installments.
     *
     * @param Loan $loan
     * @return array{total_expected_principal: string,
     *               total_expected_interest: string,
     *               total_expected_repayment: string,
     *               active_installment_count: int,
     *               settled_installment_count: int,
     *               overdue_installment_count: int,
     *               pending_or_partial_installment_count: int,
     *               superseded_installment_count: int}
     */
    private function collectContractualTotals(Loan $loan): array
    {
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->get();

        $totalPrincipal = '0.0000';
        $totalInterest  = '0.0000';
        $totalRepayment = '0.0000';

        $activeCount     = 0;
        $settledCount    = 0;
        $overdueCount    = 0;
        $pendingCount    = 0;
        $supersededCount = 0;

        foreach ($installments as $installment) {
            $status = $installment->status;

            if ($status === InstallmentStatus::Superseded) {
                $supersededCount++;
                continue;
            }

            $activeCount++;

            $totalPrincipal = bcadd(
                $totalPrincipal,
                $this->normalizeAmount((string) $installment->expected_principal),
                self::FINAL_SCALE,
            );
            $totalInterest = bcadd(
                $totalInterest,
                $this->normalizeAmount((string) $installment->expected_interest),
                self::FINAL_SCALE,
            );
            $totalRepayment = bcadd(
                $totalRepayment,
                $this->normalizeAmount((string) $installment->expected_total),
                self::FINAL_SCALE,
            );

            switch ($status) {
                case InstallmentStatus::Settled:
                    $settledCount++;
                    break;
                case InstallmentStatus::Overdue:
                    $overdueCount++;
                    break;
                case InstallmentStatus::Pending:
                case InstallmentStatus::Partial:
                    $pendingCount++;
                    break;
                default:
                    // Unknown statuses are counted in the active total
                    // but not in any specific bucket. The matrix is
                    // frozen; this branch is defensive.
                    break;
            }
        }

        return [
            'total_expected_principal'  => $totalPrincipal,
            'total_expected_interest'   => $totalInterest,
            'total_expected_repayment'  => $totalRepayment,
            'active_installment_count'  => $activeCount,
            'settled_installment_count' => $settledCount,
            'overdue_installment_count' => $overdueCount,
            'pending_or_partial_installment_count' => $pendingCount,
            'superseded_installment_count' => $supersededCount,
        ];
    }

    /**
     * Collect the per-installment breakdown.
     *
     * See the class docblock §"The per-installment breakdown" for why the
     * breakdown is informational and what each field means.
     *
     * The method performs a bounded number of queries regardless of the
     * number of installments: one for the active installments, one for
     * the interest accruals, one for the penalty accruals. The accruals
     * are eager-loaded with their Wallet obligations, so no N+1 pattern
     * occurs.
     *
     * @param Loan $loan
     * @return list<array<string, mixed>>
     */
    private function collectPerInstallmentBreakdown(Loan $loan): array
    {
        /** @var Collection<int, LoanInstallment> $installments */
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [InstallmentStatus::Superseded->value])
            ->orderBy('installment_number')
            ->get();

        if ($installments->isEmpty()) {
            return [];
        }

        $installmentIds = $installments->pluck('id')->all();

        /** @var Collection<int, LoanInterestAccrual> $interestAccruals */
        $interestAccruals = LoanInterestAccrual::query()
            ->whereIn('installment_id', $installmentIds)
            ->with('walletObligation')
            ->get()
            ->keyBy('installment_id');

        /** @var Collection<int, LoanPenaltyAccrual> $penaltyAccruals */
        $penaltyAccruals = LoanPenaltyAccrual::query()
            ->whereIn('installment_id', $installmentIds)
            ->with('walletObligation')
            ->get()
            ->keyBy('installment_id');

        $rows = [];

        foreach ($installments as $installment) {
            $interestAccrual = $interestAccruals->get($installment->id);
            $penaltyAccrual  = $penaltyAccruals->get($installment->id);

            $walletInterestOutstanding = $interestAccrual?->walletObligation
                ? $this->normalizeAmount((string) $interestAccrual->walletObligation->outstanding_amount)
                : null;

            $walletPenaltyOutstanding = $penaltyAccrual?->walletObligation
                ? $this->normalizeAmount((string) $penaltyAccrual->walletObligation->outstanding_amount)
                : null;

            $walletLinkedOutstanding = bcadd(
                $walletInterestOutstanding ?? '0.0000',
                $walletPenaltyOutstanding  ?? '0.0000',
                self::FINAL_SCALE,
            );

            $remaining = bcsub(
                (string) $installment->expected_total,
                (string) $installment->settled_amount,
                self::FINAL_SCALE,
            );

            if (bccomp($remaining, '0', self::FINAL_SCALE) < 0) {
                $remaining = '0.0000';
            }

            $rows[] = [
                'installment_id'            => (int) $installment->id,
                'installment_number'        => (int) $installment->installment_number,
                'due_date'                  => $installment->due_date->toDateString(),
                'status'                    => $installment->status->value,
                'expected_principal'        => (string) $installment->expected_principal,
                'expected_interest'         => (string) $installment->expected_interest,
                'expected_total'            => (string) $installment->expected_total,
                'settled_amount'            => (string) $installment->settled_amount,
                'remaining_total'           => $remaining,
                'wallet_interest_outstanding' => $walletInterestOutstanding,
                'wallet_penalty_outstanding'  => $walletPenaltyOutstanding,
                'wallet_linked_outstanding'   => $walletLinkedOutstanding,
            ];
        }

        return $rows;
    }

    // =========================================================================
    // Private — helpers
    // =========================================================================

    /**
     * Normalize a numeric or null value to a scale-4 decimal string.
     *
     * The `decimal:4` casts on the models return strings, so this method
     * is mostly a null-handling pass-through. The `bcadd('0', $value, 4)`
     * call additionally normalizes any representation the cast might
     * produce in edge cases (for example, a value like `"100"` becomes
     * `"100.0000"`).
     *
     * @param string|null $value
     * @return string  Decimal string at scale 4.
     */
    private function normalizeAmount(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd('0', $value, self::FINAL_SCALE);
    }
}