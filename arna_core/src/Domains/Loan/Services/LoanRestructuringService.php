<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use MaaleArna\Arna\Core\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\Arna\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\Arna\Core\Domains\Loan\Events\LoanRestructured;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\Arna\Core\Domains\Loan\Support\AmortizationCalculator;
use MaaleArna\Arna\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\Arna\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\Arna\Core\Domains\Wallet\Repositories\WalletRepository;
use MaaleArna\Arna\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\Arna\Core\Policy\Models\VersionedPolicy;

/**
 * LoanRestructuringService
 *
 * Owns the RESTRUCTURE operation of a loan's lifecycle: the renegotiation
 * of a loan's contractual terms. The loan's principal, term, and/or
 * policy version are replaced; the prior schedule's unsettled
 * installments are superseded; the prior loan-linked Wallet obligations
 * are discharged with a `LoanRestructure` reference; a new schedule is
 * generated against the new terms; and the loan transitions through
 * `in_arrears → restructured → active`.
 *
 * =============================================================================
 * Restructuring must preserve transactional ledger and half-up rounding invariants.
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
 * @version 1.0.1
 */
final class LoanRestructuringService
{
    /**
     * The `reference_type` value on loan-linked Wallet obligations.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN = 'Loan';

    /**
     * The `reference_type` value on the discharge entries this service
     * writes to the Wallet.
     *
     * Frozen by CR-2C-WALLET-OBLIGATION-LIFECYCLE-001.
     *
     * @var string
     */
    private const WALLET_REFERENCE_TYPE_LOAN_RESTRUCTURE = 'LoanRestructure';

    /**
     * Monetary scale.
     *
     * @var int
     */
    private const FINAL_SCALE = 4;

    /**
     * Intermediate scale for the amortization computation.
     *
     * Matches the scale used by `AmortizationCalculator` (A.6) and
     * `LoanScheduleService` (A.27).
     *
     * @var int
     */
    private const INTERMEDIATE_SCALE = 20;

    /**
     * The number of months in a year.
     *
     * A9 freezes the periodicity as monthly. The monthly rate is
     * `annual_rate / 12`.
     *
     * @var int
     */
    private const MONTHS_PER_YEAR = 12;

    /**
     * The policy type a loan's policy version must carry.
     *
     * @var string
     */
    private const EXPECTED_POLICY_TYPE = 'loan_product_rules';

