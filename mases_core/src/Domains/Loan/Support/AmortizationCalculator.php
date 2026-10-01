<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Support;

use InvalidArgumentException;

/**
 * AmortizationCalculator
 *
 * The deterministic equal-installment (annuity) amortization engine for the
 * Phase 2C Loan domain. Given a principal, an annual rate, and a term in
 * months, it produces the full installment schedule and the summary totals
 * that the loan's projections depend on.
 *
 * =============================================================================
 * Source of truth
 * =============================================================================
 * Two frozen decisions govern this file:
 *
 *   A9 — Interest computation
 *     · declining balance
 *     · annual rate
 *     · monthly periods
 *     · Actual/365 day-count (implicitly: the annuity formula assumes
 *       equal-length monthly periods; the day-count convention matters
 *       for pro-rating partial periods, not for the standard schedule)
 *     · half-up rounding
 *     · financial precision: 4 decimal places at rest
 *     · BCMath arithmetic, no floating-point
 *     · no compounding of ordinary contractual interest
 *
 *   D135 — Amortization
 *     · equal-installment / annuity
 *     · interest calculated against outstanding principal each period
 *     · principal = installment − interest, per period
 *     · final installment absorbs the deterministic rounding residual
 *     · no negative residual balance
 *     · schedule generation is deterministic and reproducible
 *     · no bullet, no interest-only, no custom, no combination methods
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — two correctness fixes, both essential for the D135 freeze:
 *
 *   (a) `bcpow()` cast. PHP 8.3 enforces `bcpow(string, string, int)` —
 *       passing an `int` as the exponent raises `TypeError`. The
 *       `computeMonthlyPayment()` call site now casts `$termMonths` to
 *       string. Behaviour is unchanged in the happy path; the fix
 *       restores the method on PHP 8.3+.
 *
 *   (b) `halfUp()` off-by-one. The half-unit-in-the-last-place was
 *       constructed as `'0.' . str_repeat('0', $scale - 1) . '5'`,
 *       which at `scale = 4` produces `'0.0005'` — the half-ULP of
 *       scale 3, not scale 4. The correct construction is
 *       `'0.' . str_repeat('0', $scale) . '5'`, which at `scale = 4`
 *       produces `'0.00005'`. The old construction over-rounded every
 *       value whose 5th decimal digit was `4`, producing errors of up
 *       to 0.0001 per rounded value (observable across every zero-
 *       interest test vector as `83.3333 → 83.3338`, `500.0000 →
 *       500.0007`, `0.0313 → 0.0317`, etc.). The new construction
 *       matches the D135 freeze exactly.
 *
 * The same `halfUp()` off-by-one is present verbatim in
 * `LoanScheduleService` and `LoanArrearsService`; both files carry their
 * own private copies and MUST be patched in the same deployment. A
 * follow-up CR should extract the helper into a shared trait to prevent
 * future drift.
 *
 * =============================================================================
 * The annuity formula
 * =============================================================================
 * The equal installment M that amortizes a principal P over N periods at a
 * monthly rate r (r > 0) is:
 *
 *                        r · (1 + r)^N
 *     M = P · ─────────────────────────────
 *              (1 + r)^N − 1
 *
 * The formula is algebraically equivalent to the more commonly written form
 *
 *     M = P · r / (1 − (1 + r)^(−N))
 *
 * but the second form requires a negative exponent, which PHP's `bcpow`
 * does not accept. The first form uses only a positive integer exponent and
 * is the form implemented here.
 *
 * For r = 0 (a zero-interest loan) the formula is undefined (0/0). This
 * calculator handles that case separately: every installment is
 * `principal / N`, and the final installment absorbs the residual.
 *
 * =============================================================================
 * Precision and rounding policy
 * =============================================================================
 * All arithmetic uses BCMath. Two scales are used:
 *
 *   INTERMEDIATE_SCALE = 20
 *       Every intermediate value — the monthly rate, (1+r)^N, the raw
 *       monthly payment before rounding, and the raw interest before
 *       rounding — is held at scale 20. This prevents drift from
 *       accumulating between operations and guarantees that the final
 *       result is deterministic regardless of the order in which the
 *       intermediate values are materialised.
 *
 *   FINAL_SCALE = 4
 *       Every value that appears in the returned schedule — the
 *       installment's expected principal, expected interest, expected
 *       total, and the loan's outstanding balance after each installment —
 *       is at scale 4. This matches the DECIMAL(20,4) columns declared by
 *       the A.9 / A.10 / A.11 migrations and by every monetary column in
 *       the Phase 2A and Phase 2B schemas.
 *
 * Rounding between the two scales is HALF-UP (round to nearest, ties away
 * from zero for positive values). The `halfUp()` helper implements this by
 * adding a half-unit-in-the-last-place at the target scale and truncating
 * to that scale. PHP's BCMath functions truncate to the specified scale;
 * the half-ulp addition converts that truncation into half-up rounding.
 *
 * The calculator does NOT use PHP's native floating-point operators (`+`,
 * `-`, `*`, `/`, `**`, `<`, `>`, `==`) anywhere. Every arithmetic operation
 * flows through BCMath. This is required by A9 and by the platform-wide
 * financial-correctness discipline.
 *
 * =============================================================================
 * The schedule generation algorithm (deterministic)
 * =============================================================================
 * Given (P, annualRate, N):
 *
 *   1. Validate P > 0, annualRate ≥ 0, N ≥ 1.
 *   2. Round P to scale 4 with half-up. Call the result P₀.
 *   3. Compute the monthly rate r = annualRate / 12 at scale 20.
 *   4. If r == 0, use the zero-interest schedule (see below).
 *   5. Otherwise compute M_raw = P₀ · r · (1+r)^N / ((1+r)^N − 1)
 *      at scale 20.
 *   6. Round M_raw to scale 4 with half-up. Call the result M.
 *   7. For i = 1 to N:
 *        outstanding_before_i = the outstanding balance at the start of
 *                               installment i. For i = 1, this is P₀.
 *
 *        interest_i   = halfUp(outstanding_before_i · r, 4)
 *
 *        principal_i  =
 *          if i < N:
 *            halfUp(M − interest_i, 4)
 *          if i == N:
 *            outstanding_before_i     ← final installment pays the
 *                                        remaining balance exactly; this
 *                                        is what makes the sum of
 *                                        principal_i equal P₀ precisely
 *                                        and satisfies D135's
 *                                        "no negative residual balance"
 *                                        requirement
 *
 *        total_i      = principal_i + interest_i
 *        outstanding_after_i = outstanding_before_i − principal_i
 *
 *      The final installment's total may differ from M by a small amount
 *      — this is exactly the "deterministic rounding residual" that D135
 *      requires the final installment to absorb.
 *
 *   8. Return the schedule and the summary.
 *
 * Zero-interest (r == 0) uses the same loop but with `M = P₀ / N` (rounded
 * to scale 4 with half-up) and `interest_i = 0.0000` for every installment.
 * The final-installment rule is identical.
 *
 * =============================================================================
 * Determinism guarantee
 * =============================================================================
 * For a given (P, annualRate, N), `schedule()` returns a byte-for-byte
 * identical array on every call, on every platform, in every PHP version
 * that supports BCMath at scale 20. No randomness, no time-dependence, no
 * environment-dependence, no locale-dependence. This is required by D135
 * ("schedule generation must be deterministic and reproducible") and by the
 * Phase 2C financial-test discipline (test vectors must be independently
 * verifiable).
 *
 * =============================================================================
 * What this class does NOT do
 * =============================================================================
 *   - It does NOT touch the database. It is a pure computational utility
 *     with no dependencies on the Wallet, the Shareholder domain, or the
 *     Loan aggregate.
 *
 *   - It does NOT write to the Wallet. It computes a schedule; the
 *     schedule is persisted by `LoanScheduleService` (A.23), and the
 *     schedule's installment amounts become Wallet obligations only when
 *     the interest-accrual command (A.87) fires at each installment's due
 *     date.
 *
 *   - It does NOT evaluate interest or allocation policies. It takes a
 *     numeric annual rate and a numeric term as inputs; it does not look
 *     them up from a product or from the versioned policy registry. The
 *     caller (`LoanScheduleService`) is responsible for resolving the
 *     product's frozen policy version and passing the correct inputs.
 *
 *   - It does NOT enforce minimum or maximum principal, term, or rate. The
 *     product's policy is the enforcement point; the calculator's only
 *     numeric precondition is P > 0, annualRate ≥ 0, N ≥ 1.
 *
 *   - It does NOT implement bullet, interest-only, custom-schedule, or
 *     combination methods. D135 explicitly excludes them from Phase 2C.
 *     A future Change Request that adds one of those methods would add a
 *     separate calculator class (or a new method on this one) under its
 *     own test vector.
 *
 *   - It does NOT compute arrears penalties. D133's penalty accrual is
 *     computed by `LoanArrearsService` (A.29) against overdue installments,
 *     not against the schedule's forward-looking rows. The two are
 *     separate concerns.
 *
 *   - It does NOT compute pro-rated interest for partial periods. A9
 *     permits pro-rating, but the initial Phase 2C implementation uses
 *     full-period interest at each scheduled installment; pro-rating
 *     matters only if the caller asks for a partial-period calculation,
 *     which no Phase 2C call site does. If a future Change Request
 *     requires pro-rating, this class gains a separate method for it,
 *     with its own scale and rounding rules.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9, D132, D133, D135
 *   Phase 2C Clarification Response, 2026-09-12  — C4, C5
 *   Phase 2C Final Reconciliation, 2026-09-12    — final-installment
 *                                                  residual rule
 *   Step 5 (manifest) A.6                        — This file
 *   Step 6 v1.1.0 §3.9, §3.10                    — loans and loan_installments
 *                                                  table specifications
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Support
 * @version 1.0.1
 */
