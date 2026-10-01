<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * RoleCatalogue
 *
 * The platform's twelve roles — the named bundles of permissions the
 * authorization system grants to users. Every role is either a
 * platform-scope role (super_admin, platform_admin), a tenant-scope
 * role (tenant_admin, manager, shareholder, board, president,
 * secretary, cashier, ceo), or an application-scope role
 * (finance_manager, hr_manager).
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the role definitions — the named
 * capabilities the platform grants — live in a file whose contents
 * can be reviewed and corrected independently of the seeding
 * algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-5). Its contents are byte-for-byte identical to
 * the ROLES constant it replaces; no field value was altered.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Each row carries the eight fields the seeder uses to write the
 * `roles` table:
 *
 *   slug              — the role's canonical identifier, used in
 *                       `auth.role:{slug}` route middleware, in the
 *                       ROLE_PERMISSIONS grant map, and in the
 *                       BOARD_ROLE_ASSIGNMENTS map. Immutable in
 *                       practice: renaming a slug is a coordinated CR
 *                       across the middleware, the grant map, the
 *                       board assignments, and the database.
 *
 *   name_am           — the display name in Amharic, shown in the
 *                       role management UI. Consistent with the
 *                       platform's Amharic-first policy.
 *
 *   name_en           — the display name in Latin script.
 *
 *   description_am    — a one-line Amharic description of the role's
 *                       intent.
 *
 *   description_en    — the Latin-script counterpart.
 *
 *   scope             — the role's scope of applicability. One of:
 *                         'platform'    — not tied to any tenant or
 *                                         application; a platform-
 *                                         wide capability.
 *                         'tenant'      — scoped to a single tenant.
 *                         'application' — scoped to a single
 *                                         application, and (in this
 *                                         catalogue) always also
 *                                         tenant-bound.
 *
 *   tenant_bound      — whether the role is bound to a specific
 *                       tenant. When true, the seeder writes the
 *                       seeded tenant's id into the role's
 *                       tenant_id column. When false, tenant_id is
 *                       left null and the role applies across every
 *                       tenant.
 *
 *                       The two flag values in the catalogue are:
 *                         false — super_admin, platform_admin.
 *                         true  — every other role.
 *
 *   application_slug  — the slug of the application this role is
 *                       scoped to, or null when the role is not
 *                       application-scoped.
 *
 *                       Two roles carry a non-null value:
 *                         finance_manager → 'finance'
 *                         hr_manager      → 'hr'
 *
 *                       The seeder looks up the corresponding
 *                       application's id in the applications map and
 *                       writes it into the role's application_id
 *                       column.
 *
 * =============================================================================
 * The row is a map, keyed by slug
 * =============================================================================
 * This catalogue is a MAP keyed by slug, not an integer-indexed list.
 * That matches the shape of the APPLICATIONS constant (also keyed by
 * slug) and the SHAREHOLDERS constant (keyed by SH-ID). The
 * PermissionCatalogue, by contrast, is a list. Each constant's shape
 * is preserved verbatim from the source.
 *
 * The keys duplicate the 'slug' value inside each row so that the
 * seeder can address a row by slug without scanning. A maintainer
 * editing this file must update both the outer key and the inner
 * 'slug' field together if a slug ever changes.
 *
 * =============================================================================
 * The role-to-permission grants are a separate constant
 * =============================================================================
 * This class carries the role DEFINITIONS only — the roles' identity,
 * names, scope, and boundaries. The mapping of which permissions each
 * role holds is a separate constant (ROLE_PERMISSIONS), extracted in
 * Unit S-6 as RolePermissionGrants. The two are related but
 * independent: a role can exist with no grants; a grant can exist
 * for a role that does not yet exist (the seeder skips missing
 * roles).
 *
 * =============================================================================
 * The board role assignments are a separate constant
 * =============================================================================
 * The mapping of which register entries (SH-IDs) receive which roles
 * beyond the default 'shareholder' is a third constant
 * (BOARD_ROLE_ASSIGNMENTS), extracted in Unit S-8. It depends on this
 * catalogue's slugs but is not part of it.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'slug', an
 *     unrecognized 'scope' value, an 'application_slug' that does not
 *     resolve to an application) produces whatever the seeder does
 *     with it; the catalogue is not defended against its own editor.
 *
 *   - It does NOT enforce slug uniqueness. The `roles` table's UNIQUE
 *     constraint on `slug` does that. This class is the source data;
 *     the schema is the enforcement.
 *
 *   - It does NOT carry permission lists. Those are in
 *     RolePermissionGrants (Unit S-6).
 *
 *   - It does NOT carry primary keys. The rows are upserted by slug;
 *     the numeric IDs are assigned by the database on first insert
 *     and preserved on subsequent runs.
 *
 * @package Database\Seeders\Data
 */
