<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\ObligationComponent;
use MaaleArna\MASES\Core\Domains\Wallet\Enums\WalletEntryType;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletAdjusted;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletEntryReversed;
use MaaleArna\MASES\Core\Domains\Wallet\Events\WalletOpened;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Wallet;
use MaaleArna\MASES\Core\Domains\Wallet\Models\WalletEntry;
use MaaleArna\MASES\Core\Domains\Wallet\Repositories\WalletRepository;

/**
 * WalletService
 *
 * The write path for the Wallet aggregate root and the sole primitive for
 * writing to the wallet ledger.
 *
 * =============================================================================
 * Public API
 * =============================================================================
 * Three wallet-level operations, plus one ledger-write primitive:
 *
 *     open()         — explicit wallet creation (Q4). The ONLY path that
 *                      creates a Wallet row.
 *
 *     adjust()       — operator-initiated balance adjustment (Q3,
 *                      privileged). Emits WalletAdjusted.
 *
 *     reverseEntry() — reversal of a previously posted ledger entry
 *                      (Architecture §4.3). Emits WalletEntryReversed.
 *
 *     recordEntry()  — the ledger-write primitive used by adjust(),
 *                      reverseEntry(), and the sibling services
 *                      (ObligationService 2B.16, PaymentService 2B.17,
 *                      and — after CR-2C-WALLET-OBLIGATION-LIFECYCLE-001
 *                      — ObligationService::discharge()).
 *
 * =============================================================================
 * The ledger-write primitive (recordEntry)
 * =============================================================================
 * All six entry types defined by WalletEntryType (2B.4, extended by
 * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001) are written through
 * `recordEntry()`:
 *
 *     obligation_recorded      ← ObligationService::record()
 *     payment_received         ← PaymentService::record()
 *     adjustment               ← WalletService::adjust() (this class)
 *     reversal                 ← WalletService::reverseEntry() (this class)
 *     obligation_cancelled     ← ObligationService::discharge() with
 *                                entryType = ObligationCancelled — the
 *                                Phase 2C loan-restructure path (A.34)
 *     obligation_written_off   ← ObligationService::discharge() with
 *                                entryType = ObligationWrittenOff — the
 *                                Phase 2C loan write-off path (A.35)
 *
 * Centralising the write path guarantees that the invariants of
 * Architecture §4.6 hold for every entry ever written:
 *
 *     balance_before + signed_quantity = balance_after        (per entry)
 *     wallet.current_balance = latest entry's balance_after   (projection)
 *
 * It also guarantees that the row lock (`SELECT ... FOR UPDATE`) is
 * acquired against the wallet on every write, which is the mechanism that
 * serialises concurrent balance changes.
 *
 * `recordEntry()` is PUBLIC because ObligationService and PaymentService
 * write to the ledger through it. The alternative — each service
 * duplicating the ledger-write logic — was rejected: three copies of the
 * same transaction, lock, projection-update, and reference-combination
 * discipline would drift. The single primitive is the invariant.
 *
 * =============================================================================
 * Transaction discipline for recordEntry()
 * =============================================================================
 * `recordEntry()` does NOT open its own transaction and REQUIRES that its
 * caller be inside one. The reason:
 *
 *   - The wallet row lock (`lockForUpdate()`) is only meaningful inside a
 *     transaction. Outside one, the SELECT ... FOR UPDATE would acquire
 *     and immediately release the lock, defeating the purpose.
 *
 *   - The calling operation (adjust, reverse, record-obligation,
 *     record-payment, discharge) owns its transaction, its event dispatch,
 *     and its other writes (e.g. creating the Obligation or Payment row).
 *     The ledger write is one step of a larger operation.
 *
 *   - Event dispatch after commit (DB::afterCommit) is registered by the
 *     CALLER, not by `recordEntry()`. The caller knows which event to
 *     dispatch (WalletAdjusted vs. ObligationRecorded vs. ...); the
 *     primitive is event-agnostic.
 *
 * A defensive check at the top of `recordEntry()` throws `LogicException`
 * if `DB::transactionLevel() === 0`. This is not a caller error — it is a
 * programming defect — hence `LogicException`, not `ValidationException`.
 *
 * =============================================================================
 * Currency handling (Q8, A10)
 * =============================================================================
 * `open()` accepts a currency from the caller and validates its FORMAT
 * (three uppercase ASCII letters, per ISO 4217). It does NOT consult a
 * currency allow-list: the architecture deliberately omits one, and the
 * platform's operating currency is determined by business policy rather
 * than by a schema constraint.
 *
 * Per-currency MINOR-UNIT precision enforcement — the rule stated in
 * Architecture §4.4 ("the service layer enforces the currency minor-unit
 * precision of the recorded currency, using the ISO 4217 minor-unit field
 * as the reference") — is DEFERRED to a future Change Request that
 * introduces a shared currency-precision capability. Phase 2B enforces
 * only:
 *
 *   - currency format: `^[A-Z]{3}$`
 *   - amount scale:     at most 4 decimal places (the schema's bound)
 *
 * The two checks together are conservative: they reject malformed codes
 * and amounts the schema cannot store; they do NOT reject an amount whose
 * precision exceeds a specific currency's minor unit (e.g. a hypothetical
 * "ETB 100.1234" is stored as-is). The deferred capability would tighten
 * this to per-currency precision once the platform's currency policy is
 * frozen.
 *
 * The currency is IMMUTABLE after wallet creation (A10), enforced by the
 * Wallet model's booted() guard (2B.5). This service exposes no update
 * path for currency.
 *
 * =============================================================================
 * Sign convention (Architecture §4.2)
 * =============================================================================
 * The `signed_quantity` column on every ledger entry carries a sign that
 * reflects the member's position:
 *
 *     positive  — credit movement (the organization holds credit)
 *     negative  — debit movement (the member owes)
 *
 * `adjust()` accepts a SIGNED decimal string from the caller; the sign
 * determines the direction. `reverseEntry()` derives the reversal's sign
 * as the numeric negation of the original's. `recordEntry()` accepts a
 * signed string from its caller; the caller is responsible for choosing
 * the correct sign.
 *
 * The two Phase 2C discharge entry types (`obligation_cancelled`,
 * `obligation_written_off`) ALWAYS carry a positive signed quantity equal
 * to the discharged amount; they are the mirror of `obligation_recorded`,
 * which always carries a negative signed quantity.
 *
 * Zero is rejected everywhere. A zero-quantity ledger entry would have no
 * effect and cannot be reversed meaningfully.
 *
 * =============================================================================
 * The reference-type map (Architecture §5.3, extended by
 * CR-2C-WALLET-REFTYPE-001)
 * =============================================================================
 * Architecture §5.3 freezes the mapping from each entry type to its
 * permitted `reference_type` value(s). The mapping is expressed by the
 * `REFERENCE_TYPE_MAP` constant below and enforced by
 * `assertReferenceTypePermitted()`.
 *
 * PHASE 2B (single-valued pairs):
 *
 *     obligation_recorded   → 'Obligation'
 *     payment_received      → 'Payment'
 *     adjustment            → 'Wallet'
 *     reversal              → 'WalletEntry'
 *
 * PHASE 2C (CR-2C-WALLET-REFTYPE-001 — extended to multi-valued pairs):
 *
 *     obligation_recorded   → {'Obligation', 'Loan'}
 *     payment_received      → {'Payment', 'LoanRepayment'}
 *     adjustment            → {'Wallet'}
 *     reversal              → {'WalletEntry'}
 *     obligation_cancelled  → {'Obligation', 'LoanRestructure'}
 *     obligation_written_off→ {'Obligation', 'LoanWriteOff'}
 *
 * The Phase 2C additions let the Loan domain write obligations and
 * payments under its own reference vocabulary without disturbing the
 * Phase 2B producers:
 *
 *   - `'Loan'` is the reference_type the Loan domain uses when recording
 *     principal, interest, penalty, and fee obligations against a loan
 *     (A.30, A.32, A.33). The `reference_id` is `(string) $loan->id`.
 *
 *   - `'LoanRepayment'` is the reference_type the Loan domain uses when
 *     recording a repayment received from the borrower (A.31). The
 *     `reference_id` is `(string) $loan->id`.
 *
 *   - `'LoanRestructure'` is the reference_type the restructure flow uses
 *     when discharging an obligation (A.34, via
 *     `ObligationService::discharge()`). The `reference_id` is the
 *     primary key of the `loan_restructurings` row.
 *
 *   - `'LoanWriteOff'` is the reference_type the write-off flow uses when
 *     discharging an obligation (A.35, via
 *     `ObligationService::discharge()`). The `reference_id` is the
 *     primary key of the `loan_write_offs` row.
 *
 * `'Obligation'` remains in the permit list for `obligation_cancelled`
 * and `obligation_written_off` to allow a future non-Loan producer to
 * discharge an obligation under the generic `'Obligation'` reference.
 * Phase 2C has no such producer; the Loan domain always uses the
 * `'LoanRestructure'` or `'LoanWriteOff'` alternate.
 *
 * The `reference_type` value stored is the short class basename — not the
 * fully-qualified namespace — matching the Phase 2A convention for
 * `share_holding_entries.reference_type`.
 *
 * =============================================================================
 * Immutability of posted entries
 * =============================================================================
 * This service exposes NO update and NO delete method for WalletEntry
 * rows. The ledger is append-only (Architecture §3.7). Corrections flow
 * through `reverseEntry()`, which writes a NEW entry whose
 * `reversed_entry_id` points at the original. The two Phase 2C discharge
 * entry types participate in the same discipline: a discharge is a NEW
 * entry, and the original `obligation_recorded` entry is never modified.
 *
 * The `WalletEntry` model (2B.10) provides a second defense layer: it
 * throws `LogicException` on any Eloquent-mediated `updating` or
 * `deleting` event.
 *
 * =============================================================================
 * Event dispatch
 * =============================================================================
 * Every event is dispatched via `DB::afterCommit()`, gated on
 * `config('wallet.audit.emit_events')`. When the config is false, no
 * domain event fires; the ledger write itself is unaffected.
 *
 *     open()         → WalletOpened         (2B.40)
 *     adjust()       → WalletAdjusted       (2B.45)
 *     reverseEntry() → WalletEntryReversed  (2B.46)
 *
 * The sibling services dispatch their own events:
 *
 *     ObligationService::record()             → ObligationRecorded      (2B.41)
 *     PaymentService::record()                → PaymentReceived         (2B.42)
 *     ObligationService::discharge()          → ObligationCancelled or
 *                                               ObligationWrittenOff    (Phase 2C)
 *
 * `recordEntry()` does NOT dispatch any event. This is deliberate: the
 * event is owned by the operation, not by the ledger-write primitive. See
 * the class docblock §"Transaction discipline for recordEntry()".
 *
 * =============================================================================
 * What this service deliberately does NOT do
 * =============================================================================
 *   - It does not evaluate interest, allocation, or KYC policies. The
 *     three policy-version references on the wallet (2B.5) are slots for
 *     future capabilities; this service records them if the calling code
 *     supplies them, and never reads their contents.
 *
 *   - It does not create Obligation, Payment, or PaymentAllocation rows.
 *     Those are the concerns of ObligationService (2B.16),
 *     PaymentService (2B.17), and AllocationService (2B.18). This service
 *     writes only WalletEntry rows.
 *
 *   - It does not enforce a balance bound. The wallet's balance may be
 *     positive, zero, or negative; the sign reflects the member's
 *     position. No "insufficient funds" rule exists in Phase 2B.
 *
 *   - It does not enforce currency equality across the wallet's children.
 *     That is ObligationService's and PaymentService's concern, both of
 *     which compare their incoming currency against `wallet.currency`.
 *
 *   - It does not automatically close or reopen wallets. Q5 defers those
 *     operations; `is_active` and `closed_at` are present on the model for
 *     a future CR.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §3.2    — Wallet aggregate root
 *   Architecture v1.2.0 §4.2    — Sign convention
 *   Architecture v1.2.0 §4.3    — Corrections
 *   Architecture v1.2.0 §4.4    — Precision, currency, immutability (Q2, Q8, A10)
 *   Architecture v1.2.0 §4.6    — Balance projection invariant
 *   Architecture v1.2.0 §4.7    — Deterministic lock acquisition order
 *   Architecture v1.2.0 §5.1    — Entry-type vocabulary
 *   Architecture v1.2.0 §5.3    — Permitted reference_type combinations
 *   Architecture v1.2.0 §14.1   — Event inventory
 *   Architecture v1.2.0 §16.2   — Services and their dependencies
 *   Architecture v1.2.0 §19     — Q3, Q4, Q5, Q8 resolutions
 *   Phase 2C Decision Register  — B1 (reference types), B2 (hybrid
 *                                  ledger), D133 (write-off preserves
 *                                  historical arrears)
 *   Phase 2C A.30–A.35          — the Loan-domain producers of the
 *                                  alternate reference types
 *   CR-2C-WALLET-REFTYPE-001    — this change request
 *   CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 — adds `discharge()` and the
 *                                  two discharge entry types
 *   Step 5 (manifest) 2B.15     — This file
 *   Step 7 v1.1.0 §C.6          — Cross-tenant child-validation invariant
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Services
 * @version 1.1.0
 */
