<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\Support\DemoSeederGuard;
use Database\Seeders\MasesCoreSeeder;
use Database\Seeders\Support\UserPivotCleaner;
use RuntimeException;
use Tests\TestCase;

final class DemoSeederGuardTest extends TestCase
{
    public function test_demo_seeding_is_disabled_by_default(): void
    {
        config([
            'app.env' => 'local',
            'app.demo_seeders_enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        DemoSeederGuard::assertAllowed();
    }

    public function test_demo_seeding_is_rejected_outside_local_and_testing_even_when_enabled(): void
    {
        config([
            'app.env' => 'production',
            'app.demo_seeders_enabled' => true,
        ]);

        $this->expectException(RuntimeException::class);
        DemoSeederGuard::assertAllowed();
    }

    public function test_demo_seeding_requires_explicit_opt_in_in_testing(): void
    {
        config([
            'app.env' => 'testing',
            'app.demo_seeders_enabled' => true,
        ]);

        DemoSeederGuard::assertAllowed();
        $this->assertTrue(true);
    }

    public function test_core_seeder_is_blocked_before_database_access_when_opt_in_is_missing(): void
    {
        config([
            'app.env' => 'testing',
            'app.demo_seeders_enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        app(MasesCoreSeeder::class)->run();
    }

    public function test_user_pivot_cleanup_is_blocked_before_database_access_when_opt_in_is_missing(): void
    {
        config([
            'app.env' => 'testing',
            'app.demo_seeders_enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);
        app(UserPivotCleaner::class)->clear();
    }

}
