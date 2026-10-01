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
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * TenantIsolationTest (Wallet domain)
 *
 * Verifies that the Wallet domain does not leak data across tenant
 * boundaries. This file is DISTINCT from the Phase 1 suite at
 * `tests/Feature/TenantIsolationTest.php` and from the Shareholder
 * domain's 2A.49 suite at
 * `mases_core/src/Domains/Shareholder/Tests/Feature/TenantIsolationTest.php`.
 * It is scoped to the Wallet domain and exercises every one of the nine
 * routes frozen in Step 7 v1.1.0 §C.1.
 *
 * =============================================================================
 * The 13 cross-tenant vectors (Step 7 v1.1.0 §C.3)
 * =============================================================================
 * This suite is the executable form of the cross-tenant test matrix
 * frozen in Step 7 v1.1.0 §C.3. Every vector in that matrix maps to
 * exactly one test method here:
 *
 *     T1   index                 — list returns only the caller's tenant
 *     T2   store                 — cross-tenant shareholder_id → 404
 *     T3   show                  — cross-tenant {wallet} → 404
 *     T4   adjust                — cross-tenant {wallet} → 404 + no mutation
 *     T5   obligations.store     — cross-tenant {wallet} → 404
 *     T6   payments.store        — cross-tenant {wallet} → 404
 *     T7   allocations.store     — cross-tenant {wallet} → 404
 *     T8   allocations.store     — cross-wallet payment_id → 404
 *     T9   allocations.store     — cross-wallet obligation_id → 404
 *     T10  allocations.reverse   — cross-tenant {wallet} → 404
 *     T11  allocations.reverse   — cross-wallet {allocation} → 404
 *     T12  statement.show        — cross-tenant {wallet} → 404
 *     T13  forged header         — X-Tenant-ID of the other tenant → 403
 *
 * Each of T1–T12 uses a genuine cross-tenant id. None uses the sentinel
 * `999999` — a non-existent id would exercise the "no such row" path,
 * not the "row exists but wrong tenant" path, and the two paths must be
 * indistinguishable from the caller's perspective.
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The suite uses RefreshDatabase. The Wallet domain's write endpoints
 * dispatch events via `DB::afterCommit()`, but the cross-tenant tests
 * never reach a successful write — every one of them stops at the
 * tenant-boundary check before any state change. There is therefore no
 * after-commit callback to await.
 *
 * The fixture setup uses `Wallet::create([...])` and direct model
 * inserts, bypassing the service layer entirely, so no after-commit
 * callbacks are registered on the fixture path either.
 *
 * =============================================================================
 * Scope — isolation, not authentication, not authorization
 * =============================================================================
 * The admin fixture holds EVERY Wallet-domain permission the endpoints
 * require. Consequently, no test in this suite can fail with a 403 from
 * missing permissions or missing role assignments: the failures are all
 * 404s from the tenant-boundary enforcement (T2–T12) or 403s from the
 * `tenant.membership` middleware (T13).
 *
 * This is the same scope discipline as 2A.49. Authentication (401) and
 * permission-level authorization (403) are tested by the per-endpoint
 * feature suites (2B.62–2B.66); this suite tests only the tenant
 * dimension.
 *
 * =============================================================================
 * Raw-body scan policy — the Amharic marker
 * =============================================================================
 * Where a test scans the raw response body for evidence of cross-tenant
 * leakage, the scan target is the tenant-B shareholder's Amharic given
 * name (`ሳሙኤል`). Numeric ids are NOT used as scan targets: a single-digit
 * id collides with timestamps, UUIDs, and exception line numbers, as
 * documented in the 2A.49 finding.
 *
 * Not every test in this suite performs a raw-body scan. The 404
 * responses produced by T2–T12 are small error envelopes that carry no
 * resource payload; there is nothing for them to leak. The raw-body scan
 * is applied to T1 (index, whose payload CAN in principle contain data)
 * and T13 (the forged-header case, which must not leak anything from
 * either tenant).
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createWalletPermission()` — not `createPermission()` — to avoid the
 * collision with the protected method declared on Tests\TestCase. Same
 * convention as 2B.62–2B.66.
 *
 * =============================================================================
 * Correction history (v1.0.1)
 * =============================================================================
 * `test_store_rejects_cross_tenant_shareholder` (v1.0.0) asserted
 * `assertDatabaseMissing('wallets', ['shareholder_id' => $shareholderB->id])`.
 * That assertion could never pass: the setUp() fixture already creates a
 * wallet for shareholderB as `$this->walletB`. The invariant the test
 * intends to verify is "the rejected request does not create a second
 * wallet for the foreign shareholder". v1.0.1 captures the count before
 * the request and asserts it is unchanged after.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §2      — Bounded context and domain boundary
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §6.3    — Allocation invariants (cross-wallet
 *                                 rejection at the service layer)
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Step 5 (manifest) 2B.67     — This file
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T1–T13)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.1
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Primary tenant (tenantA) — the caller's tenant.
    // -------------------------------------------------------------------------
    private Tenant $tenantA;
    private User $admin;
    private Shareholder $shareholderA;
    private Wallet $walletA;
    private Obligation $obligationA;
    private Payment $paymentA;
    private PaymentAllocation $allocationA;

    // -------------------------------------------------------------------------
    // Foreign tenant (tenantB) — the other tenant.
    // -------------------------------------------------------------------------
    private Tenant $tenantB;
    private Shareholder $shareholderB;
    private Wallet $walletB;
    private Obligation $obligationB;
    private Payment $paymentB;
    private PaymentAllocation $allocationB;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->tenantA = Tenant::create([
            'slug'      => 'tenant-a',
            'name_am'   => 'ቴናንት ኤ',
            'name_en'   => 'Tenant A',
            'domain'    => 'a.maalearna.com.et',
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::create([
            'slug'      => 'tenant-b',
            'name_am'   => 'ቴናንት ቢ',
            'name_en'   => 'Tenant B',
            'domain'    => 'b.maalearna.com.et',
            'is_active' => true,
        ]);

        // ---------------------------------------------------------------------
        // The admin is a member of tenantA only. `forceFill` bypasses
        // $fillable so the default-tenant override survives regardless of
        // the User model's mass-assignment guards.
        // ---------------------------------------------------------------------
        $this->admin = User::factory()->create([
            'email' => 'iso-admin-' . uniqid() . '@example.test',
        ]);
        $this->admin->forceFill(['default_tenant_id' => $this->tenantA->id])->save();
        $this->admin->tenants()->attach($this->tenantA->id, ['joined_at' => now()]);

        // ---------------------------------------------------------------------
        // Every Wallet-domain permission the nine routes require.
        //
        // Because the admin holds all of them, no test in this suite can
        // fail with a 403 from missing permissions or role assignments.
        // See the class docblock §"Scope".
        // ---------------------------------------------------------------------
        $this->createWalletPermission('wallet.view',              'View wallets');
        $this->createWalletPermission('wallet.open',              'Open wallet');
        $this->createWalletPermission('wallet.adjust',            'Adjust wallet balance');
        $this->createWalletPermission('wallet.obligation.create', 'Record obligation');
        $this->createWalletPermission('wallet.payment.record',    'Record payment');
        $this->createWalletPermission('wallet.allocation.create', 'Record allocation');
        $this->createWalletPermission('wallet.allocation.reverse', 'Reverse allocation');
        $this->createWalletPermission('wallet.statement.view',    'View statements');

        $this->admin->load('permissions');

        // ---------------------------------------------------------------------
        // Shareholders. The tenantB shareholder's Amharic given name is the
        // raw-body scan marker used by T1 and T13. Its father name is a
        // second marker for T1's belt-and-braces assertion.
        // ---------------------------------------------------------------------
        $this->shareholderA = Shareholder::create([
            'tenant_id'        => $this->tenantA->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);

        $this->shareholderB = Shareholder::create([
            'tenant_id'        => $this->tenantB->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'ሳሙኤል',
            'father_name_am'   => 'ተስፋዬ',
            'is_active'        => true,
        ]);

        // ---------------------------------------------------------------------
        // Wallets — one per tenant.
        // ---------------------------------------------------------------------
        $this->walletA = $this->openWalletFor($this->shareholderA);
        $this->walletB = $this->openWalletFor($this->shareholderB);

        // ---------------------------------------------------------------------
        // Obligations and payments — one per wallet.
        // ---------------------------------------------------------------------
        $this->obligationA = $this->makeObligationFor($this->walletA, '1000.0000');
        $this->paymentA    = $this->makePaymentFor($this->walletA, '500.0000');

        $this->obligationB = $this->makeObligationFor($this->walletB, '300.0000');
        $this->paymentB    = $this->makePaymentFor($this->walletB, '200.0000');

        // ---------------------------------------------------------------------
        // Allocations — one per wallet. The fixtures manually update the
        // parent projections to match what the service would have
        // produced, so that the "no mutation" assertions in T10 and T11
        // can distinguish a rejected request from one that succeeded.
        // ---------------------------------------------------------------------
        $this->allocationA = $this->makeAllocationFor(
            $this->walletA,
            $this->paymentA,
            $this->obligationA,
            '200.0000',
        );
        $this->allocationB = $this->makeAllocationFor(
            $this->walletB,
            $this->paymentB,
            $this->obligationB,
            '50.0000',
        );

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // T1 — index returns only tenantA wallets
    // =========================================================================

    public function test_index_returns_only_tenant_wallets(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data, 'Index payload must be an array under `data`.');

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $data,
        );

        $this->assertContains(
            (int) $this->walletA->id,
            $ids,
            'tenantA wallet must appear in the index for tenantA.',
        );
        $this->assertNotContains(
            (int) $this->walletB->id,
            $ids,
            'tenantB wallet must NOT appear in the index for tenantA.',
        );

        // Semantic leak check: tenantB shareholder's Amharic given name
        // must not appear anywhere in the raw response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB shareholder name must not appear in tenantA index response.',
        );
    }

    // =========================================================================
    // T2 — store rejects a cross-tenant shareholder_id
    // =========================================================================

    /**
     * The setUp() fixture already creates one wallet for shareholderB (as
     * `$this->walletB`). This test verifies that the rejected request does
     * NOT create a second wallet for that shareholder.
     *
     * The v1.0.0 form of this test asserted `assertDatabaseMissing('wallets',
     * ['shareholder_id' => $this->shareholderB->id])`, which always fails
     * because the setUp() fixture already created that wallet. The
     * invariant the test intends to verify is "the count is unchanged",
     * not "no wallet exists". See the class docblock §"Correction history
     * (v1.0.1)".
     */
    public function test_store_rejects_cross_tenant_shareholder(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Capture the pre-request count of wallets owned by the foreign
        // shareholder. The setUp() fixture creates exactly one.
        $walletsBefore = Wallet::query()
            ->where('shareholder_id', $this->shareholderB->id)
            ->count();

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(route('api.wallets.store'), [
            'shareholder_id' => $this->shareholderB->id,
            'currency'       => 'ETB',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The rejected request must not have created a second wallet for
        // the foreign shareholder. The count is unchanged.
        $walletsAfter = Wallet::query()
            ->where('shareholder_id', $this->shareholderB->id)
            ->count();

        $this->assertSame(
            $walletsBefore,
            $walletsAfter,
            'A rejected cross-tenant store must not create a wallet for the foreign shareholder.',
        );
    }

    // =========================================================================
    // T3 — show rejects a cross-tenant wallet
    // =========================================================================

    public function test_show_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.wallets.show', [
            'wallet' => $this->walletB->id,
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // T4 — adjust rejects a cross-tenant wallet, with non-mutation check
    // =========================================================================

    public function test_adjust_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.adjust', ['wallet' => $this->walletB->id]),
            ['signed_amount' => '+100.0000'],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The foreign wallet's balance is unchanged.
        $this->walletB->refresh();
        $this->assertSame('0.0000', (string) $this->walletB->current_balance);
    }

    // =========================================================================
    // T5 — obligations.store rejects a cross-tenant wallet
    // =========================================================================

    public function test_obligation_store_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.obligations.store', ['wallet' => $this->walletB->id]),
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
            1,
            Obligation::query()->where('wallet_id', $this->walletB->id)->count(),
        );
    }

    // =========================================================================
    // T6 — payments.store rejects a cross-tenant wallet
    // =========================================================================

    public function test_payment_store_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.payments.store', ['wallet' => $this->walletB->id]),
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
            1,
            Payment::query()->where('wallet_id', $this->walletB->id)->count(),
        );
    }

    // =========================================================================
    // T7 — allocations.store rejects a cross-tenant wallet
    // =========================================================================

    public function test_allocation_store_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->walletB->id]),
            [
                'payment_id'    => $this->paymentB->id,
                'obligation_id' => $this->obligationB->id,
                'amount'        => '50.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // No allocation may have been created against the foreign wallet.
        $this->assertSame(
            1,
            PaymentAllocation::query()->where('wallet_id', $this->walletB->id)->count(),
        );
    }

    // =========================================================================
    // T8 — allocations.store rejects a cross-wallet payment_id
    // =========================================================================

    public function test_allocation_store_returns_404_for_cross_tenant_payment(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->walletA->id]),
            [
                // tenantB's payment, against tenantA's wallet/obligation.
                'payment_id'    => $this->paymentB->id,
                'obligation_id' => $this->obligationA->id,
                'amount'        => '50.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The primary obligation's outstanding is unchanged. The fixture
        // allocated 200.0000 against it, so 1000.0000 − 200.0000 = 800.0000.
        $this->obligationA->refresh();
        $this->assertSame('800.0000', (string) $this->obligationA->outstanding_amount);
    }

    // =========================================================================
    // T9 — allocations.store rejects a cross-wallet obligation_id
    // =========================================================================

    public function test_allocation_store_returns_404_for_cross_tenant_obligation(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.allocations.store', ['wallet' => $this->walletA->id]),
            [
                'payment_id'    => $this->paymentA->id,
                // tenantB's obligation, against tenantA's wallet/payment.
                'obligation_id' => $this->obligationB->id,
                'amount'        => '50.0000',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The primary payment's unapplied is unchanged. The fixture
        // allocated 200.0000 from it, so 500.0000 − 200.0000 = 300.0000.
        $this->paymentA->refresh();
        $this->assertSame('300.0000', (string) $this->paymentA->unapplied_amount);
    }

    // =========================================================================
    // T10 — allocations.reverse rejects a cross-tenant wallet
    // =========================================================================

    public function test_allocation_reverse_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                // tenantB's wallet, but tenantA's allocation id.
                'wallet'     => $this->walletB->id,
                'allocation' => $this->allocationA->id,
            ]),
            [
                'reversed_amount' => '25.0000',
                'reason'          => 'Cross-tenant attempt.',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The primary allocation's amount is unchanged.
        $this->allocationA->refresh();
        $this->assertSame('200.0000', (string) $this->allocationA->amount);
    }

    // =========================================================================
    // T11 — allocations.reverse rejects a cross-wallet allocation
    // =========================================================================

    public function test_allocation_reverse_returns_404_for_cross_tenant_allocation(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->postJson(
            route('api.wallets.allocations.reverse', [
                // tenantA's wallet, but tenantB's allocation id.
                'wallet'     => $this->walletA->id,
                'allocation' => $this->allocationB->id,
            ]),
            [
                'reversed_amount' => '25.0000',
                'reason'          => 'Cross-wallet attempt.',
            ],
        );

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );

        // The foreign allocation's amount is unchanged.
        $this->allocationB->refresh();
        $this->assertSame('50.0000', (string) $this->allocationB->amount);
    }

    // =========================================================================
    // T12 — statement.show rejects a cross-tenant wallet
    // =========================================================================

    public function test_statement_returns_404_for_cross_tenant_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantA->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->walletB->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertContains(
            $response->json('error.code'),
            ['RESOURCE_NOT_FOUND', 'ROUTE_NOT_FOUND'],
        );
    }

    // =========================================================================
    // T13 — forged X-Tenant-ID header is rejected by tenant.membership
    // =========================================================================

    public function test_tenant_header_cannot_be_forged_to_access_other_tenant(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // The admin is a member of tenantA only. Sending tenantB's id in
        // the X-Tenant-ID header attempts to elevate context. The
        // TenantMembershipMiddleware resolves the header via
        // `mases_tenant_id()`, checks membership, and rejects with 403
        // TENANT_ACCESS_DENIED before any controller or permission check
        // runs.
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenantB->id,
        ])->getJson(route('api.wallets.index'));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'TENANT_ACCESS_DENIED');

        // A rejected cross-tenant request must not return a data payload.
        $this->assertNull(
            $response->json('data'),
            'A rejected cross-tenant request must not return a data payload.',
        );

        // Semantic leak check: neither tenant's shareholder name may
        // appear in the response body.
        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString(
            'ሳሙኤል',
            $body,
            'tenantB shareholder name must not leak into a rejected request response.',
        );
        $this->assertStringNotContainsString(
            'አበበ',
            $body,
            'tenantA shareholder name must not appear in a rejected request response either.',
        );
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
     * The projection decrements are performed inline so the fixture
     * state matches what the service would have produced. This matters
     * for the T8 and T9 non-mutation assertions, which must
     * distinguish a rejected request from one that succeeded.
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
     * Create a Wallet-domain permission and attach it to the admin.
     *
     * Named `createWalletPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase. Same
     * convention as 2B.62–2B.66.
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