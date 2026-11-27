<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MaaleArna\Arna\Core\Domains\Governance\Database\Seeders\GovernanceApplicationSeeder;
use MaaleArna\Arna\Core\Domains\Governance\Database\Seeders\GovernancePermissionsSeeder;
use MaaleArna\Arna\Core\Domains\Governance\Database\Seeders\GovernanceRolesSeeder;

/** Runs Governance domain seeders in dependency order. */
final class SeedGovernanceCommand extends Command
{

    protected $signature = 'governance:seed';


    protected $description = 'Run the Governance domain seeders in dependency order.';


    public function handle(): int
    {
        $this->info('Seeding Governance domain...');

        $this->call('db:seed', ['--class' => GovernanceApplicationSeeder::class]);
        $this->call('db:seed', ['--class' => GovernancePermissionsSeeder::class]);
        $this->call('db:seed', ['--class' => GovernanceRolesSeeder::class]);

        $this->info('Governance domain seeded.');

        return self::SUCCESS;
    }
}