<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P1-5
 * =============================================================================
 *
 * File:            ShareClassSeeder.php  (NEW)
 * Unit:            P1-5 — populate share_classes reference data
 * Delivery type:   New file
 * Net lines:       +…
 *
 * Before:
 *   The `share_classes` table was empty in the deployed database. The
 *   migration that creates the table (2026_09_11_000001) deliberately
 *   does not insert rows — its docblock states that the two rows are
 *   inserted by "the ShareClassSeeder (CR-008), not by this migration."
 *   No such seeder had been written. The two sibling seeders
 *   (ShareholderPermissionsSeeder, ShareholderRolesSeeder) reference the
 *   Shareholder domain's permissions and roles respectively, but neither
 *   populates the domain's reference data.
 *
 *   Consequences of the empty table:
 *     · ShareClassService::all() returns an empty collection (cold or
 *       warm cache).
 *     · ShareClassService::defaultClassOrFail() and
 *       founderClassOrFail() throw RuntimeException — mapped to HTTP
 *       500 INTERNAL_SERVER_ERROR by the canonical renderer. Any code
 *       path that reaches them (ShareholderService::register() with
 *       default-class resolution, ShareHoldingService::findOrCreateHolding(),
 *       CertificateService::issueFor()) is effectively unreachable in
 *       production.
 *     · The portal's /my/holdings and /my/certificates pages would
 *       render share_class.display_name as the class code (FOUNDERS,
 *       STANDARD) rather than the intended Amharic label, and any
 *       holding whose share_class_id pointed at a missing row would
 *       resolve to null.
 *
 *   In the current deployed state, no such downstream symptoms have
 *   manifested because share_holdings and share_certificates are also
 *   empty — the seed shareholders carry users but not yet holdings.
 *   The moment a holding or certificate is created, the empty
 *   share_classes table becomes a hard blocker.
 *
 * After:
 *   One new seeder that writes exactly two rows to `share_classes` —
 *   FOUNDERS and STANDARD — with the Amharic-first labels recorded in
 *   the migration's own docblock, the par value and currency read from
 *   config at run time, and both service cache keys invalidated as the
 *   final step.
 *
 * Surface introduced:
 *   · Class: MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders\ShareClassSeeder
 *   · Constant: SHARE_CLASSES (private)
 *   · Method: run() (public, seeded by Laravel's Seeder contract)
 *
 * Files modified:            none
 * Files added:               this file only
 * Files deleted:             none
 * Migrations:                none
 * Controllers:               none
 * Services:                  none
 * Models:                    none
 * Resources:                 none
 * Routes:                    none
 * Config:                    none
 *
 * Manual execution required (documented in §"How to run" below):
 *   php artisan db:seed \
 *       --class="MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareClassSeeder"
 *
 * This file is NOT registered in Database\Seeders\DatabaseSeeder; the
 * Shareholder domain's seeders are invoked explicitly, matching the
 * pattern of ShareholderPermissionsSeeder and ShareholderRolesSeeder.
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use MaaleArna\MASES\Core\Domains\Shareholder\Models\ShareClass;
use RuntimeException;

/**
 * ShareClassSeeder
 *
 * Populates the `share_classes` reference table with the two share
 * classes the domain recognises in Phase 2A (decision D3):
 *
 *   FOUNDERS  (name_am = መስራች)  — held by the founding shareholders.
 *   STANDARD  (name_am = መደበኛ)  — the default class for new shareholders.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * Two rows, one per code declared in `config('shareholder.share_classes.codes')`:
 *
 *   code         — the immutable, uppercase identifier; the primary
 *                  natural key (UNIQUE column on the table).
 *   name_am      — the Amharic display name; taken from the migration
 *                  docblock's own record of the intended vocabulary.
 *   name_en      — the English display name.
 *   par_value    — read at run time from
 *                  `config('shareholder.par_value.amount')`. The
 *                  config key is env-overridable via
 *                  MASES_SHAREHOLDER_PAR_VALUE.
 *   currency     — read at run time from
 *                  `config('shareholder.par_value.currency')`. The
 *                  config key is env-overridable via
 *                  MASES_SHAREHOLDER_CURRENCY.
 *   is_active    — true. Both classes are live in Phase 2A.
 *   is_treasury_eligible — false. Neither class is treasury-eligible
 *                          in Phase 2A. A future CR that turns on
 *                          treasury for a class updates the seeder
 *                          and re-runs it.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * Every insert is performed via `updateOrCreate(['code' => $code], [...])`.
 * Running this seeder repeatedly is safe:
 *
 *   · A row whose code is present is updated to the values declared here.
 *   · A row whose code is absent is inserted.
 *   · A row whose code is neither referenced here nor pre-existing is
 *     never touched. The seeder does not delete share classes.
 *
 * The `ShareClass` model's `code` set-mutator normalises the value to
 * uppercase with trimmed whitespace, so the natural key used by
 * `updateOrCreate` matches the stored representation on both insert and
 * lookup. A caller that runs this seeder against a database whose rows
 * were originally inserted with mixed-case codes will still match by
 * the normalised form.
 *
 * =============================================================================
 * Amharic labels — vocabulary and its source
 * =============================================================================
 * The two Amharic strings come from the migration's own class-level
 * docblock (2026_09_11_000001_create_share_classes_table.php), which
 * records them as the intended seed contents:
 *
 *     FOUNDERS  — held by the 29 founding shareholders, 145 shares total.
 *                 Amharic label: መስራች.
 *
 *     STANDARD  — the default class for new shareholders.
 *                 Amharic label: መደበኛ.
 *
 * The same two labels are referenced in the ShareholderRolesSeeder's
 * docblock (in the role-name table) and in the ShareholderPermissionsSeeder's
 * docblock (as the term-matching rationale for the permission labels).
 * Writing them here as data (not as config keys) matches the pattern
 * the two sibling seeders use for the vocabulary that belongs to the
 * domain's own reference data.
 *
 * =============================================================================
 * Config-code consistency
 * =============================================================================
 * Before writing, the seeder verifies that the codes it intends to seed
 * match the codes the config declares, in both directions:
 *
 *   · Every code in `config('shareholder.share_classes.codes')` must
 *     appear in this seeder's SHARE_CLASSES constant.
 *   · Every code in this seeder's SHARE_CLASSES constant must appear in
 *     the config's `codes` list.
 *
 * A mismatch throws RuntimeException — the deployment is inconsistent,
 * and silently writing one set while the config declares another would
 * produce a state in which `ShareClassService::codeIsValid()` (which
 * reads config) disagrees with `ShareClassService::findByCode()`
 * (which reads the database). Catching the divergence at seed time is
 * cheaper than discovering it at runtime as an intermittent
 * validation-versus-lookup disagreement.
 *
 * =============================================================================
 * Cache invalidation
 * =============================================================================
 * ShareClassService caches its list under two keys:
 *
 *     'shareholder:share_classes:all'         — the unfiltered list
 *     'shareholder:share_classes:all:active'  — the active-only list
 *
 * Both have a 300-second TTL. If either key was populated before this
 * seeder ran — for example by a health-check endpoint or an
 * administrative request that called ShareClassService::all() while the
 * table was empty — the empty result would continue to be served for
 * up to five minutes after seeding. The seeder forgets both keys as its
 * final step, so the next ShareClassService::all() call reads the
 * freshly-seeded table.
 *
 * The coupling between this seeder and those two cache keys is
 * deliberate and is documented here. If ShareClassService's cache key
 * ever changes, this seeder must change with it. A future CR that
 * centralises the key names into a public constant on the service
 * would remove the duplication; until then, the strings are duplicated
 * with this comment as the audit trail.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * The seeder is not part of `Database\Seeders\DatabaseSeeder`, so it
 * does not run automatically on `php artisan db:seed`. Invoke it
 * explicitly:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareClassSeeder"
 *
 * The expected output is:
 *
 *     Share classes seeded: 2 entries (FOUNDERS, STANDARD).
 *
 * A future CR may add an aggregate domain seeder that chains the
 * permission, role, and share-class seeders. Until then, run the three
 * domain seeders explicitly, in any order — they are independent and
 * idempotent.
 *
 * =============================================================================
 * Native-speaker review
 * =============================================================================
 * The two Amharic labels (መስራች, መደበኛ) are the values recorded in the
 * migration docblock as the intended seed contents. They have not been
 * reviewed by a native speaker as part of this delivery. Their
 * vocabulary is consistent with the Amharic terminology used throughout
 * the Shareholder domain's permission labels (ShareholderPermissionsSeeder
 * v1.1.0), the workspace shell's nav labels (shell-labels.ts v1.2.0),
 * and the portal's page labels (features/portal/labels.ts v2.0.0), but
 * a reviewer may still prefer alternate register choices. Corrections
 * would be a follow-up CR that touches only this file's `name_am`
 * values and the corresponding rows in the database.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders
 * @version 1.0.0
 */
final class ShareClassSeeder extends Seeder
{
    /**
     * The two share classes seeded by this seeder.
     *
     * Each entry carries the code (the natural key), the Amharic
     * display name, and the English display name. The monetary and
     * flag columns are applied uniformly to both rows and are read
     * from config at run time — see run().
     *
     * Ordering is presentation-only; `updateOrCreate` uses `code` as
     * the identity key. The list order matches
     * `config('shareholder.share_classes.codes')`, but the seeder does
     * not depend on that ordering — the config-consistency check
     * compares the SET of codes, not the sequence.
     *
     * @var array<int, array{code: string, name_am: string, name_en: string}>
     */
    private const SHARE_CLASSES = [
        [
            'code'    => 'FOUNDERS',
            'name_am' => 'መስራች',
            'name_en' => 'Founders',
        ],
        [
            'code'    => 'STANDARD',
            'name_am' => 'መደበኛ',
            'name_en' => 'Standard',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     *
     * @throws RuntimeException  When the seeder's codes and the config's
     *                           codes disagree.
     */
    public function run(): void
    {
        $this->assertCodesMatchConfig();

        /*
         * Monetary and flag values, resolved once, applied to both rows.
         *
         * `par_value` is passed to updateOrCreate as a string. The
         * ShareClass model casts the column as `decimal:4`, which
         * yields a string on read; passing a string on write avoids
         * the implicit float coercion that would occur if a PHP
         * numeric literal were used, and it preserves the exact value
         * the config declares.
         *
         * The config key returns a string by design — see the
         * §"Par Value & Currency" section of config/shareholder.php.
         */
        $parValue = (string) config('shareholder.par_value.amount', '10000');
        $currency = (string) config('shareholder.par_value.currency', 'ETB');

        foreach (self::SHARE_CLASSES as $definition) {
            ShareClass::updateOrCreate(
                // Identity key — the natural, unique code.
                ['code' => $definition['code']],
                // Values to set on insert and on update.
                [
                    'name_am'              => $definition['name_am'],
                    'name_en'              => $definition['name_en'],
                    'par_value'            => $parValue,
                    'currency'             => $currency,
                    'is_active'            => true,
                    'is_treasury_eligible' => false,
                ]
            );
        }

        /*
         * Invalidate the two service-level cache keys. See the class
         * docblock §"Cache invalidation" for the reasoning and for the
         * coupling note.
         */
        Cache::forget('shareholder:share_classes:all');
        Cache::forget('shareholder:share_classes:all:active');

        $this->command->info(sprintf(
            'Share classes seeded: %d entries (%s).',
            count(self::SHARE_CLASSES),
            implode(', ', array_column(self::SHARE_CLASSES, 'code')),
        ));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Assert that the codes this seeder writes and the codes the config
     * declares describe the same set.
     *
     * The check is bidirectional: a code in the config but not in the
     * seeder means an operator has declared a class the seeder does not
     * write; a code in the seeder but not in the config means the
     * seeder writes a class the config does not recognise. Both are
     * deployment inconsistencies and both throw.
     *
     * The comparison normalises both sides to uppercase and trims
     * whitespace, matching the ShareClass model's `code` set-mutator
     * so that a config entry of 'founders' (lowercase) and a seeder
     * entry of 'FOUNDERS' are treated as the same code.
     *
     * @return void
     *
     * @throws RuntimeException
     */
    private function assertCodesMatchConfig(): void
    {
        $seededCodes = array_map(
            static fn (array $entry): string => strtoupper(trim($entry['code'])),
            self::SHARE_CLASSES,
        );

        $configCodes = array_map(
            'strtoupper',
            array_map('trim', (array) config('shareholder.share_classes.codes', [])),
        );

        // In config, missing from seeder.
        $missingFromSeeder = array_diff($configCodes, $seededCodes);

        if ($missingFromSeeder !== []) {
            throw new RuntimeException(
                'ShareClassSeeder: the following share-class codes are declared in '
                . 'config(\'shareholder.share_classes.codes\') but are not present '
                . 'in this seeder\'s SHARE_CLASSES constant: '
                . implode(', ', $missingFromSeeder)
                . '. Update the seeder to match the config, or amend the config '
                . 'and this seeder together in a Change Request.'
            );
        }

        // In seeder, missing from config.
        $missingFromConfig = array_diff($seededCodes, $configCodes);

        if ($missingFromConfig !== []) {
            throw new RuntimeException(
                'ShareClassSeeder: the following share-class codes are present in '
                . 'this seeder\'s SHARE_CLASSES constant but are not declared in '
                . 'config(\'shareholder.share_classes.codes\'): '
                . implode(', ', $missingFromConfig)
                . '. Update the config to declare the new code, or remove it from '
                . 'the seeder in a Change Request.'
            );
        }
    }
}