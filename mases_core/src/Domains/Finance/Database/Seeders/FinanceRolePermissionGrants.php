<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Database\Seeders;

/**
 * Finance-owned permission grants contributed to platform role definitions.
 *
 * Shared platform roles receive only Finance application access here.
 * Finance-specific roles receive their complete Finance and shared-service
 * grants here. The platform role-grant registry composes these rows.
 */
final class FinanceRolePermissionGrants
{
    /** @var array<string, list<string>> */
    public const MAP = [
        'tenant_admin' => ['app.finance.access'],
        'manager' => ['app.finance.access'],
        'finance_manager' => [
            'app.finance.access',
            'file.upload', 'file.download', 'file.view', 'file.list',
            'user.profile.view',
            'finance.entity.view',
            'finance.account.view', 'finance.account.manage',
            'finance.period.view', 'finance.period.manage',
            'finance.journal.view', 'finance.journal.create',
            'finance.journal.cancel',
            'finance.journal.post', 'finance.journal.reverse',
            'finance.report.view',
        ],
        'board' => ['app.finance.access'],
        'president' => ['app.finance.access'],
        'cashier' => [
            'app.finance.access', 'user.profile.view',
            'file.view', 'file.list', 'file.download', 'file.upload',
        ],
        'ceo' => ['app.finance.access'],
    ];
}
