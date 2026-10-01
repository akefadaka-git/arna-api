<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\TenantProfile;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * TenantSeeder
 *
 * Seeds the default MASES tenant — the single row that anchors every
 * other reference-data seeder. Every user, role, application, and
 * shareholder the platform seeds is scoped to the tenant this seeder
 * writes, so this is the first domain seeder the orchestrator calls.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * tenant row and hand the persisted Tenant model back to the caller.
 * It contains no reference data — that lives in Data\TenantProfile.
 * It contains no other domain's logic — the applications,
 * permissions, roles, and users are seeded by sibling classes.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-10). Its behavior is byte-for-byte identical to
 * the private seedTenant() method it replaces.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(): Tenant
 *     The primary entry point. Performs the upsert and returns the
 *     persisted model. Called by the orchestrator when it needs the
 *     Tenant instance to pass to the seeders that follow.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Delegates to seed()
 *     and discards the return value.
 *
 * Both methods exist so that the class behaves correctly under two
 * invocation styles:
 *
 *   · Direct instantiation + seed() — what the orchestrator uses,
 *     because it needs the returned Tenant.
 *   · Laravel's seeder dispatcher — `php artisan db:seed
 *     --class="Database\Seeders\TenantSeeder"` — which invokes
 *     run(). Without run(), this second style would call the base
 *     class's empty run() and silently do nothing. The delegation
 *     prevents that footgun at the cost of three lines.
 *
 * =============================================================================
 * The upsert pattern
 * =============================================================================
 * The tenant is upserted via Tenant::updateOrCreate() with the slug
 * as the lookup key:
 *
 *     Tenant::updateOrCreate(
 *         ['slug' => $row['slug']],
 *         [ … the other five columns … ]
 *     )
 *
 * This makes the seeder idempotent: running it repeatedly against a
 * database that already has the 'main' tenant updates the existing
 * row in place rather than inserting a duplicate. The slug is not
 * included in the update array because it is the lookup key — the
 * model's save() will not touch it.
 *
 * A fresh database (no 'main' tenant) inserts. A database that has
 * the tenant updates. A database whose tenant has a different slug
 * (e.g. a staging environment with 'staging') would insert a second
 * tenant — this is by design; the seeder seeds the profile it is
 * given, not the profile it finds.
 *
 * =============================================================================
 * The settings column
 * =============================================================================
 * The tenant's 'settings' value is a plain PHP array — the
 * ['timezone' => …, 'locale' => …] map from Data\TenantProfile. The
 * Tenant model's $casts array is expected to cast this to JSON on
 * write and back to an array on read. The seeder passes the array
 * through unmodified; serialisation is the model's concern.
 *
 * If the model does not cast 'settings', the array will be written
 * verbatim by the query builder and read back as a JSON string. That
 * is a model defect, not a seeder defect; the seeder's job is to
 * hand the array to the model and let the model decide its storage
 * form.
 *
 * =============================================================================
 * Why the return type is the Eloquent model, not the row id
 * =============================================================================
 * The orchestrator needs to pass the Tenant instance (not just its
 * id) to the seeders that follow: ApplicationSeeder resolves
 * application-scoped permission grants against it, RoleSeeder writes
 * its id into the roles' tenant_id column, AdminUserSeeder and
 * ShareholderSeeder attach users to it. Returning the model gives
 * those seeders a typed object to work with and avoids a redundant
 * re-query.
 *
 * The id is accessible as $tenant->id for the seeders that need the
 * raw integer.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing tenants. Clearing is
 *     UserPivotCleaner's job (Unit S-9), and even that class clears
 *     user-adjacent tables, not tenants. A tenant left over from a
 *     previous run is updated in place by this seeder's upsert, not
 *     deleted and recreated.
 *
 *   - It does NOT validate the profile. A malformed row (missing
 *     'slug', an unparseable 'settings' value) produces whatever the
 *     model does with it; the profile is not defended against its
 *     own editor.
 *
 *   - It does NOT read config/mases.php. The tenant's identity is
 *     the Data\TenantProfile constant, not the config file's
 *     'applications' array (which is the launcher's bootstrap
 *     registry, not the tenant's). The two are independent.
 *
 *   - It does NOT write anything to stdout. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT handle the case where the Tenant model is
 *     unavailable. If the model class cannot be resolved (a misnamed
 *     namespace, a missing migration), the class fails loudly on the
 *     first line of the upsert — which is the correct outcome for a
 *     seeder whose dependency is absent.
 *
 * @package Database\Seeders
 */
final class TenantSeeder extends Seeder
{
    /**
     * Upsert the default tenant and return the persisted model.
     *
     * Idempotent: running repeatedly against a database that already
     * has the 'main' tenant updates the existing row in place. See
     * the class docblock §"The upsert pattern".
     *
     * @return Tenant the persisted tenant model, ready to be passed
     *                to the seeders that follow
     */
    public function seed(): Tenant
    {
        /*
         * Read the profile from the Data\ layer. See Unit S-2 for the
         * row's shape and the meaning of each column.
         */
        $row = TenantProfile::ROW;

        /*
         * Upsert by slug.
         *
         * The lookup key is the slug. The second argument carries the
         * five columns that may change on a re-run: the two display
         * names, the domain, the settings map, and the active flag.
         *
         * The slug itself is not in the update array — it is the
         * lookup key, and Tenant::updateOrCreate() will not touch it.
         */
        return Tenant::updateOrCreate(
            ['slug' => $row['slug']],
            [
                'name_am'   => $row['name_am'],
                'name_en'   => $row['name_en'],
                'domain'    => $row['domain'],
                'settings'  => $row['settings'],
                'is_active' => $row['is_active'],
            ]
        );
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
     * Tenant model, which run() does not provide.
     */
    public function run(): void
    {
        $this->seed();
    }
}