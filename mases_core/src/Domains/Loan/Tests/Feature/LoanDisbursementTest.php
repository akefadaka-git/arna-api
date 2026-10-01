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
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanDisbursementTest
 *
 * Feature tests for the loan-disbursement HTTP surface:
 *
 *     POST /loans/{loan}/disburse    disburse    — disburse an approved loan
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
 * The feature tests do NOT assert on the `LoanDisbursed` event (A.41).
 * The unit suite (A.95) covers the dispatch and the payload; this file
 * focuses on the HTTP contract: request shape, authorization, the
 * state-machine guards reached through the endpoint, the Wallet
 * obligation side effect, the schedule generation, and the two-stage
 * response envelope.
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
 *   3. The `loan.disburse` permission via `LoanPolicy::disburse()`
 *      dispatched by the controller (A.77).
 *
 * =============================================================================
 * The disbursement flow (A.30)
 * =============================================================================
 * Per A.30, a successful disbursement:
 *   - records the Wallet principal obligation,
 *   - generates the schedule,
 *   - writes `currency`, `disbursement_date`, `outstanding_principal`,
 *   - transitions `approved → disbursed` and `disbursed → active`.
 *
 * The feature tests assert the OBSERVABLE state of each of these steps
 * through the HTTP surface.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - disburse on a cross-tenant loan id → 404
 *   - forged `X-Tenant-ID` header → 403
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createLoanPermission()` — not `createPermission()` — to avoid the
 * method-name collision with the protected helper on `Tests\TestCase`.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.0
 */
final class LoanDisbursementTest extends TestCase
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
        $this->createLoanPermission('loan.view',     'View loans');
        $this->createLoanPermission('loan.disburse', 'Disburse loans');

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

    public function test_disburse_requires_authentication(): void
    {
        $loan = $this->makeApprovedLoan();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_disburse_requires_disburse_permission(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(403);

        // The loan is unchanged.
        $this->assertSame(
            LoanStatus::Approved,
            $loan->fresh()->status,
        );

        // No Wallet obligation was created.
        $this->assertSame(
            0,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_disburse_transitions_approved_to_active(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', LoanStatus::Active->value);
        $response->assertJsonPath('data.id', $loan->id);

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_disburse_writes_currency_from_wallet(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();
        $this->assertSame('ETB', (string) $loan->currency);
    }

    public function test_disburse_writes_disbursement_date(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();
        $this->assertNotNull($loan->disbursement_date);
    }

    public function test_disburse_writes_outstanding_principal(): void
    {
        $loan = $this->makeApprovedLoan(principal: '5000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();
        $this->assertSame('5000.0000', (string) $loan->outstanding_principal);
    }

    public function test_disburse_records_wallet_principal_obligation(): void
    {
        $loan = $this->makeApprovedLoan(principal: '5000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $this->assertDatabaseHas('wallet_obligations', [
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Principal->value,
            'original_amount'    => '5000.0000',
            'outstanding_amount' => '5000.0000',
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
        ]);
    }

    public function test_disburse_updates_wallet_balance_negatively(): void
    {
        $loan = $this->makeApprovedLoan(principal: '5000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $this->wallet->refresh();
        $this->assertSame('-5000.0000', (string) $this->wallet->current_balance);
    }

    public function test_disburse_generates_schedule(): void
    {
        $loan = $this->makeApprovedLoan(principal: '5000.0000', termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $this->assertSame(
            4,
            LoanInstallment::query()->where('loan_id', $loan->id)->count(),
        );
    }

    public function test_disburse_writes_schedule_summary_columns(): void
    {
        $loan = $this->makeApprovedLoan(principal: '5000.0000', termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $loan->refresh();
        $this->assertNotNull($loan->monthly_installment_amount);
        $this->assertNotNull($loan->total_scheduled_interest);
        $this->assertNotNull($loan->total_scheduled_repayment);
        $this->assertNotNull($loan->maturity_date);
    }

    public function test_disburse_writes_two_history_rows(): void
    {
        $loan = $this->makeApprovedLoan();

        $baselineCount = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->count();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loan->id)
            ->orderBy('id')
            ->get();

        $this->assertCount($baselineCount + 2, $history);

        $disbursedRow = $history[$baselineCount];
        $activeRow    = $history[$baselineCount + 1];

        $this->assertSame(LoanStatus::Approved, $disbursedRow->from_status);
        $this->assertSame(LoanStatus::Disbursed, $disbursedRow->to_status);

        $this->assertSame(LoanStatus::Disbursed, $activeRow->from_status);
        $this->assertSame(LoanStatus::Active, $activeRow->to_status);
    }

    // =========================================================================
    // Optional description
    // =========================================================================

    public function test_disburse_accepts_optional_description(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]), [
            'description' => 'Custom disbursement note.',
        ])->assertStatus(200);

        // The Wallet obligation carries the supplied description.
        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($obligation);
        $this->assertSame('Custom disbursement note.', (string) $obligation->description);
    }

    public function test_disburse_accepts_empty_body(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]))
          ->assertStatus(200);
    }

    public function test_disburse_rejects_overlong_description(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]), [
            'description' => str_repeat('a', 501),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['description']]);
    }

    // =========================================================================
    // State guard
    // =========================================================================

    public function test_disburse_rejects_draft_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Draft);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_disburse_rejects_submitted_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_disburse_rejects_already_active_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_disburse_rejects_written_off_loan(): void
    {
        $loan = $this->makeLoanInState(LoanStatus::WrittenOff);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    // =========================================================================
    // Wallet presence
    // =========================================================================

    public function test_disburse_rejects_borrower_without_wallet(): void
    {
        // A second borrower in the same tenant without a Wallet.
        $walletlessBorrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ካሳሁን',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        $loan = $this->makeApprovedLoan(borrower: $walletlessBorrower);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['wallet']]);
    }

    // =========================================================================
    // Currency match (Q8)
    // =========================================================================

    public function test_disburse_rejects_currency_mismatch(): void
    {
        // A product in USD against a wallet in ETB.
        $usdProduct = $this->makeProduct(
            $this->tenant,
            'PROD-USD',
            $this->policy,
            currency: 'USD',
        );

        $loan = $this->makeApprovedLoan(product: $usdProduct);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['currency']]);
    }

    // =========================================================================
    // Cross-tenant and non-existent
    // =========================================================================

    public function test_disburse_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_disburse_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeApprovedLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $foreignLoan->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign loan is unchanged.
        $this->assertSame(
            LoanStatus::Approved,
            $foreignLoan->fresh()->status,
        );

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected response.',
        );
    }

    public function test_tenant_header_cannot_be_forged_for_disburse(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_disburse_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'status',
                'currency',
                'disbursement_date',
                'outstanding_principal',
                'monthly_installment_amount',
                'total_scheduled_interest',
                'total_scheduled_repayment',
                'maturity_date',
            ],
        ]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

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
        string $currency = 'ETB',
    ): LoanProduct {
        $attributes = [
            'tenant_id'                     => $tenant->id,
            'code'                          => $code,
            'name_am'                       => 'የሙከራ ምርት',
            'name_en'                       => 'Test Product',
            'currency'                      => $currency,
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
     * Create a loan in `approved` state, with the baseline history rows
     * that a real loan in that state would carry.
     */
    private function makeApprovedLoan(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
        string $principal = '5000.0000',
        int $termMonths = 12,
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id                = $tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $product->id;
        $loan->principal_amount         = $principal;
        $loan->term_months              = $termMonths;
        $loan->status                   = LoanStatus::Approved;
        $loan->application_date         = now();
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $product->product_rules_policy_version_id;
        $loan->decision_date            = now();
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);

        return $loan;
    }

    /**
     * Create a loan in an arbitrary state, with the baseline history rows
     * that a real loan in that state would carry.
     */
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

        if ($status !== LoanStatus::Draft) {
            $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        }

        if (
            $status !== LoanStatus::Draft
            && $status !== LoanStatus::Submitted
        ) {
            $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        }

        if (
            $status === LoanStatus::Approved
            || $status === LoanStatus::Disbursed
            || $status === LoanStatus::Active
            || $status === LoanStatus::InArrears
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