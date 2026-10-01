<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationCancelled;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationRecorded;
use MaaleArna\MASES\Core\Domains\Wallet\Events\ObligationWrittenOff;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;

/**
 * ObligationService
 *
 * The write path for the `Obligation` scoped business aggregate.
 *
 * =============================================================================
 * Public API
 * =============================================================================
 * Two operations:
 *
 *     record()    — record an obligation against a wallet and post the
 *                   corresponding ledger entry.
 *
 *     discharge() — reduce an obligation's outstanding amount to zero
 *                   and post the corresponding discharge ledger entry.
 *                   Added by CR-2C-WALLET-OBLIGATION-LIFECYCLE-001.
 *
 * The service exposes no general-purpose update or delete method. The
 * only writes it performs against an `Obligation` row are the row's
 * creation (`record()`) and the zeroing of `outstanding_amount`
 * (`discharge()`). The obligation's economic facts — component,
 * currency, `original_amount`, `occurred_at` — are immutable; see
 * Architecture §6.1.
 *
 * The `outstanding_amount` projection has two legitimate writers:
 *
 *   - `AllocationService` (2B.18), when allocations and
 *     allocation-reversals are recorded against the obligation;
 *   - this service's `discharge()`, when a Loan-domain operation
 *     (restructure, write-off) discharges the obligation entirely.
 *
 * No other writer mutates `outstanding_amount`.
 *
 * =============================================================================
 * What an "obligation record" operation does
 * =============================================================================
 * `record()` performs, in order, inside one database transaction:
 *
 *   1. Validates the wallet (persisted, has a non-empty currency).
 *   2. Validates the input (component, positive amount, optional due date,
 *      optional reference pair).
 *   3. Creates the Obligation row with:
 *         original_amount    = the caller's amount
 *         outstanding_amount = the same amount (no allocations yet)
 *         currency           = the wallet's currency (Q8 — inherited,
 *                              never supplied by the caller)
 *   4. Calls WalletService::recordEntry() (2B.15) to write the ledger
 *      entry:
 *         entry_type      = obligation_recorded
 *         signed_quantity = −(original_amount)      [Architecture §4.2]
 *         component       = the caller's component
 *         reference_type  = 'Obligation'
 *         reference_id    = (string) the newly-created obligation's id
 *   5. Registers an after-commit dispatch of ObligationRecorded.
 *
 * The wallet row is locked by `recordEntry()` for the duration of the
 * transaction, serialising concurrent obligation writes against the same
 * wallet. See Architecture §4.7 for the deterministic lock order.
 *
 * =============================================================================
 * What a "discharge" operation does
 * =============================================================================
 * `discharge()` performs, in order, inside one database transaction:
 *
 *   1. Validates the entry type — only `ObligationCancelled` and
 *      `ObligationWrittenOff` are accepted. A different value is an
 *      internal caller-contract violation (the two Loan-domain callers
 *      pass only these two cases), thrown as `LogicException`.
 *
 *   2. Captures the obligation's current `outstanding_amount`.
 *
 *   3. Sets the obligation's `outstanding_amount` to `0.0000`.
 *
 *   4. Calls `WalletService::recordEntry()` to write the discharge
 *      ledger entry:
 *         entry_type      = the caller-supplied discharge type
 *         signed_quantity = +(the captured outstanding amount)
 *         component       = the obligation's component
 *         reference_type  = the caller-supplied reference type
 *         reference_id    = the caller-supplied reference id
 *         occurred_at     = now()
 *         description     = the caller-supplied reason
 *
 *   5. Registers an after-commit dispatch of the event that corresponds
 *      to the entry type:
 *         obligation_cancelled   → ObligationCancelled
 *         obligation_written_off → ObligationWrittenOff
 *
 * The obligation row's `outstanding_amount` is the ONLY obligation-side
 * mutation. Historical `PaymentAllocation` rows are NOT modified: they
 * are append-only historical facts about how the borrower's payments
 * were attributed before the discharge, and the discharge is a new
 * event in the obligation's life, not a retroactive rewrite of its
 * history.
 *
 * The positive signed quantity is the mirror of `record()`'s negative
 * signed quantity: recording an obligation debits the member's
 * position; discharging one credits the same position by the same
 * amount. The Wallet ledger therefore reconciles: the sum of
 * `obligation_recorded` and `obligation_cancelled` /
 * `obligation_written_off` entries for a given obligation is zero after
 * a full discharge.
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * An obligation is a debt the member owes. Under the member-position sign
 * convention (Architecture §4.2), recording an obligation produces a
 * NEGATIVE ledger entry. The caller supplies the POSITIVE magnitude; this
 * service applies the negative sign at the ledger-write boundary.
 *
 * Consequence: the service's API does not accept a signed amount, and does
 * not expose a direction parameter. An obligation always debits the
 * member's position. If a future CR needs a credit-side obligation (an
 * amount the organization owes the member), that is a new operation with
 * its own semantics — not a variant of `record()`.
 *
 * The discharge operations (`discharge()`) carry the mirror sign: a
 * positive ledger entry, crediting the member's position by the
 * discharged amount. The two signs are orthogonal to the caller's
 * intent: `record()` always debits; `discharge()` always credits.
 *
 * =============================================================================
 * Currency inheritance (Q8, A10)
 * =============================================================================
 * The service does not accept a `$currency` parameter. The obligation's
 * currency is set to `$wallet->currency` unconditionally. This is the
 * cleanest possible enforcement of the Q8 invariant
 * (`wallet.currency == obligation.currency`): the caller cannot supply a
 * mismatched currency because the caller cannot supply a currency at all.
 *
 * The wallet's currency is itself immutable (A10, enforced by
 * `Wallet::booted()`); the obligation's currency is therefore fixed for
 * its lifetime, matching the immutable-facts posture of 2B.6.
 *
 * =============================================================================
 * Component (D109)
 * =============================================================================
 * Every obligation carries exactly one `ObligationComponent` case:
 * principal, interest, fee, penalty, tax, or other. The component is
 * REQUIRED — it is a non-nullable argument on the method signature, and
 * the ledger entry writes it into the `component` column of `wallet_entries`.
 *
 * The service does not validate the component against any higher-level
 * business rule. In particular, it does not require interest obligations
 * to reference an interest policy, nor fee obligations to reference an
 * allocation policy. Those are policy-time concerns for future CRs; in
 * Phase 2B the component is a pure descriptive tag on the obligation and
 * the ledger entry.
 *
 * `discharge()` carries the discharged obligation's component onto the
 * corresponding discharge ledger entry's `component` column, so a reader
 * of the ledger can see WHICH component was cancelled or written off.
 * `WalletService::assertComponentConsistency()` permits a non-null
 * component on both discharge entry types for exactly this reason.
 *
 * =============================================================================
 * Reference pair (D109)
 * =============================================================================
 * The obligation may carry a `reference_type` + `reference_id` pair
 * identifying a business object in a consuming domain (a loan, a
 * subscription, a share issuance). The pair is opaque to this service;
 * it is stored as supplied.
 *
 * The pair is OPTIONAL and MUST be all-or-nothing: a caller that supplies
 * one without the other receives a ValidationException. The ledger-entry
 * primitive (2B.15) does not enforce this pairing on the obligation's
 * behalf — the obligation row's reference pair is distinct from the
 * ledger entry's reference pair (which points at the obligation itself).
 *
 * `discharge()` does not carry a reference pair on the obligation row
 * (the obligation's reference pair is set at creation and is immutable);
 * the caller-supplied pair for the discharge is written on the discharge
 * ledger entry, referencing the Loan-domain row that caused the discharge
 * (`'LoanRestructure'` or `'LoanWriteOff'`, with the primary key of the
 * restructuring or write-off row as the id).
 *
 * =============================================================================
 * Transaction and event discipline
 * =============================================================================
 * `record()` and `discharge()` each open their own transaction. Inside
 * the transaction they perform their obligation-side writes and call
 * `WalletService::recordEntry()`, which requires a surrounding
 * transaction (its defensive check throws `LogicException` otherwise)
 * and acquires the wallet row lock.
 *
 * The `ObligationRecorded`, `ObligationCancelled`, and
 * `ObligationWrittenOff` events are dispatched via `DB::afterCommit()`,
 * gated on `config('wallet.audit.emit_events')` (2B.1). If the
 * transaction rolls back, the callback is discarded and the event does
 * not fire. If `emit_events` is false, the callback is not registered;
 * the obligation and its ledger entry are still written.
 *
 * =============================================================================
 * Dependencies
 * =============================================================================
 * The constructor takes ONE dependency: `WalletService` (2B.15), used for
 * its `recordEntry()` primitive.
 *
 * This deviates from the summary in Architecture §16.2, which lists
 * `WalletRepository` and `ObligationRepository` as `ObligationService`
 * dependencies. The deviation is deliberate and is authorized by 2B.15's
 * class docblock §"The ledger-write primitive (recordEntry)":
 *
 *     "`recordEntry()` is PUBLIC because ObligationService and
 *      PaymentService write to the ledger through it."
 *
 * The service does not use the two repositories:
 *
 *   - `WalletRepository` is not needed: the controller resolves the
 *     target wallet through `WalletRepository::findForTenant()` before
 *     invoking this service, and passes the hydrated `Wallet` model in.
 *   - `ObligationRepository` is not needed: this service's only writes
 *     are a creation and a projection-zeroing, and it exposes no lookup
 *     methods (controllers use the repository directly for reads).
 *
 * Injecting either would be dead weight and would violate the "no code
 * without context" discipline (Master Prompt §73). The architecture's
 * §16.2 summary is a catalogue of possible dependencies, not a mandate.
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not evaluate policies. No interest accrual, no fee
 *     computation, no KYC check. An obligation's amount is supplied as-is
 *     by the caller.
 *
 *   - It does not allocate. Allocation is explicit and separate
 *     (AllocationService, 2B.18).
 *
 *   - It does not reverse a ledger entry. Reversals are issued through
 *     `WalletService::reverseEntry()`, which operates on ledger entries,
 *     not on obligations. A discharge is not a reversal: the discharge
 *     writes a NEW entry of the discharge type and zeroes the
 *     obligation's outstanding; a reversal writes a NEW entry of type
 *     `reversal` that numerically undoes a prior entry without touching
 *     any obligation's outstanding projection.
 *
 *   - It does not mutate `outstanding_amount` outside the two operations
 *     named in §"Public API". Outside `discharge()` and the
 *     AllocationService write path, no code updates the projection. In
 *     particular, `record()` only writes `outstanding_amount` at
 *     row-creation time (to equal `original_amount`); it does not mutate
 *     the projection of any pre-existing obligation.
 *
 *   - It does not enforce a due-date ordering relative to `occurred_at`.
 *     A retrospective obligation with a past due date is a legitimate
 *     case (recording a debt that has already matured). A future CR can
 *     add ordering if business policy requires it.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   D109                         — Obligation types
 *   Architecture v1.2.0 §3.3    — Obligation entity
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §6.1    — Obligation projection
 *   Architecture v1.2.0 §9      — Interest-policy contract boundary
 *   Architecture v1.2.0 §16.2   — Services (and the deviation noted above)
 *   Architecture v1.2.0 §19     — Q8 resolution
 *   Phase 2C A.34                — `LoanRestructuringService` (produces
 *                                  obligation_cancelled discharges)
 *   Phase 2C A.35                — `LoanWriteOffService` (produces
 *                                  obligation_written_off discharges)
 *   CR-2C-WALLET-REFTYPE-001     — Wallet ref-type map (accepted the
 *                                  Loan-domain reference types for the
 *                                  two discharge entry types)
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 — this CR; introduces
 *                                  `discharge()` and its two events
 *   Step 5 (manifest) 2B.16     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Services
 * @version 1.1.0
 */