final class RoleCatalogue
{
    /**
     * The twelve platform roles, keyed by slug.
     *
     * The keys duplicate the 'slug' value inside each row so that the
     * seeder can address a row by slug without scanning. The two are
     * kept in sync manually; a maintainer editing this file must
     * update both if a slug ever changes.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ROWS = [
        'super_admin' => [
            'slug' => 'super_admin', 'name_am' => 'ከፍተኛ የሲስተም አስተዳዳሪ', 'name_en' => 'Super Administrator',
            'description_am' => 'በሁሉም ቴናንቶችና አፕሊኬሽኖች ላይ ሙሉ የሲስተም መዳረሻ ያለው',
            'description_en' => 'Full system access across all tenants and applications.',
            'scope' => 'platform', 'tenant_bound' => false, 'application_slug' => null,
        ],
        'platform_admin' => [
            'slug' => 'platform_admin', 'name_am' => 'የፕላትፎርም አስተዳዳሪ', 'name_en' => 'Platform Administrator',
            'description_am' => 'በፕላትፎርም ደረጃ ላይ ለተፈቀዱ ቅንብሮች የአስተዳደር መዳረሻ ያለው',
            'description_en' => 'Administrative access to platform-level settings.',
            'scope' => 'platform', 'tenant_bound' => false, 'application_slug' => null,
        ],
        'tenant_admin' => [
            'slug' => 'tenant_admin', 'name_am' => 'የቴናንት አስተዳዳሪ', 'name_en' => 'Tenant Administrator',
            'description_am' => 'በተፈቀደለት ቴናንት ውስጥ ሙሉ የአስተዳደር መዳረሻ ያለው',
            'description_en' => 'Full access within a specific tenant.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'manager' => [
            'slug' => 'manager', 'name_am' => 'ሥራ አስኪያጅ', 'name_en' => 'Manager',
            'description_am' => 'በቴናንት ውስጥ የሥራ አስኪያጅ ደረጃ መዳረሻ ያለው',
            'description_en' => 'Manager-level access within a tenant.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'finance_manager' => [
            'slug' => 'finance_manager', 'name_am' => 'የፋይናንስ ሥራ አስኪያጅ', 'name_en' => 'Finance Manager',
            'description_am' => 'በፋይናንስ አፕሊኬሽን ላይ ሙሉ መዳረሻ ያለው',
            'description_en' => 'Full access to finance application.',
            'scope' => 'application', 'tenant_bound' => true, 'application_slug' => 'finance',
        ],
        'hr_manager' => [
            'slug' => 'hr_manager', 'name_am' => 'የሰው ሀብት ሥራ አስኪያጅ', 'name_en' => 'HR Manager',
            'description_am' => 'በሰው ሀብት አስተዳደር ላይ ሙሉ መዳረሻ ያለው',
            'description_en' => 'Full access to HR application.',
            'scope' => 'application', 'tenant_bound' => true, 'application_slug' => 'hr',
        ],
        'shareholder' => [
            'slug' => 'shareholder', 'name_am' => 'ባለአክሲዮን', 'name_en' => 'Shareholder',
            'description_am' => 'የባለአክሲዮን መሠረታዊ መዳረሻ — የባለቤትነት መረጃና የጋራ ስብሰባ ሰነዶች',
            'description_en' => 'Baseline shareholder access — ownership information and general meeting documents.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'board' => [
            'slug' => 'board', 'name_am' => 'የቦርድ አባል', 'name_en' => 'Board Member',
            'description_am' => 'የቦርድ አባልነት መዳረሻ — የቦርድ ስብሰባዎችና የውሳኔ ሰነዶች',
            'description_en' => 'Board member access — board meetings and decision documents.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'president' => [
            'slug' => 'president', 'name_am' => 'የቦርድ ፕሬዚዳንት', 'name_en' => 'Board President',
            'description_am' => 'የቦርድ ፕሬዚዳንት — ስብሰባዎችን የሚመራና ውሳኔዎችን የሚያጸድቅ',
            'description_en' => 'Board President — chairs meetings and approves resolutions.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'secretary' => [
            'slug' => 'secretary', 'name_am' => 'የቦርድ ፀሐፊ', 'name_en' => 'Board Secretary',
            'description_am' => 'የቦርድ ፀሐፊ — ሰነዶችን የሚያዘጋጅና የስብሰባ ማስታወሻ የሚይዝ',
            'description_en' => 'Board Secretary — prepares documents and keeps meeting minutes.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'cashier' => [
            'slug' => 'cashier', 'name_am' => 'ገንዘብ ያዥ', 'name_en' => 'Cashier',
            'description_am' => 'የቦርድ ገንዘብ ያዥ — የገንዘብ ፍሰትን የሚያስተዳድር',
            'description_en' => 'Board Cashier — manages cash flow.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
        'ceo' => [
            'slug' => 'ceo', 'name_am' => 'ዋና ሥራ አስፈጻሚ', 'name_en' => 'CEO',
            'description_am' => 'ዋና ሥራ አስፈጻሚ — የድርጅቱን የዕለት ተዕለት ሥራ የሚመራ',
            'description_en' => 'Chief Executive Officer — leads the day-to-day operations.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
    ];
}