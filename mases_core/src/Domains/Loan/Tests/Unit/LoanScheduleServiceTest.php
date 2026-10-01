<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanScheduleService;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanScheduleServiceTest
 *
 * Unit-level coverage for `LoanScheduleService` (A.27) — the generator
 * of a loan's contractual amortization schedule.
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
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — R-22 fixture FK fix. Both `makeApprovedLoan()` and
 *         `makeLoanWithoutPolicy()` previously wrote
 *         `borrower_shareholder_id = 1` and `loan_product_id = 1`
 *         directly, assuming fixture rows with those ids existed. Under
 *         `DatabaseMigrations`, the schema is wiped between test files,
 *         so no `shareholders.id = 1` and no `loan_products.id = 1`
 *         existed and the insert failed the FK constraint. The fix
 *         creates a `Shareholder` and a `LoanProduct` in `setUp()` and
 *         references their ids. The null-policy semantics of
 *         `makeLoanWithoutPolicy()` are preserved exactly: the loan's
 *         `frozen_policy_version_id` remains null.
 *
 *         No test assertions change; no call sites change; no production
 *         code touched.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * The schedule service does NOT dispatch events (see A.27's class
 * docblock §"What this service deliberately does NOT do"). However, the
 * service REQUIRES a surrounding transaction (its `assertInTransaction()`
 * guard throws `LogicException` when `DB::transactionLevel() === 0`).
 *
 * Under `RefreshDatabase`, the framework wraps each test in its own
 * transaction; the outer wrapper satisfies the service's guard, but
 * subsequent assertions inside the test see the same transaction's
 * uncommitted state. That works — but the tests that verify "the rows
 * are durably persisted" would need `assertDatabaseHas` against
 * uncommitted rows, which is fragile.
 *
 * `DatabaseMigrations` gives each test a clean migrated schema with NO
 * wrapping transaction. The tests open their own `DB::transaction(...)`
 * around the service call, which is the pattern the service's contract
 * requires. This mirrors the `DatabaseMigrations` choice made by the
 * Shareholder and Wallet unit suites for the same structural reason
 * (transaction-requiring service).
 *
 * =============================================================================
 * VersionedPolicy fixture — deployment note
 * =============================================================================
 * The `VersionedPolicy` model (Core Policy CORE.3) is not in the Loan
 * module's file set. The `makePolicy()` fixture constructs it with the
 * minimum attributes the Loan domain reads:
 *
 *     policy_type  — read by the service to validate it is
 *                    `'loan_product_rules'`.
 *     payload      — read by the service for the interest and term
 *                    sections.
 *
 * If the platform's `versioned_policies` table declares additional
 * NOT NULL columns without defaults, the fixture's `create()` call will
 * fail with a clear database error, and the deployment note on
 * `makePolicy()` will need to be extended with the missing fields. See
 * the §"Fixtures" section of the file for the exact helper.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * =============================================================================
 * Test vectors
 * =============================================================================
 * Where a test asserts an exact monetary value, the value is computed
 * against the calculator's documented precision (A.6's half-up
 * discipline at scale 4). Vectors used:
 *
 *   Zero-interest, N=1:
 *     P=10000 → single installment of 10000.0000.
 *
 *   Zero-interest, N=12:
 *     P=10000 → 10000/12 = 833.3333 per month, final absorbs residual.
 *
 *   Annuity, annual=0.12, N=2:
 *     P=10000 → M = 5075.1244
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanScheduleServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanScheduleService $service;
    private Tenant $tenant;

    /**
     * The borrower fixture for every loan row this suite creates.
     */
    private Shareholder $borrower;

    /**
     * The loan-product fixture for every loan row this suite creates.
     */
    private LoanProduct $product;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanScheduleService::class);

        $this->tenant = $this->makeTenant('schedule-tenant', 'የጊዜ ሰሌዳ ቴናንት');

        // The two FK targets the `loans` table requires. See the class
        // docblock §"Revision history (1.0.1)".
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->product = LoanProduct::create([
            'tenant_id'                     => $this->tenant->id,
            'code'                          => 'PROD-SCHEDULE',
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => 'ETB',
            'min_principal'                 => '1000.0000',
            'max_principal'                 => '100000.0000',
            'min_term_months'               => 1,
            'max_term_months'               => 60,
            'default_installment_frequency' => 'monthly',
            'is_active'                     => true,
        ]);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Transaction and persistence guards
    // =========================================================================

    public function test_generate_requires_transaction(): void
    {
        $loan = $this->makeApprovedLoan();

        $this->expectException(LogicException::class);

        $this->service->generate($loan, Carbon::parse('2026-01-01'));
    }

    public function test_generate_rejects_unpersisted_loan(): void
    {
        $loan = new Loan();
        $loan->status = LoanStatus::Approved;

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    // =========================================================================
    // Policy resolution errors
    // =========================================================================

    public function test_missing_frozen_policy_version_throws_logic_exception(): void
    {
        // A loan in `approved` state without a frozen policy version is
        // a data-integrity defect: `LoanApprovalService` (A.29) refuses
        // to approve a loan whose product has no policy version. This
        // test constructs the impossible state directly to verify the
        // service surfaces it as a `LogicException`.
        $loan = $this->makeLoanWithoutPolicy();

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_wrong_policy_type_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('not_a_loan_policy', $this->validPayload());
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_non_array_payload_throws_logic_exception(): void
    {
        $policy = $this->makePolicy(
            'loan_product_rules',
            'not-an-array',
        );
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_missing_interest_section_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::TERM_SECTION => [
                LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS => 0,
                LoanPolicyKeys::TERM_INTEREST_GRACE_MONTHS  => 0,
            ],
        ]);
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_missing_annual_rate_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::INTEREST_SECTION => [],
            LoanPolicyKeys::TERM_SECTION     => [],
        ]);
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_malformed_annual_rate_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::INTEREST_SECTION => [
                LoanPolicyKeys::INTEREST_ANNUAL_RATE => 'not-a-number',
            ],
            LoanPolicyKeys::TERM_SECTION => [],
        ]);
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_malformed_term_section_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::INTEREST_SECTION => [
                LoanPolicyKeys::INTEREST_ANNUAL_RATE => '0.12',
            ],
            LoanPolicyKeys::TERM_SECTION => 'not-an-object',
        ]);
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_malformed_principal_grace_throws_logic_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::INTEREST_SECTION => [
                LoanPolicyKeys::INTEREST_ANNUAL_RATE => '0.12',
            ],
            LoanPolicyKeys::TERM_SECTION => [
                LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS => 'abc',
            ],
        ]);
        $loan   = $this->makeApprovedLoan(policy: $policy);

        $this->expectException(LogicException::class);

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });
    }

    public function test_absent_term_section_defaults_grace_periods_to_zero(): void
    {
        // With no term section at all, both grace periods default to 0.
        // The schedule is a pure annuity — the same as if the section
        // were present with explicit zero values.
        $policy = $this->makePolicy('loan_product_rules', [
            LoanPolicyKeys::INTEREST_SECTION => [
                LoanPolicyKeys::INTEREST_ANNUAL_RATE => '0',
            ],
        ]);
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 2);

        /** @var Collection $rows */
        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $this->assertCount(2, $rows);

        // Both installments pay 5000.0000 principal (zero-interest,
        // no grace).
        $this->assertSame('5000.0000', (string) $rows[0]->expected_principal);
        $this->assertSame('5000.0000', (string) $rows[1]->expected_principal);
    }

    // =========================================================================
    // Input validation
    // =========================================================================

    public function test_zero_interest_grace_is_allowed(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 0,
            interestGrace: 0,
        ));
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 2);

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $this->assertCount(2, $rows);
    }

    public function test_non_zero_interest_grace_is_rejected(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 0,
            interestGrace: 3,
        ));
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 12);

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->generate($loan, Carbon::parse('2026-01-01'));
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('interest_grace_months', $e->errors());
        }
    }

    public function test_zero_principal_throws_validation_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload());
        $loan   = $this->makeApprovedLoan(
            policy: $policy,
            principal: '0.0000',
        );

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->generate($loan, Carbon::parse('2026-01-01'));
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_amount', $e->errors());
        }
    }

    public function test_zero_term_throws_validation_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload());

        // A term of 0 cannot be stored via the Loan fixture (the model
        // validation rejects it at the migration level), but the
        // service's guard must still fire. We construct the loan with
        // term_months = 0 directly to exercise the guard.
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 1);
        $loan->term_months = 0;

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->generate($loan, Carbon::parse('2026-01-01'));
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('term_months', $e->errors());
        }
    }

    public function test_principal_grace_equal_to_term_throws_validation_exception(): void
    {
        // Principal grace equal to the term leaves no amortizing period.
        // The service rejects this.
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            principalGrace: 12,
        ));
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 12);

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->generate($loan, Carbon::parse('2026-01-01'));
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_grace_months', $e->errors());
        }
    }

    public function test_principal_grace_greater_than_term_throws_validation_exception(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            principalGrace: 24,
        ));
        $loan = $this->makeApprovedLoan(policy: $policy, termMonths: 12);

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->generate($loan, Carbon::parse('2026-01-01'));
            });

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_grace_months', $e->errors());
        }
    }

    // =========================================================================
    // Happy path — zero-interest loan
    // =========================================================================

    public function test_zero_interest_loan_generates_even_split(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 12,
        );

        /** @var Collection $rows */
        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $this->assertCount(12, $rows);

        // First 11 installments pay 833.3333; final pays 833.3337.
        for ($i = 0; $i < 11; $i++) {
            $this->assertSame('833.3333', (string) $rows[$i]->expected_principal);
        }
        $this->assertSame('833.3337', (string) $rows[11]->expected_principal);

        // Every installment carries zero interest.
        foreach ($rows as $row) {
            $this->assertSame('0.0000', (string) $row->expected_interest);
        }
    }

    public function test_zero_interest_loan_writes_summary_columns(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 12,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        $this->assertSame('833.3333', (string) $loan->monthly_installment_amount);
        $this->assertSame('0.0000',    (string) $loan->total_scheduled_interest);
        $this->assertSame('10000.0000', (string) $loan->total_scheduled_repayment);
    }

    public function test_zero_interest_loan_writes_maturity_date(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 12,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        // Reference 2026-01-01 + 12 months = 2027-01-01.
        $this->assertSame(
            '2027-01-01',
            $loan->maturity_date->toDateString(),
        );
    }

    // =========================================================================
    // Happy path — annuity loan
    // =========================================================================

    public function test_annuity_loan_generates_expected_number_of_installments(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 2,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $this->assertCount(2, $rows);
    }

    public function test_annuity_loan_writes_monthly_installment_amount(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 2,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        // M = 10000 * 0.01 * 1.0201 / 0.0201 = 5075.1244 (half-up at
        // scale 4).
        $this->assertSame('5075.1244', (string) $loan->monthly_installment_amount);
    }

    public function test_annuity_loan_writes_total_scheduled_repayment(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 2,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        // Total = 10000 + total_interest. Total interest equals the
        // sum of the two installments' expected_interest.
        $rows = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->get();

        $totalInterest = '0.0000';
        foreach ($rows as $row) {
            $totalInterest = bcadd($totalInterest, (string) $row->expected_interest, 4);
        }

        $expected = bcadd('10000.0000', $totalInterest, 4);
        $this->assertSame($expected, (string) $loan->total_scheduled_repayment);
        $this->assertSame($totalInterest, (string) $loan->total_scheduled_interest);
    }

    // =========================================================================
    // Due dates
    // =========================================================================

    public function test_first_installment_due_one_month_after_reference(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 3,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-15'));
        });

        $this->assertSame('2026-02-15', $rows[0]->due_date->toDateString());
        $this->assertSame('2026-03-15', $rows[1]->due_date->toDateString());
        $this->assertSame('2026-04-15', $rows[2]->due_date->toDateString());
    }

    public function test_due_dates_clamp_to_end_of_shorter_month(): void
    {
        // Reference 2026-01-31. N=2. The February installment must land
        // on 2026-02-28 (2026 is not a leap year). The March
        // installment lands on 2026-03-31 (March has 31 days).
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 2,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-31'));
        });

        $this->assertSame('2026-02-28', $rows[0]->due_date->toDateString());
        $this->assertSame('2026-03-31', $rows[1]->due_date->toDateString());
    }

    public function test_maturity_date_equals_last_installment_due_date(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 6,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-15'));
        });

        $loan->refresh();

        $this->assertSame(
            $rows[5]->due_date->toDateString(),
            $loan->maturity_date->toDateString(),
        );
    }

    // =========================================================================
    // Monotonic numbering
    // =========================================================================

    public function test_first_generation_numbers_installments_from_one(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 3,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-15'));
        });

        $this->assertSame(1, $rows[0]->installment_number);
        $this->assertSame(2, $rows[1]->installment_number);
        $this->assertSame(3, $rows[2]->installment_number);
    }

    public function test_second_generation_continues_numbering(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 3,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-15'));
        });

        $loan->refresh();

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-04-15'));
        });

        // The second schedule's numbers continue from 4.
        $this->assertSame(4, $rows[0]->installment_number);
        $this->assertSame(5, $rows[1]->installment_number);
        $this->assertSame(6, $rows[2]->installment_number);
    }

    public function test_second_generation_does_not_delete_prior_rows(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '1000.0000',
            termMonths: 3,
        );

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-01-15'));
        });

        $loan->refresh();

        DB::transaction(function () use ($loan): void {
            $this->service->generate($loan, Carbon::parse('2026-04-15'));
        });

        // Six rows total: three from the first schedule, three from the
        // second. The service does NOT delete the prior rows (that is
        // the restructure service's responsibility).
        $count = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->count();

        $this->assertSame(6, $count);
    }

    // =========================================================================
    // Principal grace
    // =========================================================================

    public function test_principal_grace_prepends_zero_principal_installments(): void
    {
        // P=10000, annual=0.12, term=4, principal grace=2.
        //
        // The service computes the amortizing schedule over the
        // remaining 2 installments and prepends 2 grace installments.
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 2,
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 4,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $this->assertCount(4, $rows);

        // First two installments have zero principal.
        $this->assertSame('0.0000', (string) $rows[0]->expected_principal);
        $this->assertSame('0.0000', (string) $rows[1]->expected_principal);

        // Their interest is principal * monthly_rate = 10000 * 0.01 =
        // 100.0000.
        $this->assertSame('100.0000', (string) $rows[0]->expected_interest);
        $this->assertSame('100.0000', (string) $rows[1]->expected_interest);

        // Their total equals their interest.
        $this->assertSame('100.0000', (string) $rows[0]->expected_total);
        $this->assertSame('100.0000', (string) $rows[1]->expected_total);

        // The remaining two installments amortize the principal.
        $this->assertNotSame('0.0000', (string) $rows[2]->expected_principal);
        $this->assertNotSame('0.0000', (string) $rows[3]->expected_principal);
    }

    public function test_principal_grace_total_principal_still_equals_input(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 2,
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 4,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $sum = '0.0000';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->expected_principal, 4);
        }

        $this->assertSame('10000.0000', $sum);
    }

    public function test_principal_grace_writes_amortizing_monthly_payment(): void
    {
        // The summary column `monthly_installment_amount` reflects the
        // AMORTIZING installment amount, not the (smaller) grace
        // installment amount.
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 2,
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 4,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        // The amortizing installment amount is the third row's total
        // (or, equivalently, the calculator's own M).
        $this->assertSame(
            (string) $rows[2]->expected_total,
            (string) $loan->monthly_installment_amount,
        );
    }

    public function test_principal_grace_total_interest_includes_grace_interest(): void
    {
        // With 2 months of principal grace, the total scheduled interest
        // is the sum of the two grace installments' interest plus the
        // two amortizing installments' interest.
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
            principalGrace: 2,
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 4,
        );

        $rows = DB::transaction(function () use ($loan) {
            return $this->service->generate($loan, Carbon::parse('2026-01-01'));
        });

        $loan->refresh();

        $sum = '0.0000';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->expected_interest, 4);
        }

        $this->assertSame($sum, (string) $loan->total_scheduled_interest);
    }

    // =========================================================================
    // Determinism
    // =========================================================================

    public function test_schedule_generation_is_deterministic(): void
    {
        $policy = $this->makePolicy('loan_product_rules', $this->validPayload(
            annualRate: '0.12',
        ));
        $loan = $this->makeApprovedLoan(
            policy: $policy,
            principal: '10000.0000',
            termMonths: 4,
        );

        $first = DB::transaction(function () use ($loan) {
            return $this->service
                ->generate($loan, Carbon::parse('2026-01-01'))
                ->map(fn ($row) => [
                    'n'        => $row->installment_number,
                    'p'        => (string) $row->expected_principal,
                    'i'        => (string) $row->expected_interest,
                    't'        => (string) $row->expected_total,
                    'due'      => $row->due_date->toDateString(),
                ])
                ->all();
        });

        // Wipe the rows for the same loan and regenerate.
        LoanInstallment::query()->where('loan_id', $loan->id)->delete();

        $second = DB::transaction(function () use ($loan) {
            return $this->service
                ->generate($loan, Carbon::parse('2026-01-01'))
                ->map(fn ($row) => [
                    'n'        => $row->installment_number,
                    'p'        => (string) $row->expected_principal,
                    'i'        => (string) $row->expected_interest,
                    't'        => (string) $row->expected_total,
                    'due'      => $row->due_date->toDateString(),
                ])
                ->all();
        });

        // Both runs produce identical values.
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function makeTenant(string $slug, string $nameAm): Tenant
    {
        return Tenant::create([
            'slug'      => $slug,
            'name_am'   => $nameAm,
            'name_en'   => ucfirst(str_replace('-', ' ', $slug)),
            'domain'    => $slug . '.example.test',
            'is_active' => true,
        ]);
    }

    /**
     * Build the canonical `loan_product_rules` payload with the given
     * interest and term parameters.
     *
     * @return array<string, mixed>
     */
    private function validPayload(
        string $annualRate = '0.12',
        int $principalGrace = 0,
        int $interestGrace = 0,
    ): array {
        return [
            LoanPolicyKeys::INTEREST_SECTION => [
                LoanPolicyKeys::INTEREST_ANNUAL_RATE => $annualRate,
            ],
            LoanPolicyKeys::TERM_SECTION => [
                LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS => $principalGrace,
                LoanPolicyKeys::TERM_INTEREST_GRACE_MONTHS  => $interestGrace,
            ],
        ];
    }

    /**
     * Create a `VersionedPolicy` fixture.
     *
     * See the class docblock §"VersionedPolicy fixture" for the
     * deployment assumption on the `versioned_policies` schema.
     */
    private function makePolicy(string $policyType, mixed $payload): VersionedPolicy
    {
        return VersionedPolicy::create([
            'policy_type' => $policyType,
            'payload'     => $payload,
        ]);
    }

    /**
     * Create a persisted loan in `approved` state, optionally bound to
     * a policy version.
     *
     * The `borrower_shareholder_id` and `loan_product_id` FKs reference
     * the fixtures created in `setUp()`. See the class docblock
     * §"Revision history (1.0.1)".
     */
    private function makeApprovedLoan(
        ?VersionedPolicy $policy = null,
        string $principal = '10000.0000',
        int $termMonths = 12,
    ): Loan {
        $policy ??= $this->makePolicy('loan_product_rules', $this->validPayload());

        $loan = new Loan();
        $loan->tenant_id               = $this->tenant->id;
        $loan->borrower_shareholder_id = $this->borrower->id;
        $loan->loan_product_id         = $this->product->id;
        $loan->principal_amount        = $principal;
        $loan->term_months             = $termMonths;
        $loan->status                  = LoanStatus::Approved;
        $loan->application_date        = Carbon::now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->frozen_policy_version_id = $policy->id;
        $loan->save();

        return $loan;
    }

    /**
     * Create a persisted loan in `approved` state with no frozen policy
     * version — a data-integrity defect that the service must surface
     * as a `LogicException`.
     *
     * The null-policy semantics are PRESERVED. The only change from the
     * v1.0.0 form is the two FK references, which now point at the
     * fixtures created in `setUp()`. See the class docblock
     * §"Revision history (1.0.1)".
     */
    private function makeLoanWithoutPolicy(): Loan
    {
        $loan = new Loan();
        $loan->tenant_id               = $this->tenant->id;
        $loan->borrower_shareholder_id = $this->borrower->id;
        $loan->loan_product_id         = $this->product->id;
        $loan->principal_amount        = '10000.0000';
        $loan->term_months             = 12;
        $loan->status                  = LoanStatus::Approved;
        $loan->application_date        = Carbon::now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->frozen_policy_version_id = null;
        $loan->save();

        return $loan;
    }
}