final class WalletService
{
    /**
     * The permitted reference_type values for each WalletEntryType.
     *
     * Architecture §5.3 freezes the mapping: each entry type corresponds
     * to one or more permitted `reference_type` strings. The mapping is
     * enforced by `assertReferenceTypePermitted()` below.
     *
     * PHASE 2B baseline (single-valued):
     *
     *     obligation_recorded → ['Obligation']
     *     payment_received    → ['Payment']
     *     adjustment          → ['Wallet']
     *     reversal            → ['WalletEntry']
     *
     * PHASE 2C (CR-2C-WALLET-REFTYPE-001):
     *
     *     obligation_recorded → ['Obligation', 'Loan']
     *     payment_received    → ['Payment', 'LoanRepayment']
     *     adjustment          → ['Wallet']                  (unchanged)
     *     reversal            → ['WalletEntry']             (unchanged)
     *     obligation_cancelled→ ['Obligation', 'LoanRestructure']
     *     obligation_written_off
     *                         → ['Obligation', 'LoanWriteOff']
     *
     * The FIRST element of each list is the "primary" reference type —
     * the value the Wallet domain itself supplies when it originates an
     * entry of that type. `adjust()` and `reverseEntry()` use
     * `primaryReferenceType()` to fetch the primary; the Loan domain's
     * producers (A.30–A.35) supply their alternate directly to
     * `recordEntry()`.
     *
     * The `reference_type` value stored on the ledger row is the short
     * class basename of the source object — not its fully-qualified
     * namespace — matching the Phase 2A convention for
     * `share_holding_entries.reference_type`.
     *
     * @var array<string, list<string>>
     */
    private const REFERENCE_TYPE_MAP = [
        // ---------------------------------------------------------------------
        // Phase 2B entries. The first element of each list is the primary.
        // ---------------------------------------------------------------------
        'obligation_recorded' => ['Obligation', 'Loan'],
        'payment_received'    => ['Payment', 'LoanRepayment'],
        'adjustment'          => ['Wallet'],
        'reversal'            => ['WalletEntry'],

        // ---------------------------------------------------------------------
        // Phase 2C discharge entries (CR-2C-WALLET-OBLIGATION-LIFECYCLE-001).
        //
        // The `'Obligation'` value is the generic discharge reference; the
        // Loan domain's producers supply the alternate (`'LoanRestructure'`
        // or `'LoanWriteOff'`) and the corresponding `reference_id` (the
        // primary key of the restructuring or write-off row).
        // ---------------------------------------------------------------------
        'obligation_cancelled'   => ['Obligation', 'LoanRestructure'],
        'obligation_written_off' => ['Obligation', 'LoanWriteOff'],
    ];

