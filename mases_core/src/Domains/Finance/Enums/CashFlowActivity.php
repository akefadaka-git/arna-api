<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Enums;

/**
 * Classification for actual movements in cash and cash-equivalent accounts.
 * Non-cash journal entries are excluded from this activity classification.
 */
enum CashFlowActivity: string
{
    case OPERATING = 'operating';
    case INVESTING = 'investing';
    case FINANCING = 'financing';
}
