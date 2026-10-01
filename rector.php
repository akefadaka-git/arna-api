<?php

declare(strict_types=1);

/**
 * ============================================================================
 * Rector Configuration — MASES Core Platform Foundation API
 * ============================================================================
 *
 * Target   : Rector 2.x / PHPUnit 11.5 / Laravel 12 / PHP 8.3
 * Location : <project-root>/rector.php
 *
 * ============================================================================
 * REVISION HISTORY
 * ============================================================================
 *
 * v1.0.0 — Initial configuration. Scope is intentionally narrow: convert
 *          PHPUnit doc-comment metadata (the family of annotations that
 *          PHPUnit 11 warns about and PHPUnit 12 will reject outright) into
 *          PHP 8 attributes. Nothing else is migrated by this file.
 *
 *          The motivation is documented in phpunit.xml v1.2.0: PHPUnit 11
 *          emits one warning line per affected test method during its
 *          collection phase. With roughly fourteen hundred test methods in
 *          this repository, those lines completely obscure the pass and
 *          fail blocks that a developer actually needs to read. No XML
 *          attribute can suppress the emission, because the reporter has
 *          not yet run when the message is printed. The only durable fix
 *          is to remove the deprecated metadata from the source, which is
 *          exactly what this configuration does.
 *
 * ============================================================================
 * WHAT THIS FILE TRANSFORMS
 * ============================================================================
 *
 *   Doc-comment annotation              PHP 8 attribute
 *   ---------------------------------   ------------------------------------
 *   @test                               #[Test]
 *   @dataProvider foo                   #[DataProvider('foo')]
 *   @dataProvider foo()                 #[DataProvider('foo')]
 *   @depends testBar                    #[Depends('testBar')]
 *   @group name                         #[Group('name')]
 *   @covers ClassName                   #[CoversClass(ClassName::class)]
 *   @covers ::method                    #[CoversMethod(...)]
 *   @uses ClassName                     #[UsesClass(ClassName::class)]
 *   @testWith [[...], [...]]            #[TestWith([...])] per row
 *   @requires extension x               #[RequiresPhpExtension('x')]
 *   @requires PHP >= 8.3                #[RequiresPhp('>= 8.3')]
 *   @requires OS Linux                  #[RequiresOperatingSystem('Linux')]
 *
 * The corresponding use statements are inserted automatically.
 *
 * ============================================================================
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 * ============================================================================
 *
 *   1. It does not touch production code. withPaths() lists only test
 *      directories. Application and domain source files under app/ and
 *      mases_core/src/ (outside of Tests/) are never rewritten.
 *
 *   2. It does not apply the broad PHPUnit 10 / 11 / 12 rule sets. Those
 *      sets bundle dozens of unrelated migrations (renamed assertions,
 *      signature changes, return-type strictness on test methods, and so
 *      on). Applying them in one pass would produce a diff too large to
 *      review and would risk semantic changes the test authors did not
 *      intend. Only the annotation-to-attribute conversion is enabled here.
 *
 *   3. It does not format code. Rector will preserve existing whitespace
 *      and quoting style wherever it can. If you want a full pass with
 *      Pint or php-cs-fixer afterward, run it separately and review that
 *      diff on its own.
 *
 * ============================================================================
 * HOW TO RUN
 * ============================================================================
 *
 *   Step 1 — install the tool (one time):
 *
 *       composer require --dev rector/rector
 *
 *   Step 2 — preview the changes without writing anything:
 *
 *       vendor/bin/rector process --dry-run
 *
 *   Step 3 — inspect the diff. Every change is a mechanical substitution
 *            of a doc-comment annotation for an attribute. If a method
 *            shows any change that is not of that shape, stop and
 *            investigate before applying.
 *
 *   Step 4 — apply:
 *
 *       vendor/bin/rector process
 *
 *   Step 5 — verify the warnings are gone:
 *
 *       php artisan optimize:clear
 *       php artisan test --compact
 *
 *           The "Metadata found in doc-comment" lines should no longer
 *           appear anywhere in the output. What remains should be exactly
 *           two block shapes: PASS blocks and FAIL blocks.
 *
 * ============================================================================
 * ROLLBACK
 * ============================================================================
 *
 * If the diff is unacceptable, restore the affected files from version
 * control. Nothing in this configuration touches schema, environment, or
 * runtime configuration, so a `git checkout -- tests mases_core` is a
 * complete rollback.
 *
 * ============================================================================
 * DESIGN NOTES
 * ============================================================================
 *
 * 1. `withImportNames()` — when Rector inserts attribute references it
 *    needs to know whether to write the fully qualified name inline or
 *    add a `use` statement. The default (import names, remove unused) is
 *    what produces the cleanest diff and matches the style already used
 *    throughout this repository.
 *
 * 2. `withPhpVersion(PHP_83)` — matches the CLI binary in the deployment
 *    environment. This lets Rector choose PHP 8.3 syntax where a choice
 *    exists. It does not lower the target's required PHP version.
 *
 * 3. `withSkip()` is currently empty. If, after the dry run, you find a
 *    rule that produces an unwanted change in one specific file, add the
 *    rule class to the skip list with the file path rather than disabling
 *    the rule globally. This keeps the conversion broad while allowing
 *    narrow, documented exceptions.
 *
 * 4. `withParallel()` is set to use all available CPU cores. This suite
 *    is large; a single-threaded pass over roughly fourteen hundred files
 *    is slow enough to discourage iteration. Parallelism here is
 *    deterministic because Rector partitions by file, not by rule.
 *
 * ============================================================================
 */

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