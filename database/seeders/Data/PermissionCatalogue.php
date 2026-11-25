<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinancePermissionCatalogue;
use MaaleArna\MASES\Core\Domains\Documents\Database\Seeders\DocumentsPermissionCatalogue;

/**
 * Canonical permission vocabulary with platform, tenant and application scopes. Permission slugs are stable authorization identifiers.
 *
 * @package Database\Seeders\Data
 */
final class PermissionCatalogue
{
    /**
     * The core platform permissions, in source order; Finance and Admin security catalogues are appended separately.
     *
     * Each row is an associative array with the six keys documented
     * in the class docblock. The order below is preserved verbatim
     * from the source constant; it is the order the seeder iterates
     * and, therefore, the order the permissions appear in the
     * database on a fresh migrate.
     *
     * @var array<int, array<string, mixed>>
     */
    public const ROWS = [
        ['slug' => 'user.profile.view',   'name_am' => 'መገለጫ ማየት',           'name_en' => 'View Profile',              'category' => 'user', 'scope' => 'global',      'is_system' => true],
        ['slug' => 'user.profile.update', 'name_am' => 'መገለጫ ማዘመን',          'name_en' => 'Update Profile',            'category' => 'user', 'scope' => 'global',      'is_system' => false],
        ['slug' => 'user.list',           'name_am' => 'የተጠቃሚዎችን ዝርዝር ማየት', 'name_en' => 'View Users',                'category' => 'user', 'scope' => 'tenant',      'is_system' => false],
        ['slug' => 'user.create',         'name_am' => 'ተጠቃሚ መፍጠር',           'name_en' => 'Create User',               'category' => 'user', 'scope' => 'tenant',      'is_system' => false],
        ['slug' => 'user.update',         'name_am' => 'ተጠቃሚ ማዘመን',           'name_en' => 'Update User',               'category' => 'user', 'scope' => 'tenant',      'is_system' => false],
        ['slug' => 'user.delete',         'name_am' => 'ተጠቃሚ መሰረዝ',           'name_en' => 'Delete User',               'category' => 'user', 'scope' => 'tenant',      'is_system' => false],
        ['slug' => 'user.assign_roles',   'name_am' => 'ለተጠቃሚ ሚናዎችን መመደብ', 'name_en' => 'Assign Roles',              'category' => 'user', 'scope' => 'tenant',      'is_system' => false],
        ['slug' => 'role.list',               'name_am' => 'የሚናዎችን ዝርዝር ማየት',   'name_en' => 'View Roles',                'category' => 'role', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'role.create',             'name_am' => 'ሚና መፍጠር',              'name_en' => 'Create Role',               'category' => 'role', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'role.update',             'name_am' => 'ሚና ማዘመን',              'name_en' => 'Update Role',               'category' => 'role', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'role.delete',             'name_am' => 'ሚና መሰረዝ',              'name_en' => 'Delete Role',               'category' => 'role', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'role.assign_permissions', 'name_am' => 'ለሚና ፈቃዶችን መመደብ',  'name_en' => 'Assign Permissions to Role','category' => 'role', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'permission.list',   'name_am' => 'የፈቃዶችን ዝርዝር ማየት', 'name_en' => 'View Permissions',   'category' => 'permission', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'permission.create', 'name_am' => 'ፈቃድ መፍጠር',        'name_en' => 'Create Permission',  'category' => 'permission', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'permission.update', 'name_am' => 'ፈቃድ ማዘመን',        'name_en' => 'Update Permission',  'category' => 'permission', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'permission.delete', 'name_am' => 'ፈቃድ መሰረዝ',        'name_en' => 'Delete Permission',  'category' => 'permission', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'app.portal.access',   'name_am' => 'ፖርታል መድረስ',               'name_en' => 'Access Portal',   'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'app.hr.access',       'name_am' => 'የሰው ሀብት መድረስ',           'name_en' => 'Access HR',       'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'app.admin.access',    'name_am' => 'አስተዳደር መድረስ',              'name_en' => 'Access Admin',    'category' => 'app_access', 'scope' => 'application', 'is_system' => true],
        ['slug' => 'app.arna.access',     'name_am' => 'ዓርና መድረስ',                 'name_en' => 'Access ARNA',     'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'app.app.access',      'name_am' => 'ማሴስ ወርክስፔስ መድረስ',        'name_en' => 'Access App',      'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'app.launcher.access', 'name_am' => 'የአፕሊኬሽን መክፈቻ መድረስ',      'name_en' => 'Access Launcher', 'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
        ['slug' => 'file.upload',        'name_am' => 'ፋይል መስቀል',              'name_en' => 'Upload Files',         'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.download',      'name_am' => 'ፋይል ማውረድ',              'name_en' => 'Download Files',       'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.view',          'name_am' => 'ፋይል ማየት',               'name_en' => 'View Files',           'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.update',        'name_am' => 'ፋይል ማዘመን',              'name_en' => 'Update Files',         'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.delete',        'name_am' => 'ፋይል መሰረዝ',              'name_en' => 'Delete Files',         'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.force_delete',  'name_am' => 'ፋይልን በግድ መሰረዝ',        'name_en' => 'Force Delete Files',   'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.restore',       'name_am' => 'ፋይልን መልሶ ማግኘት',        'name_en' => 'Restore Files',        'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.list',          'name_am' => 'የፋይሎችን ዝርዝር ማየት',     'name_en' => 'List Files',           'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'file.view_versions', 'name_am' => 'የፋይል ስሪቶችን ማየት',      'name_en' => 'View File Versions',   'category' => 'file', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'tenant.list',   'name_am' => 'የቴናንቶችን ዝርዝር ማየት', 'name_en' => 'View Tenants',   'category' => 'tenant', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'tenant.create', 'name_am' => 'ቴናንት መፍጠር',        'name_en' => 'Create Tenant',  'category' => 'tenant', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'tenant.update', 'name_am' => 'ቴናንት ማዘመን',        'name_en' => 'Update Tenant',  'category' => 'tenant', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'tenant.delete', 'name_am' => 'ቴናንት መሰረዝ',        'name_en' => 'Delete Tenant',  'category' => 'tenant', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'platform.identity.read', 'name_am' => 'የመለያ ማዕከል ማየት', 'name_en' => 'View Central Identity', 'category' => 'platform_admin', 'scope' => 'global', 'is_system' => true],
        ['slug' => 'platform.rbac.read', 'name_am' => 'የሚናና ፈቃድ ማዕከል ማየት', 'name_en' => 'View Central RBAC', 'category' => 'platform_admin', 'scope' => 'global', 'is_system' => true],
        ['slug' => 'platform.tenancy.read', 'name_am' => 'የቴናንት ማዕከል ማየት', 'name_en' => 'View Central Tenancy', 'category' => 'platform_admin', 'scope' => 'global', 'is_system' => true],
        ['slug' => 'test.permission', 'name_am' => 'ፈቃድ መሞከር', 'name_en' => 'Test Permission', 'category' => 'test', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'people.workforce.view', 'name_am' => 'የሰው ኃይል አጠቃላይ መረጃን ማየት', 'name_en' => 'View Workforce Aggregate', 'category' => 'user', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'people.manage', 'name_am' => 'የሰው መገለጫዎችን ማስተዳደር', 'name_en' => 'Manage People Profiles', 'category' => 'user', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'people.account-link.manage', 'name_am' => 'የሰው መገለጫና መለያ ግንኙነትን ማስተዳደር', 'name_en' => 'Manage Global Person Account Links', 'category' => 'user', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'people.tenant-access.manage', 'name_am' => 'የሰው መገለጫን በቴናንት መካከል መፍቀድ', 'name_en' => 'Manage Global Person Tenant Access', 'category' => 'user', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'audit.view', 'name_am' => 'የኦዲት መዝገቦችን ማየት', 'name_en' => 'View Audit Logs', 'category' => 'security', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'security-event.view', 'name_am' => 'የደህንነት ክስተቶችን ማየት', 'name_en' => 'View Security Events', 'category' => 'security', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'security-event.resolve', 'name_am' => 'የደህንነት ክስተቶችን መፍታት', 'name_en' => 'Resolve Security Events', 'category' => 'security', 'scope' => 'tenant', 'is_system' => false],
        ...FinancePermissionCatalogue::ROWS,
        ...DocumentsPermissionCatalogue::ROWS,
        ['slug' => 'calendar.view', 'name_am' => 'ቀን መቁጠሪያ ማየት', 'name_en' => 'View Calendar', 'category' => 'calendar', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'calendar.create', 'name_am' => 'የቀን መቁጠሪያ መፍጠር', 'name_en' => 'Create Calendar Items', 'category' => 'calendar', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'calendar.update', 'name_am' => 'ቀን መቁጠሪያ ማዘመን', 'name_en' => 'Manage Calendar', 'category' => 'calendar', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'calendar.reserve', 'name_am' => 'ሀብት ማስያዝ', 'name_en' => 'Reserve Calendar Resources', 'category' => 'calendar', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'task.view', 'name_am' => 'ተግባሮችን ማየት', 'name_en' => 'View Own and Assigned Tasks', 'category' => 'task', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'task.view_all', 'name_am' => 'የቴናንት ተግባሮችን ማየት', 'name_en' => 'View All Tenant Tasks', 'category' => 'task', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'task.create', 'name_am' => 'ተግባር መፍጠር', 'name_en' => 'Create Tasks', 'category' => 'task', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'task.update', 'name_am' => 'የተመደቡ ተግባሮችን ማዘመን', 'name_en' => 'Update Own and Assigned Tasks', 'category' => 'task', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'task.assign', 'name_am' => 'ተግባሮችን መመደብ', 'name_en' => 'Assign and Manage Corporate Tasks', 'category' => 'task', 'scope' => 'tenant', 'is_system' => false],
            ['slug' => 'public-content.review', 'name_am' => 'ይፋዊ ይዘት መገምገምና ማተም', 'name_en' => 'Review and publish public content', 'category' => 'public_content', 'scope' => 'global', 'is_system' => false],
            ['slug' => 'announcement.view', 'name_am' => 'ማስታወቂያዎችን ማየት', 'name_en' => 'View Announcements', 'category' => 'announcement', 'scope' => 'tenant', 'is_system' => false],
            ['slug' => 'announcement.manage', 'name_am' => 'ማስታወቂያ ማዘጋጀትና ለማጽደቅ ማስገባት', 'name_en' => 'Create and Submit Announcements', 'category' => 'announcement', 'scope' => 'tenant', 'is_system' => false],
            ['slug' => 'announcement.approve', 'name_am' => 'ማስታወቂያ ማጽደቅ', 'name_en' => 'Approve Announcements', 'category' => 'announcement', 'scope' => 'tenant', 'is_system' => false],
            ['slug' => 'announcement.publish', 'name_am' => 'ማስታወቂያ ማተም', 'name_en' => 'Publish Announcements', 'category' => 'announcement', 'scope' => 'tenant', 'is_system' => false],
            ['slug' => 'dashboard.executive.view', 'name_am' => 'የአስፈጻሚ አጠቃላይ እይታ ማየት', 'name_en' => 'View Executive Overview', 'category' => 'dashboard', 'scope' => 'tenant', 'is_system' => false],
];
}