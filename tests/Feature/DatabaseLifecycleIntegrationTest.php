<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Support\\Facades\\Artisan;
use Tests\\TestCase;

final class DatabaseLifecycleIntegrationTest extends TestCase
{
    public function test_all_core_and_domain_migration_directories_are_registered(): void
    {
        $paths = (array) config('database.migrations.paths', []);

        $expected = [
            base_path('mases_core/src/Migrations'),
            base_path('mases_core/src/Policy/Database/Migrations'),
            base_path('mases_core/src/Domains/Finance/Database/Migrations'),
            base_path('mases_core/src/Domains/Governance/Database/Migrations'),
            base_path('mases_core/src/Domains/Loan/Database/Migrations'),
            base_path('mases_core/src/Domains/Shareholder/Database/Migrations'),
            base_path('mases_core/src/Domains/Wallet/Database/Migrations'),
        ];

        foreach ($expected as $path) {
            $this->assertContains($path, $paths, "Migration directory was not registered: {$path}");
        }
    }

    public function test_default_database_seeder_is_the_safe_arna_baseline(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString(ArnaBaselineSeeder::class, $source);
        $this->assertStringNotContainsString('MasesCoreSeeder::class', $source);
    }
}
