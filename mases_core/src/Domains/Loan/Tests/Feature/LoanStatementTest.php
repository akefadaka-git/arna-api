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
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanInterestAccrual;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanStatementTest
 *
 * Feature tests for the loan-statement HTTP surface:
 *
 *     GET /loans/{loan}/statement    show    — the loan's statement view
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
 * The statement endpoint is READ-ONLY. The controller (A.82) reads the
 * loan, its accruals, its Wallet-side obligations and payments, and
 * assembles a composite structure passed to `LoanStatementResource`
 * (A.74). It writes nothing and dispatches no events.
 *
 * `RefreshDatabase` is therefore the correct default per §2.13.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * The endpoint requires the `loan.statement.view` OR
 * `loan.statement.view_own` permission. `LoanPolicy::viewStatement()`
 * (A.52) resolves the two paths:
 *
 *   - `loan.statement.view` grants access to any loan in the tenant.
 *   - `loan.statement.view_own` grants access only to a loan whose
 *     borrower matches the caller's shareholder record.
 *
 * The tests exercise both paths with distinct user fixtures.
 *
 * =============================================================================
 * The composite response
 * =============================================================================
 * The response carries the statement aggregation assembled by the
 * controller (A.82):
 *
 *   - `loan` — the loan's identity and lifecycle dates.
 *   - `period` — the statement's date range.
 *   - `opening_balance` — the loan's outstanding at the period's start.
 *   - `lines` — the chronological event list.
 *   - `closing_balance` — the loan's outstanding at the period's end.
 *   - `totals` — aggregate buckets (disbursed, interest accrued,
 *     penalty accrued, repaid, restructured, written off).
 *
 * The tests assert the presence and shape of each section, plus the
 * chronological ordering of the lines.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - statement on a cross-tenant loan id → 404
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
final class LoanStatementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $borrowerUser;
    private User $stranger;
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

        $this->admin = $this->makeUserInTenant('admin');
        $this->borrowerUser = $this->makeUserInTenant('borroweruser');
        $this->stranger = $this->makeUserInTenant('stranger');
        $this->userWithoutPermission = $this->makeUserInTenant('noperm');

        // ---------------------------------------------------------------------
        // Permissions.
        //
        //   admin         → loan.statement.view (broad path)
        //   borrowerUser  → loan.statement.view_own (narrow path)
        //   stranger      → loan.statement.view_own (narrow path, no
        //                   ownership match against the fixture loan)
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',                 'View loans',                [$this->admin]);
        $this->createLoanPermission('loan.statement.view',       'View statements',           [$this->admin]);
        $this->createLoanPermission('loan.statement.view_own',   'View own statements',       [$this->borrowerUser, $this->stranger]);

        foreach ([$this->admin, $this->borrowerUser, $this->stranger, $this->userWithoutPermission] as $user) {
            $user->load('permissions');
        }

        // ---------------------------------------------------------------------
        // Shareholders, wallets, products, policy.
        // ---------------------------------------------------------------------
        $this->borrower = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'user_id'          => $this->borrowerUser->id,
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

    public function test_statement_requires_authentication(): void
    {
        $loan = $this->makeActiveLoan();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization — broad path
    // =========================================================================

    public function test_statement_grants_broad_permission(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Authorization — own-only path
    // =========================================================================

    public function test_statement_grants_own_only_permission_when_user_owns_loan(): void
    {
        // `borrowerUser` is linked to the borrower Shareholder (via the
        // `user_id` FK). The policy's own-only path grants.
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->borrowerUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_statement_denies_own_only_when_user_does_not_own_loan(): void
    {
        // `stranger` holds `loan.statement.view_own` but is NOT linked
        // to the borrower Shareholder.
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->stranger, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    public function test_statement_denies_user_without_statement_permission(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    public function test_loan_view_does_not_imply_statement_access(): void
    {
        // A user holding only `loan.view` (no statement permission)
        // cannot access the statement endpoint. The two permission
        // families are independent.
        $loan = $this->makeActiveLoan();

        $loanViewOnly = $this->makeUserInTenant('loanviewonly');
        $this->createLoanPermission('loan.view', 'View loans', [$loanViewOnly]);
        $loanViewOnly->load('permissions');

        Sanctum::actingAs($loanViewOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    // =========================================================================
    // Response shape — empty statement
    // =========================================================================

    public function test_statement_returns_200_for_disbursed_loan_with_no_activity(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_statement_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'loan' => [
                    'id',
                    'tenant_id',
                    'borrower_shareholder_id',
                    'loan_product_id',
                    'status',
                    'currency',
                    'principal_amount',
                    'term_months',
                    'application_date',
                    'decision_date',
                    'disbursement_date',
                    'maturity_date',
                    'closed_at',
                ],
                'period' => [
                    'from',
                    'to',
                ],
                'opening_balance' => [
                    'principal',
                    'interest',
                    'penalty',
                    'total',
                ],
                'lines',
                'closing_balance' => [
                    'principal',
                    'interest',
                    'penalty',
                    'total',
                ],
                'totals' => [
                    'total_disbursed',
                    'total_interest_accrued',
                    'total_penalty_accrued',
                    'total_repaid',
                    'total_restructured',
                    'total_written_off',
                ],
            ],
        ]);
    }

    public function test_statement_returns_loan_identity(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.loan.id', $loan->id);
        $response->assertJsonPath('data.loan.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.loan.borrower_shareholder_id', $this->borrower->id);
        $response->assertJsonPath('data.loan.status', LoanStatus::Active->value);
        $response->assertJsonPath('data.loan.currency', 'ETB');
    }

    // =========================================================================
    // Statement content — disbursement
    // =========================================================================

    public function test_statement_includes_disbursement_line(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makePrincipalObligation($loan, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        // At least one line of type `disbursement`.
        $lines = $response->json('data.lines');
        $this->assertIsArray($lines);

        $types = array_column($lines, 'type');
        $this->assertContains('disbursement', $types);
    }

    public function test_statement_totals_include_disbursed_amount(): void
    {
        $loan = $this->makeActiveLoan(principal: '10000.0000');
        $this->makePrincipalObligation($loan, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $this->assertSame(
            '10000.0000',
            $response->json('data.totals.total_disbursed'),
        );
    }

    // =========================================================================
    // Statement content — interest accrual
    // =========================================================================

    public function test_statement_includes_interest_accrual_line(): void
    {
        $loan = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);
        $this->makeInterestAccrual($loan, $installment, '100.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        $types = array_column($response->json('data.lines'), 'type');
        $this->assertContains('interest_accrual', $types);
    }

    public function test_statement_totals_include_interest_accrued_amount(): void
    {
        $loan = $this->makeActiveLoan();
        $installment = $this->makeInstallment($loan, number: 1);
        $this->makeInterestAccrual($loan, $installment, '100.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $this->assertSame(
            '100.0000',
            $response->json('data.totals.total_interest_accrued'),
        );
    }

    // =========================================================================
    // Statement content — repayment
    // =========================================================================

    public function test_statement_includes_repayment_line(): void
    {
        $loan = $this->makeActiveLoan();
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');
        $this->makeRepayment($loan, $obligation, '500.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        $types = array_column($response->json('data.lines'), 'type');
        $this->assertContains('repayment', $types);
    }

    public function test_statement_totals_include_repaid_amount(): void
    {
        $loan = $this->makeActiveLoan();
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');
        $this->makeRepayment($loan, $obligation, '500.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $this->assertSame(
            '500.0000',
            $response->json('data.totals.total_repaid'),
        );
    }

    // =========================================================================
    // Chronological ordering
    // =========================================================================

    public function test_statement_lines_are_in_chronological_order(): void
    {
        $loan = $this->makeActiveLoan();
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');
        $installment = $this->makeInstallment($loan, number: 1);
        $this->makeInterestAccrual($loan, $installment, '100.0000');
        $this->makeRepayment($loan, $obligation, '500.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        $lines = $response->json('data.lines');
        $this->assertNotEmpty($lines);

        $timestamps = array_column($lines, 'occurred_at');

        $sorted = $timestamps;
        sort($sorted);

        $this->assertSame($sorted, $timestamps);
    }

    // =========================================================================
    // Monotonic running balance
    // =========================================================================

    public function test_statement_lines_carry_running_balance(): void
    {
        $loan = $this->makeActiveLoan();
        $obligation = $this->makePrincipalObligation($loan, '10000.0000');
        $this->makeRepayment($loan, $obligation, '500.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);

        $lines = $response->json('data.lines');
        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $this->assertArrayHasKey('running_balance', $line);
            $this->assertArrayHasKey('principal', $line['running_balance']);
            $this->assertArrayHasKey('interest', $line['running_balance']);
            $this->assertArrayHasKey('penalty', $line['running_balance']);
            $this->assertArrayHasKey('total', $line['running_balance']);
        }
    }

    // =========================================================================
    // Pre-disbursement loan
    // =========================================================================

    public function test_statement_of_approved_loan_returns_empty_period(): void
    {
        $loan = $this->makeApprovedLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.lines', []);
        $response->assertJsonPath('data.opening_balance.total', '0.0000');
        $response->assertJsonPath('data.closing_balance.total', '0.0000');
    }

    // =========================================================================
    // Cross-tenant and non-existent
    // =========================================================================

    public function test_statement_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_statement_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $foreignLoan->id]));

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

    public function test_tenant_header_cannot_be_forged_for_statement(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function makeUserInTenant(string $role): User
    {
        $user = User::factory()->create([
            'email' => $role . '-' . uniqid() . '@example.test',
        ]);
        $user->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        return $user;
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

    private function makeActiveLoan(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
        string $principal = '10000.0000',
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id                 = $tenant->id;
        $loan->borrower_shareholder_id   = $borrower->id;
        $loan->loan_product_id           = $product->id;
        $loan->principal_amount          = $principal;
        $loan->term_months               = 12;
        $loan->status                    = LoanStatus::Active;
        $loan->application_date          = now()->subMonths(3);
        $loan->decision_date             = now()->subMonths(3);
        $loan->disbursement_date         = now()->subMonths(3);
        $loan->currency                  = 'ETB';
        $loan->settled_amount            = '0.0000';
        $loan->outstanding_principal     = $principal;
        $loan->outstanding_interest      = '0.0000';
        $loan->outstanding_penalty       = '0.0000';
        $loan->total_scheduled_interest  = '0.0000';
        $loan->total_scheduled_repayment = $principal;
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date             = now()->addMonths(9);
        $loan->frozen_policy_version_id  = $product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);
        $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
        $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);

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
        string $expectedInterest = '100.0000',
    ): LoanInstallment {
        return LoanInstallment::create([
            'loan_id'            => $loan->id,
            'installment_number' => $number,
            'due_date'           => now()->addMonths($number)->toDateString(),
            'expected_principal' => '1000.0000',
            'expected_interest'  => $expectedInterest,
            'expected_total'     => bcadd('1000.0000', $expectedInterest, 4),
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
            'occurred_at'        => $loan->disbursement_date ?? now(),
        ]);
    }

    private function makeInterestAccrual(
        Loan $loan,
        LoanInstallment $installment,
        string $amount = '100.0000',
    ): LoanInterestAccrual {
        $accrual = LoanInterestAccrual::create([
            'loan_id'            => $loan->id,
            'installment_id'     => $installment->id,
            'accrual_period_key' => sprintf('%d:%d', $loan->id, $installment->id),
            'interest_amount'    => $amount,
            'recorded_at'        => now()->subDays(5),
            'created_at'         => now()->subDays(5),
        ]);

        // The service normally writes the Wallet obligation and its id
        // back. The fixture does both directly to keep the statement
        // aggregation happy without invoking the service.
        $obligation = Obligation::create([
            'wallet_id'          => $this->wallet->id,
            'component'          => ObligationComponent::Interest->value,
            'currency'           => $this->wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => $installment->due_date,
        ]);

        $accrual->wallet_obligation_id = $obligation->id;
        $accrual->save();

        return $accrual;
    }

    private function makeRepayment(
        Loan $loan,
        Obligation $obligation,
        string $amount = '500.0000',
    ): void {
        $payment = Payment::create([
            'wallet_id'        => $this->wallet->id,
            'currency'         => $this->wallet->currency,
            'amount'           => $amount,
            'unapplied_amount' => '0.0000',
            'reference_type'   => 'LoanRepayment',
            'reference_id'     => (string) $loan->id,
            'received_at'      => now()->subDays(2),
        ]);

        PaymentAllocation::create([
            'wallet_id'     => $this->wallet->id,
            'payment_id'    => $payment->id,
            'obligation_id' => $obligation->id,
            'amount'        => $amount,
            'allocated_at'  => now()->subDays(2),
            'allocated_by'  => null,
        ]);

        // Reduce the obligation's outstanding.
        $obligation->outstanding_amount = bcsub(
            (string) $obligation->outstanding_amount,
            $amount,
            4,
        );
        $obligation->save();
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
            'occurred_at'       => now()->subMonths(3),
            'created_at'        => now()->subMonths(3),
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

        foreach ($recipients as $user) {
            $user->permissions()->syncWithoutDetaching([
                $permission->id => ['assigned_at' => now()],
            ]);
        }

        return $permission;
    }
}