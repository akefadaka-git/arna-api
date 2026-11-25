<?php

declare(strict_types=1);

/** Rector configuration for the MASES PHP test suite. */

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    /*
     * ------------------------------------------------------------------------
     * SCOPE
     * ------------------------------------------------------------------------
     *
     * Application test directories and every domain-scoped test tree
     * under mases_core. The domain walk is recursive, so per-domain
     * Tests/Unit and Tests/Feature directories are both covered without
     * enumerating them.
     *
     * Production code is intentionally excluded. Do not add app/ or
     * mases_core/src/ here.
     */
    ->withPaths([
        __DIR__ . '/tests',
        __DIR__ . '/mases_core/src/Domains',
    ])

    /*
     * ------------------------------------------------------------------------
     * RULES
     * ------------------------------------------------------------------------
     *
     * Exactly one set is enabled: PHPUnit's annotation-to-attribute
     * conversion set. It contains the rules for @test, @dataProvider,
     * @depends, @group, @covers, @uses, @testWith, and the @requires
     * family, and nothing else.
     *
     * If your installed Rector version exposes the set under a different
     * constant name, the equivalent list of individual rules is:
     *
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\GroupAnnotationToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\UsesAnnotationWithValueToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\TestAnnotationToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DataProviderAnnotationToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DependsAnnotationToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\TestWithAnnotationToAttributeRector
     *   Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\RequiresAnnotationToAttributeRector
     *
     * Prefer the set. Use the explicit list only if the set is unavailable
     * in your installed version and you need to reproduce its behaviour.
     */
    ->withSets([
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])

    /*
     * ------------------------------------------------------------------------
     * TARGET PHP VERSION
     * ------------------------------------------------------------------------
     *
     * Matches the CLI binary referenced in the deployment wrapper
     * (/opt/plesk/php/8.3/bin/php). This tells Rector it may emit PHP 8.3
     * syntax where a choice exists. It does not change the minimum PHP
     * version required by composer.json.
     */
    ->withPhpVersion(PhpVersion::PHP_83)

    /*
     * ------------------------------------------------------------------------
     * IMPORT STYLE
     * ------------------------------------------------------------------------
     *
     * When Rector inserts an attribute it must choose between writing the
     * fully qualified class name inline and adding a `use` statement.
     * `withImportNames()` selects the latter and also removes any import
     * that became unused after an annotation was converted (for example,
     * an import that existed only to satisfy a @covers annotation).
     *
     * This matches the import style already used throughout the
     * repository.
     */
    ->withImportNames(
        removeUnusedImports: true,
    )

    /*
     * ------------------------------------------------------------------------
     * PARALLELISM
     * ------------------------------------------------------------------------
     *
     * Rector partitions work by file, so parallel processing is
     * deterministic. For roughly fourteen hundred files this reduces the
     * full pass from several minutes to well under one minute on a
     * developer workstation.
     *
     * `withParallel()` with no argument uses the maximum number of
     * worker processes the machine can support. If you are running
     * inside a constrained CI container, pass an explicit integer, for
     * example withParallel(4).
     */
    ->withParallel()

    /*
     * ------------------------------------------------------------------------
     * SKIP LIST
     * ------------------------------------------------------------------------
     *
     * Empty at introduction. Populate only if the dry run reveals a rule
     * producing an unwanted change in a specific file. Prefer the
     * per-file skip form over disabling a rule globally, so the
     * conversion stays broad and any exception stays documented here.
     *
     * Example form (do not uncomment unless a real case appears):
     *
     *   ->withSkip([
     *       \Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\TestAnnotationToAttributeRector::class => [
     *           __DIR__ . '/tests/Unit/LegacyTest.php',
     *       ],
     *   ])
     */
    ->withSkip([]);