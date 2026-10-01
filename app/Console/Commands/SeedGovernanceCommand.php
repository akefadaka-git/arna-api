<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernanceApplicationSeeder;
use MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernancePermissionsSeeder;
use MaaleArna\MASES\Core\Domains\Governance\Database\Seeders\GovernanceRolesSeeder;

/**
 * SeedGovernanceCommand
 *
 * Runs the three Governance domain seeders in their dependency
 * order, working around Plesk's PHP command runner's handling of the
 * `--class` argument (which corrupts the class name by inserting a
 * stray quote and doubling backslashes).
 *
 * Usage:
 *
 *     php artisan governance:seed
 *
 * The command is auto-discovered by Laravel 11+ from the
 * `app/Console/Commands/` directory. No provider registration is
 * required.
 */
final class SeedGovernanceCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'governance:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the Governance domain seeders in dependency order.';

    /**
     * Execute the console command.
     *
     * Delegates to Laravel's `db:seed` command three times, once per
     * seeder. Inside PHP, the class names are expressed as `::class`
     * constants — no string-quoting, no backslashes visible to a
     * shell. This sidesteps Plesk's argument corruption entirely.
     *
     * @return int
     */
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