<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
use MaaleArna\MASES\Core\Policy\Models\VersionedPolicy;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanCollateralTest
 *
 * Feature tests for the loan-collateral HTTP surface:
 *
 *     GET  /loans/{loan}/collateral                                 index
 *     POST /loans/{loan}/collateral                                 store
 *     POST /loans/{loan}/collateral/{collateral}/release            release
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
 * The collateral endpoints do NOT dispatch after-commit events asserted
 * by this suite. `LoanCollateralService::register()` (A.37) and
 * `::release()` (A.37) dispatch no Loan-domain events in Phase 2C
 * (the `LoanCollateralReleased` event, A.50, is dispatched by the
 * service when it fires — but the A.50 delivery is a RESERVED artefact
 * that no current code path constructs; see A.50's class docblock
 * §"RESERVED — NO PHASE 2C DISPATCHER").
 *
 * `RefreshDatabase` is therefore the correct default per §2.13 and the
 * pattern followed by the Shareholder and Wallet feature suites.
 *
 * =============================================================================
 * Authorization layering — three distinct ability surfaces
 * =============================================================================
 * The three endpoints use three DISTINCT authorization paths, all
 * documented on `LoanCollateralPolicy` (A.54):
 *
 *   index    → Gate::authorize('view', $loan)
 *              Ability lives on `LoanPolicy`. The list of collaterals
 *              is a projection of the loan; a caller authorized to
 *              view the loan is authorized to view its collateral
 *              list.
 *
 *   store    → Gate::authorize('manage', $transient)
 *              Ability lives on `LoanCollateralPolicy`. The
 *              authorization is dispatched against a TRANSIENT
 *              `LoanCollateral` whose `loan_id` is populated. See A.54
 *              §"The transient-instance dispatch".
 *
 *   release  → Gate::authorize('release', $collateral)
 *              Ability lives on `LoanCollateralPolicy`. The policy
 *              delegates its loan-viewability check through
 *              `LoanPolicy::view()`.
 *
 * The tests below exercise each surface independently.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - index on a cross-tenant loan → 404
 *   - store on a cross-tenant loan → 404
 *   - release on a cross-tenant loan → 404
 *   - release with a cross-loan collateral id → 404
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
final class LoanCollateralTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $viewerOnly;
    private User $managerOnly;
    private User $releaserOnly;
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

        // ---------------------------------------------------------------------
        // Four users with distinct permission grants.
        //
        //   admin         → loan.view + all three collateral permissions
        //                   (satisfies every policy path)
        //   viewerOnly    → loan.view + loan.collateral.view
        //                   (can index; cannot manage or release)
        //   managerOnly   → loan.view + loan.collateral.manage
        //                   (can store; cannot release)
        //   releaserOnly  → loan.view + loan.collateral.release
        //                   (can release; cannot store)
        // ---------------------------------------------------------------------
        $this->admin = $this->makeUserInTenant('admin');
        $this->viewerOnly = $this->makeUserInTenant('vieweronly');
        $this->managerOnly = $this->makeUserInTenant('manageronly');
        $this->releaserOnly = $this->makeUserInTenant('releaseronly');

        // ---------------------------------------------------------------------
        // Permissions.
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',              'View loans',              [$this->admin, $this->viewerOnly, $this->managerOnly, $this->releaserOnly]);
        $this->createLoanPermission('loan.collateral.view',   'View collateral',         [$this->admin, $this->viewerOnly]);
        $this->createLoanPermission('loan.collateral.manage', 'Register collateral',     [$this->admin, $this->managerOnly]);
        $this->createLoanPermission('loan.collateral.release', 'Release collateral',     [$this->admin, $this->releaserOnly]);

        foreach ([$this->admin, $this->viewerOnly, $this->managerOnly, $this->releaserOnly] as $user) {
            $user->load('permissions');
        }

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
    // index() — authentication and authorization
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $loan = $this->makeActiveLoan();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_index_requires_view_ability_on_loan(): void
    {
        // A user WITHOUT any loan-view permission cannot list the
        // loan's collateral, even if they hold `loan.collateral.view`.
        $loan = $this->makeActiveLoan();

        $noLoanViewUser = $this->makeUserInTenant('noloanview');
        $this->createLoanPermission('loan.collateral.view', 'View collateral', [$noLoanViewUser]);
        $noLoanViewUser->load('permissions');

        Sanctum::actingAs($noLoanViewUser, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(403);
    }

    // =========================================================================
    // index() — happy path
    // =========================================================================

    public function test_index_returns_empty_collection_for_loan_with_no_collateral(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
    }

    public function test_index_returns_collateral_for_loan(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.collateral_type', CollateralType::External->value);
    }

    public function test_index_includes_both_registered_and_released_collaterals(): void
    {
        $loan = $this->makeActiveLoan();
        $this->makeExternalCollateral($loan);
        $this->makeExternalCollateral($loan, status: CollateralStatus::Released);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    // =========================================================================
    // index() — cross-tenant
    // =========================================================================

    public function test_index_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $foreignLoan->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
    }

    public function test_index_returns_404_for_nonexistent_loan(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => 999999]));

        $response->assertStatus(404);
    }

    // =========================================================================
    // store() — authentication and authorization
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $loan = $this->makeActiveLoan();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->externalPayload(),
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_manage_permission(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->viewerOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->externalPayload(),
        );

        $response->assertStatus(403);

        // No collateral was created.
        $this->assertSame(
            0,
            LoanCollateral::query()->where('loan_id', $loan->id)->count(),
        );
    }

    // =========================================================================
    // store() — happy path (external)
    // =========================================================================

    public function test_store_creates_external_collateral(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->externalPayload(),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.collateral_type', CollateralType::External->value);
        $response->assertJsonPath('data.status', CollateralStatus::Registered->value);
        $response->assertJsonPath('data.valuation_amount', '50000.0000');
        $response->assertJsonPath('data.valuation_currency', 'ETB');
    }

    public function test_store_persists_external_collateral(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->externalPayload(),
        )->assertStatus(201);

        $this->assertDatabaseHas('loan_collaterals', [
            'loan_id'            => $loan->id,
            'collateral_type'    => CollateralType::External->value,
            'status'             => CollateralStatus::Registered->value,
            'valuation_amount'   => '50000.0000',
            'valuation_currency' => 'ETB',
        ]);
    }

    // =========================================================================
    // store() — happy path (shareholding)
    // =========================================================================

    public function test_store_creates_shareholding_collateral(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->shareholdingPayload(),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.collateral_type', CollateralType::Shareholding->value);
        $response->assertJsonPath('data.pledged_shareholding_id', 42);
        $response->assertJsonPath('data.pledged_quantity', '100.0000');
    }

    // =========================================================================
    // store() — per-type validation
    // =========================================================================

    public function test_store_rejects_external_without_valuation_amount(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->externalPayload();
        unset($payload['valuation_amount']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['valuation_amount']]);
    }

    public function test_store_rejects_external_without_valuation_currency(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->externalPayload();
        unset($payload['valuation_currency']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['valuation_currency']]);
    }

    public function test_store_rejects_external_without_valuation_date(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->externalPayload();
        unset($payload['valuation_date']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['valuation_date']]);
    }

    public function test_store_rejects_external_with_pledge_linkage(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->externalPayload();
        $payload['pledged_shareholding_id'] = 42;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['pledged_shareholding_id']]);
    }

    public function test_store_rejects_shareholding_without_pledge_linkage(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->shareholdingPayload();
        unset($payload['pledged_shareholding_id']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['pledged_shareholding_id']]);
    }

    public function test_store_rejects_shareholding_with_partial_valuation_triple(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->shareholdingPayload();
        // Only set valuation_amount, leaving currency and date null.
        $payload['valuation_amount'] = '50000.0000';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['valuation_currency']]);
    }

    public function test_store_accepts_shareholding_with_complete_valuation_triple(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->shareholdingPayload();
        $payload['valuation_amount']   = '50000.0000';
        $payload['valuation_currency'] = 'ETB';
        $payload['valuation_date']     = '2026-01-15';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(201);
    }

    // =========================================================================
    // store() — shape validation
    // =========================================================================

    public function test_store_rejects_unknown_collateral_type(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), [
            'collateral_type' => 'guarantor',
            'description'     => 'Unsupported type.',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['collateral_type']]);
    }

    public function test_store_rejects_missing_description(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->externalPayload();
        unset($payload['description']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['description']]);
    }

    public function test_store_rejects_empty_body(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.collateral.store', ['loan' => $loan->id]), []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['collateral_type', 'description']]);
    }

    // =========================================================================
    // store() — cross-tenant
    // =========================================================================

    public function test_store_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $foreignLoan->id]),
            $this->externalPayload(),
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign loan has no collateral.
        $this->assertSame(
            0,
            LoanCollateral::query()->where('loan_id', $foreignLoan->id)->count(),
        );
    }

    // =========================================================================
    // release() — authentication and authorization
    // =========================================================================

    public function test_release_requires_authentication(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => 'Test release.'],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_release_requires_release_permission(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        // The managerOnly user holds `loan.collateral.manage` but NOT
        // `loan.collateral.release`.
        Sanctum::actingAs($this->managerOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => 'Attempted release.'],
        );

        $response->assertStatus(403);

        // The collateral is still registered.
        $collateral->refresh();
        $this->assertSame(CollateralStatus::Registered, $collateral->status);
    }

    // =========================================================================
    // release() — happy path
    // =========================================================================

    public function test_release_transitions_collateral_to_released(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => 'Loan fully repaid.'],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', CollateralStatus::Released->value);

        $collateral->refresh();
        $this->assertSame(CollateralStatus::Released, $collateral->status);
    }

    public function test_release_records_release_metadata(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => 'Loan fully repaid.'],
        )->assertStatus(200);

        $collateral->refresh();
        $this->assertNotNull($collateral->released_at);
        $this->assertSame('Loan fully repaid.', (string) $collateral->release_reason);
        $this->assertSame((int) $this->admin->id, (int) $collateral->released_by);
    }

    // =========================================================================
    // release() — validation
    // =========================================================================

    public function test_release_rejects_missing_reason(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            [],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_release_rejects_empty_reason(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => ''],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_release_rejects_whitespace_only_reason(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => "   \t\n  "],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    // =========================================================================
    // release() — state guard
    // =========================================================================

    public function test_release_rejects_already_released_collateral(): void
    {
        $loan = $this->makeActiveLoan();
        $collateral = $this->makeExternalCollateral($loan, status: CollateralStatus::Released);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $collateral->id,
            ]),
            ['reason' => 'Double release.'],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['status']]);
    }

    // =========================================================================
    // release() — cross-tenant and non-existent
    // =========================================================================

    public function test_release_returns_404_for_cross_tenant_loan(): void
    {
        $foreignLoan = $this->makeActiveLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );
        $foreignCollateral = $this->makeExternalCollateral($foreignLoan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $foreignLoan->id,
                'collateral' => $foreignCollateral->id,
            ]),
            ['reason' => 'Attempted cross-tenant release.'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign collateral is unchanged.
        $foreignCollateral->refresh();
        $this->assertSame(CollateralStatus::Registered, $foreignCollateral->status);
    }

    public function test_release_returns_404_for_cross_loan_collateral(): void
    {
        $loan = $this->makeActiveLoan();
        $otherLoan = $this->makeActiveLoan();

        $otherCollateral = $this->makeExternalCollateral($otherLoan);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => $otherCollateral->id,
            ]),
            ['reason' => 'Attempted cross-loan release.'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The other loan's collateral is unchanged.
        $otherCollateral->refresh();
        $this->assertSame(CollateralStatus::Registered, $otherCollateral->status);
    }

    public function test_release_returns_404_for_nonexistent_collateral(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.release', [
                'loan' => $loan->id,
                'collateral' => 999999,
            ]),
            ['reason' => 'Release.'],
        );

        $response->assertStatus(404);
    }

    // =========================================================================
    // T13 — forged X-Tenant-ID header
    // =========================================================================

    public function test_tenant_header_cannot_be_forged_for_collateral_index(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.collateral.index', ['loan' => $loan->id]));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // Semantic leak check.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('ሳሙኤል', $body);
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_store_response_has_expected_envelope_shape(): void
    {
        $loan = $this->makeActiveLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.loans.collateral.store', ['loan' => $loan->id]),
            $this->externalPayload(),
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'loan_id',
                'collateral_type',
                'description',
                'status',
                'registered_at',
                'is_external',
                'is_registered',
            ],
        ]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * The canonical valid payload for an external-typed collateral.
     *
     * @return array<string, mixed>
     */
    private function externalPayload(): array
    {
        return [
            'collateral_type'    => CollateralType::External->value,
            'description'        => 'Commercial property in Addis Ababa.',
            'external_reference' => 'TITLE-2026-001',
            'valuation_amount'   => '50000.0000',
            'valuation_currency' => 'ETB',
            'valuation_date'     => '2026-01-15',
        ];
    }

    /**
     * The canonical valid payload for a shareholding-typed collateral.
     *
     * @return array<string, mixed>
     */
    private function shareholdingPayload(): array
    {
        return [
            'collateral_type'        => CollateralType::Shareholding->value,
            'description'            => 'Pledge of 100 shares.',
            'pledged_shareholding_id' => 42,
            'pledged_quantity'       => '100.0000',
        ];
    }

    /**
     * Create a user attached to the primary tenant.
     */
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

    /**
     * Create an active loan with baseline history rows.
     */
    private function makeActiveLoan(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id                = $tenant->id;
        $loan->borrower_shareholder_id  = $borrower->id;
        $loan->loan_product_id          = $product->id;
        $loan->principal_amount         = '10000.0000';
        $loan->term_months              = 12;
        $loan->status                   = LoanStatus::Active;
        $loan->application_date         = now();
        $loan->decision_date            = now();
        $loan->disbursement_date        = now()->subMonths(3);
        $loan->currency                 = 'ETB';
        $loan->settled_amount           = '0.0000';
        $loan->outstanding_principal    = '10000.0000';
        $loan->outstanding_interest     = '0.0000';
        $loan->outstanding_penalty      = '0.0000';
        $loan->total_scheduled_interest = '0.0000';
        $loan->total_scheduled_repayment = '10000.0000';
        $loan->monthly_installment_amount = '0.0000';
        $loan->maturity_date            = now()->addMonths(9);
        $loan->frozen_policy_version_id = $product->product_rules_policy_version_id;
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
     * Create an external-typed collateral directly via the model.
     */
    private function makeExternalCollateral(
        Loan $loan,
        CollateralStatus $status = CollateralStatus::Registered,
    ): LoanCollateral {
        $attributes = [
            'loan_id'            => $loan->id,
            'collateral_type'    => CollateralType::External->value,
            'description'        => 'Fixture external collateral.',
            'external_reference' => 'FIXTURE-REF',
            'valuation_amount'   => '50000.0000',
            'valuation_currency' => 'ETB',
            'valuation_date'     => '2026-01-15',
            'status'             => $status->value,
            'registered_at'      => now(),
        ];

        if ($status === CollateralStatus::Released) {
            $attributes['released_at']    = now();
            $attributes['release_reason'] = 'Fixture release.';
            $attributes['released_by']    = $this->admin->id;
        }

        return LoanCollateral::create($attributes);
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

        foreach ($recipients as $user) {
            $user->permissions()->syncWithoutDetaching([
                $permission->id => ['assigned_at' => now()],
            ]);
        }

        return $permission;
    }
}