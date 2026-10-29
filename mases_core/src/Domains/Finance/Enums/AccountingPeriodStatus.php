<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Enums;

/**
 * Canonical lifecycle states for a legal entity's accounting period.
 *
 * Period closure is a controlled accounting operation. A period must not
 * be closed while it contains unposted draft journals, and closed periods
 * must reject new or changed financial postings unless a separately approved
 * reopening workflow is implemented.
 */
enum AccountingPeriodStatus: string
{
    case OPEN = 'open';

    case CLOSED = 'closed';

    /**
     * Whether journals may be created or posted into this period.
     */
    public function acceptsJournals(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Whether the period is already closed for ordinary accounting activity.
     */
    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }

    /**
     * Whether this period can transition to closed under ordinary workflow.
     *
     * This is a state eligibility check only. The service performing closure
     * must still lock the period and verify that no blocking drafts remain.
     */
    public function canBeClosed(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Whether this period can transition to open through ordinary workflow.
     *
     * Closed-period reopening should remain disabled until a separately
     * authorized, audited reopening workflow is implemented.
     */
    public function canBeReopened(): bool
    {
        return false;
    }
}
