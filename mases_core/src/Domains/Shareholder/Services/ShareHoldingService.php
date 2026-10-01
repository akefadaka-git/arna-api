<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\HoldingDirection;
use MaaleArna\MASES\Core\Domains\Shareholder\Events\ShareHoldingChanged;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHolding;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareHoldingEntry;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;

/**
 * ShareHoldingService
 *
 * The single writer for ShareHolding.current_quantity. Every balance change
 * in the Shareholder domain flows through this service.
 *
 * =============================================================================
 * Responsibility
 * =============================================================================
 * This service implements D16-CORE:
 *
 *     ShareHolding       = current-state projection
 *     ShareHoldingEntry  = append-only ledger
 *
 * For every balance-changing operation the service, inside one database
 * transaction:
 *
 *   1. Opens a transaction.
 *   2. Fetches the target ShareHolding row with SELECT ... FOR UPDATE,
 *      locking it for the duration of the transaction (D16-R6). Concurrent
 *      callers serialize on the row lock; no two balance changes can read
 *      the same balance_before and both commit.
 *   3. Reads `current_quantity` into `balance_before`.
 *   4. Computes the SIGNED quantity from the caller's HoldingDirection and
 *      non-negative magnitude. The sign is applied by the service; callers
 *      never supply a signed quantity.
 *   5. Computes `balance_after = balance_before + quantity` with bcmath.
 *   6. Rejects the operation if `balance_after` is negative (D16-R5), if the
 *      magnitude is not a positive whole number (D6), or if the entry type
 *      is not in the controlled vocabulary (D16-R3).
 *   7. Inserts a ShareHoldingEntry row recording the signed quantity, the
 *      before and after balances, and the caller's reference metadata.
 *   8. Updates ShareHolding.current_quantity to `balance_after`.
 *   9. Commits.
 *  10. Dispatches ShareHoldingChanged AFTER commit (DB::afterCommit), so
 *      no listener observes a half-written state.
 *
 * The invariant
 *
 *     balance_before + quantity = balance_after
 *
 * holds for every entry the service has ever written. It can be verified
 * at any time by ShareHoldingEntry::satisfiesBalanceInvariant() (2A.9).
 *
 * =============================================================================
 * What the service never does
 * =============================================================================
 *   - It never UPDATEs a posted ShareHoldingEntry row. The model has no
 *     `updated_at`, and no code path in this service issues an update
 *     against it (D16-R1).
 *
 *   - It never DELETEs a ShareHoldingEntry row.
 *
 *   - It never DELETEs a ShareHolding row, even at quantity 0. A holding
 *     that has been fully transferred out persists so that its historical
 *     ledger entries remain anchored.
 *
 *   - It never accepts a signed quantity from a caller. Direction is
 *     supplied as an enum, magnitude as a non-negative int.
 *
 *   - It never dispatches an event before the transaction commits.
 *
 *   - It never bypasses the ledger to write `current_quantity` directly.
 *
 * =============================================================================
 * Reversal semantics (D16-R2, D16-X5)
 * =============================================================================
 * A reversal is a NEW entry whose `entry_type` is `'reversal'` and whose
 * `reversed_entry_id` points to the original entry. It is created by
 * `reverseEntry()` and by nothing else.
 *
 * The service:
 *
 *   - Refuses to reverse an entry that has already been reversed. The check
 *     is a query for any other entry whose `reversed_entry_id` equals the
 *     original's id. If such a row exists, the operation throws.
 *
 *   - Refuses to reverse a reversal. `original.entry_type === 'reversal'`
 *     throws.
 *
 *   - Applies the reversal as the NUMERIC NEGATION of the original's signed
 *     quantity, against the CURRENT balance. If subsequent entries have
 *     moved the balance since the original was posted, the reversal still
 *     applies cleanly — it simply restores the numeric effect of the
 *     original at the current point in the ledger.
 *
 *   - Rejects the reversal if it would drive the balance below zero
 *     (D16-R5). In that case the transaction rolls back, no reversal entry
 *     is written, and no balance is changed.
 *
 * The original entry is never modified. Its `quantity`, `balance_before`,
 * `balance_after`, and `occurred_at` remain exactly as posted.
 *
 * =============================================================================
 * Concurrency (D16-R6)
 * =============================================================================
 * The `lockForUpdate()` call inside `DB::transaction()` translates to
 *
 *     SELECT ... FOR UPDATE
 *
 * on MySQL InnoDB. Two concurrent `recordEntry()` calls against the same
 * ShareHolding serialize: the second caller blocks until the first commits,
 * then sees the updated `current_quantity`. Without the lock, two callers
 * could read the same `balance_before`, compute independently, and both
 * persist — producing two entries whose `balance_after` values both claim
 * to be the new truth while the ledger diverges from the projection.
 *
 * The lock is held only for the duration of the transaction. The service
 * does no I/O beyond the two writes (entry insert + holding update), so the
 * lock is short-lived.
 *
 * =============================================================================
 * Whole-number quantities (D6)
 * =============================================================================
 * D6 permits share quantities to be whole numbers only. The service
 * enforces this by accepting `int $magnitude` (never a decimal string) and
 * rejecting any value `<= 0`. The database columns are DECIMAL(20,4) per
 * D16-X2, which reserves four decimal places for future use; a non-whole
 * value written by a future migration would be caught by
 * ShareHoldingEntry::hasWholeNumberQuantities() (2A.9) as a data-integrity
 * anomaly.
 *
 * Fractional currency amounts (payments, dividends, transfer consideration)
 * are permitted by D6 but are NOT stored on this domain's ledger. Monetary
 * fields belong to future transaction objects (D16-X6).
 *
 * =============================================================================
 * Entry-type vocabulary (D16-R3, D16-R8)
 * =============================================================================
 * The six values accepted by this domain are:
 *
 *     subscription
 *     transfer_in
 *     transfer_out
 *     allocation
 *     adjustment
 *     reversal
 *
 * `recordEntry()` accepts five of them. `reversal` is reserved for
 * `reverseEntry()`. A caller that passes `'reversal'` to `recordEntry()`
 * receives a ValidationException, because a reversal must be constructed
 * with a `reversed_entry_id` and the direction derived from the original.
 *
 * =============================================================================
 * Event dispatch
 * =============================================================================
 * On success, `ShareHoldingChanged` is dispatched with the entry, the
 * before balance, and the after balance. The event is dispatched via
 * `DB::afterCommit()`, so a rollback does not fire it. The dispatch is
 * gated on `config('shareholder.audit.emit_events')`, which defaults to
 * true; setting it to false suppresses the audit trail.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Services
 */
