<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Enums;

/**
 * Classifies the originating business workflow for a journal entry.
 *
 * This is provenance metadata, not an authorization mechanism. Source domains
 * remain the owners of their business records; Finance owns the resulting
 * general-ledger journal and its accounting controls.
 */
enum JournalSourceType: string
{
    case MANUAL = 'manual';

    case SHAREHOLDER = 'shareholder';

    case WALLET = 'wallet';

    case LOAN = 'loan';

    case PROCUREMENT = 'procurement';

    case ACCOUNTS_PAYABLE = 'accounts_payable';

    case ACCOUNTS_RECEIVABLE = 'accounts_receivable';

    case BANK = 'bank';

    case PAYROLL = 'payroll';

    case TAX = 'tax';

    case SYSTEM = 'system';

    /**
     * Whether this source normally represents a separate business domain.
     *
     * MANUAL and SYSTEM entries are generated within the accounting
     * boundary; other cases identify originating business domains or
     * operational workflows.
     */
    public function isExternalBusinessSource(): bool
    {
        return match ($this) {
            self::SHAREHOLDER,
            self::WALLET,
            self::LOAN,
            self::PROCUREMENT,
            self::ACCOUNTS_PAYABLE,
            self::ACCOUNTS_RECEIVABLE,
            self::BANK,
            self::PAYROLL,
            self::TAX => true,

            self::MANUAL,
            self::SYSTEM => false,
        };
    }

    /**
     * Whether an entry of this source type should require an explicit
     * originating business reference before it can be posted.
     *
     * The service layer must enforce the reference and verify that it belongs
     * to the same tenant and legal-entity scope.
     */
    public function requiresSourceReference(): bool
    {
        return $this->isExternalBusinessSource();
    }
}
