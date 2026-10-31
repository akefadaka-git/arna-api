<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\MASES\Core\Domains\Finance\Config\FinanceApplicationCatalogue;

/**
 * ApplicationRegistry
 *
 * The platform's seven registered applications — Shareholder Portal,
 * Finance, Human Resources, Administration, ARNA AI Assistant, the
 * MASES Workspace, and the Application Launcher itself. This is the
 * data the seeder writes into `platform_applications`.
 *
 * =============================================================================
 * What this class is — and is not
 * =============================================================================
 * This is a DATA class. It contains no logic, no queries, and no side
 * effects. It exists so that the application register — the list of
 * applications the launcher presents to users — lives in a file whose
 * contents can be reviewed and corrected independently of the seeding
 * algorithm.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-3). Unit L-10a extended it with four fields
 * that were added to the `platform_applications` table by the L-9
 * migration. Unit D-1 corrects the `launcher` row's `domain` field
 * to reflect where the launcher actually runs.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * S-3    — initial delivery. Seven rows, seven fields each.
 * L-10a  — four fields added per row (is_frontend_ready, icon_key,
 *          category, display_order).
 * D-1    — the `launcher` row's `domain` field corrected from
 *          `launcher.maalearna.com.et` (a subdomain that has no
 *          deployment) to `app.maalearna.com.et/launcher` (the
 *          route where the launcher actually runs, as part of the
 *          workspace build). No other row changed. See §"The launcher
 *          row's domain" below.
 *
 * =============================================================================
 * The row shape
 * =============================================================================
 * Each row carries the eleven columns the seeder writes to
 * `platform_applications`:
 *
 *   slug              — the application's canonical identifier.
 *                       Matches the `app.{slug}.access` permission
 *                       slugs in the permission catalogue.
 *
 *   name_am           — the display name in Amharic.
 *
 *   name_en           — the display name in Latin script.
 *
 *   description_am    — a one-line Amharic description.
 *
 *   description_en    — the Latin-script counterpart.
 *
 *   domain            — the application's location. For six of the
 *                       seven rows this is a bare hostname without a
 *                       scheme, matching the column's documented
 *                       semantics and the schema's NOT NULL UNIQUE
 *                       constraint. For the seventh row (the
 *                       launcher) it is a hostname-plus-path string,
 *                       because the launcher is not a separately
 *                       deployed subdomain. See §"The launcher row's
 *                       domain" below.
 *
 *   is_active         — whether the application is registered as part
 *                       of the platform ecosystem.
 *
 *   is_frontend_ready — whether a deployable frontend exists at the
 *                       application's location.
 *
 *   icon_key          — the Lucide icon name for the launcher tile's
 *                       glyph, or null to use the tile's default.
 *
 *   category          — the grouping key for the launcher grid, or
 *                       null to leave the tile ungrouped.
 *
 *   display_order     — the sort weight within a category.
 *
 * =============================================================================
 * The launcher row's domain
 * =============================================================================
 * The launcher is not a separately deployed application. It runs as
 * the `/launcher` route within the MASES Workspace build, which is
 * itself served at `app.maalearna.com.et`. There is no
 * `launcher.maalearna.com.et` subdomain, and there never was — the
 * value recorded in the row prior to this revision was a reserved
 * hostname that no deployment served.
 *
 * The domain value is corrected to `app.maalearna.com.et/launcher`:
 * the workspace's host plus the launcher's route path. This is
 * honest about where the launcher lives. It is a URL-like string
 * rather than a bare hostname, and it is the register's single
 * legitimate exception to the column's hostname semantics.
 *
 * Consequences of the corrected value:
 *
 *   · The row's `is_frontend_ready` remains `false`. The launcher is
 *     not separately deployable, so it is not a candidate for the
 *     launcher grid. The endpoint filters on this flag, and the row
 *     does not appear.
 *
 *   · The frontend's LauncherTile builds `https://${domain}` from
 *     the field. For the launcher row that would produce
 *     `https://app.maalearna.com.et/launcher` — the launcher page's
 *     actual URL. The tile never renders this row (see the previous
 *     point), but the constructed URL would be correct if it did.
 *
 *   · The `app.launcher.access` permission remains bound to the row.
 *     The register's shape is preserved; only the domain field's
 *     value changed.
 *
 * A future CR that removes the launcher row entirely — a defensible
 * position, since the launcher is a route rather than an application
 * — would orphan that permission's `application_id` foreign key. That
 * is a separate decision and a separate unit.
 *
 * =============================================================================
 * The readiness flag
 * =============================================================================
 * `is_active` alone cannot distinguish a registered application from
 * one whose frontend is actually deployed. All seven rows carry
 * `is_active = true`, because all seven are intended to be part of
 * the ecosystem. But only two — 'app' (the workspace) and 'portal'
 * (the shareholder portal) — have a build in the current frontend
 * repository. See the two Vite build targets in
 * `mase_app/vite.config.ts`.
 *
 * `is_frontend_ready` distinguishes the two states:
 *
 *   true   — a build exists at the location. The application is
 *            launchable: the tile renders as a clickable link.
 *
 *   false  — the application is registered but no frontend is
 *            deployed. The tile renders with a badge and no link.
 *
 * The launcher endpoint filters on BOTH flags. On the current
 * register that is two rows: `portal` and `app`.
 *
 * The 'launcher' row remains registered and is_active = true. It
 * does not appear in the launcher grid because is_frontend_ready is
 * false — the honest state for a route that is not separately
 * deployed.
 *
 * =============================================================================
 * The icon column
 * =============================================================================
 * `icon_key` names a Lucide icon in the frontend's frozen 56-icon
 * registry (Unit 8.19). An unregistered name logs a deduplicated dev
 * warning and renders no glyph, and the tile falls back to its
 * default.
 *
 * Two rows carry `null`: 'admin' and 'arna'. No icon in the observed
 * Lucide subset fits their semantics; the tiles fall back to their
 * default glyph until suitable icons are confirmed in the registry.
 *
 * =============================================================================
 * The category column
 * =============================================================================
 * `category` groups tiles in the launcher grid. The grid renders
 * flat today; this column exists for a future grid that renders
 * category sections. Five categories appear in the current register:
 * shareholder, financial, operations, platform, intelligence.
 *
 * =============================================================================
 * The ordering column
 * =============================================================================
 * `display_order` sorts tiles within their category. Lower values
 * appear first. Values 10–70 in steps of 10 match the current seed
 * order.
 *
 * =============================================================================
 * What this class deliberately does NOT do
 * =============================================================================
 *   - It does NOT validate. A malformed row (missing 'slug', an
 *     icon_key that is not a string, a duplicated domain) produces
 *     whatever the seeder does with it; the registry is not defended
 *     against its own editor. The database's UNIQUE constraints on
 *     slug and domain are the enforcement.
 *
 *   - It does NOT enforce slug or domain uniqueness. See above.
 *
 *   - It does NOT carry primary keys. The rows are upserted by slug.
 *
 *   - It does NOT read the frontend's icon registry. The icon values
 *     are declared here on the strength of their observation in the
 *     delivered frontend source; verifying them against the registry
 *     is the reviewer's concern, not this class's.
 *
 * @package Database\Seeders\Data
 */
