<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanInstallmentSettled;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRepaymentRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanSettled;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRepaymentService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanRepaymentServiceTest
 *
 * Unit-level coverage for `LoanRepaymentService` (A.31) — the
 * REPAYMENT phase of a loan's lifecycle.
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
 * 1.0.1 — R-23 fixture FK fix. Every `record()` call in this suite
 *         passed the hardcoded actor id `7`. The service writes the
 *         actor id to `wallet_payments.created_by`, which carries an
 *         FK to `users.id`. Under `DatabaseMigrations`, no such user
 *         rows exist, so the payment write failed the FK constraint
 *         and every `record()` call that reached the Wallet write
 *         failed. The fix creates a `User` fixture in `setUp()` and
 *         passes `$this->actor->id`.
 *
 *         No behavior changes; no production code touched; no assertion
 *         meaning changed.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `record()` dispatches up to three events — `LoanRepaymentRecorded`
 * (A.42), `LoanInstallmentSettled` (A.43), and `LoanSettled` (A.48) — all
 * via `DB::afterCommit()`. Under `RefreshDatabase`, the framework's
 * per-test transaction never commits, so the after-commit callbacks
 * never fire and `Event::fake()` cannot observe the dispatches.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callbacks.
 *
 * =============================================================================
 * The frozen repayment flow (A.31)
 * =============================================================================
 * Per A.31's class docblock, `record()` performs the following sequence
 * inside ONE transaction:
 *
 *   1. Reads the loan without a lock.
 *   2. Asserts the loan's status is `disbursed`, `active`, `in_arrears`,
 *      or `restructured`.
 *   3. Resolves the borrower's Wallet.
 *   4. Asserts the Wallet's currency matches the loan's currency (Q8).
 *   5. Validates the allocation instructions (well-formed, sums match,
 *      every obligation belongs to this loan).
 *   6. Records the Wallet payment via `PaymentService::record()`.
 *   7. Applies each allocation via `AllocationService::allocate()`.
 *   8. Updates the loan's `settled_amount`.
 *   9. Recomputes the loan's outstanding projections from the Wallet.
 *  10. Settles any installments whose remaining obligations are now
 *      zero (under interpretation (A)), collecting
 *      `LoanInstallmentSettled` events.
 *  11. Detects and records full loan settlement if applicable,
 *      dispatching `LoanSettled` after commit.
 *  12. Registers `LoanRepaymentRecorded` for after-commit dispatch.
 *  13. Returns the refreshed loan.
 *
 * The tests below are anchored to specific steps.
 *
 * =============================================================================
 * Fixture baseline — Wallet obligations for a loan
 * =============================================================================
 * The fixtures build a disbursed loan with:
 *   - ONE principal obligation for the whole principal,
 *   - ONE interest obligation per installment.
 *
 * The principal obligation is created with `reference_type = 'Loan'` and
 * `reference_id = (string) $loan->id`, matching the frozen B1 decision.
 * The interest obligations follow the same convention.
 *
 * The installments themselves are created with `status = pending` and
 * `settled_*` at zero, mirroring the schedule-generation output
 * (`LoanScheduleService::generate()`, A.27).
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanRepaymentServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanRepaymentService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    /**
     * The actor-user fixture for every record call this suite makes
     * with a non-null actor.
     *
     * The service does not read the user; the row is created solely to
     * satisfy the `wallet_payments.created_by` FK.
     */
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanRepaymentService::class);

        $this->tenant  = $this->makeTenant('repayment-tenant', 'የመክፈል ቴናንት');
        $this->policy  = $this->makePolicy();
        $this->product = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        // The FK target for `wallet_payments.created_by`. See the
        // class docblock §"Revision history (1.0.1)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00'));

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
    // Happy path — partial repayment
    // =========================================================================

    public function test_record_creates_payment_row(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $this->assertDatabaseHas('wallet_payments', [
            'wallet_id' => $this->wallet->id,
            'amount'    => '500.0000',
        ]);
    }

    public function test_record_creates_payment_allocation_row(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $this->assertDatabaseHas('wallet_payment_allocations', [
            'wallet_id'     => $this->wallet->id,
            'obligation_id' => $principalObligation->id,
            'amount'        => '500.0000',
        ]);
    }

    public function test_record_updates_loan_settled_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $loan->refresh();
        $this->assertSame('500.0000', (string) $loan->settled_amount);
    }

    public function test_record_reduces_outstanding_principal(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000');
        $principalObligation = $this->makePrincipalObligation($loan, '10000.0000');

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $loan->refresh();
        $this->assertSame('9500.0000', (string) $loan->outstanding_principal);
    }

    public function test_record_reduces_obligation_outstanding(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan, '10000.0000');

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $principalObligation->refresh();
        $this->assertSame('9500.0000', (string) $principalObligation->outstanding_amount);
    }

    public function test_record_marks_payment_as_applied(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $payment = Payment::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($payment);
        $this->assertSame('0.0000', (string) $payment->unapplied_amount);
        $this->assertTrue($payment->isFullyApplied());
    }

    // =========================================================================
    // Happy path — settlement of one installment
    // =========================================================================

    public function test_record_settles_installment_when_interest_obligation_cleared(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 2);
        $principalObligation = $this->makePrincipalObligation($loan, '2000.0000');
        $installment = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->first();

        // Interest obligation for the first installment.
        $interestObligation = $this->makeInterestObligation($loan, $installment, '20.0000');

        // Clear the entire principal obligation AND the first
        // installment's interest obligation.
        $this->service->record(
            loan:        $loan,
            amount:      '2020.0000',
            allocations: [
                ['obligation_id' => (int) $principalObligation->id, 'amount' => '2000.0000'],
                ['obligation_id' => (int) $interestObligation->id,  'amount' => '20.0000'],
            ],
            actorUserId: $this->actor->id,
        );

        $installment->refresh();
        $this->assertSame(InstallmentStatus::Settled, $installment->status);
    }

    public function test_record_marks_installment_settled_event(): void
    {
        Event::fake([LoanInstallmentSettled::class]);

        $loan = $this->makeActiveLoanWithSchedule(termMonths: 2);
        $principalObligation = $this->makePrincipalObligation($loan, '2000.0000');
        $installment = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->first();

        $interestObligation = $this->makeInterestObligation($loan, $installment, '20.0000');

        $this->service->record(
            loan:        $loan,
            amount:      '2020.0000',
            allocations: [
                ['obligation_id' => (int) $principalObligation->id, 'amount' => '2000.0000'],
                ['obligation_id' => (int) $interestObligation->id,  'amount' => '20.0000'],
            ],
            actorUserId: $this->actor->id,
        );

        // Closure captures `$loan` and `$installment` — MUST NOT be
        // declared `static fn`.
        Event::assertDispatched(
            LoanInstallmentSettled::class,
            function (LoanInstallmentSettled $event) use ($loan, $installment): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->installmentId() === (int) $installment->id;
            },
        );
    }

    // =========================================================================
    // Happy path — full loan settlement
    // =========================================================================

    public function test_record_settles_loan_when_every_installment_settled(): void
    {
        Event::fake([LoanSettled::class]);

        $loan = $this->makeActiveLoanWithSchedule(termMonths: 2);
        $principalObligation = $this->makePrincipalObligation($loan, '2000.0000');

        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->get();

        $interest1 = $this->makeInterestObligation($loan, $installments[0], '20.0000');
        $interest2 = $this->makeInterestObligation($loan, $installments[1], '10.0000');

        $result = $this->service->record(
            loan:        $loan,
            amount:      '2030.0000',
            allocations: [
                ['obligation_id' => (int) $principalObligation->id, 'amount' => '2000.0000'],
                ['obligation_id' => (int) $interest1->id,           'amount' => '20.0000'],
                ['obligation_id' => (int) $interest2->id,           'amount' => '10.0000'],
            ],
            actorUserId: $this->actor->id,
        );

        $this->assertSame(LoanStatus::Settled, $result->status);
        $this->assertSame(LoanStatus::Settled, $loan->fresh()->status);

        Event::assertDispatched(
            LoanSettled::class,
            function (LoanSettled $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id;
            },
        );
    }

    public function test_record_zeros_outstanding_projections_on_full_settlement(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 2);
        $principalObligation = $this->makePrincipalObligation($loan, '2000.0000');
        $installments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->get();

        $interest1 = $this->makeInterestObligation($loan, $installments[0], '20.0000');
        $interest2 = $this->makeInterestObligation($loan, $installments[1], '10.0000');

        $this->service->record(
            loan:        $loan,
            amount:      '2030.0000',
            allocations: [
                ['obligation_id' => (int) $principalObligation->id, 'amount' => '2000.0000'],
                ['obligation_id' => (int) $interest1->id,           'amount' => '20.0000'],
                ['obligation_id' => (int) $interest2->id,           'amount' => '10.0000'],
            ],
            actorUserId: $this->actor->id,
        );

        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_principal);
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
        $this->assertSame('0.0000', (string) $loan->outstanding_penalty);
    }

    // =========================================================================
    // Validation — amount shape
    // =========================================================================

    public function test_record_rejects_malformed_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      'not-a-number',
                allocations: [[
                    'obligation_id' => (int) $principalObligation->id,
                    'amount'        => 'not-a-number',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    public function test_record_rejects_zero_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '0.0000',
                allocations: [[
                    'obligation_id' => (int) $principalObligation->id,
                    'amount'        => '0.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    // =========================================================================
    // Validation — allocations
    // =========================================================================

    public function test_record_rejects_empty_allocations(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $this->makePrincipalObligation($loan);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }
    }

    public function test_record_rejects_allocation_sum_mismatch(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [[
                    'obligation_id' => (int) $principalObligation->id,
                    'amount'        => '400.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }
    }

    public function test_record_rejects_cross_loan_obligation(): void
    {
        // A second loan with its own principal obligation; the first
        // loan's repayment attempts to allocate to the second loan's
        // obligation.
        $loan = $this->makeActiveLoanWithSchedule();
        $this->makePrincipalObligation($loan);

        $otherLoan = $this->makeActiveLoanWithSchedule();
        $otherObligation = $this->makePrincipalObligation($otherLoan);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [[
                    'obligation_id' => (int) $otherObligation->id,
                    'amount'        => '500.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations.0.obligation_id', $e->errors());
        }
    }

    public function test_record_rejects_nonexistent_obligation(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $this->makePrincipalObligation($loan);

        // A wholly non-existent obligation id produces a
        // ModelNotFoundException (the repository returns null and the
        // service raises the exception).
        $this->expectException(ModelNotFoundException::class);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => 999999,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_record_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [[
                    'obligation_id' => 1,
                    'amount'        => '500.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_record_rejects_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [[
                    'obligation_id' => 1,
                    'amount'        => '500.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_record_rejects_settled_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Settled);

        try {
            $this->service->record(
                loan:        $loan,
                amount:      '500.0000',
                allocations: [[
                    'obligation_id' => 1,
                    'amount'        => '500.0000',
                ]],
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // Wallet presence and currency
    // =========================================================================

    public function test_record_rejects_borrower_without_wallet(): void
    {
        $walletless = $this->makeBorrower($this->tenant);
        $loan = $this->makeActiveLoanWithSchedule(borrower: $walletless);

        $this->expectException(LogicException::class);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => 1,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );
    }

    public function test_record_rejects_currency_mismatch(): void
    {
        // The loan has been constructed with the wallet's currency
        // through disbursement, but the wallet has been changed to a
        // different currency out-of-band. The service must reject.
        $loan = $this->makeActiveLoanWithSchedule();

        // Force the loan's currency to a mismatched value directly.
        $loan->currency = 'USD';
        $loan->save();

        $principalObligation = $this->makePrincipalObligation($loan);

        $this->expectException(LogicException::class);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );
    }

    // =========================================================================
    // After-commit event — LoanRepaymentRecorded
    // =========================================================================

    public function test_record_dispatches_loan_repayment_recorded_event(): void
    {
        Event::fake([LoanRepaymentRecorded::class]);

        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanRepaymentRecorded::class,
            function (LoanRepaymentRecorded $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->amount() === '500.0000'
                    && $event->allocationCount() === 1;
            },
        );
    }

    public function test_record_event_carries_payment_id(): void
    {
        Event::fake([LoanRepaymentRecorded::class]);

        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        $payment = Payment::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($payment);

        Event::assertDispatched(
            LoanRepaymentRecorded::class,
            function (LoanRepaymentRecorded $event) use ($payment): bool {
                return $event->paymentId() === (int) $payment->id;
            },
        );
    }

    public function test_record_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanRepaymentRecorded::class]);

        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        $this->service->record(
            loan:        $loan,
            amount:      '500.0000',
            allocations: [[
                'obligation_id' => (int) $principalObligation->id,
                'amount'        => '500.0000',
            ]],
            actorUserId: $this->actor->id,
        );

        Event::assertNotDispatched(LoanRepaymentRecorded::class);

        // The repayment itself commits regardless.
        $loan->refresh();
        $this->assertSame('500.0000', (string) $loan->settled_amount);
    }

    public function test_record_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanRepaymentRecorded::class]);

        $loan = $this->makeActiveLoanWithSchedule();
        $principalObligation = $this->makePrincipalObligation($loan);

        try {
            DB::transaction(function () use ($loan, $principalObligation): void {
                $this->service->record(
                    loan:        $loan,
                    amount:      '500.0000',
                    allocations: [[
                        'obligation_id' => (int) $principalObligation->id,
                        'amount'        => '500.0000',
                    ]],
                    actorUserId: $this->actor->id,
                );

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(LoanRepaymentRecorded::class);

        // Nothing was persisted.
        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->settled_amount);

        $principalObligation->refresh();
        $this->assertSame('10000.0000', (string) $principalObligation->outstanding_amount);
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

    /**
     * Build a loan in `active` state with a schedule of `$termMonths`
     * installments. Writes the baseline history rows.
     */
    private function makeActiveLoanWithSchedule(
        ?Shareholder $borrower = null,
        string $principal = '10000.0000',
        int $termMonths = 2,
    ): Loan {
        $borrower ??= $this->borrower;

        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = $principal;
        $loan->term_months              = $termMonths;
        $loan->status                   = LoanStatus::Active;
        $loan->application_date         = Carbon::now();
        $loan->decision_date            = Carbon::now();
        $loan->disbursement_date        = Carbon::now();
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = $principal;
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = $principal;
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date            = Carbon::now()->addMonths($termMonths);
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

        // Create installments.
        $perMonth = bcdiv($principal, (string) $termMonths, 4);
        for ($i = 1; $i <= $termMonths; $i++) {
            LoanInstallment::create([
                'loan_id'            => $loan->id,
                'installment_number' => $i,
                'due_date'           => Carbon::now()->addMonths($i)->toDateString(),
                'expected_principal' => $perMonth,
                'expected_interest'  => '0.0000',
                'expected_total'     => $perMonth,
            ]);
        }

        return $loan;
    }

    /**
     * Build a loan in an arbitrary state (used by the state-guard
     * tests).
     */
    private function makeLoanInState(LoanStatus $status): Loan
    {
        $loan = new Loan();
        $loan->tenant_id               = $this->tenant->id;
        $loan->borrower_shareholder_id = $this->borrower->id;
        $loan->loan_product_id         = $this->product->id;
        $loan->principal_amount        = '10000.0000';
        $loan->term_months             = 12;
        $loan->status                  = $status;
        $loan->application_date        = Carbon::now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->save();

        return $loan;
    }

    /**
     * Create a principal obligation for the loan, referenced back to
     * the loan via the frozen B1 reference pair.
     */
    private function makePrincipalObligation(
        Loan $loan,
        string $amount = '10000.0000',
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Principal->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => Carbon::now(),
        ]);
    }

    /**
     * Create an interest obligation for the given installment.
     */
    private function makeInterestObligation(
        Loan $loan,
        LoanInstallment $installment,
        string $amount = '20.0000',
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Interest->value,
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