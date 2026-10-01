<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocated;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentAllocationReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\PaymentAllocationReversal;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\AllocationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\ObligationRepository;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\PaymentRepository;

/**
 * AllocationService
 *
 * The write path for the allocation of payments to obligations, and for
 * the reversal of those allocations.
 *
 * =============================================================================
 * Public API
 * =============================================================================
 * Two operations:
 *
 *     allocate()  — record an explicit allocation of a payment to an
 *                   obligation (D110). Writes a `PaymentAllocation` row
 *                   and updates both parent projections.
 *
 *     reverse()   — record the reversal of an existing allocation. Writes
 *                   a `PaymentAllocationReversal` row and updates both
 *                   parent projections in the opposite direction.
 *
 * The service exposes no update and no delete method. Both entities are
 * fully immutable once recorded (2B.8 §"Immutability", 2B.9 §"Full
 * immutability"); corrections to an allocation flow through `reverse()`,
 * and there is no reversal-of-reversal operation.
 *
 * =============================================================================
 * What allocate() does
 * =============================================================================
 * `allocate()` performs, in order, inside one database transaction:
 *
 *   1. Re-resolves the payment and obligation under row locks (the caller
 *      resolved them for the 404 pre-flight; the transaction re-reads
 *      them to serialize concurrent writers).
 *   2. Enforces the six allocation invariants of Architecture §6.3.
 *   3. Creates the `PaymentAllocation` row.
 *   4. Decreases `payment.unapplied_amount` by the allocated amount.
 *   5. Decreases `obligation.outstanding_amount` by the allocated amount.
 *   6. Registers an after-commit dispatch of `PaymentAllocated` (2B.43).
 *
 * The transaction writes no ledger entries. Allocations are attribution
 * records, not monetary movements (Architecture §5.1) — the wallet's
 * `current_balance` is not touched. The two projections on payment and
 * obligation are the only rows that change.
 *
 * =============================================================================
 * The six allocation invariants (Architecture §6.3)
 * =============================================================================
 * Enforced inside the transaction, using the locked rows:
 *
 *   1. allocation.amount > 0
 *   2. allocation.amount ≤ payment.unapplied_amount
 *   3. allocation.amount ≤ obligation.outstanding_amount
 *   4. payment.wallet_id == obligation.wallet_id
 *   5. payment.currency == obligation.currency == wallet.currency   (Q8)
 *   6. On success: both projections are decremented by allocation.amount
 *
 * Invariants 1–3 are checked by this service. Invariant 4 is structurally
 * guaranteed because the caller resolves both the payment and the
 * obligation through their respective repositories' `findForWallet()`
 * methods, scoped to the same wallet. Invariant 5 is checked here; the
 * equality against the wallet's currency is checked transitively (both
 * `payment.currency` and `obligation.currency` are inherited from the
 * wallet at creation, and the wallet's currency is immutable per A10).
 * Invariant 6 is the projection update performed by this method.
 *
 * =============================================================================
 * What reverse() does
 * =============================================================================
 * `reverse()` performs, in order, inside one database transaction:
 *
 *   1. Locks the payment, obligation, and parent allocation in the
 *      deterministic order of Architecture §4.7
 *      (Payment → Obligation → PaymentAllocation).
 *   2. Computes the parent allocation's effective allocated amount by
 *      summing its existing reversals (Architecture §3.6).
 *   3. Enforces the four reversal invariants of Architecture §6.5.
 *   4. Creates the `PaymentAllocationReversal` row.
 *   5. Increases `payment.unapplied_amount` by the reversed amount.
 *   6. Increases `obligation.outstanding_amount` by the reversed amount.
 *   7. Registers an after-commit dispatch of `PaymentAllocationReversed`
 *      (2B.44).
 *
 * The original allocation row is untouched; the reversal is a separate,
 * permanent record. Multiple partial reversals of the same allocation are
 * supported — each is a distinct row — provided their sum never exceeds
 * the allocation's amount.
 *
 * =============================================================================
 * The four reversal invariants (Architecture §6.5)
 * =============================================================================
 * Enforced inside the transaction, using the locked rows:
 *
 *   1. reversal.reversed_amount > 0
 *   2. reversal.reversed_amount ≤ effective_allocated(parent) BEFORE this
 *      reversal
 *   3. parent allocation is not itself a reversal
 *   4. a reversal row is never itself reversed
 *
 * Invariant 3 is structurally guaranteed: allocations and reversals are
 * distinct model classes stored in distinct tables. Invariant 4 is
 * enforced by the absence of any method that would accept a
 * `PaymentAllocationReversal` as a reversal target. Invariants 1 and 2 are
 * checked by this service.
 *
 * =============================================================================
 * Deterministic lock acquisition (Architecture §4.7)
 * =============================================================================
 * All multi-row lock sequences respect the frozen order:
 *
 *     Wallet → Payment → Obligation → PaymentAllocation
 *
 * For `allocate()`: Payment, then Obligation. The wallet is not locked
 * because allocation does not read or write it inside the transaction
 * (its `currency` is immutable and was already read by the caller for the
 * Q8 check).
 *
 * For `reverse()`: Payment, then Obligation, then the parent allocation.
 *
 * The `Wallet` slot in the ordering is reserved for future operations that
 * need to lock the wallet itself (e.g. a future `close()`). No 2B
 * allocation operation acquires it.
 *
 * Two concurrent reversals of the same allocation serialize on the parent
 * Payment lock (both reversals target the same `payment_id`), which
 * guarantees that the "sum of existing reversals" is read at a consistent
 * point relative to the other reversal's insert.
 *
 * =============================================================================
 * Transaction and event discipline
 * =============================================================================
 * Both operations open their own transaction. Inside the transaction they
 * lock, validate, write, and register the after-commit event. The event is
 * dispatched via `DB::afterCommit()`, gated on
 * `config('wallet.audit.emit_events')` (2B.1). If the transaction rolls
 * back, the callback is discarded and no event fires.
 *
 * =============================================================================
 * No ledger entries (Architecture §5.1)
 * =============================================================================
 * Neither operation writes to `wallet_entries`. This is a deliberate
 * architectural decision: the ledger records MONETARY movements; an
 * allocation records ATTRIBUTION. The monetary effect of a payment's
 * arrival was realized at the moment `PaymentService::record()` was
 * called; the allocation changes only which obligation the payment is
 * considered to have settled, not whether the money exists.
 *
 * Consequence: `AllocationService` does not depend on `WalletService`
 * (2B.15). It writes to `wallet_payment_allocations` and
 * `wallet_payment_allocations_reversals` (through the models directly),
 * and updates the `unapplied_amount` / `outstanding_amount` projections on
 * `wallet_payments` / `wallet_obligations`. It never writes to
 * `wallet_entries`.
 *
 * =============================================================================
 * Dependencies
 * =============================================================================
 * The constructor takes three dependencies:
 *
 *     PaymentRepository     — pre-flight resolution of the target payment.
 *     ObligationRepository  — pre-flight resolution of the target obligation.
 *     AllocationRepository  — enumeration of an allocation's existing
 *                             reversals for the §6.5 invariant check.
 *
 * This matches Architecture §16.2's dependency list for `AllocationService`
 * with one omission: `WalletRepository` is NOT injected.
 *
 * The omission mirrors the documented deviations of 2B.16 and 2B.17. The
 * wallet is resolved by the controller (via
 * `WalletRepository::findForTenant()`) BEFORE this service is invoked, and
 * passed in as a hydrated model. The service performs no wallet lookup of
 * its own; injecting `WalletRepository` would add a dependency no code path
 * uses. See 2B.16 §"Dependencies" for the reasoning that applies here
 * verbatim.
 *
 * All three injected repositories are used:
 *
 *   - `PaymentRepository::findForWallet()` and
 *     `ObligationRepository::findForWallet()` are called by `allocate()`
 *     before opening the transaction, to produce the correct 404 for a
 *     cross-wallet or non-existent reference.
 *
 *   - `AllocationRepository::listReversalsForAllocation()` is called by
 *     `reverse()` INSIDE the transaction, after the parent allocation is
 *     locked, to enumerate the existing reversals for the sum check.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not evaluate an allocation policy. D110 declines to freeze a
 *     universal priority order; every allocation is explicit. The
 *     `allocation_policy_version_id` column is recorded if the caller
 *     supplies it, and never read.
 *
 *   - It does not auto-allocate. A payment with an unapplied amount
 *     remains unapplied indefinitely until an explicit allocation is
 *     recorded.
 *
 *   - It does not "hold" or "reserve" funds. Phase 2B has no hold state;
 *     the payment's `unapplied_amount` is a first-class, queryable value.
 *
 *   - It does not lock the wallet. See the class docblock
 *     §"Deterministic lock acquisition".
 *
 *   - It does not enforce a currency allow-list. Currency is inherited
 *     from the parents; per-currency minor-unit precision is deferred to
 *     a future CR.
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
 *   Architecture v1.2.0 §10     — Allocation-policy contract boundary
 *   Architecture v1.2.0 §16.2   — Services (and the deviation noted above)
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.18     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Services
 * @version 1.0.0
 */
