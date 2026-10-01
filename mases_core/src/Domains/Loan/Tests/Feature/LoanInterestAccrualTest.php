<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanInterestAccrualTest
 *
 * Feature tests for the interest-accrual scheduled command:
 *
 *     php artisan loan:accrue-interest [--as-of=YYYY-MM-DD] [--dry-run]
 *                                      [--loan-id=N] [--chunk=N]
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2A.49, 2B.62–2B.66 and the
 * Loan domain's unit suites A.90–A.101), this file lives INSIDE the Loan
 * module at `mases_core/src/Domains/Loan/Tests/Feature/` and carries the
 * namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Feature`.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — `makeInstallment()` fixture corrected. The helper previously
 *         passed `'status' => $status->value` through
 *         `LoanInstallment::create([...])`. The `status` column is
 *         DELIBERATELY absent from `LoanInstallment`'s `$fillable` array
 *         (see that model's class docblock §"Casts" and §"Partial
 *         mutability": status is initialised by the migration's default
 *         and transitions only through the services). The fillable guard
 *         silently dropped the key, so every row was created with the
 *         DB default `pending` regardless of the fixture's intent. The
 *         two tests that rely on a NON-default status —
 *         `test_command_skips_settled_installments` (line ~478) and
 *         `test_command_skips_superseded_installments` (line ~498) —
 *         therefore failed: the command correctly accrued on what the
 *         database actually contained, while the tests expected it to
 *         have skipped a settled/superseded row.
 *
 *         The fix assigns `status` AFTER the `create()` call, which
 *         bypasses the fillable guard without weakening it. The model's
 *         design intent (status is not mass-assignable) is preserved.
 *
 *         The same defect class is present in
 *         `LoanArrearsServiceTest.php`'s `makeOverdueInstallment()`
 *         helper. That file's correction is tracked separately.
 *
 * =============================================================================
 * The feature's surface is a COMMAND, not a route
 * =============================================================================
 * The interest-accrual flow has no HTTP endpoint. It is driven entirely
 * by `AccrueInterestCommand` (A.88), which the platform schedules
 * daily (C.4). The feature test therefore exercises the COMMAND as the
 * end-to-end entry point.
 *
 * The command is invoked via `$this->artisan('loan:accrue-interest', ...)`.
 * Every assertion is anchored to the observable side effects the command
 * produces: `loan_interest_accruals` rows, Wallet obligations, and the
 * loan's `outstanding_interest` projection.
 *
 * =============================================================================
 * THE FROZEN FISCAL MODEL — one accrual per installment, not daily
 * =============================================================================
 * Per the B3 decision and A.32's class docblock, an installment can be
 * accrued AT MOST ONCE. This file verifies the invariant at the COMMAND
 * level (as opposed to A.97's unit-level verification):
 *
 *   - Running the command twice produces exactly one accrual per
 *     installment across both runs.
 *   - The loan's `outstanding_interest` reflects the single accrual,
 *     not the double count.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The command dispatches no Loan-domain events. It DOES call
 * `LoanInterestAccrualService::accrue()`, which opens a per-installment
 * transaction and dispatches the Wallet's `ObligationRecorded` event
 * via `DB::afterCommit()`. That event is NOT asserted here (the Wallet
 * unit suite 2B.58 covers it).
 *
 * `RefreshDatabase` is used because the feature test does not need the
 * after-commit callback to fire for the assertions it makes. All
 * assertions are against durable state — the accrual rows, the Wallet
 * obligations, and the loan projection — which the command's
 * transactions commit durably under the test's own fixture writes.
 *
 * Note: `RefreshDatabase` wraps each test in a transaction. The
 * command's per-installment transactions become savepoints. All writes
 * are visible to the test's own queries. The `DB::afterCommit()` gate
 * fires at the end of the wrapper transaction (when the test's
 * transaction rolls back), so the Wallet's event does not fire; the
 * Wallet unit suite 2B.58 covers that event under
 * `DatabaseMigrations`.
 *
 * =============================================================================
 * Determinism
 * =============================================================================
 * `Carbon::setTestNow()` pins the wall clock. The command's default
 * `--as-of` (today) therefore resolves to a fixed date. Tests that pin
 * a different moment pass `--as-of` explicitly.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.1
 */
final class LoanInterestAccrualTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenant   = $this->makeTenant('interest-accrual-tenant', 'የወለድ ተከማች ቴናንት');
        $this->policy   = $this->makePolicy();
        $this->product  = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00:00'));

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        Carbon::setTestNow();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Command registration
    // =========================================================================

    public function test_command_is_registered_under_expected_name(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $commands = $kernel->all();

        $this->assertArrayHasKey(
            'loan:accrue-interest',
            $commands,
            'The AccrueInterestCommand must be registered under the name `loan:accrue-interest`.',
        );
    }

    // =========================================================================
    // Happy path — one accrual per due installment
    // =========================================================================

    public function test_command_accrues_installment_whose_due_date_has_arrived(): void
    {
        $loan = $this->makeActiveLoan();

        // One installment whose due date is in the past (relative to
        // the pinned `now` of 2026-12-01).
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('loan_interest_accruals', [
            'loan_id'        => $loan->id,
            'installment_id' => $installment->id,
            'interest_amount' => '100.0000',
        ]);
    }

    public function test_command_records_wallet_interest_obligation(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('wallet_obligations', [
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Interest->value,
            'original_amount'    => '100.0000',
            'outstanding_amount' => '100.0000',
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
        ]);
    }

    public function test_command_increments_loan_outstanding_interest(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $loan->refresh();
        $this->assertSame('100.0000', (string) $loan->outstanding_interest);
    }

    public function test_command_accrues_multiple_due_installments(): void
    {
        $loan = $this->makeActiveLoan();

        $installment1 = $this->makeInstallment($loan, number: 1, dueDate: Carbon::parse('2026-10-01'), expectedInterest: '100.0000');
        $installment2 = $this->makeInstallment($loan, number: 2, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            2,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        $loan->refresh();
        $this->assertSame('200.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // Installments whose due date has NOT arrived are excluded
    // =========================================================================

    public function test_command_skips_installment_whose_due_date_is_in_the_future(): void
    {
        $loan = $this->makeActiveLoan();

        // Due date in the future relative to `--as-of=2026-12-01`.
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2027-01-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
    }

    public function test_command_accrues_only_installments_whose_due_date_has_arrived(): void
    {
        $loan = $this->makeActiveLoan();

        $past = $this->makeInstallment($loan, number: 1, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');
        $future = $this->makeInstallment($loan, number: 2, dueDate: Carbon::parse('2027-01-01'), expectedInterest: '100.0000');

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(1, LoanInterestAccrual::query()->where('loan_id', $loan->id)->count());
        $this->assertDatabaseHas('loan_interest_accruals', [
            'installment_id' => $past->id,
        ]);
        $this->assertDatabaseMissing('loan_interest_accruals', [
            'installment_id' => $future->id,
        ]);
    }

    // =========================================================================
    // THE ONCE-PER-INSTALLMENT INVARIANT
    // =========================================================================

    public function test_running_command_twice_accrues_once(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-02'])
            ->assertExitCode(0);

        // Exactly one accrual row.
        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        // Exactly one Wallet obligation.
        $this->assertSame(
            1,
            Obligation::query()
                ->where('wallet_id', $this->wallet->id)
                ->where('component', ObligationComponent::Interest->value)
                ->count(),
        );

        // The projection reflects a single accrual.
        $loan->refresh();
        $this->assertSame('100.0000', (string) $loan->outstanding_interest);
    }

    public function test_running_command_three_times_still_accrues_once(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        for ($i = 0; $i < 3; $i++) {
            $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
                ->assertExitCode(0);
        }

        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        $loan->refresh();
        $this->assertSame('100.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // Backfill after scheduler outage
    // =========================================================================

    public function test_command_backfills_all_missed_installments_in_a_single_run(): void
    {
        // Three installments whose due dates are 2026-10-01, 2026-11-01,
        // and 2026-12-01. The command runs late, on 2026-12-15. All
        // three should be accrued in a single invocation.
        $loan = $this->makeActiveLoan();
        $this->makeInstallment($loan, number: 1, dueDate: Carbon::parse('2026-10-01'), expectedInterest: '100.0000');
        $this->makeInstallment($loan, number: 2, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');
        $this->makeInstallment($loan, number: 3, dueDate: Carbon::parse('2026-12-01'), expectedInterest: '100.0000');

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-15'])
            ->assertExitCode(0);

        $this->assertSame(
            3,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        $loan->refresh();
        $this->assertSame('300.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // Zero-interest installments
    // =========================================================================

    public function test_command_skips_zero_interest_installments(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '0.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        $this->assertSame(
            0,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // Loan state eligibility
    // =========================================================================

    public function test_command_skips_loans_in_ineligible_states(): void
    {
        // A loan in `draft` state is not accrual-eligible. The command
        // filters it out before calling the service.
        $loan = $this->makeLoanInState(LoanStatus::Draft);
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );
    }

    public function test_command_processes_in_arrears_loan(): void
    {
        $loan = $this->makeActiveLoan();
        $loan->status = LoanStatus::InArrears;
        $loan->save();

        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );
    }

    // =========================================================================
    // Installment state eligibility
    // =========================================================================

    public function test_command_skips_settled_installments(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
            status: InstallmentStatus::Settled,
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );
    }

    public function test_command_skips_superseded_installments(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
            status: InstallmentStatus::Superseded,
        );

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );
    }

    // =========================================================================
    // --dry-run
    // =========================================================================

    public function test_dry_run_writes_no_rows(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeInstallment(
            $loan,
            number: 1,
            dueDate: Carbon::parse('2026-11-01'),
            expectedInterest: '100.0000',
        );

        $this->artisan('loan:accrue-interest', [
            '--as-of' => '2026-12-01',
            '--dry-run' => true,
        ])->assertExitCode(0);

        // No accrual row.
        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        // No Wallet obligation.
        $this->assertSame(
            0,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );

        // No projection change.
        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // --loan-id restriction
    // =========================================================================

    public function test_loan_id_restricts_the_run(): void
    {
        $loan1 = $this->makeActiveLoan();
        $loan2 = $this->makeActiveLoan();

        $this->makeInstallment($loan1, number: 1, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');
        $this->makeInstallment($loan2, number: 1, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');

        $this->artisan('loan:accrue-interest', [
            '--as-of'   => '2026-12-01',
            '--loan-id' => (string) $loan1->id,
        ])->assertExitCode(0);

        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan1->id)->count(),
        );
        $this->assertSame(
            0,
            LoanInterestAccrual::query()->where('loan_id', $loan2->id)->count(),
        );
    }

    // =========================================================================
    // --as-of parsing
    // =========================================================================

    public function test_invalid_as_of_returns_failure_exit_code(): void
    {
        $this->artisan('loan:accrue-interest', ['--as-of' => 'not-a-date'])
            ->assertExitCode(1);
    }

    // =========================================================================
    // --chunk validation
    // =========================================================================

    public function test_invalid_chunk_returns_failure_exit_code(): void
    {
        $this->artisan('loan:accrue-interest', ['--chunk' => 'abc'])
            ->assertExitCode(1);
    }

    public function test_chunk_above_maximum_returns_failure_exit_code(): void
    {
        $this->artisan('loan:accrue-interest', ['--chunk' => '5000'])
            ->assertExitCode(1);
    }

    // =========================================================================
    // Multiple loans
    // =========================================================================

    public function test_command_processes_multiple_loans(): void
    {
        // Two loans, each with one due installment.
        $loan1 = $this->makeActiveLoan();
        $loan2 = $this->makeActiveLoan();

        $this->makeInstallment($loan1, number: 1, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');
        $this->makeInstallment($loan2, number: 1, dueDate: Carbon::parse('2026-11-01'), expectedInterest: '100.0000');

        $this->artisan('loan:accrue-interest', ['--as-of' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan1->id)->count(),
        );
        $this->assertSame(
            1,
            LoanInterestAccrual::query()->where('loan_id', $loan2->id)->count(),
        );
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

    private function makePolicy(): VersionedPolicy
    {
        return VersionedPolicy::create([
            'policy_type' => 'loan_product_rules',
            'payload'     => [
                LoanPolicyKeys::INTEREST_SECTION => [
                    LoanPolicyKeys::INTEREST_ANNUAL_RATE => '0.12',
                ],
                LoanPolicyKeys::TERM_SECTION => [
                    LoanPolicyKeys::TERM_PRINCIPAL_GRACE_MONTHS => 0,
                    LoanPolicyKeys::TERM_INTEREST_GRACE_MONTHS  => 0,
                ],
            ],
        ]);
    }

    private function makeProduct(Tenant $tenant, ?VersionedPolicy $policy = null): LoanProduct
    {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => 'PROD-' . uniqid(),
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => 'ETB',
            'min_principal'                 => '1000.0000',
            'max_principal'                 => '100000.0000',
            'min_term_months'               => 6,
            'max_term_months'               => 60,
            'default_installment_frequency' => 'monthly',
            'is_active'                     => true,
        ];

        if ($policy !== null) {
            $attributes['product_rules_policy_version_id'] = $policy->id;
        }

        return LoanProduct::create($attributes);
    }

    private function makeBorrower(Tenant $tenant): Shareholder
    {
        return Shareholder::create([
            'tenant_id'        => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);
    }

    private function makeWalletFor(Shareholder $borrower, string $currency): Wallet
    {
        return Wallet::create([
            'tenant_id'       => $borrower->tenant_id,
            'shareholder_id'  => $borrower->id,
            'currency'        => $currency,
            'current_balance' => '0.0000',
            'is_active'       => true,
            'opened_at'       => now(),
        ]);
    }

    private function makeActiveLoan(): Loan
    {
        return $this->makeLoanInState(LoanStatus::Active);
    }

    private function makeLoanInState(LoanStatus $status): Loan
    {
        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $this->borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = $status;
        $loan->application_date         = now()->subMonths(3);
        $loan->decision_date            = now()->subMonths(3);
        $loan->disbursement_date        = now()->subMonths(3);
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = '10000.0000';
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '833.3333';
        $loan->maturity_date            = now()->addMonths(9);
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

        return $loan;
    }

    /**
     * Build an installment with the given due date and status.
     *
     * Revision note (v1.0.1)
     * ----------------------------------------------------------------------
     * The `status` column is DELIBERATELY absent from
     * `LoanInstallment`'s `$fillable` array (see that model's class
     * docblock §"Partial mutability"). The helper therefore assigns
     * status AFTER the `create()` call, which bypasses the fillable
     * guard without weakening it. Passing `'status'` inside the
     * `create([...])` array — the v1.0.0 form — silently dropped the
     * key, leaving every row at the DB default `pending`.
     *
     * The signature and every call site are unchanged.
     */
    private function makeInstallment(
        Loan $loan,
        int $number = 1,
        Carbon $dueDate,
        string $expectedInterest = '100.0000',
        InstallmentStatus $status = InstallmentStatus::Pending,
    ): LoanInstallment {
        $installment = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => $dueDate->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => $expectedInterest,
            'expected_total'     => bcadd('1000.0000', $expectedInterest, 4),
        ]);

        // Assign status post-create: the column is not mass-assignable
        // by design. See the method docblock §"Revision note (v1.0.1)".
        $installment->status = $status;
        $installment->save();

        return $installment;
    }

    private function writeHistory(
        Loan $loan,
        ?LoanStatus $from,
        LoanStatus $to,
    ): void {
        LoanStatusHistory::create([
            'loan_id'           => $loan->id,
            'from_status'       => $from?->value,
            'to_status'         => $to->value,
            'transition_reason' => null,
            'actor_user_id'     => null,
            'occurred_at'       => now(),
            'created_at'        => now(),
        ]);
    }
}