<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceRouteAndMigrationDiscoveryTest extends TestCase
{
    #[Test]
    public function finance_reporting_routes_are_registered_with_authentication_tenant_and_report_permission(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'api.finance.reports.trial-balance',
            'api.finance.reports.income-statement',
            'api.finance.reports.balance-sheet',
            'api.finance.reports.cash-flow',
        ] as $routeName) {
            $route = $routes->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route, "Missing Finance report route: {$routeName}");
            $this->assertContains('GET', $route->methods(), "Finance report route must be read-only: {$routeName}");

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, "Authentication missing: {$routeName}");
            $this->assertContains('tenant.membership', $middleware, "Tenant membership missing: {$routeName}");
            $this->assertContains(
                'auth.permission:finance.report.view',
                $middleware,
                "Finance report permission missing: {$routeName}",
            );
        }
    }

    #[Test]
    public function finance_ledger_migrations_are_discovered_once_in_timestamp_order(): void
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        $migrationPath = database_path('migrations');
        $discovered = $migrator->getMigrationFiles([$migrationPath]);

        $expected = [
            '2026_10_28_000003_create_financial_ledger_foundation',
            '2026_10_28_000004_add_journal_type_to_journal_entries',
            '2026_10_28_000005_enforce_single_journal_reversal',
            '2026_10_28_000006_create_journal_source_entity_mappings',
            '2026_10_28_120000_add_canonical_coa_metadata_to_ledger_accounts',
            '2026_10_29_080000_add_cash_flow_classification_to_finance',
        ];

        foreach ($expected as $migration) {
            $this->assertArrayHasKey($migration, $discovered, "Finance migration was not discovered: {$migration}");
            $this->assertSame(
                realpath($migrationPath . DIRECTORY_SEPARATOR . $migration . '.php'),
                realpath($discovered[$migration]),
                "Finance migration must be discovered from the canonical migration directory: {$migration}",
            );
        }

        $names = array_keys($discovered);
        $indexes = array_map(
            static fn (string $name): int|false => array_search($name, $names, true),
            $expected,
        );

        $this->assertCount(count($expected), array_unique($indexes), 'Each expected Finance migration must be unique.');
        foreach ($indexes as $index => $position) {
            $this->assertNotFalse($position, "Missing migration index for {$expected[$index]}");
            if ($index > 0) {
                $this->assertLessThan(
                    $position,
                    $indexes[$index - 1],
                    "{$expected[$index - 1]} must be discovered before {$expected[$index]}",
                );
            }
        }
    }
}
