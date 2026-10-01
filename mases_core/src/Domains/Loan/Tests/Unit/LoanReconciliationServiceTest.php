<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanReconciliationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanReconciliationServiceTest
 *
 * Unit-level coverage for `LoanReconciliationService` (A.36) — the
 * service that verifies the frozen C5 reconciliation invariant.
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
 * The reconciler is READ-ONLY: it opens no transaction, dispatches no
 * events, and writes no rows (see A.36's class docblock §"What this
 * service is NOT"). The `DatabaseMigrations` precondition (asserting on
 * after-commit events) does not apply. The `RefreshDatabase` default
 * from §2.13 applies and is materially faster than
 * `DatabaseMigrations` for a read-only suite of this size.
 *
 * The fixtures build the loan, the Wallet, and the obligations via
 * direct model inserts so that no after-commit callbacks are registered
 * on the fixture path.
 *
 * =============================================================================
 * The frozen C5 invariant
 * =============================================================================
 * The reconciler verifies:
 *
 *     Σ(wallet_obligations.outstanding_amount
 *         WHERE reference_type = 'Loan'
 *           AND reference_id   = (string) $loan->id)
 *       ==
 *     loans.outstanding_principal
 *       + loans.outstanding_interest
 *       + loans.outstanding_penalty
 *
 * The C5 freeze explicitly forbids comparing against
 * `wallets.current_balance` — the wallet balance includes non-loan
 * obligations and adjustments. A test below verifies the exclusion.
 *
 * =============================================================================
 * What this suite tests
 * =============================================================================
 *   1.  Report shape — the twelve top-level keys documented on A.36's
 *       `reconcile()` method.
 *   2.  `is_balanced` true for a well-formed loan.
 *   3.  `is_balanced` false for a deliberately unbalanced loan.
 *   4.  The C5 wallet-balance exclusion.
 *   5.  Pre-disbursement loan (no Wallet) — empty Wallet side, balanced.
 *   6.  `isBalanced()` — the fast boolean.
 *   7.  `assertBalanced()` — passes on match, throws on mismatch.
 *   8.  Determinism — a second invocation with the same inputs yields
 *       an identical result.
 *   9.  Per-installment breakdown — informational.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.0
 */
