<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use MaaleArna\MASES\Core\Domains\Loan\Support\AmortizationCalculator;
use Tests\TestCase;

/**
 * AmortizationCalculatorTest
 *
 * Unit-level coverage for `AmortizationCalculator` (A.6) — the
 * deterministic equal-installment (annuity) amortization engine.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2B.57–2B.61 and the
 * Shareholder and Wallet domain test suites), this file lives INSIDE the
 * Loan module at `mases_core/src/Domains/Loan/Tests/Unit/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Unit`.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The calculator is a PURE computational utility. It touches no database,
 * opens no transaction, and dispatches no event. Neither the
 * `DatabaseMigrations` precondition (asserting on after-commit events)
 * nor the `RefreshDatabase` default (read-only DB suites) applies in
 * principle. `RefreshDatabase` is used here for a different reason: the
 * platform's `Tests\TestCase` base class expects the application's
 * database connection to be configured, and running the migrations once
 * per file is cheaper than adding a database-less base class. The
 * calculator's methods never execute a query; the trait is present for
 * suite-structural consistency, not for correctness of the assertions.
 *
 * =============================================================================
 * What this suite tests
 * =============================================================================
 *   1.  Return-shape contract — the top-level keys and per-installment
 *       keys documented on A.6's `schedule()` method.
 *   2.  Zero-interest branch — the principal splits evenly, the final
 *       installment absorbs the residual, and every interest value is
 *       `"0.0000"`.
 *   3.  Annuity branch — known N=1 and N=2 vectors with hand-verified
 *       monthly payments.
 *   4.  The two invariants that D135 requires: the sum of expected
 *       principal equals the input principal exactly, and the final
 *       installment's outstanding is exactly zero.
 *   5.  Half-up rounding at scale 4 — a fractional installment that
 *       would truncate to a different value is rounded up.
 *   6.  Determinism — a second invocation with the same inputs yields a
 *       byte-for-byte identical result.
 *   7.  Input validation — every precondition the calculator documents
 *       (positive principal, non-negative rate, positive term).
 *
 * =============================================================================
 * Known test vectors
 * =============================================================================
 * Where a test asserts an exact monetary value, the value was computed
 * by hand using the annuity formula documented on A.6 and the platform's
 * half-up rounding discipline. Values are:
 *
 *   P=1000,  annual=0.12, N=1 → M = 1010.0000
 *   P=1000,  annual=0.12, N=2 → M =  507.5124
 *   P=1000,  annual=0,    N=12 → per-installment = 83.3333
 *                                (final = 83.3337, absorbing the
 *                                 residual of 0.0004)
 *   P=1000,  annual=0,    N=1  → single installment of 1000.0000
 *
 * The docblock of A.6 gives an illustrative example for
 * `P=10000, annual=0.12, N=12` with a `monthly_payment` of `"888.4886"`.
 * That value is illustrative and NOT asserted here: recomputing the
 * annuity to the calculator's own precision yields a value closer to
 * `"888.4879"`, and pinning the test to the imprecise illustrative
 * figure would be wrong. The invariants that D135 actually requires
 * (sum of principal = input, final outstanding = zero, determinism) are
 * asserted for that configuration instead.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle. Same
 * rationale as every other suite in the platform.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.0
 */
