<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHoldingEntry;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * ShareHoldingTest
 *
 * Feature tests for the share-holding HTTP surface.
 *
 * =============================================================================
 * Endpoints covered
 * =============================================================================
 *   GET  /api/shareholders/holdings?shareholder_id=X   index()   list for a shareholder
 *   GET  /api/shareholders/holdings/{shareHolding}     show()    single holding
 *   POST /api/shareholders/holdings/entries             store()   record entry
 *
 * All routes are declared in
 * mases_core/src/Domains/Shareholder/Routes/api.php (2A.36). URLs are resolved
 * via the `route()` helper against the named routes so the suite is robust to
 * the prefix chosen in Config/shareholder.php.
 *
 * =============================================================================
 * RefreshDatabase + memory hygiene
 * =============================================================================
 * `RefreshDatabase` migrates once per file and wraps each test in a
 * transaction that rolls back. This is the pattern established for 2A.46 and
 * is required here to keep the 15-test suite within the Plesk CLI PHP memory
 * limit. `gc_collect_cycles()` at the start and end of each test forces
 * Laravel's cycle collector to run between tests.
 *
 * =============================================================================
 * Envelope and resource shape
 * =============================================================================
 * Responses follow the canonical MASES envelope:
 *
 *     { "success": true, "data": … }
 *
 * For `index()` the payload is a JSON array of holding resources.
 * For `show()` and `store()` the payload is a single holding resource,
 * which may include an embedded `entries` array and summaries of the
 * owning shareholder and share class.
 *
 * =============================================================================
 * Authorization layering
 * =============================================================================
 *   - `index` and `show` require authentication and tenant membership but no
 *     additional route-level permission. `show` additionally invokes
 *     `Gate::authorize('view', $holding)` which resolves through
 *     ShareHoldingPolicy (2A.30) to ShareholderPolicy (2A.29). An admin with
 *     the `shareholder.view` permission passes.
 *
 *   - `store` requires the `share_holding.entry` permission at the route layer
 *     and additionally invokes `Gate::authorize('view', $shareholder)` in the
 *     controller, which again resolves through ShareholderPolicy.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createShareholderPermission()` is used (not `createPermission()`) to avoid
 * the collision with the protected method declared on Tests\TestCase.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Tests\Feature
 */
final class ShareHoldingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $shareholder;
    private ShareClass $shareClass;

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
            'email'             => 'admin-' . uniqid() . '@example.test',
            'default_tenant_id' => $this->tenant->id,
        ]);
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email'             => 'noperm-' . uniqid() . '@example.test',
            'default_tenant_id' => $this->tenant->id,
        ]);
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        // Admin receives both the permission the policy consults
        // (`shareholder.view`) and the one the route middleware enforces on
        // the store endpoint (`share_holding.entry`).
        $this->createShareholderPermission('shareholder.view', 'View shareholders');
        $this->createShareholderPermission('share_holding.entry', 'Record share holding entries');

        $this->admin->load('permissions');

        $this->shareholder = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አቀፋ',
            'father_name_am'   => 'አየለ',
            'is_active'        => true,
        ]);

        $this->shareClass = ShareClass::create([
            'code'      => 'STANDARD',
            'name_am'   => 'መደበኛ',
            'name_en'   => 'Standard',
            'par_value' => '10000.0000',
            'currency'  => 'ETB',
            'is_active' => true,
        ]);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // index() — GET /api/shareholders/holdings?shareholder_id=X
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.index', [
            'shareholder_id' => $this->shareholder->id,
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_index_requires_shareholder_id_parameter(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.index'));

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['shareholder_id']]);
    }

    public function test_index_returns_holdings_for_shareholder(): void
    {
        ShareHolding::create([
            'shareholder_id'   => $this->shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '145.0000',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.index', [
            'shareholder_id' => $this->shareholder->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'shareholder_id', 'share_class_id', 'current_quantity'],
            ],
        ]);
        $response->assertJsonFragment([
            'shareholder_id'   => $this->shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '145.0000',
        ]);
    }

    public function test_index_returns_404_for_cross_tenant_shareholder(): void
    {
        $foreign = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.index', [
            'shareholder_id' => $foreign->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
            'Expected a 404 envelope code from the canonical renderer.',
        );
    }

    // =========================================================================
    // show() — GET /api/shareholders/holdings/{shareHolding}
    // =========================================================================

    public function test_show_requires_authentication(): void
    {
        $holding = $this->makeHolding('100.0000');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.show', [
            'shareHolding' => $holding->id,
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_show_returns_holding_with_entries(): void
    {
        $holding = $this->makeHolding('0.0000');

        // Insert one ledger entry directly so the response has entries to
        // return. The service would be required to write a ledger row in
        // production; here we bypass the service to control fixtures.
        ShareHoldingEntry::create([
            'share_holding_id' => $holding->id,
            'entry_type'       => 'subscription',
            'quantity'         => '100.0000',
            'balance_before'   => '0.0000',
            'balance_after'    => '100.0000',
            'occurred_at'      => now(),
            'created_at'       => now(),
        ]);
        $holding->update(['current_quantity' => '100.0000']);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.show', [
            'shareHolding' => $holding->id,
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $holding->id);
        $response->assertJsonPath('data.current_quantity', '100.0000');
        $response->assertJsonStructure([
            'data' => [
                'id',
                'shareholder_id',
                'share_class_id',
                'current_quantity',
                'entries' => [
                    '*' => ['id', 'entry_type', 'quantity', 'balance_before', 'balance_after'],
                ],
            ],
        ]);
    }

    public function test_show_returns_404_for_missing_holding(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.show', [
            'shareHolding' => 999999,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_show_returns_404_for_cross_tenant_holding(): void
    {
        $foreignShareholder = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);

        $foreignHolding = ShareHolding::create([
            'shareholder_id'   => $foreignShareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => '50.0000',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.shareholders.holdings.show', [
            'shareHolding' => $foreignHolding->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // store() — POST /api/shareholders/holdings/entries
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $holding = $this->makeHolding('0.0000');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'direction'        => 'in',
            'quantity'         => 100,
            'entry_type'       => 'subscription',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_entry_permission(): void
    {
        $holding = $this->makeHolding('0.0000');

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'direction'        => 'in',
            'quantity'         => 100,
            'entry_type'       => 'subscription',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_store_creates_entry_in_direct_mode(): void
    {
        $holding = $this->makeHolding('0.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'direction'        => 'in',
            'quantity'         => 100,
            'entry_type'       => 'subscription',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $holding->id);
        $response->assertJsonPath('data.current_quantity', '100.0000');

        // The newly created entry is attached to the response as a
        // single-element `entries` list.
        $response->assertJsonStructure([
            'data' => [
                'entries' => [
                    '*' => [
                        'id',
                        'entry_type',
                        'direction',
                        'quantity',
                        'balance_before',
                        'balance_after',
                    ],
                ],
            ],
        ]);
        $response->assertJsonPath('data.entries.0.entry_type', 'subscription');
        $response->assertJsonPath('data.entries.0.direction', 'in');
        $response->assertJsonPath('data.entries.0.quantity', '100.0000');
        $response->assertJsonPath('data.entries.0.balance_before', '0.0000');
        $response->assertJsonPath('data.entries.0.balance_after', '100.0000');

        // Verify persistence at the projection level.
        $holding->refresh();
        $this->assertSame('100.0000', (string) $holding->current_quantity);

        // Exactly one ledger entry was created.
        $this->assertSame(
            1,
            ShareHoldingEntry::where('share_holding_id', $holding->id)->count(),
        );
    }

    public function test_store_creates_entry_in_pair_mode_and_holding(): void
    {
        // No holding exists for this pair yet.
        $this->assertSame(0, ShareHolding::where('shareholder_id', $this->shareholder->id)->count());

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'shareholder_id' => $this->shareholder->id,
            'share_class_id' => $this->shareClass->id,
            'direction'      => 'in',
            'quantity'       => 50,
            'entry_type'     => 'subscription',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.current_quantity', '50.0000');
        $response->assertJsonPath('data.shareholder_id', $this->shareholder->id);
        $response->assertJsonPath('data.share_class_id', $this->shareClass->id);

        // A holding row was created by the service on first entry.
        $this->assertSame(
            1,
            ShareHolding::where('shareholder_id', $this->shareholder->id)
                ->where('share_class_id', $this->shareClass->id)
                ->count(),
        );
    }

    public function test_store_rejects_zero_quantity(): void
    {
        $holding = $this->makeHolding('0.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'direction'        => 'in',
            'quantity'         => 0,
            'entry_type'       => 'subscription',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['quantity']]);
    }

    public function test_store_rejects_reversal_entry_type(): void
    {
        $holding = $this->makeHolding('100.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'direction'        => 'out',
            'quantity'         => 50,
            'entry_type'       => 'reversal',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['entry_type']]);
    }

    public function test_store_rejects_missing_direction(): void
    {
        $holding = $this->makeHolding('0.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'share_holding_id' => $holding->id,
            'quantity'         => 100,
            'entry_type'       => 'subscription',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['direction']]);
    }

    public function test_store_returns_404_for_cross_tenant_shareholder(): void
    {
        $foreign = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሌላ',
            'is_active'        => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.shareholders.holdings.entries.store'), [
            'shareholder_id' => $foreign->id,
            'share_class_id' => $this->shareClass->id,
            'direction'      => 'in',
            'quantity'       => 50,
            'entry_type'     => 'subscription',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a ShareHolding with the given starting quantity.
     */
    private function makeHolding(string $quantity): ShareHolding
    {
        return ShareHolding::create([
            'shareholder_id'   => $this->shareholder->id,
            'share_class_id'   => $this->shareClass->id,
            'current_quantity' => $quantity,
        ]);
    }

    /**
     * Create a Shareholder-domain permission and attach it to the admin user.
     *
     * Named `createShareholderPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase.
     */
    private function createShareholderPermission(string $slug, string $label): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'shareholder',
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