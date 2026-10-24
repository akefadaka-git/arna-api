<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralStatus;
use MaaleArna\MASES\Core\Domains\Loan\Enums\CollateralType;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanCollateral;
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
 * TenantIsolationTest (Loan domain)
 *
 * Verifies that the Loan domain does not leak data across tenant
 * boundaries. This file is DISTINCT from the Phase 1 suite at
 * `tests/Feature/TenantIsolationTest.php`, from the Shareholder domain's
 * 2A.49 suite, and from the Wallet domain's 2B.67 suite. It is scoped to
 * the Loan domain and exercises every one of the sixteen Loan-domain
 * HTTP endpoints.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention, this file lives INSIDE the Loan
 * module at `mases_core/src/Domains/Loan/Tests/Feature/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Feature`.
 *
 * =============================================================================
 * The 15 active cross-tenant vectors (Stage 5 matrix)
 * =============================================================================
 * This suite is the executable form of the cross-tenant test matrix
 * referenced in the handoff. The vectors and their Loan-domain mappings:
 *
 *     T1   index              — GET  /loans
 *     T2   store              — POST /loans (cross-tenant reference)
 *     T3   show               — GET  /loans/{loan}
 *     T4   approve            — POST /loans/{loan}/approve
 *     T5   reject             — POST /loans/{loan}/reject
 *     T6   disburse           — POST /loans/{loan}/disburse
 *     T7   repay              — POST /loans/{loan}/repay
 *     T8   schedule           — GET  /loans/{loan}/schedule
 *     T9   statement          — GET  /loans/{loan}/statement
 *     T10  reconciliation     — GET  /loans/{loan}/reconciliation
 *     T11  restructure        — POST /loans/{loan}/restructure
 *     T13  writeoff           — POST /loans/{loan}/writeoff
 *     T14  collateral index   — GET  /loans/{loan}/collateral
 *     T15  collateral store   — POST /loans/{loan}/collateral
 *     T16  collateral release — POST /loans/{loan}/collateral/{collateral}/release
 *
 * Each of T1–T16 uses a genuine cross-tenant id. None uses the sentinel
 * `999999` — a non-existent id would exercise the "no such row" path,
 * not the "row exists but wrong tenant" path, and the two paths must be
 * indistinguishable from the caller's perspective.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The suite uses `RefreshDatabase`. The Loan-domain write endpoints
 * dispatch events via `DB::afterCommit()`, but every cross-tenant test
 * stops at the tenant-boundary check BEFORE any state change. There is
 * therefore no after-commit callback to await.
 *
 * The fixture setup uses direct model inserts (`Loan::create`,
 * `LoanCollateral::create`, `Wallet::create`, `Obligation::create`),
 * bypassing the service layer entirely, so no after-commit callbacks
 * are registered on the fixture path either.
 *
 * =============================================================================
 * The two-user topology
 * =============================================================================
 * The `admin` fixture holds EVERY Loan-domain permission. Consequently,
 * no test in this suite can fail with a 403 from missing permissions or
 * missing role assignments: the failures are all 404s from the
 * tenant-boundary enforcement (T2–T16) or 403s from the
 * `tenant.membership` middleware (T13 in some deployments).
 *
 * This is the same scope discipline as 2A.49 and 2B.67. Authentication
 * (401) and permission-level authorization (403) are tested by the
 * per-endpoint feature suites (A.102–A.114); this suite tests only the
 * tenant dimension.
 *
 * =============================================================================
 * Raw-body scan policy — the Amharic marker
 * =============================================================================
 * Where a test scans the raw response body for evidence of cross-tenant
 * leakage, the scan target is the tenant-B borrower's Amharic given name
 * (`ሳሙኤል`). Numeric ids are NOT used as scan targets: a single-digit id
 * collides with timestamps, UUIDs, and exception line numbers, as
 * documented in the 2A.49 finding.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `makeTenant()` — not `createTenant()` — to avoid the signature
 * collision with the protected helper declared on `Tests\TestCase`.
 *
 * `createLoanPermission()` — not `createPermission()` — to avoid the
 * collision with the protected helper on `Tests\TestCase`.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.0
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Primary tenant (tenantA) — the caller's tenant.
    // -------------------------------------------------------------------------
    private Tenant $tenantA;
    private User $admin;
    private Shareholder $borrowerA;
    private Wallet $walletA;
    private LoanProduct $productA;
    private VersionedPolicy $policyA;

    // -------------------------------------------------------------------------
    // Foreign tenant (tenantB) — the other tenant.
    // -------------------------------------------------------------------------
    private Tenant $tenantB;
    private Shareholder $borrowerB;
    private Wallet $walletB;
    private LoanProduct $productB;
    private VersionedPolicy $policyB;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenantA = $this->makeTenant('tenant-a', 'ቴናንት ኤ');
        $this->tenantB = $this->makeTenant('tenant-b', 'ቴናንት ቢ');

        // ---------------------------------------------------------------------
        // Admin — a member of tenantA only.
        // ---------------------------------------------------------------------
        $this->admin = User::factory()->create([
            'email' => 'iso-admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenantA->id])->save();
        $this->admin->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Every Loan-domain permission. Because the admin holds all of
        // them, no cross-tenant test can fail with a 403 from missing
        // permissions. See the class docblock §"The two-user topology".
        // ---------------------------------------------------------------------
        foreach (
            [
                'loan.view', 'loan.view_own', 'loan.apply',
                'loan.approve', 'loan.reject', 'loan.disburse',
                'loan.repay', 'loan.restructure', 'loan.writeoff',
                'loan.schedule.view', 'loan.statement.view',
                'loan.statement.view_own',
                'loan.collateral.view', 'loan.collateral.manage',
                'loan.collateral.release',
            ] as $slug
        ) {
            $this->createLoanPermission($slug, $slug);
        }
        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Policies and products — one per tenant.
        // ---------------------------------------------------------------------
        $this->policyA = $this->makePolicy();
        $this->policyB = $this->makePolicy();
        $this->productA = $this->makeProduct($this->tenantA, 'PROD-A', $this->policyA);
        $this->productB = $this->makeProduct($this->tenantB, 'PROD-B', $this->policyB);

        // ---------------------------------------------------------------------
        // Borrowers. TenantB's borrower carries the Amharic marker `ሳሙኤል`
        // used by every raw-body leak check in this suite.
        // ---------------------------------------------------------------------
        $this->borrowerA = Shareholder::create([
            'tenant_id'        => $this->tenantA->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->borrowerB = Shareholder::create([
            'tenant_id'        => $this->tenantB->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        // ---------------------------------------------------------------------
        // Wallets — one per borrower.
        // ---------------------------------------------------------------------
        $this->walletA = $this->makeWalletFor($this->borrowerA, 'ETB');
        $this->walletB = $this->makeWalletFor($this->borrowerB, 'ETB');

        Carbon::setTestNow(Carbon::parse('2026-12-15 10:00:00'));

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
    // T1 — index returns only tenantA loans
    // =========================================================================

    public function test_index_returns_only_tenant_loans_T1(): void
    {
        $loanA = $this->makeLoan($this->tenantA, $this->borrowerA, $this->productA, LoanStatus::Active);
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = array_map('intval', array_column($response->json('data'), 'id'));

        $this->assertContains((int) $loanA->id, $ids);
        $this->assertNotContains((int) $loanB->id, $ids);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
    }

    // =========================================================================
    // T2 — store rejects a cross-tenant reference
    // =========================================================================

    public function test_store_rejects_cross_tenant_borrower_T2(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.store'), [
            'borrower_shareholder_id' => $this->borrowerB->id,
            'loan_product_id'         => $this->productA->id,
            'principal_amount'        => '5000.0000',
            'term_months'             => 12,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // No loan created for tenantB's borrower.
        $this->assertSame(
            0,
            Loan::query()
                ->where('tenant_id', $this->tenantA->id)
                ->where('borrower_shareholder_id', $this->borrowerB->id)
                ->count(),
        );
    }

    // =========================================================================
    // T3 — show returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_show_returns_404_for_cross_tenant_loan_T3(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.show', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
    }

    // =========================================================================
    // T4 — approve returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_approve_returns_404_for_cross_tenant_loan_T4(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.approve', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign loan is unchanged.
        $this->assertSame(LoanStatus::Submitted, $loanB->fresh()->status);
    }

    // =========================================================================
    // T5 — reject returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_reject_returns_404_for_cross_tenant_loan_T5(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Submitted);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.reject', ['loan' => $loanB->id]), [
            'reason' => 'Cross-tenant attempt.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $this->assertSame(LoanStatus::Submitted, $loanB->fresh()->status);
    }

    // =========================================================================
    // T6 — disburse returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_disburse_returns_404_for_cross_tenant_loan_T6(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Approved);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.disburse', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $this->assertSame(LoanStatus::Approved, $loanB->fresh()->status);
    }

    // =========================================================================
    // T7 — repay returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_repay_returns_404_for_cross_tenant_loan_T7(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);
        $obligationB = $this->makeObligation($this->walletB, $loanB, '10000.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
            'Idempotency-Key' => 'tenant-isolation-repayment-key-0001',
        ])->postJson(route('api.loans.repay', ['loan' => $loanB->id]), [
            'amount' => '500.0000',
            'allocations' => [[
                'obligation_id' => (int) $obligationB->id,
                'amount'        => '500.0000',
            ]],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign obligation is unchanged.
        $obligationB->refresh();
        $this->assertSame('10000.0000', (string) $obligationB->outstanding_amount);
    }

    // =========================================================================
    // T8 — schedule returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_schedule_returns_404_for_cross_tenant_loan_T8(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.schedule.show', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // T9 — statement returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_statement_returns_404_for_cross_tenant_loan_T9(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.statement.show', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
    }

    // =========================================================================
    // T10 — reconciliation returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_reconciliation_returns_404_for_cross_tenant_loan_T10(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.reconciliation.show', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // T11 — restructure returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_restructure_returns_404_for_cross_tenant_loan_T11(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::InArrears);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.restructure', ['loan' => $loanB->id]), [
            'new_principal_amount'  => '8000.0000',
            'new_term_months'       => 24,
            'new_policy_version_id' => (int) $this->policyB->id,
            'reason'                => 'Cross-tenant attempt.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $this->assertSame(LoanStatus::InArrears, $loanB->fresh()->status);
    }

    // =========================================================================
    // T13 — writeoff returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_writeoff_returns_404_for_cross_tenant_loan_T13(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.writeoff', ['loan' => $loanB->id]), [
            'reason' => 'Cross-tenant attempt.',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        $this->assertSame(LoanStatus::Active, $loanB->fresh()->status);
    }

    // =========================================================================
    // T14 — collateral index returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_collateral_index_returns_404_for_cross_tenant_loan_T14(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loanB->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // T15 — collateral store returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_collateral_store_returns_404_for_cross_tenant_loan_T15(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loanB->id]), [
            'collateral_type'    => CollateralType::External->value,
            'description'        => 'Cross-tenant attempt.',
            'valuation_amount'   => '50000.0000',
            'valuation_currency' => 'ETB',
            'valuation_date'     => '2026-01-15',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // No collateral created for the foreign loan.
        $this->assertSame(
            0,
            LoanCollateral::query()->where('loan_id', $loanB->id)->count(),
        );
    }

    // =========================================================================
    // T16 — collateral release returns 404 for a cross-tenant loan
    // =========================================================================

    public function test_collateral_release_returns_404_for_cross_tenant_loan_T16(): void
    {
        $loanB = $this->makeLoan($this->tenantB, $this->borrowerB, $this->productB, LoanStatus::Active);
        $collateralB = $this->makeCollateral($loanB);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan'       => $loanB->id,
                'collateral' => $collateralB->id,
            ]),
            ['reason' => 'Cross-tenant attempt.'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign collateral is unchanged.
        $collateralB->refresh();
        $this->assertSame(CollateralStatus::Registered, $collateralB->status);
    }

    // =========================================================================
    // T13 (forged header) — X-Tenant-ID of another tenant is rejected
    // =========================================================================

    public function test_forged_tenant_header_is_rejected_for_loan_index(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // The admin is a member of tenantA only. Sending tenantB's id
        // in the X-Tenant-ID header attempts to elevate context. The
        // TenantMembershipMiddleware rejects with 403 TENANT_ACCESS_DENIED
        // before any controller or permission check runs.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a data payload.
        $this->assertNull($response->json('data'));

        // Semantic leak check: tenantB's borrower name must not appear
        // in the response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
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

    private function makeWalletFor(Shareholder $borrower, string $currency): Wallet
    {
        return Wallet::create([
            'tenant_id'       => $borrower->tenant_id,
            'shareholder_id'  => $borrower->id,
            'currency'        => $currency,
            'current_balance' => '0.0000',
            'is_active'       => true,
            'opened_at'       => now(),
        ]);
    }

    /**
     * Create a Loan with baseline history rows appropriate to the given
     * state.
     */
    private function makeLoan(
        Tenant $tenant,
        Shareholder $borrower,
        LoanProduct $product,
        LoanStatus $status,
    ): Loan {
        $loan = new Loan();
        $loan->tenant_id                = $tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = $status;
        $loan->application_date         = now()->subMonths(3);
        $loan->decision_date            = now()->subMonths(3);
        $loan->disbursement_date        = now()->subMonths(3);
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = '10000.0000';
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '833.3333';
        $loan->maturity_date            = now()->addMonths(9);
        $loan->frozen_policy_version_id = $product->product_rules_policy_version_id;
        $loan->save();

        $this->writeHistory($loan, null, LoanStatus::Draft);
        $this->writeHistory($loan, LoanStatus::Draft, LoanStatus::Submitted);

        if ($status !== LoanStatus::Draft && $status !== LoanStatus::Submitted) {
            $this->writeHistory($loan, LoanStatus::Submitted, LoanStatus::UnderReview);
        }

        if (
            $status === LoanStatus::Approved
            || $status === LoanStatus::Disbursed
            || $status === LoanStatus::Active
            || $status === LoanStatus::InArrears
            || $status === LoanStatus::WrittenOff
        ) {
            $this->writeHistory($loan, LoanStatus::UnderReview, LoanStatus::Approved);
        }

        if (
            $status === LoanStatus::Active
            || $status === LoanStatus::InArrears
            || $status === LoanStatus::WrittenOff
        ) {
            $this->writeHistory($loan, LoanStatus::Approved, LoanStatus::Disbursed);
            $this->writeHistory($loan, LoanStatus::Disbursed, LoanStatus::Active);
        }

        if ($status === LoanStatus::InArrears || $status === LoanStatus::WrittenOff) {
            $this->writeHistory($loan, LoanStatus::Active, LoanStatus::InArrears);
        }

        return $loan;
    }

    private function makeObligation(
        Wallet $wallet,
        Loan $loan,
        string $amount,
    ): Obligation {
        return Obligation::create([
            'wallet_id'          => $wallet->id,
            'component'          => ObligationComponent::Principal->value,
            'currency'           => $wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'reference_type'     => 'Loan',
            'reference_id'       => (string) $loan->id,
            'occurred_at'        => now(),
        ]);
    }

    private function makeCollateral(Loan $loan): LoanCollateral
    {
        return LoanCollateral::create([
            'loan_id'            => $loan->id,
            'collateral_type'    => CollateralType::External->value,
            'description'        => 'Fixture collateral.',
            'external_reference' => 'FIXTURE-REF',
            'valuation_amount'   => '50000.0000',
            'valuation_currency' => 'ETB',
            'valuation_date'     => '2026-01-15',
            'status'             => CollateralStatus::Registered->value,
            'registered_at'      => now(),
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