<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\MasesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
}
