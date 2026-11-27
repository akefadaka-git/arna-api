<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Http\Controllers;

use Gate;
use LogicException;
use Illuminate\Database\Eloquent\Collection;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\Arna\Core\Domains\Loan\Http\Resources\LoanStatementResource;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanCollateral;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanWriteOff;
use MaaleArna\Arna\Core\Domains\Loan\Repositories\LoanRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Payment;
use MaaleArna\Arna\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;

/**
 * Builds a chronological loan statement from monetary activity and lifecycle
 * history. Status changes remain visible as zero-value entries and do not
 * affect balances or totals.
 */
final class LoanStatementController
{
    /**
     * The monetary scale used for all deltas and running balances.
     *
     * Matches the DECIMAL(20,4) monetary columns on both domains.
     */
    private const FINAL_SCALE = 4;

    /**
     * The Wallet-side reference-type constant for loan-linked
     * obligations (per B1).
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The Wallet-side reference-type constant for loan-linked payments
     * (per B1).
     */
    private const WALLET_REFERENCE_TYPE_LOAN_REPAYMENT = 'LoanRepayment';

    /**
     * The type-priority order used as the secondary sort key for the
     * statement's chronological lines. See the class docblock §"The
     * chronological sort and its tiebreakers".
     *
     * The list contains every event type the controller emits. The
     * `status_change` entry is at priority 8 — after every monetary and
     * security event at the same instant — because a status transition
     * that accompanies a monetary event is the state machine's response
     * to it, and reading the monetary event first reflects that causal
     * order. This entry was removed by the v1.1.0 out-of-CR change and
     * restored by the B4 revert.
     *
     * @var array<string, int>
     */
    private const TYPE_PRIORITY = [
        'disbursement'       => 1,
        'interest_accrual'   => 2,
        'penalty_accrual'    => 3,
        'repayment'          => 4,
        'restructure'        => 5,
        'write_off'          => 6,
        'collateral_release' => 7,
        'status_change'      => 8,
    ];

    /**
     * Constructor.
     *
     * Two dependencies:
     *
     *   - `LoanRepository`    — the tenant-scoped loan lookup.
     *   - `WalletRepository`  — the borrower Wallet lookup.
     *
     * @param LoanRepository    $loanRepository
     * @param WalletRepository  $walletRepository
     */
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // Public API — show
    // =========================================================================

    /**
     * Return a loan's statement view.
     *
     * @param Request $request
     * @param int     $loanId
     * @return JsonResponse
     */
    public function show(Request $request, int $loanId): JsonResponse
    {
        $loan = $this->resolveLoan($loanId);

        Gate::authorize('viewStatement', $loan);

        $statement = $this->buildStatement($loan);

        return response()->json([
            'success' => true,
            'data'    => (new LoanStatementResource([
                'loan'      => $loan,
                'statement' => $statement,
            ]))->toArray($request),
        ]);
    }

    // =========================================================================
    // Private — resolution
    // =========================================================================

    /**
     * Resolve the loan addressed by the route's `{loan}` parameter,
     * eager-loading every relation the statement aggregation reads.
     *
     * The `statusHistories` relation is eager-loaded because the B4
     * revert restored `status_change` line emission. Dropping it (as
     * the v1.1.0 out-of-CR change did) would force a lazy-load on the
     * first history-row read.
     *
     * @param int $loanId
     * @return Loan
     */
    private function resolveLoan(int $loanId): Loan
    {
        return $this->loanRepository->findForTenantOrFail(
            tenantId: $this->resolveTenantId(),
            loanId:   $loanId,
            with:     [
                'borrower',
                'interestAccruals',
                'penaltyAccruals',
                'restructurings',
                'writeOff',
                'collaterals',
                'statusHistories',
            ],
        );
    }

