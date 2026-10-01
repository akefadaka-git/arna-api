<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * BootstrapUsers
 *
 * The two administrative accounts MasesCoreSeeder creates before the
 * shareholder cohort: the super administrator and the platform
 * administrator. They are the platform's first two identities and the
 * accounts used to bootstrap the system on a fresh install.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the two bootstrap accounts — the first
 * identities a running platform knows about — live in a file whose
 * contents can be reviewed and corrected independently of the seeding
 * algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-7). Its contents are byte-for-byte identical to
 * the two constants it replaces (SUPER_ADMIN_USER and ADMIN_USER); no
 * field value was altered.
 *
 * =============================================================================
 * Why two constants in one file
 * =============================================================================
 * The two users share:
 *
 *   - the same row shape (eleven keys, listed below),
 *   - the same role in the seeder's flow (created before the
 *     shareholder cohort, each attached to the seeded tenant and to
 *     exactly one role),
 *   - the same default password (DEFAULT_PASSWORD = 'Arna@2019',
 *     held by the seeder, not by this class).
 *
 * The differences between them are limited to their identity fields
 * (email, username, names), their locale (both 'am'), and their
 * assigned role (super_admin vs. platform_admin).
 *
 * Keeping them in one file makes that parallel structure visible.
 * When a future reviewer wants to add a third bootstrap account — a
 * platform observer, a deployment-only service account, etc. — the
 * pattern for a new constant is one screen below the existing two.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Both rows carry the same eleven keys, matching the columns the
 * seeder writes to the `users` table (with the two compound name
 * blocks split into their six constituent columns):
 *
 *   email                — the account's email address, used as the
 *                          primary identifier for upsert. The seeder
 *                          looks up an existing row by email before
 *                          creating; a match updates in place.
 *
 *   username             — the account's login handle. Unique per the
 *                          `users.users_username_unique` index.
 *                          Immutable in practice: renaming a bootstrap
 *                          username is a coordinated CR.
 *
 *   first_name_am        — the account's given name in Amharic.
 *   first_name_en        — the same name in Latin script.
 *   father_name_am       — the patronymic in Amharic.
 *   father_name_en       — the patronymic in Latin script.
 *   grandfather_name_am  — the avonymic in Amharic. Nullable; both
 *                          bootstrap accounts carry null.
 *   grandfather_name_en  — the avonymic in Latin script. Nullable;
 *                          both bootstrap accounts carry null.
 *
 *                          The six name fields are the composite
 *                          Ethiopian name (as documented in the
 *                          User model's getNameAttribute accessor).
 *                          The accessor composes a display name from
 *                          these six columns, preferring Amharic.
 *
 *   address              — the account's postal or geographic address.
 *                          Nullable; both bootstrap accounts carry
 *                          null, since they are system identities
 *                          rather than physical persons.
 *
 *   locale               — the account's preferred locale. Both are
 *                          'am', consistent with the platform's
 *                          Amharic-first policy.
 *
 *   is_active            — whether the account is enabled. Both are
 *                          true.
 *
 *   role_slug            — the role the seeder assigns to the
 *                          account after creation. This field is not
 *                          a column on the users table; it is the
 *                          seeder's directive for which entry in
 *                          RoleCatalogue to attach as the account's
 *                          role.
 *
 * =============================================================================
 * The two accounts
 * =============================================================================
 *   SUPER_ADMIN
 *     email      superadmin@maalearna.com.et
 *     username   superadmin
 *     name_am    ሲስተም አስተዳዳሪ        ("System Administrator")
 *     role_slug  super_admin
 *
 *     The platform's highest-privilege account. Holds every
 *     permission via the ROLE_PERMISSIONS entry for 'super_admin'
 *     (which is the wildcard grant '*'). Has no tenant assignment
 *     other than the tenant the seeder attaches it to; the
 *     'super_admin' role itself is not tenant-bound, so its
 *     capabilities apply across every tenant.
 *
 *   PLATFORM_ADMIN
 *     email      admin@maalearna.com.et
 *     username   admin
 *     name_am    አስተዳዳሪ ፕላትፎርም        ("Platform Administrator")
 *     role_slug  platform_admin
 *
 *     The platform's second-highest-privilege account. Holds every
 *     'scope:global' and 'scope:tenant' permission via the
 *     ROLE_PERMISSIONS entry for 'platform_admin', but not the
 *     'scope:application' permissions. This is deliberate — the
 *     platform administrator is intended to be a platform-level
 *     operator, not an application-level one. Application access is
 *     granted explicitly through the tenant_admin role, which the
 *     platform_admin does not hold.
 *
 *     The seeder attaches this account's role with the SUPER_ADMIN
 *     account as the 'assigned_by' value, so the platform_admin's
 *     role grant is recorded as having been performed by the
 *     super_admin. The SUPER_ADMIN account's own role grant carries
 *     a null 'assigned_by', since there is no higher authority to
 *     attribute it to.
 *
 * =============================================================================
 * The default password
 * =============================================================================
 * The seeder assigns both accounts the same default password,
 * 'Arna@2019', held as DEFAULT_PASSWORD on the seeder — not here.
 * The password is a bootstrap credential and MUST be rotated on
 * first login in any non-development environment. The seeder's
 * printSummary method warns the operator about this.
 *
 * The password is not part of these rows because it is not a column
 * the seeder persists directly; the seeder hashes it before writing
 * the users.password field, and the pre-hash string lives on the
 * seeder itself, not on the data.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'email', an
 *     unrecognized 'role_slug', a duplicate 'username') produces
 *     whatever the seeder does with it; the bootstrap users are not
 *     defended against their own editor.
 *
 *   - It does NOT carry the password hash, the password_changed_at
 *     flag, the last_login_at or last_login_ip audit fields, or the
 *     email_verified_at flag. Those are set by the seeder's
 *     upsertAdminUser method, which is the same method that writes
 *     both accounts.
 *
 *   - It does NOT carry primary keys. The rows are upserted by
 *     email; the numeric IDs are assigned by the database on first
 *     insert and preserved on subsequent runs.
 *
 *   - It does NOT carry the roles the two accounts are attached to
 *     as nested data. The roles are looked up by slug from the
 *     RoleCatalogue at seed time, and the attach operation is a
 *     separate step (assignUserRelations) that also runs for the
 *     100 shareholders.
 *
 *   - It does NOT reference an application. Neither bootstrap
 *     account is application-scoped.
 *
 * @package Database\Seeders\Data
 */
