<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants;
use MaaleArna\MASES\Core\Domains\Documents\Database\Seeders\DocumentsRolePermissionGrants;

/**
 * Canonical role-to-permission grant catalogue.
 *
 * Revision 1.2.0 records the approved platform/tenant/application scope matrix:
 * - Platform roles receive global permissions only.
 * - Tenant roles receive global and shared/same-tenant tenant permissions.
 * - Application roles receive global and same-application permissions, with
 *   tenant binding shared/null or matching the role tenant.
 *
 * Current scope-sensitive grants:
 * - super_admin and platform_admin use scope:global; neither is a wildcard role.
 * - tenant_admin uses scope:tenant, with sensitive permissions excluded below.
 * - finance_manager is Finance-application-scoped and receives Finance app
 *   permissions plus dashboard.finance.executive.view.
 * - hr_manager is HR-application-scoped.
 * - cashier retains the tenant-scoped Finance settlement execute permission.
 * - Finance Manager receives the separate Finance-app-scoped settlement
 *   execute variant.
 *
 * Scope wildcards are convenience grant specs, not scope relaxations. Every
 * resolved grant is passed through Role::syncPermissions(), which enforces the
 * model-level compatibility matrix. Sensitive audience and settlement grants
 * are excluded from wildcards and assigned only by explicit role grants.
 *
 * Supported grant forms:
 * - '*' (legacy syntax; no current role uses it)
 * - 'scope:global', 'scope:tenant', 'scope:application'
 * - literal permission slug
 *
 * @see FinanceRolePermissionGrants
 * @see RolePermissionSeeder
 */

final class RolePermissionGrants
{
    /**
     * The role-to-permission grant map, keyed by role slug.
     *
     * @var array<string, array<int, string>>
     */
    /**
     * Permissions intentionally excluded from broad wildcard grants.
     * They may be granted only by an explicit literal slug in a role spec.
     *
     * @var array<int, string>
     */
    public const WILDCARD_EXCLUSIONS = [
        'people.workforce.view', 'dashboard.finance.executive.view',
        'finance.governance_settlement.execute', 'finance.governance_settlement.execute.app',
    ];

    public const MAP = [
        'super_admin'     => ['scope:global'],
        'platform_admin'  => [
            'scope:global',
        ],
        'tenant_admin'    => [
            'scope:tenant',
        ],
        'manager'         => [
            ...DocumentsRolePermissionGrants::MAP['manager'],
            'calendar.view', 'calendar.create', 'calendar.update',
            'user.profile.view', 'user.list',
            'file.list', 'file.view', 'file.download', 'announcement.view',
            // Finance application access is assigned only to an application-scoped role.
        ],
        'finance_manager' => [...FinanceRolePermissionGrants::MAP['finance_manager'], 'dashboard.finance.executive.view'],
        'hr_manager'      => [
            'app.hr.access',
            'user.profile.view',
        ],
        'shareholder'     => [
            'calendar.view', 'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'board'           => [
            ...DocumentsRolePermissionGrants::MAP['board'],
            'calendar.view',
            'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'president'       => [
            ...DocumentsRolePermissionGrants::MAP['president'],
            'calendar.view',
            'user.profile.view', 'user.list',
            'file.view', 'file.list', 'file.download', 'announcement.view', 'announcement.approve', 'announcement.publish',
            'role.list', 'permission.list', 'dashboard.executive.view',
        ],
        'secretary'       => [
            ...DocumentsRolePermissionGrants::MAP['secretary'],
            'calendar.view', 'calendar.create', 'calendar.update',
            'user.profile.view', 'announcement.view', 'announcement.manage',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
        'cashier'         => FinanceRolePermissionGrants::MAP['cashier'],
        'ceo'             => [
            ...DocumentsRolePermissionGrants::MAP['ceo'],
            'calendar.view', 'calendar.create', 'calendar.update', 'calendar.reserve',
            ...FinanceRolePermissionGrants::MAP['ceo'],
            'user.profile.view', 'user.list', 'user.create', 'user.update',
            'role.list', 'permission.list', 'dashboard.executive.view',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
    ];
}