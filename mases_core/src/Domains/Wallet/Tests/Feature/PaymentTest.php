<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * PaymentTest
 *
 * Feature tests for the `Payment` scoped business aggregate's HTTP
 * surface.
 *
 * =============================================================================
 * Endpoint covered
 * =============================================================================
 * The single route of the PaymentController (2B.28), mapped by the routes
 * file (2B.48) per Step 7 v1.1.0 §C.1 route #6:
 *
 *     POST /wallets/{wallet}/payments  → store()  record a payment
 *
 * URLs are resolved via the `route('api.wallets.payments.store')` helper;
 * the test never hard-codes a path.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * Same rationale as 2B.63 (ObligationTest): the feature suite does not
 * assert on the after-commit `PaymentReceived` event (the unit test,
 * 2B.59, does). The suite's focus is the HTTP contract.
 *
 * =============================================================================
 * Authorization layering — the two-layer model in action
 * =============================================================================
 * The endpoint exercises both authorization layers from Step 7 v1.1.0
 * §C.1 route #6:
 *
 *   1. Route middleware `auth.permission:wallet.payment.record` runs
 *      before the controller.
 *
 *   2. The controller calls `Gate::authorize('view', $wallet)`.
 *
 * Three user fixtures test the three states, mirroring 2B.63:
 *
 *   admin                    → passes both layers → 201
 *   userWithRecordOnly       → passes middleware, fails policy → 403
 *   userWithoutPermission    → fails middleware → 403
 *
 * =============================================================================
 * Sign convention — the mirror of ObligationTest (2B.63)
 * =============================================================================
 * Recording a payment is a CREDIT of the member's position: the ledger
 * entry's signed quantity is POSITIVE. The ObligationTest suite asserts
 * the NEGATIVE case; this suite asserts the POSITIVE case. Together, the
 * two suites pin down the sign convention from both directions.
 *
 * Assertions on the ledger entry:
 *
 *     entry_type        = 'payment_received'
 *     signed_quantity   = +amount
 *     component         = null
 *     reference_type    = 'Payment'
 *     reference_id      = payment.id
 *     balance_after     = balance_before + amount
 *
 * =============================================================================
 * No component, no due date (D109)
 * =============================================================================
 * A payment is NOT component-tagged and has no due date (Architecture
 * §3.4). The tests reflect this:
 *
 *   - No `component` field is ever sent in the request body.
 *   - No `due_at` field is ever sent in the request body.
 *   - The created payment's ledger entry has a null `component`.
 *
 * =============================================================================
 * Overpayment is not an error (D110, Architecture §6.4)
 * =============================================================================
 * A payment may exceed any possible outstanding obligation. The explicit
 * recording of the unapplied remainder is the payment's own
 * `unapplied_amount` column — a first-class, queryable field. The suite
 * verifies this by recording a payment against a wallet with no
 * obligations.
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
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.64     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #6)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.0
 */