    /**
     * Maximum number of decimal places permitted by the schema.
     *
     * Mirrors the DECIMAL(20,4) columns declared in 2B.49–2B.54.
     */
    private const MAX_DECIMAL_SCALE = 4;

    /**
     * Constructor.
     *
     * @param WalletRepository $walletRepository
     */
    public function __construct(
        private readonly WalletRepository $walletRepository,
    ) {
    }

    // =========================================================================
    // Public API — open
    // =========================================================================

    /**
     * Open a wallet for a shareholder.
     *
     * This is the ONLY path that creates a Wallet row (Q4 — explicit
     * opening). No lazy creation occurs via the obligation or payment
     * paths; those services reject a request against a non-existent wallet
     * with a 404.
     *
     * The operation:
     *
     *   1. Validates the shareholder (persisted, belongs to the tenant).
     *   2. Validates the currency format.
     *   3. Checks (inside the transaction) that no wallet already exists
     *      for the (tenant, shareholder) pair, producing a friendly
     *      ValidationException on conflict.
     *   4. Creates the wallet with `current_balance = 0.0000`,
     *      `is_active = true`, `opened_at = now()`.
     *   5. Dispatches WalletOpened after commit.
     *
     * No ledger entry is produced by opening. Opening is a structural act,
     * not a monetary movement (Architecture §5.1 — the entry-type
     * vocabulary has no `wallet_opened` case).
     *
     * The composite UNIQUE constraint on `(tenant_id, shareholder_id)`
     * (2B.49) is the ultimate guarantee against concurrent opens; the
     * pre-flight check inside the transaction produces the friendly error
     * in the common case.
     *
     * @param int         $tenantId
     * @param Shareholder $shareholder
     * @param string      $currency     ISO 4217 three-letter code.
     * @return Wallet
     *
     * @throws ValidationException
     */
    public function open(int $tenantId, Shareholder $shareholder, string $currency): Wallet
    {
        $this->assertShareholderBelongsToTenant($shareholder, $tenantId);
        $this->assertCurrencyFormat($currency);

        return DB::transaction(function () use ($tenantId, $shareholder, $currency): Wallet {
            $existing = $this->walletRepository->findForShareholder($tenantId, $shareholder->id);

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'shareholder_id' => [
                        'The specified shareholder already has a wallet in this tenant.',
                    ],
                ]);
            }