final class LoanReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoanReconciliationService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanReconciliationService::class);

        $this->tenant   = $this->makeTenant('reconciliation-tenant', 'የማስታረቅ ቴናንት');
        $this->policy   = $this->makePolicy();
        $this->product  = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00'));

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
    // Report shape
    // =========================================================================

    public function test_reconcile_returns_expected_top_level_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            [
                'loan_id',
                'currency',
                'status',
                'reconciled_at',
                'contractual',
                'loan_projected',
                'wallet_linked',
                'wallet_payments',
                'per_installment',
                'divergence',
                'is_balanced',
                'provenance',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $report);
        }
    }

    public function test_reconcile_report_carries_loan_id(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        $this->assertSame((int) $loan->id, (int) $report['loan_id']);
    }

    public function test_reconcile_report_carries_status_and_currency(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        $this->assertSame(LoanStatus::Active->value, $report['status']);
        $this->assertSame('ETB', $report['currency']);
    }

    public function test_reconcile_report_reconciled_at_is_iso8601(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            (string) $report['reconciled_at'],
        );
    }

    public function test_reconcile_provenance_block_has_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            ['wallet_id', 'reconciler', 'reconciler_version']
        as $key) {
            $this->assertArrayHasKey($key, $report['provenance']);
        }

        $this->assertSame(
            LoanReconciliationService::class,
            $report['provenance']['reconciler'],
        );
        $this->assertSame(
            (int) $this->wallet->id,
            (int) $report['provenance']['wallet_id'],
        );
    }

    // =========================================================================
    // is_balanced — in balance
    // =========================================================================

    public function test_reconcile_is_balanced_for_well_formed_loan(): void
    {
        // A loan whose loan-side projections and Wallet-side obligation
        // sums agree.
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');
        $this->makeObligationFor($loan, ObligationComponent::Interest, '250.0000');
        $this->makeObligationFor($loan, ObligationComponent::Penalty, '100.0000');

        $report = $this->service->reconcile($loan);

        $this->assertTrue($report['is_balanced']);
    }

    public function test_reconcile_divergence_total_is_zero_when_balanced(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');

        $report = $this->service->reconcile($loan);

        $this->assertSame('0.0000', (string) $report['divergence']['total']);
    }

    // =========================================================================
    // is_balanced — out of balance
    // =========================================================================

    public function test_reconcile_is_unbalanced_when_wallet_side_is_higher(): void
    {
        // Loan-side projections say 10000; Wallet-side obligations sum
        // to 11000. The divergence is 1000.
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '11000.0000');

        $report = $this->service->reconcile($loan);

        $this->assertFalse($report['is_balanced']);
        $this->assertSame('1000.0000', (string) $report['divergence']['total']);
    }

    public function test_reconcile_is_unbalanced_when_wallet_side_is_lower(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '9000.0000');

        $report = $this->service->reconcile($loan);

        $this->assertFalse($report['is_balanced']);
        $this->assertSame('-1000.0000', (string) $report['divergence']['total']);
    }

    public function test_reconcile_detects_one_unit_divergence(): void
    {
        // The smallest observable divergence: one unit in the last
        // place at scale 4.
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0001');

        $report = $this->service->reconcile($loan);

        $this->assertFalse($report['is_balanced']);
        $this->assertSame('0.0001', (string) $report['divergence']['total']);
    }

    // =========================================================================
    // The C5 wallet-balance exclusion
    // =========================================================================

    public function test_reconcile_ignores_unrelated_wallet_obligations(): void
    {
        // The wallet carries an obligation that does NOT reference the
        // loan (`reference_type = 'Wallet'`). The reconciler MUST NOT
        // include it. The loan is balanced because the reconciler
        // filters on `reference_type = 'Loan'`.
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');

        // A non-loan obligation on the same wallet.
        Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Fee->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => '500.0000',
            'outstanding_amount' => '500.0000',
            'reference_type'     => 'Wallet',
            'reference_id'       => (string) $this->wallet->id,
            'occurred_at'        => Carbon::now(),
        ]);

        $report = $this->service->reconcile($loan);

        // The reconciler excludes the non-loan obligation.
        $this->assertTrue($report['is_balanced']);
    }

    public function test_reconcile_does_not_compare_against_wallet_current_balance(): void
    {
        // A wallet whose `current_balance` disagrees with the loan's
        // projections. The reconciler MUST NOT compare against the
        // wallet's `current_balance` — the C5 correction explicitly
        // forbids it.
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');

        // Force the wallet's `current_balance` to a divergent value.
        $this->wallet->current_balance = '-9999.0000';
        $this->wallet->save();

        $report = $this->service->reconcile($loan);

        // Despite the divergent `current_balance`, the loan is balanced
        // because the reconciler compares loan-linked obligations only.
        $this->assertTrue($report['is_balanced']);
    }

    // =========================================================================
    // Pre-disbursement loan
    // =========================================================================

    public function test_reconcile_pre_disbursement_loan_is_balanced(): void
    {
        // A loan in `approved` state has no Wallet obligations. The
        // loan's projections are null (migration A.9 defaults), which
        // the reconciler treats as zero. The report is balanced.
        $loan = $this->makeApprovedLoan();

        $report = $this->service->reconcile($loan);

        $this->assertTrue($report['is_balanced']);
    }

    public function test_reconcile_pre_disbursement_loan_has_no_wallet_linked_obligations(): void
    {
        $loan = $this->makeApprovedLoan();

        $report = $this->service->reconcile($loan);

        // The wallet is present (fixture creates it) but has no
        // loan-linked obligations.
        $this->assertSame(0, (int) $report['wallet_linked']['obligation_count']);
        $this->assertSame('0.0000', (string) $report['wallet_linked']['outstanding_total']);
    }

    public function test_reconcile_loan_without_wallet_is_balanced(): void
    {
        // A loan whose borrower has no wallet at all. The reconciler
        // handles the missing wallet gracefully (returns null for
        // `resolveWalletFor()`).
        $borrowerless = $this->makeBorrower($this->tenant);
        $loan = $this->makeApprovedLoan(borrower: $borrowerless);

        $report = $this->service->reconcile($loan);

        $this->assertTrue($report['is_balanced']);
        $this->assertNull($report['wallet_linked']['wallet_id']);
    }

    // =========================================================================
    // isBalanced() — the fast boolean
    // =========================================================================

    public function test_is_balanced_returns_true_for_well_formed_loan(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');

        $this->assertTrue($this->service->isBalanced($loan));
    }

    public function test_is_balanced_returns_false_for_unbalanced_loan(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '9000.0000');

        $this->assertFalse($this->service->isBalanced($loan));
    }

    // =========================================================================
    // assertBalanced() — the transaction-boundary guard
    // =========================================================================

    public function test_assert_balanced_does_not_throw_for_balanced_loan(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');

        // No exception.
        $this->service->assertBalanced($loan);

        $this->assertTrue(true); // The absence of an exception is the assertion.
    }

    public function test_assert_balanced_throws_logic_exception_for_unbalanced_loan(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '9000.0000');

        $this->expectException(LogicException::class);

        $this->service->assertBalanced($loan);
    }

    public function test_assert_balanced_exception_message_names_loan_id(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '0.0000',
            outstandingPenalty:   '0.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '9000.0000');

        try {
            $this->service->assertBalanced($loan);

            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException $e) {
            $this->assertStringContainsString(
                (string) $loan->id,
                $e->getMessage(),
            );
        }
    }

    // =========================================================================
    // Report structure — nested sections
    // =========================================================================

    public function test_reconcile_loan_projected_section_has_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            [
                'outstanding_principal',
                'outstanding_interest',
                'outstanding_penalty',
                'settled_amount',
                'outstanding_total',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $report['loan_projected']);
        }
    }

    public function test_reconcile_wallet_linked_section_has_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            [
                'wallet_id',
                'obligation_count',
                'settled_obligation_count',
                'outstanding_principal',
                'outstanding_interest',
                'outstanding_penalty',
                'outstanding_total',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $report['wallet_linked']);
        }
    }

    public function test_reconcile_wallet_linked_section_does_not_contain_current_balance(): void
    {
        // The C5 correction explicitly forbids comparing against
        // `wallets.current_balance`. The report's `wallet_linked`
        // section must not carry that key.
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        $this->assertArrayNotHasKey(
            'current_balance',
            $report['wallet_linked'],
        );
    }

    public function test_reconcile_divergence_section_has_per_component_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (['principal', 'interest', 'penalty', 'total'] as $key) {
            $this->assertArrayHasKey($key, $report['divergence']);
        }
    }

    public function test_reconcile_contractual_section_has_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            [
                'total_expected_principal',
                'total_expected_interest',
                'total_expected_repayment',
                'active_installment_count',
                'settled_installment_count',
                'overdue_installment_count',
                'pending_or_partial_installment_count',
                'superseded_installment_count',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $report['contractual']);
        }
    }

    public function test_reconcile_wallet_payments_section_has_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();

        $report = $this->service->reconcile($loan);

        foreach (
            [
                'payment_count',
                'total_received',
                'total_allocated',
                'total_unapplied',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $report['wallet_payments']);
        }
    }

    // =========================================================================
    // Payments aggregation
    // =========================================================================

    public function test_reconcile_counts_loan_linked_payments(): void
    {
        $loan = $this->makeDisbursedLoan();

        Payment::create([
            'wallet_id'        => $this->wallet->id,
            'currency'         => $this->wallet->currency,
            'amount'           => '500.0000',
            'unapplied_amount' => '500.0000',
            'reference_type'   => 'LoanRepayment',
            'reference_id'     => (string) $loan->id,
            'received_at'      => Carbon::now(),
        ]);

        $report = $this->service->reconcile($loan);

        $this->assertSame(1, (int) $report['wallet_payments']['payment_count']);
        $this->assertSame('500.0000', (string) $report['wallet_payments']['total_received']);
    }

    public function test_reconcile_ignores_unrelated_payments(): void
    {
        $loan = $this->makeDisbursedLoan();

        // A payment with a non-loan reference type.
        Payment::create([
            'wallet_id'        => $this->wallet->id,
            'currency'         => $this->wallet->currency,
            'amount'           => '500.0000',
            'unapplied_amount' => '500.0000',
            'reference_type'   => 'Transfer',
            'reference_id'     => '42',
            'received_at'      => Carbon::now(),
        ]);

        $report = $this->service->reconcile($loan);

        $this->assertSame(0, (int) $report['wallet_payments']['payment_count']);
    }

    // =========================================================================
    // Determinism
    // =========================================================================

    public function test_reconcile_is_deterministic(): void
    {
        $loan = $this->makeDisbursedLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->makeObligationFor($loan, ObligationComponent::Principal, '10000.0000');
        $this->makeObligationFor($loan, ObligationComponent::Interest, '250.0000');
        $this->makeObligationFor($loan, ObligationComponent::Penalty, '100.0000');

        $asOf = Carbon::parse('2026-11-01 12:00:00');

        $first  = $this->service->reconcile($loan, $asOf);
        $second = $this->service->reconcile($loan, $asOf);

        // Byte-for-byte equality across the entire report.
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Read-only invariant
    // =========================================================================

    public function test_reconcile_does_not_change_wallet_balance(): void
    {
        $loan = $this->makeDisbursedLoan();

        $this->wallet->refresh();
        $before = (string) $this->wallet->current_balance;

        $this->service->reconcile($loan);

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_reconcile_does_not_write_any_rows(): void
    {
        $loan = $this->makeDisbursedLoan();

        $obligationCountBefore = Obligation::query()->count();
        $paymentCountBefore    = Payment::query()->count();
        $walletCountBefore     = Wallet::query()->count();

        $this->service->reconcile($loan);

        $this->assertSame($obligationCountBefore, Obligation::query()->count());
        $this->assertSame($paymentCountBefore, Payment::query()->count());
        $this->assertSame($walletCountBefore, Wallet::query()->count());
    }

    // =========================================================================
    // Per-installment breakdown
    // =========================================================================

    public function test_reconcile_per_installment_is_a_list(): void
    {
        $loan = $this->makeDisbursedLoan();
        $this->makeInstallment($loan, number: 1);
        $this->makeInstallment($loan, number: 2);

        $report = $this->service->reconcile($loan);

        $this->assertIsArray($report['per_installment']);
        $this->assertCount(2, $report['per_installment']);
    }

    public function test_reconcile_per_installment_entries_are_ordered_by_number(): void
    {
        $loan = $this->makeDisbursedLoan();
        $this->makeInstallment($loan, number: 1);
        $this->makeInstallment($loan, number: 2);
        $this->makeInstallment($loan, number: 3);

        $report = $this->service->reconcile($loan);

        $numbers = array_map(
            static fn (array $row): int => (int) $row['installment_number'],
            $report['per_installment'],
        );

        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_reconcile_per_installment_entries_have_expected_keys(): void
    {
        $loan = $this->makeDisbursedLoan();
        $this->makeInstallment($loan, number: 1);

        $report = $this->service->reconcile($loan);

        $entry = $report['per_installment'][0];

        foreach (
            [
                'installment_id',
                'installment_number',
                'due_date',
                'status',
                'expected_principal',
                'expected_interest',
                'expected_total',
                'settled_amount',
                'remaining_total',
                'wallet_interest_outstanding',
                'wallet_penalty_outstanding',
                'wallet_linked_outstanding',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $entry);
        }
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

    private function makeDisbursedLoan(
        ?Shareholder $borrower = null,
        string $outstandingPrincipal = '10000.0000',
        string $outstandingInterest = '0.0000',
        string $outstandingPenalty = '0.0000',
    ): Loan {
        $borrower ??= $this->borrower;

        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::Active;
        $loan->application_date         = Carbon::now();
        $loan->decision_date            = Carbon::now();
        $loan->disbursement_date        = Carbon::now()->subMonths(3);
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = $outstandingPrincipal;
        $loan->outstanding_interest     = $outstandingInterest;
        $loan->outstanding_penalty      = $outstandingPenalty;
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date            = Carbon::now()->addMonths(9);
        $loan->frozen_policy_version_id = $this->policy->id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

        return $loan;
    }

    private function makeApprovedLoan(?Shareholder $borrower = null): Loan
    {
        $borrower ??= $this->borrower;

        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::Approved;
        $loan->application_date         = Carbon::now();
        $loan->decision_date            = Carbon::now();
        $loan->frozen_policy_version_id = $this->policy->id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);

        return $loan;
    }

    private function makeInstallment(
        Loan $loan,
        int $number = 1,
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => Carbon::now()->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => '20.0000',
            'expected_total'     => '1020.0000',
        ]);
    }

    private function makeObligationFor(
        Loan $loan,
        ObligationComponent $component,
        string $amount,
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => $component->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => Carbon::now(),
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