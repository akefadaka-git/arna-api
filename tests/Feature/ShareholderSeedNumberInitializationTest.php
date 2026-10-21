<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\MasesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Shareholder\Services\ShareholderNumberReconciler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShareholderSeedNumberInitializationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fresh_migration_then_seed_assigns_numbers_reservations_aliases_and_next_sequence(): void
    {
        $this->assertDatabaseCount('shareholders', 0);
        $this->assertDatabaseCount('shareholder_number_reservations', 0);
        $this->assertDatabaseCount('shareholder_legacy_aliases', 0);
        $this->assertDatabaseCount('shareholder_number_sequences', 0);

        $exitCode = Artisan::call('db:seed', [
            '--class' => MasesCoreSeeder::class,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $holders = DB::table('shareholders')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'shareholder_number']);

        $this->assertCount(100, $holders);
        $this->assertDatabaseCount('shareholder_number_reservations', 100);
        $this->assertDatabaseCount('shareholder_legacy_aliases', 100);
        $tenantIds = $holders->pluck('tenant_id')->unique()->values();
        $this->assertCount(1, $tenantIds);

        foreach ($holders as $holder) {
            $id = (int) $holder->id;
            $tenantId = (int) $holder->tenant_id;
            $canonical = 'SH-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $legacy = 'SH-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);

            $this->assertSame($canonical, $holder->shareholder_number);

            $this->assertDatabaseHas('shareholder_number_reservations', [
                'tenant_id' => $tenantId,
                'number_sequence' => $id,
                'shareholder_number' => $canonical,
                'shareholder_id' => $id,
            ]);

            $this->assertDatabaseHas('shareholder_legacy_aliases', [
                'tenant_id' => $tenantId,
                'legacy_number' => $legacy,
                'legacy_number_key' => $legacy,
                'shareholder_id' => $id,
            ]);
        }

        $this->assertDatabaseHas('shareholder_number_sequences', [
            'tenant_id' => (int) $tenantIds->first(),
            'next_number' => 101,
        ]);
    }

    #[Test]
    public function a_second_seed_fails_before_clearing_permanent_numbering_history(): void
    {
        $firstExitCode = Artisan::call('db:seed', [
            '--class' => MasesCoreSeeder::class,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $firstExitCode, Artisan::output());

        $before = [
            'users' => DB::table('users')->count(),
            'shareholders' => DB::table('shareholders')->count(),
            'reservations' => DB::table('shareholder_number_reservations')->count(),
            'aliases' => DB::table('shareholder_legacy_aliases')->count(),
            'sequences' => DB::table('shareholder_number_sequences')->count(),
        ];

        $caught = null;

        try {
            Artisan::call('db:seed', [
                '--class' => MasesCoreSeeder::class,
                '--no-interaction' => true,
            ]);
        } catch (\\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(\\RuntimeException::class, $caught);
        $this->assertStringContainsString(
            'cannot clear and reseed shareholders after permanent numbering history exists',
            $caught->getMessage(),
        );

        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['shareholders'], DB::table('shareholders')->count());
        $this->assertSame($before['reservations'], DB::table('shareholder_number_reservations')->count());
        $this->assertSame($before['aliases'], DB::table('shareholder_legacy_aliases')->count());
        $this->assertSame($before['sequences'], DB::table('shareholder_number_sequences')->count());

        $this->assertDatabaseHas('shareholder_number_sequences', [
            'next_number' => 101,
        ]);
    }

    #[Test]
    public function sequence_is_repaired_from_reservations_when_current_shareholder_rows_are_absent(): void
    {
        $exitCode = Artisan::call('db:seed', [
            '--class' => MasesCoreSeeder::class,
            '--no-interaction' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());

        $tenantId = (int) DB::table('shareholder_number_reservations')->value('tenant_id');

        /*
         * Construct a historical-state fixture: permanent reservations
         * remain, while current register rows and aliases are absent.
         * Alias rows are removed first because their FK intentionally
         * restricts shareholder deletion.
         */
        DB::table('shareholder_legacy_aliases')->delete();
        DB::table('shareholders')->delete();
        DB::table('shareholder_number_sequences')
            ->where('tenant_id', $tenantId)
            ->update(['next_number' => 1]);

        app(ShareholderNumberReconciler::class)->reconcile();

        $this->assertDatabaseHas('shareholder_number_sequences', [
            'tenant_id' => $tenantId,
            'next_number' => 101,
        ]);
        $this->assertDatabaseCount('shareholder_number_reservations', 100);
        $this->assertDatabaseCount('shareholders', 0);
    }
}
