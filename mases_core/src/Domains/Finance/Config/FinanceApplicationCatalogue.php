<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Finance\Config;

/**
 * Finance-owned metadata for the Finance application shell.
 *
 * Platform registries compose this definition; Finance remains the only
 * source of its identity, display metadata, domain and category.
 */
final class FinanceApplicationCatalogue
{
    /** @var array<string, array<string, mixed>> */
    public const ROWS = [
        'finance' => [
            'slug' => 'finance',
            'name_am' => 'ፋይናንስ',
            'name_en' => 'Finance',
            'description_am' => 'የፋይናንስ አስተዳደርና ሪፖርት',
            'description_en' => 'Financial management and reporting.',
            'domain' => 'finance.maalearna.com.et',
            'is_active' => true,
            'is_frontend_ready' => false,
            'icon_key' => 'wallet',
            'category' => 'financial',
            'display_order' => 20,
        ],
    ];

    /** @return list<array{slug:string,name:string,domain:string,description:string}> */
    public static function legacyConfigRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'slug' => $row['slug'],
                'name' => $row['name_en'],
                'domain' => $row['domain'],
                'description' => $row['description_en'],
            ],
            array_values(self::ROWS),
        );
    }
}