final class ObligationService
{
    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.50.
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * The reference_type value this service writes to ledger entries it
     * causes. Frozen by Architecture §5.3.
     */
    private const LEDGER_REFERENCE_TYPE = 'Obligation';

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
     * Record an obligation against a wallet.
     *
     * The caller supplies a POSITIVE decimal-string amount (the magnitude
     * of the debt). The service applies the negative sign at the
     * ledger-write boundary, producing the debit entry required by the
     * member-position sign convention (Architecture §4.2).
     *
     * The obligation's currency is inherited from the wallet and cannot
     * be supplied by the caller (Q8). See the class docblock §"Currency
     * inheritance".
     *
     * The operation runs inside a single database transaction that:
     *
     *   1. Creates the Obligation row.
     *   2. Calls WalletService::recordEntry() to write the corresponding
     *      ledger entry and update the wallet's `current_balance`.
     *
     * Both writes commit atomically. If the ledger write fails (e.g. a
     * malformed component, a rejected reference pairing), the obligation
     * row is rolled back with it — the two never diverge.
     *
     * `ObligationRecorded` is dispatched after commit, gated on
     * `config('wallet.audit.emit_events')`.
     *
     * @param Wallet                    $wallet        The target wallet.
     * @param ObligationComponent       $component     The economic component.
     * @param string                    $amount        Positive decimal string, at most 4 decimals.
     * @param CarbonInterface|null      $occurredAt    Business time; defaults to now.
     * @param CarbonInterface|null      $dueAt         Optional maturity.
     * @param string|null               $referenceType Optional origin label.
     * @param string|null               $referenceId   Optional origin identifier.
     * @param string|null               $description   Optional free text.
     * @param int|null                  $createdBy     Optional actor user id.
     * @return Obligation
     *
     * @throws ValidationException
     */
    public function record(
        Wallet $wallet,
        ObligationComponent $component,
        string $amount,
        ?CarbonInterface $occurredAt = null,
        ?CarbonInterface $dueAt = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?int $createdBy = null,
    ): Obligation {
        $this->assertWalletUsable($wallet);
        $this->assertAmountIsWellFormed($amount);
        $this->assertReferencePairIsConsistent($referenceType, $referenceId);

        $signedQuantity = bcmul($amount, '-1', self::MAX_DECIMAL_SCALE);

        return DB::transaction(function () use (
            $wallet,
            $component,
            $amount,
            $signedQuantity,
            $occurredAt,
            $dueAt,
            $referenceType,
            $referenceId,
            $description,
            $createdBy,
        ): Obligation {
            $businessTime = $occurredAt !== null
                ? Carbon::instance($occurredAt)
                : Carbon::now();

            // -----------------------------------------------------------------
            // 1. Create the Obligation row.
            //
            // `outstanding_amount` starts equal to `original_amount` because
            // no allocation has yet been recorded against this obligation
            // (Architecture §6.1).
            //
            // `currency` is inherited from the wallet. The caller has no
            // input channel for it (Q8).
            // -----------------------------------------------------------------
            /** @var Obligation $obligation */
            $obligation = Obligation::create([
                'wallet_id'          => $wallet->id,
                'component'          => $component->value,
                'currency'           => $wallet->currency,
                'original_amount'    => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'outstanding_amount' => bcadd('0', $amount, self::MAX_DECIMAL_SCALE),
                'occurred_at'        => $businessTime,
                'due_at'             => $dueAt !== null ? Carbon::instance($dueAt) : null,
                'reference_type'     => $referenceType,
                'reference_id'       => $referenceId,
                'description'        => $description,
                'created_by'         => $createdBy,
            ]);

            // -----------------------------------------------------------------
            // 2. Write the ledger entry.
            //
            // `WalletService::recordEntry()` requires a surrounding
            // transaction (its defensive check would throw otherwise) and
            // acquires the wallet row lock for the duration. The signed
            // quantity is the negation of the obligation amount: recording
            // an obligation debits the member's position.
            // -----------------------------------------------------------------
            $this->walletService->recordEntry(
                wallet: $wallet,
                signedQuantity: $signedQuantity,
                entryType: WalletEntryType::ObligationRecorded,
                component: $component,
                referenceType: self::LEDGER_REFERENCE_TYPE,
                referenceId: (string) $obligation->id,
                createdBy: $createdBy,
                occurredAt: $businessTime,
                description: $description,
                reversedEntryId: null,
            );

            // -----------------------------------------------------------------
            // 3. Dispatch the domain event after commit, subject to the
            //    audit feature flag.
            // -----------------------------------------------------------------
            $this->dispatchAfterCommit(new ObligationRecorded($obligation));

            return $obligation->refresh();
        });
    }

