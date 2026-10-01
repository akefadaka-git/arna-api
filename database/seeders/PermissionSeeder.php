<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\PermissionCatalogue;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * PermissionSeeder
 *
 * Seeds the platform's thirty-seven permissions — the atomic
 * capabilities the authorization system checks. Runs after
 * ApplicationSeeder and before RoleSeeder.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * 37 permission rows and hand the resulting map of persisted models
 * back to the caller. It contains no reference data — that lives in
 * Data\PermissionCatalogue. It contains no other domain's logic — the
 * tenant, applications, roles, and users are seeded by sibling
 * classes.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-12). Its behavior is byte-for-byte identical to
 * the private seedPermissions() method it replaces.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(array $applications): array
 *     The primary entry point. Performs the 37 upserts and returns
 *     the map of persisted models. Called by the orchestrator when it
 *     needs the map to pass to the seeders that follow.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Delegates to seed()
 *     with an empty applications map.
 *
 * Both methods exist for the same reason as in the earlier seeders:
 * direct instantiation + seed() is what the orchestrator uses;
 * Laravel's seeder dispatcher invokes run(), and the delegation
 * prevents a silent no-op through the base class's empty run().
 *
 * The run() delegation passes an EMPTY array to seed(). See
 * §"The run() delegate" below for what that means and why it is the
 * right behavior.
 *
 * =============================================================================
 * The applications argument
 * =============================================================================
 * This is the first seeder that takes an argument. The reason is that
 * seven of the 37 permissions are application-scoped:
 *
 *     app.portal.access    — portal
 *     app.finance.access   — finance
 *     app.hr.access        — hr
 *     app.admin.access     — admin
 *     app.arna.access      — arna
 *     app.app.access       — app
 *     app.launcher.access  — launcher
 *
 * Each of these must be written with its `application_id` foreign key
 * set to the persisted application's id. That id is not known to this
 * seeder in isolation; it is the id ApplicationSeeder's updateOrCreate
 * returned. So the caller passes the applications map, keyed by slug:
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
 * The middle segment of each `app.{slug}.access` slug is the lookup
 * key into this map. The lookup is only attempted when the row's
 * scope is 'application' AND its slug begins with 'app.' — no other
 * permission's slug is parsed for an application segment.
 *
 * =============================================================================
 * The application-id resolution
 * =============================================================================
 * For each application-scoped permission:
 *
 *   1. Split the slug on '.'. The middle segment (index 1) is the
 *      application slug.
 *   2. Look that slug up in the applications map.
 *   3. If found, take the persisted model's id.
 *
 * If the middle segment is missing (e.g. a hypothetical malformed
 * slug 'app..access') or does not resolve to an application (e.g.
 * 'app.unknown.access' when no such application was seeded), the
 * permission is written with `application_id = null` rather than
 * raising an error.
 *
 * This mirrors the source method's behavior exactly. The choice is
 * deliberate: a missing application is a data defect, not a crash
 * condition, and the permission should still be written so that the
 * defect is visible in the database rather than aborting the run.
 *
 * =============================================================================
 * The two intentional equalities
 * =============================================================================
 * Two field assignments in this seeder look redundant but are not;
 * they match the source exactly:
 *
 *   · description_am = name_am
 *   · description_en = name_en
 *
 * The current PermissionCatalogue rows carry only name_am and
 * name_en. The model has description columns (they exist and are
 * writable) but the catalogue does not yet distinguish descriptions
 * from names. Writing the name into both fields keeps the
 * description columns populated with a sensible value until a future
 * CR adds distinct description text to the catalogue.
 *
 * When that CR lands, this seeder's update array will read
 * $definition['description_am'] and $definition['description_en']
 * from the catalogue rows rather than from the name fields. The
 * change is small and localized.
 *
 * =============================================================================
 * The always-null tenant_id
 * =============================================================================
 * Every permission is written with `tenant_id = null`. This matches
 * the source method and reflects the current schema's intent: a
 * permission is either platform-wide (scope 'global'), tenant-scoped
 * (scope 'tenant'), or application-scoped (scope 'application'). The
 * `tenant_id` column exists for a future CR that allows per-tenant
 * permission overrides; the current catalogue has no such overrides,
 * so every row leaves the column null.
 *
 * =============================================================================
 * The upsert pattern
 * =============================================================================
 * Each permission is upserted via Permission::updateOrCreate() with
 * the slug as the lookup key:
 *
 *     Permission::updateOrCreate(
 *         ['slug' => $slug],
 *         [ … the nine other columns … ]
 *     )
 *
 * This makes the seeder idempotent. Running it repeatedly against a
 * database that already has the 37 permissions updates the existing
 * rows in place rather than inserting duplicates.
 *
 * =============================================================================
 * The run() delegate
 * =============================================================================
 * The Seeder base class's run() method takes no arguments. This
 * seeder's seed() method requires one. The run() delegate therefore
 * calls seed([]) — an empty applications map.
 *
 * With an empty map, every application-scoped permission's lookup
 * fails, and those seven permissions are written with
 * `application_id = null`. That is the correct behavior for a
 * standalone invocation (`php artisan db:seed
 * --class=PermissionSeeder`): the seeder writes what it can without
 * the applications map, and the seven application-scoped rows point
 * at nothing.
 *
 * A subsequent run through the orchestrator (which passes the real
 * map) will fill in the correct application_id values. The upsert
 * semantics mean the second run corrects the first run's nulls
 * rather than duplicating rows.
 *
 * This is preferable to throwing from run(). A standalone seeder
 * invocation that produces a partial but self-consistent state is
 * more useful than one that refuses to run at all.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing permissions. Clearing is
 *     UserPivotCleaner's job (Unit S-9), and even that class clears
 *     user-adjacent tables, not permissions. A permission left over
 *     from a previous run is updated in place by this seeder's
 *     upsert, not deleted and recreated.
 *
 *   - It does NOT validate the catalogue. A malformed row (missing
 *     'slug', an unrecognized 'scope' value) produces whatever the
 *     model does with it; the catalogue is not defended against its
 *     own editor. The database's UNIQUE constraint on slug is the
 *     enforcement.
 *
 *   - It does NOT re-resolve the applications map. The caller passes
 *     it in; this seeder does not query PlatformApplication. That
 *     would couple the seeder to the model instead of to the map,
 *     and it would re-query seven rows the orchestrator already has.
 *
 *   - It does NOT log per-row progress. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT swallow exceptions. If an upsert fails — a UNIQUE
 *     violation, a missing table, a database connection error — the
 *     exception propagates to the orchestrator, which is where a
 *     seeding run should fail loudly.
 *
 * @package Database\Seeders
 */
