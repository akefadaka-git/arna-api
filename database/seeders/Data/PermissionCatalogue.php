<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * PermissionCatalogue
 *
 * The platform's forty permissions — the atomic capabilities
 * the authorization system checks. Every permission is either a
 * global platform capability (user profiles, tenants) or a
 * tenant-scoped capability (roles, files, users), or an
 * application-scoped capability (access to one of the seven
 * registered applications).
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the permission catalogue — the list of
 * every capability the platform knows about — lives in a file whose
 * contents can be reviewed and corrected independently of the
 * seeding algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-4). Its contents are byte-for-byte identical to
 * the PERMISSIONS constant it replaces; no field value was altered.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Each row carries the six columns the seeder writes to the
 * `permissions` table:
 *
 *   slug        — the permission's canonical identifier, used in
 *                 `auth.permission:{slug}` route middleware and in
 *                 `PermissionGate` UI gating. Immutable in practice:
 *                 renaming a slug is a coordinated CR across the
 *                 route files, the Vue components, and the database.
 *
 *   name_am     — the display name in Amharic, shown in the
 *                 permission management UI. Consistent with the
 *                 platform's Amharic-first policy.
 *
 *   name_en     — the display name in Latin script.
 *
 *   category    — the permission's functional group. One of:
 *                 'user', 'role', 'permission', 'app_access',
 *                 'file', 'tenant', 'test', 'organization'.
 *
 *   scope       — the permission's scope of applicability. One of:
 *                 'global'      — platform-wide, not tied to a tenant.
 *                 'tenant'      — scoped to a single tenant.
 *                 'application' — scoped to a single application.
 *
 *   is_system   — whether the permission is considered a system
 *                 capability that cannot be removed or renamed via
 *                 the management UI. Two permissions carry this flag:
 *                 'user.profile.view' (every authenticated user can
 *                 view their own profile) and 'app.admin.access'
 *                 (administration access is a platform invariant).
 *
 * =============================================================================
 * The row is a list, not a map
 * =============================================================================
 * This catalogue is an INTEGER-INDEXED list, not a map keyed by slug.
 * That differs from the other extracted constants (APPLICATIONS is
 * keyed by slug; SHAREHOLDERS is keyed by SH-ID; TENANT is a single
 * row). The shape is preserved verbatim from the source constant.
 *
 * The seeder iterates the list and reads each row's 'slug' field to
 * perform the upsert. Keying the catalogue by slug would change the
 * shape of the data and require a corresponding change in the
 * seeder's loop; that is not this unit's job.
 *
 * =============================================================================
 * The seven categories, and why they are not the same as scope
 * =============================================================================
 * 'category' groups permissions by the resource they act on, for
 * display and filtering in the management UI. 'scope' describes the
 * boundary within which a grant of the permission is meaningful.
 *
 * The two are orthogonal. A category-'file' permission can have
 * scope 'tenant' (most of them do). A category-'tenant' permission
 * has scope 'global' (tenant management is a platform capability).
 * A category-'app_access' permission has scope 'application' (it is
 * meaningful only in the context of the named application).
 *
 * =============================================================================
 * The application-scoped permissions
 * =============================================================================
 * Seven permissions in the 'app_access' category carry the slug
 * shape `app.{slug}.access`, one per registered application:
 *
 *   app.portal.access    — portal
 *   app.finance.access   — finance
 *   app.hr.access        — hr
 *   app.admin.access     — admin   (is_system = true)
 *   app.arna.access      — arna
 *   app.app.access       — app
 *   app.launcher.access  — launcher
 *
 * The seeder reads the middle segment of each slug to look up the
 * corresponding application's id in the applications map, so that
 * each permission is written with its `application_id` set. This
 * slug-to-application resolution is the only place the seeder
 * interprets a slug's structure; no other permission's slug is
 * parsed.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'slug', an
 *     unrecognized 'category', a duplicated 'slug') produces whatever
 *     the seeder does with it; the catalogue is not defended against
 *     its own editor.
 *
 *   - It does NOT enforce slug uniqueness. The `permissions` table's
 *     UNIQUE constraint on `slug` does that. This class is the source
 *     data; the schema is the enforcement.
 *
 *   - It does NOT carry a 'description_am' or 'description_en' field.
 *     The seeder writes the permission's `name_am` / `name_en` into
 *     both the name and the description columns of the model — the
 *     current catalogue does not distinguish names from
 *     descriptions. A future CR that adds distinct descriptions
 *     extends the row shape and the seeder's write list together.
 *
 *   - It does NOT carry primary keys. The rows are upserted by slug;
 *     the numeric IDs are assigned by the database on first insert
 *     and preserved on subsequent runs.
 *
 * @package Database\Seeders\Data
 */
