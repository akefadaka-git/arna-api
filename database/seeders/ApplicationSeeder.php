<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\ApplicationRegistry;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * ApplicationSeeder
 *
 * Seeds the platform's seven registered applications — Shareholder
 * Portal, Finance, Human Resources, Administration, ARNA AI
 * Assistant, the MASES Workspace, and the Application Launcher
 * itself. Runs after TenantSeeder and before PermissionSeeder and
 * RoleSeeder, both of which resolve application ids from the map
 * this seeder returns.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery (Unit S-11). Extracted from
 *          MasesCoreSeeder during the seeder refactoring. Wrote the
 *          seven fields the source method wrote: slug, name_am,
 *          name_en, description_am, description_en, domain, and
 *          is_active.
 *
 * 1.1.0  — launcher readiness and presentation (Unit L-10b):
 *          · The seeder now writes four additional columns that
 *            were added to `platform_applications` by the L-9
 *            migration but were not yet populated:
 *
 *              is_frontend_ready  — whether a deployable frontend
 *                                   exists at the domain
 *              icon_key           — the Lucide icon name for the
 *                                   launcher tile's glyph, or null
 *              category           — the launcher grid grouping key,
 *                                   or null
 *              display_order      — the tile's sort weight within
 *                                   its category
 *
 *          · The four values come from Data\ApplicationRegistry
 *            (extended in Unit L-10a). They replace the columns'
 *            migration defaults — `false`, `NULL`, `NULL`, `0`
 *            respectively — which every row carried between L-9 and
 *            this unit.
 *
 *          · No other change. The lookup key, the iteration order,
 *            the return shape, and the two public methods are
 *            unchanged from 1.0.0. Idempotency is preserved: the
 *            upsert's update array now carries eleven fields
 *            instead of seven, and a re-run updates all eleven in
 *            place.
 *
 *          · MINOR version bump because the change adds write
 *            behavior without altering the seeder's public surface.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * seven application rows and hand the resulting map of persisted
 * models back to the caller. It contains no reference data — that
 * lives in Data\ApplicationRegistry. It contains no other domain's
 * logic — the tenant, permissions, roles, and users are seeded by
 * sibling classes.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(): array
 *     The primary entry point. Performs the seven upserts and returns
 *     the map of persisted models. Called by the orchestrator when it
 *     needs the map to pass to the seeders that follow.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Delegates to seed()
 *     and discards the return value.
 *
 * Both methods exist for the same reason as in TenantSeeder (Unit
 * S-10): direct instantiation + seed() is what the orchestrator uses;
 * Laravel's seeder dispatcher invokes run(), and the delegation
 * prevents a silent no-op through the base class's empty run().
 *
 * =============================================================================
 * The return value
 * =============================================================================
 * The return is a map:
 *
 *     [
 *         'portal'   => PlatformApplication,
 *         'finance'  => PlatformApplication,
 *         'hr'       => PlatformApplication,
 *         'admin'    => PlatformApplication,
 *         'arna'     => PlatformApplication,
 *         'app'      => PlatformApplication,
 *         'launcher' => PlatformApplication,
 *     ]
 *
 * Keyed by slug, so a downstream seeder can resolve an application's
 * id without scanning. The permission seeder reads the middle
 * segment of each `app.{slug}.access` slug and looks it up in this
 * map; the role seeder reads each role's `application_slug` field
 * and does the same.
 *
 * The values are the PERSISTED models — not stubs, not row ids.
 * Their `id` properties are the integers the downstream seeders
 * write into foreign-key columns.
 *
 * =============================================================================
 * The upsert pattern
 * =============================================================================
 * Each application is upserted via PlatformApplication::updateOrCreate()
 * with the slug as the lookup key:
 *
 *     PlatformApplication::updateOrCreate(
 *         ['slug' => $slug],
 *         [ … the ten other columns … ]
 *     )
 *
 * The update array carries ten fields after this unit:
 *
 *     name_am, name_en, description_am, description_en,
 *     domain, is_active,
 *     is_frontend_ready, icon_key, category, display_order
 *
 * The 'slug' is not in the update array because it is the lookup
 * key — the model's save() will not touch it.
 *
 * This makes the seeder idempotent: running it repeatedly against a
 * database that already has the seven applications updates the
 * existing rows in place rather than inserting duplicates. The four
 * columns added by L-10b are updated on every re-run, so a schema
 * whose rows still carry the migration's defaults is brought into
 * alignment by a single seeder invocation.
 *
 * =============================================================================
 * The four new columns
 * =============================================================================
 * The four columns this unit begins writing were added by the L-9
 * migration and were unpopulated by the seeder between L-9 and this
 * unit. Their meaning and the values the registry assigns are
 * documented in Data\ApplicationRegistry (Unit L-10a). Summarized:
 *
 *   is_frontend_ready — true for 'portal' and 'app' (the two builds
 *                       present in the current frontend repository);
 *                       false for the other five. The launcher
 *                       endpoint (Unit L-11) filters on this flag
 *                       combined with is_active.
 *
 *   icon_key          — the Lucide icon name for the tile's glyph,
 *                       or null to fall back to the tile's default.
 *                       Two rows carry null: 'admin' and 'arna'.
 *
 *   category          — the grid's grouping key. Five categories
 *                       appear across the seven rows:
 *                       shareholder, financial, operations,
 *                       platform, intelligence.
 *
 *   display_order     — the tile's sort weight within its category.
 *                       Values 10–70 in steps of 10, matching the
 *                       current seed order.
 *
 * When a downstream column is dropped from the registry (a future
 * CR that removes it), the seeder continues to write null for the
 * column — the registry row's field is undefined, and the seeder's
 * update array carries `$definition[...]` which resolves to null for
 * a missing key. This matches the source's tolerance of an
 * incomplete row and is not a defect: the model's `fillable`
 * whitelist and the schema's column nullability are the enforcement.
 *
 * =============================================================================
 * Why the iteration key is the outer array key, not $definition['slug']
 * =============================================================================
 * Each row in Data\ApplicationRegistry carries a 'slug' field that
 * duplicates its outer key. The lookup below uses the OUTER key.
 * This is deliberate and matches the source method: the outer key is
 * the map's canonical address, and the inner field is a copy kept
 * for readers who want to see each row whole. If the two ever
 * disagree — a maintainer edited one but not the other — the seeder
 * uses the outer key, the database enforces uniqueness on the inner
 * 'slug' column, and the mismatch surfaces as an integrity error on
 * the next run. That is the correct failure mode.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing applications. Clearing is
 *     UserPivotCleaner's job (Unit S-9), and even that class clears
 *     user-adjacent tables, not applications. An application left
 *     over from a previous run is updated in place by this seeder's
 *     upsert, not deleted and recreated.
 *
 *   - It does NOT validate the registry. A malformed row (missing
 *     'slug', a duplicated 'domain', an icon_key that is not a
 *     string) produces whatever the model does with it; the
 *     registry is not defended against its own editor. The
 *     database's UNIQUE constraints on slug and domain are the
 *     enforcement.
 *
 *   - It does NOT guard the four new columns with Schema::hasColumn.
 *     See the class docblock §"The four new columns" for the
 *     reasoning: the columns are part of the schema once L-9 has
 *     been merged, and the other seven columns carry no guards
 *     either. A future CR that reintroduces schema-branch
 *     portability for these columns adds the guards then.
 *
 *   - It does NOT log per-row progress. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT swallow exceptions. If an upsert fails — a UNIQUE
 *     violation, a missing table, a database connection error, or a
 *     value the column cannot represent — the exception propagates
 *     to the orchestrator, which is where a seeding run should fail
 *     loudly.
 *
 * @package Database\Seeders
 */
