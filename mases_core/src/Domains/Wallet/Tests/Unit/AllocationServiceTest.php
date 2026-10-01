<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocated;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocationReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocationReversal;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Services\AllocationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\ObligationService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\PaymentService;
use MaaleArna\MASES\Core\Domains\Wallet\Services\WalletService;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * AllocationServiceTest
 *
 * Unit tests for AllocationService (2B.18).
 *
 * =============================================================================
 * Scope
 * =============================================================================
 * Exercises the two public methods of AllocationService in isolation:
 *
 *     allocate()  — record an explicit allocation of a payment to an
 *                   obligation.
 *
 *     reverse()   — record the reversal of an existing allocation.
 *
 * AllocationService is the most dependency-heavy service in the Wallet
 * domain: it injects three repositories (`PaymentRepository`,
 * `ObligationRepository`, `AllocationRepository`), and its operations
 * update TWO projection columns on different tables
 * (`payment.unapplied_amount`, `obligation.outstanding_amount`).
 *
 * =============================================================================
 * DatabaseMigrations, not RefreshDatabase
 * =============================================================================
 * Same rationale as 2B.57–2B.59: both operations dispatch their events
 * via `DB::afterCommit()`, and RefreshDatabase's wrapping transaction
 * would prevent the after-commit callbacks from firing before the
 * assertions.
 *
 * =============================================================================
 * No ledger entries — allocations are attribution, not money
 * =============================================================================
 * Neither `allocate()` nor `reverse()` produces a `wallet_entries` row.
 * This is the central architectural fact that separates attribution from
 * monetary movement (Architecture §5.1). Four tests verify the "no
 * ledger effect" invariant explicitly, two per method.
 *
 * =============================================================================
 * Cross-tenant protection — the §C.6 invariant
 * =============================================================================
 * AllocationService is where the Step 7 v1.1.0 §C.6 cross-tenant
 * protection is most load-bearing. The service resolves `payment_id` and
 * `obligation_id` through their wallet-scoped repositories before opening
 * its transaction. A cross-wallet (or non-existent) id yields `null`
 * from the repository, and the service throws
 * `ModelNotFoundException`.
 *
 * =============================================================================
 * Fixture helper naming (v1.0.1 — collision fix)
 * =============================================================================
 * The helper that creates a Tenant is named `makeTenant()` — not
 * `createTenant()`. The base class `Tests\TestCase` declares a protected
 * method `createTenant(array $attributes = [], User|array|null $users = null)`;
 * redeclaring it in a child class with a different signature produces
 * either a fatal access-level error or a `TypeError` at the call site.
 *
 * =============================================================================
 * Event-assertion closures (v1.0.1)
 * =============================================================================
 * Two event-dispatch tests use assertion closures that access
 * `$this->wallet->id`. Those closures therefore cannot be declared
 * `static`. The v1.0.0 delivery declared them `static fn`, which produced
 * "Using $this when not in object context". v1.0.1 removes the `static`
 * modifier on both closures.
 *
 * =============================================================================
 * Refreshing before capturing the wallet balance (v1.0.2)
 * =============================================================================
 * `test_allocate_does_not_change_wallet_balance` and
 * `test_reverse_does_not_change_wallet_balance` capture the wallet's
 * `current_balance` into a `$before` variable, run the operation under
 * test, and assert the balance is unchanged.
 *
 * The setUp fixtures create the obligation and the payment by calling
 * `ObligationService::record()` and `PaymentService::record()`. Those
 * calls mutate the wallet's `current_balance` COLUMN in the database
 * (obligation −1000, payment +500 → net −500) but do NOT mutate the
 * in-memory `$this->wallet` model — the model's `current_balance`
 * property still holds the value it was hydrated with at creation
 * (`0.0000`).
 *
 * The v1.0.1 delivery captured `$before` from the STALE in-memory model,
 * so `$before = '0.0000'` while the fresh database read returned
 * `'-500.0000'`. The assertion failed even though the behaviour under
 * test (allocate / reverse do not touch the wallet balance) was
 * correct.
 *
 * The v1.0.2 correction adds `$this->wallet->refresh();` BEFORE capturing
 * `$before` in both tests. After refresh, the in-memory model reflects
 * the database state, and `$before` captures the true pre-operation
 * balance. The test then verifies that the operation under test does not
 * change that value.
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
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.60     — This file
 *   Step 7 v1.1.0 §C.3          — Cross-tenant test matrix (T7, T8, T9)
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *   §2.13 (conventions)         — Test conventions
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Tests\Unit
 * @version 1.0.2
 */
final class AllocationServiceTest extends TestCase
{
    use DatabaseMigrations;

