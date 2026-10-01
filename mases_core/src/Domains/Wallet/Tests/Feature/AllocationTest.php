<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature;

use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocationReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * AllocationTest
 *
 * Feature tests for the `PaymentAllocation` HTTP surface.
 *
 * =============================================================================
 * Endpoints covered
 * =============================================================================
 * The two routes of the AllocationController (2B.29), mapped by the
 * routes file (2B.48) per Step 7 v1.1.0 §C.1 routes #7 and #8:
 *
 *     POST /wallets/{wallet}/allocations                        → store()    #7
 *     POST /wallets/{wallet}/allocations/{allocation}/reverse   → reverse()  #8
 *
 * URLs are resolved via the `route('api.wallets.allocations.*')` helper.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * Same rationale as 2B.62–2B.64. The feature suite does not assert on
 * the after-commit `PaymentAllocated` / `PaymentAllocationReversed`
 * events; the unit test (2B.60) does. Fixtures are built directly through
 * the models, bypassing the service layer entirely — so no after-commit
 * callbacks are registered on any test path.
 *
 * =============================================================================
 * Two endpoints, two permissions, one shared policy gate
 * =============================================================================
 * The two endpoints have DIFFERENT route middleware but the SAME policy
 * gate:
 *
 *     store    → route middleware `wallet.allocation.create`
 *                controller calls `Gate::authorize('view', $wallet)`
 *
 *     reverse  → route middleware `wallet.allocation.reverse`
 *                controller calls `Gate::authorize('view', $wallet)`
 *
 * The two permissions are deliberately NOT bundled (Step 7 v1.1.0
 * §A.2.1): a caller may hold `create` without `reverse`. The suite
 * exercises both middleware layers and both policy gates with distinct
 * user fixtures.
 *
 * =============================================================================
 * Four user fixtures, five authorization states
 * =============================================================================
 *   admin                    → wallet.view + wallet.allocation.create
 *                              + wallet.allocation.reverse
 *                              → passes both layers on both endpoints
 *
 *   userWithCreateOnly       → wallet.allocation.create only (no view,
 *                              no reverse)
 *                              → store: middleware pass, policy fail (403)
 *                              → reverse: middleware fail (403)
 *
 *   userWithoutPermission    → no permissions
 *                              → both endpoints: middleware fail (403)
 *
 * =============================================================================
 * Cross-tenant protection — the strongest test in the domain
 * =============================================================================
 * This suite exercises all five cross-tenant vectors that Step 7 v1.1.0
 * §C.3 assigns to the allocation endpoint:
 *
 *     T7  → cross-tenant {wallet} on store              404
 *     T8  → cross-wallet payment_id on store            404
 *     T9  → cross-wallet obligation_id on store         404
 *     T10 → cross-tenant {wallet} on reverse            404
 *     T11 → cross-wallet {allocation} on reverse        404
 *
 * The store endpoint's T8 and T9 are resolved inside the service (2B.18
 * §"allocate" pre-flight resolution); the reverse endpoint's T11 is
 * resolved by the controller's two-stage lookup (2B.29 §"Two-stage
 * tenant scoping") before the service is invoked.
 *
 * =============================================================================
 * Allocations do NOT touch the wallet (Architecture §5.1)
 * =============================================================================
 * Neither endpoint produces a `wallet_entries` row, and neither endpoint
 * changes `wallet.current_balance`. Two tests verify each invariant for
 * each endpoint — four tests in total. See 2B.60 §"No ledger entries"
 * for the unit-level rationale.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createWalletPermission()` — not `createPermission()` — to avoid the
 * collision with the protected method declared on Tests\TestCase.
 *
 * =============================================================================
 * Correction history (v1.0.1)
 * =============================================================================
 * `test_store_returns_404_for_nonexistent_payment` (v1.0.0) asserted 404
 * for a wholly nonexistent `payment_id`. That expectation was incorrect:
 * the `StoreAllocationRequest`'s `exists:wallet_payments,id` rule catches
 * such an id at the form-validation layer and returns 422. A 404 applies
 * only to a CROSS-WALLET id — an id that exists but belongs to a
 * different wallet — which is covered by
 * `test_store_returns_404_for_cross_wallet_payment_t8`.
 *
 * v1.0.1 renames the test to `test_store_rejects_nonexistent_payment` and
 * changes the assertion to 422 with `errors.payment_id`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.5    — PaymentAllocation entity
 *   Architecture v1.2.0 §3.6    — PaymentAllocationReversal entity (A1)
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Allocations do not produce ledger entries
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.3    — Allocation invariants
 *   Architecture v1.2.0 §6.5    — Allocation-reversal invariants
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.65     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §A.2.1        — Rationale for the reverse privilege scope
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (routes #7, #8)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T7–T11)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.1
 */
final class AllocationTest extends TestCase
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
    private Obligation $obligation;
    private Payment $payment;

    private Wallet $otherWallet;
    private Obligation $otherObligation;
    private Payment $otherPayment;

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
        // Three users.
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
        // `wallet.view` is attached to the admin only.
        // `wallet.allocation.create` and `wallet.allocation.reverse` are
        // attached to the admin.
        // `userWithCreateOnly` holds ONLY `wallet.allocation.create` —
        // proving the two permissions are independently enforced.
        // ---------------------------------------------------------------------
        $this->createWalletPermission('wallet.view',              'View wallets');
        $this->createWalletPermission('wallet.allocation.create', 'Record allocation');
        $reversePermission = $this->createWalletPermission(
            'wallet.allocation.reverse',
            'Reverse allocation',
        );

        // `userWithCreateOnly` gets ONLY the create permission — no
        // view, no reverse.
        $createPermission = Permission::query()
            ->where('slug', 'wallet.allocation.create')
            ->firstOrFail();

        $this->userWithCreateOnly->permissions()->attach(
            $createPermission->id,
            ['assigned_at' => now()],
        );

        $this->admin->load('permissions');
        $this->userWithCreateOnly->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders and wallets.
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

        $this->wallet      = $this->openWalletFor($this->shareholder);
        $this->otherWallet = $this->openWalletFor($this->otherShareholder);

        // ---------------------------------------------------------------------
        // Obligations and payments — the two sides of an allocation.
        //
        // Built directly through the models (bypassing the services) so
        // that no after-commit callbacks are registered. The ledger
        // entries that would normally accompany them are NOT created, but
        // the allocation endpoint does not read the ledger — it reads the
        // parent rows' projections, which the direct-model path initialises
        // correctly.
        // ---------------------------------------------------------------------
        $this->obligation = $this->makeObligationFor($this->wallet, '1000.0000');
        $this->payment    = $this->makePaymentFor($this->wallet, '500.0000');

        $this->otherObligation = $this->makeObligationFor($this->otherWallet, '300.0000');
        $this->otherPayment    = $this->makePaymentFor($this->otherWallet, '200.0000');

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // store() — authentication and authorization
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_allocation_create_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');

        $this->assertSame(
            0,
            PaymentAllocation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    public function test_store_requires_wallet_view_policy_gate(): void
    {
        // Holds `wallet.allocation.create` (middleware pass) but not
        // `wallet.view` (policy fail).
        Sanctum::actingAs($this->userWithCreateOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // store() — happy path
    // =========================================================================

    public function test_store_creates_allocation(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.wallet_id', $this->wallet->id);
        $response->assertJsonPath('data.payment_id', $this->payment->id);
        $response->assertJsonPath('data.obligation_id', $this->obligation->id);
        $response->assertJsonPath('data.amount', '200.0000');

        $this->assertDatabaseHas('wallet_payment_allocations', [
            'wallet_id'     => $this->wallet->id,
            'payment_id'    => $this->payment->id,
            'obligation_id' => $this->obligation->id,
            'amount'        => '200.0000',
        ]);
    }

    public function test_store_decrements_payment_unapplied(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        )->assertStatus(201);

        $this->payment->refresh();
        $this->assertSame('300.0000', (string) $this->payment->unapplied_amount);
    }

    public function test_store_decrements_obligation_outstanding(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        )->assertStatus(201);

        $this->obligation->refresh();
        $this->assertSame('800.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_store_does_not_change_wallet_balance(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $before = (string) $this->wallet->current_balance;

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        )->assertStatus(201);

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_store_produces_no_ledger_entry(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $entryCountBefore = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        )->assertStatus(201);

        $entryCountAfter = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        // Allocations are attribution, not monetary movements. The
        // ledger is untouched (Architecture §5.1).
        $this->assertSame($entryCountBefore, $entryCountAfter);
    }

    public function test_store_accepts_null_policy_version(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.allocation_policy_version_id', null);
    }

    // =========================================================================
    // store() — validation
    // =========================================================================

    public function test_store_rejects_zero_amount(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '0.0000',
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
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => 'not-a-number',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_missing_payment_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['payment_id']]);
    }

    public function test_store_rejects_missing_obligation_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id' => $this->payment->id,
                'amount'     => '200.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['obligation_id']]);
    }

    public function test_store_rejects_amount_exceeding_payment_unapplied(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Payment has 500.0000 unapplied; allocating 600.0000 violates
        // §6.3 invariant 2.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '600.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    public function test_store_rejects_amount_exceeding_obligation_outstanding(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Create a payment larger than the obligation's outstanding so
        // the obligation bound is the binding constraint.
        $largePayment = $this->makePaymentFor($this->wallet, '2000.0000');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $largePayment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '1500.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['amount']]);
    }

    // =========================================================================
    // store() — cross-tenant protection (T7, T8, T9)
    // =========================================================================

    public function test_store_returns_404_for_cross_tenant_wallet_T7(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->otherWallet->id]),
            [
                'payment_id'    => $this->otherPayment->id,
                'obligation_id' => $this->otherObligation->id,
                'amount'        => '100.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        $this->assertSame(
            0,
            PaymentAllocation::query()->where('wallet_id', $this->otherWallet->id)->count(),
        );
    }

    public function test_store_returns_404_for_cross_wallet_payment_T8(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                // A payment belonging to the OTHER wallet.
                'payment_id'    => $this->otherPayment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '100.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The primary obligation's outstanding is unchanged.
        $this->obligation->refresh();
        $this->assertSame('1000.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_store_returns_404_for_cross_wallet_obligation_T9(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                // An obligation belonging to the OTHER wallet.
                'obligation_id' => $this->otherObligation->id,
                'amount'        => '100.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The primary payment's unapplied is unchanged.
        $this->payment->refresh();
        $this->assertSame('500.0000', (string) $this->payment->unapplied_amount);
    }

    /**
     * A wholly nonexistent payment_id is caught by the StoreAllocationRequest's
     * `exists:wallet_payments,id` rule (2B.24 §"Rules") and produces a
     * 422 VALIDATION_ERROR — not a 404.
     *
     * A CROSS-WALLET id is a different case: the row exists in the
     * `wallet_payments` table, so the `exists` rule passes; the service's
     * wallet-scoped pre-flight lookup then returns null and produces the
     * 404. That case is covered by
     * `test_store_returns_404_for_cross_wallet_payment_t8`, which passes.
     *
     * The v1.0.0 form of this test asserted 404, which was incorrect for
     * the reason above. See the class docblock §"Correction history
     * (v1.0.1)".
     */
    public function test_store_rejects_nonexistent_payment(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => 999999,
                'obligation_id' => $this->obligation->id,
                'amount'        => '100.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['payment_id']]);
    }

    // =========================================================================
    // reverse() — authentication and authorization
    // =========================================================================

    public function test_reverse_requires_authentication(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Audit test.',
            ],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_reverse_requires_allocation_reverse_permission(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        // `userWithCreateOnly` holds `wallet.allocation.create` but NOT
        // `wallet.allocation.reverse`. The route middleware rejects.
        Sanctum::actingAs($this->userWithCreateOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Audit test.',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');

        $this->assertSame(
            0,
            PaymentAllocationReversal::query()
                ->where('payment_allocation_id', $allocation->id)
                ->count(),
        );
    }

    public function test_reverse_requires_wallet_view_policy_gate(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        // A user holding only `wallet.allocation.reverse` (middleware pass)
        // but not `wallet.view` (policy fail). We grant the reverse
        // permission to `userWithoutPermission` just for this test.
        $reversePermission = Permission::query()
            ->where('slug', 'wallet.allocation.reverse')
            ->firstOrFail();

        $this->userWithoutPermission->permissions()->attach(
            $reversePermission->id,
            ['assigned_at' => now()],
        );

        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Audit test.',
            ],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // reverse() — happy path
    // =========================================================================

    public function test_reverse_creates_reversal_row(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.wallet_id', $this->wallet->id);
        $response->assertJsonPath('data.payment_allocation_id', $allocation->id);
        $response->assertJsonPath('data.reversed_amount', '50.0000');
        $response->assertJsonPath('data.reason', 'Partial correction.');
    }

    public function test_reverse_increments_payment_unapplied(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        // After allocation: payment.unapplied = 300.0000.
        $this->payment->refresh();
        $this->assertSame('300.0000', (string) $this->payment->unapplied_amount);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        )->assertStatus(201);

        // After reversal: payment.unapplied = 350.0000.
        $this->payment->refresh();
        $this->assertSame('350.0000', (string) $this->payment->unapplied_amount);
    }

    public function test_reverse_increments_obligation_outstanding(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        )->assertStatus(201);

        // After allocation + partial reversal:
        // obligation.outstanding = 1000 - 200 + 50 = 850.0000.
        $this->obligation->refresh();
        $this->assertSame('850.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_reverse_does_not_change_wallet_balance(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $before = (string) $this->wallet->current_balance;

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        )->assertStatus(201);

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_reverse_produces_no_ledger_entry(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $entryCountBefore = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        )->assertStatus(201);

        $entryCountAfter = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->assertSame($entryCountBefore, $entryCountAfter);
    }

    public function test_reverse_preserves_original_allocation(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Partial correction.',
            ],
        )->assertStatus(201);

        // The original allocation row is untouched. Immutability is a
        // model-level guarantee (2B.8).
        $allocation->refresh();
        $this->assertSame('200.0000', (string) $allocation->amount);
    }

    // =========================================================================
    // reverse() — validation
    // =========================================================================

    public function test_reverse_rejects_zero_reversed_amount(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '0.0000',
                'reason'          => 'Zero-reversal.',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reversed_amount']]);
    }

    public function test_reverse_rejects_amount_exceeding_effective(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '250.0000',
                'reason'          => 'Exceeds the parent.',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reversed_amount']]);
    }

    public function test_reverse_rejects_missing_reason(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    public function test_reverse_rejects_whitespace_only_reason(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => '   ',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['reason']]);
    }

    // =========================================================================
    // reverse() — cross-tenant protection (T10, T11)
    // =========================================================================

    public function test_reverse_returns_404_for_cross_tenant_wallet_T10(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                // The OTHER wallet — belongs to a different tenant.
                'wallet'     => $this->otherWallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Cross-tenant attempt.',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The allocation is unchanged.
        $allocation->refresh();
        $this->assertSame('200.0000', (string) $allocation->amount);
    }

    public function test_reverse_returns_404_for_cross_wallet_allocation_T11(): void
    {
        // Build a foreign allocation belonging to the OTHER wallet.
        $foreignAllocation = $this->makeAllocationFor(
            $this->otherWallet,
            $this->otherPayment,
            $this->otherObligation,
            '50.0000',
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                // The PRIMARY wallet — legitimate for the caller.
                'wallet'     => $this->wallet->id,
                // The OTHER allocation — does not belong to this wallet.
                'allocation' => $foreignAllocation->id,
            ]),
            [
                'reversed_amount' => '25.0000',
                'reason'          => 'Cross-wallet attempt.',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);

        // The foreign allocation is unchanged.
        $foreignAllocation->refresh();
        $this->assertSame('50.0000', (string) $foreignAllocation->amount);
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
            route('api.wallets.allocations.store', ['wallet' => $this->wallet->id]),
            [
                'payment_id'    => $this->payment->id,
                'obligation_id' => $this->obligation->id,
                'amount'        => '200.0000',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'wallet_id',
                'payment_id',
                'obligation_id',
                'amount',
                'allocated_at',
                'allocated_by',
                'allocation_policy_version_id',
                'created_at',
                'updated_at',
            ],
        ]);
    }

    public function test_reverse_response_has_expected_envelope_shape(): void
    {
        $allocation = $this->makeAllocationFor($this->wallet, $this->payment, $this->obligation, '200.0000');

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                'wallet'     => $this->wallet->id,
                'allocation' => $allocation->id,
            ]),
            [
                'reversed_amount' => '50.0000',
                'reason'          => 'Audit test.',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'wallet_id',
                'payment_allocation_id',
                'reversed_amount',
                'reason',
                'reversed_at',
                'reversed_by',
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
     * Create an obligation row directly, bypassing the service layer.
     *
     * `outstanding_amount` starts equal to `original_amount` (no
     * allocations yet). The wallet's ledger is not touched by this
     * fixture — the allocation endpoint never reads the ledger.
     */
    private function makeObligationFor(Wallet $wallet, string $amount): Obligation
    {
        return Obligation::create([
            'wallet_id'          => $wallet->id,
            'component'          => 'principal',
            'currency'           => $wallet->currency,
            'original_amount'    => $amount,
            'outstanding_amount' => $amount,
            'occurred_at'        => Carbon::now(),
        ]);
    }

    /**
     * Create a payment row directly, bypassing the service layer.
     *
     * `unapplied_amount` starts equal to `amount` (no allocations yet).
     */
    private function makePaymentFor(Wallet $wallet, string $amount): Payment
    {
        return Payment::create([
            'wallet_id'        => $wallet->id,
            'currency'         => $wallet->currency,
            'amount'           => $amount,
            'unapplied_amount' => $amount,
            'received_at'      => Carbon::now(),
        ]);
    }

    /**
     * Create a payment allocation row directly, bypassing the service
     * layer, AND decrement both parent projections.
     *
     * The direct-model fixture path is used to avoid triggering the
     * service's after-commit event dispatch, which would be discarded by
     * the enclosing RefreshDatabase transaction anyway but which would
     * still cost the dispatch overhead per test. The projection
     * decrements are performed inline so the fixture state matches what
     * the service would have produced.
     */
    private function makeAllocationFor(
        Wallet $wallet,
        Payment $payment,
        Obligation $obligation,
        string $amount,
    ): PaymentAllocation {
        $allocation = PaymentAllocation::create([
            'wallet_id'     => $wallet->id,
            'payment_id'    => $payment->id,
            'obligation_id' => $obligation->id,
            'amount'        => $amount,
            'allocated_at'  => Carbon::now(),
            'allocated_by'  => null,
        ]);

        // Decrement both projections to reflect the allocation.
        $payment->unapplied_amount = bcsub(
            (string) $payment->unapplied_amount,
            $amount,
            4,
        );
        $payment->save();

        $obligation->outstanding_amount = bcsub(
            (string) $obligation->outstanding_amount,
            $amount,
            4,
        );
        $obligation->save();

        return $allocation;
    }

    /**
     * Create a Wallet-domain permission and attach it to the admin user.
     *
     * Named `createWalletPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase.
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