            /** @var Wallet $wallet */
            $wallet = Wallet::create([
                'tenant_id'        => $tenantId,
                'shareholder_id'   => $shareholder->id,
                'currency'         => strtoupper($currency),
                'current_balance'  => '0.0000',
                'is_active'        => true,
                'opened_at'        => Carbon::now(),
            ]);

            $this->dispatchAfterCommit(new WalletOpened($wallet));

            return $wallet->refresh();
        });
    }

    // =========================================================================
    // Public API — adjust
    // =========================================================================

    /**
     * Record an operator-initiated balance adjustment.
     *
     * This is the ONLY mechanism in Phase 2B by which an operator may
     * change a wallet's monetary balance outside the obligation/payment
     * lifecycle. It exists solely to handle legitimate
     * non-obligation/non-payment corrections (Q3). It is protected by the
     * dedicated `wallet.adjust` permission, held only by `super_admin` in
     * the frozen RBAC matrix.
     *
     * The `$signedAmount` parameter is a SIGNED decimal string:
     *
     *     "+500.0000"   — credit the member (positive movement)
     *     "-500.0000"   — debit the member (negative movement)
     *
     * The sign determines direction. There is no separate `HoldingDirection`
     * parameter; the architecture does not declare a direction enum for
     * the wallet domain.
     *
     * The adjustment is performed inside a transaction. The wallet row is
     * locked (SELECT ... FOR UPDATE) for the duration, so a concurrent
     * adjustment or reversal serializes against this one. The entry is
     * written with `entry_type = 'adjustment'` and the primary
     * `reference_type = 'Wallet'` (the wallet itself is the source).
     *
     * @param Wallet      $wallet
     * @param string      $signedAmount  Signed decimal string; must be non-zero.
     * @param int|null    $createdBy     Optional actor user id.
     * @param string|null $description   Optional free text.
     * @return WalletEntry
     *
     * @throws ValidationException
     */
    public function adjust(
        Wallet $wallet,
        string $signedAmount,
        ?int $createdBy = null,
        ?string $description = null,
    ): WalletEntry {
        $this->assertWalletPersisted($wallet);
        $this->assertSignedQuantityIsWellFormed($signedAmount);

        return DB::transaction(function () use (
            $wallet,
            $signedAmount,
            $createdBy,
            $description,
        ): WalletEntry {
            $entry = $this->recordEntry(
                wallet: $wallet,
                signedQuantity: $signedAmount,
                entryType: WalletEntryType::Adjustment,
                component: null,
                referenceType: $this->primaryReferenceType(WalletEntryType::Adjustment),
                referenceId: (string) $wallet->id,
                createdBy: $createdBy,
                occurredAt: null,
                description: $description,
                reversedEntryId: null,
            );

            $this->dispatchAfterCommit(new WalletAdjusted($entry));

            return $entry;
        });
    }

    // =========================================================================
    // Public API — reverseEntry
    // =========================================================================

    /**
     * Reverse a previously posted ledger entry.
     *
     * The original entry is NOT modified. A new entry is written with
     * `entry_type = 'reversal'`, `reversed_entry_id` pointing at the
     * original, and `signed_quantity` equal to the numeric negation of the
     * original's. See Architecture §4.3.
     *
     * Constraints enforced before persist (Architecture §4.3):
     *
     *   - The original entry must be persisted.
     *   - The original entry must not itself be a reversal
     *     (a reversal cannot be reversed).
     *   - The original entry must not already be reversed (each entry may
     *     be reversed at most once).
     *
     * The reversal is performed inside a transaction. The wallet row is
     * locked for the duration; a concurrent adjustment or reversal
     * serializes against this one.
     *
     * No balance bound is enforced. Reversing an entry that would drive
     * the balance to any legal state (positive, zero, or negative) is
     * permitted. See the class docblock §"What this service deliberately
     * does NOT do".
     *
     * @param WalletEntry $entry
     * @param int|null    $createdBy     Optional actor user id.
     * @param string|null $description   Optional free text.
     * @return WalletEntry  The reversal entry (NOT the original).
     *
     * @throws ValidationException
     */
    public function reverseEntry(
        WalletEntry $entry,
        ?int $createdBy = null,
        ?string $description = null,
    ): WalletEntry {
        $this->assertEntryIsPersisted($entry);
        $this->assertEntryIsNotReversal($entry);
        $this->assertEntryNotAlreadyReversed($entry);

        $wallet = $entry->wallet;

        if ($wallet === null) {
            throw ValidationException::withMessages([
                'entry_id' => ['The entry has no associated wallet.'],
            ]);
        }

        $negated = bcmul((string) $entry->signed_quantity, '-1', self::MAX_DECIMAL_SCALE);

        return DB::transaction(function () use (
            $wallet,
            $entry,
            $negated,
            $createdBy,
            $description,
        ): WalletEntry {
            $reversal = $this->recordEntry(
                wallet: $wallet,
                signedQuantity: $negated,
                entryType: WalletEntryType::Reversal,
                component: null,
                referenceType: $this->primaryReferenceType(WalletEntryType::Reversal),
                referenceId: (string) $entry->id,
                createdBy: $createdBy,
                occurredAt: null,
                description: $description
                    ?? sprintf('Reversal of entry #%d', $entry->id),
                reversedEntryId: (int) $entry->id,
            );

            $this->dispatchAfterCommit(new WalletEntryReversed($reversal));

            return $reversal;
        });
    }

    // =========================================================================
    // Public API — recordEntry (ledger-write primitive)
    // =========================================================================

    /**
     * Write a ledger entry and update the wallet's balance projection.
     *
     * This is the sole write path to `wallet_entries`. It is called by
     * this service's `adjust()` and `reverseEntry()` methods, and by the
     * sibling services `ObligationService::record()` (2B.16),
     * `PaymentService::record()` (2B.17), and — after
     * CR-2C-WALLET-OBLIGATION-LIFECYCLE-001 —
     * `ObligationService::discharge()`.
     *
     * MUST be called from inside a database transaction. The method does
     * NOT open its own transaction; the caller owns the transaction, its
     * other writes, and its event dispatch. A defensive check throws
     * `LogicException` if no transaction is active — see the class
     * docblock §"Transaction discipline for recordEntry()" for the
     * reasoning.
     *
     * What this method does, in order:
     *
     *   1. Validates the inputs (non-empty signed amount, well-formed
     *      reference pairing for the entry type, reversal consistency).
     *   2. Locks the wallet row (SELECT ... FOR UPDATE). This serialises
     *      concurrent writes against the same wallet.
     *   3. Reads the wallet's `current_balance` into `balance_before`.
     *   4. Computes `balance_after = balance_before + signed_quantity`
     *      with bcmath at scale 4.
     *   5. Inserts the ledger entry with `created_at = now()`.
     *   6. Updates the wallet's `current_balance` to `balance_after`.
     *   7. Returns the persisted entry.
     *
     * No event is dispatched by this method. The caller dispatches the
     * event that corresponds to its operation (WalletAdjusted,
     * ObligationRecorded, PaymentReceived, WalletEntryReversed,
     * ObligationCancelled, ObligationWrittenOff).
     *
     * The balance invariant `balance_before + signed_quantity = balance_after`
     * is guaranteed to hold for every row written through this method.
     *
     * @param Wallet                    $wallet
     * @param string                    $signedQuantity  Signed decimal string; must be non-zero.
     * @param WalletEntryType           $entryType
     * @param ObligationComponent|null  $component       Set only for obligation_recorded.
     * @param string|null               $referenceType   Must match the entry type per §5.3
     *                                                   (as extended by CR-2C-WALLET-REFTYPE-001).
     * @param string|null               $referenceId
     * @param int|null                  $createdBy
     * @param CarbonInterface|null      $occurredAt      Business time; defaults to now.
     * @param string|null               $description
     * @param int|null                  $reversedEntryId Set only for reversal entries.
     * @return WalletEntry
     *
     * @throws ValidationException
     * @throws LogicException        When called without an active transaction.
     */
    public function recordEntry(
        Wallet $wallet,
        string $signedQuantity,
        WalletEntryType $entryType,
        ?ObligationComponent $component = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?int $createdBy = null,
        ?CarbonInterface $occurredAt = null,
        ?string $description = null,
        ?int $reversedEntryId = null,
    ): WalletEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'WalletService::recordEntry() must be called from inside a '
                . 'database transaction. The wallet row lock (SELECT ... FOR '
                . 'UPDATE) is only meaningful inside a transaction; outside '
                . 'one, the lock is acquired and immediately released, '
                . 'defeating its purpose.'
            );
        }

        $this->assertWalletPersisted($wallet);
        $this->assertSignedQuantityIsWellFormed($signedQuantity);
        $this->assertReferenceTypePermitted($entryType, $referenceType);
        $this->assertReversalConsistency(
            entryType: $entryType,
            reversedEntryId: $reversedEntryId,
            referenceType: $referenceType,
        );
        $this->assertComponentConsistency($entryType, $component);

        // ---------------------------------------------------------------------
        // Lock the wallet row for the duration of the transaction. This is
        // the mechanism that serialises concurrent balance changes against
        // the same wallet. See Architecture §4.7 for the deterministic lock
        // order that all 2B services respect.
        // ---------------------------------------------------------------------
        /** @var Wallet $locked */
        $locked = Wallet::query()
            ->whereKey($wallet->id)
            ->lockForUpdate()
            ->firstOrFail();

        $balanceBefore = (string) $locked->current_balance;
        $balanceAfter  = bcadd($balanceBefore, $signedQuantity, self::MAX_DECIMAL_SCALE);

        $now = Carbon::now();

        /** @var WalletEntry $entry */
        $entry = WalletEntry::create([
            'wallet_id'         => $locked->id,
            'entry_type'        => $entryType->value,
            'component'         => $component?->value,
            'signed_quantity'   => bcadd('0', $signedQuantity, self::MAX_DECIMAL_SCALE),
            'balance_before'    => $balanceBefore,
            'balance_after'     => $balanceAfter,
            'reference_type'    => $referenceType,
            'reference_id'      => $referenceId,
            'reversed_entry_id' => $reversedEntryId,
            'occurred_at'       => $occurredAt !== null
                ? Carbon::instance($occurredAt)
                : $now,
            'created_at'        => $now,
            'created_by'        => $createdBy,
            'description'       => $description,
        ]);

        $locked->current_balance = $balanceAfter;
        $locked->save();

        return $entry->refresh();
    }

    // =========================================================================
    // Private — reference-type helpers
    // =========================================================================

    /**
     * Return the PRIMARY reference type for the given entry type.
     *
     * The primary is the first element of the entry's list in
     * `REFERENCE_TYPE_MAP`. For `adjustment` and `reversal` it is the
     * only element (`'Wallet'` and `'WalletEntry'` respectively); for the
     * other entry types it is the Phase 2B-canonical value
     * (`'Obligation'`, `'Payment'`, `'Obligation'`, `'Obligation'`).
     *
     * This helper is the correct way for Wallet-domain code to obtain the
     * reference type it should supply when it originates an entry of a
     * given type. The Loan domain's producers (A.30–A.35) supply their
     * alternate reference types directly to `recordEntry()` and do NOT
     * use this helper.
     *
     * @param WalletEntryType $entryType
     * @return string
     *
     * @throws LogicException  When the entry type has no mapping.
     */
    private function primaryReferenceType(WalletEntryType $entryType): string
    {
        $list = self::REFERENCE_TYPE_MAP[$entryType->value] ?? null;

        if ($list === null || $list === []) {
            // Defensive: a future enum case that forgets to extend the map
            // would surface here. The exception is a programming defect,
            // not a caller error, hence LogicException.
            throw new LogicException(
                sprintf(
                    'WalletService has no reference-type mapping for entry '
                    . 'type "%s". Every WalletEntryType case must have an '
                    . 'entry in REFERENCE_TYPE_MAP.',
                    $entryType->value,
                ),
            );
        }

        return $list[0];
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that a wallet is persisted.
     *
     * @param Wallet $wallet
     * @return void
     *
     * @throws ValidationException
     */
    private function assertWalletPersisted(Wallet $wallet): void
    {
        if (!$wallet->exists) {
            throw ValidationException::withMessages([
                'wallet_id' => ['The wallet must be a persisted record.'],
            ]);
        }
    }

    /**
     * Assert that a shareholder is persisted and belongs to the given tenant.
     *
     * The tenant check enforces the invariant recorded in Step 7 v1.1.0
     * §C.6: cross-entity references must be resolved through tenant-scoped
     * lookups. A shareholder belonging to a different tenant is rejected,
     * and the caller (a controller) translates the exception into the same
     * 404 a non-existent shareholder id would produce.
     *
     * @param Shareholder $shareholder
     * @param int         $tenantId
     * @return void
     *
     * @throws ValidationException
     */
    private function assertShareholderBelongsToTenant(Shareholder $shareholder, int $tenantId): void
    {
        if (!$shareholder->exists) {
            throw ValidationException::withMessages([
                'shareholder_id' => ['The shareholder must be a persisted record.'],
            ]);
        }

        if ((int) $shareholder->tenant_id !== $tenantId) {
            throw ValidationException::withMessages([
                'shareholder_id' => [
                    'The shareholder does not belong to the specified tenant.',
                ],
            ]);
        }
    }

    /**
     * Assert that a currency is a plausible ISO 4217 three-letter code.
     *
     * Format only: three uppercase ASCII letters. This is the 2B-scope
     * validation described in the class docblock §"Currency handling".
     * Per-currency minor-unit precision is deferred to a future CR.
     *
     * @param string $currency
     * @return void
     *
     * @throws ValidationException
     */
    private function assertCurrencyFormat(string $currency): void
    {
        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw ValidationException::withMessages([
                'currency' => [
                    'The currency must be a three-letter ISO 4217 code (e.g. ETB, USD).',
                ],
            ]);
        }
    }

    /**
     * Assert that a signed quantity string is well-formed and non-zero.
     *
     * Accepts an optional leading sign, then digits, then an optional
     * decimal part of at most four digits. Rejects empty strings, strings
     * with more than four decimals, and zero values.
     *
     * @param string $signedQuantity
     * @return void
     *
     * @throws ValidationException
     */
    private function assertSignedQuantityIsWellFormed(string $signedQuantity): void
    {
        if (!preg_match('/^[+-]?\d+(\.\d{1,4})?$/', $signedQuantity)) {
            throw ValidationException::withMessages([
                'signed_quantity' => [
                    'The signed quantity must be a decimal number with at '
                    . 'most four decimal places, optionally signed.',
                ],
            ]);
        }

        if (bccomp($signedQuantity, '0', self::MAX_DECIMAL_SCALE) === 0) {
            throw ValidationException::withMessages([
                'signed_quantity' => [
                    'The signed quantity must be non-zero. Zero-value entries '
                    . 'are not permitted on the wallet ledger.',
                ],
            ]);
        }
    }

    /**
     * Assert that the reference type is one of the permitted values for
     * the given entry type.
     *
     * `REFERENCE_TYPE_MAP` declares the permitted set per entry type; the
     * check is a membership test. A `null` reference type is NEVER
     * permitted — every ledger entry must carry a non-null reference
     * pairing, because the ledger is a cross-domain correlation point and
     * a null reference would defeat that purpose.
     *
     * PHASE 2C (CR-2C-WALLET-REFTYPE-001): the check accepts a LIST of
     * permitted reference types per entry type rather than a single
     * string. See the class docblock §"The reference-type map" for the
     * full mapping.
     *
     * @param WalletEntryType $entryType
     * @param string|null     $referenceType
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReferenceTypePermitted(
        WalletEntryType $entryType,
        ?string $referenceType,
    ): void {
        $permitted = self::REFERENCE_TYPE_MAP[$entryType->value] ?? null;

        if ($permitted === null) {
            // Unrecognised entry type — cannot happen for a valid enum case,
            // but the guard is defensive against a future addition that
            // forgets to extend the map.
            throw ValidationException::withMessages([
                'entry_type' => [
                    sprintf(
                        'The entry type "%s" has no permitted reference-type mapping.',
                        $entryType->value,
                    ),
                ],
            ]);
        }

        if ($referenceType === null || !in_array($referenceType, $permitted, true)) {
            throw ValidationException::withMessages([
                'reference_type' => [
                    sprintf(
                        'An entry of type "%s" must use one of the '
                        . 'permitted reference types [%s]; got "%s".',
                        $entryType->value,
                        implode(', ', $permitted),
                        $referenceType ?? '(null)',
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert that reversal-specific fields are consistent with the entry
     * type.
     *
     * A reversal entry MUST have a non-null `reversed_entry_id` and MUST
     * use `reference_type = 'WalletEntry'`. A non-reversal entry MUST have
     * a null `reversed_entry_id`.
     *
     * The check overlaps partially with `assertReferenceTypePermitted()`
     * (which already forces `reference_type = 'WalletEntry'` for reversal
     * entries). The overlap is intentional: the two checks express
     * different invariants (reference pairing vs. reversal linkage), and
     * keeping them separate makes the failure mode clearer.
     *
     * @param WalletEntryType $entryType
     * @param int|null        $reversedEntryId
     * @param string|null     $referenceType
     * @return void
     *
     * @throws ValidationException
     */
    private function assertReversalConsistency(
        WalletEntryType $entryType,
        ?int $reversedEntryId,
        ?string $referenceType,
    ): void {
        $isReversal = $entryType === WalletEntryType::Reversal;

        if ($isReversal && $reversedEntryId === null) {
            throw ValidationException::withMessages([
                'reversed_entry_id' => [
                    'A reversal entry must reference the original entry it reverses.',
                ],
            ]);
        }

        if (!$isReversal && $reversedEntryId !== null) {
            throw ValidationException::withMessages([
                'reversed_entry_id' => [
                    'Only reversal entries may carry a reversed_entry_id.',
                ],
            ]);
        }
    }

    /**
     * Assert that the component column is consistent with the entry type.
     *
     * A component is meaningful only for `obligation_recorded` entries;
     * every other entry type must have a null component. A caller that
     * supplies a component for a non-obligation entry is expressing an
     * intent the ledger does not represent.
     *
     * PHASE 2C NOTE: the two discharge entry types
     * (`obligation_cancelled`, `obligation_written_off`) are produced by
     * `ObligationService::discharge()`. That method carries the discharged
     * obligation's component onto the discharge entry's `component`
     * column, so that a reader of the ledger can see WHICH component was
     * cancelled or written off. To support that, the discharge service
     * does NOT call `recordEntry()` directly with a non-null component;
     * instead, the component consistency rule below is relaxed to permit
     * a component on the two discharge entry types.
     *
     * The rule as enforced:
     *
     *   obligation_recorded      — component REQUIRED (may be non-null)
     *   obligation_cancelled     — component PERMITTED (may be null or
     *                              non-null; the discharge service
     *                              supplies it)
     *   obligation_written_off   — component PERMITTED (may be null or
     *                              non-null; the discharge service
     *                              supplies it)
     *   payment_received         — component MUST be null
     *   adjustment               — component MUST be null
     *   reversal                 — component MUST be null
     *
     * The relaxation is part of the same CR-2C-WALLET-OBLIGATION-LIFECYCLE-001
     * that introduced the two discharge entry types.
     *
     * @param WalletEntryType          $entryType
     * @param ObligationComponent|null $component
     * @return void
     *
     * @throws ValidationException
     */
    private function assertComponentConsistency(
        WalletEntryType $entryType,
        ?ObligationComponent $component,
    ): void {
        // The two entry types that may carry a component: the one that
        // records an obligation, and the two that discharge one.
        $mayCarryComponent = in_array($entryType, [
            WalletEntryType::ObligationRecorded,
            WalletEntryType::ObligationCancelled,
            WalletEntryType::ObligationWrittenOff,
        ], true);

        if (!$mayCarryComponent && $component !== null) {
            throw ValidationException::withMessages([
                'component' => [
                    sprintf(
                        'The component column is only meaningful for '
                        . 'obligation-related entries (recorded, cancelled, '
                        . 'written off); entry type "%s" must not carry a '
                        . 'component.',
                        $entryType->value,
                    ),
                ],
            ]);
        }
    }

    /**
     * Assert that an entry is persisted.
     *
     * @param WalletEntry $entry
     * @return void
     *
     * @throws ValidationException
     */
    private function assertEntryIsPersisted(WalletEntry $entry): void
    {
        if (!$entry->exists) {
            throw ValidationException::withMessages([
                'entry_id' => ['The entry to reverse must be a persisted record.'],
            ]);
        }
    }

    /**
     * Assert that the entry being reversed is not itself a reversal.
     *
     * A reversal of a reversal is not a supported operation in Phase 2B
     * (Architecture §4.3). The correct remedy for a mistaken reversal is
     * a new adjustment or a new obligation, not a second reversal.
     *
     * @param WalletEntry $entry
     * @return void
     *
     * @throws ValidationException
     */
    private function assertEntryIsNotReversal(WalletEntry $entry): void
    {
        if ($entry->entry_type === WalletEntryType::Reversal) {
            throw ValidationException::withMessages([
                'entry_id' => [
                    'A reversal entry cannot itself be reversed.',
                ],
            ]);
        }
    }

    /**
     * Assert that the entry has not already been reversed.
     *
     * Each entry may be reversed at most once (Architecture §4.3). The
     * check queries the ledger for any entry whose `reversed_entry_id`
     * equals this entry's id. The result is a boolean; no model is
     * hydrated.
     *
     * The check does NOT lock the original entry. Two concurrent reversal
     * calls for the same entry could both pass this check and both insert
     * a reversal row. The table does not express a UNIQUE on
     * `reversed_entry_id` (Step 6 v1.1.0 §3.7 — the same posture as 2A's
     * `share_holding_entries`); the race window is narrow and the
     * consequence (two reversal rows) is detectable by inspection. If the
     * race ever becomes material, a future CR adds a filtered unique
     * index or a per-entry lock.
     *
     * @param WalletEntry $entry
     * @return void
     *
     * @throws ValidationException
     */
    private function assertEntryNotAlreadyReversed(WalletEntry $entry): void
    {
        $alreadyReversed = WalletEntry::query()
            ->where('reversed_entry_id', $entry->id)
            ->exists();

        if ($alreadyReversed) {
            throw ValidationException::withMessages([
                'entry_id' => [
                    'The entry has already been reversed.',
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
     * is false, no domain event fires; the ledger write itself is
     * unaffected.
     *
     * `DB::afterCommit()` registers a callback that runs after the
     * OUTERMOST transaction commits. If no transaction is active, the
     * callback runs immediately — a defensive case for tests or scripts
     * that call the service outside a transaction. In production, every
     * write path in this service wraps in a transaction, so the immediate
     * branch is not exercised.
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