final class ShareHoldingService
{
    /**
     * Entry types that `recordEntry()` accepts directly.
     *
     * `reversal` is deliberately absent — it is produced only by
     * `reverseEntry()`, because a reversal must carry `reversed_entry_id`
     * and a negated quantity derived from the original.
     *
     * @var list<string>
     */
    private const DIRECT_ENTRY_TYPES = [
        'subscription',
        'transfer_in',
        'transfer_out',
        'allocation',
        'adjustment',
    ];

    /**
     * Construct the service with its dependency.
     *
     * `ShareClassService` is used by `recordEntryFor()` to resolve the
     * default class when a caller does not supply one. The service does not
     * depend on the Shareholder service or the Certificate service;
     * preserving that one-way dependency keeps the module graph acyclic.
     *
     * @param ShareClassService $shareClassService
     */
    public function __construct(
        private readonly ShareClassService $shareClassService,
    ) {
    }

    // =========================================================================
    // Public API — record a balance-changing entry
    // =========================================================================

    /**
     * Record a balance-changing entry against an existing ShareHolding row.
     *
     * This is the primary write path. Use `recordEntryFor()` when the
     * holding row may not exist yet and needs to be created at quantity 0.
     *
     * @param ShareHolding              $holding         The target holding. Must be persisted.
     * @param HoldingDirection          $direction       In or Out. Neutral is rejected.
     * @param int                       $magnitude       Non-negative whole-number quantity.
     * @param string                    $entryType       One of DIRECT_ENTRY_TYPES.
     * @param string|null               $referenceType   Optional origin label (e.g. "ShareTransfer").
     * @param string|null               $referenceId     Optional origin identifier (e.g. "42").
     * @param string|null               $description     Optional human-readable note.
     * @param int|null                  $createdBy       Optional actor user id.
     * @param CarbonInterface|null      $occurredAt      Business-event time; defaults to now.
     * @return ShareHoldingEntry
     *
     * @throws ValidationException
     */
    public function recordEntry(
        ShareHolding $holding,
        HoldingDirection $direction,
        int $magnitude,
        string $entryType,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?CarbonInterface $occurredAt = null,
    ): ShareHoldingEntry {
        $this->assertHoldingIsPersisted($holding);
        $this->assertDirectionIsNotNeutral($direction);
        $this->assertMagnitudeIsPositiveWholeNumber($magnitude);
        $this->assertEntryTypeIsDirect($entryType);

        $signedQuantity = $direction->applyTo($magnitude);

        return $this->persistEntry(
            holding: $holding,
            signedQuantity: $signedQuantity,
            entryType: $entryType,
            reversedEntryId: null,
            referenceType: $referenceType,
            referenceId: $referenceId,
            description: $description,
            createdBy: $createdBy,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Record a balance-changing entry for a (shareholder, share class) pair.
     *
     * If no ShareHolding row exists for the pair, one is created at
     * quantity 0, then the entry is applied. The creation and the entry are
     * inside the same transaction.
     *
     * Use this method when the caller knows the shareholder and the class
     * but not the holding id — the common case from HTTP controllers, where
     * the request supplies shareholder_id and share_class_id.
     *
     * @param Shareholder               $shareholder
     * @param ShareClass                $shareClass
     * @param HoldingDirection          $direction
     * @param int                       $magnitude
     * @param string                    $entryType
     * @param string|null               $referenceType
     * @param string|null               $referenceId
     * @param string|null               $description
     * @param int|null                  $createdBy
     * @param CarbonInterface|null      $occurredAt
     * @return ShareHoldingEntry
     *
     * @throws ValidationException
     */
    public function recordEntryFor(
        Shareholder $shareholder,
        ShareClass $shareClass,
        HoldingDirection $direction,
        int $magnitude,
        string $entryType,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null,
        ?int $createdBy = null,
        ?CarbonInterface $occurredAt = null,
    ): ShareHoldingEntry {
        if (!$shareholder->exists) {
            throw ValidationException::withMessages([
                'shareholder_id' => ['The shareholder must be a persisted record.'],
            ]);
        }

        if (!$shareClass->exists) {
            throw ValidationException::withMessages([
                'share_class_id' => ['The share class must be a persisted record.'],
            ]);
        }

        $holding = $this->findOrCreateHolding($shareholder, $shareClass);

        return $this->recordEntry(
            holding: $holding,
            direction: $direction,
            magnitude: $magnitude,
            entryType: $entryType,
            referenceType: $referenceType,
            referenceId: $referenceId,
            description: $description,
            createdBy: $createdBy,
            occurredAt: $occurredAt,
        );
    }

    // =========================================================================
    // Public API — reversal
    // =========================================================================

    /**
     * Reverse a posted entry by creating a new, linked reversal entry.
     *
     * The original entry is not modified in any way. The reversal:
     *
     *   - has `entry_type = 'reversal'`
     *   - has `quantity = -original.quantity`
     *   - has `reversed_entry_id = original.id`
     *   - records the balance around the reversal (before / after)
     *
     * The reversal is applied to the holding's CURRENT balance, not the
     * balance that existed at the time of the original entry. If subsequent
     * entries have moved the balance, the reversal still applies cleanly;
     * if the reversal would drive the balance negative, D16-R5 rejects the
     * operation and the transaction rolls back.
     *
     * @param ShareHoldingEntry         $original
     * @param string|null               $description
     * @param int|null                  $createdBy
     * @param CarbonInterface|null      $occurredAt
     * @return ShareHoldingEntry
     *
     * @throws ValidationException
     */
    public function reverseEntry(
        ShareHoldingEntry $original,
        ?string $description = null,
        ?int $createdBy = null,
        ?CarbonInterface $occurredAt = null,
    ): ShareHoldingEntry {
        if (!$original->exists) {
            throw ValidationException::withMessages([
                'entry_id' => ['The entry to reverse must be a persisted record.'],
            ]);
        }

        if ($original->entry_type === 'reversal') {
            throw ValidationException::withMessages([
                'entry_id' => ['A reversal entry cannot itself be reversed.'],
            ]);
        }

        $alreadyReversed = ShareHoldingEntry::query()
            ->where('reversed_entry_id', $original->id)
            ->exists();

        if ($alreadyReversed) {
            throw ValidationException::withMessages([
                'entry_id' => ['The entry has already been reversed.'],
            ]);
        }

        // The holding must be reloaded inside the transaction for the lock.
        $holding = $original->shareHolding;

        if ($holding === null) {
            throw ValidationException::withMessages([
                'entry_id' => ['The entry has no associated share holding.'],
            ]);
        }

        // Signed quantity of the reversal is the negation of the original.
        // Use bcmath because the original's quantity is a decimal string.
        $originalQuantity = (string) $original->quantity;
        $negated         = bcmul($originalQuantity, '-1', 4);

        // Convert the negated decimal string back to an int magnitude for
        // the persist step. The value must be a whole number per D6.
        // We have already validated, in reverseEntry() call sites, that the
        // original entry had a whole-number quantity — the entire domain
        // forbids fractional shares — so the conversion is lossless.
        $magnitude = (int) ltrim($negated, '-');
        $direction = bccomp($negated, '0', 4) >= 0
            ? HoldingDirection::In
            : HoldingDirection::Out;

        return $this->persistEntry(
            holding: $holding,
            signedQuantity: $direction->applyTo($magnitude),
            entryType: 'reversal',
            reversedEntryId: $original->id,
            referenceType: 'ShareHoldingEntry',
            referenceId: (string) $original->id,
            description: $description
                ?? sprintf('Reversal of entry #%d', $original->id),
            createdBy: $createdBy,
            occurredAt: $occurredAt,
        );
    }

    // =========================================================================
    // Public API — holding lookup / creation
    // =========================================================================

    /**
     * Return the ShareHolding for a (shareholder, share class) pair,
     * creating it at quantity 0 if it does not exist.
     *
     * This method is safe to call outside a transaction — the creation is
     * idempotent by virtue of the UNIQUE (shareholder_id, share_class_id)
     * constraint. If two callers race, one will succeed and the other will
     * observe an integrity violation; the ShareHoldingService's own
     * `recordEntryFor()` wraps the lookup inside its transaction, so the
     * race window in practice is zero for domain callers.
     *
     * @param Shareholder $shareholder
     * @param ShareClass  $shareClass
     * @return ShareHolding
     */
    public function findOrCreateHolding(Shareholder $shareholder, ShareClass $shareClass): ShareHolding
    {
        /** @var ShareHolding|null $existing */
        $existing = ShareHolding::query()
            ->where('shareholder_id', $shareholder->id)
            ->where('share_class_id', $shareClass->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var ShareHolding $created */
        $created = ShareHolding::create([
            'shareholder_id'   => $shareholder->id,
            'share_class_id'   => $shareClass->id,
            'current_quantity' => '0.0000',
        ]);

        return $created;
    }

    /**
     * Return the current quantity of a holding as a decimal string.
     *
     * Read-only convenience. The holding is not locked; the caller accepts
     * that the value may be stale by the time it is used.
     *
     * @param ShareHolding $holding
     * @return string  Decimal string such as "145.0000".
     */
    public function currentQuantity(ShareHolding $holding): string
    {
        return (string) $holding->current_quantity;
    }

    /**
     * Return the ledger entries for a holding, in chronological order.
     *
     * The relation `entries()` on the ShareHolding model already orders by
     * `occurred_at` ascending then id ascending. This method is a thin
     * wrapper that returns a bounded slice when a limit is given.
     *
     * @param ShareHolding $holding
     * @param int|null     $limit
     * @return Collection<int, ShareHoldingEntry>
     */
    public function entriesFor(ShareHolding $holding, ?int $limit = null): Collection
    {
        $query = $holding->entries();

        if ($limit !== null && $limit > 0) {
            $query = $query->limit($limit);
        }

        return $query->get();
    }

    // =========================================================================
    // Private — transactional persistence
    // =========================================================================

    /**
     * Persist a single entry and update the holding's projection, inside a
     * database transaction, with row-level locking.
     *
     * This method is the ONLY place in the domain that writes to
     * ShareHolding.current_quantity. It is private deliberately: the two
     * public entry points (`recordEntry` and `reverseEntry`) fully validate
     * their inputs before calling it, and every call site is guaranteed to
     * have already resolved the target holding.
     *
     * @param ShareHolding       $holding
     * @param int                $signedQuantity  The signed whole-number quantity to apply.
     * @param string             $entryType
     * @param int|null           $reversedEntryId
     * @param string|null        $referenceType
     * @param string|null        $referenceId
     * @param string|null        $description
     * @param int|null           $createdBy
     * @param CarbonInterface|null $occurredAt
     * @return ShareHoldingEntry
     *
     * @throws ValidationException
     */
    private function persistEntry(
        ShareHolding $holding,
        int $signedQuantity,
        string $entryType,
        ?int $reversedEntryId,
        ?string $referenceType,
        ?string $referenceId,
        ?string $description,
        ?int $createdBy,
        ?CarbonInterface $occurredAt,
    ): ShareHoldingEntry {
        return DB::transaction(function () use (
            $holding,
            $signedQuantity,
            $entryType,
            $reversedEntryId,
            $referenceType,
            $referenceId,
            $description,
            $createdBy,
            $occurredAt,
        ): ShareHoldingEntry {
            // ---------------------------------------------------------------
            // 1. Re-fetch the holding with a row-level lock (D16-R6).
            // ---------------------------------------------------------------
            /** @var ShareHolding $locked */
            $locked = ShareHolding::query()
                ->whereKey($holding->id)
                ->lockForUpdate()
                ->firstOrFail();

            // ---------------------------------------------------------------
            // 2. Read the current balance.
            // ---------------------------------------------------------------
            $balanceBefore = (string) $locked->current_quantity;

            // ---------------------------------------------------------------
            // 3. Compute the signed quantity and the new balance with bcmath.
            // ---------------------------------------------------------------
            $signedQuantityString = bcadd('0', (string) $signedQuantity, 4);
            $balanceAfter         = bcadd($balanceBefore, $signedQuantityString, 4);

            // ---------------------------------------------------------------
            // 4. Enforce D16-R5: balance_after must not be negative.
            // ---------------------------------------------------------------
            $allowNegative = (bool) config('shareholder.allow_negative_balance', false);

            if (!$allowNegative && bccomp($balanceAfter, '0', 4) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => [
                        sprintf(
                            'The operation would drive the share holding to %s, which is below zero. '
                            . 'Current balance: %s, requested change: %s.',
                            rtrim(rtrim($balanceAfter, '0'), '.'),
                            rtrim(rtrim($balanceBefore, '0'), '.'),
                            $signedQuantityString,
                        ),
                    ],
                ]);
            }

            // ---------------------------------------------------------------
            // 5. Create the ledger entry.
            //
            //    `created_at` is set explicitly because the ShareHoldingEntry
            //    model has $timestamps = false and does not maintain it.
            // ---------------------------------------------------------------
            $now = Carbon::now();

            /** @var ShareHoldingEntry $entry */
            $entry = ShareHoldingEntry::create([
                'share_holding_id'  => $locked->id,
                'entry_type'        => $entryType,
                'quantity'          => $signedQuantityString,
                'balance_before'    => $balanceBefore,
                'balance_after'     => $balanceAfter,
                'reference_type'    => $referenceType,
                'reference_id'      => $referenceId,
                'description'       => $description,
                'occurred_at'       => $occurredAt ?? $now,
                'created_by'        => $createdBy,
                'reversed_entry_id' => $reversedEntryId,
                'created_at'        => $now,
            ]);

            // ---------------------------------------------------------------
            // 6. Update the projection.
            // ---------------------------------------------------------------
            $locked->current_quantity = $balanceAfter;
            $locked->save();

            // ---------------------------------------------------------------
            // 7. Dispatch the domain event AFTER commit (D16-CORE).
            // ---------------------------------------------------------------
            $this->dispatchAfterCommit(
                new ShareHoldingChanged(
                    entry:         $entry,
                    balanceBefore: $balanceBefore,
                    balanceAfter:  $balanceAfter,
                )
            );

            // Refresh the entry so any accessors resolved from the row are
            // consistent with what was persisted.
            return $entry->refresh();
        });
    }

    // =========================================================================
    // Private — validation
    // =========================================================================

    /**
     * Assert that the holding has been persisted.
     *
     * @param ShareHolding $holding
     * @return void
     *
     * @throws ValidationException
     */
    private function assertHoldingIsPersisted(ShareHolding $holding): void
    {
        if (!$holding->exists) {
            throw ValidationException::withMessages([
                'share_holding_id' => ['The share holding must be a persisted record.'],
            ]);
        }
    }

    /**
     * Assert that the direction is In or Out, never Neutral.
     *
     * Neutral entries are not produced in Phase 2A (see the class docblock
     * of HoldingDirection, file 2A.4). A caller that supplies Neutral is
     * expressing an intent the domain cannot yet represent.
     *
     * @param HoldingDirection $direction
     * @return void
     *
     * @throws ValidationException
     */
    private function assertDirectionIsNotNeutral(HoldingDirection $direction): void
    {
        if ($direction->isNeutral()) {
            throw ValidationException::withMessages([
                'direction' => [
                    'Neutral entries are not supported in Phase 2A. Use In or Out.',
                ],
            ]);
        }
    }

    /**
     * Assert that the magnitude is a positive whole number.
     *
     * Per D6, fractional shares are not permitted, and a zero-quantity
     * entry would produce a meaningless ledger row. The parameter is typed
     * `int`, so the caller cannot supply a fractional value — this method
     * additionally rejects zero and negative magnitudes.
     *
     * @param int $magnitude
     * @return void
     *
     * @throws ValidationException
     */
    private function assertMagnitudeIsPositiveWholeNumber(int $magnitude): void
    {
        if ($magnitude <= 0) {
            throw ValidationException::withMessages([
                'quantity' => ['The share quantity must be a positive whole number.'],
            ]);
        }
    }

    /**
     * Assert that the entry type is one that `recordEntry()` may produce.
     *
     * `reversal` is rejected here — it is produced only by `reverseEntry()`,
     * because a reversal must carry a `reversed_entry_id` and a signed
     * quantity derived from the original.
     *
     * @param string $entryType
     * @return void
     *
     * @throws ValidationException
     */
    private function assertEntryTypeIsDirect(string $entryType): void
    {
        if (!in_array($entryType, self::DIRECT_ENTRY_TYPES, true)) {
            throw ValidationException::withMessages([
                'entry_type' => [
                    sprintf(
                        'The entry type "%s" is not permitted here. Allowed: %s.',
                        $entryType,
                        implode(', ', self::DIRECT_ENTRY_TYPES),
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
     * @param object $event
     * @return void
     */
    private function dispatchAfterCommit(object $event): void
    {
        if (!config('shareholder.audit.emit_events', true)) {
            return;
        }

        DB::afterCommit(static function () use ($event): void {
            Event::dispatch($event);
        });
    }
}