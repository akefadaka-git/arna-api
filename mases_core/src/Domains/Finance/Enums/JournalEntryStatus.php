<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Enums;

/**
 * Canonical lifecycle states for general-ledger journal entries.
 *
 * Lifecycle rules:
 * - DRAFT: Editable only through explicitly authorized draft operations.
 * - POSTED: Posted to the ledger; financial content is immutable.
 * - REVERSED: Previously posted entry offset by a separate reversal entry.
 * - CANCELLED: Draft cancelled before posting; retained for audit history.
 *
 * A reversed entry must never be deleted or rewritten. Its reversal is
 * represented by a separate journal entry linked to the original entry.
 */
enum JournalEntryStatus: string
{
    case DRAFT = 'draft';

    case POSTED = 'posted';

    case REVERSED = 'reversed';

    case CANCELLED = 'cancelled';

    /**
     * Determine whether the entry has reached a terminal state.
     *
     * Terminal status does not imply that the record can be deleted.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::POSTED,
            self::REVERSED,
            self::CANCELLED => true,

            self::DRAFT => false,
        };
    }

    /**
     * Determine whether this entry is eligible for posting.
     */
    public function canBePosted(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Determine whether this entry is eligible for cancellation.
     *
     * Only drafts may be cancelled. Posted entries must be corrected
     * through the controlled reversal workflow.
     */
    public function canBeCancelled(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Determine whether the entry represents a posted ledger record.
     *
     * Reversed entries remain part of the accounting audit trail.
     */
    public function isLedgerRecord(): bool
    {
        return match ($this) {
            self::POSTED,
            self::REVERSED => true,

            self::DRAFT,
            self::CANCELLED => false,
        };
    }

    /**
     * Determine whether financial content must be immutable.
     */
    public function isImmutable(): bool
    {
        return $this !== self::DRAFT;
    }
}