final class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $userWithRecordOnly;
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

        $this->userWithRecordOnly = User::factory()->create([
            'email' => 'recordonly-' . uniqid() . '@example.test',
        ]);
        $this->userWithRecordOnly->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithRecordOnly->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        $this->userWithoutPermission = User::factory()->create([
            'email' => 'noperm-' . uniqid() . '@example.test',
        ]);
        $this->userWithoutPermission->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $this->userWithoutPermission->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Permissions.
        //
        // `wallet.view` is attached to the admin only.
        // `wallet.payment.record` is attached to the admin AND to
        // `userWithRecordOnly` — the latter exercises the case where the
        // route middleware passes but the policy gate fails.
        // ---------------------------------------------------------------------
        $this->createWalletPermission('wallet.view', 'View wallets');
        $recordPermission = $this->createWalletPermission(
            'wallet.payment.record',
            'Record payment',
        );

        $this->userWithRecordOnly->permissions()->attach(
            $recordPermission->id,
            ['assigned_at' => now()],
        );

        $this->admin->load('permissions');
        $this->userWithRecordOnly->load('permissions');

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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    public function test_store_requires_payment_record_permission(): void
    {
        Sanctum::actingAs($this->userWithoutPermission, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_PERMISSION_DENIED');

        // No payment may have been created.
        $this->assertSame(0, Payment::query()->where('wallet_id', $this->wallet->id)->count());
    }

    public function test_store_requires_wallet_view_policy_gate(): void
    {
        // The user holds `wallet.payment.record` (route middleware passes)
        // but not `wallet.view` and does not own the wallet (policy gate
        // fails). This exercises the second layer of the two-layer
        // authorization model.
        Sanctum::actingAs($this->userWithRecordOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);

        // No payment may have been created.
        $this->assertSame(0, Payment::query()->where('wallet_id', $this->wallet->id)->count());
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function test_store_creates_payment(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.wallet_id', $this->wallet->id);
        $response->assertJsonPath('data.currency', 'ETB');
        $response->assertJsonPath('data.amount', '500.0000');
        $response->assertJsonPath('data.unapplied_amount', '500.0000');
        $response->assertJsonPath('data.is_unapplied', true);
        $response->assertJsonPath('data.is_partially_applied', false);
        $response->assertJsonPath('data.is_fully_applied', false);

        $this->assertDatabaseHas('wallet_payments', [
            'wallet_id'        => $this->wallet->id,
            'currency'         => 'ETB',
            'amount'           => '500.0000',
            'unapplied_amount' => '500.0000',
        ]);
    }

    public function test_store_creates_ledger_entry(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        )->assertStatus(201);

        $payment = Payment::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($payment);

        // Payment ledger entry carries a POSITIVE signed quantity (credit).
        // Contrast with ObligationTest (2B.63), whose entry carries the
        // negation.
        $this->assertDatabaseHas('wallet_entries', [
            'wallet_id'       => $this->wallet->id,
            'entry_type'      => 'payment_received',
            'component'       => null,
            'signed_quantity' => '500.0000',
            'balance_before'  => '0.0000',
            'balance_after'   => '500.0000',
            'reference_type'  => 'Payment',
            'reference_id'    => (string) $payment->id,
        ]);
    }

    public function test_store_updates_wallet_balance_positively(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        )->assertStatus(201);

        $this->wallet->refresh();
        $this->assertSame('500.0000', (string) $this->wallet->current_balance);
    }

    public function test_store_accumulates_multiple_payments(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        foreach (['500.0000', '300.0000', '150.0000'] as $amount) {
            $this->withHeaders([
                'X-Tenant-ID' => (string) $this->tenant->id,
            ])->postJson(
                route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
                ['amount' => $amount],
            )->assertStatus(201);
        }

        $this->wallet->refresh();
        $this->assertSame('950.0000', (string) $this->wallet->current_balance);
        $this->assertSame(
            3,
            Payment::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    public function test_store_accepts_overpayment_without_error(): void
    {
        // The wallet has no obligations. A payment of any size is
        // accepted; the unapplied remainder is recorded explicitly on
        // the payment row (D110). No error is raised.
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '10000.0000'],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.unapplied_amount', '10000.0000');
        $response->assertJsonPath('data.is_unapplied', true);
    }

    public function test_store_accepts_amount_with_one_decimal(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '100.5'],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '100.5000');
    }

    public function test_store_accepts_amount_with_no_decimal(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '100'],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '100.0000');
    }

    public function test_store_accepts_explicit_received_at(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            [
                'amount'      => '500.0000',
                'received_at' => '2026-01-15T12:00:00+03:00',
            ],
        );

        $response->assertStatus(201);
        $this->assertStringStartsWith(
            '2026-01-15',
            (string) $response->json('data.received_at'),
        );
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            [
                'amount'         => '500.0000',
                'reference_type' => 'transfer',
                'reference_id'   => '42',
            ],
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.reference_type', 'transfer');
        $response->assertJsonPath('data.reference_id', '42');
    }

    public function test_store_rejects_reference_type_without_id(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            [
                'amount'         => '500.0000',
                'reference_type' => 'transfer',
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            [
                'amount'       => '500.0000',
                'reference_id' => '42',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['reference_type']]);
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            [],
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '0.0000'],
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '-500.0000'],
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => 'not-a-number'],
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '100.12345'],
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
            route('api.wallets.payments.store', ['wallet' => $foreignWallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No payment may have been created against the foreign wallet.
        $this->assertSame(
            0,
            Payment::query()->where('wallet_id', $foreignWallet->id)->count(),
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
            route('api.wallets.payments.store', ['wallet' => 999999]),
            ['amount' => '500.0000'],
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
            route('api.wallets.payments.store', ['wallet' => $this->wallet->id]),
            ['amount' => '500.0000'],
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'wallet_id',
                'currency',
                'amount',
                'unapplied_amount',
                'received_at',
                'reference_type',
                'reference_id',
                'description',
                'created_by',
                'is_unapplied',
                'is_partially_applied',
                'is_fully_applied',
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
     * Used by tests that need an existing wallet as a fixture.
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