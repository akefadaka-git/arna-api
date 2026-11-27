<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\Arna\Core\Domains\Loan\Models\Loan;
use MaaleArna\Arna\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\Arna\Core\Domains\Loan\Support\AmortizationCalculator;
use MaaleArna\Arna\Core\Domains\Loan\Support\LoanPolicyKeys;

/**
 * LoanScheduleService
 *
 * Generates and persists a loan's contractual amortization schedule.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * This service owns exactly one operation: given a loan and a reference
 * date, produce the schedule's installment rows and update the loan's
 * schedule-summary columns. It is the SOLE writer of:
 *
 *   - `loan_installments` rows for a new schedule;
 *   - `loans.monthly_installment_amount`;
 *   - `loans.total_scheduled_interest`;
 *   - `loans.total_scheduled_repayment`;
 *   - `loans.maturity_date`.
 *
 * It is NOT the sole writer of `loan_installments.settled_*` or
 * `loan_installments.status` — those are updated later by the services
 * that drive payments, arrears, and restructures (see A.19's class
 * docblock §"Partial mutability"). It is NOT the writer of the loan's
 * outstanding projections or its lifecycle status; those belong to the
 * disbursement, repayment, arrears, restructure, and write-off services.
 *
 * =============================================================================
 * Monetary calculations use half-up rounding at the configured scale.
 * @package MaaleArna\Arna\Core\Domains\Loan\Services
 * @version 1.0.1
 */
final class LoanScheduleService
{
    /**
     * The scale at which monetary values are expressed throughout the
     * service.
     *
     * Matches the DECIMAL(20,4) columns on `loans` and
     * `loan_installments` and the platform-wide monetary precision.
     */
    private const FINAL_SCALE = 4;

    /**
     * The scale at which intermediate values are held during the grace
     * interest computation.
     *
     * Matches the intermediate scale used by `AmortizationCalculator`
     * (A.6). The choice of 20 is dictated by the same rationale: enough
     * headroom that no digit is lost before the final rounding.
     */
    private const INTERMEDIATE_SCALE = 20;

    /**
     * The divisor applied to the annual rate to derive the monthly rate.
     *
     * A9 freezes the periodicity as monthly. The monthly rate is
     * `annual_rate / 12`.
     */
    private const MONTHS_PER_YEAR = 12;

    /**
     * The policy type that a loan's frozen policy version must carry.
     *
     * The FK on `loans.frozen_policy_version_id` should always point at a
     * policy of this type. A mismatch is a `LogicException` (see the
     * class docblock §"Policy payload reading").
     */
    private const EXPECTED_POLICY_TYPE = 'loan_product_rules';

