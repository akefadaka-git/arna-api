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
 * Records immutable payment facts and the matching ledger entry in one transaction; allocation is separate and updates only projections.
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
     *   1. Locks and refreshes the Wallet row before inserting any child
     *      row with a Wallet foreign key. This avoids concurrent InnoDB
     *      foreign-key shared-lock upgrades deadlocking on the Wallet.
     *   2. Creates the Payment row.
     *   3. Calls WalletService::recordEntry() to write the corresponding
     *      ledger entry and update the wallet's `current_balance` under
     *      the already-held Wallet lock.
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
            // Lock and refresh the Wallet before inserting wallet_payments.
            // InnoDB takes a shared parent-row lock for the child FK check;
            // inserting first and then upgrading to FOR UPDATE lets two
            // concurrent payment writers deadlock while both hold that
            // shared lock. Taking the exclusive lock first serializes them.
            /** @var Wallet $lockedWallet */
            $lockedWallet = Wallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $businessTime = $receivedAt !== null
                ? Carbon::instance($receivedAt)
                : Carbon::now();

            // -----------------------------------------------------------------
            // 2. Create the Payment row.
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
                'wallet_id'        => $lockedWallet->id,
                'currency'         => $lockedWallet->currency,
                'amount'           => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'unapplied_amount' => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'received_at'      => $businessTime,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'description'      => $description,
                'created_by'       => $createdBy,
            ]);

            // -----------------------------------------------------------------
            // 3. Write the ledger entry.
            //
            // `WalletService::recordEntry()` requires a surrounding
            // transaction (its defensive check would throw otherwise) and
            // re-acquires the same Wallet row lock within this transaction.
            // The lock was taken before the Payment insert to avoid an InnoDB
            // foreign-key shared-lock upgrade deadlock. The signed quantity
            // is positive: receiving a payment credits the member's position.
            //
            // No component is passed — payments are not component-tagged;
            // the meaning is established later by allocations.
            // -----------------------------------------------------------------
            $this->walletService->recordEntry(
                wallet: $lockedWallet,
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
            // 4. Dispatch the domain event after commit, subject to the
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