    /**
     * Resolve the authenticated tenant's id from the request context.
     *
     * See `LoanController::resolveTenantId()` (A.77) for the shared
     * rationale. The guard mirrors that method verbatim.
     *
     * @return int
     *
     * @throws LogicException
     */
    private function resolveTenantId(): int
    {
        /** @var int|string|null $tenantId */
        $tenantId = function_exists('arna_tenant_id')
            ? arna_tenant_id()
            : null;

        if ($tenantId === null || $tenantId === '') {
            throw new LogicException(
                'LoanStatementController requires a resolved tenant id. '
                . 'The `tenant.membership` middleware must populate the '
                . 'request context before the statement action runs. '
                . 'A missing tenant indicates a middleware-bypass defect '
                . 'or a route registered without the middleware stack.'
            );
        }

        return (int) $tenantId;
    }

    /**
     * Resolve the borrower's Wallet, if it exists.
     *
     * Returns null when the borrower has no Wallet — a legitimate state
     * for a pre-disbursement loan. See the class docblock §"The
     * pre-disbursement loan".
     *
     * @param Loan $loan
     * @return Wallet|null
     */
    private function resolveBorrowerWallet(Loan $loan): ?Wallet
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
    // Private — statement assembly
    // =========================================================================

    /**
     * Build the composite statement structure for a loan.
     *
     * See the class docblock §"The event sources and their balance
     * deltas" for the interpretation of each line type.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     */
    private function buildStatement(Loan $loan): array
    {
        $wallet = $this->resolveBorrowerWallet($loan);

        $events = $this->collectEvents($loan, $wallet);

        if ($events === []) {
            return $this->emptyStatement();
        }

        usort($events, [$this, 'compareEvents']);

        return $this->walkEvents($events);
    }

    /**
     * Build an empty statement structure.
     *
     * @return array<string, mixed>
     */
    private function emptyStatement(): array
    {
        return [
            'period' => [
                'from' => null,
                'to'   => null,
            ],
            'opening_balance' => $this->zeroBalance(),
            'lines'           => [],
            'closing_balance' => $this->zeroBalance(),
            'totals'          => $this->zeroTotals(),
        ];
    }

    /**
     * Collect the raw event list from every source.
     *
     * The sources enumerated here are the ONLY ones the statement
     * recognizes. The `statusHistories` source was restored by the B4
     * revert; see the class docblock §"Revision history" for the full
     * account of its v1.1.0 removal and this reinstatement.
     *
     * @param Loan        $loan
     * @param Wallet|null $wallet
     * @return list<array<string, mixed>>
     */
    private function collectEvents(Loan $loan, ?Wallet $wallet): array
    {
        $events = [];

        // ---------------------------------------------------------------------
        // Disbursement.
        // ---------------------------------------------------------------------
        if ($loan->disbursement_date !== null) {
            $events[] = $this->disbursementEvent($loan);
        }

        // ---------------------------------------------------------------------
        // Interest accruals.
        // ---------------------------------------------------------------------
        foreach ($loan->interestAccruals as $accrual) {
            $events[] = $this->interestAccrualEvent($accrual);
        }

        // ---------------------------------------------------------------------
        // Penalty accruals.
        // ---------------------------------------------------------------------
        foreach ($loan->penaltyAccruals as $accrual) {
            $events[] = $this->penaltyAccrualEvent($accrual);
        }

        // ---------------------------------------------------------------------
        // Repayments — require the Wallet.
        // ---------------------------------------------------------------------
        if ($wallet !== null) {
            $events = array_merge(
                $events,
                $this->collectRepaymentEvents($loan, $wallet),
            );
        }

        // ---------------------------------------------------------------------
        // Restructurings.
        // ---------------------------------------------------------------------
        foreach ($loan->restructurings as $restructuring) {
            $events[] = $this->restructureEvent($restructuring);
        }

        // ---------------------------------------------------------------------
        // Write-off (at most one).
        // ---------------------------------------------------------------------
        if ($loan->writeOff !== null) {
            $events[] = $this->writeOffEvent($loan->writeOff);
        }

        // ---------------------------------------------------------------------
        // Collateral releases.
        // ---------------------------------------------------------------------
        if ($loan->relationLoaded('collaterals')) {
            foreach ($loan->collaterals as $collateral) {
                if ($collateral->isReleased()) {
                    $events[] = $this->collateralReleaseEvent($collateral);
                }
            }
        }

        // ---------------------------------------------------------------------
        // Status changes.
        //
        // One event per `LoanStatusHistory` row. Every delta is zero —
        // a lifecycle transition moves no money — but the line is
        // emitted so that the statement interleaves lifecycle and
        // monetary activity in a single chronology. This is the
        // pre-v1.1.0 contract, restored by the B4 revert.
        // ---------------------------------------------------------------------
        if ($loan->relationLoaded('statusHistories')) {
            foreach ($loan->statusHistories as $history) {
                $events[] = $this->statusChangeEvent($history);
            }
        }

        return $events;
    }

