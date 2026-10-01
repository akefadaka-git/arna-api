<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationRecorded;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * ObligationServiceTest
 *
 * Unit tests for ObligationService (2B.16).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Exercises the single public method of ObligationService in isolation:
 *
 *     record()  — record an obligation against a wallet and post the
 *                 corresponding ledger entry.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * Same rationale as 2B.57 (WalletServiceTest): ObligationService
 * dispatches its event via `DB::afterCommit()`, and RefreshDatabase's
 * wrapping transaction would prevent the after-commit callback from
 * firing before the assertions.
 *
 * =============================================================================
 * Two-row operations
 * =============================================================================
 * Every successful `record()` produces TWO rows in one transaction:
 *
 *   1. An `Obligation` row (2B.50).
 *   2. A `WalletEntry` row (2B.54) of type `obligation_recorded`.
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * Recording an obligation is a DEBIT of the member's position: a NEGATIVE
 * signed quantity on the ledger entry. The caller supplies a POSITIVE
 * magnitude; the service negates it at the ledger-write boundary.
 *
 * =============================================================================
 * Fixture helper naming (v1.0.1 — collision fix)
 * =============================================================================
 * The helper that creates a Tenant is named `makeTenant()` — not
 * `createTenant()` — to avoid a signature collision with the base class
 * `Tests\TestCase`.
 *
 * =============================================================================
 * Event-assertion closure (v1.0.2)
 * =============================================================================
 * The `Event::assertDispatched` closure in
 * `test_record_dispatches_obligation_recorded_event` accesses
 * `$this->wallet->id`, so it cannot be declared `static`. v1.0.1 declared
 * it `static fn`, which produced "Using $this when not in object context".
 * v1.0.2 removes the `static` modifier.
 *
 * The sibling event-assertion tests in 2B.57, 2B.59, and 2B.60 use only
 * locally-captured variables inside their closures and are correctly
 * `static fn`; only this specific assertion needed `$this`.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.58     — This file
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit
 * @version 1.0.2
 */
final class ObligationServiceTest extends TestCase
{
    use DatabaseMigrations;

    private ObligationService $service;
    private WalletService $walletService;
    private Tenant $tenant;
    private Shareholder $shareholder;
    private Wallet $wallet;

    // =========================================================================
    // Lifecycle
    // =========================================================================

    protected function setUp(): void
    {
        gc_collect_cycles();

        parent::setUp();

        $this->service       = app(ObligationService::class);
        $this->walletService = app(WalletService::class);

        $this->tenant      = $this->makeTenant('main-tenant', 'ዋና ቴናንት');
        $this->shareholder = $this->createShareholder($this->tenant);
        $this->wallet      = $this->openWallet();

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // record() — happy path
    // =========================================================================

    public function test_record_creates_obligation_row(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertDatabaseHas('wallet_obligations', [
            'id'         => $obligation->id,
            'wallet_id'  => $this->wallet->id,
            'component'  => 'principal',
            'currency'   => 'ETB',
        ]);
    }

    public function test_record_persists_component_enum_value(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Interest,
            amount: '250.0000',
        );

        $this->assertSame(ObligationComponent::Interest, $obligation->component);
        $this->assertSame('interest', $obligation->component->value);
    }

    public function test_record_inherits_currency_from_wallet(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Fee,
            amount: '100.0000',
        );

