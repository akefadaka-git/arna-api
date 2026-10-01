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
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * StatementTest
 *
 * Feature tests for the wallet statement projection's HTTP surface.
 *
 * =============================================================================
 * Endpoint covered
 * =============================================================================
 * The single route of the StatementController (2B.30), mapped by the
 * routes file (2B.48) per Step 7 v1.1.0 §C.1 route #9:
 *
 *     GET /wallets/{wallet}/statement  → show()  generate statement
 *
 * URLs are resolved via the `route('api.wallets.statement.show')` helper.
 *
 * =============================================================================
 * Policy-only authorization — the S7-A amendment in action
 * =============================================================================
 * This is one of only two routes in the Wallet domain with NO route-level
 * permission middleware. The other is `show` on the wallet itself
 * (2B.62, route #3). The reasoning (Step 7 v1.1.0 §C.1.1):
 *
 *   - The statement endpoint has TWO permission paths:
 *
 *         wallet.statement.view       — broad, tenant-wide
 *         wallet.statement.view_own   — own-wallet only
 *
 *   - A route-level `wallet.statement.view` middleware would reject a
 *     manager holding ONLY `wallet.statement.view_own` — before the
 *     policy could evaluate ownership.
 *
 *   - The endpoint therefore delegates BOTH paths to the policy layer:
 *     `Gate::authorize('viewStatement', $wallet)`.
 *
 * Five fixtures exercise the five observable authorization states:
 *
 *   admin      — holds `wallet.statement.view`               → 200 (broad)
 *   manager    — holds `wallet.statement.view_own` and owns  → 200 (own)
 *                the wallet
 *   stranger   — holds `wallet.statement.view_own` but does  → 403
 *                NOT own the wallet
 *   viewerOnly — holds `wallet.view` only (no statement       → 403
 *                permission of either kind)
 *   noPerm     — holds nothing                                → 403
 *
 * The fourth fixture is the most important: it proves that holding the
 * wallet's own view permission does NOT imply statement access. The two
 * permission pairs are independent (Step 7 v1.1.0 §C.1.1, S7-A).
 *
 * =============================================================================
 * RefreshDatabase, not DatabaseMigrations
 * =============================================================================
 * The statement endpoint is READ-ONLY: it dispatches no events, opens no
 * transaction, and writes no rows (2B.19 §"Read-only service"). The
 * feature suite therefore has no after-commit assertions to make, and
 * `RefreshDatabase` — the default per §2.13 — is both correct and
 * faster than `DatabaseMigrations`.
 *
 * The fixture setup builds wallet and ledger rows directly through the
 * models, bypassing the service layer entirely, so no after-commit
 * callbacks are ever registered.
 *
 * =============================================================================
 * Response envelope — no StatementResource
 * =============================================================================
 * The service (2B.19) returns a plain array with the frozen schema
 * documented in its `generate()` docblock. The controller (2B.30) wraps
 * it in the standard envelope without a Resource wrapper — there is no
 * `StatementResource` in the 2B manifest, and the projection is already
 * a value object with a stable shape.
 *
 * Success responses therefore nest the projection under `data.*` (CR-003a):
 *
 *     data.wallet_id
 *     data.currency
 *     data.period.from
 *     data.period.to
 *     data.opening_balance
 *     data.period_entries       (array)
 *     data.debit_total
 *     data.credit_total
 *     data.closing_balance
 *     data.generated_at
 *     data.generated_by
 *     data.provenance
 *
 * =============================================================================
 * Period query parameters
 * =============================================================================
 * The `from` and `to` query parameters are validated inline by the
 * controller (2B.30 §"Period parameters") via `$request->validate()`,
 * not by a dedicated FormRequest. Both are required and must be
 * parseable date-times. The period is interpreted as `[from, to)`.
 *
 * =============================================================================
 * Fixture helper naming
 * =============================================================================
 * `createWalletPermission()` — not `createPermission()` — to avoid the
 * collision with the protected method declared on Tests\TestCase.
 *
 * The `makeLedgerEntry()` fixture is the only fixture helper in the 2B
 * suite that manages the balance-invariant chain: each entry's
 * `balance_before` is the previous entry's `balance_after`. This is
 * required because the statement service reads the chain, not just the
 * individual rows.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D113                         — Statement model
 *   Architecture v1.2.0 §3.7    — WalletEntry ledger table
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §11     — Statement projection boundary
 *   Architecture v1.2.0 §11.1   — Projection, not materialization
 *   Architecture v1.2.0 §11.2   — Content (per D113)
 *   Architecture v1.2.0 §11.4   — Debit / credit column definitions
 *   Architecture v1.2.0 §12     — Tenant isolation
 *   Architecture v1.2.0 §13     — RBAC integration
 *   Step 5 (manifest) 2B.66     — This file
 *   Step 7 v1.1.0 §A.1.1        — Permission slugs
 *   Step 7 v1.1.0 §A.1.2        — F-2B-RBAC-1 resolution (choice c)
 *   Step 7 v1.1.0 §C.1          — Nine-route API structure (route #9)
 *   Step 7 v1.1.0 §C.1.1        — Statement policy-only authorization (S7-A)
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T12)
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Feature
 * @version 1.0.0
 */
final class StatementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $admin;
    private User $manager;
    private User $stranger;
    private User $viewerOnly;
    private User $noPerm;
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
        // Five users, five authorization states.
        // ---------------------------------------------------------------------
        $this->admin = $this->makeUser('admin');
        $this->manager = $this->makeUser('manager');
        $this->stranger = $this->makeUser('stranger');
        $this->viewerOnly = $this->makeUser('vieweronly');
        $this->noPerm = $this->makeUser('noperm');

        // ---------------------------------------------------------------------
        // Permissions.
        //
        //   wallet.view            → admin (not needed for statement)
        //   wallet.statement.view  → admin (broad path)
        //   wallet.statement.view_own → manager, stranger (own path)
        //
        // `viewerOnly` receives `wallet.view` only; it does NOT receive
        // either statement permission. This proves that wallet-detail
        // access does not imply statement access.
        // ---------------------------------------------------------------------
        $this->createWalletPermission('wallet.view',             'View wallets',           [$this->admin, $this->viewerOnly]);
        $this->createWalletPermission('wallet.statement.view',   'View statements',        [$this->admin]);

        $ownPermission = $this->createWalletPermission(
            'wallet.statement.view_own',
            'View own wallet statement',
            [$this->manager, $this->stranger],
        );

        foreach ([$this->admin, $this->manager, $this->stranger, $this->viewerOnly, $this->noPerm] as $user) {
            $user->load('permissions');
        }

        // ---------------------------------------------------------------------
        // Shareholders and the primary wallet.
        //
        // The `manager` user is linked to the shareholder via the
        // shareholder's `user_id` column, which is the "direct self-service"
        // link (2A.7 §"user"). This is the ownership path that
        // WalletPolicy::userOwnsWallet() consults.
        // ---------------------------------------------------------------------
        $this->shareholder = Shareholder::create([
            'tenant_id'        => $this->tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'user_id'          => $this->manager->id,
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
    // Authentication
    // =========================================================================

    public function test_statement_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('error.code', 'AUTH_UNAUTHENTICATED');
    }

    // =========================================================================
    // Authorization — the five states
    // =========================================================================

    public function test_statement_grants_broad_permission(): void
    {
        // The admin holds `wallet.statement.view`. The policy grants
        // through the broad path.
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_statement_grants_own_only_permission_when_user_owns_wallet(): void
    {
        // The manager holds only `wallet.statement.view_own` (not the
        // broad variant). Because the wallet's shareholder has
        // `user_id = manager.id`, the policy grants through the own-only
        // path. This is the S7-A amendment in action: a route-level
        // `wallet.statement.view` middleware would have rejected this
        // caller with a 403 before the policy ran.
        Sanctum::actingAs($this->manager, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_statement_denies_own_only_when_user_does_not_own_wallet(): void
    {
        // The stranger holds `wallet.statement.view_own` but does NOT own
        // the wallet (the shareholder's `user_id` points at the manager,
        // not the stranger). The policy's own-only path fails.
        Sanctum::actingAs($this->stranger, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    public function test_statement_view_does_not_imply_statement_access(): void
    {
        // `viewerOnly` holds `wallet.view` (the wallet-detail permission)
        // but neither `wallet.statement.view` nor
        // `wallet.statement.view_own`. The two permission pairs are
        // independent (Step 7 v1.1.0 §C.1.1).
        Sanctum::actingAs($this->viewerOnly, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    public function test_statement_denies_user_without_any_permission(): void
    {
        Sanctum::actingAs($this->noPerm, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Happy path — content
    // =========================================================================

    public function test_statement_returns_empty_projection_for_empty_period(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.wallet_id', $this->wallet->id);
        $response->assertJsonPath('data.currency', 'ETB');
        $response->assertJsonPath('data.opening_balance', '0.0000');
        $response->assertJsonPath('data.closing_balance', '0.0000');
        $response->assertJsonPath('data.debit_total', '0.0000');
        $response->assertJsonPath('data.credit_total', '0.0000');
        $response->assertJsonPath('data.period_entries', []);
    }

    public function test_statement_returns_entries_in_period(): void
    {
        $this->makeLedgerEntry(
            wallet: $this->wallet,
            signedQuantity: '500.0000',
            occurredAt: Carbon::create(2026, 1, 15, 10, 0, 0),
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.period_entries');
        $response->assertJsonPath('data.period_entries.0.signed_quantity', '500.0000');
        $response->assertJsonPath('data.period_entries.0.credit', '500.0000');
        $response->assertJsonPath('data.period_entries.0.debit', '0.0000');
    }

    public function test_statement_debit_and_credit_totals(): void
    {
        $this->makeLedgerEntry($this->wallet, '500.0000', Carbon::create(2026, 1, 5, 10));
        $this->makeLedgerEntry($this->wallet, '-300.0000', Carbon::create(2026, 1, 10, 10));
        $this->makeLedgerEntry($this->wallet, '200.0000', Carbon::create(2026, 1, 15, 10));

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.debit_total', '300.0000');
        $response->assertJsonPath('data.credit_total', '700.0000');
        $response->assertJsonCount(3, 'data.period_entries');
    }

    public function test_statement_opening_and_closing_balances(): void
    {
        // A pre-period entry sets the opening balance.
        $this->makeLedgerEntry($this->wallet, '1000.0000', Carbon::create(2025, 12, 15, 10));
        // In-period entries: 500 credit, 300 debit → closing = 1200.
        $this->makeLedgerEntry($this->wallet, '500.0000', Carbon::create(2026, 1, 5, 10));
        $this->makeLedgerEntry($this->wallet, '-300.0000', Carbon::create(2026, 1, 15, 10));

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.opening_balance', '1000.0000');
        $response->assertJsonPath('data.closing_balance', '1200.0000');
    }

    public function test_statement_carries_currency(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.currency', 'ETB');
    }

    // =========================================================================
    // Period boundaries — [from, to)
    // =========================================================================

    public function test_statement_entry_at_from_is_included(): void
    {
        $this->makeLedgerEntry(
            $this->wallet,
            '500.0000',
            Carbon::create(2026, 1, 1, 0, 0, 0),
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.period_entries');
    }

    public function test_statement_entry_at_to_is_excluded(): void
    {
        $this->makeLedgerEntry(
            $this->wallet,
            '500.0000',
            Carbon::create(2026, 2, 1, 0, 0, 0),
        );

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.period_entries');
        $response->assertJsonPath('data.credit_total', '0.0000');
    }

    // =========================================================================
    // Read-only invariant
    // =========================================================================

    public function test_statement_does_not_change_wallet_balance(): void
    {
        $this->makeLedgerEntry($this->wallet, '500.0000', Carbon::create(2026, 1, 5, 10));

        $this->wallet->refresh();
        $before = (string) $this->wallet->current_balance;

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]))->assertStatus(200);

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_statement_does_not_write_any_rows(): void
    {
        $this->makeLedgerEntry($this->wallet, '500.0000', Carbon::create(2026, 1, 5, 10));

        $walletCount = Wallet::query()->count();
        $entryCount  = WalletEntry::query()->count();

        Sanctum::actingAs($this->admin, ['*']);

        $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]))->assertStatus(200);

        // Statement generation is a pure projection; no rows are written.
        $this->assertSame($walletCount, Wallet::query()->count());
        $this->assertSame($entryCount, WalletEntry::query()->count());
    }

    // =========================================================================
    // Validation — query parameters
    // =========================================================================

    public function test_statement_requires_from_parameter(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['from']]);
    }

    public function test_statement_requires_to_parameter(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['to']]);
    }

    public function test_statement_rejects_malformed_from(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => 'not-a-date',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['from']]);
    }

    public function test_statement_rejects_inverted_period(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-02-01T00:00:00+03:00',
            'to'     => '2026-01-01T00:00:00+03:00',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['period']]);
    }

    public function test_statement_rejects_same_instant_period(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $moment = '2026-01-15T12:00:00+03:00';

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => $moment,
            'to'     => $moment,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['errors' => ['period']]);
    }

    // =========================================================================
    // Tenant scoping — T12
    // =========================================================================

    public function test_statement_returns_404_for_cross_tenant_wallet_T12(): void
    {
        $foreignWallet = $this->openWalletFor($this->otherShareholder);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $foreignWallet->id,
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

    public function test_statement_returns_404_for_missing_wallet(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => 999999,
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
    // Response shape
    // =========================================================================

    public function test_statement_response_has_expected_envelope_shape(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+03:00',
            'to'     => '2026-02-01T00:00:00+03:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'wallet_id',
                'currency',
                'period' => ['from', 'to'],
                'opening_balance',
                'period_entries',
                'debit_total',
                'credit_total',
                'closing_balance',
                'generated_at',
                'generated_by',
                'provenance' => [
                    'wallet_id',
                    'period_from',
                    'period_to',
                    'ledger_max_entry_id',
                    'generator',
                    'generator_version',
                ],
            ],
        ]);
    }

    public function test_statement_accepts_iso8601_parameters(): void
    {
        // The controller's inline `date` rule accepts any format that
        // `strtotime` can parse. This test pins the canonical ISO 8601
        // form with an explicit timezone offset.
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->withHeaders([
            'X-Tenant-ID' => (string) $this->tenant->id,
        ])->getJson(route('api.wallets.statement.show', [
            'wallet' => $this->wallet->id,
            'from'   => '2026-01-01T00:00:00+00:00',
            'to'     => '2026-02-01T00:00:00+00:00',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a user attached to the primary tenant.
     *
     * The user's email is deterministic per role so that the fixture list
     * is readable in test failures.
     */
    private function makeUser(string $role): User
    {
        $user = User::factory()->create([
            'email' => $role . '-' . uniqid() . '@example.test',
        ]);
        $user->forceFill(['default_tenant_id' => $this->tenant->id])->save();
        $user->tenants()->attach($this->tenant->id, ['joined_at' => now()]);

        return $user;
    }

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
     * Create a ledger entry directly, maintaining the balance-invariant
     * chain.
     *
     * Each entry's `balance_before` is the wallet's current projected
     * balance; each entry's `balance_after` is the sum. The wallet's
     * `current_balance` is updated after each entry so that the next
     * call to this helper reads the correct starting balance.
     *
     * This chain discipline is required because the statement service
     * (2B.19) reads the ledger's `balance_before` / `balance_after`
     * columns directly — a fixture that produced a broken chain would
     * produce statements with nonsense opening / closing balances.
     *
     * The `entry_type` is always `adjustment` with `reference_type =
     * 'Wallet'` (the permitted pairing per Architecture §5.3). The
     * statement projection renders these fields as-is; the choice is
     * irrelevant to the arithmetic.
     */
    private function makeLedgerEntry(
        Wallet $wallet,
        string $signedQuantity,
        Carbon $occurredAt,
    ): WalletEntry {
        $wallet->refresh();
        $balanceBefore = (string) $wallet->current_balance;
        $balanceAfter  = bcadd($balanceBefore, $signedQuantity, 4);

        $entry = WalletEntry::create([
            'wallet_id'       => $wallet->id,
            'entry_type'      => 'adjustment',
            'component'       => null,
            'signed_quantity' => $signedQuantity,
            'balance_before'  => $balanceBefore,
            'balance_after'   => $balanceAfter,
            'reference_type'  => 'Wallet',
            'reference_id'    => (string) $wallet->id,
            'reversed_entry_id' => null,
            'occurred_at'     => $occurredAt,
            'created_at'      => $occurredAt,
            'created_by'      => null,
            'description'     => null,
        ]);

        $wallet->current_balance = $balanceAfter;
        $wallet->save();

        return $entry;
    }

    /**
     * Create a Wallet-domain permission and attach it to the given users.
     *
     * The `$recipients` parameter is a list of User models to attach the
     * permission to. Different tests need different permission
     * distributions; the array form keeps the seeder-style block
     * readable.
     *
     * Named `createWalletPermission()` — not `createPermission()` — to
     * avoid a method-name collision with the base Tests\TestCase.
     *
     * @param array<int, User> $recipients
     */
    private function createWalletPermission(string $slug, string $label, array $recipients = []): Permission
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

        foreach ($recipients as $user) {
            $user->permissions()->attach($permission->id, ['assigned_at' => now()]);
        }

        return $permission;
    }
}