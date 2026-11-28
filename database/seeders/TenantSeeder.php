<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\TenantProfile;
use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

/**
 * Creates or updates the default tenant.
 * Runs first because subsequent reference-data seeders depend on it.
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

        // MySQL normalizes JSON object key order when persisting settings.
        // Canonicalize the catalogue array before comparing it with the
        // model's decoded JSON cast, otherwise isDirty() reports a false
        // change on every rerun and updates the tenant timestamp repeatedly.
        $settings = $row['settings'];
        ksort($settings);

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
        $tenant = Tenant::query()->firstOrNew(['slug' => $row['slug']]);
        $tenant->fill([
            'name_am' => $row['name_am'],
            'name_en' => $row['name_en'],
            'domain' => $row['domain'],
            'settings' => $settings,
            'is_active' => $row['is_active'],
        ]);

        // Avoid timestamp and model-event writes on a true no-op rerun.
        if ($tenant->isDirty()) {
            $tenant->save();
        }

        return $tenant;
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