<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Loan\Models\LoanProduct;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * LoanProductTest
 *
 * Feature tests for the loan product catalogue HTTP surface:
 *
 *     GET /loans/products    index    — list the tenant's product catalogue
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
 * Revision history
 * =============================================================================
 * 1.0.0 — initial delivery.
 *
 * 1.0.1 — CR-RESIDUAL-02. The test
 *         `test_products_route_resolves_before_show_route()` previously
 *         constructed its request against the hardcoded URL
 *         `/loans/products`, which omits the group prefix the provider
 *         (A.85) applies from `config('loan.routes.prefix')`. After
 *         CR-L1 corrected that config from `loans` to `api/loans`, the
 *         route's effective URL became `/api/loans/products`, and the
 *         literal URL matched no registered route. The failure surfaced
 *         as `NotFoundHttpException: The route loans/products could not
 *         be found` from `Route::getRoutes()->match()`, not as a
 *         route-shadowing assertion failure.
 *
 *         The test now derives its URL from the SAME config source the
 *         provider consumes, so the two cannot diverge on any future
 *         prefix change. No production file was touched; the route
 *         declaration and its ordering were already correct.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The product catalogue endpoint is READ-ONLY. It performs no writes and
 * dispatches no events. `RefreshDatabase` is the correct default per
 * §2.13.
 *
 * =============================================================================
 * Authorization
 * =============================================================================
 * The endpoint requires the `loan.view` permission. The controller (A.78)
 * dispatches `Gate::authorize('viewAny', LoanProduct::class)`, which
 * resolves through `LoanProductPolicy::viewAny()` (A.53).
 *
 * Note the deliberate distinction from the loan-view permissions:
 *
 *   - `loan.view` grants catalogue access (the product catalogue is a
 *     read of the tenant's catalogue; a user who can see loans needs
 *     to see the products those loans are originated under).
 *
 *   - `loan.view_own` does NOT grant catalogue access. A borrower who
 *     can see their own loans does not see the tenant's full product
 *     catalogue; that is an administrative read.
 *
 * Both cases are tested.
 *
 * =============================================================================
 * Route ordering — the /loans/products rule
 * =============================================================================
 * The route `/loans/products` MUST resolve to `LoanProductController::index`
 * and NOT to `LoanController::show` with `{loan} = 'products'`. The
 * frozen ordering rule (A.84's class docblock §"The route-ORDERING rule")
 * places the static `products` route BEFORE the dynamic `{loan}` route.
 *
 * A regression test verifies the resolution.
 *
 * =============================================================================
 * Cross-tenant vectors
 * =============================================================================
 *   - The catalogue is scoped to the authenticated tenant.
 *   - forged `X-Tenant-ID` header → 403 (T13).
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
 * @version 1.0.1
 */
final class LoanProductTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private User $borrowerOnly;

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
        $this->userWithoutPermission = $this->makeUserInTenant('noperm');
        $this->borrowerOnly = $this->makeUserInTenant('borroweronly');

        // ---------------------------------------------------------------------
        // Permissions.
        //
        //   admin         → loan.view (broad catalogue access)
        //   borrowerOnly  → loan.view_own (own-loans only; NO catalogue access)
        // ---------------------------------------------------------------------
        $this->createLoanPermission('loan.view',     'View loans',     [$this->admin]);
        $this->createLoanPermission('loan.view_own', 'View own loans', [$this->borrowerOnly]);

        foreach ([$this->admin, $this->userWithoutPermission, $this->borrowerOnly] as $user) {
            $user->load('permissions');
        }

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

    public function test_index_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_index_returns_403_when_user_lacks_view_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    public function test_index_denies_loan_view_own_without_loan_view(): void
    {
        // A borrower holding only `loan.view_own` does NOT see the
        // tenant's product catalogue. The catalogue is an administrative
        // read gated by `loan.view`.
        Sanctum::actingAs($this->borrowerOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_index_returns_empty_collection_for_tenant_with_no_products(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
    }

    public function test_index_returns_products_for_tenant(): void
    {
        $this->makeProduct($this->tenant, 'PROD-A');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'PROD-A');
    }

    public function test_index_returns_all_products_by_default(): void
    {
        $this->makeProduct($this->tenant, 'PROD-ACTIVE', isActive: true);
        $this->makeProduct($this->tenant, 'PROD-INACTIVE', isActive: false);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_orders_products_by_code(): void
    {
        $this->makeProduct($this->tenant, 'PROD-C');
        $this->makeProduct($this->tenant, 'PROD-A');
        $this->makeProduct($this->tenant, 'PROD-B');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.code', 'PROD-A');
        $response->assertJsonPath('data.1.code', 'PROD-B');
        $response->assertJsonPath('data.2.code', 'PROD-C');
    }

    public function test_index_returns_product_response_shape(): void
    {
        $this->makeProduct($this->tenant, 'PROD-A');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'tenant_id',
                    'code',
                    'name_am',
                    'name_en',
                    'display_name',
                    'currency',
                    'min_principal',
                    'max_principal',
                    'min_term_months',
                    'max_term_months',
                    'default_installment_frequency',
                    'product_rules_policy_version_id',
                    'has_policy_version',
                    'is_active',
                    'created_at',
                    'updated_at',
                ],
            ],
        ]);
    }

    public function test_index_returns_both_bilingual_names(): void
    {
        // The resource emits BOTH `name_am` and `name_en` regardless of
        // the active locale; `display_name` applies the Amharic-first
        // resolution rule.
        $this->makeProduct($this->tenant, 'PROD-A');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.0.name_am'));
        $this->assertNotNull($response->json('data.0.name_en'));
        $this->assertNotNull($response->json('data.0.display_name'));
    }

    // =========================================================================
    // ?active_only= filter
    // =========================================================================

    public function test_index_filters_active_only_when_requested(): void
    {
        $this->makeProduct($this->tenant, 'PROD-ACTIVE', isActive: true);
        $this->makeProduct($this->tenant, 'PROD-INACTIVE', isActive: false);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index', ['active_only' => 1]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'PROD-ACTIVE');
    }

    public function test_index_active_only_accepts_true_literal(): void
    {
        $this->makeProduct($this->tenant, 'PROD-ACTIVE', isActive: true);
        $this->makeProduct($this->tenant, 'PROD-INACTIVE', isActive: false);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index', ['active_only' => 'true']));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_index_active_only_zero_returns_full_catalogue(): void
    {
        $this->makeProduct($this->tenant, 'PROD-ACTIVE', isActive: true);
        $this->makeProduct($this->tenant, 'PROD-INACTIVE', isActive: false);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index', ['active_only' => 0]));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
    }

    // =========================================================================
    // Route ordering
    // =========================================================================

    public function test_products_route_resolves_before_show_route(): void
    {
        // The products route's effective URL MUST resolve to
        // `LoanProductController::index` and NOT to
        // `LoanController::show` with `{loan} = 'products'`.
        //
        // The URL is derived from the SAME config source the provider
        // (A.85) consumes when it applies the group prefix, rather than
        // hardcoded. A literal path such as `/loans/products` would
        // omit the config-applied prefix and match no registered route,
        // which is the defect that CR-RESIDUAL-02 corrected.
        $prefix = trim((string) config('loan.routes.prefix'), '/');
        $url    = '/' . ($prefix === '' ? '' : $prefix . '/') . 'products';

        $route = Route::getRoutes()->match(
            Request::create($url, 'GET'),
        );

        $this->assertSame(
            'api.loans.products.index',
            $route->getName(),
        );
    }

    // =========================================================================
    // No `meta` key (non-paginated response)
    // =========================================================================

    public function test_index_does_not_expose_pagination_metadata(): void
    {
        $this->makeProduct($this->tenant, 'PROD-A');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonMissingPath('meta');
    }

    // =========================================================================
    // Cross-tenant isolation
    // =========================================================================

    public function test_index_returns_only_tenant_products(): void
    {
        $this->makeProduct($this->tenant, 'PROD-A');
        $this->makeProduct($this->otherTenant, 'PROD-B');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.code', 'PROD-A');

        // Semantic leak check: the foreign tenant's product code must
        // not appear in the response.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'PROD-B',
            $body,
            'tenantB product code must not appear in tenantA catalogue response.',
        );
    }

    // =========================================================================
    // T13 — forged X-Tenant-ID header
    // =========================================================================

    public function test_tenant_header_cannot_be_forged_to_access_other_tenant_catalogue(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->otherTenant->id,
        ])->getJson(route('api.loans.products.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a data
        // payload.
        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );
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
            'description_am'                => 'የሙከራ ምርት መግለጫ።',
            'description_en'                => 'Test product description.',
            'purpose'                       => 'Working capital for members.',
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
     * Create a Loan permission and attach it to the given users.
     *
     * @param array<int, User> $recipients
     */
    private function createLoanPermission(string $slug, string $label, array $recipients = []): Permission
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

        foreach ($recipients as $user) {
            $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        return $permission;
    }
}