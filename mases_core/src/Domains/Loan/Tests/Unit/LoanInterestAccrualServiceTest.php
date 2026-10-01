<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanInterestAccrualService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanInterestAccrualServiceTest
 *
 * Unit-level coverage for `LoanInterestAccrualService` (A.32) — the
 * service that records ONE interest obligation per installment, at the
 * installment's due date, on the borrower's Wallet.
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
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `accrue()` performs all of its writes inside a single
 * `DB::transaction(...)`. The service does NOT dispatch a Loan-domain
 * event (per A.32's class docblock §"What this service deliberately
 * does NOT do": the audit trail is the `loan_interest_accruals` row
 * itself, plus the Wallet's `ObligationRecorded` event).
 *
 * The `DatabaseMigrations` choice here is therefore not about
 * after-commit callbacks on Loan-domain events. It is about the fact
 * that the service's own transaction must be the OUTERMOST one for its
 * savepoint boundaries to be meaningful. `RefreshDatabase` would wrap
 * each test in an outer transaction, pushing the service's transaction
 * down to a savepoint — which works, but changes the semantics the
 * service was designed for. `DatabaseMigrations` avoids the wrapper and
 * exercises the service's transaction as the outermost one, matching
 * production.
 *
 * The Wallet's `ObligationRecorded` event dispatch (via
 * `ObligationService::record()` internally calling
 * `WalletService::recordEntry()`) DOES fire after commit. The tests do
 * not assert on that event; the Wallet's own unit suite (2B.58) already
 * does.
 *
 * =============================================================================
 * THE FROZEN ACCRUAL MODEL — one accrual per installment, not daily
 * =============================================================================
 * Per the B3 decision and A.32's class docblock, an installment can be
 * accrued AT MOST ONCE. The unique constraint on
 * `loan_interest_accruals.accrual_period_key` (migration A.15) is the
 * structural enforcement. The tests below verify:
 *
 *   - First call inserts the accrual row and returns the model.
 *   - Second call (same loan, same installment) is a no-op: returns
 *     null, inserts no duplicate row, does not double-increment the
 *     loan's `outstanding_interest`.
 *
 * =============================================================================
 * Fixture baseline
 * =============================================================================
 * The fixtures build an active loan with:
 *   - N installments whose `expected_interest` is `"100.0000"` each,
 *   - an ETB-denominated borrower wallet.
 *
 * The tests then invoke `accrue($loan, $installment)` on an eligible
 * installment and assert the accrual row and Wallet obligation shape.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.0
 */
