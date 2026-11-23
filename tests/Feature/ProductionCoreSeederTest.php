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

    public function test_production_bootstrap_rerun_preserves_existing_operational_account_and_register_state(): void
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

        $superAdminEmail = BootstrapUsers::SUPER_ADMIN['email'];
        $shareholderEmail = (new \Database\Seeders\ShareholderSeeder())->seededAccountEmails()[0];

        $superAdmin = DB::table('users')->where('email', $superAdminEmail)->first();
        $shareholder = DB::table('users')->where('email', $shareholderEmail)->first();
        $this->assertNotNull($superAdmin);
        $this->assertNotNull($shareholder);

        $register = DB::table('shareholders')->where('user_id', $shareholder->id)->first();
        $this->assertNotNull($register);

        $loginAt = '2026-11-01 12:34:56';
        DB::table('users')->whereIn('id', [$superAdmin->id, $shareholder->id])->update([
            'is_active' => false,
            'email_verified_at' => null,
            'last_login_at' => $loginAt,
            'last_login_ip' => '203.0.113.10',
            'address' => 'Operator-corrected address',
        ]);
        DB::table('shareholders')->where('id', $register->id)->update([
            'is_active' => false,
            'first_name_en' => 'Operator-corrected register name',
        ]);

        $secondExitCode = Artisan::call('db:seed', [
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $secondExitCode, Artisan::output());

        foreach ([$superAdmin->id, $shareholder->id] as $userId) {
            $user = DB::table('users')->where('id', $userId)->first();
            $this->assertFalse((bool) $user->is_active);
            $this->assertNull($user->email_verified_at);
            $this->assertSame($loginAt, $user->last_login_at);
            $this->assertSame('203.0.113.10', $user->last_login_ip);
            $this->assertSame('Operator-corrected address', $user->address);
        }

        $preservedRegister = DB::table('shareholders')->where('id', $register->id)->first();
        $this->assertFalse((bool) $preservedRegister->is_active);
        $this->assertSame('Operator-corrected register name', $preservedRegister->first_name_en);
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

        $this->assertSame(
            4,
            DB::table('permissions')->whereIn('slug', [
                'shareholder.view_own',
                'wallet.view_own',
                'loan.apply',
                'governance.view',
            ])->count(),
        );
        $this->assertSame(2, DB::table('share_classes')->count());
        $this->assertDatabaseHas('share_classes', ['code' => 'FOUNDERS']);
        $this->assertDatabaseHas('share_classes', ['code' => 'STANDARD']);

        $this->assertSame(100, DB::table('shareholder_number_reservations')->count());
        $this->assertSame(100, DB::table('shareholder_legacy_aliases')->count());

        $this->assertSame(
            101,
            DB::table('shareholder_number_sequences')->value('next_number'),
        );

        $this->assertSame(100, count(ShareholderRegister::ROWS));
    }
}