    /**
     * Constructor.
     *
     * One dependency: the amortization calculator. The calculator is a
     * stateless utility with a no-argument constructor, so the container
     * resolves it by reflection with no explicit binding required.
     *
     * @param AmortizationCalculator $calculator
     */
    public function __construct(
        private readonly AmortizationCalculator $calculator,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate and persist a new schedule for a loan.
     *
     * The method:
     *
     *   1. Asserts the caller is inside a transaction.
     *   2. Asserts the loan is persisted.
     *   3. Resolves and validates the loan's frozen policy version.
     *   4. Extracts the annual rate and grace periods from the policy
     *      payload.
     *   5. Validates the loan's principal and term against the
     *      calculator's preconditions.
     *   6. Computes the amortizing schedule via the calculator.
     *   7. Prepends principal-grace installments (if any).
     *   8. Computes due dates from the reference date.
     *   9. Assigns monotonic installment numbers.
     *  10. Inserts the `loan_installments` rows.
     *  11. Updates the loan's summary columns and `maturity_date`.
     *  12. Returns the created rows.
     *
     * @param Loan              $loan           The loan to schedule.
     * @param CarbonInterface   $referenceDate  The date from which due
     *                                           dates are computed. The
     *                                           first installment is due
     *                                           one month after this date.
     * @return Collection<int, LoanInstallment>  The created rows, ordered
     *                                            by installment_number.
     *
     * @throws LogicException       When called outside a transaction, with
     *                              an unpersisted loan, or with a policy
     *                              payload that is malformed.
     * @throws ValidationException  When the loan's principal or term is
     *                              invalid, when grace periods are
     *                              inconsistent, or when interest grace is
     *                              non-zero (deferred).
     */
    public function generate(
        Loan $loan,
        CarbonInterface $referenceDate,
    ): Collection {
        $this->assertInTransaction();

        if (!$loan->exists) {
            throw new LogicException(
                'LoanScheduleService::generate() requires a persisted loan. '
                . 'A transient model has no id and cannot be the subject of '
                . 'installment rows.'
            );
        }

        // ---------------------------------------------------------------------
        // 1. Resolve and validate the frozen policy payload.
        // ---------------------------------------------------------------------
        $payload = $this->resolvePolicyPayload($loan);

        $annualRate     = $this->extractAnnualRate($payload);
        $principalGrace = $this->extractPrincipalGrace($payload);
        $interestGrace  = $this->extractInterestGrace($payload);

        $this->assertInterestGraceIsZero($interestGrace);

        // ---------------------------------------------------------------------
        // 2. Validate the loan's principal and term against the
        //    calculator's preconditions. The calculator throws
        //    InvalidArgumentException on violation; this service
        //    pre-validates so that the failure mode is
        //    ValidationException (a domain-meaningful error) rather than
        //    a low-level argument error.
        // ---------------------------------------------------------------------
        $this->assertPrincipalIsPositive((string) $loan->principal_amount);
        $this->assertTermIsPositive($loan->term_months);

        $amortizingTerm = $loan->term_months - $principalGrace;

        $this->assertPrincipalGraceLeavesAmortizingTerm($principalGrace, $loan->term_months, $amortizingTerm);

        // ---------------------------------------------------------------------
        // 3. Compute the amortizing schedule.
        //
        // When principalGrace is 0 (the common case), the amortizing term
        // equals the loan's term and the calculator produces exactly the
        // loan's schedule with no grace prepended.
        // ---------------------------------------------------------------------
        $amortizing = $this->calculator->schedule(
            $loan->principal_amount,
            $annualRate,
            $amortizingTerm,
        );

        // ---------------------------------------------------------------------
        // 4. Build the full installment list (grace installments
        //    prepended when applicable).
        // ---------------------------------------------------------------------
        $installments = $this->buildInstallments(
            $amortizing,
            (string) $loan->principal_amount,
            $annualRate,
            $principalGrace,
        );

        $installmentCount = count($installments);

        // ---------------------------------------------------------------------
        // 5. Compute due dates and the monotonic starting number.
        // ---------------------------------------------------------------------
        $dueDates      = $this->computeDueDates($referenceDate, $installmentCount);
        $startNumber   = $this->nextInstallmentNumber($loan);

        // ---------------------------------------------------------------------
        // 6. Insert the installment rows.
        //
        // `LoanInstallment`'s `$fillable` list includes only the columns
        // that the schedule writer legitimately supplies; the migration
        // A.10 provides column defaults for `settled_principal`,
        // `settled_interest`, `settled_amount`, and `status`. Eloquent
        // omits unset columns from the INSERT, so the defaults apply.
        // ---------------------------------------------------------------------
        $rows = [];
        foreach ($installments as $index => $inst) {
            $rows[] = LoanInstallment::create([
                'loan_id'            => $loan->id,
                'installment_number' => $startNumber + $index,
                'due_date'           => $dueDates[$index],
                'expected_principal' => $inst['expected_principal'],
                'expected_interest'  => $inst['expected_interest'],
                'expected_total'     => $inst['expected_total'],
            ]);
        }

        // ---------------------------------------------------------------------
        // 7. Update the loan's summary columns and maturity date.
        //
        // The three summary columns are persisted, transactional
        // projections of the schedule. The maturity date is the final
        // installment's due date.
        //
        // `monthly_installment_amount` reflects the AMORTIZING
        // installment amount — the M computed by the calculator. Grace
        // installments have a different (smaller) amount, because they
        // carry only interest. This is documented in the class docblock
        // §"Grace periods".
        // ---------------------------------------------------------------------
        [$totalPrincipal, $totalInterest, $totalRepayment] =
            $this->summarize($installments);

        $finalDueDate = $dueDates[$installmentCount - 1];

        $loan->monthly_installment_amount = $amortizing['monthly_payment'];
        $loan->total_scheduled_interest   = $totalInterest;
        $loan->total_scheduled_repayment  = $totalRepayment;
        $loan->maturity_date              = $finalDueDate;
        $loan->save();

        return new Collection($rows);
    }

    // =========================================================================
    // Private — policy payload resolution
    // =========================================================================

    /**
     * Resolve and validate the loan's frozen policy payload.
     *
     * The method:
     *
     *   1. Loads the `policyVersion` relation if not already loaded.
     *   2. Asserts the relation is present (the loan must have been
     *      approved).
     *   3. Asserts the policy's `policy_type` is the expected
     *      `loan_product_rules` (see the class docblock §"Policy payload
     *      reading").
     *   4. Returns the payload array.
     *
     * @param Loan $loan
     * @return array<string, mixed>
     *
     * @throws LogicException  When the loan has no frozen policy version
     *                         or the referenced policy is of the wrong
     *                         type.
     */
    private function resolvePolicyPayload(Loan $loan): array
    {
        $loan->loadMissing('policyVersion');

        $policy = $loan->policyVersion;

        if ($policy === null) {
            throw new LogicException(
                sprintf(
                    'Loan id=%d has no frozen policy version. '
                    . 'A schedule cannot be generated without an '
                    . 'approved policy. This indicates a defect in the '
                    . 'approval flow (LoanApprovalService, A.25): every '
                    . 'approved loan must have frozen_policy_version_id '
                    . 'set before disbursement or restructure.',
                    $loan->id,
                ),
            );
        }

        if ($policy->policy_type !== self::EXPECTED_POLICY_TYPE) {
            throw new LogicException(
                sprintf(
                    'Loan id=%d has frozen policy version id=%d of type '
                    . '"%s"; the schedule service expects type "%s". '
                    . 'This indicates a defect in the product-creation '
                    . 'or approval flow: the FK should point at a '
                    . 'loan_product_rules policy.',
                    $loan->id,
                    $policy->id,
                    $policy->policy_type,
                    self::EXPECTED_POLICY_TYPE,
                ),
            );
        }

        $payload = $policy->payload;

        if (!is_array($payload)) {
            throw new LogicException(
                sprintf(
                    'Policy version id=%d has a non-array payload. '
                    . 'The loan_product_rules payload must be a JSON '
                    . 'object.',
                    $policy->id,
                ),
            );
        }

        return $payload;
    }

    /**
     * Extract the annual rate from the policy payload.
     *
     * The rate is expected at:
     *
     *     payload['interest']['annual_rate']
     *
     * and must be a decimal-fraction string (for example, `"0.12"` for
     * 12%). The exact shape is validated against the same regex used by
     * `AmortizationCalculator` (A.6).
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
                    . 'section, or the section is not an object.',
                    LoanPolicyKeys::INTEREST_SECTION,
                ),
            );
        }

        $annualRate = $interestSection[LoanPolicyKeys::INTEREST_ANNUAL_RATE] ?? null;

        if (!is_string($annualRate) || $annualRate === '') {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload is missing the "%s.%s" '
                    . 'key, or the value is not a non-empty string.',
                    LoanPolicyKeys::INTEREST_SECTION,
                    LoanPolicyKeys::INTEREST_ANNUAL_RATE,
                ),
            );
        }

        if (!preg_match('/^\d+(\.\d{1,20})?$/', $annualRate)) {
            throw new LogicException(
                sprintf(
                    'The "%s.%s" value "%s" is not a non-negative decimal '
                    . 'string with at most 20 decimal places.',
                    LoanPolicyKeys::INTEREST_SECTION,
                    LoanPolicyKeys::INTEREST_ANNUAL_RATE,
                    $annualRate,
                ),
            );
        }

        return $annualRate;
    }

    /**
     * Extract the principal grace period, in months.
     *
     * The value is expected at:
     *
     *     payload['term']['principal_grace_months']
     *
     * and defaults to 0 when absent. A non-integer value is a
     * `LogicException`.
     *
     * @param array<string, mixed> $payload
     * @return int
     *
     * @throws LogicException
     */
    private function extractPrincipalGrace(array $payload): int
    {
        return $this->extractIntegerFromTermSection(
            $payload,
            LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS,
        );
    }

    /**
     * Extract the interest grace period, in months.
     *
     * The value is expected at:
     *
     *     payload['term']['interest_grace_months']
     *
     * and defaults to 0 when absent. A non-integer value is a
     * `LogicException`.
     *
     * Phase 2C does not implement interest grace; the caller
     * (`generate()`) throws `ValidationException` if the extracted value
     * is non-zero. See the class docblock §"Grace periods".
     *
     * @param array<string, mixed> $payload
     * @return int
     *
     * @throws LogicException
     */
    private function extractInterestGrace(array $payload): int
    {
        return $this->extractIntegerFromTermSection(
            $payload,
            LoanPolicyKeys::TERM_INTEREST_GRACE_MONTHS,
        );
    }

    /**
     * Extract an integer value from the term section, defaulting to 0
     * when absent.
     *
     * Shared helper for both grace-period keys. The term section may be
     * absent entirely (in which case both grace periods default to 0); if
     * present, the value at the given key must be an integer or an
     * integer-shaped string.
     *
     * @param array<string, mixed> $payload
     * @param string               $key
     * @return int
     *
     * @throws LogicException
     */
    private function extractIntegerFromTermSection(array $payload, string $key): int
    {
        $termSection = $payload[LoanPolicyKeys::TERM_SECTION] ?? null;

        if ($termSection === null) {
            // The whole term section is absent; every key defaults to
            // its zero value.
            return 0;
        }

        if (!is_array($termSection)) {
            throw new LogicException(
                sprintf(
                    'The loan_product_rules payload contains a "%s" value '
                    . 'that is not an object.',
                    LoanPolicyKeys::TERM_SECTION,
                ),
            );
        }

        $value = $termSection[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value)) {
            return (int) $value;
        }

        throw new LogicException(
            sprintf(
                'The "%s.%s" value must be a non-negative integer; got '
                . 'a value of type %s.',
                LoanPolicyKeys::TERM_SECTION,
                $key,
                get_debug_type($value),
            ),
        );
    }

