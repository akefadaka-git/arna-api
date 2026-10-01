<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\RoleCatalogue;
use Database\Seeders\Data\TenantProfile;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * RoleSeeder
 *
 * Seeds the platform's twelve roles — the named bundles of permissions
 * the authorization system grants to users. Runs after TenantSeeder,
 * ApplicationSeeder, and PermissionSeeder; before RolePermissionSeeder
 * and the user seeders that follow.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * twelve role rows and hand the resulting map of persisted models
 * back to the caller. It contains no reference data — that lives in
 * Data\RoleCatalogue. It contains no other domain's logic — the
 * tenant, applications, permissions, and users are seeded by sibling
 * classes.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-13). Its behavior is byte-for-byte identical to
 * the private seedRoles() method it replaces.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(Tenant $tenant, array $applications): array
 *     The primary entry point. Performs the twelve upserts and
 *     returns the map of persisted models. Called by the orchestrator
 *     when it needs the map to pass to the seeders that follow.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Resolves the two
 *     arguments from the database and delegates to seed(). See
 *     §"The run() delegate" below for why this one is different from
 *     the earlier seeders' run() methods.
 *
 * =============================================================================
 * The two arguments
 * =============================================================================
 * This is the first seeder to take two arguments, and the reasons are
 * different for each:
 *
 *   $tenant
 *     Ten of the twelve roles are tenant-bound. Their `tenant_id`
 *     column must be set to a specific tenant's id — the tenant the
 *     orchestrator is seeding. The Tenant model is passed in
 *     directly rather than the id, because the value the seeder
 *     writes is `$tenant->id` and the Tenant instance is what the
 *     orchestrator already holds from TenantSeeder's return.
 *
 *     The two non-tenant-bound roles — super_admin and platform_admin
 *     — have `tenant_bound => false` in the catalogue, so their
 *     `tenant_id` column is written as null. The $tenant argument is
 *     not consulted for them.
 *
 *   $applications
 *     Two of the twelve roles are application-scoped. Their
 *     `application_id` column must be set to a specific application's
 *     id, resolved by the slug the role names:
 *
 *         finance_manager → 'finance'
 *         hr_manager      → 'hr'
 *
 *     The applications map — keyed by slug, values are persisted
 *     PlatformApplication models — is exactly the map
 *     ApplicationSeeder::seed() returns. Passing it in means this
 *     seeder does not query the applications table again; it reuses
 *     what the orchestrator already has.
 *
 *     The other ten roles have `application_slug => null` in the
 *     catalogue, so their `application_id` column is written as
 *     null. The $applications argument is not consulted for them.
 *
 * =============================================================================
 * The tenant_id resolution
 * =============================================================================
 * For each role:
 *
 *     $tenantId = $definition['tenant_bound'] ? $tenant->id : null;
 *
 * The condition is on the catalogue's `tenant_bound` field, not on
 * the row's `scope` field. The two would correlate in the current
 * catalogue — every 'platform'-scoped role has tenant_bound = false,
 * and every 'tenant'- or 'application'-scoped role has tenant_bound =
 * true — but the seeder keys on `tenant_bound` because that is what
 * the column's semantics demand. A future CR that adds a role whose
 * scope and bound status diverge extends the catalogue's `scope`
 * vocabulary without changing this line.
 *
 * =============================================================================
 * The application_id resolution
 * =============================================================================
 * For each role:
 *
 *     $applicationId = null;
 *     if ($definition['application_slug'] !== null
 *         && isset($applications[$definition['application_slug']])
 *     ) {
 *         $applicationId = $applications[$definition['application_slug']]->id;
 *     }
 *
 * Two conditions must both hold:
 *
 *   1. The row's `application_slug` is not null. The ten non-
 *      application-scoped roles carry null and skip the lookup
 *      entirely.
 *
 *   2. The slug resolves to an application in the map. A slug that
 *      does not resolve (e.g. 'finance' when no 'finance' application
 *      was seeded) leaves $applicationId as null.
 *
 * A role written with `application_id = null` is not an error — it
 * is what the platform means when the application is not seeded. The
 * subsequent orchestrator-driven run, once the applications exist,
 * fills in the correct id via the upsert's update path. The row is
 * not duplicated; it is corrected.
 *
 * =============================================================================
 * The upsert pattern
 * =============================================================================
 * Each role is upserted via Role::updateOrCreate() with the slug as
 * the lookup key:
 *
 *     Role::updateOrCreate(
 *         ['slug' => $slug],
 *         [ … the eight other columns … ]
 *     )
 *
 * This makes the seeder idempotent. Running it repeatedly against a
 * database that already has the twelve roles updates the existing
 * rows in place rather than inserting duplicates.
 *
 * =============================================================================
 * The run() delegate
 * =============================================================================
 * Unlike TenantSeeder, ApplicationSeeder, and PermissionSeeder, whose
 * run() delegates are either trivial or tolerant of a missing
 * dependency, this seeder's run() must resolve BOTH prerequisites
 * from the database before it can delegate:
 *
 *   · the tenant is looked up by the slug named in TenantProfile::ROW.
 *     This is the same slug TenantSeeder would have written.
 *
 *   · the applications map is built by querying PlatformApplication
 *     for every row and keying the result by slug.
 *
 * If the tenant is not found, run() throws a RuntimeException. This
 * is a deliberate departure from PermissionSeeder's "write what you
 * can" approach. See §"Why a missing tenant throws" below.
 *
 * =============================================================================
 * Why a missing tenant throws (and a missing application does not)
 * =============================================================================
 * The two prerequisites are not equivalent:
 *
 *   · A missing APPLICATION produces a role with `application_id =
 *     null`. That state is self-consistent: the role exists, its
 *     permissions are unaffected, and the null means "not scoped to
 *     a currently-seeded application." It is corrected on the next
 *     orchestrator-driven run. This is the same partial-but-honest
 *     state PermissionSeeder produces, and it is acceptable.
 *
 *   · A missing TENANT produces ten roles with `tenant_id = null`
 *     despite their catalogue rows declaring `tenant_bound = true`.
 *     That state is INCONSISTENT with the catalogue: it asserts
 *     that tenant-bound roles exist without a tenant. Writing it
 *     would require this seeder to either lie about the roles'
 *     bound status or to write null and contradict the catalogue.
 *
 * Both alternatives are worse than throwing. The throw tells the
 * operator exactly what is missing and what to run first.
 *
 * The run() delegate remains useful: on a database where
 * TenantSeeder has already run (or where a 'main' tenant exists from
 * a prior install), the standalone invocation works. It only throws
 * when the invocation is genuinely premature — a state the operator
 * wants to know about, not to work around.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing roles. Clearing is
 *     UserPivotCleaner's job (Unit S-9), and even that class clears
 *     user-adjacent tables, not roles. A role left over from a
 *     previous run is updated in place by this seeder's upsert.
 *
 *   - It does NOT validate the catalogue. A malformed row (missing
 *     'slug', an unrecognized 'scope' value, an `application_slug`
 *     that names no application) produces whatever the model does
 *     with it; the catalogue is not defended against its own editor.
 *
 *   - It does NOT attach permissions to roles. That is
 *     RolePermissionSeeder's job (Unit S-14); it consumes the map
 *     this seeder returns.
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
     *         through the MasesCoreSeeder orchestrator, which passes
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
                . 'the MasesCoreSeeder orchestrator, which passes the '
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