<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Database\Seeders;

/**
 * Finance-owned role definitions used by the platform role seeder.
 */
final class FinanceRoleCatalogue
{
    /** @var array<string, array<string, mixed>> */
    public const ROWS = [
        'finance_manager' => [
            'slug' => 'finance_manager',
            'name_am' => 'የፋይናንስ ሥራ አስኪያጅ',
            'name_en' => 'Finance Manager',
            'description_am' => 'በፋይናንስ አፕሊኬሽን ላይ ሙሉ መዳረሻ ያለው',
            'description_en' => 'Full access to finance application.',
            'scope' => 'application',
            'tenant_bound' => true,
            'application_slug' => 'finance',
        ],
        'cashier' => [
            'slug' => 'cashier',
            'name_am' => 'ገንዘብ ያዥ',
            'name_en' => 'Cashier',
            'description_am' => 'የቦርድ ገንዘብ ያዥ — የገንዘብ ፍሰትን የሚያስተዳድር',
            'description_en' => 'Board Cashier — manages cash flow.',
            'scope' => 'tenant',
            'tenant_bound' => true,
            'application_slug' => null,
        ],
    ];
}
