<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\PermissionCatalogue;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * Seeds platform permissions, including application-scoped access grants.
 * Runs after applications and before roles and role-permission assignments.
 */
final class PermissionSeeder extends Seeder
{
    /**
     * Upsert the platform permission catalogue and return the map of persisted
     * models, keyed by slug.
     *
     * Idempotent: running repeatedly against a database that already
     * has the 60 permissions updates the existing rows in place.
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