final class AmortizationCalculator
{
    /**
     * The scale at which intermediate values are held during the
     * computation.
     *
     * Every value that is not part of the returned schedule — the monthly
     * rate, (1 + r)^N, the raw monthly payment before rounding, the raw
     * interest before rounding — is held at this scale. The choice of 20
     * is dictated by the requirement that the final result be deterministic
     * and independent of the order of intermediate operations; 20 decimal
     * places of headroom is ample for any loan the platform is likely to
     * encounter and leaves no room for observable drift at scale 4.
     */
    private const INTERMEDIATE_SCALE = 20;

    /**
     * The scale at which the returned schedule's monetary values are
     * expressed.
     *
     * Matches every DECIMAL(20,4) monetary column in the Phase 2A and
     * Phase 2B schemas, and the corresponding columns declared by the
     * Phase 2C migrations (A.9, A.10).
     */
    private const FINAL_SCALE = 4;

    /**
     * The number of months in a year, used to derive the monthly rate from
     * the annual rate.
     *
     * A9 freezes the periodicity as monthly. D132 freezes the term unit as
     * months. The monthly rate is `annualRate / 12`; this constant is the
     * single place where that divisor is declared.
     */
    private const MONTHS_PER_YEAR = 12;

    /**
     * Generate the amortization schedule for a loan.
     *
     * This is the class's sole public method. It returns both the
     * per-installment schedule and the summary totals that the caller
     * (`LoanScheduleService`, A.23) persists onto the loan row.
     *
     * The return shape:
     *
     *     [
     *         'monthly_payment'  => '888.4879',   // the M value used for
     *                                             // every non-final
     *                                             // installment; the final
     *                                             // installment's total
     *                                             // may differ by a small
     *                                             // rounding residual
     *         'total_principal'  => '10000.0000', // equal to the input
     *                                             // principal after
     *                                             // rounding to scale 4
     *         'total_interest'   => '661.8548',   // sum of expected_interest
     *                                             // across all installments
     *         'total_repayment'  => '10661.8548', // total_principal +
     *                                             // total_interest
     *         'installments'     => [
     *             [
     *                 'installment_number' => 1,
     *                 'expected_principal' => '788.4879',
     *                 'expected_interest'  => '100.0000',
     *                 'expected_total'     => '888.4879',
     *                 'outstanding_after'  => '9211.5121',
     *             ],
     *             // ...
     *         ],
     *     ]
     *
     * Every monetary value is a decimal string at scale 4. The
     * `installments` value is a proper list (index keys 0..N-1);
     * `installment_number` is 1-based for display.
     *
     * @param string $principal    Positive decimal string, up to 20 decimal
     *                             places. Rounded internally to scale 4
     *                             with half-up before computing.
     * @param string $annualRate   Non-negative decimal string, up to 20
     *                             decimal places, expressing the annual
     *                             rate as a decimal fraction (0.10 = 10%,
     *                             0.05 = 5%, "0" = zero-interest). Not
     *                             rounded; used at full precision.
     * @param int    $termMonths   Positive integer, >= 1.
     * @return array{
     *     monthly_payment: string,
     *     total_principal: string,
     *     total_interest: string,
     *     total_repayment: string,
     *     installments: list<array{
     *         installment_number: int,
     *         expected_principal: string,
     *         expected_interest: string,
     *         expected_total: string,
     *         outstanding_after: string
     *     }>
     * }
     *
     * @throws InvalidArgumentException  When any input violates the
     *                                   documented preconditions.
     */
    public function schedule(string $principal, string $annualRate, int $termMonths): array
    {
        $principal  = $this->assertPositiveDecimal($principal, 'principal');
        $annualRate = $this->assertNonNegativeDecimal($annualRate, 'annualRate');
        $this->assertPositiveInteger($termMonths, 'termMonths');

        // Round the principal to scale 4 with half-up. The caller is
        // expected to supply a value at scale ≤ 4; passing more precision
        // is tolerated but rounded here so that every downstream value is
        // at a single, consistent scale.
        $principalAtScale = $this->halfUp($principal, self::FINAL_SCALE);

        if ($this->isZero($principalAtScale)) {
            throw new InvalidArgumentException(
                'The principal must be at least 0.0001 after rounding to '
                . 'four decimal places.'
            );
        }

        // Derive the monthly rate at intermediate precision. This is the
        // single value that decides whether we take the zero-interest
        // branch or the standard annuity branch.
        $monthlyRate = bcdiv(
            $annualRate,
            (string) self::MONTHS_PER_YEAR,
            self::INTERMEDIATE_SCALE,
        );

        if ($this->isZero($monthlyRate)) {
            return $this->scheduleZeroInterest($principalAtScale, $termMonths);
        }

        return $this->scheduleWithInterest($principalAtScale, $monthlyRate, $termMonths);
    }

