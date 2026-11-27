<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use MaaleArna\Arna\Core\Domains\Finance\Database\Seeders\FinanceRoleCatalogue;

/**
 * Canonical platform role definitions. Keep role slugs stable; permission grants are maintained separately.
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
        ...FinanceRoleCatalogue::ROWS,
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
        'ceo' => [
            'slug' => 'ceo', 'name_am' => 'ዋና ሥራ አስፈጻሚ', 'name_en' => 'CEO',
            'description_am' => 'ዋና ሥራ አስፈጻሚ — የድርጅቱን የዕለት ተዕለት ሥራ የሚመራ',
            'description_en' => 'Chief Executive Officer — leads the day-to-day operations.',
            'scope' => 'tenant', 'tenant_bound' => true, 'application_slug' => null,
        ],
    ];
}