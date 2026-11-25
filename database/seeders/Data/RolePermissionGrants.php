<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceRolePermissionGrants;
use MaaleArna\MASES\Core\Domains\Documents\Database\Seeders\DocumentsRolePermissionGrants;

/**
 * RolePermissionGrants
 *
 * The mapping of which permissions each of the twelve platform roles
 * holds. This is the join-table data the seeder writes into the
 * role-to-permission pivot; the pivot's name is discovered from the
 * Role model at runtime (typically `role_has_permissions` or
 * `permission_role`), not hardcoded here.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @package Database\Seeders\Data
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
        'people.workforce.view',
    ];

    public const MAP = [
        'super_admin'     => ['*'],
        'platform_admin'  => [
            'scope:global', 'scope:tenant',
            'app.admin.access', 'app.app.access', 'app.launcher.access',
        ],
        'tenant_admin'    => [
            'scope:tenant',
            'app.portal.access', ...FinanceRolePermissionGrants::MAP['tenant_admin'], 'app.hr.access',
            'app.admin.access', 'app.arna.access', 'app.app.access', 'app.launcher.access',
        ],
        'manager'         => [
            ...DocumentsRolePermissionGrants::MAP['manager'],
            'calendar.view', 'calendar.create', 'calendar.update',
            'user.profile.view', 'user.list',
            'file.list', 'file.view', 'file.download', 'announcement.view',
            ...FinanceRolePermissionGrants::MAP['manager'], 'app.hr.access', 'app.portal.access',
        ],
        'finance_manager' => [...FinanceRolePermissionGrants::MAP['finance_manager'], 'dashboard.executive.view'],
        'hr_manager'      => [
            'app.hr.access',
            'file.upload', 'file.download', 'file.view', 'file.list',
            'user.profile.view',
        ],
        'shareholder'     => [
            'calendar.view', 'app.portal.access', 'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'board'           => [
            ...DocumentsRolePermissionGrants::MAP['board'],
            'calendar.view', 'app.portal.access', ...FinanceRolePermissionGrants::MAP['board'],
            'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'president'       => [
            ...DocumentsRolePermissionGrants::MAP['president'],
            'calendar.view', 'app.portal.access', ...FinanceRolePermissionGrants::MAP['president'], 'app.admin.access',
            'user.profile.view', 'user.list',
            'file.view', 'file.list', 'file.download', 'announcement.view', 'announcement.approve', 'announcement.publish',
            'role.list', 'permission.list', 'dashboard.executive.view',
        ],
        'secretary'       => [
            ...DocumentsRolePermissionGrants::MAP['secretary'],
            'calendar.view', 'calendar.create', 'calendar.update',
            'app.portal.access', 'user.profile.view', 'announcement.view', 'announcement.manage',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
        'cashier'         => FinanceRolePermissionGrants::MAP['cashier'],
        'ceo'             => [
            ...DocumentsRolePermissionGrants::MAP['ceo'],
            'calendar.view', 'calendar.create', 'calendar.update', 'calendar.reserve',
            'app.portal.access', ...FinanceRolePermissionGrants::MAP['ceo'], 'app.hr.access', 'app.admin.access',
            'user.profile.view', 'user.list', 'user.create', 'user.update',
            'role.list', 'permission.list', 'dashboard.executive.view',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
    ];
}