final class ApplicationSeeder extends Seeder
{
    /**
     * Upsert the seven applications and return the map of persisted
     * models, keyed by slug.
     *
     * Idempotent: running repeatedly against a database that already
     * has the seven applications updates the existing rows in place.
     * See the class docblock §"The upsert pattern".
     *
     * @return array<string, PlatformApplication> the persisted
     *         application models, keyed by slug, ready to be passed
     *         to the seeders that follow
     */
    public function seed(): array
    {
        /*
         * Read the registry from the Data\ layer. See Unit S-3 for
         * the original row shape and Unit L-10a for the four
         * additional fields this seeder now writes.
         *
         * The registry is a map keyed by slug; each value is a row
         * carrying the same slug under its own 'slug' key (see the
         * class docblock §"Why the iteration key is the outer array
         * key").
         */
        $registry = ApplicationRegistry::ROWS;

        $result = [];

        foreach ($registry as $slug => $definition) {
            /*
             * Upsert by slug.
             *
             * The lookup key is the OUTER key of the registry map.
             * See the class docblock §"Why the iteration key is the
             * outer array key" for the reasoning.
             *
             * The update array carries ten fields: the six the
             * original 1.0.0 seeder wrote (name_am, name_en,
             * description_am, description_en, domain, is_active),
             * plus the four this unit adds (is_frontend_ready,
             * icon_key, category, display_order). The slug is the
             * lookup key and is not in this array.
             */
            $result[$slug] = PlatformApplication::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am'           => $definition['name_am'],
                    'name_en'           => $definition['name_en'],
                    'description_am'    => $definition['description_am'],
                    'description_en'    => $definition['description_en'],
                    'domain'            => $definition['domain'],
                    'is_active'         => $definition['is_active'],
                    'is_frontend_ready' => $definition['is_frontend_ready'],
                    'icon_key'          => $definition['icon_key'],
                    'category'          => $definition['category'],
                    'display_order'     => $definition['display_order'],
                ]
            );
        }

        return $result;
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Delegates to seed() and discards the return value. Exists so
     * that Laravel's seeder dispatcher (`php artisan db:seed
     * --class=…`) can invoke this class without silently no-op'ing
     * through the base class's empty run(). See the class docblock
     * §"The two-method surface".
     *
     * The orchestrator uses seed() directly — it needs the returned
     * map, which run() does not provide.
     */
    public function run(): void
    {
        $this->seed();
    }
}