<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Read-only B5 audit of PHPUnit annotation-to-attribute migration.
 * Scans tests/ and domain PHP files; does not modify files or run Rector.
 */
final class MasesRectorVerifyCommand extends Command
{
    /** @var list<string> PHPUnit attributes recognized by the scan. */
    private const PHPUNIT_ATTRIBUTES = [
        'Test',
        'DataProvider',
        'DataProviderExternal',
        'Depends',
        'DependsExternal',
        'DependsUsingDeepClone',
        'DependsUsingShallowClone',
        'DependsOnClass',
        'DependsOnMethod',
        'Group',
        'CoversClass',
        'CoversFunction',
        'CoversNothing',
        'UsesClass',
        'UsesFunction',
        'TestDox',
        'TestWith',
        'TestWithJson',
        'RequiresPhp',
        'RequiresPhpExtension',
        'RequiresPhpunit',
        'RequiresPhpunitExtension',
        'RequiresOperatingSystem',
        'RequiresOperatingSystemFamily',
        'RequiresSetting',
        'BackupGlobals',
        'BackupStaticProperties',
        'ExcludeGlobalVariableFromBackup',
        'PreserveGlobalState',
        'RunInSeparateProcess',
        'RunTestsInSeparateProcesses',
        'RunClassInSeparateProcess',
        'WithoutErrorHandler',
    ];

    /** @var list<string> Legacy PHPUnit annotations recognized by the scan. */
    private const LEGACY_ANNOTATIONS = [
        'test',
        'dataProvider',
        'depends',
        'group',
        'covers',
        'coversNothing',
        'uses',
        'testdox',
        'backupGlobals',
        'backupStaticProperties',
        'runInSeparateProcess',
        'runTestsInSeparateProcesses',
        'runClassInSeparateProcess',
        'requires',
        'withoutErrorHandler',
    ];

    protected $signature = 'mases:rector:verify
                            {--scope=all    : Which scope to scan: all, tests, or domains}
                            {--output=      : Optional absolute path to write the report to}
                            {--list-files   : Emit one line per file, grouped by classification}';

    protected $description = 'B5 audit helper — classify every PHP file under tests/ and mases_core/src/Domains/ by Rector ANNOTATIONS_TO_ATTRIBUTES state. Read-only.';

    public function handle(): int
    {
        $scope = (string) $this->option('scope');

        $directories = $this->resolveDirectories($scope);

        if ($directories === []) {
            $this->error("Unknown --scope value: {$scope}. Use one of: all, tests, domains.");

            return self::FAILURE;
        }

        $this->info('MASES — B5 Rector verification scan');
        $this->newLine();
        $this->line('Scope           : ' . $scope);
        $this->line('Base path       : ' . base_path());
        $this->line('Directories     : ' . implode(', ', array_keys($directories)));
        $this->newLine();

        $finder = (new Finder())
            ->files()
            ->in(array_values($directories))
            ->name('*.php')
            ->sortByName();

        $classified = [
            'MIGRATED'    => [],
            'MIXED'       => [],
            'LEGACY-ONLY' => [],
            'NEITHER'     => [],
        ];

        $readErrors = [];

        foreach ($finder as $file) {
            try {
                $contents = $file->getContents();
            } catch (Throwable $e) {
                $readErrors[] = $file->getRealPath() . ' — ' . $e->getMessage();
                continue;
            }

            $relative = $this->relativePath($file->getRealPath());

            $category = $this->classify($relative, $contents);

            $classified[$category][] = $relative;
        }

        foreach ($classified as $category => &$paths) {
            sort($paths);
        }
        unset($paths);

        $report = $this->renderReport($classified, $readErrors, $scope);

        $this->output->write($report);

        $outputPath = $this->option('output');

        if (is_string($outputPath) && $outputPath !== '') {
            $absolute = $this->absolutePath($outputPath);

            if (file_put_contents($absolute, $report) === false) {
                $this->error("Could not write report to {$absolute}.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("Report written to: {$absolute}");
        }

        return self::SUCCESS;
    }

    // Directory resolution

    /**
     * @return array<string, string>  map of human label => absolute path
     */
    private function resolveDirectories(string $scope): array
    {
        $tests   = base_path('tests');
        $domains = base_path('mases_core/src/Domains');

        $all = [];

        if (is_dir($tests)) {
            $all['tests/'] = $tests;
        }

        if (is_dir($domains)) {
            $all['mases_core/src/Domains/'] = $domains;
        }

        return match ($scope) {
            'all'     => $all,
            'tests'   => array_intersect_key($all, ['tests/' => true]),
            'domains' => array_intersect_key($all, ['mases_core/src/Domains/' => true]),
            default   => [],
        };
    }

    // Classification

    private function classify(string $relativePath, string $contents): string
    {
        $hasAttribute = $this->hasPhpUnitAttribute($contents);
        $hasLegacy    = $this->hasLegacyAnnotation($contents);

        if ($hasAttribute && $hasLegacy) {
            return 'MIXED';
        }

        if ($hasAttribute) {
            return 'MIGRATED';
        }

        if ($hasLegacy) {
            return 'LEGACY-ONLY';
        }

        return 'NEITHER';
    }

    /** Checks for recognized PHPUnit attributes in PHP 8 attribute syntax. */
    private function hasPhpUnitAttribute(string $contents): bool
    {
        $names = implode('|', array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::PHPUNIT_ATTRIBUTES,
        ));

        $pattern = '/#\[\s*\\\\?'
            . '(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?'
            . '(?:[A-Za-z_][A-Za-z0-9_]*\\\\)?'
            . '(?:' . $names . ')'
            . '\b/s';

        return preg_match($pattern, $contents) === 1;
    }

    /** Checks for recognized legacy PHPUnit annotations in docblocks. */
    private function hasLegacyAnnotation(string $contents): bool
    {
        $names = implode('|', array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::LEGACY_ANNOTATIONS,
        ));

        $pattern = '/^[ \t]*\*[ \t]+@(?:' . $names . ')\b/m';

        return preg_match($pattern, $contents) === 1;
    }

