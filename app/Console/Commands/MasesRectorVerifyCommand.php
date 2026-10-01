<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * MasesRectorVerifyCommand
 *
 * B5 audit helper. Read-only. Classifies every `.php` file under the two
 * Rector-targeted scopes — `tests/` and `mases_core/src/Domains/` — by
 * whether it carries PHP 8 attribute syntax (the output of Rector's
 * `PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES`) or legacy PHPUnit
 * docblock annotations.
 *
 * It does NOT modify any file. It does NOT expose a public endpoint.
 * It does NOT run Rector.
 *
 * Purpose: produce the file list needed for the B5 verification pass,
 * distinguishing:
 *
 *   MIGRATED     — file has at least one PHPUnit attribute; no legacy
 *                  annotation remains. This is the expected state for a
 *                  file Rector touched.
 *
 *   MIXED        — file has BOTH an attribute and a legacy annotation.
 *                  This is suspicious: Rector should have converted the
 *                  whole file. A MIXED file needs per-file review.
 *
 *   LEGACY-ONLY  — file has legacy annotations but no attribute. If the
 *                  Rector run was scoped to this directory and this file
 *                  ends in `Test.php`, this is a file Rector should have
 *                  converted but did not.
 *
 *   NEITHER      — file has no attribute and no legacy annotation. This
 *                  is the normal state for a file that was never a
 *                  candidate for the transformation (a model, a service,
 *                  a resource, a helper).
 *
 * The scan is textual. It reads each file once; it does not parse PHP.
 * It is safe to run on the live tree.
 */
final class MasesRectorVerifyCommand extends Command
{
    /**
     * PHPUnit attribute names that Rector's ANNOTATIONS_TO_ATTRIBUTES
     * rule produces, or that a human author may write after the rule
     * has run. The list is a superset of what the rule currently emits;
     * a superset is the correct posture for a verification scan.
     *
     * @var list<string>
     */
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

    /**
     * Legacy PHPUnit docblock annotation names that
     * ANNOTATIONS_TO_ATTRIBUTES converts. Matching is case-sensitive on
     * the tag names that are conventionally lowerCamelCase; a leading
     * `@` and optional whitespace precede them.
     *
     * @var list<string>
     */
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

    // =========================================================================
    // Directory resolution
    // =========================================================================

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

    // =========================================================================
    // Classification
    // =========================================================================

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

    /**
     * Whether the file contains a PHPUnit attribute in `#[...]` syntax.
     *
     * Matches:
     *   #[Test]
     *   #[\PHPUnit\Framework\Attributes\Test]
     *   #[DataProvider('foo')]
     *   #[PHPUnit\Framework\Attributes\DataProvider('foo')]
     *   #[Attributes\DataProvider('foo')]      (with a use import)
     *
     * The class-name alternation is bounded by a word boundary so that
     * a longer attribute whose name merely starts with one of the
     * configured tokens (e.g. a hypothetical `#[\Foo\Testament]`) does
     * not falsely match.
     */
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

    /**
     * Whether the file contains a legacy PHPUnit docblock annotation.
     *
     * Matches tags inside a docblock line:
     *   * @test
     *   * @dataProvider foo
     *   * @depends testFoo
     *
     * The pattern is anchored to the start of a line and requires an
     * asterisk-and-whitespace prefix, so a literal `@test` in a string
     * or a regex does not match.
     */
    private function hasLegacyAnnotation(string $contents): bool
    {
        $names = implode('|', array_map(
            static fn (string $n): string => preg_quote($n, '/'),
            self::LEGACY_ANNOTATIONS,
        ));

        $pattern = '/^[ \t]*\*[ \t]+@(?:' . $names . ')\b/m';

        return preg_match($pattern, $contents) === 1;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

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

    // =========================================================================
    // Path helpers
    // =========================================================================

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