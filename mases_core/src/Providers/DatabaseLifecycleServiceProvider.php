<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Registers all migration directories under MASES Core, independently
 * of feature flags and route activation.
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && $entry->getFilename() === 'Migrations') {
                $paths[] = $entry->getPathname();
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if ($paths !== []) {
            $this->loadMigrationsFrom($paths);
        }
    }
}
