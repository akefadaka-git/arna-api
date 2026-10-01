<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Services;

use Carbon\CarbonInterface;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;

/**
 * LoanReconciliationService
 *
 * Verifies the frozen reconciliation invariant between a loan's persisted
 * projections and the loan-linked Wallet obligation population.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — `collectWalletObligations()` enum-cast fix. The loop previously
 *         performed `(string) $obligation->component`. The
 *         `wallet_obligations.component` column casts to the
 *         `ObligationComponent` enum (a string-backed enum, no
 *         `__toString()`), so PHP raises
 *         `Error: Object of class … could not be converted to string` on
 *         every call where at least one loan-linked obligation exists.
 *         The fix reads `$obligation->component->value`, which is the
 *         enum's backed string. The change was surfaced by the D-13
 *         discovery of the domain test tree; every reconciliation
 *         feature test and every reconciliation unit test whose fixture
 *         seeded a loan-linked obligation was failing with a 500
 *         before this revision. No API shape changed: `$byComponent`
 *         remains a `array<string, string>` keyed by the snake_case
 *         component names.
 *
 * =============================================================================
 * The frozen invariant (Phase 2C Final Reconciliation §3)
 * =============================================================================
 * The exact wording, quoted from the reviewer's correction:
 *
 *     For each loan, the sum of `outstanding_amount` across all Wallet
 *     obligations where `reference_type = 'Loan'` and `reference_id =
 *     (string) $loan->id` must equal the sum of the loan's persisted
 *     projections: outstanding_principal + outstanding_interest +
 *     outstanding_penalty.
 *
 *     Loan reconciliation must NOT compare against the wallet's
 *     `current_balance`. The wallet balance includes all obligations
 *     and adjustments on the wallet, including non-loan ones. A loan's
 *     obligations are a subset.
 *
 * The invariant is expressed in one equation:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) $loan->id)
 *       ==
 *     loan.outstanding_principal
 *       + loan.outstanding_interest
 *       + loan.outstanding_penalty
 *
 * The invariant is checked STRICTLY on the aggregate. Per-component
 * divergences are reported as INFORMATION (they help a consumer locate
 * where a divergence occurred), but they are not separately enforced:
 * a zero aggregate divergence with a non-zero component divergence
 * indicates a data defect that the service reports but does not
 * classify as a balanced state.
 *
 * =============================================================================
 * The two modes of use
 * =============================================================================
 * The C5 decision specifies a COMBINATION of two modes. This service
 * supports both:
 *
 *   (A) MANDATORY INTERNAL RECONCILIATION — invoked by the financial
 *       services (`LoanDisbursementService`, `LoanRepaymentService`,
 *       `LoanInterestAccrualService`, `LoanArrearsService`,
 *       `LoanRestructuringService`, `LoanWriteOffService`) at their
 *       transaction boundaries, via `assertBalanced()`. If the invariant
 *       is violated, the caller throws and its transaction rolls back.
 *
 *   (B) ON-DEMAND RECONCILIATION ENDPOINT — invoked by the reconciliation
 *       controller (`LoanReconciliationController`, A.78) via
 *       `reconcile()`, which produces a full diagnostic report that is
 *       returned to the caller.
 *
 * The two modes share the same invariant and the same underlying checks.
 * `assertBalanced()` is a thin wrapper over `isBalanced()`, which is
 * itself a thin, efficient wrapper over the aggregate check. `reconcile()`
 * produces the full report, including the per-installment and per-component
 * breakdowns that the endpoint consumes.
 *
 * =============================================================================
 * A note on the "mandatory internal reconciliation" wiring
 * =============================================================================
 * The C5 decision requires `assertBalanced()` to be called at every
 * financial transaction boundary. The service provides that method.
 * Wiring it into the six financial services (A.27 through A.35) is a
 * SEPARATE STEP from delivering the reconciler itself, and is NOT
 * performed by this file:
 *
 *   - The six services have already been delivered without the calls.
 *
 *   - Regenerating them now would violate the one-file-at-a-time
 *     discipline and fragment the audit trail: a reviewer examining
 *     A.30 would see the file delivered, then re-delivered, without a
 *     clear reason.
 *
 *   - The integration is small and additive: one `assertBalanced()`
 *     call at the end of each service's transaction, before the final
 *     `return`.
 *
 * The reviewer is asked to decide between:
 *
 *   1. Accepting the current state and recording a follow-up CR to wire
 *      the calls in a dedicated pass.
 *
 *   2. Requesting an explicit regeneration of A.27 through A.35 with the
 *      `assertBalanced()` calls added.
 *
 * Either outcome is compatible with the frozen C5 decision; only the
 * sequencing differs.
 *
 * =============================================================================
 * What this service is NOT
 * =============================================================================
 *   - It does NOT write to the Wallet. It reads `wallet_obligations` and
 *     `wallet_payments`.
 *
 *   - It does NOT write to the Loan domain. It reads `loans`,
 *     `loan_installments`, `loan_interest_accruals`, and
 *     `loan_penalty_accruals`.
 *
 *   - It does NOT open a transaction. It is a read-only service; the
 *     caller's transaction (if any) is unaffected.
 *
 *   - It does NOT dispatch events.
 *
 *   - It does NOT modify the loan's projections to "make them balance".
 *     A divergence is a data defect, not a value to correct in place. The
 *     correct remedy is to investigate the divergence, not to overwrite
 *     one side with the other.
 *
 *   - It does NOT read `wallets.current_balance`. The reviewer's §3
 *     correction explicitly forbids it: a wallet's balance includes
 *     obligations not linked to the loan and adjustments made for
 *     unrelated reasons. Comparing a loan's projections to the entire
 *     wallet balance would produce false divergences.
 *
 *   - It does NOT recompute the loan's projections. The projections are
 *     the persisted values maintained by the financial services; the
 *     reconciler compares them against the Wallet, it does not derive
 *     them.
 *
 *   - It does NOT compare the loan's schedule (`loan_installments`) to
 *     the Wallet obligation population on a per-installment basis for
 *     the purpose of determining balance. The schedule is the
 *     CONTRACTUAL view; the Wallet is the MONETARY view; the two are
 *     intentionally different (see the B2 hybrid-ledger freeze). The
 *     per-installment breakdown that `reconcile()` returns is
 *     INFORMATION, not a separate balance check.
 *
 * =============================================================================
 * The per-installment breakdown, and why it is informational
 * =============================================================================
 * Under the frozen B2 hybrid-ledger design, principal is recorded as ONE
 * obligation for the WHOLE loan (A.30), not as one obligation per
 * installment. The Wallet's per-installment view therefore contains only
 * the interest obligation (from `loan_interest_accruals`, A.15) and any
 * penalty obligation (from `loan_penalty_accruals`, A.16). The loan's
 * per-installment view contains `expected_principal`, `expected_interest`,
 * and their sum.
 *
 * The two views are structurally different. A "per-installment
 * divergence" cannot mean "the loan's expected amount for this
 * installment differs from the Wallet's outstanding for this
 * installment" — that comparison is invalid by construction. Instead, the
 * per-installment breakdown that this service returns shows:
 *
 *   - the loan-side expected and settled values (contractual view);
 *   - the Wallet-side linked interest and penalty obligations for the
 *     installment (monetary view).
 *
 * The two are presented side by side so that a consumer can see, at the
 * granularity of a single installment, what the schedule promises and
 * what the Wallet currently records. No per-installment "balanced" flag
 * is produced; that judgment belongs to the aggregate.
 *
 * =============================================================================
 * What this service uses for each side
 * =============================================================================
 *   Loan-side projections:
 *     loans.outstanding_principal       (nullable)
 *     loans.outstanding_interest        (nullable)
 *     loans.outstanding_penalty         (NOT NULL, default 0)
 *     loans.settled_amount              (NOT NULL, default 0)
 *
 *   Wallet-side outstanding:
 *     wallet_obligations WHERE
 *       wallet_id      = <borrower's wallet id>
 *       reference_type = 'Loan'
 *       reference_id   = (string) $loan.id
 *     grouped by `component`, summing `outstanding_amount`.
 *
 *   Wallet-side payments:
 *     wallet_payments WHERE
 *       wallet_id      = <borrower's wallet id>
 *       reference_type = 'LoanRepayment'
 *       reference_id   = (string) $loan.id
 *     summing `amount` and `unapplied_amount`.
 *
 *   Contractual schedule:
 *     loan_installments WHERE
 *       loan_id = $loan.id
 *       AND status <> 'superseded'
 *     summing `expected_principal`, `expected_interest`, and
 *     `expected_total`; counting by status.
 *
 * =============================================================================
 * What happens when the loan has no Wallet
 * =============================================================================
 * A loan in a pre-disbursement state (`draft`, `submitted`, `under_review`,
 * `approved`) has no borrower Wallet in practice. This is not a defect.
 * The Wallet-side report is simply empty:
 *
 *   - `wallet_linked.wallet_id` is null;
 *   - all Wallet-side sums are `"0.0000"`;
 *   - the obligation and payment counts are zero.
 *
 * The aggregate check compares the loan's projections (null → 0, per the
 * migration A.9) against the empty Wallet side (0). For a loan in a
 * pre-disbursement state, `is_balanced` is `true` — the loan has nothing
 * to reconcile against. This is correct: there is no divergence to
 * detect.
 *
 * The service does NOT throw on a missing Wallet. It is a read-only
 * diagnostic; a missing Wallet is a fact about the loan's state, not an
 * error in the reconciliation itself.
 *
 * =============================================================================
 * Determinism and reproducibility
 * =============================================================================
 * For a given loan and a given moment, `reconcile()` produces a
 * byte-for-byte identical result on every call, on every platform. No
 * randomness, no time-dependence beyond `reconciled_at`, no
 * locale-dependence. This is required for the endpoint's consumers to
 * trust the report, and for the unit tests to assert deterministic
 * results.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B1, B2, B3
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation
 *                                                  combination)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §3 (the exact
 *                                                  reconciliation
 *                                                  invariant and the
 *                                                  wallet-balance
 *                                                  exclusion)
 *   Phase 2C A.5                                 — `InstallmentStatus`
 *   Phase 2C A.15                                — `loan_interest_accruals`
 *   Phase 2C A.16                                — `loan_penalty_accruals`
 *   Phase 2C A.18                                — `Loan`
 *   Phase 2C A.19                                — `LoanInstallment`
 *   Phase 2C A.30                                — `LoanDisbursementService`
 *   Phase 2C A.31                                — `LoanRepaymentService`
 *   Phase 2C A.32                                — `LoanInterestAccrualService`
 *   Phase 2C A.33                                — `LoanArrearsService`
 *   Phase 2C A.34                                — `LoanRestructuringService`
 *   Phase 2C A.35                                — `LoanWriteOffService`
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *   Phase 2B migration 2B.51                     — `wallet_payments`
 *   Phase 2B migration 2B.49                     — `wallets` (the
 *                                                  `current_balance`
 *                                                  column excluded from
 *                                                  this service by design)
 *   Step 5 (manifest) A.36                       — This file
 *   Step 6 v1.1.0 §3.9, §3.10, §3.15, §3.16      — `loans`,
 *                                                  `loan_installments`,
 *                                                  `loan_interest_accruals`,
 *                                                  `loan_penalty_accruals`
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Services
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