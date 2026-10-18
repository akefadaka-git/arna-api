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

final class ShareHoldingService
{
    /**
     * @var list<string>
     */
    private const DIRECT_ENTRY_TYPES = [
        'subscription',
        'transfer_in',
        'transfer_out',
        'allocation',
        'adjustment',
    ];

    public function __construct(
        private readonly ShareClassService $shareClassService,
    ) {
    }

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
     * Atomically create or locate the holding and post its first entry.
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
                'shareholder_id' => [
                    'The shareholder must be a persisted record.',
                ],
            ]);
        }

        if (!$shareClass->exists) {
            throw ValidationException::withMessages([
                'share_class_id' => [
                    'The share class must be a persisted record.',
                ],
            ]);
        }

        $this->assertDirectionIsNotNeutral($direction);
        $this->assertMagnitudeIsPositiveWholeNumber($magnitude);
        $this->assertEntryTypeIsDirect($entryType);

        return DB::transaction(function () use (
            $shareholder,
            $shareClass,
            $direction,
            $magnitude,
            $entryType,
            $referenceType,
            $referenceId,
            $description,
            $createdBy,
            $occurredAt,
        ): ShareHoldingEntry {
            $holding = $this->findOrCreateHolding(
                $shareholder,
                $shareClass,
            );

            return $this->recordEntry(
                $holding,
                $direction,
                $magnitude,
                $entryType,
                $referenceType,
                $referenceId,
                $description,
                $createdBy,
                $occurredAt,
            );
        });
    }

    /**
     * Reverse an entry without modifying the original ledger row.
     *
     * The original entry is locked while checking for an existing reversal.
     *
     * @throws ValidationException
     */
    public function reverseEntry(
        ShareHoldingEntry $original,
        ?string $description = null,
        ?int $createdBy = null,
        ?CarbonInterface $occurredAt = null,
    ): ShareHoldingEntry {
        if (!$original->exists || $original->getKey() === null) {
            throw ValidationException::withMessages([
                'entry_id' => [
                    'The entry to reverse must be a persisted record.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $original,
            $description,
            $createdBy,
            $occurredAt,
        ): ShareHoldingEntry {
            /** @var ShareHoldingEntry $lockedOriginal */
            $lockedOriginal = ShareHoldingEntry::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOriginal->entry_type === 'reversal') {
                throw ValidationException::withMessages([
                    'entry_id' => [
                        'A reversal entry cannot itself be reversed.',
                    ],
                ]);
            }

            $alreadyReversed = ShareHoldingEntry::query()
                ->where('reversed_entry_id', $lockedOriginal->getKey())
                ->exists();

            if ($alreadyReversed) {
                throw ValidationException::withMessages([
                    'entry_id' => [
                        'The entry has already been reversed.',
                    ],
                ]);
            }

            $holding = $lockedOriginal->shareHolding;

            if ($holding === null) {
                throw ValidationException::withMessages([
                    'entry_id' => [
                        'The entry has no associated share holding.',
                    ],
                ]);
            }

            $originalQuantity = (string) $lockedOriginal->quantity;
            $negated = bcmul($originalQuantity, '-1', 4);

            $magnitude = (int) ltrim($negated, '-');
            $direction = bccomp($negated, '0', 4) >= 0
                ? HoldingDirection::In
                : HoldingDirection::Out;

            return $this->persistEntry(
                holding: $holding,
                signedQuantity: $direction->applyTo($magnitude),
                entryType: 'reversal',
                reversedEntryId: (int) $lockedOriginal->getKey(),
                referenceType: 'ShareHoldingEntry',
                referenceId: (string) $lockedOriginal->getKey(),
                description: $description
                    ?? sprintf(
                        'Reversal of entry #%d',
                        $lockedOriginal->getKey(),
                    ),
                createdBy: $createdBy,
                occurredAt: $occurredAt,
            );
        });
    }

    /**
     * Find or create a holding while serializing creation attempts for the
     * same shareholder on databases that support row-level locking.
     */
    public function findOrCreateHolding(
        Shareholder $shareholder,
        ShareClass $shareClass,
    ): ShareHolding {
        if (!$shareholder->exists || $shareholder->getKey() === null) {
            throw ValidationException::withMessages([
                'shareholder_id' => [
                    'The shareholder must be a persisted record.',
                ],
            ]);
        }

        if (!$shareClass->exists || $shareClass->getKey() === null) {
            throw ValidationException::withMessages([
                'share_class_id' => [
                    'The share class must be a persisted record.',
                ],
            ]);
        }

        return DB::transaction(function () use (
            $shareholder,
            $shareClass,
        ): ShareHolding {
            // Serialize first-holding creation for this shareholder.
            $lockedShareholder = Shareholder::query()
                ->whereKey($shareholder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ShareHolding|null $existing */
            $existing = ShareHolding::query()
                ->where('shareholder_id', $lockedShareholder->getKey())
                ->where('share_class_id', $shareClass->getKey())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            /** @var ShareHolding $created */
            $created = ShareHolding::query()->create([
                'shareholder_id' => $lockedShareholder->getKey(),
                'share_class_id' => $shareClass->getKey(),
                'current_quantity' => '0.0000',
            ]);

            return $created;
        });
    }

    public function currentQuantity(ShareHolding $holding): string
    {
        return (string) $holding->current_quantity;
    }

    /**
     * @return Collection<int, ShareHoldingEntry>
     */
    public function entriesFor(
        ShareHolding $holding,
        ?int $limit = null,
    ): Collection {
        $query = $holding->entries();

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

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
            /** @var ShareHolding $locked */
            $locked = ShareHolding::query()
                ->whereKey($holding->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = (string) $locked->current_quantity;

            $signedQuantityString = bcadd(
                '0',
                (string) $signedQuantity,
                4,
            );

            $balanceAfter = bcadd(
                $balanceBefore,
                $signedQuantityString,
                4,
            );

            $allowNegative = (bool) config(
                'shareholder.allow_negative_balance',
                false,
            );

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

            $now = Carbon::now();

            /** @var ShareHoldingEntry $entry */
            $entry = ShareHoldingEntry::query()->create([
                'share_holding_id' => $locked->getKey(),
                'entry_type' => $entryType,
                'quantity' => $signedQuantityString,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'occurred_at' => $occurredAt ?? $now,
                'created_by' => $createdBy,
                'reversed_entry_id' => $reversedEntryId,
                'created_at' => $now,
            ]);

            $locked->current_quantity = $balanceAfter;
            $locked->save();

            $this->dispatchAfterCommit(
                new ShareHoldingChanged(
                    entry: $entry,
                    balanceBefore: $balanceBefore,
                    balanceAfter: $balanceAfter,
                ),
            );

            return $entry->refresh();
        });
    }

    private function assertHoldingIsPersisted(
        ShareHolding $holding,
    ): void {
        if (!$holding->exists || $holding->getKey() === null) {
            throw ValidationException::withMessages([
                'share_holding_id' => [
                    'The share holding must be a persisted record.',
                ],
            ]);
        }
    }

    private function assertDirectionIsNotNeutral(
        HoldingDirection $direction,
    ): void {
        if ($direction->isNeutral()) {
            throw ValidationException::withMessages([
                'direction' => [
                    'Neutral entries are not supported in Phase 2A. Use In or Out.',
                ],
            ]);
        }
    }

    private function assertMagnitudeIsPositiveWholeNumber(
        int $magnitude,
    ): void {
        if ($magnitude <= 0) {
            throw ValidationException::withMessages([
                'quantity' => [
                    'The share quantity must be a positive whole number.',
                ],
            ]);
        }
    }

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
