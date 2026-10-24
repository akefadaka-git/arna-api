<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoanRepaymentIdempotencyMigrationDiscoveryTest extends TestCase
{
    #[Test]
    public function idempotency_schema_migration_is_discovered_once_from_the_canonical_flat_directory(): void
    {
        $migrationName = '2026_10_24_120000_create_loan_repayment_idempotencies_table';
        $flatPath = database_path('migrations/' . $migrationName . '.php');
        $legacyDomainPath = base_path(
            'mases_core/src/Domains/Loan/Database/Migrations/' . $migrationName . '.php',
        );

        $this->assertFileExists($flatPath);
        $this->assertFileDoesNotExist(
            $legacyDomainPath,
            'Schema migrations must have one canonical copy in database/migrations.',
        );

        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $registeredPaths = array_values(array_filter(array_map(
            static fn (string $path): string|false => realpath($path),
            $migrator->paths(),
        )));

        $migrationPaths = array_values(array_unique([
            ...$registeredPaths,
            database_path('migrations'),
        ]));

        $discovered = $migrator->getMigrationFiles($migrationPaths);

        $this->assertArrayHasKey($migrationName, $discovered);
        $this->assertSame(
            realpath($flatPath),
            realpath($discovered[$migrationName]),
            'The idempotency schema migration must resolve to the canonical flat file.',
        );
    }
}