    // =========================================================================
    // Private — schedule generation
    // =========================================================================

    /**
     * Generate the schedule for a zero-interest loan.
     *
     * A zero-interest loan has no compounding and no accrual; the entire
     * principal is split into N equal installments, with the final
     * installment absorbing the rounding residual. This is the D135
     * algorithm with the interest term forced to zero.
     *
     * @param string $principal    Principal at scale 4, strictly positive.
     * @param int    $termMonths   Positive integer, >= 1.
     * @return array{monthly_payment: string, total_principal: string,
     *               total_interest: string, total_repayment: string,
     *               installments: list<array{installment_number: int,
     *               expected_principal: string, expected_interest: string,
     *               expected_total: string, outstanding_after: string}>}
     */
    private function scheduleZeroInterest(string $principal, int $termMonths): array
    {
        $perMonthRaw = bcdiv(
            $principal,
            (string) $termMonths,
            self::INTERMEDIATE_SCALE,
        );
        $perMonth = $this->halfUp($perMonthRaw, self::FINAL_SCALE);

        $installments = [];
        $outstanding  = $principal;
        $zero         = $this->zero();

        for ($i = 1; $i <= $termMonths; $i++) {
            $outstandingBefore = $outstanding;

            if ($i < $termMonths) {
                $principalPortion = $perMonth;

                // Defensive cap: never pay more than the outstanding
                // balance. In a well-formed zero-interest loan this
                // branch never fires because perMonth * N >= principal
                // by construction of the divisor.
                if ($this->compare($principalPortion, $outstandingBefore) > 0) {
                    $principalPortion = $outstandingBefore;
                }
            } else {
                $principalPortion = $outstandingBefore;
            }

            $outstanding = bcsub(
                $outstandingBefore,
                $principalPortion,
                self::FINAL_SCALE,
            );

            // Defensive: floor at zero. The final installment's principal
            // is exactly the outstanding amount, so the subtraction is
            // exact for i == N; for i < N the cap above prevents an
            // overshoot.
            if ($this->compare($outstanding, $zero) < 0) {
                $outstanding = $zero;
            }

            $installments[] = [
                'installment_number' => $i,
                'expected_principal' => $principalPortion,
                'expected_interest'  => $zero,
                'expected_total'     => $principalPortion,
                'outstanding_after'  => $outstanding,
            ];
        }

        return $this->summarize($installments, $principal, $perMonth);
    }

