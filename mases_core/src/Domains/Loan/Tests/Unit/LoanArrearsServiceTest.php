<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use LogicException;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanEnteredArrears;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanPenaltyRecorded;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanPenaltyAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanArrearsService;
use MaaleArna\MASES\Core\Domains\Loan\Support\LoanPolicyKeys;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanArrearsServiceTest
 *
 * Unit-level coverage for `LoanArrearsService` (A.33) — the service that
 * records ONE penalty obligation per overdue installment, at the moment
 * the installment crosses its grace threshold without full settlement.
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
 * 1.0.1 — `makeOverdueInstallment()` fixture corrected. The helper
 *         previously passed `'status' => $status->value` through
 *         `LoanInstallment::create([...])`. The `status` column is
 *         DELIBERATELY absent from `LoanInstallment`'s `$fillable` array
 *         (see that model's class docblock §"Partial mutability": status
 *         is initialised by the migration's default `'pending'` and
 *         transitions only through the services). The fillable guard
 *         silently dropped the key, so every row was created with the
 *         DB default `pending` regardless of the fixture's intent. The
 *         tests that relied on a NON-default status —
 *         `test_detect_rejects_settled_installment`,
 *         `test_detect_rejects_overdue_installment`,
 *         `test_detect_rejects_superseded_installment`, and every test
 *         whose in-memory instance needed a populated `status` — failed
 *         with the wrong exception or with `null is identical to an
 *         object of class InstallmentStatus`.
 *
 *         The fix assigns `status` AFTER the `create()` call, which
 *         bypasses the fillable guard without weakening it. The model's
 *         design intent (status is not mass-assignable) is preserved.
 *
 *         This is the same defect class as the v1.0.1 fix in
 *         `LoanInterestAccrualTest.php`.
 *
 *         The two-sided correction to the paired production service is
 *         in `LoanArrearsService.php` v1.0.2: the error path in
 *         `assertInstallmentIsPenaltyEligible()` is now null-safe, so
 *         the three `rejects_*_installment` tests pass for the reason
 *         they were written to test (the status IS settled / overdue /
 *         superseded), not because the status was silently null.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `detect()` dispatches up to two events — `LoanEnteredArrears` (A.44)
 * and `LoanPenaltyRecorded` (A.45) — via the state machine's
 * `DB::afterCommit()` path (for the arrears transition) and the
 * service's own `DB::afterCommit()` registration (for the penalty).
 * Under `RefreshDatabase`, the framework's per-test transaction never
 * commits, so the after-commit callbacks never fire and `Event::fake()`
 * cannot observe the dispatches. `DatabaseMigrations` avoids the
 * wrapping transaction; the service's own `DB::transaction(...)` becomes
 * the outermost one, and its commit triggers the after-commit callbacks.
 *
 * =============================================================================
 * THE FROZEN ARREARS MODEL — one penalty per installment, not daily
 * =============================================================================
 * Per the D133 decision and A.33's class docblock, an installment can be
 * penalized AT MOST ONCE. The unique constraint on
 * `loan_penalty_accruals.accrual_period_key` (migration A.16) is the
 * structural enforcement. The tests below verify:
 *
 *   - First call inserts the penalty row and returns the model.
 *   - Second call (same loan, same installment) is a no-op: returns
 *     null, inserts no duplicate row, does not double-increment the
 *     loan's `outstanding_penalty`.
 *
 * =============================================================================
 * The once-per-loan `in_arrears` transition
 * =============================================================================
 * On the FIRST overdue installment, `detect()` performs the
 * `active → in_arrears` state transition in the same transaction as the
 * penalty recording, and fires `LoanEnteredArrears` (A.44) after
 * commit. On subsequent overdue installments, the loan is already
 * `in_arrears`; no transition occurs, and only `LoanPenaltyRecorded`
 * (A.45) is dispatched.
 *
 * The tests below verify this once-per-loan invariant directly.
 *
 * =============================================================================
 * Fixture baseline
 * =============================================================================
 * The fixtures build an active loan with installments in `pending`
 * state and a policy whose `arrears` section is enabled with a
 * configurable penalty rate and grace period.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanArrearsServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanArrearsService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanArrearsService::class);

        $this->tenant   = $this->makeTenant('arrears-tenant', 'የእዳ ቴናንት');
        $this->policy   = $this->makePolicy();
        $this->product  = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));

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
    // Happy path — penalty recording
    // =========================================================================

    public function test_detect_creates_penalty_accrual_row(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $accrual = $this->service->detect(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);
        $this->assertSame(
            sprintf('%d:%d', $loan->id, $installment->id),
            (string) $accrual->accrual_period_key,
        );
    }

    public function test_detect_records_wallet_penalty_obligation(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            overdueAmount: '1000.0000',
        );

        $this->service->detect(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-06-01'),
        );

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Penalty->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('Loan', (string) $obligation->reference_type);
        $this->assertSame((string) $loan->id, (string) $obligation->reference_id);
    }

    public function test_detect_penalty_uses_configured_rate(): void
    {
        // The policy's penalty rate is 0.05 (5%). The overdue amount is
        // 1000.0000, so the penalty is 1000 × 0.05 = 50.0000.
        $loan        = $this->makeActiveLoan(policy: $this->makePolicy(penaltyRate: '0.05'));
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            overdueAmount: '1000.0000',
        );

        $accrual = $this->service->detect(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);
        $this->assertSame('50.0000', (string) $accrual->penalty_amount);
    }

    public function test_detect_applies_cap_when_configured(): void
    {
        // The policy caps the penalty at 20.0000. With a rate of 0.05
        // and an overdue amount of 1000.0000, the uncapped penalty is
        // 50.0000. The cap reduces it to 20.0000.
        $policy = $this->makePolicy(
            penaltyRate: '0.05',
            cap: '20.0000',
        );
        $loan        = $this->makeActiveLoan(policy: $policy);
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            overdueAmount: '1000.0000',
        );

        $accrual = $this->service->detect(
            loan:        $loan,
            installment: $installment,
            asOf:        Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);
        $this->assertSame('20.0000', (string) $accrual->penalty_amount);
    }

    public function test_detect_writes_obligation_id_back_to_accrual(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $accrual = $this->service->detect(
            $loan,
            $installment,
            asOf: Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);
        $this->assertTrue($accrual->hasWalletObligation());

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Penalty->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame(
            (int) $obligation->id,
            (int) $accrual->wallet_obligation_id,
        );
    }

    public function test_detect_increments_loan_outstanding_penalty(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            overdueAmount: '1000.0000',
        );

        $this->assertSame('0.0000', (string) $loan->outstanding_penalty);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        $loan->refresh();
        $this->assertNotSame('0.0000', (string) $loan->outstanding_penalty);
    }

    public function test_detect_transitions_installment_to_overdue(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->assertSame(InstallmentStatus::Pending, $installment->status);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        $installment->refresh();
        $this->assertSame(InstallmentStatus::Overdue, $installment->status);
    }

    // =========================================================================
    // The once-per-loan in_arrears transition
    // =========================================================================

    public function test_detect_transitions_active_loan_to_in_arrears(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $result = $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        // The returned accrual does not carry the loan, but the loan
        // row is transitioned in the same transaction.
        $this->assertNotNull($result);
        $this->assertSame(LoanStatus::InArrears, $loan->fresh()->status);
    }

    public function test_detect_dispatches_loan_entered_arrears_on_first_overdue(): void
    {
        Event::fake([LoanEnteredArrears::class]);

        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        // Closure captures `$loan` and `$installment` — MUST NOT be
        // declared `static fn`.
        Event::assertDispatched(
            LoanEnteredArrears::class,
            function (LoanEnteredArrears $event) use ($loan, $installment): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->installmentId() === (int) $installment->id;
            },
        );
    }

    public function test_detect_does_not_redispatch_loan_entered_arrears(): void
    {
        // First overdue installment fires the event.
        $loan         = $this->makeActiveLoan();
        $installment1 = $this->makeOverdueInstallment($loan, number: 1);

        $this->service->detect($loan, $installment1, asOf: Carbon::parse('2026-06-01'));

        // The loan is now `in_arrears`.
        $this->assertSame(LoanStatus::InArrears, $loan->fresh()->status);

        // Second overdue installment — the loan is already in_arrears,
        // so no second `LoanEnteredArrears` is dispatched.
        Event::fake([LoanEnteredArrears::class, LoanPenaltyRecorded::class]);

        $installment2 = $this->makeOverdueInstallment($loan->fresh(), number: 2);

        $this->service->detect(
            $loan->fresh(),
            $installment2,
            asOf: Carbon::parse('2026-06-01'),
        );

        Event::assertNotDispatched(LoanEnteredArrears::class);
        Event::assertDispatched(LoanPenaltyRecorded::class);
    }

    public function test_detect_on_already_in_arrears_loan_still_records_penalty(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::InArrears);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $accrual = $this->service->detect(
            $loan,
            $installment,
            asOf: Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);
        $this->assertSame(LoanStatus::InArrears, $loan->fresh()->status);
    }

    // =========================================================================
    // Idempotency — one penalty per installment
    // =========================================================================

    public function test_detect_is_idempotent_for_the_same_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $first = $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
        $this->assertNotNull($first);

        // Second call returns null (already penalized).
        $second = $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-02'));
        $this->assertNull($second);

        // Exactly one penalty accrual row.
        $this->assertSame(
            1,
            LoanPenaltyAccrual::query()
                ->where('loan_id', $loan->id)
                ->where('installment_id', $installment->id)
                ->count(),
        );
    }

    public function test_detect_does_not_double_increment_on_retry(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            overdueAmount: '1000.0000',
        );

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        $loan->refresh();
        $penaltyAfterFirst = (string) $loan->outstanding_penalty;

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-02'));

        $loan->refresh();
        $this->assertSame($penaltyAfterFirst, (string) $loan->outstanding_penalty);

        // Only one Wallet penalty obligation.
        $this->assertSame(
            1,
            Obligation::query()
                ->where('wallet_id', $this->wallet->id)
                ->where('component', ObligationComponent::Penalty->value)
                ->count(),
        );
    }

    // =========================================================================
    // The `arrears.enabled` gate
    // =========================================================================

    public function test_detect_throws_when_arrears_disabled(): void
    {
        // The service throws `LogicException` for a loan whose product
        // disables arrears. The command (A.89) filters such loans out
        // before calling the service.
        $policy = $this->makePolicy(arrearsEnabled: false);
        $loan   = $this->makeActiveLoan(policy: $policy);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    // =========================================================================
    // Zero-amount penalties
    // =========================================================================

    public function test_detect_skips_zero_rate_penalty(): void
    {
        // A rate of 0 produces a zero penalty; the service skips
        // without writing an accrual row.
        $policy = $this->makePolicy(penaltyRate: '0');
        $loan   = $this->makeActiveLoan(policy: $policy);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $accrual = $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        $this->assertNull($accrual);
        $this->assertSame(
            0,
            LoanPenaltyAccrual::query()->where('loan_id', $loan->id)->count(),
        );
    }

    // =========================================================================
    // Loan state guard
    // =========================================================================

    public function test_detect_rejects_draft_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Draft);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_approved_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Approved);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_rejected_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Rejected);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_settled_loan(): void
    {
        $loan        = $this->makeLoanInState(LoanStatus::Settled);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    // =========================================================================
    // Installment state guard
    // =========================================================================

    public function test_detect_rejects_settled_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Settled,
        );

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_overdue_installment(): void
    {
        // An installment already in `overdue` state was penalized by a
        // prior call. The service throws `LogicException` — the
        // command should have filtered it out.
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Overdue,
        );

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_superseded_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Superseded,
        );

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_accepts_partial_installment(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment(
            $loan,
            number: 1,
            status: InstallmentStatus::Partial,
        );

        $accrual = $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        $this->assertNotNull($accrual);
    }

    // =========================================================================
    // Cross-loan guard
    // =========================================================================

    public function test_detect_rejects_installment_from_another_loan(): void
    {
        $loan        = $this->makeActiveLoan();
        $otherLoan   = $this->makeActiveLoan();

        $otherInstallment = $this->makeOverdueInstallment($otherLoan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $otherInstallment, asOf: Carbon::parse('2026-06-01'));
    }

    // =========================================================================
    // Wallet presence
    // =========================================================================

    public function test_detect_rejects_borrower_without_wallet(): void
    {
        $walletless  = $this->makeBorrower($this->tenant);
        $loan        = $this->makeActiveLoan(borrower: $walletless);
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    // =========================================================================
    // Persistence guards
    // =========================================================================

    public function test_detect_rejects_unpersisted_loan(): void
    {
        $loan = new Loan();
        $loan->status = LoanStatus::Active;

        $installment = new LoanInstallment();

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));
    }

    public function test_detect_rejects_unpersisted_installment(): void
    {
        $loan = $this->makeActiveLoan();

        $ghost = new LoanInstallment();
        $ghost->loan_id = $loan->id;

        $this->expectException(LogicException::class);

        $this->service->detect($loan, $ghost, asOf: Carbon::parse('2026-06-01'));
    }

    // =========================================================================
    // After-commit event — LoanPenaltyRecorded
    // =========================================================================

    public function test_detect_dispatches_loan_penalty_recorded_event(): void
    {
        Event::fake([LoanPenaltyRecorded::class]);

        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $accrual = $this->service->detect(
            $loan,
            $installment,
            asOf: Carbon::parse('2026-06-01'),
        );

        $this->assertNotNull($accrual);

        // Closure captures `$loan` and `$accrual` — MUST NOT be
        // declared `static fn`.
        Event::assertDispatched(
            LoanPenaltyRecorded::class,
            function (LoanPenaltyRecorded $event) use ($loan, $accrual): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->accrualId() === (int) $accrual->id;
            },
        );
    }

    public function test_detect_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanEnteredArrears::class, LoanPenaltyRecorded::class]);

        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $this->service->detect($loan, $installment, asOf: Carbon::parse('2026-06-01'));

        Event::assertNotDispatched(LoanEnteredArrears::class);
        Event::assertNotDispatched(LoanPenaltyRecorded::class);

        // The writes themselves commit regardless.
        $this->assertSame(
            1,
            LoanPenaltyAccrual::query()->where('loan_id', $loan->id)->count(),
        );
        $this->assertSame(LoanStatus::InArrears, $loan->fresh()->status);
    }

    // =========================================================================
    // asOf semantics
    // =========================================================================

    public function test_detect_records_asOf_on_accrual_row(): void
    {
        $loan        = $this->makeActiveLoan();
        $installment = $this->makeOverdueInstallment($loan, number: 1);

        $asOf = Carbon::parse('2026-06-01 14:00:00');

        $accrual = $this->service->detect($loan, $installment, asOf: $asOf);

        $this->assertNotNull($accrual);
        $this->assertSame(
            '2026-06-01',
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

    private function makePolicy(
        string $penaltyRate = '0.05',
        int $graceDays = 15,
        ?string $cap = null,
        bool $arrearsEnabled = true,
    ): VersionedPolicy {
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
                LoanPolicyKeys::ARREARS_SECTION => [
                    LoanPolicyKeys::ARREARS_ENABLED      => $arrearsEnabled,
                    LoanPolicyKeys::ARREARS_PENALTY_RATE => $penaltyRate,
                    LoanPolicyKeys::ARREARS_GRACE_DAYS   => $graceDays,
                    LoanPolicyKeys::ARREARS_BASIS        => 'overdue_installment',
                    LoanPolicyKeys::ARREARS_CAP          => $cap,
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

    private function makeActiveLoan(
        ?Shareholder $borrower = null,
        ?VersionedPolicy $policy = null,
    ): Loan {
        $policy ??= $this->policy;

        return $this->makeLoanInState(LoanStatus::Active, $borrower, $policy);
    }

    private function makeLoanInState(
        LoanStatus $status,
        ?Shareholder $borrower = null,
        ?VersionedPolicy $policy = null,
    ): Loan {
        $borrower ??= $this->borrower;
        $policy   ??= $this->policy;

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
        $loan->frozen_policy_version_id = $policy->id;
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
     * Build an installment with a known overdue amount and status.
     *
     * The `expected_total` is `expected_principal + expected_interest`.
     * The `settled_amount` defaults to zero, so `remainingTotal()`
     * equals `expected_total`. To simulate an overdue amount, the
     * fixture sets `expected_principal` such that the total matches
     * the requested amount.
     *
     * ----------------------------------------------------------------------
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
    private function makeOverdueInstallment(
        Loan $loan,
        int $number = 1,
        string $overdueAmount = '1000.0000',
        InstallmentStatus $status = InstallmentStatus::Pending,
    ): LoanInstallment {
        // The penalty amount is computed against `remainingTotal()`
        // (expected_total − settled_amount). We set expected_principal
        // equal to the overdue amount and expected_interest to zero,
        // so remainingTotal = overdueAmount.
        $installment = LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => Carbon::now()->subDays(30)->toDateString(),
            'expected_principal' => $overdueAmount,
            'expected_interest'  => '0.0000',
            'expected_total'     => $overdueAmount,
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
            'occurred_at'       => Carbon::now(),
            'created_at'        => Carbon::now(),
        ]);
    }
}