    private AllocationService $service;
    private WalletService $walletService;
    private ObligationService $obligationService;
    private PaymentService $paymentService;

    private Tenant $tenant;
    private Shareholder $shareholder;
    private Wallet $wallet;
    private Obligation $obligation;
    private Payment $payment;

    private Tenant $otherTenant;
    private Shareholder $otherShareholder;
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

        $this->service           = app(AllocationService::class);
        $this->walletService     = app(WalletService::class);
        $this->obligationService = app(ObligationService::class);
        $this->paymentService    = app(PaymentService::class);

        // Primary tenant fixtures.
        $this->tenant      = $this->makeTenant('main-tenant', 'ዋና ቴናንት');
        $this->shareholder = $this->createShareholder($this->tenant);
        $this->wallet      = $this->openWallet($this->tenant, $this->shareholder);

        $this->obligation = $this->makeObligation($this->wallet, '1000.0000');
        $this->payment    = $this->makePayment($this->wallet, '500.0000');

        // Foreign tenant fixtures — used only in cross-wallet tests.
        $this->otherTenant      = $this->makeTenant('other-tenant', 'ሌላ ቴናንት');
        $this->otherShareholder = $this->createShareholder($this->otherTenant);
        $this->otherWallet      = $this->openWallet($this->otherTenant, $this->otherShareholder);

        $this->otherObligation = $this->makeObligation($this->otherWallet, '300.0000');
        $this->otherPayment    = $this->makePayment($this->otherWallet, '200.0000');

        gc_collect_cycles();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();

        parent::tearDown();

