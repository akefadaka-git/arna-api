<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletAdjusted;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletEntryReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletOpened;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * WalletServiceTest
 *
 * Unit tests for WalletService (2B.15).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Exercises the four public methods of WalletService in isolation:
 *
 *     open()         — explicit wallet creation (Q4)
 *     adjust()       — operator-initiated balance adjustment (Q3)
 *     reverseEntry() — reversal of a previously posted ledger entry
 *     recordEntry()  — the ledger-write primitive
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * This test uses DatabaseMigrations for two reasons:
 *
 *   1. WalletService dispatches its events via `DB::afterCommit()`. The
 *      after-commit callbacks fire only when the OUTERMOST transaction
 *      commits. RefreshDatabase wraps every test in a transaction that
 *      never commits until the test ends; the after-commit callbacks
 *      would not fire in time to be asserted. DatabaseMigrations runs
 *      migrations fresh per test with no wrapping transaction, so each
 *      service call commits and its events fire immediately.
 *
 *   2. Testing rollback semantics requires commits to be real. A future
 *      test that asserts "a rolled-back operation dispatches no event"
 *      would be impossible under RefreshDatabase.
 *
 * =============================================================================
 * Fixture helper naming (v1.0.2)
 * =============================================================================
 * The helper that creates a Tenant is named `makeTenant()` — not
 * `createTenant()`. The base class `Tests\TestCase` declares a protected
 * method `createTenant(array $attributes = [], User|array|null $users = null)`;
 * redeclaring it in a child class with a different signature produces
 * either a fatal access-level error or a `TypeError` at the call site.
 * Renaming to `makeTenant()` avoids the collision.
 *
 * =============================================================================
 * The `DB` facade import (v1.0.2)
 * =============================================================================
 * `test_record_entry_rejects_wrong_reference_type_for_entry_type` wraps
 * its assertion inside `DB::transaction(...)`. The `DB` facade must be
 * imported at the top of the file — without the import, PHP resolves the
 * bare `DB` symbol against the current namespace and fails with
 * "Class ...\\DB not found". v1.0.1 omitted the import; v1.0.2 adds it.
 *
 * =============================================================================
 * Event assertions
 * =============================================================================
 * The service dispatches three of its seven events from the methods
 * under test in this file:
 *
 *     open()         → WalletOpened
 *     adjust()       → WalletAdjusted
 *     reverseEntry() → WalletEntryReversed
 *
 * Each is asserted with `Event::fake()` + `Event::assertDispatched(...)`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.3    — Corrections (reversal semantics)
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §19     — Q3, Q4, Q6, Q8 resolutions
 *   Step 5 (manifest) 2B.57     — This file
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit
 * @version 1.0.2
 */
final class WalletServiceTest extends TestCase
{
    use DatabaseMigrations;

    private WalletService $service;
    private Tenant $tenant;
    private Tenant $otherTenant;
    private Shareholder $shareholder;
    private Shareholder $otherShareholder;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service = app(WalletService::class);

        $this->tenant      = $this->makeTenant('main-tenant', 'ዋና ቴናንት');
        $this->otherTenant = $this->makeTenant('other-tenant', 'ሌላ ቴናንት');

        $this->shareholder      = $this->createShareholder($this->tenant);
        $this->otherShareholder = $this->createShareholder($this->otherTenant);

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // open() — happy path
    // =========================================================================

    public function test_open_creates_wallet_for_shareholder(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->assertSame((int) $this->tenant->id, (int) $wallet->tenant_id);
        $this->assertSame((int) $this->shareholder->id, (int) $wallet->shareholder_id);
        $this->assertSame('ETB', (string) $wallet->currency);

        $this->assertDatabaseHas('wallets', [
            'id'             => $wallet->id,
            'tenant_id'      => $this->tenant->id,
            'shareholder_id' => $this->shareholder->id,
            'currency'       => 'ETB',
        ]);
    }

    public function test_open_sets_initial_balance_to_zero(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->assertSame('0.0000', (string) $wallet->current_balance);
    }

    public function test_open_sets_is_active_true(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->assertTrue($wallet->is_active);
    }

    public function test_open_sets_opened_at_timestamp(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->assertNotNull($wallet->opened_at);
    }

    public function test_open_uppercases_lowercase_currency(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'etb',
        );

