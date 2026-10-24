<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use RuntimeException;
use Tests\Concerns\LoanDatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanWrittenOff;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanWriteOff;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanWriteOffService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanWriteOffServiceTest
 *
 * Unit-level coverage for `LoanWriteOffService` (A.35) — the WRITE-OFF
 * operation of a loan's lifecycle.
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
 * 1.0.1 — R-23 fixture FK fix. Every `writeOff()` call in this suite
 *         passed a hardcoded actor id (`7` or `42`). The service
 *         writes that id to `loan_write_offs.written_off_by`, which
 *         carries an FK to `users.id`. Under `DatabaseMigrations`, no
 *         such user rows exist, so the write-off-row insert failed the
 *         FK constraint and every `writeOff()` call that reached the
 *         insert failed.
 *
 *         The fix creates a `User` fixture in `setUp()` and passes
 *         `$this->actor->id`. The two assertions that compared against
 *         the literal actor ids become comparisons against
 *         `$this->actor->id`.
 *
 *         No behavior changes; no production code touched; no assertion
 *         meaning changed.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `writeOff()` dispatches `LoanWrittenOff` via the state machine's
 * `DB::afterCommit()` path. Under `RefreshDatabase`, the framework's
 * per-test transaction never commits, so the after-commit callback
 * never fires and `Event::fake()` cannot observe the dispatch.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callback.
 *
 * =============================================================================
 * The frozen write-off flow (A.35)
 * =============================================================================
 * Per A.35's class docblock, `writeOff()` performs the following
 * sequence inside ONE transaction:
 *
 *   1. Validates the reason (non-empty).
 *   2. Reads the loan WITHOUT a lock.
 *   3. Asserts the loan is in `active`, `in_arrears`, or
 *      `restructured` state.
 *   4. Loads the borrower, resolves the Wallet.
 *   5. Captures the loan's current outstanding projections and
 *      computes `writeOffAmount` as their sum.
 *   6. Inserts the `loan_write_offs` row → obtains `$writeOffId`.
 *   7. Discharges every outstanding obligation linked to the loan,
 *      using `reference_type = 'LoanWriteOff'` and
 *      `reference_id = (string) $writeOffId`.
 *   8. Zeroes the loan's three outstanding projections.
 *   9. Transitions to `written_off`, dispatching `LoanWrittenOff` after
 *      commit.
 *  10. Returns the refreshed loan.
 *
 * Every assertion below is anchored to one of these steps.
 *
 * =============================================================================
 * The `ObligationWrittenOff` vs. `ObligationCancelled` distinction
 * =============================================================================
 * The write-off discharges use `WalletEntryType::ObligationWrittenOff`
 * — NOT `ObligationCancelled` (which the restructure flow uses). The
 * two entry types are recorded distinctly on the Wallet's ledger, so
 * the audit trail preserves the semantic difference between "this debt
 * is forgiven" (write-off) and "these terms are replaced" (restructure).
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanWriteOffServiceTest extends TestCase
{
    use LoanDatabaseMigrations;

    private LoanWriteOffService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    /**
     * The actor-user fixture for every writeOff call this suite makes
     * with a non-null actor.
     *
     * The service does not read the user; the row is created solely to
     * satisfy the `loan_write_offs.written_off_by` FK.
     */
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanWriteOffService::class);

        $this->tenant   = $this->makeTenant('writeoff-tenant', 'የመሰረዝ ቴናንት');
        $this->policy   = $this->makePolicy();
        $this->product  = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet   = $this->makeWalletFor($this->borrower, 'ETB');

        // The FK target for `loan_write_offs.written_off_by`. See the
        // class docblock §"Revision history (1.0.1)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 15:00:00'));

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
    // Happy path — lifecycle transition
    // =========================================================================

    public function test_write_off_transitions_active_to_written_off(): void
    {
        $loan = $this->makeActiveLoan();

        $result = $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible per board resolution.',
            actorUserId: $this->actor->id,
        );

        $this->assertSame(LoanStatus::WrittenOff, $result->status);
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
    }

    public function test_write_off_writes_history_row(): void
    {
        $loan = $this->makeActiveLoan();

        $baselineCount = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->count();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // One additional row: active → written_off.
        $this->assertCount($baselineCount + 1, $history);

        $row = $history[$baselineCount];
        $this->assertSame(LoanStatus::Active, $row->from_status);
        $this->assertSame(LoanStatus::WrittenOff, $row->to_status);
        $this->assertSame('Uncollectible.', $row->transition_reason);
        $this->assertSame($this->actor->id, (int) $row->actor_user_id);
    }

    public function test_write_off_from_in_arrears_transitions_correctly(): void
    {
        $loan = $this->makeInArrearsLoan();

        $result = $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $this->assertSame(LoanStatus::WrittenOff, $result->status);

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::WrittenOff->value)
            ->firstOrFail();

        $this->assertSame(LoanStatus::InArrears, $row->from_status);
    }

    public function test_write_off_from_restructured_transitions_correctly(): void
    {
        $loan = $this->makeRestructuredLoan();

        $result = $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $this->assertSame(LoanStatus::WrittenOff, $result->status);

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::WrittenOff->value)
            ->firstOrFail();

        $this->assertSame(LoanStatus::Restructured, $row->from_status);
    }

    // =========================================================================
    // Happy path — LoanWriteOff row
    // =========================================================================

    public function test_write_off_inserts_write_off_row(): void
    {
        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $this->assertDatabaseHas('loan_write_offs', [
            'loan_id' => $loan->id,
            'reason'  => 'Uncollectible.',
        ]);
    }

    public function test_write_off_row_records_outstanding_snapshot(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '8000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame(
            '8000.0000',
            (string) $row->outstanding_principal_at_writeoff,
        );
        $this->assertSame(
            '250.0000',
            (string) $row->outstanding_interest_at_writeoff,
        );
        $this->assertSame(
            '100.0000',
            (string) $row->outstanding_penalty_at_writeoff,
        );
    }

    public function test_write_off_amount_equals_sum_of_snapshot(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '8000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertTrue($row->isFullWriteOff());
        $this->assertSame('8350.0000', (string) $row->write_off_amount);
    }

    public function test_write_off_row_records_reason_and_actor(): void
    {
        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Board resolution #42.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame('Board resolution #42.', (string) $row->reason);
        $this->assertSame($this->actor->id, (int) $row->written_off_by);
    }

    public function test_write_off_row_records_written_off_at(): void
    {
        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame(
            '2026-10-01',
            $row->written_off_at->toDateString(),
        );
    }

    // =========================================================================
    // Reference-id ordering
    // =========================================================================

    public function test_write_off_row_id_precedes_discharge_reference(): void
    {
        // The write-off row's primary key is used as the
        // `reference_id` on every Wallet discharge. This is the
        // Phase 2C Final Reconciliation §1.3 ordering fix.
        $loan = $this->makeActiveLoan();
        $this->makePrincipalObligation($loan);

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $dischargeEntry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('entry_type', WalletEntryType::ObligationWrittenOff->value)
            ->where('reference_type', 'LoanWriteOff')
            ->first();

        $this->assertNotNull($dischargeEntry);
        $this->assertSame(
            (string) $row->id,
            (string) $dischargeEntry->reference_id,
        );
    }

    // =========================================================================
    // Wallet discharges
    // =========================================================================

    public function test_write_off_discharges_principal_obligation(): void
    {
        $loan = $this->makeActiveLoan();
        $principal = $this->makePrincipalObligation($loan, '8000.0000');

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $principal->refresh();
        $this->assertSame('0.0000', (string) $principal->outstanding_amount);
    }

    public function test_write_off_discharges_interest_obligation(): void
    {
        $loan = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);
        $interest = $this->makeInterestObligation($loan, $installment, '250.0000');

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $interest->refresh();
        $this->assertSame('0.0000', (string) $interest->outstanding_amount);
    }

    public function test_write_off_discharges_penalty_obligation(): void
    {
        $loan = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);
        $penalty = $this->makePenaltyObligation($loan, $installment, '100.0000');

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $penalty->refresh();
        $this->assertSame('0.0000', (string) $penalty->outstanding_amount);
    }

    public function test_write_off_discharge_uses_written_off_entry_type(): void
    {
        // The write-off discharges use `ObligationWrittenOff` — NOT
        // `ObligationCancelled` (which the restructure flow uses).
        $loan = $this->makeActiveLoan();
        $this->makePrincipalObligation($loan);

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $this->assertDatabaseHas('wallet_entries', [
            'wallet_id'      => $this->wallet->id,
            'entry_type'     => WalletEntryType::ObligationWrittenOff->value,
            'reference_type' => 'LoanWriteOff',
        ]);

        $this->assertDatabaseMissing('wallet_entries', [
            'wallet_id'      => $this->wallet->id,
            'entry_type'     => WalletEntryType::ObligationCancelled->value,
        ]);
    }

    public function test_write_off_skips_already_discharged_obligations(): void
    {
        // An obligation with `outstanding_amount = 0` is not
        // discharged again — the service's enumeration filters on
        // `outstanding_amount > 0`.
        $loan = $this->makeActiveLoan();
        $alreadyPaid = $this->makePrincipalObligation($loan, '8000.0000');
        $alreadyPaid->outstanding_amount = '0.0000';
        $alreadyPaid->save();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        // No discharge entry for the already-zero obligation.
        $this->assertSame(
            0,
            WalletEntry::query()
                ->where('wallet_id', $this->wallet->id)
                ->where('entry_type', WalletEntryType::ObligationWrittenOff->value)
                ->where('reference_type', 'LoanWriteOff')
                ->count(),
        );
    }

    // =========================================================================
    // Projection reset
    // =========================================================================

    public function test_write_off_zeroes_outstanding_projections(): void
    {
        $loan = $this->makeActiveLoan(
            outstandingPrincipal: '8000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_principal);
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
        $this->assertSame('0.0000', (string) $loan->outstanding_penalty);
    }

    public function test_write_off_does_not_change_settled_amount(): void
    {
        // The loan's `settled_amount` is a running total of what the
        // borrower actually paid. A write-off forgives debt; it does
        // not retroactively alter the paid history.
        $loan = $this->makeActiveLoan();
        $loan->settled_amount = '1500.0000';
        $loan->save();

        $this->service->writeOff(
            loan:        $loan->fresh(),
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $loan->refresh();
        $this->assertSame('1500.0000', (string) $loan->settled_amount);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_write_off_rejects_empty_reason(): void
    {
        $loan = $this->makeActiveLoan();

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      '',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_write_off_rejects_whitespace_only_reason(): void
    {
        $loan = $this->makeActiveLoan();

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      "   \t\n  ",
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_write_off_reason_check_fires_before_transaction(): void
    {
        // The pre-transaction reason check means a whitespace-only
        // reason rejects BEFORE any lock is acquired. The loan row is
        // unchanged.
        $loan = $this->makeActiveLoan();

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      '',
                actorUserId: $this->actor->id,
            );
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertNull($loan->fresh()->outstanding_principal === '0.0000' ? null : null);
        $this->assertSame('8000.0000', (string) $loan->fresh()->outstanding_principal);
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_write_off_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_write_off_rejects_submitted_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_write_off_rejects_approved_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Approved);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_write_off_rejects_disbursed_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Disbursed);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_write_off_rejects_settled_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Settled);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_write_off_rejects_already_written_off_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::WrittenOff);

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Double write-off.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // Wallet presence
    // =========================================================================

    public function test_write_off_rejects_borrower_without_wallet(): void
    {
        $walletless = $this->makeBorrower($this->tenant);
        $loan       = $this->makeActiveLoan(borrower: $walletless);

        $this->expectException(LogicException::class);

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );
    }

    // =========================================================================
    // Atomicity
    // =========================================================================

    public function test_write_off_rolls_back_on_wallet_failure(): void
    {
        // The loan's borrower has no Wallet; the write-off row insert
        // happens BEFORE the Wallet resolution, so the whole
        // transaction must roll back: no write-off row, no status
        // change.
        $walletless = $this->makeBorrower($this->tenant);
        $loan       = $this->makeActiveLoan(borrower: $walletless);

        $countBefore = LoanWriteOff::query()->where('loan_id', $loan->id)->count();

        try {
            $this->service->writeOff(
                loan:        $loan,
                reason:      'Uncollectible.',
                actorUserId: $this->actor->id,
            );

            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertSame(
            $countBefore,
            LoanWriteOff::query()->where('loan_id', $loan->id)->count(),
        );

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    // =========================================================================
    // Cross-loan safety
    // =========================================================================

    public function test_write_off_discharges_only_this_loans_obligations(): void
    {
        // A second loan with its own obligation. The write-off of the
        // first loan must NOT discharge the second loan's obligation.
        $loan = $this->makeActiveLoan();
        $this->makePrincipalObligation($loan, '8000.0000');

        $otherLoan = $this->makeActiveLoan();
        $otherObligation = $this->makePrincipalObligation($otherLoan, '5000.0000');

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $otherObligation->refresh();
        $this->assertSame('5000.0000', (string) $otherObligation->outstanding_amount);
    }

    // =========================================================================
    // After-commit event
    // =========================================================================

    public function test_write_off_dispatches_loan_written_off_event(): void
    {
        Event::fake([LoanWrittenOff::class]);

        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanWrittenOff::class,
            function (LoanWrittenOff $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->reason() === 'Uncollectible.';
            },
        );
    }

    public function test_write_off_event_carries_write_off_id(): void
    {
        Event::fake([LoanWrittenOff::class]);

        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        $row = LoanWriteOff::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        Event::assertDispatched(
            LoanWrittenOff::class,
            function (LoanWrittenOff $event) use ($row): bool {
                return $event->writeOffId() === (int) $row->id;
            },
        );
    }

    public function test_write_off_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanWrittenOff::class]);

        $loan = $this->makeActiveLoan();

        $this->service->writeOff(
            loan:        $loan,
            reason:      'Uncollectible.',
            actorUserId: $this->actor->id,
        );

        Event::assertNotDispatched(LoanWrittenOff::class);

        // The transition itself commits regardless.
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
    }

    public function test_write_off_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanWrittenOff::class]);

        $loan = $this->makeActiveLoan();

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->writeOff(
                    loan:        $loan,
                    reason:      'Uncollectible.',
                    actorUserId: $this->actor->id,
                );

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(LoanWrittenOff::class);

        // The status change also rolled back.
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
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

    private function makeActiveLoan(
        ?Shareholder $borrower = null,
        string $outstandingPrincipal = '8000.0000',
        string $outstandingInterest = '250.0000',
        string $outstandingPenalty = '100.0000',
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
        $loan->disbursement_date        = Carbon::now()->subMonths(6);
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = $outstandingPrincipal;
        $loan->outstanding_interest     = $outstandingInterest;
        $loan->outstanding_penalty      = $outstandingPenalty;
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date            = Carbon::now()->addMonths(6);
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

    private function makeInArrearsLoan(?Shareholder $borrower = null): Loan
    {
        $borrower ??= $this->borrower;

        $loan = $this->makeActiveLoan($borrower);
        $loan->status = LoanStatus::InArrears;
        $loan->save();

        $this->writeHistory($loan, LoanStatus::Active, LoanStatus::InArrears);

        return $loan->fresh();
    }

    private function makeRestructuredLoan(?Shareholder $borrower = null): Loan
    {
        $borrower ??= $this->borrower;

        $loan = $this->makeInArrearsLoan($borrower);
        $loan->status = LoanStatus::Restructured;
        $loan->save();

        $this->writeHistory($loan, LoanStatus::InArrears, LoanStatus::Restructured);

        return $loan->fresh();
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
        $loan->application_date         = Carbon::now();
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = '8000.0000';
        $loan->outstanding_interest     = '250.0000';
        $loan->outstanding_penalty      = '100.0000';
        $loan->frozen_policy_version_id = $this->policy->id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);

        return $loan;
    }

    private function makeInstallment(
        Loan $loan,
        int $number = 1,
        string $expectedInterest = '250.0000',
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => Carbon::now()->subMonths(2)->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => $expectedInterest,
            'expected_total'     => bcadd('1000.0000', $expectedInterest, 4),
        ]);
    }

    private function makePrincipalObligation(
        Loan $loan,
        string $amount = '8000.0000',
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

    private function makeInterestObligation(
        Loan $loan,
        LoanInstallment $installment,
        string $amount = '250.0000',
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

    private function makePenaltyObligation(
        Loan $loan,
        LoanInstallment $installment,
        string $amount = '100.0000',
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Penalty->value,
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