final class BootstrapUsers
{
    /**
     * The super administrator — the platform's highest-privilege
     * account. The seeder creates this account first, before any
     * other user, so that subsequent role grants can be attributed
     * to it.
     *
     * @var array<string, mixed>
     */
    public const SUPER_ADMIN = [
        'email' => 'superadmin@maalearna.com.et',
        'username' => 'superadmin',
        'first_name_am' => 'ሲስተም', 'first_name_en' => 'System',
        'father_name_am' => 'አስተዳዳሪ', 'father_name_en' => 'Administrator',
        'grandfather_name_am' => null, 'grandfather_name_en' => null,
        'address' => null,
        'locale' => 'am', 'is_active' => true, 'role_slug' => 'super_admin',
    ];

    /**
     * The platform administrator — the platform's second-highest-
     * privilege account. The seeder creates this account after the
     * super administrator, so that its role grant can be attributed
     * to the super administrator as the assigning authority.
     *
     * @var array<string, mixed>
     */
    public const PLATFORM_ADMIN = [
        'email' => 'admin@maalearna.com.et',
        'username' => 'admin',
        'first_name_am' => 'አስተዳዳሪ', 'first_name_en' => 'Administrator',
        'father_name_am' => 'ፕላትፎርም', 'father_name_en' => 'Platform',
        'grandfather_name_am' => null, 'grandfather_name_en' => null,
        'address' => null,
        'locale' => 'am', 'is_active' => true, 'role_slug' => 'platform_admin',
    ];
}