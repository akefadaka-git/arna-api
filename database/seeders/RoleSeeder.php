<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\RoleCatalogue;
use Database\Seeders\Data\TenantProfile;
use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\PlatformApplication;
use MaaleArna\Arna\Core\Authorization\Models\Role;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * Seeds the platform RBAC roles.
 * Runs after tenant, application and permission seeders, before role grants
 * and user seeders.
 */
final class RoleSeeder extends Seeder
{
    /**
     * Upsert the twelve roles and return the map of persisted models,
     * keyed by slug.
     *
     * Idempotent: running repeatedly against a database that already
     * has the twelve roles updates the existing rows in place.
     *
     * @param Tenant $tenant
     *        the tenant the roles are scoped to. Its id is written
     *        into the `tenant_id` column for every role whose
     *        catalogue row declares `tenant_bound = true`. The two
     *        platform-scoped roles skip this assignment and keep
     *        `tenant_id = null`.
     *
     * @param array<string, PlatformApplication> $applications
     *        the persisted application models, keyed by slug, as
     *        returned by ApplicationSeeder::seed(). Used only to
     *        resolve the two application-scoped roles'
     *        application_id foreign keys.
     *
     * @return array<string, Role> the persisted role models, keyed by
     *         slug, ready to be passed to the RolePermissionSeeder
     *         and to the user seeders.
     */
    public function seed(Tenant $tenant, array $applications): array
    {
        /*
         * Read the catalogue from the Data\ layer. See Unit S-5 for
         * the row shape. The catalogue is a MAP keyed by slug; each
         * value is a row carrying the same slug under its own 'slug'
         * key.
         */
        $catalogue = RoleCatalogue::ROWS;

        $result = [];

        foreach ($catalogue as $slug => $definition) {
            /*
             * Resolve the tenant_id. See the class docblock
             * §"The tenant_id resolution".
             *
             * The condition is on the catalogue's 'tenant_bound'
             * flag, not on the role's 'scope' field. The two would
             * correlate in the current catalogue, but the semantics
             * that matter for the column are the bound flag's.
             */
            $tenantId = $definition['tenant_bound'] ? $tenant->id : null;

            /*
             * Resolve the application_id. See the class docblock
             * §"The application_id resolution".
             *
             * Two conditions must both hold: the row names an
             * application slug, and that slug resolves to an
             * application in the map. Either condition failing
             * leaves $applicationId as null.
             */
            $applicationId = null;
            if ($definition['application_slug'] !== null
                && isset($applications[$definition['application_slug']])
            ) {
                $applicationId = $applications[$definition['application_slug']]->id;
            }

            /*
             * Upsert by slug.
             *
             * The eight update values are every field in the
             * catalogue row EXCEPT the three that are not columns
             * on the roles table — 'tenant_bound' is a data
             * directive, not a column, and the two derived values
             * (tenantId, applicationId) are computed above. The
             * 'slug' is the lookup key and is not in the update
             * array.
             */
            $result[$slug] = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am'        => $definition['name_am'],
                    'name_en'        => $definition['name_en'],
                    'description_am' => $definition['description_am'],
                    'description_en' => $definition['description_en'],
                    'scope'          => $definition['scope'],
                    'tenant_id'      => $tenantId,
                    'application_id' => $applicationId,
                    'is_active'      => true,
                ]
            );
        }

        return $result;
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Resolves both prerequisites from the database and delegates to
     * seed(). Throws a RuntimeException if the tenant is missing —
     * see the class docblock §"Why a missing tenant throws (and a
     * missing application does not)".
     *
     * Exists so that Laravel's seeder dispatcher (`php artisan
     * db:seed --class=…`) can invoke this class without silently
     * no-op'ing through the base class's empty run().
     *
     * The orchestrator uses seed() directly — it holds both the
     * Tenant model and the applications map from earlier steps.
     *
     * @throws RuntimeException when the default tenant has not been
     *         seeded. Run TenantSeeder first, or invoke the seeder
     *         through the ArnaCoreSeeder orchestrator, which passes
     *         the tenant explicitly.
     */
    public function run(): void
    {
        /*
         * Resolve the tenant by the slug named in TenantProfile::ROW.
         * This is the same slug TenantSeeder writes; if the tenant
         * exists, this lookup finds it.
         *
         * Use `first()` rather than `firstOrFail()` so that a missing
         * tenant produces the seeder's own clear error message
         * rather than the framework's generic model-not-found
         * exception.
         */
        $tenant = Tenant::query()
            ->where('slug', TenantProfile::ROW['slug'])
            ->first();

        if ($tenant === null) {
            throw new RuntimeException(
                'RoleSeeder requires the default tenant to exist before '
                . 'it can seed tenant-bound roles. '
                . 'Run TenantSeeder first, or invoke the seeder through '
                . 'the ArnaCoreSeeder orchestrator, which passes the '
                . 'tenant explicitly.'
            );
        }

        /*
         * Build the applications map by querying every application
         * row and keying the result by slug. This is the same shape
         * ApplicationSeeder::seed() returns; the query is a fallback
         * for the standalone path.
         *
         * If no applications exist, the resulting map is empty and
         * the two application-scoped roles are written with
         * application_id = null. That is the acceptable partial
         * state documented in the class docblock.
         */
        $applications = PlatformApplication::query()
            ->get()
            ->keyBy('slug')
            ->all();

        $this->seed($tenant, $applications);
    }
}