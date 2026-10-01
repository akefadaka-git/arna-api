<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * WalletTest
 *
 * Feature tests for the `Wallet` aggregate root's HTTP surface.
 *
 * =============================================================================
 * Endpoints covered
 * =============================================================================
 * The four routes of the WalletController (2B.26), mapped by the routes
 * file (2B.48) per Step 7 v1.1.0 §C.1:
 *
 *     GET   /wallets                    index()    list tenant's wallets
 *     POST  /wallets                    store()    open a wallet
 *     GET   /wallets/{wallet}           show()     single wallet
 *     POST  /wallets/{wallet}/adjust    adjust()   operator adjustment
 *
 * URLs are resolved via the `route('api.wallets.*')` helper; the test
 * never hard-codes a path.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The suite uses RefreshDatabase. Although the WalletService dispatches
 * its events via `DB::afterCommit()`, this suite does NOT assert on those
 * events — the unit tests (2B.57) already do, under DatabaseMigrations.
 * The feature suite's focus is the HTTP contract: request shape,
 * authorization layering, validation, and response envelope. None of
 * those concerns depend on the after-commit callback firing.
 *
 * The fixture setup that uses `Wallet::create([...])` directly (see the
 * `openWallet()` helper) bypasses the service entirely, so no
 * after-commit callbacks are registered on the fixture path either.
 *
 * =============================================================================
 * Authorization layering (Step 7 v1.1.0 §C.1)
 * =============================================================================
 * The four endpoints exercise the two-layer authorization model:
 *
 *   - Route middleware (`auth.permission:wallet.*`) runs before the
 *     controller. Four of the four endpoints carry it:
 *
 *         index   → wallet.view
 *         store   → wallet.open
 *         show    → (none)
 *         adjust  → wallet.adjust
 *
 *   - The policy layer (WalletPolicy, 2B.37) is invoked by the controller
 *     via Gate::authorize(...) for `viewAny`, `view`, and `viewStatement`
 *     abilities.
 *
 * The admin fixture is therefore seeded with four permissions:
 *
 *     wallet.view    — index (route) + show (policy)
 *     wallet.open    — store (route)
 *     wallet.adjust  — adjust (route) + adjust (policy: view on wallet)
 *
 * The `userWithoutPermission` fixture has zero permissions and is used
 * exclusively for the 403 tests.
 *
 * =============================================================================
 * Envelope
 * =============================================================================
 * Every successful response nests its payload under `data.*` (CR-003a,
 * §2.16). Assertions use the nested path:
 *
 *     index   → data.0.id, data.0.currency, ...
 *     store   → data.id, data.currency, ...
 *     show    → data.id, data.currency, ...
 *     adjust  → data.id, data.current_balance, ...
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createWalletPermission()` — not `createPermission()` — to avoid the
 * collision with the protected method declared on Tests\TestCase.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §19     — Q4, Q5 resolutions
 *   Step 5 (manifest) 2B.62     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §A.2          — Role binding matrix
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (routes #1–#4)
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.0
 */
final class WalletTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithoutPermission;
    private Shareholder $shareholder;
    private Shareholder $otherShareholder;

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

        $this->createWalletPermission('wallet.view',   'View wallets');
        $this->createWalletPermission('wallet.open',   'Open wallet');
        $this->createWalletPermission('wallet.adjust', 'Adjust wallet balance');

        $this->admin->load('permissions');

        $this->shareholder = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->otherShareholder = Shareholder::create([
            'tenant_id'        => $this->otherTenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
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
    // index() — GET /wallets
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_index_requires_wallet_view_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_index_returns_wallets_for_tenant(): void
    {
        $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'tenant_id',
                    'shareholder_id',
                    'currency',
                    'current_balance',
                    'is_active',
                    'is_closed',
                ],
            ],
        ]);
        $response->assertJsonPath('data.0.currency', 'ETB');
    }

    public function test_index_returns_empty_collection_when_no_wallets(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
    }

    // =========================================================================
    // store() — POST /wallets
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_wallet_open_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_store_creates_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.currency', 'ETB');
        $response->assertJsonPath('data.current_balance', '0.0000');
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.is_closed', false);
        $response->assertJsonPath('data.tenant_id', $this->tenant->id);
        $response->assertJsonPath('data.shareholder_id', $this->shareholder->id);

        $this->assertDatabaseHas('wallets', [
            'tenant_id'      => $this->tenant->id,
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);
    }

    public function test_store_uppercases_lowercase_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'etb',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.currency', 'ETB');
    }

    public function test_store_rejects_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->otherShareholder->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No wallet may have been created for the foreign shareholder.
        $this->assertDatabaseMissing('wallets', [
            'shareholder_id' => $this->otherShareholder->id,
        ]);
    }

    public function test_store_rejects_duplicate_wallet_for_pair(): void
    {
        $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['shareholder_id']]);
    }

    public function test_store_rejects_missing_shareholder_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'currency' => 'ETB',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['shareholder_id']]);
    }

    public function test_store_rejects_malformed_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ET',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['currency']]);
    }

    // =========================================================================
    // show() — GET /wallets/{wallet}
    // =========================================================================

    public function test_show_requires_authentication(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.show', ['wallet' => $wallet->id]));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_show_returns_wallet(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.show', ['wallet' => $wallet->id]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $wallet->id);
        $response->assertJsonPath('data.currency', 'ETB');
        $response->assertJsonPath('data.current_balance', '0.0000');
        $response->assertJsonPath('data.shareholder_id', $this->shareholder->id);
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.is_closed', false);
    }

    public function test_show_returns_404_for_missing_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.show', ['wallet' => 999999]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    public function test_show_returns_404_for_cross_tenant_wallet(): void
    {
        $foreign = $this->openWalletFor($this->otherShareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.show', ['wallet' => $foreign->id]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // adjust() — POST /wallets/{wallet}/adjust
    // =========================================================================

    public function test_adjust_requires_authentication(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $wallet->id]),
            ['signed_amount' => '+500.0000'],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_adjust_requires_wallet_adjust_permission(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $wallet->id]),
            ['signed_amount' => '+500.0000'],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');
    }

    public function test_adjust_updates_current_balance(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $wallet->id]),
            [
                'signed_amount' => '+500.0000',
                'description'   => 'Manual correction.',
            ],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $wallet->id);
        $response->assertJsonPath('data.current_balance', '500.0000');

        $wallet->refresh();
        $this->assertSame('500.0000', (string) $wallet->current_balance);
    }

    public function test_adjust_returns_404_for_cross_tenant_wallet(): void
    {
        $foreign = $this->openWalletFor($this->otherShareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $foreign->id]),
            ['signed_amount' => '+100.0000'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign wallet must be unchanged.
        $foreign->refresh();
        $this->assertSame('0.0000', (string) $foreign->current_balance);
    }

    public function test_adjust_rejects_zero_amount(): void
    {
        $wallet = $this->openWalletFor($this->shareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $wallet->id]),
            ['signed_amount' => '0.0000'],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['signed_amount']]);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Open a wallet for the given shareholder, bypassing HTTP and the
     * service layer.
     *
     * Used by tests that need an existing wallet as a fixture. The store
     * endpoint is exercised separately by the store tests, which start
     * from a shareholder without a wallet.
     */
    private function openWalletFor(Shareholder $shareholder): Wallet
    {
        return Wallet::create([
            'tenant_id'       => $shareholder->tenant_id,
            'shareholder_id'  => $shareholder->id,
            'currency'        => 'ETB',
            'current_balance' => '0.0000',
            'is_active'       => true,
            'opened_at'       => Carbon::now(),
        ]);
    }

    /**
     * Create a Wallet-domain permission and attach it to the admin user.
     *
     * Named `createWalletPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase. Mirrors
     * the convention established by 2A.46–2A.49.
     */
    private function createWalletPermission(string $slug, string $label): Permission
    {
        $permission = Permission::create([
            'slug'           => $slug,
            'name_am'        => null,
            'name_en'        => $label,
            'description_am' => null,
            'description_en' => $label,
            'category'       => 'wallet',
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