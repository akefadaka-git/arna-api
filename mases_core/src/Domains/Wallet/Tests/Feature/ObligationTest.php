<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * ObligationTest
 *
 * Feature tests for the `Obligation` scoped business aggregate's HTTP
 * surface.
 *
 * =============================================================================
 * Endpoint covered
 * =============================================================================
 * The single route of the ObligationController (2B.27), mapped by the
 * routes file (2B.48) per Step 7 v1.1.0 §C.1 route #5:
 *
 *     POST /wallets/{wallet}/obligations  → store()  record an obligation
 *
 * URLs are resolved via the `route('api.wallets.obligations.store')`
 * helper; the test never hard-codes a path.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The suite uses RefreshDatabase. Although ObligationService dispatches
 * its `ObligationRecorded` event via `DB::afterCommit()`, this suite does
 * NOT assert on that event — the unit test (2B.58) already does. The
 * feature suite's focus is the HTTP contract: request shape, authorization
 * layering, validation, response envelope, and the two-row side effect
 * (obligation + ledger entry) at the HTTP boundary.
 *
 * Fixture setup uses `Wallet::create([...])` directly, so no after-commit
 * callbacks are registered on the fixture path.
 *
 * =============================================================================
 * Authorization layering — the two-layer model in action
 * =============================================================================
 * The endpoint exercises both authorization layers from Step 7 v1.1.0
 * §C.1 route #5:
 *
 *   1. Route middleware `auth.permission:wallet.obligation.create` runs
 *      before the controller. A caller without the permission receives
 *      403 AUTH_PERMISSION_DENIED before any domain code runs.
 *
 *   2. The controller calls `Gate::authorize('view', $wallet)`. A caller
 *      who holds `wallet.obligation.create` but NOT `wallet.view` (and
 *      does not own the wallet) receives 403 from the policy.
 *
 * Three fixtures test the three states:
 *
 *   admin                  → holds `wallet.view` + `wallet.obligation.create`
 *                            → happy path, 201
 *
 *   userWithCreateOnly     → holds `wallet.obligation.create` but NOT
 *                            `wallet.view`
 *                            → passes middleware, fails policy, 403
 *
 *   userWithoutPermission  → holds neither
 *                            → fails middleware, 403
 *
 * =============================================================================
 * The two-row side effect at the HTTP boundary
 * =============================================================================
 * Every successful `store()` produces TWO rows inside the service's
 * transaction:
 *
 *   1. An `Obligation` row (2B.50).
 *   2. A `WalletEntry` row (2B.54) of type `obligation_recorded`, with a
 *      NEGATIVE signed quantity (debit of the member's position).
 *
 * The tests assert on both. The obligation row is asserted via
 * `assertDatabaseHas('wallet_obligations', ...)`; the ledger entry is
 * asserted via `assertDatabaseHas('wallet_entries', ...)`. The
 * wallet's updated `current_balance` is asserted separately.
 *
 * =============================================================================
 * Envelope
 * =============================================================================
 * Success responses nest under `data.*` (CR-003a, §2.16). The resource
 * (2B.32 v1.0.1) emits the obligation payload with the fields exercised
 * by the `test_store_response_has_expected_envelope_shape` test.
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
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.63     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #5)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T5)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.0
 */
final class ObligationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithCreateOnly;
    private User $userWithoutPermission;
    private Shareholder $shareholder;
    private Shareholder $otherShareholder;
    private Wallet $wallet;

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
        // Three users, three authorization states.
        // ---------------------------------------------------------------------
        $this->admin = User::factory()->create([
            'email' => 'admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->admin->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithCreateOnly = User::factory()->create([
            'email' => 'createonly-' . uniqid() . '@example.test',
        ]);
        $this->userWithCreateOnly->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithCreateOnly->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email' => 'noperm-' . uniqid() . '@example.test',
        ]);
        $this->userWithoutPermission->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Permissions.
        //
        // `wallet.view` is attached to the admin only (it is required by
        // the WalletPolicy::view() gate that the controller calls after
        // the route middleware passes).
        //
        // `wallet.obligation.create` is attached to BOTH the admin and
        // `userWithCreateOnly` — the latter exercises the case where the
        // route middleware passes but the policy gate fails.
        // ---------------------------------------------------------------------
        $this->createWalletPermission('wallet.view', 'View wallets');
        $createPermission = $this->createWalletPermission(
            'wallet.obligation.create',
            'Record obligation',
        );

        $this->userWithCreateOnly->permissions()->attach(
            $createPermission->id,
            ['assigned_at' => now()],
        );

        $this->admin->load('permissions');
        $this->userWithCreateOnly->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders and the primary wallet.
        // ---------------------------------------------------------------------
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

        $this->wallet = $this->openWalletFor($this->shareholder);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // Authentication and authorization
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_obligation_create_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');

        // No obligation may have been created.
        $this->assertSame(0, Obligation::query()->where('wallet_id', $this->wallet->id)->count());
    }

    public function test_store_requires_wallet_view_policy_gate(): void
    {
        // The user holds `wallet.obligation.create` (route middleware
        // passes) but not `wallet.view` and does not own the wallet
        // (policy gate fails). This exercises the second layer of the
        // two-layer authorization model.
        Sanctum::actingAs($this->userWithCreateOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        // No obligation may have been created.
        $this->assertSame(0, Obligation::query()->where('wallet_id', $this->wallet->id)->count());
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_store_creates_obligation(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.wallet_id', $this->wallet->id);
        $response->assertJsonPath('data.component', 'principal');
        $response->assertJsonPath('data.currency', 'ETB');
        $response->assertJsonPath('data.original_amount', '500.0000');
        $response->assertJsonPath('data.outstanding_amount', '500.0000');
        $response->assertJsonPath('data.is_open', true);
        $response->assertJsonPath('data.is_partially_settled', false);
        $response->assertJsonPath('data.is_settled', false);

        $this->assertDatabaseHas('wallet_obligations', [
            'wallet_id'         => $this->wallet->id,
            'component'         => 'principal',
            'currency'          => 'ETB',
            'original_amount'   => '500.0000',
            'outstanding_amount' => '500.0000',
        ]);
    }

    public function test_store_creates_ledger_entry(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        )->assertStatus(201);

        $obligation = Obligation::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($obligation);

        $this->assertDatabaseHas('wallet_entries', [
            'wallet_id'      => $this->wallet->id,
            'entry_type'     => 'obligation_recorded',
            'component'      => 'principal',
            'signed_quantity' => '-500.0000',
            'balance_before' => '0.0000',
            'balance_after'  => '-500.0000',
            'reference_type' => 'Obligation',
            'reference_id'   => (string) $obligation->id,
        ]);
    }

    public function test_store_updates_wallet_balance_negatively(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        )->assertStatus(201);

        $this->wallet->refresh();
        $this->assertSame('-500.0000', (string) $this->wallet->current_balance);
    }

    public function test_store_accepts_all_six_components(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $components = ['principal', 'interest', 'fee', 'penalty', 'tax', 'other'];

        foreach ($components as $component) {
            $response = $this->withHeaders([
                'X-Tenant-ID' => (string) $this->tenant->id,
            ])->postJson(
                route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
                [
                    'component' => $component,
                    'amount'    => '10.0000',
                ],
            );

            $response->assertStatus(201);
            $response->assertJsonPath('data.component', $component);
        }

        $this->assertSame(
            6,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    public function test_store_accepts_explicit_occurred_at(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component'   => 'principal',
                'amount'      => '500.0000',
                'occurred_at' => '2026-01-15T12:00:00+03:00',
            ],
        );

        $response->assertStatus(201);
        $this->assertStringStartsWith(
            '2026-01-15',
            (string) $response->json('data.occurred_at'),
        );
    }

    public function test_store_accepts_explicit_due_at(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
                'due_at'    => '2026-12-31T23:59:59+03:00',
            ],
        );

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.due_at'));
        $this->assertStringStartsWith('2026-12-31', (string) $response->json('data.due_at'));
    }

    // =========================================================================
    // Reference pair
    // =========================================================================

    public function test_store_accepts_complete_reference_pair(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component'      => 'principal',
                'amount'         => '500.0000',
                'reference_type' => 'loan',
                'reference_id'   => '42',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.reference_type', 'loan');
        $response->assertJsonPath('data.reference_id', '42');
    }

    public function test_store_rejects_reference_type_without_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component'      => 'principal',
                'amount'         => '500.0000',
                'reference_type' => 'loan',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['reference_type']]);
    }

    public function test_store_rejects_reference_id_without_type(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component'    => 'principal',
                'amount'       => '500.0000',
                'reference_id' => '42',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['reference_type']]);
    }

    // =========================================================================
    // Validation — component
    // =========================================================================

    public function test_store_rejects_missing_component(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['component']]);
    }

    public function test_store_rejects_unknown_component(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'not-a-real-component',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['component']]);
    }

    // =========================================================================
    // Validation — amount
    // =========================================================================

    public function test_store_rejects_missing_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            ['component' => 'principal'],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_zero_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '0.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_negative_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '-500.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_malformed_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => 'not-a-number',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_amount_with_too_many_decimals(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '100.12345',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    // =========================================================================
    // Tenant scoping
    // =========================================================================

    public function test_store_returns_404_for_cross_tenant_wallet(): void
    {
        $foreignWallet = $this->openWalletFor($this->otherShareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $foreignWallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No obligation may have been created against the foreign wallet.
        $this->assertSame(
            0,
            Obligation::query()->where('wallet_id', $foreignWallet->id)->count(),
        );

        // The foreign wallet's balance is unchanged.
        $foreignWallet->refresh();
        $this->assertSame('0.0000', (string) $foreignWallet->current_balance);
    }

    public function test_store_returns_404_for_missing_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => 999999]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // Response shape
    // =========================================================================

    public function test_store_response_has_expected_envelope_shape(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->wallet->id]),
            [
                'component' => 'principal',
                'amount'    => '500.0000',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'wallet_id',
                'component',
                'currency',
                'original_amount',
                'outstanding_amount',
                'occurred_at',
                'due_at',
                'reference_type',
                'reference_id',
                'description',
                'created_by',
                'is_open',
                'is_partially_settled',
                'is_settled',
                'created_at',
                'updated_at',
            ],
        ]);
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
     * the convention established by 2A.46–2A.49 and 2B.62.
     *
     * The method attaches the permission to `$this->admin` only. Tests
     * that need to grant a permission to a different user attach the
     * returned Permission to that user explicitly.
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