<?php

declare(strict_types=1);

namespace Tests\Feature;

use MaaleArna\MASES\Core\Providers\DatabaseLifecycleServiceProvider;
use Tests\TestCase;

final class DatabaseLifecycleIntegrationTest extends TestCase
{
    public function test_complete_database_lifecycle_provider_is_booted(): void
    {
        $this->assertArrayHasKey(
            DatabaseLifecycleServiceProvider::class,
            $this->app->getLoadedProviders()
        );
    }

    public function test_every_known_core_and_domain_migration_directory_exists(): void
    {
        $expected = [
            'mases_core/src/Migrations',
            'mases_core/src/Policy/Database/Migrations',
            'mases_core/src/Domains/Finance/Database/Migrations',
            'mases_core/src/Domains/Governance/Database/Migrations',
            'mases_core/src/Domains/Loan/Database/Migrations',
            'mases_core/src/Domains/Shareholder/Database/Migrations',
            'mases_core/src/Domains/Wallet/Database/Migrations',
        ];

        foreach ($expected as $relativePath) {
            $this->assertDirectoryExists(
                base_path($relativePath),
                "Expected migration directory is missing: {$relativePath}"
            );
        }
    }

    public function test_default_database_seeder_uses_safe_baseline_not_reset_cohort(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('ArnaBaselineSeeder::class', $source);
        $this->assertStringNotContainsString('MasesCoreSeeder::class', $source);
    }
}
