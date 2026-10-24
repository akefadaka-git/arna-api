<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Unit;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Concerns\LoanDatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Events\LoanApplicationSubmitted;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Loan\Services\LoanApplicationService;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanApplicationServiceTest
 *
 * Unit-level coverage for `LoanApplicationService` (A.28) — the
 * draft-creation and submission phases of a loan's lifecycle.
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
 * LoanLoanDatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * `submit()` dispatches `LoanApplicationSubmitted` via the state
 * machine's `DB::afterCommit()` path. Under `RefreshDatabase`, the
 * framework's per-test transaction never commits, so the after-commit
 * callback never fires and `Event::fake()` cannot observe the dispatch.
 * `DatabaseMigrations` avoids the wrapping transaction; the service's
 * own `DB::transaction(...)` becomes the outermost one, and its commit
 * triggers the after-commit callbacks.
 *
 * The `createDraft()` tests do not need the after-commit semantics —
 * `createDraft()` dispatches no event — but the file-wide choice is
 * `DatabaseMigrations` because the `submit()` tests in the same file
 * require it.
 *
 * =============================================================================
 * Cross-entity fixtures — Shareholder, LoanProduct, Wallet
 * =============================================================================
 * The `createDraft()` flow cross-checks the borrower, the product, and
 * (when the borrower has a wallet) the wallet's currency. The fixtures
 * build each of the three via direct model inserts (`Model::create([...])`)
 * so that the test setup does not depend on any cross-domain service.
 *
 * The `Wallet` fixture is created only when the currency-match test
 * needs it. Tests that do not exercise the currency check leave the
 * borrower wallet-less, which is a legitimate state at draft-creation
 * time (per A.28 §"Currency handling").
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle. Same
 * discipline as every other suite in the platform.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C A.28 — `LoanApplicationService`
 *   Phase 2C A.26 — `LoanStateMachine` (invoked by `createDraft` and
 *                   `submit`)
 *   Phase 2C A.38 — `LoanApplicationSubmitted` (dispatched by `submit`)
 *   Phase 2A     — `Shareholder` model (the borrower)
 *   Phase 2B     — `Wallet` model (consulted for the currency check)
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Unit
 * @version 1.0.0
 */
final class LoanApplicationServiceTest extends TestCase
{
    use LoanDatabaseMigrations;

    private LoanApplicationService $service;
    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $actor;

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(LoanApplicationService::class);

        $this->tenant      = $this->makeTenant('application-tenant', 'የማመልከቻ ቴናንት');
        $this->otherTenant = $this->makeTenant('other-tenant', 'ሌላ ቴናንት');

        $this->actor = User::factory()->create([
            'email' => 'actor-' . uniqid() . '@example.test',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

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
    // createDraft() — happy path
    // =========================================================================

    public function test_create_draft_creates_loan_in_draft_state(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
            actorUserId:           (int) $this->actor->id,
        );

        $this->assertInstanceOf(Loan::class, $loan);
        $this->assertSame(LoanStatus::Draft, $loan->status);
        $this->assertSame((int) $this->tenant->id, (int) $loan->tenant_id);
        $this->assertSame((int) $borrower->id, (int) $loan->borrower_shareholder_id);
        $this->assertSame((int) $product->id, (int) $loan->loan_product_id);
        $this->assertSame('5000.0000', (string) $loan->principal_amount);
        $this->assertSame(12, (int) $loan->term_months);
    }

    public function test_create_draft_writes_initial_history_row(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
            actorUserId:           (int) $this->actor->id,
        );

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->get();

        $this->assertCount(1, $history);

        $row = $history->first();
        $this->assertNull($row->from_status);
        $this->assertSame(LoanStatus::Draft, $row->to_status);
        $this->assertNull($row->transition_reason);
        $this->assertSame((int) $this->actor->id, $row->actor_user_id);
    }

    public function test_create_draft_records_free_text_fields(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
            purpose:               'Working capital for the coming season.',
            externalReference:     'REF-2026-001',
            actorUserId:           (int) $this->actor->id,
        );

