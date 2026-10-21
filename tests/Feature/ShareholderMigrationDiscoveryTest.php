<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShareholderMigrationDiscoveryTest extends TestCase
{
    #[Test]
    public function both_numbering_migrations_are_discovered_once_in_timestamp_order(): void
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $schemaMigration = '2026_10_18_000006_add_shareholder_business_number';
        $reconciliationMigration = '2026_10_21_000001_reconcile_existing_shareholder_numbers';

        $defaultPath = database_path('migrations');
        $domainPath = base_path(
            'mases_core/src/Domains/Shareholder/Database/Migrations'
        );

        $registeredPaths = array_map(
            static fn (string $path): string => rtrim($path, DIRECTORY_SEPARATOR),
            $migrator->paths(),
        );

        $this->assertContains(
            $domainPath,
            $registeredPaths,
            'ShareholderServiceProvider must register the domain migration directory.',
        );

        $migrationPaths = array_values(array_unique([
            ...$registeredPaths,
            $defaultPath,
        ]));

        $discovered = $migrator->getMigrationFiles($migrationPaths);

        $this->assertArrayHasKey($schemaMigration, $discovered);
        $this->assertArrayHasKey($reconciliationMigration, $discovered);
        $this->assertSame(
            realpath($defaultPath . DIRECTORY_SEPARATOR . $schemaMigration . '.php'),
            realpath($discovered[$schemaMigration]),
            'The schema migration must be discovered from database/migrations only.',
        );
        $this->assertSame(
            realpath($domainPath . DIRECTORY_SEPARATOR . $reconciliationMigration . '.php'),
            realpath($discovered[$reconciliationMigration]),
            'The reconciliation migration must be discovered from the registered Shareholder domain path.',
        );

        $migrationNames = array_keys($discovered);
        $this->assertLessThan(
            array_search($reconciliationMigration, $migrationNames, true),
            array_search($schemaMigration, $migrationNames, true),
            'Schema migration must sort before reconciliation migration.',
        );

        $this->assertFileDoesNotExist(
            $domainPath . DIRECTORY_SEPARATOR . $schemaMigration . '.php',
            'The old nested schema migration copy must not be present.',
        );
    }
}
