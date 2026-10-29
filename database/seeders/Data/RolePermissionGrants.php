<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

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
 * Revision history
 * =============================================================================
 * 1.2.0  — People-domain least-privilege read permissions:
 *          · Added people.view to the manager role for directory/profile reads.
 *          · Added people.view and people.sensitive.view to the tenant-scoped
 *            permission catalogue; tenant_admin/platform_admin receive the
 *            tenant-scoped capabilities through their existing scope grants.
 *          · No sensitive read permission is granted to manager by default.
 *
 * 1.0.0  — initial delivery (Unit S-6). Extracted from MasesCoreSeeder
 *          during the seeder refactoring. Twelve roles, three grant-
 *          spec forms ('*', 'scope:{scope}', literal slugs).
 *
 * 1.1.0  — platform_admin application access reconciliation (Unit C-1):
 *          · The `platform_admin` grant gains three explicit
 *            application-access slugs:
 *
 *              app.admin.access
 *              app.app.access
 *              app.launcher.access
 *
 *          · The three form the "platform-operation" subset of the
 *            register — the applications that serve the platform's
 *            own operation, as distinct from the four end-user
 *            applications (portal, finance, hr, arna). Prior to this
 *            revision, platform_admin held no application access at
 *            all, and a user with only that role saw an empty
 *            launcher.
 *
 *          · The change preserves the role hierarchy: super_admin
 *            remains the only role with universal access (its ['*']
 *            wildcard resolves to all 53 permissions), and the four
 *            end-user applications remain exclusive to the roles
 *            whose purpose they serve (tenant_admin for the tenant-
 *            scoped grants, and the end-user roles for their own).
 *
 *          · No other role's grant changed. The other eleven roles'
 *            specs are byte-for-byte identical to 1.0.0.
 *
 *          · MINOR version bump. The change is additive to one role's
 *            grant spec; the file's shape, the three grant-spec
 *            forms, and every other role's definition are unchanged.
 *
 *          · RE-ISSUED after the launcher-track validation's Layer 3.5
 *            reported platform_admin with zero app_access slugs. The
 *            file's content is byte-for-byte identical to the original
 *            1.1.0 delivery. The re-issue exists so that the operator
 *            can be certain of the file state before re-seeding; no
 *            code changed.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the grant map — the answer to "what can
 * each role do?" — lives in a file whose contents can be reviewed and
 * corrected independently of the seeding algorithm.
 *
 * =============================================================================
 * The grant-spec vocabulary
 * =============================================================================
 * A grant spec is a STRING. Three forms are recognised by the seeder:
 *
 *   1. '*' — the wildcard grant. Matches every permission in the
 *            catalogue.
 *
 *      Used by: super_admin.
 *
 *   2. 'scope:{scope}' — the scope grant. Matches every permission
 *                        in the catalogue whose `scope` field equals
 *                        the named scope. The scope names are the
 *                        three values defined by PermissionCatalogue:
 *
 *                          scope:global
 *                          scope:tenant
 *                          scope:application
 *
 *      Used by: platform_admin (scope:global, scope:tenant),
 *               tenant_admin (scope:tenant).
 *
 *   3. A literal permission slug — the exact grant. Matches the one
 *      permission whose slug equals the string. Every slug used
 *      below is defined in PermissionCatalogue; a slug that does not
 *      resolve to a permission is silently skipped by the seeder.
 *
 *      Used by: every role except super_admin.
 *
 * =============================================================================
 * The form is a map, keyed by role slug
 * =============================================================================
 * This constant is a MAP keyed by role slug, matching the shape of
 * RoleCatalogue (Unit S-5). Each value is an integer-indexed list of
 * grant specs, which may mix all three forms above.
 *
 * The outer keys MUST match the role slugs defined in RoleCatalogue.
 *
 * =============================================================================
 * Precedence and deduplication
 * =============================================================================
 * When a role's grant list mixes forms, the resulting permission list
 * is the UNION of everything matched. Duplicates are removed by the
 * seeder's resolvePermissionIds method before the pivot sync.
 *
 * =============================================================================
 * The twelve roles and their grant strategy
 * =============================================================================
 *   super_admin     — '*'              : every permission
 *
 *   platform_admin  — scope:global + scope:tenant
 *                     + app.admin.access + app.app.access
 *                     + app.launcher.access
 *                                      : the two non-application scopes
 *                                        plus the three platform-operation
 *                                        applications
 *
 *   tenant_admin    — scope:tenant + every 'app.*.access'
 *                                      : everything scoped to a tenant,
 *                                        plus explicit access to all
 *                                        seven applications
 *
 *   manager         — a hand-picked list: profile, user list, read-only
 *                                       files, and three application
 *                                       accesses (finance, hr, portal)
 *
 *   finance_manager — finance application access + the four file
 *                                       permissions needed for the
 *                                       finance workflow
 *
 *   hr_manager      — hr application access + the same four file
 *                                       permissions
 *
 *   shareholder     — portal access + own profile + read-only files
 *
 *   board           — portal and finance access + profile + read-only
 *                                       files
 *
 *   president       — as board, plus admin application access, user
 *                                       list, and the two list-read
 *                                       permissions for roles and
 *                                       permissions
 *
 *   secretary       — portal access + profile + files including upload
 *                                       and update
 *
 *   cashier         — finance access + profile + files including upload
 *
 *   ceo             — the four application accesses + profile, user
 *                                       list, user create/update, role
 *                                       list, permission list, and files
 *                                       including upload and update
 *
 * =============================================================================
 * The platform-operation subset
 * =============================================================================
 * The three applications granted to platform_admin by Unit C-1 are
 * the "platform-operation" subset of the register:
 *
 *   app.admin.access    — Administration.
 *   app.app.access      — MASES Workspace.
 *   app.launcher.access — Application Launcher.
 *
 * The four end-user applications — portal, finance, hr, arna — are
 * deliberately excluded. A platform administrator's job is
 * administering the platform, not using the applications that serve
 * the platform's user base.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A grant spec that names a permission that
 *     does not exist in the catalogue is silently skipped by the
 *     seeder.
 *
 *   - It does NOT enumerate the resolved permissions per role. That
 *     is the seeder's job.
 *
 *   - It does NOT carry the pivot's table name.
 *
 *   - It does NOT reference application access permissions by any
 *     shorthand.
 *
 * @package Database\Seeders\Data
 */