        gc_collect_cycles();
    }

    // =========================================================================
    // allocate() — happy path
    // =========================================================================

    public function test_allocate_creates_allocation_row(): void
    {
        $allocation = $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $this->assertDatabaseHas('wallet_payment_allocations', [
            'id'            => $allocation->id,
            'wallet_id'     => $this->wallet->id,
            'payment_id'    => $this->payment->id,
            'obligation_id' => $this->obligation->id,
            'amount'        => '200.0000',
        ]);
    }

    public function test_allocate_decrements_payment_unapplied(): void
    {
        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $this->payment->refresh();
        $this->assertSame('300.0000', (string) $this->payment->unapplied_amount);
        $this->assertTrue($this->payment->isPartiallyApplied());
    }

    public function test_allocate_decrements_obligation_outstanding(): void
    {
        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $this->obligation->refresh();
        $this->assertSame('800.0000', (string) $this->obligation->outstanding_amount);
        $this->assertTrue($this->obligation->isPartiallySettled());
    }

    public function test_allocate_does_not_change_wallet_balance(): void
    {
        // Refresh before capturing — the setUp fixtures mutate the wallet's
        // current_balance COLUMN in the database but not the in-memory
        // model. See the class docblock §"Refreshing before capturing the
        // wallet balance (v1.0.2)".
        $this->wallet->refresh();
        $before = (string) $this->wallet->current_balance;

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_allocate_produces_no_ledger_entry(): void
    {
        $entryCountBefore = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $entryCountAfter = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->assertSame($entryCountBefore, $entryCountAfter);
    }

    public function test_allocate_stores_wallet_id_from_parents(): void
    {
        $allocation = $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        $this->assertSame((int) $this->wallet->id, (int) $allocation->wallet_id);
    }

    public function test_allocate_stores_policy_version_when_provided(): void
    {
        $allocation = $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
            policyVersionId: null,
        );

        $this->assertNull($allocation->allocation_policy_version_id);
        $this->assertFalse($allocation->hasPolicy());
    }

    public function test_allocate_accepts_full_payment_amount(): void
    {
        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '500.0000',
        );

        $this->payment->refresh();
        $this->assertSame('0.0000', (string) $this->payment->unapplied_amount);
        $this->assertTrue($this->payment->isFullyApplied());
    }

    // =========================================================================
    // allocate() — the six §6.3 invariants
    // =========================================================================

    public function test_allocate_rejects_zero_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '0.0000',
        );
    }

    public function test_allocate_rejects_malformed_amount(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: 'not-a-number',
        );
    }

    public function test_allocate_rejects_amount_exceeding_payment_unapplied(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '600.0000',
        );
    }

    public function test_allocate_rejects_amount_exceeding_obligation_outstanding(): void
    {
        $largePayment = $this->makePayment($this->wallet, '2000.0000');

        $this->expectException(ValidationException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $largePayment->id,
            obligationId: (int) $this->obligation->id,
            amount: '1500.0000',
        );
    }

    public function test_allocate_rejects_cross_wallet_payment(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->otherPayment->id,
            obligationId: (int) $this->obligation->id,
            amount: '100.0000',
        );
    }

    public function test_allocate_rejects_cross_wallet_obligation(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->otherObligation->id,
            amount: '100.0000',
        );
    }

    public function test_allocate_rejects_nonexistent_payment(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: 999999,
            obligationId: (int) $this->obligation->id,
            amount: '100.0000',
        );
    }

    public function test_allocate_rejects_nonexistent_obligation(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: 999999,
            amount: '100.0000',
        );
    }

    // =========================================================================
    // allocate() — event
    // =========================================================================

    public function test_allocate_dispatches_payment_allocated_event(): void
    {
        Event::fake([PaymentAllocated::class]);

        $allocation = $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '200.0000',
        );

        // The closure accesses `$this->wallet`, so it MUST NOT be static.
        // See the class docblock §"Event-assertion closures (v1.0.1)".
        Event::assertDispatched(
            PaymentAllocated::class,
            fn (PaymentAllocated $event): bool => $event->allocationId() === (int) $allocation->id
                && $event->walletId() === (int) $this->wallet->id
                && $event->paymentId() === (int) $this->payment->id
                && $event->obligationId() === (int) $this->obligation->id
                && $event->amount() === '200.0000',
        );
    }

    // =========================================================================
    // reverse() — happy path
    // =========================================================================

    public function test_reverse_creates_reversal_row(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $reversal = $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $this->assertDatabaseHas('wallet_payment_allocation_reversals', [
            'id'                    => $reversal->id,
            'wallet_id'             => $this->wallet->id,
            'payment_allocation_id' => $allocation->id,
            'reversed_amount'       => '50.0000',
            'reason'                => 'Partial correction for testing.',
        ]);
    }

    public function test_reverse_increments_payment_unapplied(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->payment->refresh();
        $this->assertSame('300.0000', (string) $this->payment->unapplied_amount);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $this->payment->refresh();
        $this->assertSame('350.0000', (string) $this->payment->unapplied_amount);
    }

    public function test_reverse_increments_obligation_outstanding(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->obligation->refresh();
        $this->assertSame('800.0000', (string) $this->obligation->outstanding_amount);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $this->obligation->refresh();
        $this->assertSame('850.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_reverse_does_not_change_wallet_balance(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        // Refresh before capturing — the setUp fixtures mutate the wallet's
        // current_balance COLUMN in the database but not the in-memory
        // model. See the class docblock §"Refreshing before capturing the
        // wallet balance (v1.0.2)".
        $this->wallet->refresh();
        $before = (string) $this->wallet->current_balance;

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $this->wallet->refresh();
        $this->assertSame($before, (string) $this->wallet->current_balance);
    }

    public function test_reverse_produces_no_ledger_entry(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $entryCountBefore = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $entryCountAfter = WalletEntry::query()
            ->where('wallet_id', $this->wallet->id)
            ->count();

        $this->assertSame($entryCountBefore, $entryCountAfter);
    }

    public function test_reverse_preserves_original_allocation(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Partial correction for testing.',
        );

        $allocation->refresh();
        $this->assertSame('200.0000', (string) $allocation->amount);
    }

    public function test_reverse_supports_partial_reversal(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $reversal = $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '75.0000',
            reason: 'Partial correction for testing.',
        );

        $this->assertSame('75.0000', (string) $reversal->reversed_amount);

        $this->obligation->refresh();
        $this->assertSame('875.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_reverse_supports_multiple_partial_reversals(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'First partial reversal.',
        );

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Second partial reversal.',
        );

        $this->assertSame(
            2,
            PaymentAllocationReversal::query()
                ->where('payment_allocation_id', $allocation->id)
                ->count(),
        );

        $this->obligation->refresh();
        $this->assertSame('900.0000', (string) $this->obligation->outstanding_amount);
    }

    public function test_reverse_stores_reason_and_actor(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $reversal = $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Documented reason.',
            reversedBy: null,
        );

        $this->assertSame('Documented reason.', (string) $reversal->reason);
        $this->assertNull($reversal->reversed_by);
        $this->assertFalse($reversal->hasActor());
    }

    public function test_reverse_supports_full_reversal(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '200.0000',
            reason: 'Full correction.',
        );

        $this->obligation->refresh();
        $this->payment->refresh();

        $this->assertSame('1000.0000', (string) $this->obligation->outstanding_amount);
        $this->assertTrue($this->obligation->isOpen());
        $this->assertSame('500.0000', (string) $this->payment->unapplied_amount);
        $this->assertTrue($this->payment->isUnapplied());
    }

    // =========================================================================
    // reverse() — the four §6.5 invariants
    // =========================================================================

    public function test_reverse_rejects_zero_reversed_amount(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '0.0000',
            reason: 'Zero-reversal.',
        );
    }

    public function test_reverse_rejects_malformed_reversed_amount(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: 'not-a-number',
            reason: 'Malformed.',
        );
    }

    public function test_reverse_rejects_amount_exceeding_effective(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '250.0000',
            reason: 'Exceeds the parent.',
        );
    }

    public function test_reverse_rejects_cumulative_reversals_exceeding_effective(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '150.0000',
            reason: 'First partial reversal.',
        );

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '100.0000',
            reason: 'Exceeds remaining.',
        );
    }

    public function test_reverse_rejects_empty_reason(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: '',
        );
    }

    public function test_reverse_rejects_whitespace_only_reason(): void
    {
        $allocation = $this->makeAllocation('200.0000');

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: "   \t\n  ",
        );
    }

    public function test_reverse_rejects_cross_wallet_allocation(): void
    {
        $foreignAllocation = $this->service->allocate(
            wallet: $this->otherWallet,
            paymentId: (int) $this->otherPayment->id,
            obligationId: (int) $this->otherObligation->id,
            amount: '50.0000',
        );

        $this->expectException(ValidationException::class);

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $foreignAllocation,
            reversedAmount: '25.0000',
            reason: 'Attempted cross-wallet reversal.',
        );
    }

    // =========================================================================
    // reverse() — event
    // =========================================================================

    public function test_reverse_dispatches_payment_allocation_reversed_event(): void
    {
        Event::fake([PaymentAllocationReversed::class]);

        $allocation = $this->makeAllocation('200.0000');

        $reversal = $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '50.0000',
            reason: 'Audit test.',
        );

        // The closure accesses `$this->wallet`, so it MUST NOT be static.
        // See the class docblock §"Event-assertion closures (v1.0.1)".
        Event::assertDispatched(
            PaymentAllocationReversed::class,
            fn (PaymentAllocationReversed $event): bool => $event->reversalId() === (int) $reversal->id
                && $event->allocationId() === (int) $allocation->id
                && $event->walletId() === (int) $this->wallet->id
                && $event->reversedAmount() === '50.0000'
                && $event->reason() === 'Audit test.',
        );
    }

    // =========================================================================
    // Projection consistency
    // =========================================================================

    public function test_full_reverse_restores_projections(): void
    {
        $initialPaymentUnapplied      = (string) $this->payment->unapplied_amount;
        $initialObligationOutstanding = (string) $this->obligation->outstanding_amount;

        $allocation = $this->makeAllocation('200.0000');

        $this->service->reverse(
            wallet: $this->wallet,
            allocation: $allocation,
            reversedAmount: '200.0000',
            reason: 'Full correction.',
        );

        $this->payment->refresh();
        $this->obligation->refresh();

        $this->assertSame($initialPaymentUnapplied, (string) $this->payment->unapplied_amount);
        $this->assertSame($initialObligationOutstanding, (string) $this->obligation->outstanding_amount);
    }

    public function test_projection_arithmetic_is_additive(): void
    {
        $secondPayment = $this->makePayment($this->wallet, '400.0000');

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: '150.0000',
        );

        $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $secondPayment->id,
            obligationId: (int) $this->obligation->id,
            amount: '250.0000',
        );

        $this->obligation->refresh();
        $this->payment->refresh();
        $secondPayment->refresh();

        $this->assertSame('600.0000', (string) $this->obligation->outstanding_amount);
        $this->assertSame('350.0000', (string) $this->payment->unapplied_amount);
        $this->assertSame('150.0000', (string) $secondPayment->unapplied_amount);
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

    private function openWallet(Tenant $tenant, Shareholder $shareholder): Wallet
    {
        return $this->walletService->open(
            tenantId: (int) $tenant->id,
            shareholder: $shareholder,
            currency: 'ETB',
        );
    }

    private function makeObligation(Wallet $wallet, string $amount): Obligation
    {
        return $this->obligationService->record(
            wallet: $wallet,
            component: ObligationComponent::Principal,
            amount: $amount,
        );
    }

    private function makePayment(Wallet $wallet, string $amount): Payment
    {
        return $this->paymentService->record(
            wallet: $wallet,
            amount: $amount,
        );
    }

    private function makeAllocation(string $amount): PaymentAllocation
    {
        return $this->service->allocate(
            wallet: $this->wallet,
            paymentId: (int) $this->payment->id,
            obligationId: (int) $this->obligation->id,
            amount: $amount,
        );
    }
}