final class AllocationService
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.51–2B.53.
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Constructor.
     *
     * @param PaymentRepository    $paymentRepository
     * @param ObligationRepository $obligationRepository
     * @param AllocationRepository $allocationRepository
     */
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly ObligationRepository $obligationRepository,
        private readonly AllocationRepository $allocationRepository,
    ) {
    }

    // =========================================================================
    // Public API — allocate
    // =========================================================================

    /**
     * Record an explicit allocation of a payment to an obligation.
     *
     * The caller supplies the target wallet (already tenant-resolved by the
     * controller), a payment id, an obligation id, and the amount to apply.
     * Both ids are resolved through their respective repositories' scoped
     * lookups BEFORE the transaction opens, so a cross-wallet or
     * non-existent reference fails fast with a `ModelNotFoundException`
     * (rendered as 404).
     *
     * All six invariants of Architecture §6.3 are enforced inside the
     * transaction, using the locked payment and obligation rows.
     *
     * The payment's `unapplied_amount` and the obligation's
     * `outstanding_amount` are both decremented by the allocated amount.
     * No ledger entry is produced (see the class docblock §"No ledger
     * entries").
     *
     * @param Wallet      $wallet
     * @param int         $paymentId
     * @param int         $obligationId
     * @param string      $amount        Positive decimal string, at most 4 decimals.
     * @param int|null    $createdBy     Optional actor user id.
     * @param int|null    $policyVersionId  Optional allocation-policy-version reference.
     * @return PaymentAllocation
     *
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function allocate(
        Wallet $wallet,
        int $paymentId,
        int $obligationId,
        string $amount,
        ?int $createdBy = null,
        ?int $policyVersionId = null,
    ): PaymentAllocation {
        $this->assertWalletUsable($wallet);
        $this->assertAmountIsWellFormed($amount);

        // ---------------------------------------------------------------------
        // Pre-flight resolution, BEFORE the transaction. A cross-wallet id
        // (or a non-existent one) yields `null` from the scoped repository
        // lookup, and the ModelNotFoundException renders as a 404 — the
        // same response a non-existent id would produce. This is the
        // Step 7 v1.1.0 §C.6 invariant, enforced at the earliest point
        // where the caller can be told "not found".
        // ---------------------------------------------------------------------
        $payment = $this->paymentRepository->findForWallet($wallet->id, $paymentId);

        if ($payment === null) {
            throw (new ModelNotFoundException())->setModel(Payment::class, [$paymentId]);
        }

        $obligation = $this->obligationRepository->findForWallet($wallet->id, $obligationId);

        if ($obligation === null) {
            throw (new ModelNotFoundException())->setModel(Obligation::class, [$obligationId]);
        }

        return DB::transaction(function () use (
            $wallet,
            $payment,
            $obligation,
            $amount,
            $createdBy,
            $policyVersionId,
        ): PaymentAllocation {
            // -----------------------------------------------------------------
            // Re-read the parents under row locks, in the deterministic
            // order of Architecture §4.7: Payment, then Obligation.
            //
            // The pre-flight repository resolution established that both
            // rows exist and belong to the wallet; the locked read ensures
            // that their projections are read at a consistent point
            // relative to any concurrent allocation or reversal.
            // -----------------------------------------------------------------
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Obligation $lockedObligation */
            $lockedObligation = Obligation::query()
                ->whereKey($obligation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // -----------------------------------------------------------------
            // Enforce the §6.3 invariants using the locked values.
            // -----------------------------------------------------------------
            $this->assertPaymentHasSufficientUnapplied($lockedPayment, $amount);
            $this->assertObligationHasSufficientOutstanding($lockedObligation, $amount);
            $this->assertCurrencyEquality($lockedPayment, $lockedObligation, $wallet);

            // -----------------------------------------------------------------
            // Create the allocation row.
            //
            // `wallet_id` is stored explicitly (denormalized from the parent
            // payment, which is guaranteed to match the obligation's
            // wallet_id by the §6.3 invariant) so that wallet-scoped queries
            // do not have to traverse either parent.
            // -----------------------------------------------------------------
            $now = Carbon::now();

            /** @var PaymentAllocation $allocation */
            $allocation = PaymentAllocation::create([
                'wallet_id'                    => $wallet->id,
                'payment_id'                   => $lockedPayment->id,
                'obligation_id'                => $lockedObligation->id,
                'amount'                       => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'allocated_at'                 => $now,
                'allocated_by'                 => $createdBy,
                'allocation_policy_version_id' => $policyVersionId,
            ]);

            // -----------------------------------------------------------------
            // Decrement both projections. The decrements are exact under
            // bcmath at scale 4; the §6.3 invariants enforced above
            // guarantee that neither projection goes negative.
            // -----------------------------------------------------------------
            $newUnapplied = bcsub(
                (string) $lockedPayment->unapplied_amount,
                $amount,
                self::MAX_DECIMAL_SCALE,
            );
            $lockedPayment->unapplied_amount = $newUnapplied;
            $lockedPayment->save();

            $newOutstanding = bcsub(
                (string) $lockedObligation->outstanding_amount,
                $amount,
                self::MAX_DECIMAL_SCALE,
            );
            $lockedObligation->outstanding_amount = $newOutstanding;
            $lockedObligation->save();

            // -----------------------------------------------------------------
            // Dispatch the domain event after commit, subject to the audit
            // feature flag.
            // -----------------------------------------------------------------
            $this->dispatchAfterCommit(new PaymentAllocated($allocation));

            return $allocation->refresh();
        });
    }

    // =========================================================================
    // Public API — reverse
    // =========================================================================

    /**
     * Record the reversal of an existing allocation.
     *
     * The parent allocation is expected to have been resolved by the
     * controller through `AllocationRepository::findForWallet()` (2B.14),
     * which enforces the wallet-scoping composition. This method verifies
     * the allocation belongs to the given wallet, then locks the payment,
     * obligation, and allocation in the deterministic order of
     * Architecture §4.7.
     *
     * The four invariants of Architecture §6.5 are enforced inside the
     * transaction. The parent allocation's effective allocated amount
     * (amount minus the sum of its existing reversals) is the upper bound
     * on the new reversal's amount.
     *
     * Both parent projections are incremented by the reversed amount —
     * the opposite of what `allocate()` does.
     *
     * @param Wallet             $wallet
     * @param PaymentAllocation  $allocation
     * @param string             $reversedAmount  Positive decimal string, at most 4 decimals.
     * @param string             $reason          Required. Why the reversal is being made.
     * @param int|null           $reversedBy      Optional actor user id.
     * @return PaymentAllocationReversal
     *
     * @throws ValidationException
     */
    public function reverse(
        Wallet $wallet,
        PaymentAllocation $allocation,
        string $reversedAmount,
        string $reason,
        ?int $reversedBy = null,
    ): PaymentAllocationReversal {
        $this->assertWalletUsable($wallet);
        $this->assertAllocationBelongsToWallet($allocation, $wallet);
        $this->assertAmountIsWellFormed($reversedAmount);
        $this->assertReasonIsProvided($reason);

        return DB::transaction(function () use (
            $wallet,
            $allocation,
            $reversedAmount,
            $reason,
            $reversedBy,
        ): PaymentAllocationReversal {
            // -----------------------------------------------------------------
            // Lock the payment, obligation, and parent allocation in the
            // deterministic order of Architecture §4.7:
            //     Payment → Obligation → PaymentAllocation.
            //
            // The ids are read from the un-locked allocation model passed
            // in by the caller; those ids are immutable (2B.8 §"Immutability"),
            // so no race can change them between the read and the lock.
            // -----------------------------------------------------------------
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($allocation->payment_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Obligation $lockedObligation */
            $lockedObligation = Obligation::query()
                ->whereKey($allocation->obligation_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var PaymentAllocation $lockedAllocation */
            $lockedAllocation = PaymentAllocation::query()
                ->whereKey($allocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            // -----------------------------------------------------------------
            // Compute the parent allocation's effective allocated amount
            // by summing its existing reversals. The enumeration uses the
            // wallet-scoped lookup method on the repository; the parent
            // allocation is now locked, so no concurrent writer can insert
            // a new reversal of this same allocation without first
            // acquiring the same Payment lock that we hold.
            // -----------------------------------------------------------------
            $existingReversals = $this->allocationRepository
                ->listReversalsForAllocation($wallet->id, $lockedAllocation->id);

            $sumReversed = '0';
            foreach ($existingReversals as $existing) {
                $sumReversed = bcadd(
                    $sumReversed,
                    (string) $existing->reversed_amount,
                    self::MAX_DECIMAL_SCALE,
                );
            }

            $effectiveAllocated = bcsub(
                (string) $lockedAllocation->amount,
                $sumReversed,
                self::MAX_DECIMAL_SCALE,
            );

            // -----------------------------------------------------------------
            // Enforce the §6.5 invariants using the locked and derived values.
            // -----------------------------------------------------------------
            $this->assertReversalDoesNotExceedEffective($reversedAmount, $effectiveAllocated);

            // -----------------------------------------------------------------
            // Create the reversal row.
            // -----------------------------------------------------------------
            $now = Carbon::now();

            /** @var PaymentAllocationReversal $reversal */
            $reversal = PaymentAllocationReversal::create([
                'wallet_id'             => $wallet->id,
                'payment_allocation_id' => $lockedAllocation->id,
                'reversed_amount'       => bcadd('0', $reversedAmount, self::MAX_DECIMAL_SCALE),
                'reason'                => $reason,
                'reversed_at'           => $now,
                'reversed_by'           => $reversedBy,
            ]);

            // -----------------------------------------------------------------
            // Increment both projections by the reversed amount. This is
            // the opposite direction of the decrements performed by
            // allocate(); the sum over the allocation's lifetime is
            // conserved.
            // -----------------------------------------------------------------
            $newUnapplied = bcadd(
                (string) $lockedPayment->unapplied_amount,
                $reversedAmount,
                self::MAX_DECIMAL_SCALE,
            );
            $lockedPayment->unapplied_amount = $newUnapplied;
            $lockedPayment->save();

            $newOutstanding = bcadd(
                (string) $lockedObligation->outstanding_amount,
                $reversedAmount,
                self::MAX_DECIMAL_SCALE,
            );
            $lockedObligation->outstanding_amount = $newOutstanding;
            $lockedObligation->save();

            // -----------------------------------------------------------------
            // Dispatch the domain event after commit, subject to the audit
            // feature flag.
            // -----------------------------------------------------------------
            $this->dispatchAfterCommit(new PaymentAllocationReversed($reversal));

            return $reversal->refresh();
        });
    }

    // =========================================================================
    // Private — validation (shared by both operations)
    // =========================================================================

    /**
     * Assert that the wallet is usable as a target for an allocation or
     * reversal.
     *
     * Mirrors the identical check in ObligationService (2B.16) and
     * PaymentService (2B.17):
     *
     *   1. The wallet must be persisted.
     *   2. The wallet must carry a non-empty currency.
     *   3. The wallet's currency must match `^[A-Za-z]{3}$`.
     *
     * Tenant scoping is NOT re-verified here: it was established by the
     * controller's `WalletRepository::findForTenant()` call before this
     * service was invoked (Step 7 v1.1.0 §C.6).
     *
     * @param Wallet $wallet
     * @return void
     *
     * @throws ValidationException
     */
    private function assertWalletUsable(Wallet $wallet): void
    {
        if (!$wallet->exists) {
            throw ValidationException::withMessages([
                'wallet_id' => ['The wallet must be a persisted record.'],
            ]);
        }

        $currency = (string) $wallet->currency;

        if ($currency === '') {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet has no currency. Allocations cannot be '
                    . 'recorded against a wallet with a missing currency.',
                ],
            ]);
        }

        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet currency is not a valid ISO 4217 code. '
                    . 'This indicates a data anomaly; the operation is '
                    . 'rejected rather than proceeding against a malformed wallet.',
                ],
            ]);
        }
    }

    /**
     * Assert that a positive decimal-string amount is well-formed.
     *
     * Used by both `allocate()` and `reverse()`. Byte-for-byte identical
     * to the same rule in 2B.16 and 2B.17; duplication is deliberate (see
     * 2B.17 §"Structural parity" for the reasoning).
     *
     * @param string $amount
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAmountIsWellFormed(string $amount): void
    {
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $amount)) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The amount must be a positive decimal number with at '
                    . 'most four decimal places.',
                ],
            ]);
        }

        if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The amount must be strictly greater than zero.',
                ],
            ]);
        }
    }

    /**
     * Assert that the reversal reason is non-empty.
     *
     * Architecture §3.6 requires the reversal's `reason` field: the
     * correction metadata is what distinguishes a reversal from a mere
     * mutation. A whitespace-only reason is treated as absent.
     *
     * @param string $reason
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReasonIsProvided(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => [
                    'A reversal reason is required. Provide a non-empty '
                    . 'explanation of why the allocation is being reversed.',
                ],
            ]);
        }
    }

    // =========================================================================
    // Private — validation (allocate-specific)
    // =========================================================================

    /**
     * Assert that the payment has enough unapplied amount to cover the
     * requested allocation (§6.3 invariant 2).
     *
     * @param Payment $payment
     * @param string  $amount
     * @return void
     *
     * @throws ValidationException
     */
    private function assertPaymentHasSufficientUnapplied(Payment $payment, string $amount): void
    {
        if (bccomp($amount, (string) $payment->unapplied_amount, self::MAX_DECIMAL_SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    sprintf(
                        'The allocation amount (%s) exceeds the payment\'s '
                        . 'unapplied amount (%s).',
                        $amount,
                        (string) $payment->unapplied_amount,
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert that the obligation has enough outstanding amount to cover
     * the requested allocation (§6.3 invariant 3).
     *
     * @param Obligation $obligation
     * @param string     $amount
     * @return void
     *
     * @throws ValidationException
     */
    private function assertObligationHasSufficientOutstanding(
        Obligation $obligation,
        string $amount,
    ): void {
        if (bccomp($amount, (string) $obligation->outstanding_amount, self::MAX_DECIMAL_SCALE) > 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    sprintf(
                        'The allocation amount (%s) exceeds the obligation\'s '
                        . 'outstanding amount (%s).',
                        $amount,
                        (string) $obligation->outstanding_amount,
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert the Q8 currency equality across payment, obligation, and
     * wallet.
     *
     * The check compares all three currency strings against the wallet's.
     * The wallet's currency is immutable (A10); the payment and obligation
     * currencies are inherited from it at creation (2B.16, 2B.17). In a
     * well-formed system all three match by construction. The check
     * exists as a defence against a row whose currency column has been
     * corrupted externally.
     *
     * @param Payment  $payment
     * @param Obligation $obligation
     * @param Wallet   $wallet
     * @return void
     *
     * @throws ValidationException
     */
    private function assertCurrencyEquality(
        Payment $payment,
        Obligation $obligation,
        Wallet $wallet,
    ): void {
        $walletCurrency     = (string) $wallet->currency;
        $paymentCurrency    = (string) $payment->currency;
        $obligationCurrency = (string) $obligation->currency;

        if (
            $paymentCurrency !== $walletCurrency
            || $obligationCurrency !== $walletCurrency
        ) {
            throw ValidationException::withMessages([
                'currency' => [
                    'The payment, obligation, and wallet currencies must '
                    . 'match. This indicates a data anomaly; the allocation '
                    . 'is rejected rather than proceeding across currencies.',
                ],
            ]);
        }
    }

    // =========================================================================
    // Private — validation (reverse-specific)
    // =========================================================================

    /**
     * Assert that the allocation belongs to the given wallet.
     *
     * The controller resolved the allocation through
     * `AllocationRepository::findForWallet()` (2B.14), which enforces the
     * wallet-scoping composition. This service re-verifies the
     * correspondence as a defence against a caller that constructs the
     * service call directly (tests, artisan scripts) and passes a
     * mismatched wallet.
     *
     * @param PaymentAllocation $allocation
     * @param Wallet            $wallet
     * @return void
     *
     * @throws ValidationException
     */
    private function assertAllocationBelongsToWallet(
        PaymentAllocation $allocation,
        Wallet $wallet,
    ): void {
        if (!$allocation->exists) {
            throw ValidationException::withMessages([
                'allocation_id' => [
                    'The allocation to reverse must be a persisted record.',
                ],
            ]);
        }

        if ((int) $allocation->wallet_id !== (int) $wallet->id) {
            throw ValidationException::withMessages([
                'allocation_id' => [
                    'The allocation does not belong to the specified wallet.',
                ],
            ]);
        }
    }

    /**
     * Assert that the reversal does not exceed the allocation's effective
     * allocated amount (§6.5 invariant 2).
     *
     * The "effective allocated amount" is the parent allocation's
     * `amount` minus the sum of its existing valid reversals
     * (Architecture §3.6). The reversal being recorded now must not push
     * that sum above the allocation's amount.
     *
     * @param string $reversedAmount
     * @param string $effectiveAllocated
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReversalDoesNotExceedEffective(
        string $reversedAmount,
        string $effectiveAllocated,
    ): void {
        if (bccomp($reversedAmount, $effectiveAllocated, self::MAX_DECIMAL_SCALE) > 0) {
            throw ValidationException::withMessages([
                'reversed_amount' => [
                    sprintf(
                        'The reversed amount (%s) exceeds the allocation\'s '
                        . 'effective allocated amount (%s). A reversal cannot '
                        . 'undo more than the allocation records as applied.',
                        $reversedAmount,
                        $effectiveAllocated,
                    ),
                ],
            ]);
        }
    }

    // =========================================================================
    // Private — event dispatch
    // =========================================================================

    /**
     * Dispatch an event after the current transaction commits, subject to
     * the audit feature flag.
     *
     * The gate is `config('wallet.audit.emit_events')`, declared in
     * `Config/wallet.php` (2B.1) and defaulted to `true`. When the config
     * is false, no domain event fires; the allocation or reversal row and
     * the two projection updates are still written.
     *
     * `DB::afterCommit()` registers a callback that runs after the
     * OUTERMOST transaction commits. If no transaction is active, the
     * callback runs immediately — a defensive case for tests or scripts
     * that call the service outside a transaction. In production, both
     * operations wrap in a transaction, so the immediate branch is not
     * exercised.
     *
     * @param object $event
     * @return void
     */
    private function dispatchAfterCommit(object $event): void
    {
        if (!config('wallet.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}