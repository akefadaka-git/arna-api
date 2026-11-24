<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Database\Seeders;

/**
 * Finance-owned permission grants contributed to the platform role registry.
 *
 * finance_manager is an application-scoped Finance role and receives the
 * Finance application entry, Finance-domain permissions, and the
 * application-scoped settlement execution variant.
 *
 * cashier remains tenant-scoped and receives the tenant-scoped settlement
 * execution permission. The two execution variants are intentionally distinct
 * and are excluded from broad role-scope wildcards.
 *
 * Other shared roles receive no Finance-specific grants from this catalogue.
 * RolePermissionSeeder composes these explicit grants with the platform role
 * catalogue and validates them through Role::syncPermissions().
 */

final class FinanceRolePermissionGrants
{
    /** @var array<string, list<string>> */
    public const MAP = [
        'tenant_admin' => [],
        'manager' => [],
        'finance_manager' => [
            'app.finance.access',
            'user.profile.view',
            'finance.entity.view',
            'finance.account.view', 'finance.account.manage',
            'finance.period.view', 'finance.period.manage',
            'finance.journal.view', 'finance.journal.create',
            'finance.journal.cancel',
            'finance.journal.post', 'finance.journal.reverse',
            'finance.report.view',
            'finance.ai.view', 'finance.ai.propose', 'finance.ai.approve',
            'finance.subledger.view', 'finance.subledger.ingest', 'finance.integration.view',
            'finance.governance_settlement.configure',
            'finance.governance_settlement.approve',
            'finance.governance_settlement.execute.app',
        ],
        'board' => [],
        'president' => [],
        'cashier' => [
            'user.profile.view',
            'file.view', 'file.list', 'file.download', 'file.upload',
            'finance.governance_settlement.execute',
        ],
        'ceo' => [],
    ];
}