    // =========================================================================
    // Private — input validation
    // =========================================================================

    /**
     * Assert that the caller is inside a database transaction.
     *
     * @return void
     *
     * @throws LogicException
     */
    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'LoanScheduleService::generate() must be called from '
                . 'inside a database transaction. The schedule rows and '
                . 'the loan summary columns must be written atomically '
                . 'with the caller\'s other writes (the Wallet principal '
                . 'obligation at disbursement; the Wallet discharge '
                . 'entries at restructure).'
            );
        }
    }

    /**
     * Assert that the loan's principal is strictly positive.
     *
     * @param string $principal
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPrincipalIsPositive(string $principal): void
    {
        if (bccomp($principal, '0', self::FINAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'principal_amount' => [
                    'The loan principal must be strictly greater than zero '
                    . 'for a schedule to be generated.',
                ],
            ]);
        }
    }

    /**
     * Assert that the loan's term is strictly positive.
     *
     * @param int $termMonths
     * @return void
     *
     * @throws ValidationException
     */
    private function assertTermIsPositive(int $termMonths): void
    {
        if ($termMonths < 1) {
            throw ValidationException::withMessages([
                'term_months' => [
                    'The loan term must be at least 1 month for a schedule '
                    . 'to be generated.',
                ],
            ]);
        }
    }

    /**
     * Assert that the principal grace period leaves a positive
     * amortizing term.
     *
     * @param int $principalGrace
     * @param int $termMonths
     * @param int $amortizingTerm
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPrincipalGraceLeavesAmortizingTerm(
        int $principalGrace,
        int $termMonths,
        int $amortizingTerm,
    ): void {
        if ($amortizingTerm < 1) {
            throw ValidationException::withMessages([
                'principal_grace_months' => [
                    sprintf(
                        'The principal grace period (%d months) must be '
                        . 'strictly less than the loan term (%d months), '
                        . 'so that at least one installment amortizes the '
                        . 'principal.',
                        $principalGrace,
                        $termMonths,
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert that interest grace is zero.
     *
     * Phase 2C does not implement interest grace. See the class docblock
     * §"Grace periods" for the rationale.
     *
     * @param int $interestGrace
     * @return void
     *
     * @throws ValidationException
     */
    private function assertInterestGraceIsZero(int $interestGrace): void
    {
        if ($interestGrace <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'interest_grace_months' => [
                sprintf(
                    'Interest grace (%d months) is not supported in '
                    . 'Phase 2C. The interest grace feature requires an '
                    . 'explicit policy decision about accrual '
                    . 'capitalization that the current register does not '
                    . 'freeze; a future Change Request will implement it. '
                    . 'Set interest_grace_months to 0 for the product to '
                    . 'be schedulable.',
                    $interestGrace,
                ),
            ],
        ]);
    }

    // =========================================================================
    // Private — schedule assembly
    // =========================================================================

    /**
     * Build the full installment list, prepending grace installments when
     * a principal grace period is configured.
     *
     * The output is a plain list of associative arrays with the three
     * expected monetary values already computed as decimal strings at
     * scale 4. Each entry has the shape:
     *
     *     [
     *         'expected_principal' => string,
     *         'expected_interest'  => string,
     *         'expected_total'     => string,
     *     ]
     *
     * Grace installments (when any) have `expected_principal = "0.0000"`
     * and `expected_interest` equal to the half-up rounding of
     * `principal · monthly_rate` at scale 4. Every grace installment
     * carries the same interest value, because the principal is not
     * amortized during grace and the monthly rate is constant.
     *
     * @param array<string, mixed> $amortizing      The calculator output.
     * @param string               $principal       The loan principal.
     * @param string               $annualRate      The annual rate.
     * @param int                  $principalGrace  Number of grace months.
     * @return list<array{expected_principal: string, expected_interest: string, expected_total: string}>
     */
    private function buildInstallments(
        array $amortizing,
        string $principal,
        string $annualRate,
        int $principalGrace,
    ): array {
        $installments = [];

        if ($principalGrace > 0) {
            $graceInterest = $this->computeGraceInterest($principal, $annualRate);

            for ($i = 0; $i < $principalGrace; $i++) {
                $installments[] = [
                    'expected_principal' => '0.0000',
                    'expected_interest'  => $graceInterest,
                    'expected_total'     => $graceInterest,
                ];
            }
        }

        foreach ($amortizing['installments'] as $inst) {
            $installments[] = [
                'expected_principal' => (string) $inst['expected_principal'],
                'expected_interest'  => (string) $inst['expected_interest'],
                'expected_total'     => (string) $inst['expected_total'],
            ];
        }

        return $installments;
    }

    /**
     * Compute the interest carried by a single grace installment.
     *
     * The value is `halfUp(principal · annual_rate / 12, 4)`.
     *
     * This helper duplicates the half-up rounding logic declared on
     * `AmortizationCalculator` (A.6) as a private method. The duplication
     * is deliberate: the calculator's method is private, and exposing it
     * publicly would widen the calculator's API surface for a single
     * internal use. The two implementations are identical byte-for-byte;
     * a future CR that changes one MUST change the other (and would
     * likely extract the shared helper at that point).
     *
     * See the class docblock §"Revision history" (v1.0.1) for the
     * off-by-one that was present in this copy until v1.0.1.
     *
     * @param string $principal
     * @param string $annualRate
     * @return string  Decimal string at scale 4.
     */
    private function computeGraceInterest(string $principal, string $annualRate): string
    {
        $monthlyRate = bcdiv(
            $annualRate,
            (string) self::MONTHS_PER_YEAR,
            self::INTERMEDIATE_SCALE,
        );

        $interestRaw = bcmul(
            $principal,
            $monthlyRate,
            self::INTERMEDIATE_SCALE,
        );

        return $this->halfUp($interestRaw, self::FINAL_SCALE);
    }

    /**
     * Round a decimal string to a target scale using half-up rounding.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * This method is a byte-for-byte copy of
     * `AmortizationCalculator::halfUp()` (A.6, patched in the same
     * deployment as v1.0.1) and `LoanArrearsService::halfUp()` (A.33,
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
    // Private — due dates, numbering, summary
    // =========================================================================

    /**
     * Compute the due dates for a schedule of the given length.
     *
     * The first installment is due one month after the reference date;
     * the Nth is due N months after. Month arithmetic uses Carbon's
     * `addMonthsNoOverflow()` to clamp to the last day of the target
     * month when needed (see the class docblock §"Due-date computation").
     *
     * The method returns an array of date strings in the format
     * `Y-m-d`, suitable for the migration A.10's `date` column.
     *
     * @param CarbonInterface $referenceDate
     * @param int             $count
     * @return list<string>
     */
    private function computeDueDates(CarbonInterface $referenceDate, int $count): array
    {
        $start = Carbon::instance($referenceDate);

        $dates = [];

        for ($i = 1; $i <= $count; $i++) {
            $dates[] = $start->copy()->addMonthsNoOverflow($i)->toDateString();
        }

        return $dates;
    }

    /**
     * Compute the starting installment number for a new schedule.
     *
     * Returns `MAX(installment_number) + 1` for the loan's existing
     * installments, or 1 when the loan has none. The check runs against
     * the loan's ENTIRE installment population, including superseded
     * rows, so the numbering is strictly increasing across the loan's
     * lifetime.
     *
     * The uniqueness of the resulting numbers is guaranteed by the
     * monotonic computation combined with the migration A.10's UNIQUE
     * constraint on `(loan_id, installment_number)`. Two concurrent
     * schedule generations against the same loan would race on the
     * `MAX()` read; the UNIQUE constraint would reject the loser. In
     * practice the caller holds the loan's transaction, so the race
     * window is closed by the caller's own row locks.
     *
     * @param Loan $loan
     * @return int
     */
    private function nextInstallmentNumber(Loan $loan): int
    {
        /** @var int|string|null $maxNumber */
        $maxNumber = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->max('installment_number');

        return ((int) ($maxNumber ?? 0)) + 1;
    }

    /**
     * Summarize the schedule's monetary totals.
     *
     * Returns a three-element list:
     *
     *     [0] — total expected principal, as a decimal string at scale 4.
     *     [1] — total expected interest, as a decimal string at scale 4.
     *     [2] — total expected repayment (principal + interest), as a
     *           decimal string at scale 4.
     *
     * The totals are computed by summing the schedule's entries, not by
     * reusing the calculator's own summary values, because the schedule
     * may include prepended grace installments whose interest is not
     * part of the calculator's output.
     *
     * @param list<array{expected_principal: string, expected_interest: string, expected_total: string}> $installments
     * @return array{0: string, 1: string, 2: string}
     */
    private function summarize(array $installments): array
    {
        $totalPrincipal = '0.0000';
        $totalInterest  = '0.0000';

        foreach ($installments as $inst) {
            $totalPrincipal = bcadd(
                $totalPrincipal,
                $inst['expected_principal'],
                self::FINAL_SCALE,
            );
            $totalInterest = bcadd(
                $totalInterest,
                $inst['expected_interest'],
                self::FINAL_SCALE,
            );
        }

        $totalRepayment = bcadd($totalPrincipal, $totalInterest, self::FINAL_SCALE);

        return [$totalPrincipal, $totalInterest, $totalRepayment];
    }
}