<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Providers;

use Illuminate\\Filesystem\\Filesystem;
use Illuminate\\Support\\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Registers every MASES Core and domain migration directory independently
 * of feature flags. Schema lifecycle must not depend on whether HTTP routes
 * for a domain are enabled.
 *
 * The standard database/migrations directory remains Laravel's default.
 * Existing domain providers may also register their own paths; Laravel's
 * migrator indexes migration files by basename, so the same migration file
 * is not executed twice. This provider is the completeness backstop for
 * domains whose service providers conditionally load migrations.
 */
final class DatabaseLifecycleServiceProvider extends ServiceProvider
{
    public function boot(Filesystem $files): void
    {
        $root = base_path('mases_core/src');
        if (!$files->isDirectory($root)) {
            return;
        }

        $paths = [];
        $coreMigrations = $root . DIRECTORY_SEPARATOR . 'Migrations';
        if ($files->isDirectory($coreMigrations)) {
            $paths[] = $coreMigrations;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir() || $entry->getFilename() !== 'Migrations') {
                continue;
            }

            $path = $entry->getPathname();
            if ($path !== $coreMigrations) {
                $paths[] = $path;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }
}