        $this->assertSame('Working capital for the coming season.', $loan->purpose);
        $this->assertSame('REF-2026-001', $loan->external_reference);
    }

    public function test_create_draft_records_created_by(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
            actorUserId:           (int) $this->actor->id,
        );

        $this->assertSame((int) $this->actor->id, (int) $loan->created_by);
    }

    public function test_create_draft_dispatches_no_event(): void
    {
        Event::fake();

        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );

        // createDraft does not dispatch any event. The initial history
        // row is the audit trail for draft creation.
        Event::assertNotDispatched(LoanApplicationSubmitted::class);
    }

    // =========================================================================
    // createDraft() — borrower resolution
    // =========================================================================

    public function test_create_draft_rejects_nonexistent_borrower(): void
    {
        $product = $this->makeProduct($this->tenant);

        $this->expectException(ModelNotFoundException::class);

        $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: 999999,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );
    }

    public function test_create_draft_rejects_cross_tenant_borrower(): void
    {
        // The borrower exists but belongs to another tenant.
        $foreignBorrower = $this->makeBorrower($this->otherTenant);
        $product         = $this->makeProduct($this->tenant);

        $this->expectException(ModelNotFoundException::class);

        $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $foreignBorrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );
    }

    // =========================================================================
    // createDraft() — product resolution
    // =========================================================================

    public function test_create_draft_rejects_nonexistent_product(): void
    {
        $borrower = $this->makeBorrower($this->tenant);

        $this->expectException(ModelNotFoundException::class);

        $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         999999,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );
    }

    public function test_create_draft_rejects_cross_tenant_product(): void
    {
        $borrower       = $this->makeBorrower($this->tenant);
        $foreignProduct = $this->makeProduct($this->otherTenant);

        $this->expectException(ModelNotFoundException::class);

        $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $foreignProduct->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );
    }

    public function test_create_draft_rejects_inactive_product(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant, isActive: false);

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '5000.0000',
                termMonths:            12,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('loan_product_id', $e->errors());
        }
    }

    // =========================================================================
    // createDraft() — principal bounds
    // =========================================================================

    public function test_create_draft_rejects_principal_below_minimum(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minPrincipal: '1000.0000',
            maxPrincipal: '100000.0000',
        );

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '500.0000',
                termMonths:            12,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_amount', $e->errors());
        }
    }

    public function test_create_draft_rejects_principal_above_maximum(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minPrincipal: '1000.0000',
            maxPrincipal: '100000.0000',
        );

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '200000.0000',
                termMonths:            12,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('principal_amount', $e->errors());
        }
    }

    public function test_create_draft_accepts_principal_at_minimum_bound(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minPrincipal: '1000.0000',
            maxPrincipal: '100000.0000',
        );

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '1000.0000',
            termMonths:            12,
        );

        $this->assertSame('1000.0000', (string) $loan->principal_amount);
    }

    public function test_create_draft_accepts_principal_at_maximum_bound(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minPrincipal: '1000.0000',
            maxPrincipal: '100000.0000',
        );

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '100000.0000',
            termMonths:            12,
        );

        $this->assertSame('100000.0000', (string) $loan->principal_amount);
    }

    // =========================================================================
    // createDraft() — term bounds
    // =========================================================================

    public function test_create_draft_rejects_term_below_minimum(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minTerm: 6,
            maxTerm: 60,
        );

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '5000.0000',
                termMonths:            3,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('term_months', $e->errors());
        }
    }

    public function test_create_draft_rejects_term_above_maximum(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minTerm: 6,
            maxTerm: 60,
        );

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '5000.0000',
                termMonths:            120,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('term_months', $e->errors());
        }
    }

    public function test_create_draft_accepts_term_at_bounds(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct(
            $this->tenant,
            minTerm: 6,
            maxTerm: 60,
        );

        $atMin = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            6,
        );

        $atMax = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            60,
        );

        $this->assertSame(6, (int) $atMin->term_months);
        $this->assertSame(60, (int) $atMax->term_months);
    }

    // =========================================================================
    // createDraft() — currency match (when borrower has a Wallet)
    // =========================================================================

    public function test_create_draft_rejects_product_currency_mismatch_with_wallet(): void
    {
        // The borrower has a wallet in ETB; the product is denominated
        // in USD. The service rejects the mismatch.
        $borrower = $this->makeBorrower($this->tenant);
        $this->makeWalletFor($borrower, 'ETB');

        $product = $this->makeProduct($this->tenant, currency: 'USD');

        try {
            $this->service->createDraft(
                tenantId:              (int) $this->tenant->id,
                borrowerShareholderId: (int) $borrower->id,
                loanProductId:         (int) $product->id,
                principalAmount:       '5000.0000',
                termMonths:            12,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('loan_product_id', $e->errors());
        }
    }

    public function test_create_draft_accepts_currency_match(): void
    {
        $borrower = $this->makeBorrower($this->tenant);
        $this->makeWalletFor($borrower, 'ETB');

        $product = $this->makeProduct($this->tenant, currency: 'ETB');

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );

        $this->assertSame(LoanStatus::Draft, $loan->status);
    }

    public function test_create_draft_skips_currency_check_when_borrower_has_no_wallet(): void
    {
        // The borrower has no wallet yet; the currency check is deferred
        // to disbursement time (per A.28 §"Currency handling").
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant, currency: 'USD');

        $loan = $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
        );

        $this->assertSame(LoanStatus::Draft, $loan->status);
    }

    // =========================================================================
    // submit() — happy path
    // =========================================================================

    public function test_submit_transitions_draft_to_submitted(): void
    {
        $loan = $this->makeDraftLoan();

        $result = $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        $this->assertSame(LoanStatus::Submitted, $result->status);
    }

    public function test_submit_writes_status_history_row(): void
    {
        $loan = $this->makeDraftLoan();

        $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        // Two history rows: the initial-creation row and the
        // draft→submitted transition.
        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $history);

        $transition = $history[1];
        $this->assertSame(LoanStatus::Draft, $transition->from_status);
        $this->assertSame(LoanStatus::Submitted, $transition->to_status);
        $this->assertSame((int) $this->actor->id, $transition->actor_user_id);
    }

    public function test_submit_dispatches_event_after_commit(): void
    {
        Event::fake([LoanApplicationSubmitted::class]);

        $loan = $this->makeDraftLoan();

        $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        // Closure captures `$loan` — MUST NOT be declared `static fn`.
        Event::assertDispatched(
            LoanApplicationSubmitted::class,
            function (LoanApplicationSubmitted $event) use ($loan): bool {
                return $event->loanId() === (int) $loan->id
                    && $event->actorUserId() === (int) $this->actor->id;
            },
        );
    }

    public function test_submit_respects_emit_events_gate(): void
    {
        config()->set('loan.audit.emit_events', false);

        Event::fake([LoanApplicationSubmitted::class]);

        $loan = $this->makeDraftLoan();

        $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        Event::assertNotDispatched(LoanApplicationSubmitted::class);

        // The transition itself commits regardless.
        $this->assertSame(LoanStatus::Submitted, $loan->fresh()->status);
    }

    public function test_submit_does_not_dispatch_event_on_rollback(): void
    {
        Event::fake([LoanApplicationSubmitted::class]);

        $loan = $this->makeDraftLoan();

        // Wrap the service call in a broader transaction that will
        // roll back after the service returns.
        try {
            DB::transaction(function () use ($loan): void {
                $this->service->submit(
                    loan:        $loan,
                    actorUserId: (int) $this->actor->id,
                );

                throw new RuntimeException('simulated downstream failure');
            });
        } catch (RuntimeException) {
            // Expected; the outer transaction rolled back.
        }

        Event::assertNotDispatched(LoanApplicationSubmitted::class);

        // The inner transition also rolled back.
        $this->assertSame(LoanStatus::Draft, $loan->fresh()->status);
    }

    // =========================================================================
    // submit() — state guard
    // =========================================================================

    public function test_submit_rejects_non_draft_loan(): void
    {
        // A loan already in `submitted` state cannot be submitted again.
        $loan = $this->makeDraftLoan();

        // First submission succeeds.
        $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        try {
            $this->service->submit(
                loan:        $loan->fresh(),
                actorUserId: (int) $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_submit_rejects_approved_loan(): void
    {
        // Directly construct a loan in `approved` state — a status that
        // is not draft.
        $loan = $this->makeDraftLoan();
        $loan->status = LoanStatus::Approved;
        $loan->save();

        try {
            $this->service->submit(
                loan:        $loan->fresh(),
                actorUserId: (int) $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // =========================================================================
    // submit() — optional reason
    // =========================================================================

    public function test_submit_accepts_optional_reason(): void
    {
        $loan = $this->makeDraftLoan();

        $this->service->submit(
            loan:        $loan,
            reason:      'Submitted for review.',
            actorUserId: (int) $this->actor->id,
        );

        $transition = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Submitted->value)
            ->firstOrFail();

        $this->assertSame('Submitted for review.', $transition->transition_reason);
    }

    public function test_submit_accepts_null_reason(): void
    {
        $loan = $this->makeDraftLoan();

        $this->service->submit(
            loan:        $loan,
            reason:      null,
            actorUserId: (int) $this->actor->id,
        );

        $transition = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->where('to_status', LoanStatus::Submitted->value)
            ->firstOrFail();

        $this->assertNull($transition->transition_reason);
    }

    // =========================================================================
    // submit() — concurrency: the double-submission race
    // =========================================================================

    public function test_submit_rejects_double_submission(): void
    {
        $loan = $this->makeDraftLoan();

        // First submission succeeds.
        $this->service->submit(
            loan:        $loan,
            actorUserId: (int) $this->actor->id,
        );

        // Second submission on a stale in-memory instance is rejected
        // by the state machine's row-locked read (the status is
        // `submitted`, not `draft`).
        try {
            $this->service->submit(
                loan:        $loan,
                actorUserId: (int) $this->actor->id,
            );

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
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

    private function makeProduct(
        Tenant $tenant,
        string $currency = 'ETB',
        string $minPrincipal = '1000.0000',
        string $maxPrincipal = '100000.0000',
        int $minTerm = 1,
        int $maxTerm = 60,
        bool $isActive = true,
    ): LoanProduct {
        return LoanProduct::create([
            'tenant_id'                     => $tenant->id,
            'code'                          => 'PROD-' . uniqid(),
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => $currency,
            'min_principal'                 => $minPrincipal,
            'max_principal'                 => $maxPrincipal,
            'min_term_months'               => $minTerm,
            'max_term_months'               => $maxTerm,
            'default_installment_frequency' => 'monthly',
            'is_active'                     => $isActive,
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

    private function makeDraftLoan(): Loan
    {
        $borrower = $this->makeBorrower($this->tenant);
        $product  = $this->makeProduct($this->tenant);

        return $this->service->createDraft(
            tenantId:              (int) $this->tenant->id,
            borrowerShareholderId: (int) $borrower->id,
            loanProductId:         (int) $product->id,
            principalAmount:       '5000.0000',
            termMonths:            12,
            actorUserId:           (int) $this->actor->id,
        );
    }
}