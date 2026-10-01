<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\PaymentReceived;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Payment;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * PaymentService
 *
 * The write path for the `Payment` scoped business aggregate.
 *
 * =============================================================================
 * Public API
 * =============================================================================
 * One operation:
 *
 *     record()  — record a payment received into a wallet and post the
 *                 corresponding ledger entry.
 *
 * The service exposes no update or delete method. Payments are immutable
 * in their economic facts (currency, amount, received_at) and their only
 * mutable field — `unapplied_amount` — is a projection updated by
 * AllocationService (2B.18), not by this service. See Architecture §6.2.
 *
 * =============================================================================
 * What a "payment record" operation does
 * =============================================================================
 * `record()` performs, in order, inside one database transaction:
 *
 *   1. Validates the wallet (persisted, has a non-empty currency).
 *   2. Validates the input (positive amount, optional reference pair).
 *   3. Creates the Payment row with:
 *         amount            = the caller's amount
 *         unapplied_amount  = the same amount (no allocations yet)
 *         currency          = the wallet's currency (Q8 — inherited,
 *                             never supplied by the caller)
 *   4. Calls WalletService::recordEntry() (2B.15) to write the ledger
 *      entry:
 *         entry_type      = payment_received
 *         signed_quantity = +(amount)               [Architecture §4.2]
 *         component       = null                    [payments are not
 *                                                    component-tagged]
 *         reference_type  = 'Payment'
 *         reference_id    = (string) the newly-created payment's id
 *   5. Registers an after-commit dispatch of PaymentReceived.
 *
 * The wallet row is locked by `recordEntry()` for the duration of the
 * transaction, serialising concurrent payment writes against the same
 * wallet. See Architecture §4.7 for the deterministic lock order.
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * A payment is money the member pays in. Under the member-position sign
 * convention (Architecture §4.2), recording a payment produces a POSITIVE
 * ledger entry: the organization now holds credit for the member.
 *
 * The caller supplies the POSITIVE magnitude; the service passes it
 * through to `recordEntry()` with its sign intact. Unlike ObligationService
 * (2B.16), which negates the amount before writing the ledger entry, this
 * service applies no sign flip.
 *
 * Consequence: the service's API does not accept a signed amount, and does
 * not expose a direction parameter. A payment always credits the member's
 * position. If a future CR needs a debit-side "refund" operation, that is
 * a new operation with its own semantics — not a variant of `record()`.
 *
 * =============================================================================
 * Currency inheritance (Q8, A10)
 * =============================================================================
 * The service does not accept a `$currency` parameter. The payment's
 * currency is set to `$wallet->currency` unconditionally. This is the
 * cleanest possible enforcement of the Q8 invariant
 * (`wallet.currency == payment.currency`): the caller cannot supply a
 * mismatched currency because the caller cannot supply a currency at all.
 *
 * The wallet's currency is itself immutable (A10, enforced by
 * `Wallet::booted()`); the payment's currency is therefore fixed for its
 * lifetime, matching the immutable-facts posture of 2B.7.
 *
 * =============================================================================
 * No component (contrast with ObligationService, 2B.16)
 * =============================================================================
 * A payment is NOT component-tagged. The `ObligationComponent` vocabulary
 * describes WHAT the money IS economically (principal, interest, fee,
 * etc.); a payment is money in, without a specified economic meaning. The
 * meaning is established by the ALLOCATIONS that later settle specific
 * obligations. Until allocated, a payment is unattributed.
 *
 * Consequence: the `component` column on the ledger entry is null for
 * `payment_received` entries, and `WalletService::recordEntry()`
 * (2B.15) enforces this by rejecting any component on a non-
 * `obligation_recorded` entry.
 *
 * =============================================================================
 * Reference pair (D109)
 * =============================================================================
 * The payment may carry a `reference_type` + `reference_id` pair
 * identifying a business object in a consuming domain (a transfer, an
 * invoice, an external remittance). The pair is opaque to this service;
 * it is stored as supplied.
 *
 * The pair is OPTIONAL and MUST be all-or-nothing: a caller that supplies
 * one without the other receives a ValidationException. The ledger-entry
 * primitive (2B.15) does not enforce this pairing on the payment's
 * behalf — the payment row's reference pair is distinct from the ledger
 * entry's reference pair (which points at the payment itself).
 *
 * =============================================================================
 * No overpayment handling at record time (D110)
 * =============================================================================
 * A payment may exceed the total of the wallet's outstanding obligations.
 * That fact is not detected at record time and is not an error. The
 * unapplied remainder is recorded as `unapplied_amount` on the payment
 * row, which is the explicit recording D110 requires (Architecture §6.4).
 *
 * Any subsequent allocation against the payment reduces
 * `unapplied_amount`; if the wallet has no obligations to allocate
 * against, the payment remains entirely unapplied indefinitely. There is
 * no "hold" or "reserve" state in Phase 2B.
 *
 * =============================================================================
 * Transaction and event discipline
 * =============================================================================
 * `record()` opens its own transaction. Inside the transaction it creates
 * the payment and calls `WalletService::recordEntry()`, which requires a
 * surrounding transaction (its defensive check throws `LogicException`
 * otherwise) and acquires the wallet row lock.
 *
 * The `PaymentReceived` event is dispatched via `DB::afterCommit()`,
 * gated on `config('wallet.audit.emit_events')` (2B.1). If the
 * transaction rolls back, the callback is discarded and the event does
 * not fire. If `emit_events` is false, the callback is not registered;
 * the payment and its ledger entry are still written.
 *
 * =============================================================================
 * Dependencies
 * =============================================================================
 * The constructor takes ONE dependency: `WalletService` (2B.15), used for
 * its `recordEntry()` primitive.
 *
 * This deviates from the summary in Architecture §16.2, which lists
 * `WalletRepository` and `PaymentRepository` as `PaymentService`
 * dependencies. The deviation is deliberate and mirrors the pattern
 * established by 2B.16 (ObligationService). See that file's class
 * docblock §"Dependencies" for the full rationale; the same reasoning
 * applies here verbatim:
 *
 *   - `WalletRepository` is not needed: the controller resolves the
 *     target wallet through `WalletRepository::findForTenant()` before
 *     invoking this service, and passes the hydrated `Wallet` model in.
 *   - `PaymentRepository` is not needed: this service's only write is a
 *     creation, and it exposes no lookup methods.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not allocate. Allocation is explicit and separate
 *     (AllocationService, 2B.18).
 *
 *   - It does not evaluate policies. No allocation policy is read; the
 *     payment's reference pair is stored as an opaque descriptor.
 *
 *   - It does not reverse. Reversals of the ledger entry are issued
 *     through `WalletService::reverseEntry()`, which operates on ledger
 *     entries, not on payments.
 *
 *   - It does not update `unapplied_amount`. That projection is the sole
 *     responsibility of AllocationService, updated transactionally when
 *     allocations or allocation-reversals are recorded.
 *
 *   - It does not enforce a currency allow-list. Currency is inherited
 *     from the wallet; per-currency minor-unit precision is deferred to a
 *     future CR (see 2B.15 §"Currency handling").
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D110                         — Payment attribution
 *   Architecture v1.2.0 §3.4    — Payment entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.2    — Payment projection
 *   Architecture v1.2.0 §6.4    — Overpayment and unapplied amounts
 *   Architecture v1.2.0 §16.2   — Services (and the deviation noted above)
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Step 5 (manifest) 2B.17     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Services
 * @version 1.0.0
 */
