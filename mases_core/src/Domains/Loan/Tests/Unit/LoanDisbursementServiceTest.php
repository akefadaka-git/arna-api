<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use RuntimeException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanDisbursed;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanDisbursementService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanDisbursementServiceTest
 *
 * Unit-level coverage for `LoanDisbursementService` (A.30) — the
 * DISBURSEMENT phase of a loan's lifecycle.
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
 * 1.0.1 — R-23 fixture FK fix. Every `disburse()` call in this suite
 *         passed the hardcoded actor id `7`. The service writes the
 *         actor id to `wallet_obligations.created_by`, which carries an
 *         FK to `users.id`. Under `DatabaseMigrations`, no such user
 *         rows exist, so the obligation write failed the FK constraint
 *         and every `disburse()` call that reached the Wallet write
 *         failed. The fix creates a `User` fixture in `setUp()` and
 *         passes `$this->actor->id`.
 *
 *         No behavior changes; no production code touched; no assertion
 *         meaning changed.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `disburse()` dispatches `LoanDisbursed` via the state machine's
 * `DB::afterCommit()` path. Under `RefreshDatabase`, the framework's
 * per-test transaction never commits, so the after-commit callback
 * never fires and `Event::fake()` cannot observe the dispatch.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callback.
 *
 * =============================================================================
 * The frozen disbursement flow (A.30)
 * =============================================================================
 * Per A.30's class docblock, `disburse()` performs the following
 * sequence inside ONE transaction:
 *
 *   1. Reads the loan without a lock.
 *   2. Asserts the loan is in `approved` state.
 *   3. Loads borrower and product via relations.
 *   4. Resolves the borrower's Wallet.
 *   5. Asserts product.currency === wallet.currency (Q8).
 *   6. Records the Wallet principal obligation.
 *   7. Generates the loan's schedule.
 *   8. Writes currency, disbursement_date, outstanding_principal.
 *   9. Transitions approved→disbursed, dispatching `LoanDisbursed`.
 *  10. Transitions disbursed→active, no event.
 *  11. Returns the refreshed loan.
 *
 * Every assertion below is anchored to one of these steps.
 *
 * =============================================================================
 * Fixtures used
 * =============================================================================
 *   - Tenant, LoanProduct (with policy version reference), VersionedPolicy
 *     with the canonical `loan_product_rules` payload.
 *   - Shareholder (borrower) with a Wallet in ETB.
 *   - User (actor) for the created_by FK on the Wallet obligation.
 *   - A loan in `approved` state with the frozen policy version written.
 *
 * The policy payload is the same minimal shape used by A.92/A.94:
 * `interest.annual_rate` and `term.principal_grace_months`/
 * `term.interest_grace_months`. See the `makePolicy()` helper.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle. Same
 * discipline as every other suite in the platform.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.1
 */
final class LoanDisbursementServiceTest extends TestCase
{
    use DatabaseMigrations;

    private LoanDisbursementService $service;
    private Tenant $tenant;
    private LoanProduct $product;
    private VersionedPolicy $policy;
    private Shareholder $borrower;
    private Wallet $wallet;

