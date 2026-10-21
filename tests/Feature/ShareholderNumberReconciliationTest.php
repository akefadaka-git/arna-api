<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\Shareholder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ShareholderNumberReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function approved_existing_ids_are_numbered_and_legacy_aliases_are_preserved(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $first = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Legacy one',
            'is_active' => true,
        ]);

        DB::table('shareholders')->insert([
            'id' => 100,
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Legacy one hundred',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path(
            'mases_core/src/Domains/Shareholder/Database/Migrations/2026_10_21_000001_reconcile_existing_shareholder_numbers.php'
        );
        $migration->up();

        $this->assertDatabaseHas('shareholders', [
            'id' => $first->id,
            'tenant_id' => $tenant->id,
            'shareholder_number' => 'SH-000001',
        ]);
        $this->assertDatabaseHas('shareholders', [
            'id' => 100,
            'tenant_id' => $tenant->id,
            'shareholder_number' => 'SH-000100',
        ]);
        $this->assertDatabaseHas('shareholder_legacy_aliases', [
            'tenant_id' => $tenant->id,
            'legacy_number_key' => 'SH-0001',
            'shareholder_id' => $first->id,
        ]);
        $this->assertDatabaseHas('shareholder_legacy_aliases', [
            'tenant_id' => $tenant->id,
            'legacy_number_key' => 'SH-0100',
            'shareholder_id' => 100,
        ]);
        $this->assertDatabaseHas('shareholder_number_sequences', [
            'tenant_id' => $tenant->id,
            'next_number' => 101,
        ]);
    }

    #[Test]
    public function reconciliation_aborts_instead_of_guessing_for_unmapped_ids_above_100(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        $first = Shareholder::create([
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Legacy one',
            'is_active' => true,
        ]);

        DB::table('shareholders')->insert([
            'id' => 101,
            'tenant_id' => $tenant->id,
            'shareholder_type' => 'individual',
            'first_name_en' => 'Unmapped legacy record',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path(
            'mases_core/src/Domains/Shareholder/Database/Migrations/2026_10_21_000001_reconcile_existing_shareholder_numbers.php'
        );

        $this->expectException(RuntimeException::class);
        $migration->up();

        $this->assertDatabaseHas('shareholders', [
            'id' => $first->id,
            'shareholder_number' => null,
        ]);
    }
}