    // =========================================================================
    // Private — per-event builders
    // =========================================================================

    /**
     * Build the disbursement event.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     */
    private function disbursementEvent(Loan $loan): array
    {
        $amount = (string) $loan->principal_amount;

        return [
            'occurred_at'     => $loan->disbursement_date,
            'type'            => 'disbursement',
            'description'     => __('loan::statement.disbursement', [
                'loan_id' => $loan->id,
            ]),
            'principal_delta' => $amount,
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => $amount,
            'reference_type'  => 'Loan',
            'reference_id'    => (int) $loan->id,
            'meta'            => [
                'currency' => $loan->currency,
            ],
        ];
    }

    /**
     * Build an interest-accrual event.
     *
     * @param LoanInterestAccrual $accrual
     * @return array<string, mixed>
     */
    private function interestAccrualEvent(LoanInterestAccrual $accrual): array
    {
        $amount = (string) $accrual->interest_amount;

        return [
            'occurred_at'     => $accrual->recorded_at,
            'type'            => 'interest_accrual',
            'description'     => __('loan::statement.interest_accrual', [
                'installment_id' => $accrual->installment_id,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => $amount,
            'penalty_delta'   => '0.0000',
            'total_delta'     => $amount,
            'reference_type'  => 'LoanInterestAccrual',
            'reference_id'    => (int) $accrual->getKey(),
            'meta'            => [
                'installment_id'       => (int) $accrual->installment_id,
                'accrual_period_key'   => (string) $accrual->accrual_period_key,
                'wallet_obligation_id' => $accrual->wallet_obligation_id !== null
                    ? (int) $accrual->wallet_obligation_id
                    : null,
            ],
        ];
    }

    /**
     * Build a penalty-accrual event.
     *
     * @param LoanPenaltyAccrual $accrual
     * @return array<string, mixed>
     */
    private function penaltyAccrualEvent(LoanPenaltyAccrual $accrual): array
    {
        $amount = (string) $accrual->penalty_amount;

        return [
            'occurred_at'     => $accrual->recorded_at,
            'type'            => 'penalty_accrual',
            'description'     => __('loan::statement.penalty_accrual', [
                'installment_id' => $accrual->installment_id,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => $amount,
            'total_delta'     => $amount,
            'reference_type'  => 'LoanPenaltyAccrual',
            'reference_id'    => (int) $accrual->getKey(),
            'meta'            => [
                'installment_id'       => (int) $accrual->installment_id,
                'accrual_period_key'   => (string) $accrual->accrual_period_key,
                'wallet_obligation_id' => $accrual->wallet_obligation_id !== null
                    ? (int) $accrual->wallet_obligation_id
                    : null,
            ],
        ];
    }

    /**
     * Build a restructure event.
     *
     * @param LoanRestructuring $restructuring
     * @return array<string, mixed>
     */
    private function restructureEvent(LoanRestructuring $restructuring): array
    {
        $principalDelta = bcsub(
            (string) $restructuring->new_principal_amount,
            (string) $restructuring->previous_outstanding_principal,
            self::FINAL_SCALE,
        );

        $interestDelta = bcmul(
            (string) $restructuring->previous_outstanding_interest,
            '-1',
            self::FINAL_SCALE,
        );

        $penaltyDelta = bcmul(
            (string) $restructuring->previous_outstanding_penalty,
            '-1',
            self::FINAL_SCALE,
        );

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $restructuring->restructured_at,
            'type'            => 'restructure',
            'description'     => __('loan::statement.restructure', [
                'restructuring_id' => $restructuring->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => 'LoanRestructure',
            'reference_id'    => (int) $restructuring->getKey(),
            'meta'            => [
                'reason'                   => (string) $restructuring->reason,
                'new_principal_amount'     => (string) $restructuring->new_principal_amount,
                'new_term_months'          => (int) $restructuring->new_term_months,
                'previous_installment_count' => (int) $restructuring->previous_installment_count,
                'new_installment_count'    => (int) $restructuring->new_installment_count,
            ],
        ];
    }

    /**
     * Build a write-off event.
     *
     * @param LoanWriteOff $writeOff
     * @return array<string, mixed>
     */
    private function writeOffEvent(LoanWriteOff $writeOff): array
    {
        $principalDelta = bcmul(
            (string) $writeOff->outstanding_principal_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $interestDelta = bcmul(
            (string) $writeOff->outstanding_interest_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $penaltyDelta = bcmul(
            (string) $writeOff->outstanding_penalty_at_writeoff,
            '-1',
            self::FINAL_SCALE,
        );

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $writeOff->written_off_at,
            'type'            => 'write_off',
            'description'     => __('loan::statement.write_off', [
                'write_off_id' => $writeOff->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => 'LoanWriteOff',
            'reference_id'    => (int) $writeOff->getKey(),
            'meta'            => [
                'reason'          => (string) $writeOff->reason,
                'write_off_amount' => (string) $writeOff->write_off_amount,
            ],
        ];
    }

    /**
     * Build a collateral-release event.
     *
     * @param LoanCollateral $collateral
     * @return array<string, mixed>
     */
    private function collateralReleaseEvent(LoanCollateral $collateral): array
    {
        return [
            'occurred_at'     => $collateral->released_at,
            'type'            => 'collateral_release',
            'description'     => __('loan::statement.collateral_release', [
                'collateral_id' => $collateral->getKey(),
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => '0.0000',
            'reference_type'  => 'LoanCollateral',
            'reference_id'    => (int) $collateral->getKey(),
            'meta'            => [
                'collateral_type' => $collateral->collateral_type->value,
                'release_reason'  => (string) $collateral->release_reason,
            ],
        ];
    }

    /**
     * Build a status-change event.
     *
     * Restored by the B4 revert. Every delta is zero — the state-machine
     * transition does not touch the loan's outstanding projections — but
     * the line is emitted so a reader sees the lifecycle interleaved
     * with the monetary activity.
     *
     * The history row's attributes are read through small private
     * helpers rather than direct property access, because the exact
     * attribute names on the `LoanStatusHistory` model were not
     * verified from pasted evidence during this audit. See the class
     * docblock §"Attribute-name assumption".
     *
     * @param LoanStatusHistory $history
     * @return array<string, mixed>
     */
    private function statusChangeEvent(LoanStatusHistory $history): array
    {
        $from = $this->statusHistoryFromStatus($history);
        $to   = $this->statusHistoryToStatus($history);

        return [
            'occurred_at'     => $this->statusHistoryOccurredAt($history),
            'type'            => 'status_change',
            'description'     => __('loan::statement.status_change', [
                'from' => $from,
                'to'   => $to,
            ]),
            'principal_delta' => '0.0000',
            'interest_delta'  => '0.0000',
            'penalty_delta'   => '0.0000',
            'total_delta'     => '0.0000',
            'reference_type'  => 'LoanStatusHistory',
            'reference_id'    => $this->statusHistoryId($history),
            'meta'            => [
                'from_status' => $from,
                'to_status'   => $to,
                'reason'      => $this->statusHistoryReason($history),
                'actor_id'    => $this->statusHistoryActor($history),
            ],
        ];
    }

    // =========================================================================
    // Private — repayment collection (the Wallet-side reads)
    // =========================================================================

    /**
     * Collect the loan's repayment events.
     *
     * Reads the loan's Wallet-side payments and their allocations using
     * the direct-model query pattern established by A.31 and A.36.
     *
     * @param Loan   $loan
     * @param Wallet $wallet
     * @return list<array<string, mixed>>
     */
    private function collectRepaymentEvents(Loan $loan, Wallet $wallet): array
    {
        /** @var Collection<int, Payment> $payments */
        $payments = Payment::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT)
            ->where('reference_id', (string) $loan->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            return [];
        }

        $paymentIds = $payments->pluck('id')->all();

        /** @var Collection<int, PaymentAllocation> $allocations */
        $allocations = PaymentAllocation::query()
            ->whereIn('payment_id', $paymentIds)
            ->get();

        $allocationsByPayment = $allocations->groupBy('payment_id');

        $obligationIds = $allocations->pluck('obligation_id')->unique()->values()->all();

        /** @var Collection<int, Obligation> $obligations */
        $obligations = $obligationIds === []
            ? new Collection()
            : Obligation::query()->whereIn('id', $obligationIds)->get()->keyBy('id');

        $events = [];

        foreach ($payments as $payment) {
            $events[] = $this->repaymentEvent(
                $payment,
                $allocationsByPayment->get($payment->id, collect()),
                $obligations,
            );
        }

        return $events;
    }

    /**
     * Build a repayment event from a Wallet payment and its allocations.
     *
     * @param Payment                                         $payment
     * @param \Illuminate\Support\Collection<int, PaymentAllocation> $allocations
     * @param \Illuminate\Support\Collection<int, Obligation> $obligations
     * @return array<string, mixed>
     */
    private function repaymentEvent(
        Payment $payment,
        \Illuminate\Support\Collection $allocations,
        \Illuminate\Support\Collection $obligations,
    ): array {
        $principalDelta = '0.0000';
        $interestDelta  = '0.0000';
        $penaltyDelta   = '0.0000';

        $allocationIds = [];
        $allocatedObligationIds = [];

        foreach ($allocations as $allocation) {
            $allocationIds[] = (int) $allocation->getKey();
            $allocatedObligationIds[] = (int) $allocation->obligation_id;

            $obligation = $obligations->get($allocation->obligation_id);

            if ($obligation === null) {
                // An allocation pointing at a missing obligation is a
                // data-integrity defect; the controller emits the
                // allocation's amount as a principal delta as the
                // safest fallback (it will surface in the running
                // balance and can be investigated separately).
                $principalDelta = bcadd(
                    $principalDelta,
                    bcmul((string) $allocation->amount, '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                continue;
            }

            $componentValue = $obligation->component instanceof ObligationComponent
                ? $obligation->component->value
                : (string) $obligation->component;

            $negated = bcmul((string) $allocation->amount, '-1', self::FINAL_SCALE);

            if ($componentValue === ObligationComponent::Principal->value) {
                $principalDelta = bcadd($principalDelta, $negated, self::FINAL_SCALE);
            } elseif ($componentValue === ObligationComponent::Interest->value) {
                $interestDelta = bcadd($interestDelta, $negated, self::FINAL_SCALE);
            } elseif ($componentValue === ObligationComponent::Penalty->value) {
                $penaltyDelta = bcadd($penaltyDelta, $negated, self::FINAL_SCALE);
            } else {
                // Unrecognised component — treat as principal for a
                // visible fallback.
                $principalDelta = bcadd($principalDelta, $negated, self::FINAL_SCALE);
            }
        }

        $totalDelta = bcadd(
            bcadd($principalDelta, $interestDelta, self::FINAL_SCALE),
            $penaltyDelta,
            self::FINAL_SCALE,
        );

        return [
            'occurred_at'     => $payment->received_at,
            'type'            => 'repayment',
            'description'     => __('loan::statement.repayment', [
                'payment_id' => $payment->getKey(),
            ]),
            'principal_delta' => $principalDelta,
            'interest_delta'  => $interestDelta,
            'penalty_delta'   => $penaltyDelta,
            'total_delta'     => $totalDelta,
            'reference_type'  => self::WALLET_REFERENCE_TYPE_LOAN_REPAYMENT,
            'reference_id'    => (int) $payment->getKey(),
            'meta'            => [
                'payment_amount'         => (string) $payment->amount,
                'unapplied_amount'       => (string) $payment->unapplied_amount,
                'allocation_count'       => count($allocationIds),
                'allocation_ids'         => $allocationIds,
                'allocated_obligation_ids' => $allocatedObligationIds,
            ],
        ];
    }

    // =========================================================================
    // Private — chronological sort
    // =========================================================================

    /**
     * Compare two events for the chronological ordering.
     *
     * See the class docblock §"The chronological sort and its
     * tiebreakers".
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return int
     */
    private function compareEvents(array $a, array $b): int
    {
        $aTime = $this->timestampOf($a['occurred_at']);
        $bTime = $this->timestampOf($b['occurred_at']);

        if ($aTime !== $bTime) {
            return $aTime <=> $bTime;
        }

        $aPriority = self::TYPE_PRIORITY[$a['type']] ?? 99;
        $bPriority = self::TYPE_PRIORITY[$b['type']] ?? 99;

        if ($aPriority !== $bPriority) {
            return $aPriority <=> $bPriority;
        }

        return ((int) $a['reference_id']) <=> ((int) $b['reference_id']);
    }

    /**
     * Extract a sortable integer timestamp from a date-like value.
     *
     * @param mixed $value
     * @return int
     */
    private function timestampOf(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && $value !== '') {
            return (int) strtotime($value);
        }

        return 0;
    }

    // =========================================================================
    // Private — running-balance walk
    // =========================================================================

    /**
     * Walk the sorted events, compute running balances, aggregate totals,
     * and produce the statement structure.
     *
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function walkEvents(array $events): array
    {
        $runningPrincipal = '0.0000';
        $runningInterest  = '0.0000';
        $runningPenalty   = '0.0000';

        $totals = $this->zeroTotals();

        $lines = [];

        foreach ($events as $event) {
            $runningPrincipal = bcadd(
                $runningPrincipal,
                (string) $event['principal_delta'],
                self::FINAL_SCALE,
            );
            $runningInterest = bcadd(
                $runningInterest,
                (string) $event['interest_delta'],
                self::FINAL_SCALE,
            );
            $runningPenalty = bcadd(
                $runningPenalty,
                (string) $event['penalty_delta'],
                self::FINAL_SCALE,
            );

            $runningTotal = bcadd(
                bcadd($runningPrincipal, $runningInterest, self::FINAL_SCALE),
                $runningPenalty,
                self::FINAL_SCALE,
            );

            $line = $event;
            $line['running_balance'] = [
                'principal' => $runningPrincipal,
                'interest'  => $runningInterest,
                'penalty'   => $runningPenalty,
                'total'     => $runningTotal,
            ];

            $lines[] = $line;

            $totals = $this->accumulateTotals($totals, $event);
        }

        $opening = $this->zeroBalance();

        $closing = [
            'principal' => $runningPrincipal,
            'interest'  => $runningInterest,
            'penalty'   => $runningPenalty,
            'total'     => bcadd(
                bcadd($runningPrincipal, $runningInterest, self::FINAL_SCALE),
                $runningPenalty,
                self::FINAL_SCALE,
            ),
        ];

        return [
            'period' => [
                'from' => $events[0]['occurred_at'] ?? null,
                'to'   => $events[count($events) - 1]['occurred_at'] ?? null,
            ],
            'opening_balance' => $opening,
            'lines'           => $lines,
            'closing_balance' => $closing,
            'totals'          => $totals,
        ];
    }

    /**
     * Accumulate a single event's contribution to the totals buckets.
     *
     * @param array<string, string> $totals
     * @param array<string, mixed>  $event
     * @return array<string, string>
     */
    private function accumulateTotals(array $totals, array $event): array
    {
        switch ($event['type']) {
            case 'disbursement':
                $totals['total_disbursed'] = bcadd(
                    $totals['total_disbursed'],
                    (string) $event['principal_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'interest_accrual':
                $totals['total_interest_accrued'] = bcadd(
                    $totals['total_interest_accrued'],
                    (string) $event['interest_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'penalty_accrual':
                $totals['total_penalty_accrued'] = bcadd(
                    $totals['total_penalty_accrued'],
                    (string) $event['penalty_delta'],
                    self::FINAL_SCALE,
                );
                break;

            case 'repayment':
                // The repaid amount is the absolute value of the
                // (negative) total_delta.
                $totals['total_repaid'] = bcadd(
                    $totals['total_repaid'],
                    bcmul((string) $event['total_delta'], '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                break;

            case 'restructure':
                $newPrincipal = (string) (
                    $event['meta']['new_principal_amount'] ?? '0.0000'
                );
                $totals['total_restructured'] = bcadd(
                    $totals['total_restructured'],
                    $newPrincipal,
                    self::FINAL_SCALE,
                );
                break;

            case 'write_off':
                $totals['total_written_off'] = bcadd(
                    $totals['total_written_off'],
                    bcmul((string) $event['total_delta'], '-1', self::FINAL_SCALE),
                    self::FINAL_SCALE,
                );
                break;

            default:
                // collateral_release and status_change have no
                // contribution to the totals.
                break;
        }

        return $totals;
    }

    // =========================================================================
    // Private — LoanStatusHistory attribute accessors
    //
    // Small helpers that read the status-history row's attributes through
    // the most likely names, in order. See the class docblock
    // §"Attribute-name assumption".
    // =========================================================================

    /**
     * The history row's occurrence timestamp.
     *
     * @param LoanStatusHistory $history
     * @return mixed  DateTimeInterface, string, or null.
     */
    private function statusHistoryOccurredAt(LoanStatusHistory $history): mixed
    {
        return $history->occurred_at
            ?? $history->changed_at
            ?? $history->transitioned_at
            ?? $history->created_at
            ?? null;
    }

    /**
     * The history row's actor user id.
     *
     * @param LoanStatusHistory $history
     * @return int|null
     */
    private function statusHistoryActor(LoanStatusHistory $history): ?int
    {
        $actor = $history->changed_by
            ?? $history->actor_user_id
            ?? $history->created_by
            ?? null;

        return $actor !== null ? (int) $actor : null;
    }

    /**
     * The history row's primary key.
     *
     * @param LoanStatusHistory $history
     * @return int
     */
    private function statusHistoryId(LoanStatusHistory $history): int
    {
        return (int) $history->getKey();
    }

    /**
     * The history row's `from` status, as a string value.
     *
     * Handles both an enum-cast attribute and a raw string. A null
     * `from_status` (the initialize() row) renders as an empty string,
     * which the resource passes through verbatim.
     *
     * @param LoanStatusHistory $history
     * @return string
     */
    private function statusHistoryFromStatus(LoanStatusHistory $history): string
    {
        $from = $history->from_status ?? null;

        if ($from instanceof LoanStatus) {
            return $from->value;
        }

        return $from === null ? '' : (string) $from;
    }

    /**
     * The history row's `to` status, as a string value.
     *
     * Handles both an enum-cast attribute and a raw string.
     *
     * @param LoanStatusHistory $history
     * @return string
     */
    private function statusHistoryToStatus(LoanStatusHistory $history): string
    {
        $to = $history->to_status ?? null;

        if ($to instanceof LoanStatus) {
            return $to->value;
        }

        return $to === null ? '' : (string) $to;
    }

    /**
     * The history row's reason, if any.
     *
     * @param LoanStatusHistory $history
     * @return string|null
     */
    private function statusHistoryReason(LoanStatusHistory $history): ?string
    {
        $reason = $history->reason ?? null;

        return is_string($reason) ? $reason : null;
    }

    // =========================================================================
    // Private — zero-value constructors
    // =========================================================================

    /**
     * A four-component balance at zero.
     *
     * @return array<string, string>
     */
    private function zeroBalance(): array
    {
        return [
            'principal' => '0.0000',
            'interest'  => '0.0000',
            'penalty'   => '0.0000',
            'total'     => '0.0000',
        ];
    }

    /**
     * The six-bucket totals at zero.
     *
     * @return array<string, string>
     */
    private function zeroTotals(): array
    {
        return [
            'total_disbursed'        => '0.0000',
            'total_interest_accrued' => '0.0000',
            'total_penalty_accrued'  => '0.0000',
            'total_repaid'           => '0.0000',
            'total_restructured'     => '0.0000',
            'total_written_off'      => '0.0000',
        ];
    }
}