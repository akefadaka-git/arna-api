<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use RuntimeException;

/**
 * Fail-closed boundary for the destructive demo/bootstrap seeding flow.
 *
 * These seeders delete the existing user graph and recreate shared
 * bootstrap credentials. They are only permitted in local/testing
 * environments with an explicit MASES_ENABLE_DEMO_SEEDING=true opt-in.
 */
final class DemoSeederGuard
{
    public static function assertAllowed(): void
    {
        $environment = (string) config('app.env', 'production');
        $enabled = filter_var(
            config('app.demo_seeders_enabled', false),
            FILTER_VALIDATE_BOOL,
        );

        if (! in_array($environment, ['local', 'testing'], true) || ! $enabled) {
            throw new RuntimeException(
                'Demo/bootstrap seeding is disabled. It requires APP_ENV=local or testing '
                . 'and MASES_ENABLE_DEMO_SEEDING=true. Never enable this for production data.'
            );
        }
    }
}
