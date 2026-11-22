<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Default database seed entry point.
 *
 * Uses the non-destructive ARNA baseline. The legacy MasesCoreSeeder remains
 * available for explicitly controlled demo/cohort rebuild workflows only.
 */
final class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ArnaBaselineSeeder::class);
    }
}