final class PermissionSeeder extends Seeder
{
    /**
     * Upsert the 37 permissions and return the map of persisted
     * models, keyed by slug.
     *
     * Idempotent: running repeatedly against a database that already
     * has the 37 permissions updates the existing rows in place.
     *
     * @param array<string, PlatformApplication> $applications
     *        the persisted application models, keyed by slug, as
     *        returned by ApplicationSeeder::seed(). Used only to
     *        resolve the seven application-scoped permissions'
     *        application_id foreign keys.
     *
     * @return array<string, Permission> the persisted permission
     *         models, keyed by slug, ready to be passed to the
     *         RolePermissionSeeder
     */
    public function seed(array $applications): array
    {
        /*
         * Read the catalogue from the Data\ layer. See Unit S-4 for
         * the row shape. The catalogue is a LIST (integer-indexed),
         * not a map — each row carries its slug under its own
         * 'slug' key. The key of the returned map is derived from
         * that field.
         */
        $catalogue = PermissionCatalogue::ROWS;

        $result = [];

        foreach ($catalogue as $definition) {
            $slug = $definition['slug'];

            /*
             * Resolve the application_id for application-scoped
             * permissions.
             *
             * Two conditions must both hold:
             *
             *   1. The row's 'scope' field is exactly 'application'.
             *      Permissions with scope 'global' or 'tenant' are
             *      not application-bound and their application_id
             *      remains null.
             *
             *   2. The slug begins with 'app.'. This is a second
             *      guard on the same set of rows: in the current
             *      catalogue, every 'application'-scoped permission
             *      happens to have an 'app.'-prefixed slug, and
             *      no non-application-scoped permission does. The
             *      two conditions could be collapsed into one, but
             *      keeping both expresses the seeder's intent more
             *      explicitly: "these are the app-access
             *      permissions."
             *
             * The middle segment of the slug — the string between
             * the two dots — is the application's slug, used as the
             * lookup key into the applications map.
             *
             * A missing middle segment (malformed slug) or an
             * unknown application slug (application not seeded)
             * leaves $applicationId as null. See the class
             * docblock §"The application-id resolution".
             */
            $applicationId = null;
            if ($definition['scope'] === 'application'
                && str_starts_with($slug, 'app.')
            ) {
                $appSlug = explode('.', $slug)[1] ?? null;
                if ($appSlug !== null && isset($applications[$appSlug])) {
                    $applicationId = $applications[$appSlug]->id;
                }
            }

            /*
             * Upsert by slug.
             *
             * The nine update values are every field in the
             * catalogue row EXCEPT the slug (the lookup key), plus
             * the two derived values: the application_id resolved
             * above, and the always-null tenant_id.
             *
             * Note the two intentional equalities —
             * description_am = name_am and description_en =
             * name_en. See the class docblock §"The two intentional
             * equalities".
             */
            $result[$slug] = Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am'        => $definition['name_am'],
                    'name_en'        => $definition['name_en'],
                    'description_am' => $definition['name_am'],
                    'description_en' => $definition['name_en'],
                    'category'       => $definition['category'],
                    'scope'          => $definition['scope'],
                    'application_id' => $applicationId,
                    'tenant_id'      => null,
                    'is_active'      => true,
                    'is_system'      => $definition['is_system'],
                ]
            );
        }

        return $result;
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Delegates to seed() with an empty applications map. See the
     * class docblock §"The run() delegate" for what that means — the
     * seven application-scoped permissions are written with a null
     * application_id, which a subsequent orchestrator-driven run
     * will correct.
     *
     * Exists so that Laravel's seeder dispatcher (`php artisan
     * db:seed --class=…`) can invoke this class without silently
     * no-op'ing through the base class's empty run().
     *
     * The orchestrator uses seed() directly — it needs the returned
     * map and it has the applications map to pass in.
     */
    public function run(): void
    {
        $this->seed([]);
    }
}