    /**
     * Constructor.
     *
     * Five dependencies:
     *
     *   - `LoanStateMachine` — for the two status transitions.
     *
     *   - `LoanScheduleService` — to persist the new schedule at step 12.
     *
     *   - `WalletRepository` — tenant-scoped Wallet resolution.
     *
     *   - `ObligationService` — for the discharge calls.
     *
     *   - `AmortizationCalculator` — to compute the summary values at
     *     step 7 (see the class docblock §"The summary computation at
     *     step 7").
     *
     * @param LoanStateMachine       $stateMachine
     * @param LoanScheduleService    $scheduleService
     * @param WalletRepository       $walletRepository
     * @param ObligationService      $obligationService
     * @param AmortizationCalculator $calculator
     */
    public function __construct(
        private readonly LoanStateMachine $stateMachine,
        private readonly LoanScheduleService $scheduleService,
        private readonly WalletRepository $walletRepository,
        private readonly ObligationService $obligationService,
        private readonly AmortizationCalculator $calculator,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================
    /**
     * Restructure a loan.
     *
     * See the class docblock §"The frozen restructure flow" for the full
     * sequence of operations.
     *
     * @param Loan                 $loan
     * @param string               $newPrincipalAmount  Decimal string, at
     *                                                   scale 4.
     * @param int                  $newTermMonths
     * @param int                  $newPolicyVersionId
     * @param string               $reason              Required,
     *                                                   non-empty.
     * @param CarbonInterface|null $asOf                The restructure
     *                                                   moment; defaults
     *                                                   to `now()`.
     * @param int|null             $actorUserId
     * @return Loan  The refreshed loan in `active` state.
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     * @throws LogicException
     */
    public function restructure(
        Loan $loan,
        string $newPrincipalAmount,
        int $newTermMonths,
        int $newPolicyVersionId,
        string $reason,
        ?CarbonInterface $asOf = null,
        ?int $actorUserId = null,
    ): Loan {
        return DB::transaction(function () use (
            $loan,
            $newPrincipalAmount,
            $newTermMonths,
            $newPolicyVersionId,
            $reason,
            $asOf,
            $actorUserId,
        ): Loan {
            // -----------------------------------------------------------------
            // 1. Validate the inputs.
            // -----------------------------------------------------------------
            $this->assertReasonIsProvided($reason);
            $this->assertNewTermsAreValid($newPrincipalAmount, $newTermMonths);

            // -----------------------------------------------------------------
            // 2. Read the loan (no lock).
            // -----------------------------------------------------------------
            /** @var Loan $current */
            $current = Loan::query()
                ->whereKey($loan->id)
                ->firstOrFail();

            // -----------------------------------------------------------------
            // 3. Assert the loan is restructurable.
            // -----------------------------------------------------------------
            $this->assertLoanIsRestructurable($current);

            // -----------------------------------------------------------------
            // 4. Resolve the borrower and Wallet.
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
                        . 'restructurable. Data-integrity defect.',
                        $borrower->id,
                        $current->id,
                    )
                );
            }

            // -----------------------------------------------------------------
            // 5. Capture the loan's previous terms.
            // -----------------------------------------------------------------
            $previousPolicyVersionId = $current->frozen_policy_version_id;
            $previousOutstandingPrincipal = (string) ($current->outstanding_principal ?? '0');
            $previousOutstandingInterest  = (string) ($current->outstanding_interest  ?? '0');
            $previousOutstandingPenalty   = (string) $current->outstanding_penalty;
            $previousMaturityDate         = $current->maturity_date;

            $previousInstallmentCount = $this->countActiveInstallments($current);

            // -----------------------------------------------------------------
            // 6. Resolve and validate the new policy version.
            // -----------------------------------------------------------------
            $newPolicy = $this->resolveAndValidateNewPolicy(
                $newPolicyVersionId,
                (int) $current->tenant_id,
            );

            // -----------------------------------------------------------------
            // 7. Compute the new schedule's summary values WITHOUT
            //    persisting anything. See the class docblock
            //    §"The summary computation at step 7".
            // -----------------------------------------------------------------
            $moment = $asOf !== null
                ? Carbon::instance($asOf)
                : Carbon::now();

            $summary = $this->computeScheduleSummary(
                principal:        $newPrincipalAmount,
                termMonths:       $newTermMonths,
                policy:           $newPolicy,
                referenceDate:    $moment,
            );

            // -----------------------------------------------------------------
            // 8. INSERT the restructuring row.
            // -----------------------------------------------------------------
            /** @var LoanRestructuring $restructuring */
            $restructuring = LoanRestructuring::create([
                'loan_id'                           => $current->id,
                'previous_policy_version_id'        => $previousPolicyVersionId,
                'previous_outstanding_principal'    => $previousOutstandingPrincipal,
                'previous_outstanding_interest'     => $previousOutstandingInterest,
                'previous_outstanding_penalty'      => $previousOutstandingPenalty,
                'previous_maturity_date'            => $previousMaturityDate,
                'previous_installment_count'        => $previousInstallmentCount,
                'new_policy_version_id'             => $newPolicyVersionId,
                'new_principal_amount'              => $newPrincipalAmount,
                'new_term_months'                   => $newTermMonths,
                'new_maturity_date'                 => $summary['maturity_date'],
                'new_total_scheduled_repayment'     => $summary['total_scheduled_repayment'],
                'new_installment_count'             => $summary['installment_count'],
                'reason'                            => $reason,
                'restructured_at'                   => $moment,
                'restructured_by'                   => $actorUserId,
            ]);

            $restructuringId = (int) $restructuring->getKey();

            // -----------------------------------------------------------------
            // 9. Discharge every outstanding Wallet obligation linked to
            //    the loan.
            // -----------------------------------------------------------------
            $this->dischargeLoanObligations(
                wallet:        $wallet,
                loan:          $current,
                restructuringId: $restructuringId,
                reason:        $reason,
                actorUserId:   $actorUserId,
            );

            // -----------------------------------------------------------------
            // 10. Supersede the prior schedule's unsettled installments.
            // -----------------------------------------------------------------
            $this->supersedeUnsettledInstallments($current);

            // -----------------------------------------------------------------
            // 11. Overwrite the loan's terms with the new values.
            //
            // The schedule service at step 12 reads these fields, so they
            // must be updated first.
            // -----------------------------------------------------------------
            $current->principal_amount        = $newPrincipalAmount;
            $current->term_months             = $newTermMonths;
            $current->frozen_policy_version_id = $newPolicyVersionId;
            $current->save();

            // -----------------------------------------------------------------
            // 12. Generate the new installment schedule.
            //
            // The schedule service updates the loan's summary columns
            // (`monthly_installment_amount`, `total_scheduled_interest`,
            // `total_scheduled_repayment`, `maturity_date`). The values
            // it writes must agree with the summary computed at step 7;
            // they do, because the two computations use identical inputs.
            // -----------------------------------------------------------------
            $this->scheduleService->generate($current, $moment);

            $current->refresh();

            // -----------------------------------------------------------------
            // 13. Set the loan's three outstanding projections to
            //     reflect the NEW contractual debt.
            //
            // The prior loan-linked obligations were discharged at step
            // 9; the new schedule's interest obligations will be
            // recorded by `LoanInterestAccrualService` (A.32) as each
            // installment's due date arrives, at which point the loan's
            // projections will be adjusted incrementally. Immediately
            // after the restructure, the loan's outstanding principal
            // equals the newly-agreed principal, and interest and
            // penalty are zero.
            // -----------------------------------------------------------------
            $current->outstanding_principal = $newPrincipalAmount;
            $current->outstanding_interest  = '0.0000';
            $current->outstanding_penalty   = '0.0000';
            $current->save();

            // -----------------------------------------------------------------
            // 14. Transition `in_arrears → restructured`, dispatching
            //     `LoanRestructured` after commit.
            // -----------------------------------------------------------------
            $event = new LoanRestructured(
                $current,
                $restructuring,
                $actorUserId,
            );

            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Restructured,
                reason: $reason,
                actorUserId: $actorUserId,
                afterCommitEvent: $event,
            );

