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
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use Tests\TestCase;

final class ArnaBaselineSeederTest extends TestCase
{
    public function test_default_baseline_is_repeatable_and_preserves_existing_and_financial_records(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This integration test requires the disposable MySQL clean-install database.');
        }

        if (! config('app.demo_seeders_enabled')) {
            $this->markTestSkipped('The disposable CI database must explicitly enable demo seeding to create preservation fixtures.');
        }

        // migrate:fresh --seed has already invoked the default DatabaseSeeder.
        // Prove that it provisions reference data only, not identities or financial assumptions.
        $this->assertSame(0, DB::table('users')->count(), 'Default baseline must not create user identities.');
        $this->assertSame(0, DB::table('shareholders')->count(), 'Default baseline must not create shareholder identities.');
        $this->assertGreaterThan(0, DB::table('tenants')->count());
        $this->assertGreaterThan(0, DB::table('platform_applications')->count());
        $this->assertGreaterThan(0, DB::table('permissions')->count());
        $this->assertGreaterThan(0, DB::table('roles')->count());
        $this->assertSame(
            0,
            DB::table('share_classes')->count(),
            'Default baseline must not provision legal share classes before Articles and official-register reconciliation.',
        );
        $this->assertSame(0, DB::table('ledger_accounts')->count(), 'Default baseline must not provision the chart of accounts.');
        $this->assertSame(0, DB::table('loan_products')->count(), 'Default baseline must not provision loan products.');
        $this->assertSame(
            0,
            DB::table('versioned_policies')->where('policy_type', 'loan_product_rules')->count(),
            'Default baseline must not provision loan financial policies.',
        );

        // Populate realistic existing user/shareholder data only in this
        // disposable CI database. This destructive legacy seeder is never
        // called by the production/default baseline.
        $exitCode = Artisan::call('db:seed', ['--class' => MasesCoreSeeder::class, '--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $tenant = Tenant::query()->firstOrFail();

        // An Owner-managed, non-catalogue grant must survive the default baseline.
        $shareholderRole = Role::query()->where('slug', 'shareholder')->firstOrFail();
        $customPermissionId = DB::table('permissions')->insertGetId([
            'name_am' => 'የሙከራ ብጁ ፈቃድ',
            'name_en' => 'Owner-managed custom permission',
            'slug' => 'test.owner-managed.custom',
            'description_am' => 'የግል የሙከራ ፈቃድ',
            'description_en' => 'Owner-managed preservation fixture',
            'category' => 'test',
            'scope' => 'global',
            'tenant_id' => null,
            'application_id' => null,
            'is_active' => true,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_has_permissions')->insert([
            'role_id' => $shareholderRole->id,
            'permission_id' => $customPermissionId,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $this->runSeeder(FinanceChartOfAccountsSeeder::class);
        $coaSnapshot = $this->tableSnapshot('ledger_accounts');
        $this->assertNotSame('', $coaSnapshot);
        app(FinanceChartOfAccountsSeeder::class)->run();
        $this->assertSame($coaSnapshot, $this->tableSnapshot('ledger_accounts'));

        $this->runSeeder(LoanProductsSeeder::class);
        $productSnapshot = $this->tableSnapshot('loan_products');
        $this->assertSame(3, DB::table('loan_products')->count());

        $this->runSeeder(LoanProductRulesPolicySeeder::class);
        $policySnapshot = $this->tableSnapshot('versioned_policies');
        $this->assertSame(1, DB::table('versioned_policies')
            ->where('policy_type', 'loan_product_rules')->count());

        $this->runSeeder(LoanProductsSeeder::class);
        $this->runSeeder(LoanProductRulesPolicySeeder::class);
        $this->assertSame($productSnapshot, $this->tableSnapshot('loan_products'));
        $this->assertSame($policySnapshot, $this->tableSnapshot('versioned_policies'));
        $this->assertSame(0, DB::table('loan_products')->whereNull('product_rules_policy_version_id')->count());

        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertGreaterThan(0, DB::table('shareholders')->count());

        // Reference catalogues are allowed to be added by the first baseline
        // run. Existing identities, shareholder register, financial and other
        // operational records must remain byte-for-byte stable.
        $protectedBefore = $this->snapshotPersistentTables(excludeBaselineReferenceTables: true);
        $this->runDefaultSeeder();
        $this->assertTrue(
            DB::table('role_has_permissions')
                ->where('role_id', $shareholderRole->id)
                ->where('permission_id', $customPermissionId)
                ->exists(),
            'Default baseline must preserve Owner-managed role-permission grants.',
        );
        $protectedAfter = $this->snapshotPersistentTables(excludeBaselineReferenceTables: true);
        $this->assertSame($protectedBefore, $protectedAfter, 'The first safe baseline run must preserve existing business records.');

        // A second default run must also preserve all reference records and
        // all composite RBAC grants without duplicates or detachment.
        $first = $this->snapshotPersistentTables();
        $this->runDefaultSeeder();
        $second = $this->snapshotPersistentTables();
        $this->assertSame($first, $second, 'A second safe baseline run must not delete, duplicate or mutate persistent data or grants.');
        $this->assertTrue(
            DB::table('role_has_permissions')
                ->where('role_id', $shareholderRole->id)
                ->where('permission_id', $customPermissionId)
                ->exists(),
            'Repeated baseline runs must preserve Owner-managed role-permission grants.',
        );
        $this->assertGreaterThan(0, DB::table('users')->count());
        $this->assertGreaterThan(0, DB::table('shareholders')->count());
    }

    private function runDefaultSeeder(): void
    {
        $this->runSeeder(\Database\Seeders\DatabaseSeeder::class);
    }

    private function runSeeder(string $seederClass): void
    {
        $exitCode = Artisan::call('db:seed', ['--class' => $seederClass, '--force' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());
    }

    private function tableSnapshot(string $table): string
    {
        $rows = DB::table($table)->get()->map(
            static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR)
        )->all();
        sort($rows, SORT_STRING);

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * @return array<string, string>
     */
    private function snapshotPersistentTables(bool $excludeBaselineReferenceTables = false): array
    {
        $volatile = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions'];
        $baselineReferences = [
            'tenants',
            'platform_applications',
            'permissions',
            'roles',
            'role_has_permissions',
            'share_classes',
        ];
        $snapshot = [];

        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, $volatile, true)
                || ($excludeBaselineReferenceTables && in_array($table, $baselineReferences, true))) {
                continue;
            }

            $snapshot[$table] = $this->tableSnapshot($table);
        }

        ksort($snapshot);

        return $snapshot;
    }
}
