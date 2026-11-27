<?php

declare(strict_types=1);

/* Registers the Governance application in the platform launcher registry. */

namespace MaaleArna\Arna\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\PlatformApplication;

/**
 * GovernanceApplicationSeeder
 *
 * Registers the Governance domain as a first-class ARNA
 * application in the `platform_applications` table.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery (G1 file 9 of 10). One idempotent
 *          `updateOrCreate` writing the `governance` row. Domain is
 *          path-based (`app.maalearna.com.et/governance`); icon is
 *          `landmark`, verified against BaseIcon.vue's frozen
 *          registry; `is_frontend_ready` is `false` until the
 *          Governance UI deploys.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * One row in `platform_applications`:
 *
 *     slug              governance
 *     name_am           የአስተዳደር እና የአመራር ሥርዓት
 *     name_en           Governance
 *     description_am    የቦርድ፣ የኮሚቴዎች እና የውሳኔዎች ሥርዓት
 *     description_en    Board, committees, and governance decisions.
 *     icon_key          landmark
 *     category          platform
 *     display_order     65
 *     domain            app.maalearna.com.et/governance
 *     is_active         true
 *     is_frontend_ready false
 *
 * See the file-level change-verification block for the reasoning
 * behind each value.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * The write goes through `updateOrCreate(['slug' => 'governance'], [...])`.
 * Running the seeder repeatedly is safe:
 *
 *   · The row is inserted on the first run.
 *   · On subsequent runs, the same row is updated to the same
 *     values. The row's `id` does not change; foreign keys that
 *     reference it (in particular `permissions.application_id` on
 *     the `app.governance.access` permission) remain valid.
 *   · No other row is touched.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * Invoke explicitly, as the FIRST of the three Governance seeders:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\Arna\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 * Expected output:
 *
 *     Governance application registered: slug=governance, id=<N>, frontend_ready=false.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Database\Seeders
 * @version 1.0.0
 */
final class GovernanceApplicationSeeder extends Seeder
{
    /**
     * The single application row this seeder manages.
     *
     * All fields are declared here so that a reviewer sees the row's
     * full shape in one place. The `slug` is the identity key used
     * by `updateOrCreate`.
     *
     * @var array{
     *     slug: string,
     *     name_am: string,
     *     name_en: string,
     *     description_am: string,
     *     description_en: string,
     *     icon_key: string,
     *     category: string,
     *     display_order: int,
     *     domain: string,
     *     is_active: bool,
     *     is_frontend_ready: bool,
     * }
     */
    private const APPLICATION = [
        'slug'           => 'governance',

        // Amharic name — the master prompt §5's canonical rendering.
        'name_am'        => 'የአስተዳደር እና የአመራር ሥርዓት',
        'name_en'        => 'Governance',

        // Description — a short noun phrase matching the register of
        // the sibling rows' descriptions.
        'description_am' => 'የቦርድ፣ የኮሚቴዎች እና የውሳኔዎች ሥርዓት',
        'description_en' => 'Board, committees, and governance decisions.',

        // Icon — verified against BaseIcon.vue's frozen 56-icon
        // registry. `landmark` is the classical civic-building glyph
        // and is not currently consumed by any other launcher tile or
        // nav leaf.
        'icon_key'       => 'landmark',

        // Category and order — Governance groups with the platform-tier
        // tiles (`app`, `launcher`), placed between them.
        'category'       => 'platform',
        'display_order'  => 65,

        // Domain — path-based, matching the `launcher` row precedent.
        // See the file-level change-verification block for the full
        // rationale.
        'domain'         => 'app.maalearna.com.et/governance',

        // Lifecycle flags. `is_active` is true: the domain is
        // registered at the platform level. `is_frontend_ready` is
        // false: the Governance UI is not yet deployed; the launcher
        // must not present a clickable tile until it is.
        'is_active'         => true,
        'is_frontend_ready' => false,
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $application = PlatformApplication::updateOrCreate(
            // Identity key.
            ['slug' => self::APPLICATION['slug']],
            // Values to set on insert and on update.
            [
                'name_am'           => self::APPLICATION['name_am'],
                'name_en'           => self::APPLICATION['name_en'],
                'description_am'    => self::APPLICATION['description_am'],
                'description_en'    => self::APPLICATION['description_en'],
                'icon_key'          => self::APPLICATION['icon_key'],
                'category'          => self::APPLICATION['category'],
                'display_order'     => self::APPLICATION['display_order'],
                'domain'            => self::APPLICATION['domain'],
                'is_active'         => self::APPLICATION['is_active'],
                'is_frontend_ready' => self::APPLICATION['is_frontend_ready'],
            ]
        );

        $this->command->info(sprintf(
            'Governance application registered: slug=%s, id=%d, frontend_ready=%s.',
            $application->slug,
            $application->id,
            $application->is_frontend_ready ? 'true' : 'false',
        ));
    }
}