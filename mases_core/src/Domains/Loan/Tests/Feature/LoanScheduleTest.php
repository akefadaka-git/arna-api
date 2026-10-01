<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\InstallmentStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInstallment;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanScheduleTest
 *
 * Feature tests for the loan-schedule HTTP surface:
 *
 *     GET /loans/{loan}/schedule    show    — the loan's amortization schedule
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
 * The schedule endpoint is READ-ONLY: it performs no writes and
 * dispatches no events. `RefreshDatabase` is the correct default per
 * §2.13 and matches the pattern of the Shareholder and Wallet feature
 * suites.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * The endpoint requires the `loan.schedule.view` permission. The
 * controller (A.81) dispatches `Gate::authorize('viewSchedule', $loan)`,
 * which resolves through `LoanPolicy::viewSchedule()` (A.52):
 *
 *     holds `loan.schedule.view` AND (holds `loan.view` OR is the borrower)
 *
 * The second condition is exercised implicitly (the admin holds
 * `loan.view`, so the OR branch passes).
 *
 * =============================================================================
 * The eager-load contract
 * =============================================================================
 * `LoanScheduleResource` (A.73) gates both `installments` and `totals`
 * on `whenLoaded('installments', ...)`. The controller (A.81) passes
 * `with: ['installments']` to the repository at the resolution step, so
 * the response always carries both fields. A test in this file verifies
 * the presence of `installments` and `totals` in every successful
 * response.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - schedule on a cross-tenant loan id → 404
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
final class LoanScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $borrower;
    private Shareholder $otherBorrower;
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
        // Permissions.
        //
        // `loan.view` is required by `LoanPolicy::viewSchedule()`'s
        // second clause (view OR own).
        //
        // `loan.schedule.view` is the endpoint's required permission.
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',          'View loans');
        $this->createLoanPermission('loan.schedule.view', 'View loan schedules');

        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders, products, policy.
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

    public function test_schedule_requires_authentication(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_schedule_requires_schedule_view_permission(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    public function test_schedule_denies_loan_view_without_schedule_view(): void
    {
        // A user with only `loan.view` (no `loan.schedule.view`) is
        // denied. `LoanPolicy::viewSchedule()` requires
        // `loan.schedule.view` as the first clause.
        $loan = $this->makeActiveLoanWithSchedule();

        $loanViewOnly = User::factory()->create([
            'email' => 'loanviewonly-' . uniqid() . '@example.test',
        ]);
        $loanViewOnly->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $loanViewOnly->tenants()->attach($this->tenant->id, ['joined_at' => now()]);
        $this->createLoanPermission('loan.view', 'View loans', [$loanViewOnly]);
        $loanViewOnly->load('permissions');

        Sanctum::actingAs($loanViewOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    // =========================================================================
    // Happy path — loan with a schedule
    // =========================================================================

    public function test_schedule_returns_200_for_active_loan(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_schedule_returns_loan_identity(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.loan_id', $loan->id);
        $response->assertJsonPath('data.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.borrower_shareholder_id', $this->borrower->id);
        $response->assertJsonPath('data.loan_product_id', $this->product->id);
        $response->assertJsonPath('data.status', LoanStatus::Active->value);
        $response->assertJsonPath('data.currency', 'ETB');
    }

    public function test_schedule_returns_schedule_summary_columns(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000', termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'monthly_installment_amount',
                'total_scheduled_interest',
                'total_scheduled_repayment',
                'maturity_date',
            ],
        ]);
    }

    // =========================================================================
    // Happy path — installments
    // =========================================================================

    public function test_schedule_returns_installments_collection(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonCount(4, 'data.installments');
    }

    public function test_schedule_installments_are_ordered_by_number(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 3);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.installments.0.installment_number', 1);
        $response->assertJsonPath('data.installments.1.installment_number', 2);
        $response->assertJsonPath('data.installments.2.installment_number', 3);
    }

    public function test_schedule_installment_entry_has_expected_shape(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 1);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'installments' => [
                    '*' => [
                        'id',
                        'loan_id',
                        'installment_number',
                        'due_date',
                        'expected_principal',
                        'expected_interest',
                        'expected_total',
                        'settled_principal',
                        'settled_interest',
                        'settled_amount',
                        'remaining_principal',
                        'remaining_interest',
                        'remaining_total',
                        'status',
                    ],
                ],
            ],
        ]);
    }

    // =========================================================================
    // Happy path — totals
    // =========================================================================

    public function test_schedule_returns_totals_object(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'totals' => [
                    'installment_count',
                    'active_installment_count',
                    'pending_count',
                    'partial_count',
                    'settled_count',
                    'overdue_count',
                    'superseded_count',
                    'total_expected_principal',
                    'total_expected_interest',
                    'total_expected_repayment',
                    'total_settled_principal',
                    'total_settled_interest',
                    'total_settled_amount',
                    'total_remaining_principal',
                    'total_remaining_interest',
                    'total_remaining_total',
                ],
            ],
        ]);
    }

    public function test_schedule_totals_reflect_installment_count(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.totals.installment_count', 4);
        $response->assertJsonPath('data.totals.active_installment_count', 4);
        $response->assertJsonPath('data.totals.pending_count', 4);
        $response->assertJsonPath('data.totals.settled_count', 0);
        $response->assertJsonPath('data.totals.superseded_count', 0);
    }

    public function test_schedule_totals_expected_principal_equals_loan_principal(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(principal: '10000.0000', termMonths: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $this->assertSame(
            '10000.0000',
            $response->json('data.totals.total_expected_principal'),
        );
    }

    // =========================================================================
    // Happy path — pre-disbursement loan
    // =========================================================================

    public function test_schedule_of_approved_loan_returns_empty_installments(): void
    {
        // A loan in `approved` state has no schedule yet. The endpoint
        // returns the loan's identity with an empty installments array
        // and zero totals.
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.installments', []);
        $response->assertJsonPath('data.totals.installment_count', 0);
        $response->assertJsonPath('data.totals.total_expected_principal', '0.0000');
        $response->assertJsonPath('data.monthly_installment_amount', null);
        $response->assertJsonPath('data.maturity_date', null);
    }

    // =========================================================================
    // Superseded installments
    // =========================================================================

    public function test_schedule_includes_superseded_installments(): void
    {
        $loan = $this->makeActiveLoanWithSchedule(termMonths: 2);

        // Mark both installments as superseded (simulating a restructure).
        LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->update(['status' => InstallmentStatus::Superseded->value]);

        // Add two new installments continuing the numbering.
        $this->makeInstallment($loan, number: 3);
        $this->makeInstallment($loan, number: 4);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        // All four installments are returned.
        $response->assertJsonCount(4, 'data.installments');

        // The totals distinguish active vs. superseded.
        $response->assertJsonPath('data.totals.installment_count', 4);
        $response->assertJsonPath('data.totals.superseded_count', 2);
        $response->assertJsonPath('data.totals.active_installment_count', 2);
    }

    // =========================================================================
    // Cross-tenant and non-existent
    // =========================================================================

    public function test_schedule_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_schedule_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoanWithSchedule(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $foreignLoan->id]));

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

    public function test_tenant_header_cannot_be_forged_for_schedule(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_schedule_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'loan_id',
                'tenant_id',
                'borrower_shareholder_id',
                'loan_product_id',
                'status',
                'currency',
                'principal_amount',
                'term_months',
                'monthly_installment_amount',
                'total_scheduled_interest',
                'total_scheduled_repayment',
                'maturity_date',
                'totals',
                'installments',
            ],
        ]);
    }

    public function test_schedule_does_not_leak_collaterals_or_other_relations(): void
    {
        $loan = $this->makeActiveLoanWithSchedule();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.collaterals');
        $response->assertJsonMissingPath('data.restructurings');
        $response->assertJsonMissingPath('data.write_off');
        $response->assertJsonMissingPath('data.borrower');
        $response->assertJsonMissingPath('data.product');
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
        int $termMonths = 4,
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
        $loan->monthly_installment_amount = bcdiv($principal, (string) $termMonths, 4);
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

    private function makeApprovedLoan(): Loan
    {
        $loan = new Loan();
        $loan->tenant_id                = $this->tenant->id;
        $loan->borrower_shareholder_id  = $this->borrower->id;
        $loan->loan_product_id          = $this->product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::Approved;
        $loan->application_date         = now();
        $loan->decision_date            = now();
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->frozen_policy_version_id = $this->product->product_rules_policy_version_id;
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
            'due_date'           => now()->addMonths($number)->toDateString(),
            'expected_principal' => '2500.0000',
            'expected_interest'  => '0.0000',
            'expected_total'     => '2500.0000',
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
     * Ensure a Loan permission exists and attach it to the given users.
     *
     * Idempotent against the `permissions.slug` UNIQUE constraint: the
     * same slug may legitimately be requested twice within one test —
     * once in `setUp()` for the baseline users, and once from a test
     * body that grants it to a freshly-created user. The second call
     * returns the existing row via `firstOrCreate()` instead of
     * attempting a duplicate insert, and the attach is
     * detach-safe for repeated (user, permission) pairs.
     *
     * @param array<int, User> $recipients
     */
    private function createLoanPermission(string $slug, string $label, array $recipients = []): Permission
    {
        $permission = Permission::firstOrCreate(
            ['slug' => $slug],
            [
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
            ],
        );

        $recipients = $recipients === [] ? [$this->admin] : $recipients;

        foreach ($recipients as $user) {
            $user->permissions()->syncWithoutDetaching([
                $permission->id => ['assigned_at' => now()],
            ]);
        }

        return $permission;
    }
}