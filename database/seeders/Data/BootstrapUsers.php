<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

/**
 * Seed-only platform administrator records for fresh installs. Rotate bootstrap credentials before production use.
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