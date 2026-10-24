<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanRepaymentTest
 *
 * Feature tests for the loan-repayment HTTP surface:
 *
 *     POST /loans/{loan}/repay    store    — record a repayment
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
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The feature tests do NOT assert on the after-commit events —
 * `LoanRepaymentRecorded` (A.42), `LoanInstallmentSettled` (A.43), and
 * `LoanSettled` (A.48). The unit suite (A.96) covers the dispatch and the
 * payloads; this file focuses on the HTTP contract: request shape,
 * authorization layering, the cross-field sum validation, the state-
 * machine guards reached through the endpoint, and the Wallet-side side
 * effects (payment, allocations, projections).
 *
 * `RefreshDatabase` is therefore the correct default per §2.13 and the
 * pattern followed by the Shareholder and Wallet feature suites.
 *
 * =============================================================================
 * Authorization layering
 * =============================================================================
 * The endpoint requires:
 *
 *   1. Authentication via `auth:sanctum`.
 *   2. Tenant membership via `tenant.membership`.
 *   3. The `loan.repay` permission via `LoanPolicy::repay()` dispatched
 *      by the controller (A.80).
 *
 * =============================================================================
 * The repayment flow (A.31)
 * =============================================================================
 * Per A.31, a successful repayment:
 *   - records the Wallet payment via `PaymentService::record()`,
 *   - applies each allocation via `AllocationService::allocate()`,
 *   - updates `settled_amount` on the loan,
 *   - recomputes the three outstanding projections,
 *   - settles installments whose remaining obligations are now zero,
 *   - settles the loan if every installment is settled.
 *
 * The feature tests assert the OBSERVABLE state of the primary steps
 * through the HTTP surface.
 *
 * =============================================================================
 * The allocation sum check
 * =============================================================================
 * `StoreRepaymentRequest` (A.64) performs a cross-field check: the sum
 * of `allocations.*.amount` must equal the top-level `amount`. A test
 * verifies the request-level 422 on mismatch.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - repay on a cross-tenant loan id → 404
 *   - forged `X-Tenant-ID` header → 403
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createLoanPermission()` — not `createPermission()` — to avoid the
 * method-name collision with the protected helper on `Tests\TestCase`.
 *
 * =============================================================================
 * Fixture helper scope — no duplicate installments
 * =============================================================================
 * `makeActiveLoanWithSchedule()` creates installments numbered
 * `1..termMonths` for the loan. Any test that needs a specific
 * installment MUST fetch the existing row rather than create a new
 * one: the `loan_installments` table carries a UNIQUE constraint on
 * `(loan_id, installment_number)`, and a duplicate insert aborts the
 * test with a `UniqueConstraintViolationException`. The earlier
 * `makeInstallment()` helper that existed for this purpose has been
 * removed — its only caller has been rewritten to fetch the existing
 * installment.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.0
 */
final class LoanRepaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $borrower;
    private Shareholder $otherBorrower;
    private Wallet $wallet;
    private LoanProduct $product;
    private LoanProduct $otherProduct;
    private VersionedPolicy $policy;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenant = Tenant::create([
            'slug'      => 'main-tenant',
            'name_am'   => 'ዋና ቴናንት',
            'name_en'   => 'Main Tenant',
            'domain'    => 'main.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->otherTenant = Tenant::create([
            'slug'      => 'other-tenant',
            'name_am'   => 'ሌላ ቴናንት',
            'name_en'   => 'Other Tenant',
            'domain'    => 'other.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email' => 'noperm-' . uniqid() . '@example.test',
        ]);
        $this->userWithoutPermission->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // The permissions the endpoint requires.
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',  'View loans');
        $this->createLoanPermission('loan.repay', 'Record repayments');

        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders, wallets, and products.
        // ---------------------------------------------------------------------
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->otherBorrower = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        $this->wallet = Wallet::create([
            'tenant_id'       => $this->tenant->id,
            'shareholder_id'  => $this->borrower->id,
            'currency'        => 'ETB',
            'current_balance' => '0.0000',
            'is_active'       => true,
            'opened_at'       => now(),
        ]);

        $this->policy       = $this->makePolicy();
        $this->product      = $this->makeProduct($this->tenant, 'PROD-A', $this->policy);
        $this->otherProduct = $this->makeProduct($this->otherTenant, 'PROD-B', $this->policy);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_repay_requires_authentication(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_repay_requires_repay_permission(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(403);

        // No payment or allocation was created.
        $this->assertSame(
            0,
            Payment::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // Happy path — partial repayment
    // =========================================================================

    public function test_repay_records_payment(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('wallet_payments', [
            'wallet_id' => $this->wallet->id,
            'amount'    => '500.0000',
        ]);
    }

    public function test_repay_records_allocation(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(200);

        $this->assertDatabaseHas('wallet_payment_allocations', [
            'wallet_id'     => $this->wallet->id,
            'obligation_id' => $obligation->id,
            'amount'        => '500.0000',
        ]);
    }

    public function test_repay_updates_loan_settled_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(200);

        $loan->refresh();
        $this->assertSame('500.0000', (string) $loan->settled_amount);
    }

    public function test_repay_reduces_obligation_outstanding(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000');
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(200);

        $obligation->refresh();
        $this->assertSame('9500.0000', (string) $obligation->outstanding_amount);
    }

    public function test_repay_updates_outstanding_principal_projection(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000');
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(200);

        $loan->refresh();
        $this->assertSame('9500.0000', (string) $loan->outstanding_principal);
    }

    // =========================================================================
    // Happy path — full settlement
    // =========================================================================

    public function test_repay_full_settlement_transitions_loan_to_settled(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '2000.0000', termMonths: 1);
        $obligation = $this->makePrincipalObligation($loan, '2000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '2000.0000'),
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', LoanStatus::Settled->value);

        $this->assertSame(LoanStatus::Settled, $loan->fresh()->status);
    }

    public function test_repay_full_settlement_zeros_projections(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '2000.0000', termMonths: 1);
        $obligation = $this->makePrincipalObligation($loan, '2000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '2000.0000'),
        )->assertStatus(200);

        $loan->refresh();
        $this->assertSame('0.0000', (string) $loan->outstanding_principal);
        $this->assertSame('0.0000', (string) $loan->outstanding_interest);
        $this->assertSame('0.0000', (string) $loan->outstanding_penalty);
    }

    // =========================================================================
    // Validation — shape
    // =========================================================================

    public function test_repay_rejects_missing_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'allocations' => [[
                'obligation_id' => (int) $obligation->id,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_repay_rejects_missing_allocations(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => '500.0000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['allocations']]);
    }

    public function test_repay_rejects_empty_allocations(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount'      => '500.0000',
            'allocations' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['allocations']]);
    }

    public function test_repay_rejects_malformed_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => 'not-a-number',
            'allocations' => [[
                'obligation_id' => (int) $obligation->id,
                'amount'        => 'not-a-number',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    // =========================================================================
    // Validation — cross-field sum
    // =========================================================================

    public function test_repay_rejects_allocation_sum_mismatch(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => (int) $obligation->id,
                'amount'        => '400.0000',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['allocations']]);
    }

    public function test_repay_accepts_sum_matching_across_allocations(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000');
        $principal = $this->makePrincipalObligation($loan, '5000.0000');

        // Fetch the installment #1 that `makeActiveLoanWithSchedule`
        // already created. Do NOT create a second one — the
        // `loan_installments` table carries a UNIQUE constraint on
        // `(loan_id, installment_number)` and a duplicate insert
        // aborts the test.
        $installment = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('installment_number', 1)
            ->firstOrFail();

        $interest = $this->makeInterestObligation($loan, $installment, '100.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => '600.0000',
            'allocations' => [
                ['obligation_id' => (int) $principal->id, 'amount' => '500.0000'],
                ['obligation_id' => (int) $interest->id,  'amount' => '100.0000'],
            ],
        ]);

        $response->assertStatus(200);
    }

    // =========================================================================
    // Validation — obligation ownership
    // =========================================================================

    public function test_repay_rejects_nonexistent_obligation(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => 999999,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_repay_rejects_cross_loan_obligation(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $this->makePrincipalObligation($loan);

        // A second loan under the same tenant, with its own obligation.
        $otherLoan = $this->makeActiveLoanWithSchedule();
        $otherObligation = $this->makePrincipalObligation($otherLoan, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $loan->id]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => (int) $otherObligation->id,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['allocations.0.obligation_id']]);
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_repay_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_repay_rejects_rejected_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Rejected);
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_repay_rejects_settled_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Settled);
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_repay_rejects_written_off_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::WrittenOff);
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    // =========================================================================
    // Cross-tenant and non-existent
    // =========================================================================

    public function test_repay_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => 999999]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => 1,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_repay_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoanWithSchedule(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(route('api.loans.repay', ['loan' => $foreignLoan->id]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => 1,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected response.',
        );
    }

    public function test_tenant_header_cannot_be_forged_for_repay(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation),
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_repay_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'test-repayment-' . uniqid(),
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'status',
                'settled_amount',
                'outstanding_principal',
                'outstanding_interest',
                'outstanding_penalty',
                'outstanding_total',
            ],
        ]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * The canonical valid payload for `POST /loans/{loan}/repay`.
     *
     * @return array<string, mixed>
     */
    private function validPayload(
        Obligation $obligation,
        string $amount = '500.0000',
    ): array {
        return [
            'amount' => $amount,
            'allocations' => [[
                'obligation_id' => (int) $obligation->id,
                'amount'        => $amount,
            ]],
        ];
    }

    public function test_replaying_same_idempotency_key_does_not_create_a_second_payment(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);
        $payload = $this->validPayload($obligation, amount: '500.0000');
        $headers = [
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'repayment-retry-key-0001',
        ];

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders($headers)
            ->postJson(route('api.loans.repay', ['loan' => $loan->id]), $payload)
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson(route('api.loans.repay', ['loan' => $loan->id]), $payload)
            ->assertStatus(200);

        $this->assertSame(1, Payment::query()->where('wallet_id', $this->wallet->id)->count());
        $this->assertSame('500.0000', (string) $loan->fresh()->settled_amount);
        $this->assertDatabaseHas('loan_repayment_idempotencies', [
            'tenant_id' => $this->tenant->id,
            'loan_id' => $loan->id,
            'idempotency_key' => 'repayment-retry-key-0001',
        ]);
    }

    public function test_retry_replays_original_response_and_hashes_equivalent_decimal_values_canonically(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);
        $originalHeaders = [
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'repayment-original-response-0001',
        ];

        Sanctum::actingAs($this->admin, ['*']);

        $original = $this->withHeaders($originalHeaders)->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0'),
        )->assertStatus(200);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'repayment-later-operation-0001',
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '250.0000'),
        )->assertStatus(200);

        // Equivalent decimal formatting is the same economic payload. The
        // response must be the original 500.0000 snapshot, not the current
        // loan representation after the later 250.0000 repayment.
        $replay = $this->withHeaders($originalHeaders)->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(200);

        $this->assertSame($original->getContent(), $replay->getContent());
        $this->assertSame(2, Payment::query()->where('wallet_id', $this->wallet->id)->count());
        $this->assertSame('750.0000', (string) $loan->fresh()->settled_amount);

        $this->assertDatabaseHas('loan_repayment_idempotencies', [
            'tenant_id' => $this->tenant->id,
            'loan_id' => $loan->id,
            'idempotency_key' => 'repayment-original-response-0001',
            'response_status' => 200,
        ]);
    }

    public function test_distinct_repayments_accumulate_settled_amount(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        foreach ([
            ['key' => 'repayment-distinct-key-0001', 'amount' => '500.0000'],
            ['key' => 'repayment-distinct-key-0002', 'amount' => '600.0000'],
        ] as $repayment) {
            $this->withHeaders([
                'X-Tenant-ID' => (string) $this->tenant->id,
                'Idempotency-Key' => $repayment['key'],
            ])->postJson(
                route('api.loans.repay', ['loan' => $loan->id]),
                $this->validPayload($obligation, amount: $repayment['amount']),
            )->assertStatus(200);
        }

        $this->assertSame('1100.0000', (string) $loan->fresh()->settled_amount);
        $this->assertSame(2, Payment::query()->where('wallet_id', $this->wallet->id)->count());
    }

    public function test_repayment_requires_idempotency_key_header(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.repay', ['loan' => $loan->id]),
            $this->validPayload($obligation, amount: '500.0000'),
        )->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_reusing_idempotency_key_with_different_payload_returns_conflict(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();
        $obligation = $this->makePrincipalObligation($loan);
        $headers = [
            'X-Tenant-ID' => (string) $this->tenant->id,
            'Idempotency-Key' => 'repayment-retry-key-0002',
        ];

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders($headers)
            ->postJson(
                route('api.loans.repay', ['loan' => $loan->id]),
                $this->validPayload($obligation, amount: '500.0000'),
            )
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->postJson(
                route('api.loans.repay', ['loan' => $loan->id]),
                $this->validPayload($obligation, amount: '600.0000'),
            )
            ->assertStatus(409);

        $this->assertSame(1, Payment::query()->where('wallet_id', $this->wallet->id)->count());
        $this->assertSame('500.0000', (string) $loan->fresh()->settled_amount);
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

    private function makeProduct(
        Tenant $tenant,
        string $code,
        ?VersionedPolicy $policy = null,
    ): LoanProduct {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => $code,
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

    /**
     * Create a loan in `active` state with a schedule of `$termMonths`
     * installments and the baseline history rows.
     */
    private function makeActiveLoanWithSchedule(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
        string $principal = '10000.0000',
        int $termMonths = 2,
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id                 = $tenant->id;
        $loan->borrower_shareholder_id   = $borrower->id;
        $loan->loan_product_id           = $product->id;
        $loan->principal_amount          = $principal;
        $loan->term_months               = $termMonths;
        $loan->status                    = LoanStatus::Active;
        $loan->application_date          = now();
        $loan->decision_date             = now();
        $loan->disbursement_date         = now()->subMonths(1);
        $loan->currency                  = 'ETB';
        $loan->settled_amount            = '0.0000';
        $loan->outstanding_principal     = $principal;
        $loan->outstanding_interest      = '0.0000';
        $loan->outstanding_penalty       = '0.0000';
        $loan->total_scheduled_interest  = '0.0000';
        $loan->total_scheduled_repayment = $principal;
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date             = now()->addMonths($termMonths);
        $loan->frozen_policy_version_id  = $product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

        $perMonth = bcdiv($principal, (string) $termMonths, 4);
        for ($i = 1; $i <= $termMonths; $i++) {
            LoanInstallment::create([
                'loan_id'            => $loan->id,
                'installment_number' => $i,
                'due_date'           => now()->addMonths($i)->toDateString(),
                'expected_principal' => $perMonth,
                'expected_interest'  => '0.0000',
                'expected_total'     => $perMonth,
            ]);
        }

        return $loan;
    }

    private function makeLoanInState(LoanStatus $status): Loan
    {
        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $this->borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '5000.0000';
        $loan->term_months              = 12;
        $loan->status                   = $status;
        $loan->application_date         = now();
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);

        return $loan;
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
            'occurred_at'        => now(),
        ]);
    }

    private function makeInterestObligation(
        Loan $loan,
        LoanInstallment $installment,
        string $amount = '100.0000',
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Interest->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => now(),
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
            'occurred_at'       => now(),
            'created_at'        => now(),
        ]);
    }

    /**
     * Create a Loan permission and attach it to the admin user.
     *
     * Named `createLoanPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base `Tests\TestCase`.
     */
    private function createLoanPermission(string $slug, string $label): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'loan',
            'scope'          => 'tenant',
            'tenant_id'      => null,
            'application_id' => null,
            'is_active'      => true,
            'is_system'      => true,
        ]);

        $this->admin->permissions()->attach($permission->id, ['assigned_at' => now()]);

        return $permission;
    }
}