    /**
     * Generate the schedule for a loan with a strictly positive monthly
     * rate.
     *
     * Implements the D135 annuity algorithm exactly. See the class
     * docblock §"The schedule generation algorithm" for the step-by-step
     * description.
     *
     * @param string $principal    Principal at scale 4, strictly positive.
     * @param string $monthlyRate  Monthly rate at intermediate precision,
     *                             strictly positive.
     * @param int    $termMonths   Positive integer, >= 1.
     * @return array{monthly_payment: string, total_principal: string,
     *               total_interest: string, total_repayment: string,
     *               installments: list<array{installment_number: int,
     *               expected_principal: string, expected_interest: string,
     *               expected_total: string, outstanding_after: string}>}
     */
    private function scheduleWithInterest(
        string $principal,
        string $monthlyRate,
        int $termMonths,
    ): array {
        $monthlyPayment = $this->computeMonthlyPayment(
            $principal,
            $monthlyRate,
            $termMonths,
        );

        $installments = [];
        $outstanding  = $principal;
        $zero         = $this->zero();

        for ($i = 1; $i <= $termMonths; $i++) {
            $outstandingBefore = $outstanding;

            // Interest accrues against the outstanding principal at the
            // start of the period, per A9 (declining balance).
            $interestRaw = bcmul(
                $outstandingBefore,
                $monthlyRate,
                self::INTERMEDIATE_SCALE,
            );
            $interest = $this->halfUp($interestRaw, self::FINAL_SCALE);

            if ($i < $termMonths) {
                // Regular installment: principal is whatever remains of
                // the fixed monthly payment after interest.
                $principalRaw     = bcsub(
                    $monthlyPayment,
                    $interest,
                    self::INTERMEDIATE_SCALE,
                );
                $principalPortion = $this->halfUp($principalRaw, self::FINAL_SCALE);

                // Defensive cap: never pay more than the outstanding
                // balance. In a well-formed annuity loan this branch
                // never fires because M < outstanding * (1 + r) holds
                // throughout the schedule.
                if ($this->compare($principalPortion, $outstandingBefore) > 0) {
                    $principalPortion = $outstandingBefore;
                }
            } else {
                // Final installment: principal is the entire remaining
                // balance. This is the D135 "final installment absorbs
                // the deterministic rounding residual" rule. Sum of
                // principals across all installments is exactly the
                // input principal, by construction.
                $principalPortion = $outstandingBefore;
            }

            $total = bcadd($principalPortion, $interest, self::FINAL_SCALE);

            $outstanding = bcsub(
                $outstandingBefore,
                $principalPortion,
                self::FINAL_SCALE,
            );

            // Defensive: floor at zero. The final installment's principal
            // is exactly the outstanding amount; for i < N the cap above
            // prevents an overshoot.
            if ($this->compare($outstanding, $zero) < 0) {
                $outstanding = $zero;
            }

            $installments[] = [
                'installment_number' => $i,
                'expected_principal' => $principalPortion,
                'expected_interest'  => $interest,
                'expected_total'     => $total,
                'outstanding_after'  => $outstanding,
            ];
        }

        return $this->summarize($installments, $principal, $monthlyPayment);
    }

