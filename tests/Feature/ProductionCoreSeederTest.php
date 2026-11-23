<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Data\BootstrapUsers;
use Database\Seeders\Data\ShareholderRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProductionCoreSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seeder_bootstraps_102_identities_in_production_without_demo_opt_in(): void
    {
        config([
            'app.env' => 'production',
            'app.demo_seeders_enabled' => false,
        ]);

        $firstExitCode = Artisan::call('db:seed', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $firstExitCode, Artisan::output());
        $this->assertProductionCohortIsComplete();

        // A production bootstrap rerun must reconcile, not duplicate, the cohort.
        $secondExitCode = Artisan::call('db:seed', [
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $secondExitCode, Artisan::output());
        $this->assertProductionCohortIsComplete();
    }

    private function assertProductionCohortIsComplete(): void
    {
        $shareholderEmails = (new \Database\Seeders\ShareholderSeeder())->seededAccountEmails();
        $expectedEmails = array_merge(
            [BootstrapUsers::SUPER_ADMIN['email'], BootstrapUsers::PLATFORM_ADMIN['email']],
            $shareholderEmails,
        );

        $this->assertCount(102, $expectedEmails);
        $this->assertSame(
            102,
            DB::table('users')->whereIn('email', $expectedEmails)->distinct()->count('email'),
        );

        $this->assertSame(100, DB::table('shareholders')->count());
        $this->assertSame(98, DB::table('shareholders')->where('shareholder_type', 'individual')->count());
        $this->assertSame(2, DB::table('shareholders')->where('shareholder_type', '!=', 'individual')->count());

        $this->assertSame(
            100,
            DB::table('shareholders')
                ->whereNotNull('shareholder_number')
                ->where(function ($query): void {
                    $query->whereNotNull('user_id')
                        ->orWhereNotNull('representative_user_id');
                })
                ->count(),
        );

        $shareholderUserIds = DB::table('users')
            ->whereIn('email', $shareholderEmails)
            ->pluck('id');

        $this->assertSame(
            100,
            DB::table('user_has_roles')
                ->whereIn('user_id', $shareholderUserIds)
                ->distinct()
                ->count('user_id'),
        );

        $shareholderRoleId = DB::table('roles')->where('slug', 'shareholder')->value('id');
        $boardRoleId = DB::table('roles')->where('slug', 'board')->value('id');

        $this->assertNotNull($shareholderRoleId);
        $this->assertNotNull($boardRoleId);
        $this->assertSame(
            100,
            DB::table('user_has_roles')->where('role_id', $shareholderRoleId)
                ->whereIn('user_id', $shareholderUserIds)->distinct()->count('user_id'),
        );
        $this->assertSame(
            8,
            DB::table('user_has_roles')->where('role_id', $boardRoleId)
                ->whereIn('user_id', $shareholderUserIds)->distinct()->count('user_id'),
        );

        $this->assertSame(100, DB::table('shareholder_number_reservations')->count());
        $this->assertSame(100, DB::table('shareholder_legacy_aliases')->count());

        $this->assertSame(
            101,
            DB::table('shareholder_number_sequences')->value('next_number'),
        );

        $this->assertSame(100, count(ShareholderRegister::ROWS));
    }
}