final class LoanInterestAccrualServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanInterestAccrualService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanInterestAccrualService::class);

        $this->tenant   = $this->makeTenant('interest-tenant', 'የወለድ ቴናንት');
        $this->policy   = $this->makePolicy();
        $this->product  = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00'));

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
    // Happy path
    // =========================================================================

    public function test_accrue_creates_interest_accrual_row(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '100.0000',
        );

        $accrual = $this->service->accrue(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-05-15'),
        );

        $this->assertNotNull($accrual);
        $this->assertSame('100.0000', (string) $accrual->interest_amount);
        $this->assertSame(
            sprintf('%d:%d', $loan->id, $installment->id),
            (string) $accrual->accrual_period_key,
        );
    }

    public function test_accrue_records_wallet_interest_obligation(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '100.0000',
        );

        $this->service->accrue(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-05-15'),
        );

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Interest->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('100.0000', (string) $obligation->original_amount);
        $this->assertSame('100.0000', (string) $obligation->outstanding_amount);
    }

    public function test_accrue_obligation_carries_loan_reference(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Interest->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('Loan', (string) $obligation->reference_type);
        $this->assertSame((string) $loan->id, (string) $obligation->reference_id);
    }

    public function test_accrue_writes_obligation_id_back_to_accrual(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);

        $accrual = $this->service->accrue(
            $loan,
            $installment,
            asOf: Carbon::parse('2026-05-15'),
        );

        $this->assertNotNull($accrual);
        $this->assertTrue($accrual->hasWalletObligation());

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Interest->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame(
            (int) $obligation->id,
            (int) $accrual->wallet_obligation_id,
        );
    }

    public function test_accrue_increments_loan_outstanding_interest(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '100.0000',
        );

        // The loan's initial outstanding_interest must be zero (it is
        // set to zero on the active-loan fixture).
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $loan->refresh();
        $this->assertSame('100.0000', (string) $loan->outstanding_interest);
    }

    public function test_accrue_accumulates_across_installments(): void
    {
        $loan = $this->makeActiveLoan();

        $installment1 = $this->makeInstallment($loan, number: 1, expectedInterest: '100.0000');
        $installment2 = $this->makeInstallment($loan, number: 2, expectedInterest: '50.0000');

        $this->service->accrue($loan, $installment1, asOf: Carbon::parse('2026-05-15'));
        $this->service->accrue($loan, $installment2, asOf: Carbon::parse('2026-05-15'));

        $loan->refresh();
        $this->assertSame('150.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // Idempotency — one accrual per installment
    // =========================================================================

    public function test_accrue_is_idempotent_for_the_same_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '100.0000',
        );

        $first = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
        $this->assertNotNull($first);

        // Second call returns null (already accrued).
        $second = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-16'));
        $this->assertNull($second);

        // Exactly one accrual row exists.
        $this->assertSame(
            1,
            LoanInterestAccrual::query()
                ->where('loan_id', $loan->id)
                ->where('installment_id', $installment->id)
                ->count(),
        );
    }

    public function test_accrue_does_not_double_increment_on_retry(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '100.0000',
        );

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-16'));

        $loan->refresh();
        $this->assertSame('100.0000', (string) $loan->outstanding_interest);

        // Only one Wallet obligation.
        $this->assertSame(
            1,
            Obligation::query()
                ->where('wallet_id', $this->wallet->id)
                ->where('component', ObligationComponent::Interest->value)
                ->count(),
        );
    }

    // =========================================================================
    // Zero-interest installments
    // =========================================================================

    public function test_accrue_skips_zero_interest_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '0.0000',
        );

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        // The service returns null and writes no accrual row.
        $this->assertNull($accrual);

        $this->assertSame(
            0,
            LoanInterestAccrual::query()
                ->where('loan_id', $loan->id)
                ->count(),
        );

        // No Wallet obligation.
        $this->assertSame(
            0,
            Obligation::query()
                ->where('wallet_id', $this->wallet->id)
                ->count(),
        );
    }

    public function test_accrue_zero_interest_does_not_change_loan_projection(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            expectedInterest: '0.0000',
        );

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // Loan state guard
    // =========================================================================

    public function test_accrue_rejects_draft_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Draft);
        $installment = $this->makeInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_rejects_approved_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Approved);
        $installment = $this->makeInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_rejects_rejected_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Rejected);
        $installment = $this->makeInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_rejects_settled_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Settled);
        $installment = $this->makeInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    // =========================================================================
    // Eligible loan states
    // =========================================================================

    public function test_accrue_accepts_active_loan(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $this->assertNotNull($accrual);
    }

    public function test_accrue_accepts_in_arrears_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::InArrears);
        $installment = $this->makeInstallment($loan, number: 1);

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $this->assertNotNull($accrual);
    }

    public function test_accrue_accepts_restructured_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Restructured);
        $installment = $this->makeInstallment($loan, number: 1);

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $this->assertNotNull($accrual);
    }

    // =========================================================================
    // Installment state guard
    // =========================================================================

    public function test_accrue_rejects_settled_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Settled,
        );

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_rejects_superseded_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Superseded,
        );

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_accepts_partial_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Partial,
        );

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $this->assertNotNull($accrual);
    }

    public function test_accrue_accepts_overdue_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Overdue,
        );

        $accrual = $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

        $this->assertNotNull($accrual);
    }

    // =========================================================================
    // Cross-loan guard
    // =========================================================================

    public function test_accrue_rejects_installment_from_another_loan(): void
    {
        $loan      = $this->makeActiveLoan();
        $otherLoan = $this->makeActiveLoan();

        // Installment belongs to the other loan.
        $otherInstallment = $this->makeInstallment($otherLoan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue(
            $loan,
            $otherInstallment,
            asOf: Carbon::parse('2026-05-15'),
        );
    }

    // =========================================================================
    // Wallet presence
    // =========================================================================

    public function test_accrue_rejects_borrower_without_wallet(): void
    {
        $walletless = $this->makeBorrower($this->tenant);
        $loan       = $this->makeActiveLoan(borrower: $walletless);
        $installment = $this->makeInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    // =========================================================================
    // Persistence guards
    // =========================================================================

    public function test_accrue_rejects_unpersisted_loan(): void
    {
        $loan = new Loan();
        $loan->status = LoanStatus::Active;

        $installment = new LoanInstallment();

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));
    }

    public function test_accrue_rejects_unpersisted_installment(): void
    {
        $loan = $this->makeActiveLoan();

        $ghost = new LoanInstallment();
        $ghost->loan_id = $loan->id;

        $this->expectException(LogicException::class);

        $this->service->accrue($loan, $ghost, asOf: Carbon::parse('2026-05-15'));
    }

    // =========================================================================
    // Atomicity
    // =========================================================================

    public function test_accrue_rolls_back_on_wallet_failure(): void
    {
        // The loan's borrower has no Wallet — the accrual row insert
        // happens BEFORE the service resolves the Wallet, so the whole
        // transaction must roll back: no accrual row, no projection
        // change.
        $walletless  = $this->makeBorrower($this->tenant);
        $loan        = $this->makeActiveLoan(borrower: $walletless);
        $installment = $this->makeInstallment($loan, number: 1);

        $countBefore = LoanInterestAccrual::query()->where('loan_id', $loan->id)->count();

        try {
            $this->service->accrue($loan, $installment, asOf: Carbon::parse('2026-05-15'));

            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // Expected.
        }

        // No accrual row was left behind.
        $this->assertSame(
            $countBefore,
            LoanInterestAccrual::query()->where('loan_id', $loan->id)->count(),
        );

        // The loan's projection is unchanged.
        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
    }

    // =========================================================================
    // asOf semantics
    // =========================================================================

    public function test_accrue_records_asOf_on_accrual_row(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);

        $asOf = Carbon::parse('2026-05-15 10:30:00');

        $accrual = $this->service->accrue($loan, $installment, asOf: $asOf);

        $this->assertNotNull($accrual);
        $this->assertSame(
            '2026-05-15',
            $accrual->recorded_at->toDateString(),
        );
    }

    public function test_accrue_defaults_asOf_to_now(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);

        $accrual = $this->service->accrue($loan, $installment);

        $this->assertNotNull($accrual);
        // The pinned clock is 2026-05-15.
        $this->assertSame(
            '2026-05-15',
            $accrual->recorded_at->toDateString(),
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
                'interest' => ['annual_rate' => '0.12'],
                'term'     => [
                    'principal_grace_months' => 0,
                    'interest_grace_months'  => 0,
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
            'min_term_months'               => 1,
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
            'opened_at'       => Carbon::now(),
        ]);
    }

    private function makeActiveLoan(?Shareholder $borrower = null): Loan
    {
        return $this->makeLoanInState(LoanStatus::Active, $borrower);
    }

    private function makeLoanInState(
        LoanStatus $status,
        ?Shareholder $borrower = null,
    ): Loan {
        $borrower ??= $this->borrower;

        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = $status;
        $loan->application_date         = Carbon::now();
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = '10000.0000';
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date            = Carbon::now()->addMonths(12);
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

    private function makeInstallment(
        Loan $loan,
        int $number = 1,
        string $expectedInterest = '100.0000',
        InstallmentStatus $status = InstallmentStatus::Pending,
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => Carbon::now()->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => $expectedInterest,
            'expected_total'     => bcadd('1000.0000', $expectedInterest, 4),
            'status'             => $status->value,
        ]);
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
            'occurred_at'       => Carbon::now(),
            'created_at'        => Carbon::now(),
        ]);
    }
}