<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\Arna\Core\Domains\Finance\Config\FinanceApplicationCatalogue;

/**
 * ApplicationRegistry
 *
 * The platform's seven registered applications — Shareholder Portal,
 * Finance, Human Resources, Administration, ARNA AI Assistant, the
 * ARNA Workspace, and the Application Launcher itself. This is the
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
 * The class was extracted from ArnaCoreSeeder during the seeder
 * refactoring (Unit S-3). Unit L-10a extended it with four fields
 * that were added to the `platform_applications` table by the L-9
 * migration. Unit D-1 corrects the `launcher` row's `domain` field
 * to reflect where the launcher actually runs.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
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
            'slug' => 'app', 'name_am' => 'ማሴስ ወርክስፔስ', 'name_en' => 'ARNA Workspace',
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