<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;

/**
 * DatabaseMigrations with safe teardown for intentionally irreversible migrations.
 *
 * Laravel's DatabaseMigrations trait rolls migrations back after each test.
 * That is incompatible with migrations whose down() method deliberately
 * refuses to erase permanent identity history. Rebuild the test schema before
 * each test instead; migrate:fresh drops the schema without invoking migration
 * down() methods, and preserves real transaction commits needed by afterCommit
 * event assertions.
 */
trait LoanDatabaseMigrations
{
    use DatabaseMigrations {
        runDatabaseMigrations as protected runDatabaseMigrationsWithRollback;
    }

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
    }
}
