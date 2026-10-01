<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentReceived;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * PaymentServiceTest
 *
 * Unit tests for PaymentService (2B.17).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Exercises the single public method of PaymentService in isolation:
 *
 *     record()  — record a payment received into a wallet and post the
 *                 corresponding ledger entry.
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * Same rationale as 2B.57 and 2B.58: PaymentService dispatches its event
 * via `DB::afterCommit()`, and RefreshDatabase's wrapping transaction
 * would prevent the after-commit callback from firing before the
 * assertion.
 *
 * =============================================================================
 * Sign convention — the mirror of ObligationService (Architecture §4.2)
 * =============================================================================
 * Recording a payment is a CREDIT of the member's position: a POSITIVE
 * signed quantity on the ledger entry. Unlike ObligationService (2B.16),
 * which NEGATES the caller's positive magnitude before writing the ledger
 * entry, PaymentService (2B.17) PASSES the positive magnitude through
 * unchanged.
 *
 * =============================================================================
 * No component on the payment or its ledger entry
 * =============================================================================
 * A payment is NOT component-tagged (Architecture §3.4, D109). The
 * ledger entry produced by `record()` therefore carries a null
 * `component` column, enforced by WalletService::recordEntry()'s
 * `assertComponentConsistency()` guard (2B.15).
 *
 * =============================================================================
 * Fixture helper naming (v1.0.1 — collision fix)
 * =============================================================================
 * The helper that creates a Tenant is named `makeTenant()` — not
 * `createTenant()`. The base class `Tests\TestCase` declares a protected
 * method `createTenant(array $attributes = [], User|array|null $users = null)`;
 * redeclaring it in a child class with a different signature produces
 * either a fatal access-level error or a `TypeError` at the call site.
 * Renaming to `makeTenant()` avoids the collision.
 *
 * =============================================================================
 * Event-assertion closure (v1.0.1)
 * =============================================================================
 * The `Event::assertDispatched` closure in
 * `test_record_dispatches_payment_received_event` accesses
 * `$this->wallet->id`, so it cannot be declared `static`. v1.0.0 declared
 * it `static fn`, which produced "Using $this when not in object context".
 * v1.0.1 removes the `static` modifier.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.59     — This file
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit
 * @version 1.0.1
 */
final class PaymentServiceTest extends TestCase
{
    use DatabaseMigrations;

    private PaymentService $service;
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

        $this->service       = app(PaymentService::class);
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

    public function test_record_creates_payment_row(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertDatabaseHas('wallet_payments', [
            'id'        => $payment->id,
            'wallet_id' => $this->wallet->id,
            'currency'  => 'ETB',
            'amount'    => '500.0000',
        ]);
    }

    public function test_record_persists_positive_amount(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertSame('500.0000', (string) $payment->amount);
    }

    public function test_record_inherits_currency_from_wallet(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertSame('ETB', (string) $payment->currency);
    }

    public function test_record_initializes_unapplied_to_amount(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertSame(
            (string) $payment->amount,
            (string) $payment->unapplied_amount,
        );
        $this->assertSame('500.0000', (string) $payment->unapplied_amount);
    }

    public function test_record_reports_unapplied_state(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertTrue($payment->isUnapplied());
        $this->assertFalse($payment->isFullyApplied());
        $this->assertFalse($payment->isPartiallyApplied());
    }

    public function test_record_accepts_overpayment_without_error(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '10000.0000',
        );

