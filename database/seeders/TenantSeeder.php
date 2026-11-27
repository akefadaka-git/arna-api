<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\TenantProfile;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * Creates or updates the default tenant.
 * Runs first because subsequent reference-data seeders depend on it.
 */
final class TenantSeeder extends Seeder
{
    /**
     * Upsert the default tenant and return the persisted model.
     *
     * Existing rows are saved only when canonical profile attributes differ.
     * A true no-op rerun must not refresh updated_at or fire model update events.
     *
     * @return Tenant the persisted tenant model, ready to be passed
     *                to the seeders that follow
     */
    public function seed(): Tenant
    {
        $row = TenantProfile::ROW;

        $tenant = Tenant::query()->firstOrNew(['slug' => $row['slug']]);
        $tenant->fill([
            'name_am' => $row['name_am'],
            'name_en' => $row['name_en'],
            'domain' => $row['domain'],
            'settings' => $row['settings'],
            'is_active' => $row['is_active'],
        ]);

        // Eloquent save() updates timestamps even when the supplied values
        // are unchanged. Avoid that write so baseline reruns are truly stable.
        if ($tenant->isDirty()) {
            $tenant->save();
        }

        return $tenant;
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Delegates to seed() and discards the return value. Exists so
     * that Laravel's seeder dispatcher (php artisan db:seed --class=…)
     * can invoke this class without silently no-op'ing through the base
     * class's empty run().
     */
    public function run(): void
    {
        $this->seed();
    }
}
