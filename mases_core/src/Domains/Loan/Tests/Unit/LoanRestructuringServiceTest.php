<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use RuntimeException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanRestructured;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanRestructuring;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanRestructuringService;
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
 * LoanRestructuringServiceTest
 *
 * Unit-level coverage for `LoanRestructuringService` (A.34) — the
 * RESTRUCTURE operation of a loan's lifecycle.
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
 * 1.0.1 — R-23 fixture FK fix. The `restructureLoan()` helper passed a
 *         hardcoded actor id (`7`) to every `restructure()` call. The
 *         service writes that id to `loan_restructurings.restructured_by`,
 *         which carries an FK to `users.id`. Under
 *         `DatabaseMigrations`, no such user rows exist, so the
 *         restructure-row insert failed the FK constraint and every
 *         `restructure()` call that reached the insert failed.
 *
 *         The fix creates a `User` fixture in `setUp()` and passes
 *         `$this->actor->id`. Because the helper's default parameter
 *         value must be a constant expression, the helper's
 *         `$actorUserId` parameter now defaults to `null` and coalesces
 *         to `$this->actor->id` inside the helper body.
 *
 *         No behavior changes; no production code touched; no assertion
 *         meaning changed.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `restructure()` dispatches `LoanRestructured` via the state machine's
 * `DB::afterCommit()` path. Under `RefreshDatabase`, the framework's
 * per-test transaction never commits, so the after-commit callback
 * never fires and `Event::fake()` cannot observe the dispatch.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callback.
 *
 * =============================================================================
 * The frozen restructure flow (A.34)
 * =============================================================================
 * Per the handoff and the Phase 2C Final Reconciliation §1.2, the
 * service performs the following sequence inside ONE transaction:
 *
 *   1. Validates the loan's state (`in_arrears`) and the reason.
 *   2. Computes previous outstanding from the loan's projections.
 *   3. Inserts the `loan_restructurings` row → obtains `$restructuringId`.
 *   4. For each outstanding Wallet obligation linked to the loan:
 *      discharges via `ObligationService::discharge()` with
 *      `reference_type = 'LoanRestructure'` and
 *      `reference_id = (string) $restructuringId`.
 *   5. Supersedes the prior schedule's unsettled installments.
 *   6. Generates a new schedule via `LoanScheduleService::generate()`.
 *   7. Recomputes the loan's projections.
 *   8. Transitions `in_arrears → restructured`, dispatching
 *      `LoanRestructured` after commit.
 *   9. Transitions `restructured → active`, no event.
 *  10. Returns the refreshed loan.
 *
 * =============================================================================
 * SIGNATURE WARNING
 * =============================================================================
 * The `restructure()` call in this file uses the handoff §5 pre-spec
 * signature:
 *
 *     restructure(
 *         Loan $loan,
 *         string $newPrincipalAmount,
 *         int $newTermMonths,
 *         int $newPolicyVersionId,
 *         string $reason,
 *         ?int $actorUserId,
 *     ): Loan
 *
 * If A.34's actual signature differs (e.g., extra parameters, a
 * `Carbon $asOf`, or a differently-ordered argument list), a corrective
 * edit to every `$this->service->restructure(...)` call is required.
 * The `restructureLoan()` private helper centralizes the call so the
 * fix is a single edit.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanRestructuringServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanRestructuringService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private VersionedPolicy $newPolicy;
    private Shareholder $borrower;
    private Wallet $wallet;

    /**
     * The actor-user fixture for every restructure call this suite
     * makes with a non-null actor.
     *
     * The service does not read the user; the row is created solely to
     * satisfy the `loan_restructurings.restructured_by` FK.
     */
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanRestructuringService::class);

        $this->tenant    = $this->makeTenant('restructure-tenant', 'የመልሶ ማዋቀር ቴናንት');
        $this->policy    = $this->makePolicy(annualRate: '0.12');
        $this->newPolicy = $this->makePolicy(annualRate: '0.10');
        $this->product   = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower  = $this->makeBorrower($this->tenant);
        $this->wallet    = $this->makeWalletFor($this->borrower, 'ETB');

        // The FK target for `loan_restructurings.restructured_by`.
        // See the class docblock §"Revision history (1.0.1)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));

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
    // Happy path — lifecycle transitions
    // =========================================================================

    public function test_restructure_transitions_in_arrears_to_active(): void
    {
        $loan = $this->makeInArrearsLoan();

        $result = $this->restructureLoan($loan);

        // The service performs two transitions:
        //   in_arrears → restructured
        //   restructured → active
        // The returned status is the FINAL state, `active`.
        $this->assertSame(LoanStatus::Active, $result->status);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_restructure_writes_two_history_rows(): void
    {
        $loan = $this->makeInArrearsLoan();

        $baselineCount = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->count();

        $this->restructureLoan($loan);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // Two additional rows: in_arrears→restructured and
        // restructured→active.
        $this->assertCount($baselineCount + 2, $history);

        $restructuredRow = $history[$baselineCount];
        $activeRow       = $history[$baselineCount + 1];

        $this->assertSame(LoanStatus::InArrears, $restructuredRow->from_status);
        $this->assertSame(LoanStatus::Restructured, $restructuredRow->to_status);

        $this->assertSame(LoanStatus::Restructured, $activeRow->from_status);
        $this->assertSame(LoanStatus::Active, $activeRow->to_status);
    }

    public function test_restructure_writes_reason_to_history_row(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan, reason: 'Seasonal hardship relief.');

        $row = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Restructured->value)
            ->firstOrFail();

        $this->assertSame('Seasonal hardship relief.', $row->transition_reason);
    }

    // =========================================================================
    // Happy path — LoanRestructuring row
    // =========================================================================

    public function test_restructure_inserts_restructuring_row(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan);

        $this->assertDatabaseHas('loan_restructurings', [
            'loan_id' => $loan->id,
        ]);
    }

    public function test_restructure_row_records_previous_outstanding(): void
    {
        $loan = $this->makeInArrearsLoan(
            outstandingPrincipal: '10000.0000',
            outstandingInterest:  '250.0000',
            outstandingPenalty:   '100.0000',
        );

        $this->restructureLoan($loan);

        $row = LoanRestructuring::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame('10000.0000', (string) $row->previous_outstanding_principal);
        $this->assertSame('250.0000', (string) $row->previous_outstanding_interest);
        $this->assertSame('100.0000', (string) $row->previous_outstanding_penalty);
    }

    public function test_restructure_row_records_new_terms(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan(
            $loan,
            newPrincipalAmount: '8000.0000',
            newTermMonths: 24,
        );

        $row = LoanRestructuring::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame('8000.0000', (string) $row->new_principal_amount);
        $this->assertSame(24, (int) $row->new_term_months);
    }

    public function test_restructure_row_records_policy_versions(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan);

        $row = LoanRestructuring::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame(
            (int) $this->policy->id,
            (int) $row->previous_policy_version_id,
        );
        $this->assertSame(
            (int) $this->newPolicy->id,
            (int) $row->new_policy_version_id,
        );
    }

    public function test_restructure_row_records_reason(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan, reason: 'Board-approved relief.');

        $row = LoanRestructuring::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        $this->assertSame('Board-approved relief.', (string) $row->reason);
    }

    // =========================================================================
    // Reference-id ordering
    // =========================================================================

    public function test_restructure_row_id_precedes_discharge_reference(): void
    {
        // The restructuring row's primary key is used as the
        // `reference_id` on every Wallet discharge. This is the
        // Phase 2C Final Reconciliation §1.2 ordering fix.
        $loan = $this->makeInArrearsLoan();
        $this->makePrincipalObligation($loan);

        $this->restructureLoan($loan);

        $row = LoanRestructuring::query()
            ->where('loan_id', $loan->id)
            ->firstOrFail();

        // Find the discharge entry for the principal obligation.
        $dischargeEntry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('entry_type', WalletEntryType::ObligationCancelled->value)
            ->where('reference_type', 'LoanRestructure')
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

    public function test_restructure_discharges_outstanding_obligations(): void
    {
        $loan = $this->makeInArrearsLoan();
        $principal = $this->makePrincipalObligation($loan, '10000.0000');

        $this->restructureLoan($loan);

        $principal->refresh();
        $this->assertSame('0.0000', (string) $principal->outstanding_amount);
    }

    public function test_restructure_discharges_interest_obligation(): void
    {
        $loan = $this->makeInArrearsLoan();
        $installment = $this->makeInstallment($loan, number: 1);
        $interest = $this->makeInterestObligation($loan, $installment, '250.0000');

        $this->restructureLoan($loan);

        $interest->refresh();
        $this->assertSame('0.0000', (string) $interest->outstanding_amount);
    }

    public function test_restructure_discharge_uses_cancelled_entry_type(): void
    {
        // The restructure discharges use `ObligationCancelled` — NOT
        // `ObligationWrittenOff` (which the write-off flow uses). The
        // distinction is semantically meaningful.
        $loan = $this->makeInArrearsLoan();
        $this->makePrincipalObligation($loan);

        $this->restructureLoan($loan);

        $this->assertDatabaseHas('wallet_entries', [
            'wallet_id'      => $this->wallet->id,
            'entry_type'     => WalletEntryType::ObligationCancelled->value,
            'reference_type' => 'LoanRestructure',
        ]);

        $this->assertDatabaseMissing('wallet_entries', [
            'wallet_id'      => $this->wallet->id,
            'entry_type'     => WalletEntryType::ObligationWrittenOff->value,
        ]);
    }

    // =========================================================================
    // Supersession of prior installments
    // =========================================================================

    public function test_restructure_supersedes_unsettled_installments(): void
    {
        $loan = $this->makeInArrearsLoan();
        $this->makeInstallment($loan, number: 1, status: InstallmentStatus::Overdue);
        $this->makeInstallment($loan, number: 2, status: InstallmentStatus::Pending);

        $this->restructureLoan($loan);

        $superseded = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentStatus::Superseded->value)
            ->count();

        $this->assertSame(2, $superseded);
    }

    public function test_restructure_preserves_already_settled_installments(): void
    {
        // Already-settled installments keep their `settled` status;
        // the restructure does not supersede them.
        $loan = $this->makeInArrearsLoan();
        $this->makeInstallment($loan, number: 1, status: InstallmentStatus::Settled);
        $this->makeInstallment($loan, number: 2, status: InstallmentStatus::Overdue);

        $this->restructureLoan($loan);

        $settled = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentStatus::Settled->value)
            ->count();

        $this->assertSame(1, $settled);

        $superseded = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('status', InstallmentStatus::Superseded->value)
            ->count();

        $this->assertSame(1, $superseded);
    }

    public function test_restructure_generates_new_schedule(): void
    {
        $loan = $this->makeInArrearsLoan();
        $this->makeInstallment($loan, number: 1, status: InstallmentStatus::Overdue);
        $this->makeInstallment($loan, number: 2, status: InstallmentStatus::Pending);

        $this->restructureLoan($loan, newTermMonths: 4);

        // Four new (non-superseded) installments.
        $activeCount = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [InstallmentStatus::Superseded->value])
            ->count();

        $this->assertSame(4, $activeCount);
    }

    public function test_restructure_new_schedule_continues_numbering(): void
    {
        $loan = $this->makeInArrearsLoan();
        $this->makeInstallment($loan, number: 1, status: InstallmentStatus::Overdue);
        $this->makeInstallment($loan, number: 2, status: InstallmentStatus::Pending);

        $this->restructureLoan($loan, newTermMonths: 3);

        // The new schedule's installment numbers continue from 3.
        $newInstallments = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->whereNotIn('status', [InstallmentStatus::Superseded->value])
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(3, $newInstallments);
        $this->assertSame(3, $newInstallments[0]->installment_number);
        $this->assertSame(4, $newInstallments[1]->installment_number);
        $this->assertSame(5, $newInstallments[2]->installment_number);
    }

    // =========================================================================
    // Projections
    // =========================================================================

    public function test_restructure_updates_outstanding_principal(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan(
            $loan,
            newPrincipalAmount: '8000.0000',
        );

        // After the restructure, the loan's outstanding principal
        // equals the new principal (no payments yet).
        $loan->refresh();
        $this->assertSame('8000.0000', (string) $loan->outstanding_principal);
    }

    public function test_restructure_writes_new_policy_version_to_loan(): void
    {
        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan);

        $loan->refresh();
        $this->assertSame(
            (int) $this->newPolicy->id,
            (int) $loan->frozen_policy_version_id,
        );
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_restructure_rejects_empty_reason(): void
    {
        $loan = $this->makeInArrearsLoan();

        try {
            $this->restructureLoan($loan, reason: '');
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    public function test_restructure_rejects_whitespace_only_reason(): void
    {
        $loan = $this->makeInArrearsLoan();

        try {
            $this->restructureLoan($loan, reason: "   \t\n  ");
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_restructure_rejects_active_loan(): void
    {
        // The frozen C4 matrix permits only `in_arrears → restructured`.
        $loan = $this->makeLoanInState(LoanStatus::Active);

        $this->expectException(ValidationException::class);

        $this->restructureLoan($loan);
    }

    public function test_restructure_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        $this->expectException(ValidationException::class);

        $this->restructureLoan($loan);
    }

    public function test_restructure_rejects_settled_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Settled);

        $this->expectException(ValidationException::class);

        $this->restructureLoan($loan);
    }

    public function test_restructure_rejects_written_off_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::WrittenOff);

        $this->expectException(ValidationException::class);

        $this->restructureLoan($loan);
    }

    // =========================================================================
    // Cross-loan obligation safety
    // =========================================================================

    public function test_restructure_discharges_only_this_loans_obligations(): void
    {
        // A second loan with its own obligation. The restructure of the
        // first loan must NOT discharge the second loan's obligation.
        $loan = $this->makeInArrearsLoan();
        $this->makePrincipalObligation($loan, '10000.0000');

        $otherLoan = $this->makeInArrearsLoan();
        $otherObligation = $this->makePrincipalObligation($otherLoan, '5000.0000');

        $this->restructureLoan($loan);

        // The first loan's obligation is discharged.
        $this->assertSame(
            1,
            Obligation::query()
                ->where('wallet_id', $this->wallet->id)
                ->where('reference_id', (string) $loan->id)
                ->where('outstanding_amount', '0.0000')
                ->count(),
        );

        // The second loan's obligation is untouched.
        $otherObligation->refresh();
        $this->assertSame('5000.0000', (string) $otherObligation->outstanding_amount);
    }

    // =========================================================================
    // After-commit event
    // =========================================================================

    public function test_restructure_dispatches_loan_restructured_event(): void
    {
        Event::fake([LoanRestructured::class]);

        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan);

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanRestructured::class,
            function (LoanRestructured $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id;
            },
        );
    }

    public function test_restructure_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanRestructured::class]);

        $loan = $this->makeInArrearsLoan();

        $this->restructureLoan($loan);

        Event::assertNotDispatched(LoanRestructured::class);

        // The transition itself commits regardless.
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_restructure_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanRestructured::class]);

        $loan = $this->makeInArrearsLoan();

        try {
            DB::transaction(function () use ($loan): void {
                $this->restructureLoan($loan);

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(LoanRestructured::class);

        // The status change rolled back.
        $this->assertSame(LoanStatus::InArrears, $loan->fresh()->status);
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

    private function makePolicy(string $annualRate = '0.12'): VersionedPolicy
    {
        return VersionedPolicy::create([
            'policy_type' => 'loan_product_rules',
            'payload'     => [
                'interest' => ['annual_rate' => $annualRate],
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

    private function makeInArrearsLoan(
        string $outstandingPrincipal = '10000.0000',
        string $outstandingInterest = '0.0000',
        string $outstandingPenalty = '0.0000',
    ): Loan {
        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $this->borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::InArrears;
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
        $this->writeHistory($loan, LoanStatus::Active, LoanStatus::InArrears);

        return $loan;
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
        $loan->outstanding_principal    = '10000.0000';
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $this->policy->id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);

        return $loan;
    }

    private function makeInstallment(
        Loan $loan,
        int $number = 1,
        InstallmentStatus $status = InstallmentStatus::Pending,
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => Carbon::now()->subMonths(2)->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => '10.0000',
            'expected_total'     => '1010.0000',
            'status'             => $status->value,
        ]);
    }

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

    /**
     * Single helper for the restructure call. Centralizing the call
     * lets a signature change in A.34 be fixed in one place.
     *
     * The `$actorUserId` parameter defaults to `null` so that the
     * helper body can substitute the fixture-provided actor id. A
     * default parameter value of `$this->actor->id` is not legal in
     * PHP (default values must be constant expressions), so the
     * coalesce runs inside the body. See the class docblock
     * §"Revision history (1.0.1)".
     */
    private function restructureLoan(
        Loan $loan,
        string $newPrincipalAmount = '10000.0000',
        int $newTermMonths = 12,
        ?VersionedPolicy $newPolicy = null,
        string $reason = 'Restructure per committee decision.',
        ?int $actorUserId = null,
    ): Loan {
        $newPolicy   ??= $this->newPolicy;
        $actorUserId ??= $this->actor->id;

        return $this->service->restructure(
            loan:               $loan,
            newPrincipalAmount: $newPrincipalAmount,
            newTermMonths:      $newTermMonths,
            newPolicyVersionId: (int) $newPolicy->id,
            reason:             $reason,
            actorUserId:        $actorUserId,
        );
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