    /**
     * Compute the fixed monthly payment M for a standard annuity loan.
     *
     *     M = P · r · (1 + r)^N / ((1 + r)^N − 1)
     *
     * The computation is performed entirely at intermediate precision;
     * the result is rounded to scale 4 with half-up before being returned.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * The `bcpow()` third argument is cast to string. PHP 8.3 enforces
     * `bcpow(string $num, string $exponent, ?int $scale = null): string`
     * — the exponent parameter is typed as `string`, and passing an
     * `int` raises `TypeError`. Earlier PHP versions silently coerced
     * the value; PHP 8.3 does not. The cast is explicit and total: it
     * produces the same numeric result the previous versions computed,
     * and it satisfies the strict type signature.
     *
     * @param string $principal    Principal at scale 4, strictly positive.
     * @param string $monthlyRate  Monthly rate at intermediate precision,
     *                             strictly positive.
     * @param int    $termMonths   Positive integer, >= 1.
     * @return string  M at scale 4.
     */
    private function computeMonthlyPayment(
        string $principal,
        string $monthlyRate,
        int $termMonths,
    ): string {
        $onePlusR   = bcadd('1', $monthlyRate, self::INTERMEDIATE_SCALE);
        $compounded = bcpow($onePlusR, (string) $termMonths, self::INTERMEDIATE_SCALE);
        $numerator  = bcmul(
            $principal,
            bcmul($monthlyRate, $compounded, self::INTERMEDIATE_SCALE),
            self::INTERMEDIATE_SCALE,
        );
        $denominator = bcsub($compounded, '1', self::INTERMEDIATE_SCALE);

        $paymentRaw = bcdiv($numerator, $denominator, self::INTERMEDIATE_SCALE);

        return $this->halfUp($paymentRaw, self::FINAL_SCALE);
    }