        $this->assertSame('ETB', (string) $obligation->currency);
    }

    public function test_record_initializes_outstanding_to_original(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertSame(
            (string) $obligation->original_amount,
            (string) $obligation->outstanding_amount,
        );
        $this->assertSame('500.0000', (string) $obligation->outstanding_amount);
    }

    public function test_record_reports_open_state(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertTrue($obligation->isOpen());
        $this->assertFalse($obligation->isSettled());
        $this->assertFalse($obligation->isPartiallySettled());
    }

    public function test_record_accepts_all_six_components(): void
    {
        $components = [
            ObligationComponent::Principal,
            ObligationComponent::Interest,
            ObligationComponent::Fee,
            ObligationComponent::Penalty,
            ObligationComponent::Tax,
            ObligationComponent::Other,
        ];

        foreach ($components as $index => $component) {
            $obligation = $this->service->record(
                wallet: $this->wallet,
                component: $component,
                amount: '10.0000',
                description: 'Component #' . $index,
            );

            $this->assertSame($component, $obligation->component);
        }

        $this->assertSame(
            6,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // record() — ledger entry
    // =========================================================================

    public function test_record_produces_obligation_recorded_ledger_entry(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(WalletEntryType::ObligationRecorded, $entry->entry_type);
        $this->assertSame((string) $obligation->id, (string) $entry->reference_id);
        $this->assertSame('Obligation', (string) $entry->reference_type);
    }

    public function test_record_ledger_entry_carries_negated_amount(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertSame('-500.0000', (string) $entry->signed_quantity);
        $this->assertTrue($entry->isDebit());
        $this->assertFalse($entry->isCredit());
    }

    public function test_record_ledger_entry_carries_component(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Penalty,
            amount: '25.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertTrue($entry->hasComponent());
        $this->assertSame(ObligationComponent::Penalty, $entry->component);
    }

    public function test_record_ledger_entry_satisfies_balance_invariant(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertTrue($entry->satisfiesBalanceInvariant());
        $this->assertSame('0.0000', (string) $entry->balance_before);
        $this->assertSame('-500.0000', (string) $entry->balance_after);
    }

    public function test_record_updates_wallet_balance_negatively(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->wallet->refresh();
        $this->assertSame('-500.0000', (string) $this->wallet->current_balance);
    }

    public function test_multiple_obligations_accumulate_negatively(): void
    {
        $this->service->record($this->wallet, ObligationComponent::Principal, '500.0000');
        $this->service->record($this->wallet, ObligationComponent::Interest, '50.0000');
        $this->service->record($this->wallet, ObligationComponent::Fee, '25.0000');

        $this->wallet->refresh();
        $this->assertSame('-575.0000', (string) $this->wallet->current_balance);
        $this->assertSame(
            3,
            WalletEntry::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // record() — timestamps
    // =========================================================================

    public function test_record_defaults_occurred_at_to_now(): void
    {
        $before = Carbon::now()->subSecond();
        $after  = Carbon::now()->addSecond();

        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertTrue($obligation->occurred_at->greaterThanOrEqualTo($before));
        $this->assertTrue($obligation->occurred_at->lessThanOrEqualTo($after));
    }

    public function test_record_preserves_supplied_occurred_at(): void
    {
        $occurredAt = Carbon::create(2026, 1, 15, 12, 0, 0);

        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
            occurredAt: $occurredAt,
        );

        $this->assertSame(
            $occurredAt->toDateTimeString(),
            $obligation->occurred_at->toDateTimeString(),
        );
    }

    public function test_record_accepts_null_due_at(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertNull($obligation->due_at);
        $this->assertFalse($obligation->hasDueDate());
    }

    public function test_record_preserves_supplied_due_at(): void
    {
        $dueAt = Carbon::create(2026, 12, 31, 23, 59, 59);

        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
            dueAt: $dueAt,
        );

        $this->assertSame(
            $dueAt->toDateTimeString(),
            $obligation->due_at->toDateTimeString(),
        );
        $this->assertTrue($obligation->hasDueDate());
    }

    // =========================================================================
    // record() — reference pair
    // =========================================================================

    public function test_record_accepts_complete_reference_pair(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
            referenceType: 'loan',
            referenceId: '42',
        );

        $this->assertSame('loan', (string) $obligation->reference_type);
        $this->assertSame('42', (string) $obligation->reference_id);
        $this->assertTrue($obligation->hasReference());
    }

    public function test_record_accepts_absent_reference_pair(): void
    {
        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        $this->assertNull($obligation->reference_type);
        $this->assertNull($obligation->reference_id);
        $this->assertFalse($obligation->hasReference());
    }

    // =========================================================================
    // record() — validation
    // =========================================================================

    public function test_record_rejects_zero_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '0.0000',
        );
    }

    public function test_record_rejects_negative_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '-500.0000',
        );
    }

    public function test_record_rejects_malformed_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: 'not-a-number',
        );
    }

    public function test_record_rejects_amount_with_too_many_decimals(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '100.12345',
        );
    }

    public function test_record_rejects_unpersisted_wallet(): void
    {
        $ghost = new Wallet();

        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $ghost,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );
    }

    public function test_record_rejects_reference_type_without_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
            referenceType: 'loan',
            referenceId: null,
        );
    }

    public function test_record_rejects_reference_id_without_type(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
            referenceType: null,
            referenceId: '42',
        );
    }

    public function test_record_rolls_back_obligation_on_ledger_failure(): void
    {
        $countBefore = Obligation::query()->where('wallet_id', $this->wallet->id)->count();
        $entryBefore = WalletEntry::query()->where('wallet_id', $this->wallet->id)->count();

        try {
            $this->service->record(
                wallet: $this->wallet,
                component: ObligationComponent::Principal,
                amount: '500.0000',
                referenceType: 'loan',
                referenceId: null,
            );
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(
            $countBefore,
            Obligation::query()->where('wallet_id', $this->wallet->id)->count(),
        );
        $this->assertSame(
            $entryBefore,
            WalletEntry::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // record() — event
    // =========================================================================

    public function test_record_dispatches_obligation_recorded_event(): void
    {
        Event::fake([ObligationRecorded::class]);

        $obligation = $this->service->record(
            wallet: $this->wallet,
            component: ObligationComponent::Principal,
            amount: '500.0000',
        );

        // The closure accesses `$this->wallet`, so it MUST NOT be static.
        // See the class docblock §"Event-assertion closure (v1.0.2)".
        Event::assertDispatched(
            ObligationRecorded::class,
            fn (ObligationRecorded $event): bool => $event->obligationId() === (int) $obligation->id
                && $event->walletId() === (int) $this->wallet->id
                && $event->component() === ObligationComponent::Principal,
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

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
        return $this->walletService->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );
    }
}