    /**
     * Discharge an outstanding obligation.
     *
     * Reduces the obligation's `outstanding_amount` to zero and posts the
     * corresponding discharge entry to the wallet ledger. See the class
     * docblock §"What a \"discharge\" operation does" for the full
     * sequence.
     *
     * The entry type selects the semantic:
     *
     *     obligation_cancelled   — the obligation's terms are being
     *                              replaced (loan-restructure path).
     *     obligation_written_off — the obligation is forgiven (loan
     *                              write-off path).
     *
     * Both are recorded as different Wallet entry types and announced by
     * different domain events, so the Wallet's audit trail preserves the
     * distinction.
     *
     * `reason` is recorded on the discharge ledger entry's `description`
     * column. The `referenceType` and `referenceId` are the caller's
     * Loan-side reference pair (e.g. `'LoanRestructure'` + the
     * restructuring row id) and are stored verbatim on the ledger entry.
     *
     * Existing payment allocations against the obligation are NOT
     * mutated; the discharge is a new event in the obligation's life, not
     * a retroactive rewrite of its payment history.
     *
     * @param Wallet           $wallet        The borrower's wallet.
     * @param Obligation       $obligation    The obligation to discharge.
     * @param WalletEntryType  $entryType     One of `ObligationCancelled`
     *                                        or `ObligationWrittenOff`.
     * @param string           $reason        Non-empty justification.
     * @param string           $referenceType The Loan-side reference type
     *                                        (`'LoanRestructure'` or
     *                                        `'LoanWriteOff'`).
     * @param string           $referenceId   The Loan-side reference id
     *                                        (the primary key of the
     *                                        restructuring or write-off
     *                                        row, as a string).
     * @param int|null         $createdBy     Optional actor user id.
     * @return Obligation  The refreshed obligation (outstanding = 0.0000).
     *
     * @throws LogicException        When `$entryType` is not a discharge type.
     * @throws ValidationException
     */
    public function discharge(
        Wallet $wallet,
        Obligation $obligation,
        WalletEntryType $entryType,
        string $reason,
        string $referenceType,
        string $referenceId,
        ?int $createdBy = null,
    ): Obligation {
        // ---------------------------------------------------------------------
        // 1. Guard the entry type.
        //
        // `discharge()` is a discharge-specific operation; only the two
        // discharge entry types are meaningful. A different value is an
        // internal caller-contract violation (the two Loan-domain callers
        // pass only these two cases), so it is a `LogicException` — not a
        // `ValidationException`. See the class docblock §"What a
        // \"discharge\" operation does".
        // ---------------------------------------------------------------------
        if (
            $entryType !== WalletEntryType::ObligationCancelled
            && $entryType !== WalletEntryType::ObligationWrittenOff
        ) {
            throw new LogicException(
                sprintf(
                    'ObligationService::discharge() accepts only the '
                    . 'ObligationCancelled and ObligationWrittenOff entry '
                    . 'types; got "%s".',
                    $entryType->value,
                )
            );
        }

        $dischargedAmount = (string) $obligation->outstanding_amount;

        return DB::transaction(function () use (
            $wallet,
            $obligation,
            $entryType,
            $reason,
            $referenceType,
            $referenceId,
            $createdBy,
            $dischargedAmount,
        ): Obligation {
            // -----------------------------------------------------------------
            // 2. Zero the obligation's outstanding projection.
            //
            // The obligation row is the discharged subject; its
            // `outstanding_amount` is the projection being reset. No
            // other obligation-side field is mutated.
            // -----------------------------------------------------------------
            $obligation->outstanding_amount = '0.0000';
            $obligation->save();

            // -----------------------------------------------------------------
            // 3. Write the discharge ledger entry.
            //
            // The signed quantity is POSITIVE — the mirror of
            // `obligation_recorded`'s negative signed quantity. The
            // component is carried from the obligation so that a reader
            // of the ledger can see WHICH component was discharged.
            // The reason is recorded on the entry's `description`; the
            // caller's Loan-side reference pair is stored verbatim.
            // -----------------------------------------------------------------
            $this->walletService->recordEntry(
                wallet: $wallet,
                signedQuantity: bcadd('0', $dischargedAmount, self::MAX_DECIMAL_SCALE),
                entryType: $entryType,
                component: $obligation->component,
                referenceType: $referenceType,
                referenceId: $referenceId,
                createdBy: $createdBy,
                occurredAt: null,
                description: $reason,
                reversedEntryId: null,
            );

            // -----------------------------------------------------------------
            // 4. Dispatch the operation-specific event after commit,
            //    subject to the audit feature flag.
            //
            // The guard at step 1 makes the `match` exhaustive; no
            // default arm is required.
            // -----------------------------------------------------------------
            $event = match ($entryType) {
                WalletEntryType::ObligationCancelled => new ObligationCancelled($obligation),
                WalletEntryType::ObligationWrittenOff => new ObligationWrittenOff($obligation),
            };

            $this->dispatchAfterCommit($event);

            return $obligation->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the wallet is usable as a target for a new obligation.
     *
     * Three checks:
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
     *      (2B.15 §"assertCurrencyFormat") is reasserted here so that an
     *      obligation cannot be created against a wallet whose currency
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
                    'The wallet has no currency. Obligations cannot be '
                    . 'recorded against a wallet with a missing currency.',
                ],
            ]);
        }

        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'wallet_id' => [
                    'The wallet currency is not a valid ISO 4217 code. '
                    . 'This indicates a data anomaly; the obligation is '
                    . 'rejected rather than recorded against a malformed wallet.',
                ],
            ]);
        }
    }

    /**
     * Assert that the amount is a well-formed positive decimal string.
     *
     * The amount is a magnitude — the caller supplies the positive value,
     * and the service applies the negative sign at the ledger-write
     * boundary. The checks are:
     *
     *   - Non-empty, matches `/^\d+(\.\d{1,4})?$/` (no sign, at most four
     *     decimals).
     *   - Strictly greater than zero (a zero-amount obligation is
     *     meaningless).
     *
     * A negative input is rejected here, not silently accepted with a
     * flipped sign. The service does not have a "sign of the amount"
     * concept; the caller's intent is expressed through the operation
     * (record an obligation), not through the value.
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
                'original_amount' => [
                    'The obligation amount must be a positive decimal number '
                    . 'with at most four decimal places.',
                ],
            ]);
        }

        if (bccomp($amount, '0', self::MAX_DECIMAL_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'original_amount' => [
                    'The obligation amount must be strictly greater than zero.',
                ],
            ]);
        }
    }

    /**
     * Assert that the reference pair is all-or-nothing.
     *
     * An obligation may carry an opaque reference to a consuming-domain
     * business object. The pair is optional; when supplied it must be
     * complete. A caller that supplies only one half of the pair is
     * expressing an intent the obligation cannot represent, and receives
     * a ValidationException.
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
     * is false, no domain event fires; the obligation and its ledger entry
     * are still written.
     *
     * `DB::afterCommit()` registers a callback that runs after the
     * OUTERMOST transaction commits. If no transaction is active, the
     * callback runs immediately — a defensive case for tests or scripts
     * that call the service outside a transaction. In production,
     * `record()` and `discharge()` always wrap in a transaction, so the
     * immediate branch is not exercised.
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