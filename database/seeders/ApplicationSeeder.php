<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\ApplicationRegistry;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * Seeds the registered platform applications and returns their model map.
 * Runs before permission and role seeders that reference application IDs.
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