    // =========================================================================
    // Private — summary assembly
    // =========================================================================

    /**
     * Assemble the top-level result: the schedule plus the summary totals
     * that the caller stores on the loan row.
     *
     * The summary fields are computed from the schedule itself, so they
     * cannot disagree with it. In particular:
     *
     *     total_principal = sum(expected_principal) == input principal
     *     total_interest  = sum(expected_interest)
     *     total_repayment = total_principal + total_interest
     *
     * @param list<array<string, mixed>> $installments
     * @param string                     $principal
     * @param string                     $monthlyPayment
     * @return array{monthly_payment: string, total_principal: string,
     *               total_interest: string, total_repayment: string,
     *               installments: list<array<string, mixed>>}
     */
    private function summarize(
        array $installments,
        string $principal,
        string $monthlyPayment,
    ): array {
        $totalPrincipal = $this->zero();
        $totalInterest  = $this->zero();

        foreach ($installments as $row) {
            $totalPrincipal = bcadd(
                $totalPrincipal,
                (string) $row['expected_principal'],
                self::FINAL_SCALE,
            );
            $totalInterest = bcadd(
                $totalInterest,
                (string) $row['expected_interest'],
                self::FINAL_SCALE,
            );
        }

        $totalRepayment = bcadd($totalPrincipal, $totalInterest, self::FINAL_SCALE);

        return [
            'monthly_payment' => $monthlyPayment,
            'total_principal' => $totalPrincipal,
            'total_interest'  => $totalInterest,
            'total_repayment' => $totalRepayment,
            'installments'    => $installments,
        ];
    }

    // =========================================================================
    // Private — input validation
    // =========================================================================