final class AmortizationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The system-under-test.
     */
    private AmortizationCalculator $calculator;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->calculator = app(AmortizationCalculator::class);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Return-shape contract
    // =========================================================================

    public function test_schedule_returns_expected_top_level_keys(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 2);

        foreach (
            [
                'monthly_payment',
                'total_principal',
                'total_interest',
                'total_repayment',
                'installments',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_schedule_returns_one_installment_per_period(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 12);

        $this->assertCount(12, $result['installments']);
    }

    public function test_each_installment_has_expected_keys(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 3);

        foreach ($result['installments'] as $installment) {
            foreach (
                [
                    'installment_number',
                    'expected_principal',
                    'expected_interest',
                    'expected_total',
                    'outstanding_after',
                ] as $key
            ) {
                $this->assertArrayHasKey($key, $installment);
            }
        }
    }

    public function test_installment_numbers_are_one_based_and_monotonic(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 5);

        $numbers = array_map(
            static fn (array $row): int => $row['installment_number'],
            $result['installments'],
        );

        $this->assertSame([1, 2, 3, 4, 5], $numbers);
    }

    public function test_monetary_values_are_decimal_strings(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 3);

        $this->assertIsString($result['monthly_payment']);
        $this->assertIsString($result['total_principal']);
        $this->assertIsString($result['total_interest']);
        $this->assertIsString($result['total_repayment']);

        foreach ($result['installments'] as $installment) {
            $this->assertIsString($installment['expected_principal']);
            $this->assertIsString($installment['expected_interest']);
            $this->assertIsString($installment['expected_total']);
            $this->assertIsString($installment['outstanding_after']);
        }
    }

    public function test_monetary_values_are_at_scale_four(): void
    {
        $result = $this->calculator->schedule('1000', '0.12', 3);

        // Every monetary value the calculator emits ends with exactly
        // four digits after the decimal point. This is the D135 /
        // platform-wide storage precision.
        $pattern = '/^-?\d+\.\d{4}$/';

        $this->assertMatchesRegularExpression($pattern, $result['monthly_payment']);
        $this->assertMatchesRegularExpression($pattern, $result['total_principal']);
        $this->assertMatchesRegularExpression($pattern, $result['total_interest']);
        $this->assertMatchesRegularExpression($pattern, $result['total_repayment']);

        foreach ($result['installments'] as $installment) {
            $this->assertMatchesRegularExpression($pattern, $installment['expected_principal']);
            $this->assertMatchesRegularExpression($pattern, $installment['expected_interest']);
            $this->assertMatchesRegularExpression($pattern, $installment['expected_total']);
            $this->assertMatchesRegularExpression($pattern, $installment['outstanding_after']);
        }
    }

    // =========================================================================
    // Zero-interest branch
    // =========================================================================

    public function test_zero_interest_split_evenly_over_term(): void
    {
        // P=1000, N=12, r=0 → perMonth = halfUp(1000/12, 4) = 83.3333
        $result = $this->calculator->schedule('1000', '0', 12);

        $this->assertSame('83.3333', $result['monthly_payment']);
        $this->assertCount(12, $result['installments']);
    }

    public function test_zero_interest_installments_are_all_zero_interest(): void
    {
        $result = $this->calculator->schedule('1000', '0', 12);

        foreach ($result['installments'] as $installment) {
            $this->assertSame('0.0000', $installment['expected_interest']);
        }
    }

    public function test_zero_interest_final_installment_absorbs_residual(): void
    {
        // 11 installments of 83.3333 = 916.6663. The final installment
        // must pay the remaining 83.3337 to reach 1000.0000 exactly.
        $result = $this->calculator->schedule('1000', '0', 12);

        $final = $result['installments'][11];

        $this->assertSame('83.3337', $final['expected_principal']);
        $this->assertSame('0.0000', $final['outstanding_after']);
    }

    public function test_zero_interest_sum_of_principal_equals_input(): void
    {
        $result = $this->calculator->schedule('1000', '0', 12);

        $sum = '0.0000';
        foreach ($result['installments'] as $installment) {
            $sum = bcadd($sum, $installment['expected_principal'], 4);
        }

        $this->assertSame('1000.0000', $sum);
        $this->assertSame('1000.0000', $result['total_principal']);
    }

    public function test_zero_interest_single_installment(): void
    {
        // N=1 with no interest: the single installment pays the full
        // principal.
        $result = $this->calculator->schedule('1000', '0', 1);

        $this->assertCount(1, $result['installments']);

        $only = $result['installments'][0];

        $this->assertSame('1000.0000', $only['expected_principal']);
        $this->assertSame('0.0000', $only['expected_interest']);
        $this->assertSame('1000.0000', $only['expected_total']);
        $this->assertSame('0.0000', $only['outstanding_after']);
    }

    // =========================================================================
    // Annuity branch — known vectors
    // =========================================================================

    public function test_annuity_single_period_repays_principal_plus_one_period_interest(): void
    {
        // P=1000, annual=0.12 → r=0.01. N=1: M = 1000 * 1.01 = 1010.0000.
        // The single installment pays 1000 principal and 10.0000 interest.
        $result = $this->calculator->schedule('1000', '0.12', 1);

        $this->assertSame('1010.0000', $result['monthly_payment']);
        $this->assertCount(1, $result['installments']);

        $only = $result['installments'][0];

        $this->assertSame('1000.0000', $only['expected_principal']);
        $this->assertSame('10.0000', $only['expected_interest']);
        $this->assertSame('1010.0000', $only['expected_total']);
        $this->assertSame('0.0000', $only['outstanding_after']);
    }

    public function test_annuity_two_period_monthly_payment_is_known_value(): void
    {
        // P=1000, annual=0.12, N=2 → r=0.01.
        // M = 1000 * 0.01 * (1.01)^2 / ((1.01)^2 - 1)
        //   = 1000 * 0.01 * 1.0201 / 0.0201
        //   = 10.201 / 0.0201
        //   = 507.512437... → half-up = 507.5124
        $result = $this->calculator->schedule('1000', '0.12', 2);

        $this->assertSame('507.5124', $result['monthly_payment']);
        $this->assertCount(2, $result['installments']);
    }

    public function test_annuity_two_period_installment_breakdown(): void
    {
        // P=1000, r=0.01, N=2, M=507.5124.
        //
        // Period 1:
        //   outstanding_before = 1000.0000
        //   interest = halfUp(1000 * 0.01, 4)   = 10.0000
        //   principal = halfUp(507.5124 - 10.0000, 4) = 497.5124
        //   outstanding_after = 502.4876
        //
        // Period 2 (final):
        //   outstanding_before = 502.4876
        //   interest = halfUp(502.4876 * 0.01, 4) = halfUp(5.024876) = 5.0249
        //   principal = outstanding_before        = 502.4876
        //   total = 502.4876 + 5.0249             = 507.5125
        $result = $this->calculator->schedule('1000', '0.12', 2);

        $first = $result['installments'][0];
        $final = $result['installments'][1];

        $this->assertSame('497.5124', $first['expected_principal']);
        $this->assertSame('10.0000',  $first['expected_interest']);
        $this->assertSame('507.5124', $first['expected_total']);
        $this->assertSame('502.4876', $first['outstanding_after']);

        $this->assertSame('502.4876', $final['expected_principal']);
        $this->assertSame('5.0249',   $final['expected_interest']);
        $this->assertSame('507.5125', $final['expected_total']);
        $this->assertSame('0.0000',   $final['outstanding_after']);
    }

    public function test_annuity_two_period_final_installment_total_may_differ_from_monthly_payment(): void
    {
        // D135 requires the final installment to absorb the residual.
        // Its total may therefore differ from `monthly_payment` by a
        // small amount; that is not a defect.
        $result = $this->calculator->schedule('1000', '0.12', 2);

        $final = $result['installments'][1];

        $this->assertNotSame($result['monthly_payment'], $final['expected_total']);
        $this->assertSame('507.5125', $final['expected_total']);
    }

    // =========================================================================
    // Annuity branch — invariants
    // =========================================================================

    public function test_sum_of_expected_principal_equals_input_principal(): void
    {
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $sum = '0.0000';
        foreach ($result['installments'] as $installment) {
            $sum = bcadd($sum, $installment['expected_principal'], 4);
        }

        $this->assertSame('10000.0000', $sum);
        $this->assertSame('10000.0000', $result['total_principal']);
    }

    public function test_final_installment_outstanding_is_exactly_zero(): void
    {
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $final = $result['installments'][11];

        $this->assertSame('0.0000', $final['outstanding_after']);
    }

    public function test_total_repayment_equals_principal_plus_interest(): void
    {
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $expected = bcadd(
            $result['total_principal'],
            $result['total_interest'],
            4,
        );

        $this->assertSame($expected, $result['total_repayment']);
    }

    public function test_total_interest_equals_sum_of_installment_interest(): void
    {
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $sum = '0.0000';
        foreach ($result['installments'] as $installment) {
            $sum = bcadd($sum, $installment['expected_interest'], 4);
        }

        $this->assertSame($sum, $result['total_interest']);
    }

    public function test_outstanding_decreases_monotonically(): void
    {
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $previous = '999999999.0000';
        foreach ($result['installments'] as $installment) {
            $current = $installment['outstanding_after'];
            $this->assertLessThanOrEqual(
                0,
                bccomp($current, $previous, 4),
                'Outstanding balance must not increase across installments.',
            );
            $previous = $current;
        }
    }

    public function test_interest_decreases_across_installments_for_declining_balance(): void
    {
        // A9 freezes the interest method as declining balance. Under
        // that method, each period's interest is smaller than the
        // previous period's, because the outstanding balance is
        // smaller.
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $previous = null;
        foreach ($result['installments'] as $installment) {
            $current = $installment['expected_interest'];

            if ($previous !== null) {
                $this->assertLessThanOrEqual(
                    0,
                    bccomp($current, $previous, 4),
                    'Interest must not increase across installments under declining balance.',
                );
            }

            $previous = $current;
        }
    }

    public function test_principal_increases_across_installments_for_annuity(): void
    {
        // Under the standard annuity, interest decreases and the total
        // is fixed (for non-final installments), so principal increases
        // across the schedule.
        $result = $this->calculator->schedule('10000', '0.12', 12);

        $previous = null;
        foreach ($result['installments'] as $index => $installment) {
            $current = $installment['expected_principal'];

            if ($previous !== null && $index < 11) {
                // The final installment absorbs the residual and may
                // not follow the monotone trend. Compare only the
                // non-final installments.
                $this->assertLessThanOrEqual(
                    0,
                    bccomp($previous, $current, 4),
                    'Principal must not decrease across non-final installments under annuity.',
                );
            }

            $previous = $current;
        }
    }

    // =========================================================================
    // Half-up rounding
    // =========================================================================

    public function test_half_up_rounds_a_half_unit_up_at_scale_four(): void
    {
        // P=1000, N=3, zero-interest → perMonth_raw = 333.333333...
        // half-up at scale 4 → 333.3333
        // Final installment absorbs the residual.
        $result = $this->calculator->schedule('1000', '0', 3);

        $this->assertSame('333.3333', $result['monthly_payment']);
        $this->assertSame('333.3333', $result['installments'][0]['expected_principal']);
        $this->assertSame('333.3333', $result['installments'][1]['expected_principal']);
        $this->assertSame('333.3334', $result['installments'][2]['expected_principal']);
    }

    public function test_half_up_rounds_ties_away_from_zero(): void
    {
        // P=1, N=16, zero-interest → perMonth_raw = 0.0625 exactly.
        // At scale 4, half-up rounds 0.0625 → 0.0625 (no residual at
        // that exact scale). The test's purpose is to pin the boundary
        // behavior at the third-to-fourth digit.
        //
        // We use a value whose remainder at the 5th digit is exactly 5
        // to force the half-up branch: P=3, N=8 → 0.375 exactly. With
        // no further digits, this is not a half-way case, so we instead
        // use P=5, N=16 → 0.3125 exactly. Still not half-way. To hit a
        // true half-way at scale 4 we need a value whose 5th decimal
        // digit is 5 and whose subsequent digits are 0: P=1, N=32 →
        // 0.03125. Half-up at scale 4 → 0.0313.
        $result = $this->calculator->schedule('1', '0', 32);

        $this->assertSame('0.0313', $result['monthly_payment']);
    }

    // =========================================================================
    // Determinism
    // =========================================================================

    public function test_schedule_is_deterministic(): void
    {
        $first  = $this->calculator->schedule('10000', '0.12', 24);
        $second = $this->calculator->schedule('10000', '0.12', 24);

        // Byte-for-byte equality, including ordering of installments.
        $this->assertSame($first, $second);
    }

    public function test_schedule_is_deterministic_for_zero_interest(): void
    {
        $first  = $this->calculator->schedule('9999', '0', 17);
        $second = $this->calculator->schedule('9999', '0', 17);

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Input validation
    // =========================================================================

    public function test_rejects_zero_principal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('0', '0.12', 12);
    }

    public function test_rejects_negative_principal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('-1000', '0.12', 12);
    }

    public function test_rejects_malformed_principal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('not-a-number', '0.12', 12);
    }

    public function test_rejects_principal_with_scientific_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('1e3', '0.12', 12);
    }

    public function test_rejects_negative_annual_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('1000', '-0.05', 12);
    }

    public function test_rejects_malformed_annual_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('1000', 'not-a-number', 12);
    }

    public function test_rejects_zero_term(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('1000', '0.12', 0);
    }

    public function test_rejects_negative_term(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule('1000', '0.12', -1);
    }

    public function test_accepts_whitespace_around_principal(): void
    {
        // The calculator trims input before validation. A principal
        // wrapped in whitespace is accepted.
        $result = $this->calculator->schedule('  1000  ', '0.12', 2);

        $this->assertSame('1000.0000', $result['total_principal']);
    }

    public function test_accepts_whitespace_around_rate(): void
    {
        $result = $this->calculator->schedule('1000', '  0.12  ', 2);

        $this->assertSame('507.5124', $result['monthly_payment']);
    }

    public function test_accepts_zero_rate(): void
    {
        // Zero is a valid non-negative rate; the calculator switches to
        // the zero-interest branch.
        $result = $this->calculator->schedule('1000', '0', 2);

        $this->assertSame('500.0000', $result['monthly_payment']);
    }

    public function test_accepts_zero_rate_with_zero_decimals(): void
    {
        $result = $this->calculator->schedule('1000', '0.00', 2);

        $this->assertSame('500.0000', $result['monthly_payment']);
    }
}