        $this->assertSame('10000.0000', (string) $payment->unapplied_amount);
        $this->assertTrue($payment->isUnapplied());
    }

    public function test_record_accepts_amount_with_one_decimal(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '100.5',
        );

        $this->assertSame('100.5000', (string) $payment->amount);
    }

    public function test_record_accepts_amount_with_no_decimal(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '100',
        );

        $this->assertSame('100.0000', (string) $payment->amount);
    }

    // =========================================================================
    // record() — ledger entry
    // =========================================================================

    public function test_record_produces_payment_received_ledger_entry(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(WalletEntryType::PaymentReceived, $entry->entry_type);
        $this->assertSame((string) $payment->id, (string) $entry->reference_id);
        $this->assertSame('Payment', (string) $entry->reference_type);
    }

    public function test_record_ledger_entry_carries_positive_amount(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertSame('500.0000', (string) $entry->signed_quantity);
        $this->assertTrue($entry->isCredit());
        $this->assertFalse($entry->isDebit());
    }

    public function test_record_ledger_entry_carries_null_component(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertNull($entry->component);
        $this->assertFalse($entry->hasComponent());
    }

    public function test_record_ledger_entry_satisfies_balance_invariant(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $entry = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->first();

        $this->assertTrue($entry->satisfiesBalanceInvariant());
        $this->assertSame('0.0000', (string) $entry->balance_before);
        $this->assertSame('500.0000', (string) $entry->balance_after);
    }

    public function test_record_updates_wallet_balance_positively(): void
    {
        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->wallet->refresh();
        $this->assertSame('500.0000', (string) $this->wallet->current_balance);
    }

    public function test_multiple_payments_accumulate_positively(): void
    {
        $this->service->record($this->wallet, '500.0000');
        $this->service->record($this->wallet, '300.0000');
        $this->service->record($this->wallet, '150.0000');

        $this->wallet->refresh();
        $this->assertSame('950.0000', (string) $this->wallet->current_balance);
        $this->assertSame(
            3,
            WalletEntry::query()->where('wallet_id', $this->wallet->id)->count(),
        );
    }

    // =========================================================================
    // record() — timestamps
    // =========================================================================

    public function test_record_defaults_received_at_to_now(): void
    {
        $before = Carbon::now()->subSecond();
        $after  = Carbon::now()->addSecond();

        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertTrue($payment->received_at->greaterThanOrEqualTo($before));
        $this->assertTrue($payment->received_at->lessThanOrEqualTo($after));
    }

    public function test_record_preserves_supplied_received_at(): void
    {
        $receivedAt = Carbon::create(2026, 1, 15, 12, 0, 0);

        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
            receivedAt: $receivedAt,
        );

        $this->assertSame(
            $receivedAt->toDateTimeString(),
            $payment->received_at->toDateTimeString(),
        );
    }

    // =========================================================================
    // record() — reference pair
    // =========================================================================

    public function test_record_accepts_complete_reference_pair(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
            referenceType: 'transfer',
            referenceId: '42',
        );

        $this->assertSame('transfer', (string) $payment->reference_type);
        $this->assertSame('42', (string) $payment->reference_id);
        $this->assertTrue($payment->hasReference());
    }

    public function test_record_accepts_absent_reference_pair(): void
    {
        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        $this->assertNull($payment->reference_type);
        $this->assertNull($payment->reference_id);
        $this->assertFalse($payment->hasReference());
    }

    // =========================================================================
    // record() — validation
    // =========================================================================

    public function test_record_rejects_zero_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: '0.0000',
        );
    }

    public function test_record_rejects_negative_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: '-500.0000',
        );
    }

    public function test_record_rejects_malformed_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: 'not-a-number',
        );
    }

    public function test_record_rejects_amount_with_too_many_decimals(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: '100.12345',
        );
    }

    public function test_record_rejects_unpersisted_wallet(): void
    {
        $ghost = new Wallet();

        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $ghost,
            amount: '500.0000',
        );
    }

    public function test_record_rejects_reference_type_without_id(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
            referenceType: 'transfer',
            referenceId: null,
        );
    }

    public function test_record_rejects_reference_id_without_type(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
            referenceType: null,
            referenceId: '42',
        );
    }

    // =========================================================================
    // record() — event
    // =========================================================================

    public function test_record_dispatches_payment_received_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $payment = $this->service->record(
            wallet: $this->wallet,
            amount: '500.0000',
        );

        // The closure accesses `$this->wallet`, so it MUST NOT be static.
        // See the class docblock §"Event-assertion closure (v1.0.1)".
        Event::assertDispatched(
            PaymentReceived::class,
            fn (PaymentReceived $event): bool => $event->paymentId() === (int) $payment->id
                && $event->walletId() === (int) $this->wallet->id
                && $event->amount() === '500.0000',
        );
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
        return $this->walletService->open(
            tenantId: (int) $this->tenant->id,
            shareholder: $this->shareholder,
            currency: 'ETB',
        );
    }
}