    /**
     * The actor-user fixture for every disburse call this suite makes
     * with a non-null actor.
     *
     * The service does not read the user; the row is created solely to
     * satisfy the `wallet_obligations.created_by` FK.
     */
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanDisbursementService::class);

        $this->tenant = $this->makeTenant('disbursement-tenant', 'የመክፈያ ቴናንት');
        $this->policy = $this->makePolicy();
        $this->product = $this->makeProduct($this->tenant, $this->policy);
        $this->borrower = $this->makeBorrower($this->tenant);
        $this->wallet = $this->makeWalletFor($this->borrower, 'ETB');

        // The FK target for `wallet_obligations.created_by`. See the
        // class docblock §"Revision history (1.0.1)".
        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-01 11:00:00'));

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

    public function test_disburse_transitions_approved_to_active(): void
    {
        $loan = $this->makeApprovedLoan();

        $result = $this->service->disburse($loan, actorUserId: $this->actor->id);

        // The service performs two transitions:
        //   approved → disbursed
        //   disbursed → active
        // The returned status is the FINAL state, `active`.
        $this->assertSame(LoanStatus::Active, $result->status);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_disburse_writes_two_history_rows(): void
    {
        $loan = $this->makeApprovedLoan();

        // The fixture writes the pre-approval history rows for a loan
        // in `approved` state (initial-creation, submitted, under_review,
        // approved). See the `makeApprovedLoan()` fixture for the exact
        // baseline.
        $baselineCount = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->count();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        // Two additional rows: approved→disbursed and disbursed→active.
        $this->assertCount($baselineCount + 2, $history);

        $disbursedRow = $history[$baselineCount];
        $activeRow    = $history[$baselineCount + 1];

        $this->assertSame(LoanStatus::Approved, $disbursedRow->from_status);
        $this->assertSame(LoanStatus::Disbursed, $disbursedRow->to_status);

        $this->assertSame(LoanStatus::Disbursed, $activeRow->from_status);
        $this->assertSame(LoanStatus::Active, $activeRow->to_status);
    }

    // =========================================================================
    // Happy path — metadata written to the loan row
    // =========================================================================

    public function test_disburse_writes_currency_from_wallet(): void
    {
        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertSame('ETB', (string) $loan->currency);
    }

    public function test_disburse_writes_disbursement_date(): void
    {
        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertNotNull($loan->disbursement_date);
        $this->assertSame(
            '2026-04-01',
            $loan->disbursement_date->toDateString(),
        );
    }

    public function test_disburse_writes_outstanding_principal(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000');

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertSame('10000.0000', (string) $loan->outstanding_principal);
    }

    // =========================================================================
    // Happy path — Wallet principal obligation
    // =========================================================================

    public function test_disburse_creates_principal_obligation_on_wallet(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000');

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->where('component', ObligationComponent::Principal->value)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('10000.0000', (string) $obligation->original_amount);
        $this->assertSame('10000.0000', (string) $obligation->outstanding_amount);
    }

    public function test_disburse_obligation_carries_loan_reference(): void
    {
        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('Loan', (string) $obligation->reference_type);
        $this->assertSame((string) $loan->id, (string) $obligation->reference_id);
    }

    public function test_disburse_updates_wallet_balance_negatively(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000');

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $this->wallet->refresh();
        $this->assertSame('-10000.0000', (string) $this->wallet->current_balance);
    }

    // =========================================================================
    // Happy path — schedule generation
    // =========================================================================

    public function test_disburse_generates_schedule(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000', termMonths: 4);

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $rows = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->get();

        $this->assertCount(4, $rows);
        $this->assertSame(1, $rows[0]->installment_number);
        $this->assertSame(4, $rows[3]->installment_number);
    }

    public function test_disburse_writes_schedule_summary_columns(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000', termMonths: 4);

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $loan->refresh();
        $this->assertNotNull($loan->monthly_installment_amount);
        $this->assertNotNull($loan->total_scheduled_interest);
        $this->assertNotNull($loan->total_scheduled_repayment);
        $this->assertNotNull($loan->maturity_date);
    }

    public function test_disburse_writes_maturity_date_from_schedule(): void
    {
        $loan = $this->makeApprovedLoan(principal: '10000.0000', termMonths: 12);

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        $loan->refresh();

        // Disbursement 2026-04-01 + 12 months = 2027-04-01.
        $this->assertSame(
            '2027-04-01',
            $loan->maturity_date->toDateString(),
        );
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_disburse_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_disburse_rejects_submitted_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_disburse_rejects_already_disbursed_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Disbursed);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_disburse_rejects_active_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Active);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // Wallet presence
    // =========================================================================

    public function test_disburse_rejects_borrower_without_wallet(): void
    {
        // A borrower with no Wallet cannot receive a principal
        // obligation. The service produces a 422 with a `wallet` field
        // error (per A.30's class docblock).
        $walletlessBorrower = $this->makeBorrower($this->tenant);
        $loan = $this->makeApprovedLoan(borrower: $walletlessBorrower);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('wallet', $e->errors());
        }
    }

    // =========================================================================
    // Currency match (Q8)
    // =========================================================================

    public function test_disburse_rejects_currency_mismatch(): void
    {
        // The product's currency is USD; the wallet's is ETB. The
        // service rejects the mismatch.
        $product = $this->makeProduct($this->tenant, $this->policy, currency: 'USD');
        $loan    = $this->makeApprovedLoan(product: $product);

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency', $e->errors());
        }
    }

    public function test_disburse_accepts_currency_match(): void
    {
        $loan = $this->makeApprovedLoan();

        $result = $this->service->disburse($loan, actorUserId: $this->actor->id);

        $this->assertSame(LoanStatus::Active, $result->status);
    }

    // =========================================================================
    // Atomicity
    // =========================================================================

    public function test_disburse_is_atomic_on_schedule_failure(): void
    {
        // A loan whose product's policy lacks the interest section
        // causes the schedule service to raise a LogicException AFTER
        // the Wallet obligation has been recorded. The whole
        // transaction must roll back: no obligation, no balance change,
        // no status change.
        $brokenPolicy = $this->makeBrokenPolicy();
        $brokenProduct = $this->makeProduct($this->tenant, $brokenPolicy);
        $loan = $this->makeApprovedLoan(product: $brokenProduct);

        $walletCountBefore     = Obligation::query()->where('wallet_id', $this->wallet->id)->count();
        $balanceBefore         = (string) $this->wallet->current_balance;

        try {
            $this->service->disburse($loan, actorUserId: $this->actor->id);

            $this->fail('Expected LogicException was not thrown.');
        } catch (LogicException) {
            // Expected.
        }

        // Nothing was persisted.
        $this->assertSame(
            $walletCountBefore,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );

        $this->wallet->refresh();
        $this->assertSame($balanceBefore, (string) $this->wallet->current_balance);

        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);
    }

    // =========================================================================
    // After-commit event
    // =========================================================================

    public function test_disburse_dispatches_loan_disbursed_event(): void
    {
        Event::fake([LoanDisbursed::class]);

        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanDisbursed::class,
            function (LoanDisbursed $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id;
            },
        );
    }

    public function test_disburse_event_carries_wallet_ids(): void
    {
        Event::fake([LoanDisbursed::class]);

        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        // The event carries the Wallet id and the Wallet obligation id
        // that the disbursement produced. Both are the join keys to the
        // Wallet-side records.
        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($obligation);

        // Closure captures `$loan` and `$obligation` — MUST NOT be
        // declared `static fn`.
        Event::assertDispatched(
            LoanDisbursed::class,
            function (LoanDisbursed $event) use ($loan, $obligation): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->walletId() === (int) $this->wallet->id
                    && $event->walletObligationId() === (int) $obligation->id;
            },
        );
    }

    public function test_disburse_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanDisbursed::class]);

        $loan = $this->makeApprovedLoan();

        $this->service->disburse($loan, actorUserId: $this->actor->id);

        Event::assertNotDispatched(LoanDisbursed::class);

        // The transition itself commits regardless.
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_disburse_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanDisbursed::class]);

        $loan = $this->makeApprovedLoan();

        try {
            DB::transaction(function () use ($loan): void {
                $this->service->disburse($loan, actorUserId: $this->actor->id);

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        Event::assertNotDispatched(LoanDisbursed::class);

        // The status change also rolled back.
        $this->assertSame(LoanStatus::Approved, $loan->fresh()->status);

        // And the Wallet obligation rolled back.
        $this->assertSame(
            0,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
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

    private function makeBrokenPolicy(): VersionedPolicy
    {
        // The `term` section is present but the `interest` section is
        // missing. The schedule service (A.27) will raise a
        // LogicException when it tries to extract the annual rate.
        return VersionedPolicy::create([
            'policy_type' => 'loan_product_rules',
            'payload'     => [
                'term' => [
                    'principal_grace_months' => 0,
                    'interest_grace_months'  => 0,
                ],
            ],
        ]);
    }

    private function makeProduct(
        Tenant $tenant,
        ?VersionedPolicy $policy = null,
        string $currency = 'ETB',
    ): LoanProduct {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => 'PROD-' . uniqid(),
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => $currency,
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
     * Build a loan in `approved` state without going through the state
     * machine. The fixture writes the intermediate history rows that a
     * real loan in `approved` state would carry.
     */
    private function makeApprovedLoan(
        ?LoanProduct $product = null,
        ?Shareholder $borrower = null,
        string $principal = '10000.0000',
        int $termMonths = 12,
    ): Loan {
        $product  ??= $this->product;
        $borrower ??= $this->borrower;

        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $product->id;
        $loan->principal_amount         = $principal;
        $loan->term_months              = $termMonths;
        $loan->status                   = LoanStatus::Approved;
        $loan->application_date         = Carbon::now();
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $product->product_rules_policy_version_id;
        $loan->decision_date            = Carbon::now();
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);

        return $loan;
    }

    /**
     * Build a loan in an arbitrary state (used by the state-guard
     * tests). Writes the baseline history rows appropriate to the
     * state.
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
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);

        if ($status !== LoanStatus::Draft) {
            $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        }

        if ($status !== LoanStatus::Draft && $status !== LoanStatus::Submitted) {
            $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        }

        if (
            $status !== LoanStatus::Draft
            && $status !== LoanStatus::Submitted
            && $status !== LoanStatus::UnderReview
        ) {
            $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        }

        return $loan;
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