final class ApplicationRegistry
{
    /**
     * The seven registered applications, keyed by slug.
     *
     * The keys duplicate the 'slug' value inside each row so that the
     * seeder can address a row by slug without scanning. The two are
     * kept in sync manually; a maintainer editing this file must
     * update both if a slug ever changes.
     *
     * @var array<string, array<string, mixed>>
     */
    public const ROWS = [
        'portal' => [
            'slug' => 'portal', 'name_am' => 'የባለአክሲዮኖች ፖርታል', 'name_en' => 'Shareholder Portal',
            'description_am' => 'የባለአክሲዮኖች መረጃና ተሳትፎ', 'description_en' => 'Shareholder information and engagement.',
            'domain' => 'portal.maalearna.com.et', 'is_active' => true,
            'is_frontend_ready' => true, 'icon_key' => 'users', 'category' => 'shareholder', 'display_order' => 10,
        ],
        ...FinanceApplicationCatalogue::ROWS,
        'hr' => [
            'slug' => 'hr', 'name_am' => 'የሰው ሀብት አስተዳደር', 'name_en' => 'Human Resources',
            'description_am' => 'የሰው ሀብት አስተዳደር፣ የደመወዝ አስተዳደርና የሰራተኛ መረጃ', 'description_en' => 'HR management, payroll, and personnel.',
            'domain' => 'hr.maalearna.com.et', 'is_active' => true,
            'is_frontend_ready' => false, 'icon_key' => 'briefcase', 'category' => 'operations', 'display_order' => 30,
        ],
        'admin' => [
            'slug' => 'admin', 'name_am' => 'አስተዳደር', 'name_en' => 'Administration',
            'description_am' => 'የፕላትፎርም አስተዳደርና ውቅር', 'description_en' => 'Platform administration and configuration.',
            'domain' => 'admin.maalearna.com.et', 'is_active' => true,
            'is_frontend_ready' => false, 'icon_key' => null, 'category' => 'platform', 'display_order' => 40,
        ],
        'arna' => [
            'slug' => 'arna', 'name_am' => 'ዓርና ኤ.አይ. ረዳት', 'name_en' => 'ARNA AI Assistant',
            'description_am' => 'ለተጠቃሚዎችና ለሕዝብ የሚያገለግል በኤ.አይ. የተደገፈ ረዳት', 'description_en' => 'AI-powered assistant for users and public.',
            'domain' => 'arna.maalearna.com.et', 'is_active' => true,
            'is_frontend_ready' => false, 'icon_key' => null, 'category' => 'intelligence', 'display_order' => 50,
        ],
        'app' => [
            'slug' => 'app', 'name_am' => 'ማሴስ ወርክስፔስ', 'name_en' => 'MASES Workspace',
            'description_am' => 'ማዕከላዊ የሥራ ቦታና የጋራ ዳሽቦርድ', 'description_en' => 'Central workspace and unified dashboard.',
            'domain' => 'app.maalearna.com.et', 'is_active' => true,
            'is_frontend_ready' => true, 'icon_key' => 'home', 'category' => 'platform', 'display_order' => 60,
        ],
        'launcher' => [
            'slug' => 'launcher', 'name_am' => 'የአፕሊኬሽን መክፈቻ', 'name_en' => 'Application Launcher',
            'description_am' => 'ወደተፈቀዱ አፕሊኬሽኖች መዳረሻ', 'description_en' => 'Gateway to authorized applications.',
            /*
             * The launcher's domain is a hostname-plus-path string,
             * not a bare hostname, because the launcher is a route
             * within the workspace build rather than a separately
             * deployed subdomain. See the class docblock §"The
             * launcher row's domain".
             */
            'domain' => 'app.maalearna.com.et/launcher', 'is_active' => true,
            'is_frontend_ready' => false, 'icon_key' => 'layout-grid', 'category' => 'platform', 'display_order' => 70,
        ],
    ];
}