        $this->assertSame('ETB', (string) $wallet->currency);
    }

    public function test_open_creates_no_ledger_entry(): void
    {
        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->assertSame(
            0,
            WalletEntry::query()->where('wallet_id', $wallet->id)->count(),
        );
    }

    // =========================================================================
    // open() — validation
    // =========================================================================

    public function test_open_rejects_duplicate_wallet_for_pair(): void
    {
        $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        $this->expectException(ValidationException::class);

        $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );
    }

    public function test_open_rejects_cross_tenant_shareholder(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->otherShareholder,
            currency: 'ETB',
        );
    }

    public function test_open_rejects_malformed_currency(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ET',
        );
    }

    public function test_open_rejects_unpersisted_shareholder(): void
    {
        $ghost = new Shareholder();

        $this->expectException(ValidationException::class);

        $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $ghost,
            currency: 'ETB',
        );
    }

    // =========================================================================
    // open() — event
    // =========================================================================

    public function test_open_dispatches_wallet_opened_event(): void
    {
        Event::fake([WalletOpened::class]);

        $wallet = $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );

        Event::assertDispatched(
            WalletOpened::class,
            static fn (WalletOpened $event): bool => $event->walletId() === (int) $wallet->id,
        );
    }

    // =========================================================================
    // adjust() — happy path
    // =========================================================================

    public function test_adjust_positive_creates_credit_entry(): void
    {
        $wallet = $this->openWallet();

        $entry = $this->service->adjust(
            wallet: $wallet,
            signedAmount: '+500.0000',
            createdBy: null,
            description: 'Test credit adjustment',
        );

        $this->assertSame(WalletEntryType::Adjustment, $entry->entry_type);
        $this->assertSame('500.0000', (string) $entry->signed_quantity);
        $this->assertTrue($entry->isCredit());
        $this->assertFalse($entry->isDebit());
    }

    public function test_adjust_negative_creates_debit_entry(): void
    {
        $wallet = $this->openWallet();

        $entry = $this->service->adjust(
            wallet: $wallet,
            signedAmount: '-300.0000',
        );

        $this->assertSame(WalletEntryType::Adjustment, $entry->entry_type);
        $this->assertSame('-300.0000', (string) $entry->signed_quantity);
        $this->assertTrue($entry->isDebit());
        $this->assertFalse($entry->isCredit());
    }

    public function test_adjust_updates_wallet_balance(): void
    {
        $wallet = $this->openWallet();

        $this->service->adjust($wallet, '+500.0000');

        $wallet->refresh();
        $this->assertSame('500.0000', (string) $wallet->current_balance);
    }

    public function test_adjust_satisfies_balance_invariant(): void
    {
        $wallet = $this->openWallet();

        $entry = $this->service->adjust($wallet, '+500.0000');

        $this->assertTrue($entry->satisfiesBalanceInvariant());
        $this->assertSame('0.0000', (string) $entry->balance_before);
        $this->assertSame('500.0000', (string) $entry->balance_after);
    }

    public function test_adjust_entry_references_wallet(): void
    {
        $wallet = $this->openWallet();

        $entry = $this->service->adjust($wallet, '+100.0000');

        $this->assertSame('Wallet', (string) $entry->reference_type);
        $this->assertSame((string) $wallet->id, (string) $entry->reference_id);
    }

    // =========================================================================
    // adjust() — validation
    // =========================================================================

    public function test_adjust_rejects_zero_amount(): void
    {
        $wallet = $this->openWallet();

        $this->expectException(ValidationException::class);

        $this->service->adjust($wallet, '0.0000');
    }

    public function test_adjust_rejects_malformed_amount(): void
    {
        $wallet = $this->openWallet();

        $this->expectException(ValidationException::class);

        $this->service->adjust($wallet, 'not-a-number');
    }

    public function test_adjust_rejects_unpersisted_wallet(): void
    {
        $ghost = new Wallet();

        $this->expectException(ValidationException::class);

        $this->service->adjust($ghost, '+100.0000');
    }

    // =========================================================================
    // adjust() — event
    // =========================================================================

    public function test_adjust_dispatches_wallet_adjusted_event(): void
    {
        Event::fake([WalletAdjusted::class]);

        $wallet = $this->openWallet();
        $entry = $this->service->adjust($wallet, '+250.0000');

        Event::assertDispatched(
            WalletAdjusted::class,
            static fn (WalletAdjusted $event): bool => $event->entryId() === (int) $entry->id,
        );
    }

    // =========================================================================
    // reverseEntry() — happy path
    // =========================================================================

    public function test_reverse_entry_creates_reversal_entry(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $reversal = $this->service->reverseEntry($original);

        $this->assertSame(WalletEntryType::Reversal, $reversal->entry_type);
        $this->assertSame('-500.0000', (string) $reversal->signed_quantity);
    }

    public function test_reverse_entry_links_to_original(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $reversal = $this->service->reverseEntry($original);

        $this->assertSame((int) $original->id, (int) $reversal->reversed_entry_id);
        $this->assertSame('WalletEntry', (string) $reversal->reference_type);
        $this->assertSame((string) $original->id, (string) $reversal->reference_id);
    }

    public function test_reverse_entry_updates_wallet_balance_back(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $this->service->reverseEntry($original);

        $wallet->refresh();
        $this->assertSame('0.0000', (string) $wallet->current_balance);
    }

    public function test_reverse_entry_satisfies_balance_invariant(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $reversal = $this->service->reverseEntry($original);

        $this->assertTrue($reversal->satisfiesBalanceInvariant());
        $this->assertSame('500.0000', (string) $reversal->balance_before);
        $this->assertSame('0.0000', (string) $reversal->balance_after);
    }

    // =========================================================================
    // reverseEntry() — validation
    // =========================================================================

    public function test_reverse_entry_rejects_reversal_of_reversal(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');
        $reversal = $this->service->reverseEntry($original);

        $this->expectException(ValidationException::class);

        $this->service->reverseEntry($reversal);
    }

    public function test_reverse_entry_rejects_double_reversal(): void
    {
        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $this->service->reverseEntry($original);

        $this->expectException(ValidationException::class);

        $this->service->reverseEntry($original);
    }

    public function test_reverse_entry_rejects_unpersisted_entry(): void
    {
        $ghost = new WalletEntry();

        $this->expectException(ValidationException::class);

        $this->service->reverseEntry($ghost);
    }

    // =========================================================================
    // reverseEntry() — event
    // =========================================================================

    public function test_reverse_entry_dispatches_wallet_entry_reversed_event(): void
    {
        Event::fake([WalletEntryReversed::class]);

        $wallet = $this->openWallet();
        $original = $this->service->adjust($wallet, '+500.0000');

        $reversal = $this->service->reverseEntry($original);

        Event::assertDispatched(
            WalletEntryReversed::class,
            static fn (WalletEntryReversed $event): bool => $event->entryId() === (int) $reversal->id
                && $event->reversedEntryId() === (int) $original->id,
        );
    }

    // =========================================================================
    // recordEntry() — transaction guard
    // =========================================================================

    public function test_record_entry_rejects_call_outside_transaction(): void
    {
        $wallet = $this->openWallet();

        $this->expectException(LogicException::class);

        $this->service->recordEntry(
            wallet: $wallet,
            signedQuantity: '+100.0000',
            entryType: WalletEntryType::Adjustment,
            referenceType: 'Wallet',
            referenceId: (string) $wallet->id,
        );
    }

    public function test_record_entry_rejects_wrong_reference_type_for_entry_type(): void
    {
        $wallet = $this->openWallet();

        $this->expectException(ValidationException::class);

        DB::transaction(function () use ($wallet): void {
            $this->service->recordEntry(
                wallet: $wallet,
                signedQuantity: '+100.0000',
                entryType: WalletEntryType::Adjustment,
                referenceType: 'Payment',
                referenceId: (string) $wallet->id,
            );
        });
    }

    // =========================================================================
    // Projection invariant
    // =========================================================================

    public function test_wallet_projection_matches_latest_entry_balance(): void
    {
        $wallet = $this->openWallet();

        $this->service->adjust($wallet, '+500.0000');
        $this->service->adjust($wallet, '-200.0000');
        $this->service->adjust($wallet, '+50.0000');

        $wallet->refresh();

        /** @var WalletEntry $latest */
        $latest = WalletEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->first();

        $this->assertSame(
            (string) $latest->balance_after,
            (string) $wallet->current_balance,
        );
        $this->assertSame('350.0000', (string) $wallet->current_balance);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * Create a tenant.
     *
     * Named `makeTenant()` — not `createTenant()` — to avoid a signature
     * collision with the protected method declared on the base class.
     */
    private function makeTenant(string $slug, string $nameAm): Tenant
    {
        return Tenant::create([
            'slug'      => $slug,
            'name_am'   => $nameAm,
            'name_en'   => ucfirst(str_replace('-', ' ', $slug)),
            'domain'    => $slug . '.example.test',
            'is_active' => true,
        ]);
    }

    private function createShareholder(Tenant $tenant): Shareholder
    {
        return Shareholder::create([
            'tenant_id'        => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_am'    => 'አበበ',
            'father_name_am'   => 'ከበደ',
            'is_active'        => true,
        ]);
    }

    private function openWallet(): Wallet
    {
        return $this->service->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );
    }
}