final class PermissionCatalogue
{
    /**
     * The fifty-six platform permissions, in source order.
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
        ['slug' => 'app.finance.access',  'name_am' => 'ፋይናንስ መድረስ',              'name_en' => 'Access Finance',  'category' => 'app_access', 'scope' => 'application', 'is_system' => false],
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
        ['slug' => 'test.permission', 'name_am' => 'ፈቃድ መሞከር', 'name_en' => 'Test Permission', 'category' => 'test', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'people.manage', 'name_am' => 'የሰው መገለጫዎችን ማስተዳደር', 'name_en' => 'Manage People Profiles', 'category' => 'user', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'people.account-link.manage', 'name_am' => 'የሰው መገለጫና መለያ ግንኙነትን ማስተዳደር', 'name_en' => 'Manage Global Person Account Links', 'category' => 'user', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'people.tenant-access.manage', 'name_am' => 'የሰው መገለጫን በቴናንት መካከል መፍቀድ', 'name_en' => 'Manage Global Person Tenant Access', 'category' => 'user', 'scope' => 'global', 'is_system' => false],
        ['slug' => 'finance.entity.view', 'name_am' => 'የሕጋዊ አካላትን ማየት', 'name_en' => 'View Legal Entities', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.entity.manage', 'name_am' => 'ሕጋዊ አካላትን ማስተዳደር', 'name_en' => 'Manage Legal Entities', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.account.view', 'name_am' => 'የሂሳብ መዝገብ ማየት', 'name_en' => 'View Chart of Accounts', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.account.manage', 'name_am' => 'የሂሳብ መዝገብ ማስተዳደር', 'name_en' => 'Manage Chart of Accounts', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.period.view', 'name_am' => 'የሂሳብ ጊዜዎችን ማየት', 'name_en' => 'View Accounting Periods', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.period.manage', 'name_am' => 'የሂሳብ ጊዜዎችን ማስተዳደር', 'name_en' => 'Manage Accounting Periods', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.view', 'name_am' => 'የመዝገብ ማስታወሻዎችን ማየት', 'name_en' => 'View Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.create', 'name_am' => 'የመዝገብ ማስታወሻ መፍጠር', 'name_en' => 'Create Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.cancel', 'name_am' => 'የሂሳብ ረቂቅ ማስታወሻዎችን መሰረዝ', 'name_en' => 'Cancel Draft Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.post', 'name_am' => 'የመዝገብ ማስታወሻ መለጠፍ', 'name_en' => 'Post Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.journal.reverse', 'name_am' => 'የመዝገብ ማስታወሻ መቀልበስ', 'name_en' => 'Reverse Journal Entries', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'finance.report.view', 'name_am' => 'የፋይናንስ ሪፖርቶችን ማየት', 'name_en' => 'View Financial Reports', 'category' => 'accounting', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.unit.view', 'name_am' => 'የድርጅት ክፍሎችን ማየት', 'name_en' => 'View Organization Units', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.unit.manage', 'name_am' => 'የድርጅት ክፍሎችን ማስተዳደር', 'name_en' => 'Manage Organization Units', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.assignment.view', 'name_am' => 'የድርጅት ምደባዎችን ማየት', 'name_en' => 'View Organization Assignments', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.assignment.manage', 'name_am' => 'የድርጅት ምደባዎችን ማስተዳደር', 'name_en' => 'Manage Organization Assignments', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
        ['slug' => 'organization.audit.view', 'name_am' => 'የድርጅት የኦዲት ታሪክን ማየት', 'name_en' => 'View Organization Audit History', 'category' => 'organization', 'scope' => 'tenant', 'is_system' => false],
    ];
}