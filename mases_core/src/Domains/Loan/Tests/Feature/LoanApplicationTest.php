<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Enums\LoanStatus;
use MaaleArna\MASES\Core\Domains\Loan\Models\Loan;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanStatusHistory;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanApplicationTest
 *
 * Feature tests for the loan-application HTTP surface:
 *
 *     GET  /loans     index   — list loans in the authenticated tenant
 *     POST /loans     store   — create a loan in the draft state
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention (see 2A.48, 2B.57–2B.61 and the
 * Shareholder and Wallet domain test suites), this file lives INSIDE the
 * Loan module at `mases_core/src/Domains/Loan/Tests/Feature/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Tests\Feature`.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The loan-application flow does NOT assert on after-commit events. The
 * `createDraft()` service method (A.28) dispatches no event; only the
 * `submit()` method dispatches `LoanApplicationSubmitted` (A.38), and
 * submission has no Phase 2C HTTP endpoint.
 *
 * `RefreshDatabase` is therefore the correct default per §2.13 and the
 * pattern followed by the Shareholder and Wallet feature suites.
 *
 * =============================================================================
 * Authorization layering
 * =============================================================================
 *   - `index` requires the `loan.view` permission. The controller
 *     dispatches `Gate::authorize('viewAny', Loan::class)`, which
 *     resolves through `LoanPolicy::viewAny()` (A.52).
 *
 *   - `store` requires the `loan.apply` permission. The permission is
 *     NOT routed through a policy ability (the loan does not exist yet);
 *     the controller dispatches `Gate::authorize('loan.apply')` as a raw
 *     ability string. See A.77's class docblock §"The store endpoint's
 *     authorization" for the rationale.
 *
 * The tests exercise both authorization layers with distinct user
 * fixtures.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - `store` with a cross-tenant `borrower_shareholder_id` → 404
 *   - `store` with a cross-tenant `loan_product_id` → 404
 *   - `store` with a forged `X-Tenant-ID` header → 403 (T13)
 *
 * A cross-tenant reference must produce the SAME 404 as a non-existent
 * reference: the two are indistinguishable from the caller's
 * perspective, per the tenant-isolation invariant (handoff §3).
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createLoanPermission()` — not `createPermission()` — to avoid the
 * method-name collision with the protected `createPermission()` helper
 * on `Tests\TestCase`. Same convention as 2A.46, 2A.47, 2A.48, and
 * 2B.62–2B.66.
 *
 * =============================================================================
 * Memory hygiene
 * =============================================================================
 * `gc_collect_cycles()` runs at both ends of the test lifecycle. Same
 * discipline as every other suite in the platform.
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Tests\Feature
 * @version 1.0.0
 */
final class LoanApplicationTest extends TestCase
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
        // Two users. The admin holds the Loan permissions; the second user
        // holds nothing.
        // ---------------------------------------------------------------------
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
        // Permissions the Loan endpoints require.
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',  'View loans');
        $this->createLoanPermission('loan.apply', 'Apply for a loan');

        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders and products — one of each in the primary tenant,
        // one of each in the foreign tenant.
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

        $this->product = $this->makeProduct($this->tenant, 'PROD-A');
        $this->otherProduct = $this->makeProduct($this->otherTenant, 'PROD-B');

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // index() — authentication
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // index() — authorization
    // =========================================================================

    public function test_index_returns_403_when_user_lacks_view_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // index() — happy path
    // =========================================================================

    public function test_index_returns_empty_collection_for_tenant_with_no_loans(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_index_returns_loans_for_tenant(): void
    {
        $loan = $this->makeDraftLoan();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('data.0.id', $loan->id);
        $response->assertJsonPath('data.0.status', LoanStatus::Draft->value);
    }

    public function test_index_returns_pagination_metadata(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'meta' => [
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to',
                ],
            ],
        ]);
    }

    // =========================================================================
    // index() — cross-tenant isolation
    // =========================================================================

    public function test_index_returns_only_tenant_loans(): void
    {
        $tenantLoan = $this->makeDraftLoan();
        $foreignLoan = $this->makeDraftLoan(
            tenant: $this->otherTenant,
            borrower: $this->otherBorrower,
            product: $this->otherProduct,
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('meta.pagination.total', 1);

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains(
            (int) $tenantLoan->id,
            array_map('intval', $ids),
            'tenantA loan must appear in the index for tenantA.',
        );
        $this->assertNotContains(
            (int) $foreignLoan->id,
            array_map('intval', $ids),
            'tenantB loan must NOT appear in the index for tenantA.',
        );

        // Semantic leak check: tenantB's borrower's Amharic given name
        // must not appear anywhere in the raw response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not appear in tenantA index response.',
        );
    }

    // =========================================================================
    // store() — authentication
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload());

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // store() — authorization
    // =========================================================================

    public function test_store_requires_apply_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload());

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        // No loan may have been created.
        $this->assertSame(
            0,
            Loan::query()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    // =========================================================================
    // store() — happy path
    // =========================================================================

    public function test_store_creates_loan_in_draft_state(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', LoanStatus::Draft->value);
        $response->assertJsonPath('data.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.borrower_shareholder_id', $this->borrower->id);
        $response->assertJsonPath('data.loan_product_id', $this->product->id);
        $response->assertJsonPath('data.principal_amount', '5000.0000');
        $response->assertJsonPath('data.term_months', 12);
    }

    public function test_store_persists_loan_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload())
          ->assertStatus(201);

        $this->assertDatabaseHas('loans', [
            'tenant_id'               => $this->tenant->id,
            'borrower_shareholder_id' => $this->borrower->id,
            'loan_product_id'         => $this->product->id,
            'status'                  => LoanStatus::Draft->value,
            'principal_amount'        => '5000.0000',
            'term_months'             => 12,
        ]);
    }

    public function test_store_writes_initial_history_row(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload());

        $response->assertStatus(201);

        $loanId = (int) $response->json('data.id');

        $history = LoanStatusHistory::query()
            ->where('loan_id', $loanId)
            ->get();

        $this->assertCount(1, $history);

        $row = $history->first();
        $this->assertNull($row->from_status);
        $this->assertSame(LoanStatus::Draft, $row->to_status);
        $this->assertSame((int) $this->admin->id, $row->actor_user_id);
    }

    public function test_store_records_optional_purpose_and_external_reference(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['purpose']            = 'Working capital.';
        $payload['external_reference'] = 'REF-2026-001';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.purpose', 'Working capital.');
        $response->assertJsonPath('data.external_reference', 'REF-2026-001');
    }

    public function test_store_accepts_omitted_optional_fields(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        unset($payload['purpose'], $payload['external_reference']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.purpose', null);
        $response->assertJsonPath('data.external_reference', null);
    }

    // =========================================================================
    // store() — shape validation
    // =========================================================================

    public function test_store_rejects_empty_body(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure([
            'errors' => [
                'borrower_shareholder_id',
                'loan_product_id',
                'principal_amount',
                'term_months',
            ],
        ]);
    }

    public function test_store_rejects_missing_borrower_shareholder_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        unset($payload['borrower_shareholder_id']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['borrower_shareholder_id']]);
    }

    public function test_store_rejects_missing_loan_product_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        unset($payload['loan_product_id']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['loan_product_id']]);
    }

    public function test_store_rejects_missing_principal_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        unset($payload['principal_amount']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['principal_amount']]);
    }

    public function test_store_rejects_missing_term_months(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        unset($payload['term_months']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['term_months']]);
    }

    public function test_store_rejects_malformed_principal_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['principal_amount'] = 'not-a-number';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['principal_amount']]);
    }

    public function test_store_rejects_principal_amount_with_too_many_decimals(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['principal_amount'] = '5000.12345';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['principal_amount']]);
    }

    public function test_store_rejects_zero_term_months(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['term_months'] = 0;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['term_months']]);
    }

    // =========================================================================
    // store() — domain validation (delegated to the service)
    // =========================================================================

    public function test_store_rejects_principal_below_product_minimum(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['principal_amount'] = '500.0000';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['principal_amount']]);
    }

    public function test_store_rejects_principal_above_product_maximum(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['principal_amount'] = '500000.0000';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['principal_amount']]);
    }

    public function test_store_rejects_term_below_product_minimum(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['term_months'] = 2;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['term_months']]);
    }

    public function test_store_rejects_term_above_product_maximum(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['term_months'] = 120;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['term_months']]);
    }

    // =========================================================================
    // store() — cross-tenant and non-existent references
    // =========================================================================

    public function test_store_rejects_nonexistent_borrower(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['borrower_shareholder_id'] = 999999;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_store_rejects_cross_tenant_borrower(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['borrower_shareholder_id'] = $this->otherBorrower->id;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No loan may have been created.
        $this->assertSame(
            0,
            Loan::query()->where('tenant_id', $this->tenant->id)->count(),
        );
    }

    public function test_store_rejects_nonexistent_product(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['loan_product_id'] = 999999;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_store_rejects_cross_tenant_product(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['loan_product_id'] = $this->otherProduct->id;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_store_rejects_inactive_product(): void
    {
        $inactiveProduct = $this->makeProduct($this->tenant, 'PROD-INACTIVE', isActive: false);

        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['loan_product_id'] = $inactiveProduct->id;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['loan_product_id']]);
    }

    // =========================================================================
    // store() — semantic leak check
    // =========================================================================

    public function test_store_does_not_leak_foreign_borrower_name_on_error(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $payload = $this->validPayload();
        $payload['borrower_shareholder_id'] = $this->otherBorrower->id;

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $payload);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected response.',
        );
    }

    // =========================================================================
    // T13 — forged X-Tenant-ID header
    // =========================================================================

    public function test_tenant_header_cannot_be_forged_to_access_other_tenant(): void
    {
        // The admin is a member of tenantA only. Sending tenantB's id
        // in the X-Tenant-ID header attempts to elevate context. The
        // TenantMembershipMiddleware resolves the header via
        // `mases_tenant_id()`, checks membership, and rejects with 403
        // TENANT_ACCESS_DENIED before any controller or permission check
        // runs.
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a data
        // payload.
        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        // Semantic leak check: tenantB's borrower name must not appear
        // in the response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB borrower name must not leak into a rejected request response.',
        );
    }

    // =========================================================================
    // Response envelope
    // =========================================================================

    public function test_store_response_has_expected_envelope_shape(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.loans.store'), $this->validPayload());

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'tenant_id',
                'borrower_shareholder_id',
                'loan_product_id',
                'status',
                'principal_amount',
                'term_months',
                'currency',
                'settled_amount',
                'outstanding_principal',
                'outstanding_interest',
                'outstanding_penalty',
                'outstanding_total',
                'created_at',
                'updated_at',
            ],
        ]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * The canonical valid payload for `POST /loans`.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'borrower_shareholder_id' => $this->borrower->id,
            'loan_product_id'         => $this->product->id,
            'principal_amount'        => '5000.0000',
            'term_months'             => 12,
        ];
    }

    /**
     * Create a LoanProduct under the given tenant.
     */
    private function makeProduct(
        Tenant $tenant,
        string $code,
        bool $isActive = true,
    ): LoanProduct {
        return LoanProduct::create([
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
            'is_active'                     => $isActive,
        ]);
    }

    /**
     * Create a persisted draft loan directly via the model, bypassing
     * the service. Used by the index tests to seed the read surface.
     */
    private function makeDraftLoan(
        ?Tenant $tenant = null,
        ?Shareholder $borrower = null,
        ?LoanProduct $product = null,
    ): Loan {
        $tenant   ??= $this->tenant;
        $borrower ??= $this->borrower;
        $product  ??= $this->product;

        $loan = new Loan();
        $loan->tenant_id               = $tenant->id;
        $loan->borrower_shareholder_id = $borrower->id;
        $loan->loan_product_id         = $product->id;
        $loan->principal_amount        = '5000.0000';
        $loan->term_months             = 12;
        $loan->status                  = LoanStatus::Draft;
        $loan->application_date        = now();
        $loan->settled_amount          = '0.0000';
        $loan->outstanding_penalty     = '0.0000';
        $loan->save();

        LoanStatusHistory::create([
            'loan_id'           => $loan->id,
            'from_status'       => null,
            'to_status'         => LoanStatus::Draft->value,
            'transition_reason' => null,
            'actor_user_id'     => null,
            'occurred_at'       => now(),
            'created_at'        => now(),
        ]);

        return $loan;
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