final class RolePermissionGrants
{
    /**
     * The role-to-permission grant map, keyed by role slug.
     *
     * @var array<string, array<int, string>>
     */
    public const MAP = [
        'super_admin'     => ['*'],
        'platform_admin'  => [
            'scope:global', 'scope:tenant',
            'app.admin.access', 'app.app.access', 'app.launcher.access',
        ],
        'tenant_admin'    => [
            'scope:tenant',
            'app.portal.access', 'app.finance.access', 'app.hr.access',
            'app.admin.access', 'app.arna.access', 'app.app.access', 'app.launcher.access',
        ],
        'manager'         => [
            'user.profile.view', 'user.list', 'people.view',
            'file.list', 'file.view', 'file.download',
            'app.finance.access', 'app.hr.access', 'app.portal.access',
        ],
        'finance_manager' => [
            'app.finance.access',
            'file.upload', 'file.download', 'file.view', 'file.list',
            'user.profile.view',
            'finance.entity.view',
            'finance.account.view', 'finance.account.manage',
            'finance.period.view', 'finance.period.manage',
            'finance.journal.view', 'finance.journal.create',
            'finance.journal.post', 'finance.journal.reverse',
            'finance.report.view',
        ],
        'hr_manager'      => [
            'app.hr.access',
            'file.upload', 'file.download', 'file.view', 'file.list',
            'user.profile.view',
        ],
        'shareholder'     => [
            'app.portal.access', 'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'board'           => [
            'app.portal.access', 'app.finance.access',
            'user.profile.view',
            'file.view', 'file.list', 'file.download',
        ],
        'president'       => [
            'app.portal.access', 'app.finance.access', 'app.admin.access',
            'user.profile.view', 'user.list',
            'file.view', 'file.list', 'file.download',
            'role.list', 'permission.list',
        ],
        'secretary'       => [
            'app.portal.access', 'user.profile.view',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
        'cashier'         => [
            'app.finance.access', 'user.profile.view',
            'file.view', 'file.list', 'file.download', 'file.upload',
        ],
        'ceo'             => [
            'app.portal.access', 'app.finance.access', 'app.hr.access', 'app.admin.access',
            'user.profile.view', 'user.list', 'user.create', 'user.update',
            'role.list', 'permission.list',
            'file.view', 'file.list', 'file.download', 'file.upload', 'file.update',
        ],
    ];
}