            // -----------------------------------------------------------------
            // 15. Transition `restructured → active`, no event.
            //
            // The `restructured` state is transient: it settles into
            // `active` in the same transaction. The second transition
            // carries no actor (it is automatic) and no event (the
            // audit-relevant milestone was the first transition).
            // -----------------------------------------------------------------
            $current = $this->stateMachine->transition(
                loan: $current,
                nextStatus: LoanStatus::Active,
                reason: null,
                actorUserId: null,
                afterCommitEvent: null,
            );

            $current->refresh();

            return $current;
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert the reason is non-empty.
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
                    'A restructure reason is required. Supply a non-empty '
                    . 'explanation of why the loan is being restructured.',
                ],
            ]);
        }
    }

    /**
     * Assert the new terms are well-formed.
     *
     * @param string $newPrincipalAmount
     * @param int    $newTermMonths
     * @return void
     *
     * @throws ValidationException
     */
    private function assertNewTermsAreValid(
        string $newPrincipalAmount,
        int $newTermMonths,
    ): void {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $newPrincipalAmount)) {
            throw ValidationException::withMessages([
                'new_principal_amount' => [
                    'The new principal amount must be a positive decimal '
                    . 'string with at most four decimal places.',
                ],
            ]);
        }

        if (bccomp($newPrincipalAmount, '0', self::FINAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'new_principal_amount' => [
                    'The new principal amount must be strictly greater '
                    . 'than zero.',
                ],
            ]);
        }

        if ($newTermMonths < 1) {
            throw ValidationException::withMessages([
                'new_term_months' => [
                    'The new term must be at least 1 month.',
                ],
            ]);
        }
    }

    /**
     * Assert the loan is in a restructurable state.
     *
     * Eligible state: `in_arrears`. See the class docblock §"Scope".
     *
     * @param Loan $loan
     * @return void
     *
     * @throws ValidationException
     */
    private function assertLoanIsRestructurable(Loan $loan): void
    {
        if ($loan->status === LoanStatus::InArrears) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'A loan in status "%s" cannot be restructured. The '
                    . 'frozen transition matrix permits only '
                    . '"in_arrears → restructured".',
                    $loan->status->value,
                ),
            ],
        ]);
    }

    /**
     * Resolve and validate the new policy version.
     *
     * The version must exist within the loan's tenant and carry the
     * expected `policy_type`. See the class docblock §"The new policy
     * version validation" for the deferred same-product check.
     *
     * @param int $newPolicyVersionId
     * @param int $tenantId
     * @return VersionedPolicy
     *
     * @throws ValidationException
     */
    private function resolveAndValidateNewPolicy(
        int $newPolicyVersionId,
        int $tenantId,
    ): VersionedPolicy {
        /** @var VersionedPolicy|null $policy */
        $policy = VersionedPolicy::query()
            ->whereKey($newPolicyVersionId)
            ->first();

        if ($policy === null) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'No policy version exists with id=%d.',
                        $newPolicyVersionId,
                    ),
                ],
            ]);
        }

        if ((string) $policy->policy_type !== self::EXPECTED_POLICY_TYPE) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'Policy version id=%d is of type "%s"; the '
                        . 'restructure requires a policy of type "%s".',
                        $newPolicyVersionId,
                        $policy->policy_type,
                        self::EXPECTED_POLICY_TYPE,
                    ),
                ],
            ]);
        }

        // The tenant filter is a soft check: the tenant on a
        // VersionedPolicy is expected to be non-null in production.
        // A null tenant on the policy is treated as "shared across
        // tenants" and accepted; a mismatched non-null tenant is
        // rejected.
        if (
            isset($policy->tenant_id)
            && $policy->tenant_id !== null
            && (int) $policy->tenant_id !== $tenantId
        ) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'Policy version id=%d belongs to a different '
                        . 'tenant. Cross-tenant policy references are '
                        . 'forbidden.',
                        $newPolicyVersionId,
                    ),
                ],
            ]);
        }

        return $policy;
    }

    // =========================================================================
    // Private — previous-state capture
    // =========================================================================

    /**
     * Count the loan's active (non-superseded) installments.
     *
     * @param Loan $loan
     * @return int
     */
    private function countActiveInstallments(Loan $loan): int
    {
        return LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [InstallmentStatus::Superseded->value])
            ->count();
    }

    // =========================================================================
    // Private — new schedule summary computation
    // =========================================================================

    /**
     * Compute the new schedule's summary values.
     *
     * See the class docblock §"The summary computation at step 7" for the
     * rationale on computing the summary before the schedule is persisted.
     *
     * @param string          $principal
     * @param int             $termMonths
     * @param VersionedPolicy $policy
     * @param CarbonInterface $referenceDate
     * @return array{maturity_date: string, total_scheduled_repayment: string, installment_count: int}
     *
     * @throws LogicException
     */
    private function computeScheduleSummary(
        string $principal,
        int $termMonths,
        VersionedPolicy $policy,
        CarbonInterface $referenceDate,
    ): array {
        $payload = $policy->payload;

        if (!is_array($payload)) {
            throw new LogicException(
                sprintf(
                    'Policy version id=%d has a non-array payload.',
                    $policy->id,
                ),
            );
        }

        $annualRate     = $this->extractAnnualRate($payload);
        $principalGrace = $this->extractPrincipalGrace($payload);

        if ($principalGrace >= $termMonths) {
            throw ValidationException::withMessages([
                'new_policy_version_id' => [
                    sprintf(
                        'The policy\'s principal grace period (%d months) '
                        . 'must be strictly less than the new term '
                        . '(%d months).',
                        $principalGrace,
                        $termMonths,
                    ),
                ],
            ]);
        }

        $amortizingTerm = $termMonths - $principalGrace;

        $amortizing = $this->calculator->schedule(
            $principal,
            $annualRate,
            $amortizingTerm,
        );

        $totalPrincipal = '0.0000';
        $totalInterest  = '0.0000';

        if ($principalGrace > 0) {
            $monthlyRate = bcdiv(
                $annualRate,
                (string) self::MONTHS_PER_YEAR,
                self::INTERMEDIATE_SCALE,
            );

            $graceInterest = $this->halfUp(
                bcmul($principal, $monthlyRate, self::INTERMEDIATE_SCALE),
                self::FINAL_SCALE,
            );

            for ($i = 0; $i < $principalGrace; $i++) {
                $totalInterest = bcadd($totalInterest, $graceInterest, self::FINAL_SCALE);
            }
        }

        foreach ($amortizing['installments'] as $inst) {
            $totalPrincipal = bcadd(
                $totalPrincipal,
                (string) $inst['expected_principal'],
                self::FINAL_SCALE,
            );
            $totalInterest = bcadd(
                $totalInterest,
                (string) $inst['expected_interest'],
                self::FINAL_SCALE,
            );
        }

        $totalRepayment = bcadd($totalPrincipal, $totalInterest, self::FINAL_SCALE);

        $maturityDate = Carbon::instance($referenceDate)
            ->copy()
            ->addMonthsNoOverflow($termMonths)
            ->toDateString();

        return [
            'maturity_date'             => $maturityDate,
            'total_scheduled_repayment' => $totalRepayment,
            'installment_count'         => $termMonths,
        ];
    }

    /**
     * Extract the annual rate from a policy payload.
     *
     * @param array<string, mixed> $payload
     * @return string
     *
     * @throws LogicException
     */
    private function extractAnnualRate(array $payload): string
    {
        $interestSection = $payload[LoanPolicyKeys::INTEREST_SECTION] ?? null;

        if (!is_array($interestSection)) {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the "%s" '
                    . 'section.',
                    LoanPolicyKeys::INTEREST_SECTION,
                ),
            );
        }

        $annualRate = $interestSection[LoanPolicyKeys::INTEREST_ANNUAL_RATE] ?? null;

        if (!is_string($annualRate) || $annualRate === '') {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the '
                    . '"%s.%s" value.',
                    LoanPolicyKeys::INTEREST_SECTION,
                    LoanPolicyKeys::INTEREST_ANNUAL_RATE,
                ),
            );
        }

        return $annualRate;
    }

    /**
     * Extract the principal grace period from a policy payload.
     *
     * @param array<string, mixed> $payload
     * @return int
     */
    private function extractPrincipalGrace(array $payload): int
    {
        $termSection = $payload[LoanPolicyKeys::TERM_SECTION] ?? null;

        if (!is_array($termSection)) {
            return 0;
        }

        $value = $termSection[LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Round a decimal string to a target scale using half-up rounding.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * This method is a byte-for-byte copy of
     * `AmortizationCalculator::halfUp()` (A.6, patched in the same
     * deployment as v1.0.1) and `LoanScheduleService::halfUp()`
     * (A.27, patched in the same deployment as v1.0.1).
     *
     * The previous construction of the half-ULP was
     * `'0.' . str_repeat('0', $scale - 1) . '5'`, which at `scale = 4`
     * produces `'0.0005'` (the half-ULP of scale 3, not scale 4). The
     * corrected construction is `'0.' . str_repeat('0', $scale) . '5'`,
     * which at `scale = 4` produces `'0.00005'`. See the
     * `AmortizationCalculator` v1.0.1 revision note for a worked
     * example.
     *
     * All four copies MUST be kept in lockstep pending a follow-up
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
    // Private — discharge and supersede
    // =========================================================================
    /**
     * Discharge every outstanding Wallet obligation linked to the loan.
     *
     * See the class docblock §"Discharging the loan's Wallet
     * obligations".
     *
     * @param Wallet $wallet
     * @param Loan     $loan
     * @param int      $restructuringId
     * @param string   $reason
     * @param int|null $actorUserId
     * @return void
     */
    private function dischargeLoanObligations(
        Wallet $wallet,
        Loan $loan,
        int $restructuringId,
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
                wallet:        $wallet,
                obligation:    $obligation,
                entryType:     WalletEntryType::ObligationCancelled,
                reason:        $reason,
                referenceType: self::WALLET_REFERENCE_TYPE_LOAN_RESTRUCTURE,
                referenceId:   (string) $restructuringId,
                createdBy:     $actorUserId,
            );
        }
    }

    /**
     * Supersede the loan's unsettled installments.
     *
     * See the class docblock §"Superseding the prior schedule's
     * unsettled installments".
     *
     * The query uses an EXPLICIT exclusion list — `Settled` and
     * `Superseded` — rather than relying on
     * `InstallmentStatus::unsettledValues()`. The helper's membership
     * is not guaranteed to align with the frozen contract of this step
     * (in the current enum it includes `Settled`, which would violate
     * the preservation rule). The explicit list keeps the service
     * correct regardless of any future revision to the helper.
     *
     * @param Loan $loan
     * @return void
     */
    private function supersedeUnsettledInstallments(Loan $loan): void
    {
        LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [
                InstallmentStatus::Settled->value,
                InstallmentStatus::Superseded->value,
            ])
            ->update([
                'status' => InstallmentStatus::Superseded->value,
            ]);
    }
}