final class PaymentService
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.51.
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * The reference_type value this service writes to ledger entries it
     * causes. Frozen by Architecture §5.3.
     */
    private const LEDGER_REFERENCE_TYPE = 'Payment';

    /**
     * Constructor.
     *
     * @param WalletService $walletService
     */
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Record a payment received into a wallet.
     *
     * The caller supplies a POSITIVE decimal-string amount (the magnitude
     * of the payment). The service passes it to the ledger-write primitive
     * with its sign intact, producing the credit entry required by the
     * member-position sign convention (Architecture §4.2).
     *
     * The payment's currency is inherited from the wallet and cannot be
     * supplied by the caller (Q8). See the class docblock §"Currency
     * inheritance".
     *
     * The operation runs inside a single database transaction that:
     *
     *   1. Creates the Payment row.
     *   2. Calls WalletService::recordEntry() to write the corresponding
     *      ledger entry and update the wallet's `current_balance`.
     *
     * Both writes commit atomically. If the ledger write fails (e.g. a
     * rejected reference pairing), the payment row is rolled back with it
     * — the two never diverge.
     *
     * `PaymentReceived` is dispatched after commit, gated on
     * `config('wallet.audit.emit_events')`.
     *
     * @param Wallet                $wallet        The target wallet.
     * @param string                $amount        Positive decimal string, at most 4 decimals.
     * @param CarbonInterface|null  $receivedAt    Business time; defaults to now.
     * @param string|null           $referenceType Optional origin label.
     * @param string|null           $referenceId   Optional origin identifier.
     * @param string|null           $description   Optional free text.
     * @param int|null              $createdBy     Optional actor user id.
     * @return Payment
     *
     * @throws ValidationException
     */
    public function record(
        Wallet $wallet,
        string $amount,
        ?CarbonInterface $receivedAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): Payment {
        $this->assertWalletUsable($wallet);
        $this->assertAmountIsWellFormed($amount);
        $this->assertReferencePairIsConsistent($referenceType, $referenceId);

        // The payment's signed quantity is the positive amount, passed
        // through unchanged. Contrast with ObligationService (2B.16), where
        // the amount is negated before reaching the ledger.
        $signedQuantity = bcadd('0', $amount, self::MAX_DECIMAL_SCALE);

        return DB::transaction(function () use (
            $wallet,
            $amount,
            $signedQuantity,
            $receivedAt,
            $referenceType,
            $referenceId,
            $description,
            $createdBy,
        ): Payment {
            $businessTime = $receivedAt !== null
                ? Carbon::instance($receivedAt)
                : Carbon::now();

            // -----------------------------------------------------------------
            // 1. Create the Payment row.
            //
            // `unapplied_amount` starts equal to `amount` because no
            // allocation has yet been recorded against this payment
            // (Architecture §6.2).
            //
            // `currency` is inherited from the wallet. The caller has no
            // input channel for it (Q8).
            // -----------------------------------------------------------------
            /** @var Payment $payment */
            $payment = Payment::create([
                'wallet_id'        => $wallet->id,
                'currency'         => $wallet->currency,
                'amount'           => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'unapplied_amount' => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'received_at'      => $businessTime,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'description'      => $description,
                'created_by'       => $createdBy,
            ]);

            // -----------------------------------------------------------------
            // 2. Write the ledger entry.
            //
            // `WalletService::recordEntry()` requires a surrounding
            // transaction (its defensive check would throw otherwise) and
            // acquires the wallet row lock for the duration. The signed
            // quantity is the positive payment amount: receiving a payment
            // credits the member's position.
            //
            // No component is passed — payments are not component-tagged;
            // the meaning is established later by allocations.
            // -----------------------------------------------------------------
            $this->walletService->recordEntry(
                wallet: $wallet,
                signedQuantity: $signedQuantity,
                entryType: WalletEntryType::PaymentReceived,
                component: null,
                referenceType: self::LEDGER_REFERENCE_TYPE,
                referenceId: (string) $payment->id,
                createdBy: $createdBy,
                occurredAt: $businessTime,
                description: $description,
                reversedEntryId: null,
            );

            // -----------------------------------------------------------------
            // 3. Dispatch the domain event after commit, subject to the
            //    audit feature flag.
            // -----------------------------------------------------------------
            $this->dispatchAfterCommit(new PaymentReceived($payment));

            return $payment->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the wallet is usable as a target for a new payment.
     *
     * Three checks, identical in substance to ObligationService's
     * `assertWalletUsable()` (2B.16):
     *
     *   1. The wallet must be persisted. A transient model has no id and
     *      cannot receive child rows.
     *
     *   2. The wallet must carry a non-empty currency. The currency is set
     *      at wallet creation and is immutable (A10); an empty value
     *      indicates a data anomaly or an external edit that bypassed the
     *      model guard.
     *
     *   3. The wallet must carry a valid ISO 4217 code (three ASCII
     *      letters). The same format rule applied at wallet-creation time
     *      (2B.15 §"assertCurrencyFormat") is reasserted here so that a
     *      payment cannot be created against a wallet whose currency
     *      column has been corrupted externally.
     *
     * The check does NOT verify the tenant scope. Tenant scoping is
     * established by the controller's preceding `WalletRepository::findForTenant()`
     * lookup (Step 7 v1.1.0 §C.6); the service trusts the resolved model.
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
                    'The wallet has no currency. Payments cannot be '
                    . 'recorded against a wallet with a missing currency.',
                ],
            ]);
        }

        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet currency is not a valid ISO 4217 code. '
                    . 'This indicates a data anomaly; the payment is '
                    . 'rejected rather than recorded against a malformed wallet.',
                ],
            ]);
        }
    }

    /**
     * Assert that the amount is a well-formed positive decimal string.
     *
     * The amount is a magnitude — the caller supplies the positive value,
     * and the service passes it to the ledger unchanged. The checks are:
     *
     *   - Non-empty, matches `/^\d+(\.\d{1,4})?$/` (no sign, at most four
     *     decimals).
     *   - Strictly greater than zero (a zero-amount payment is
     *     meaningless).
     *
     * A negative input is rejected here, not silently accepted with a
     * flipped sign. The service does not have a "sign of the amount"
     * concept; the caller's intent is expressed through the operation
     * (record a payment), not through the value.
     *
     * The validation is byte-for-byte identical to ObligationService's
     * `assertAmountIsWellFormed()` (2B.16 §"assertAmountIsWellFormed").
     * Duplication is deliberate: the two services are independent write
     * paths, and neither should depend on the other's validation internals.
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
                    'The payment amount must be a positive decimal number '
                    . 'with at most four decimal places.',
                ],
            ]);
        }

        if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount must be strictly greater than zero.',
                ],
            ]);
        }
    }

    /**
     * Assert that the reference pair is all-or-nothing.
     *
     * A payment may carry an opaque reference to a consuming-domain
     * business object. The pair is optional; when supplied it must be
     * complete. A caller that supplies only one half of the pair is
     * expressing an intent the payment cannot represent, and receives a
     * ValidationException.
     *
     * The validation mirrors ObligationService's
     * `assertReferencePairIsConsistent()` (2B.16) verbatim.
     *
     * @param string|null $referenceType
     * @param string|null $referenceId
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReferencePairIsConsistent(
        ?string $referenceType,
        ?string $referenceId,
    ): void {
        $hasType = $referenceType !== null && $referenceType !== '';
        $hasId   = $referenceId !== null && $referenceId !== '';

        if ($hasType !== $hasId) {
            throw ValidationException::withMessages([
                'reference_type' => [
                    'The reference_type and reference_id must be supplied '
                    . 'together, or omitted together.',
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
     * is false, no domain event fires; the payment and its ledger entry
     * are still written.
     *
     * `DB::afterCommit()` registers a callback that runs after the
     * OUTERMOST transaction commits. If no transaction is active, the
     * callback runs immediately — a defensive case for tests or scripts
     * that call the service outside a transaction. In production, `record()`
     * always wraps in a transaction, so the immediate branch is not
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