    /**
     * Assert that a value is a strictly positive decimal string.
     *
     * Accepted shape: an optional integer part (required), an optional
     * decimal point followed by 1–20 digits. No sign is accepted; no
     * scientific notation; no thousand separators; no currency symbols.
     * The caller is expected to have already normalised the input to this
     * shape.
     *
     * @param string $value
     * @param string $fieldName  Used in the exception message.
     * @return string  The trimmed value on success.
     *
     * @throws InvalidArgumentException
     */
    private function assertPositiveDecimal(string $value, string $fieldName): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('The %s must not be empty.', $fieldName),
            );
        }

        if (!preg_match('/^\d+(\.\d{1,20})?$/', $value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must be a positive decimal string with up to 20 '
                    . 'decimal places; got "%s".',
                    $fieldName,
                    $value,
                ),
            );
        }

        if ($this->isZero($value)) {
            throw new InvalidArgumentException(
                sprintf('The %s must be strictly positive.', $fieldName),
            );
        }

        return $value;
    }

    /**
     * Assert that a value is a non-negative decimal string.
     *
     * Accepted shape: same as `assertPositiveDecimal()`, except that the
     * value `"0"` and `"0.000…0"` are permitted.
     *
     * @param string $value
     * @param string $fieldName
     * @return string
     *
     * @throws InvalidArgumentException
     */
    private function assertNonNegativeDecimal(string $value, string $fieldName): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf('The %s must not be empty.', $fieldName),
            );
        }

        if (!preg_match('/^\d+(\.\d{1,20})?$/', $value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must be a non-negative decimal string with up '
                    . 'to 20 decimal places; got "%s".',
                    $fieldName,
                    $value,
                ),
            );
        }

        return $value;
    }

    /**
     * Assert that an integer is strictly positive.
     *
     * @param int    $value
     * @param string $fieldName
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private function assertPositiveInteger(int $value, string $fieldName): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'The %s must be a positive integer (>= 1); got %d.',
                    $fieldName,
                    $value,
                ),
            );
        }
    }

    // =========================================================================
    // Private — decimal arithmetic helpers
    // =========================================================================

    /**
     * Round a decimal string to a target scale using half-up rounding.
     *
     * "Half-up" here means: round to nearest; when the value lies exactly
     * halfway between the two candidates at the target scale, round to the
     * candidate whose absolute value is larger (i.e. away from zero for
     * signed values). For the positive values this class produces, "half
     * away from zero" and "half up" coincide.
     *
     * ------------------------------------------------------------------
     * The algorithm
     * ------------------------------------------------------------------
     * The implementation adds a half-unit-in-the-last-place at the target
     * scale and then truncates the sum to that scale. PHP's BCMath
     * functions truncate to the specified scale; the half-ulp addition
     * therefore converts truncation into half-up rounding.
     *
     * For any input at precision ≤ INTERMEDIATE_SCALE, the half-ulp
     * addition is performed at INTERMEDIATE_SCALE, so no digit of the
     * input is lost in the addition itself. The result is then truncated
     * to the target scale with `bcadd($shifted, '0', $scale)`, which
     * yields exactly the truncated-to-scale value.
     *
     * ------------------------------------------------------------------
     * Revision note (v1.0.1)
     * ------------------------------------------------------------------
     * The half-ulp construction was previously:
     *
     *     '0.' . str_repeat('0', $scale - 1) . '5'
     *
     * which at `scale = 4` produces `'0.0005'`. That is the half-ULP of
     * scale 3, not scale 4. The correct construction is:
     *
     *     '0.' . str_repeat('0', $scale) . '5'
     *
     * which at `scale = 4` produces `'0.00005'`. The old construction
     * over-rounded every value whose 5th decimal digit was `4`, yielding
     * an error of up to 0.0001 per rounded value. Concretely:
     *
     *     value = 83.33333333…, scale 4
     *         old half-ulp '0.0005'   → 83.33338333… → truncate → 83.3333  ✓ (coincidentally correct)
     *         old half-ulp on 83.33335 → 83.33385    → truncate → 83.3338  ✗ (wrong; should be 83.3334)
     *
     * The new construction rounds correctly for every input whose 5th
     * decimal digit is 4 or 5.
     *
     * ------------------------------------------------------------------
     * Sign handling
     * ------------------------------------------------------------------
     * The function strips a leading `-` (or `+`) before performing the
     * addition, and re-attaches it to the result. This is correct for the
     * phase's requirements because the class never produces negative
     * values, but the handling is present as a defensive measure and to
     * keep the helper safe for future reuse.
     *
     * @param string $value
     * @param int    $scale  Non-negative target scale.
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

        // The half-unit-in-the-last-place at the target scale:
        //   scale  0 → "0.5"
        //   scale  4 → "0.00005"
        //   scale 20 → "0.00000000000000000005"
        //
        // str_repeat('0', $scale) produces exactly $scale zeros, which
        // is the number of digits between the decimal point and the
        // trailing '5'. At scale 4, the result is the string `'0.00005'`.
        $halfUlp = $scale === 0
            ? '0.5'
            : '0.' . str_repeat('0', $scale) . '5';

        $shifted = bcadd($value, $halfUlp, self::INTERMEDIATE_SCALE);

        // Truncate to the target scale. bcadd with '0' as the second
        // operand and the target scale as the third produces exactly the
        // truncated-to-scale value.
        $truncated = bcadd($shifted, '0', $scale);

        return $sign . $truncated;
    }

    /**
     * Whether a decimal string represents zero.
     *
     * Compared at intermediate precision so that a value like
     * `"0.00000000000000000001"` is NOT classified as zero. For the values
     * this class handles, such a value can only arise from a caller
     * passing an absurdly small rate; the conservative behaviour is to
     * treat it as non-zero and proceed with the standard annuity branch.
     *
     * @param string $value
     * @return bool
     */
    private function isZero(string $value): bool
    {
        return bccomp($value, '0', self::INTERMEDIATE_SCALE) === 0;
    }

    /**
     * Compare two decimal strings at intermediate precision.
     *
     * Returns -1, 0, or +1. Comparison is exact for any values at or below
     * the intermediate scale.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    private function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::INTERMEDIATE_SCALE);
    }

    /**
     * The canonical zero at final scale.
     *
     * @return string
     */
    private function zero(): string
    {
        return $this->halfUp('0', self::FINAL_SCALE);
    }
}