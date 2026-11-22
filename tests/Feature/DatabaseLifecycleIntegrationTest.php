<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\MasesCoreSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Domains\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductRulesPolicySeeder;
use MaaleArna\MASES\Core\Domains\Loan\Database\Seeders\LoanProductsSeeder;
use MaaleArna\MASES\Core\Providers\DatabaseLifecycleServiceProvider;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class DatabaseLifecycleIntegrationTest extends TestCase
{
    public function test_every_migration_directory_is_registered_without_duplicate_basenames(): void
    {
        $coreRoot = base_path('mases_core/src');
        $paths = [database_path('migrations')];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($coreRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && $entry->getFilename() === 'Migrations') {
                $paths[] = $entry->getPathname();
            }
        }

        $files = [];
        foreach ($paths as $path) {
            $this->assertDirectoryExists($path);
            $fileIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($fileIterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $basenames = [];
        foreach ($files as $file) {
            $basenames[pathinfo($file, PATHINFO_FILENAME)][] = $file;
        }

        $duplicates = array_filter($basenames, static fn (array $matches): bool => count($matches) > 1);
        $this->assertSame([], $duplicates, 'Migration basenames must be globally unique.');

        $registered = $this->app->make('migrator')->getMigrationFiles($paths);
        $this->assertCount(count($files), $registered, 'Every migration file must be discoverable exactly once.');
        $this->assertArrayHasKey(DatabaseLifecycleServiceProvider::class, $this->app->getLoadedProviders());
    }

    public function test_default_baseline_is_repeatable_and_preserves_existing_and_financial_records(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires the disposable MySQL clean-install database.');
        }

        if (! config('app.demo_seeders_enabled')) {
            $this->markTestSkipped('The disposable CI database must explicitly enable demo seeding to create preservation fixtures.');
        }

        // Populate realistic existing user/shareholder data only in this
        // disposable CI database. This destructive legacy seeder is never
        // called by the production/default baseline.
        app(MasesCoreSeeder::class)->run();

        $tenant = Tenant::query()->firstOrFail();
        DB::table('legal_entities')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'CI-LIFECYCLE-ENTITY',
            'name_am' => 'የሙከራ ሕጋዊ አካል',
            'name_en' => 'CI Lifecycle Test Entity',
            'base_currency' => 'ETB',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify each controlled financial seeder independently and twice.
        app(FinanceChartOfAccountsSeeder::class)->run();
        $coaCount = DB::table('ledger_accounts')->count();
        $this->assertGreaterThan(0, $coaCount);
        app(FinanceChartOfAccountsSeeder::class)->run();
        $this->assertSame($coaCount, DB::table('ledger_accounts')->count());

        app(LoanProductsSeeder::class)->run();
        $productCount = DB::table('loan_products')->count();
        $this->assertSame(3, $productCount);

        app(LoanProductRulesPolicySeeder::class)->run();
        $policyCount = DB::table('versioned_policies')
            ->where('policy_type', 'loan_product_rules')
            ->count();
        $this->assertSame(1, $policyCount);

        app(LoanProductsSeeder::class)->run();
        app(LoanProductRulesPolicySeeder::class)->run();
        $this->assertSame($productCount, DB::table('loan_products')->count());
        $this->assertSame($policyCount, DB::table('versioned_policies')
            ->where('policy_type', 'loan_product_rules')->count());
        $this->assertSame(0, DB::table('loan_products')->whereNull('product_rules_policy_version_id')->count());

        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertGreaterThan(0, DB::table('shareholders')->count());

        // Run the real default entry point twice. Compare every persistent
        // business/RBAC table so user, shareholder, operational and grant
        // records cannot silently disappear or change on the second run.
        $this->runDefaultSeeder();
        $first = $this->snapshotPersistentTables();

        $this->runDefaultSeeder();
        $second = $this->snapshotPersistentTables();

        $this->assertSame($first, $second, 'A second safe baseline run must not delete, duplicate or mutate persistent data or grants.');
        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertGreaterThan(0, DB::table('shareholders')->count());
    }

    private function runDefaultSeeder(): void
    {
        $exitCode = Artisan::call('db:seed', ['--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @return array<string, string>
     */
    private function snapshotPersistentTables(): array
    {
        $volatile = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'];
        $snapshot = [];

        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, $volatile, true)) {
                continue;
            }

            $rows = DB::table($table)->get()->map(
                static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR)
            )->all();
            sort($rows, SORT_STRING);
            $snapshot[$table] = hash('sha256', implode("\n", $rows));
        }

        ksort($snapshot);

        return $snapshot;
    }
}