    // Rendering

    /**
     * @param array<string, list<string>> $classified
     * @param list<string>                $readErrors
     */
    private function renderReport(array $classified, array $readErrors, string $scope): string
    {
        $lines = [];

        $totalFiles = array_sum(array_map('count', $classified));

        $lines[] = '================ B5 RECTOR VERIFICATION — REPORT ================';
        $lines[] = 'Generated at    : ' . now()->toIso8601String();
        $lines[] = 'Base path       : ' . base_path();
        $lines[] = 'Scope           : ' . $scope;
        $lines[] = 'Total PHP files : ' . $totalFiles;
        $lines[] = '';
        $lines[] = '--- SUMMARY ---';
        $lines[] = sprintf('MIGRATED      %4d', count($classified['MIGRATED']));
        $lines[] = sprintf('MIXED         %4d', count($classified['MIXED']));
        $lines[] = sprintf('LEGACY-ONLY   %4d', count($classified['LEGACY-ONLY']));
        $lines[] = sprintf('NEITHER       %4d', count($classified['NEITHER']));
        $lines[] = '';

        $lines[] = 'Legend:';
        $lines[] = '  MIGRATED     — has PHPUnit attributes, no legacy annotation.';
        $lines[] = '                 These are the files Rector ANNOTATIONS_TO_ATTRIBUTES touched.';
        $lines[] = '  MIXED        — has BOTH an attribute and a legacy annotation.';
        $lines[] = '                 Suspicious; needs per-file review.';
        $lines[] = '  LEGACY-ONLY  — has legacy annotations but no attribute.';
        $lines[] = '                 If the filename ends in Test.php, this is a file Rector missed.';
        $lines[] = '  NEITHER      — no attribute and no legacy annotation.';
        $lines[] = '                 Normal for non-test sources, models, services, resources.';
        $lines[] = '';

        if ($this->option('list-files')) {
            foreach (['MIGRATED', 'MIXED', 'LEGACY-ONLY'] as $category) {
                $lines[] = '--- ' . $category . ' (' . count($classified[$category]) . ') ---';

                if ($classified[$category] === []) {
                    $lines[] = '(none)';
                    $lines[] = '';
                    continue;
                }

                foreach ($classified[$category] as $path) {
                    $lines[] = '  ' . $path;
                }

                $lines[] = '';
            }

            $lines[] = '--- NEITHER (' . count($classified['NEITHER']) . ') ---';
            $lines[] = '  (suppressed — this category is the expected state for non-test files;';
            $lines[] = '   re-run with --list-files to see them if needed, or omit --list-files to';
            $lines[] = '   keep the report short.)';
            $lines[] = '';
        } else {
            $lines[] = 'Run with --list-files to see the per-file lists.';
            $lines[] = '';
        }

        if ($readErrors !== []) {
            $lines[] = '--- READ ERRORS (' . count($readErrors) . ') ---';

            foreach ($readErrors as $error) {
                $lines[] = '  ' . $error;
            }

            $lines[] = '';
        }

        $lines[] = '================ END REPORT ================';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    // Path helpers

    private function relativePath(string $absolute): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolute, $base)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($base)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $absolute);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return base_path($path);
    }
}