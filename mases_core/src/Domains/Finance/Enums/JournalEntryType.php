<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Enums;

/**
 * Classifies the accounting purpose of a journal entry.
 *
 * This enum describes intent only. Posting, reversal linkage, period rules,
 * authorization, and source validation must be enforced by the domain service.
 */
enum JournalEntryType: string
{
    case STANDARD = 'standard';
    case ADJUSTING = 'adjusting';
    case CLOSING = 'closing';
    case REVERSAL = 'reversal';

    /**
     * Whether this type must reference the journal entry it reverses.
     *
     * The posting service must validate that the referenced entry exists,
     * belongs to the same tenant and legal entity, and is eligible for reversal.
     */
    public function requiresReversalReference(): bool
    {
        return $this === self::REVERSAL;
    }

    /**
     * Whether this type represents period-end closing activity.
     *
     * The accounting service must still validate the period state and
     * enforce the organization's approved closing policy.
     */
    public function isPeriodClosingEntry(): bool
    {
        return $this === self::CLOSING;
    }

    /**
     * Whether this type can be created as an ordinary manual journal.
     *
     * Reversal entries require a validated reversal workflow. Closing entries
     * should be created only through the period-close workflow.
     */
    public function isOrdinaryManualEntryAllowed(): bool
    {
        return $this === self::STANDARD || $this === self::ADJUSTING;
    }
}
