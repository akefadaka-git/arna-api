<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * Default tenant seed record. Its slug is the canonical tenant identifier and must exist before dependent seed data.
 *
 * @package Database\Seeders\Data
 */
final class TenantProfile
{
    /**
     * The default tenant, keyed by the column names the Tenant model
     * persists. The seeder passes this array to Tenant::updateOrCreate()
     * with the 'slug' as the lookup key.
     *
     * @var array<string, mixed>
     */
    public const ROW = [
        'slug'      => 'main',
        'name_am'   => 'ማሌ ዓርና ዋና',
        'name_en'   => 'Maale Arna Main',
        'domain'    => 'main.maalearna.com.et',
        'settings'  => ['timezone' => 'Africa/Addis_Ababa', 'locale' => 'am'],
        'is_active' => true,
    ];
}