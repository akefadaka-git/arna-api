<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G1 · File 9 of 10
 * =============================================================================
 *
 * File:            Database/Seeders/GovernanceApplicationSeeder.php  (NEW)
 * Unit:            G1 — Governance domain foundation (launcher registry)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `platform_applications` table held
 *   seven rows (IDs 1–7): `portal`, `finance`, `hr`, `admin`,
 *   `arna`, `app`, `launcher`. No `governance` row existed. As a
 *   consequence:
 *
 *     · The GovernancePermissionsSeeder (G1 file 7 of 10) could
 *       not create its application-scoped `app.governance.access`
 *       permission — that permission references
 *       `platform_applications.id` via a foreign key, and no id
 *       was available.
 *
 *     · The launcher had no tile for the Governance domain, even
 *       in its hidden (not-frontend-ready) state. A future
 *       deployment of the frontend could not flip the tile to
 *       visible without a corresponding registry row.
 *
 * After:
 *   One file that registers the Governance domain in the platform
 *   launcher registry. The seed is idempotent: a repeated run
 *   updates the row in place rather than inserting a duplicate.
 *
 *   What the seeder writes — one row in `platform_applications`:
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
 *   What the seeder deliberately does NOT do:
 *
 *     · It does NOT create any other launcher row. The seven
 *       existing rows are untouched.
 *
 *     · It does NOT set `is_frontend_ready = true`. That flag is
 *       flipped by a separate CR when the Governance UI actually
 *       deploys. Until then, the launcher must not present a
 *       clickable tile that leads to a 404.
 *
 *     · It does NOT modify the frontend router, the shell nav
 *       tree, or any `.env`. The registry row is data; the
 *       frontend consumes it through the launcher service.
 *
 * =============================================================================
 * The domain value — path-based, matching the launcher precedent
 * =============================================================================
 * The `domain` column is set to `app.maalearna.com.et/governance`
 * rather than `governance.maalearna.com.et`.
 *
 * The choice matches:
 *
 *   · The G1 approval decision — Governance is a Workspace module
 *     at `app.maalearna.com.et/governance`, not a separately
 *     deployed frontend.
 *
 *   · The existing `launcher` row precedent. Row id 7 carries
 *     `domain = 'app.maalearna.com.et/launcher'` — a path-based
 *     value, not a subdomain. `governance` follows the same shape.
 *
 *   · The absence of a `governance` entry in
 *     `RequestTenantResolver::DEFAULT_RESERVED_DOMAINS`. If the
 *     domain were set to `governance.maalearna.com.et`, an HTTP
 *     request to that host would resolve `governance` as a tenant
 *     slug rather than as an application host, producing a 404.
 *     The path-based domain sidesteps the issue entirely.
 *
 *   If a future deployment decision moves the frontend to a
 *   subdomain, the change is a one-line update to this row via a
 *   follow-up CR — not a schema change, not a code change.
 *
 * =============================================================================
 * The icon_key — verified against BaseIcon.vue's frozen registry
 * =============================================================================
 * `BaseIcon.vue` (mase_app/src/components/primitives/BaseIcon/)
 * declares a frozen registry of 56 Lucide icons. A name not in the
 * registry produces a development-time warning and renders no
 * glyph. The registry was supplied and inspected for this delivery.
 *
 * Candidates considered for the Governance tile:
 *
 *     gavel     — NOT in the registry. The classical symbol for
 *                 governance and adjudication, but unregistered.
 *     scale     — NOT in the registry. The classical symbol for
 *                 justice and balance, but unregistered.
 *     vote      — NOT in the registry. Direct but unavailable.
 *     building  — IN the registry. Reads as "corporate" rather
 *                 than "governance" and is already the natural
 *                 shape for an organisation-structure icon.
 *     shield    — IN the registry. Reads as "security" or
 *                 "compliance" rather than "governance".
 *     landmark  — IN the registry. The classical civic-building
 *                 glyph (columns and pediment). The conventional
 *                 Lucide icon for government, civic authority, and
 *                 institutional governance. Already imported by
 *                 BaseIcon.vue under the "Shareholder" grouping
 *                 comment, and not currently consumed by any nav
 *                 leaf or existing launcher tile.
 *     handshake — IN the registry. Already used by the workspace
 *                 `loans` nav leaf (`shell-labels.ts` v1.6.0).
 *                 Reusing it would create visual ambiguity between
 *                 two unrelated surfaces.
 *
 * `landmark` was chosen because it is registered, semantically
 * correct, and not currently consumed elsewhere. No amendment to
 * `BaseIcon.vue` is required.
 *
 * -----------------------------------------------------------------------------
 * Adjacent finding — not fixed by this unit
 * -----------------------------------------------------------------------------
 * The workspace's `loan-products` nav leaf in
 * `mase_app/src/router/shell-labels.ts` v1.6.0 references
 * `icon: 'package'`. That name is NOT in BaseIcon.vue's registry.
 * The pre-existing `CR-LW3` delivery flagged the name as
 * unverified in its own docblock (§"The icon name — please
 * verify"). This seeder neither introduces nor repairs the
 * defect — it is recorded here only so the observation is not
 * lost. Correcting it is a one-line change to `shell-labels.ts`,
 * which is a separate CR outside the Governance domain.
 *
 * =============================================================================
 * The category and display_order — where the tile sits in the grid
 * =============================================================================
 * Existing launcher rows, ordered by display_order:
 *
 *     10  portal      category: shareholder
 *     20  finance     category: financial
 *     30  hr          category: operations
 *     40  admin       category: platform
 *     50  arna        category: intelligence
 *     60  app         category: platform
 *     70  launcher    category: platform
 *
 * Governance is a platform-level application, like `app`, `admin`,
 * and `launcher`. Its `category` is set to `platform` for that
 * reason. Its `display_order` is set to `65` — between `app` (60)
 * and `launcher` (70) — so that:
 *
 *   · The `launcher` row remains last (its semantic position as
 *     the "launcher of launchers" is preserved).
 *
 *   · Governance groups visually with the other platform-tier
 *     tiles (`app`, `launcher`).
 *
 *   · The gap between 60 and 70 leaves room for a future platform
 *     tile to be inserted at any integer value without a
 *     re-ordering migration.
 *
 * If the design review prefers a different position, the change
 * is a one-line update to the `display_order` value in the
 * `APPLICATION` constant below.
 *
 * =============================================================================
 * The name_am — sourced from the master prompt §5
 * =============================================================================
 * The Amharic display name is `የአስተዳደር እና የአመራር ሥርዓት`, the
 * rendering the master prompt §5 uses as the domain's canonical
 * Amharic name. It is longer than the sibling rows' names —
 * `ማሴስ ወርክስፔስ` for `app`, `የሰው ሀብት አስተዳደር` for `hr` — but the
 * launcher tile has adequate width and the phrase is the domain's
 * established name in the platform's own documentation.
 *
 * The English name `Governance` is the master prompt's own
 * rendering and matches the `slug`.
 *
 * If a native-speaker reviewer prefers a shorter tile label, the
 * change is a one-line update to `name_am` and, for symmetry, a
 * corresponding one-line change to the description. Such a
 * revision would be a follow-up CR touching only this seeder.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * The write goes through
 * `PlatformApplication::updateOrCreate(['slug' => 'governance'], [...])`.
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
 * The seeder is not part of `Database\Seeders\DatabaseSeeder`, so
 * it does not run automatically on `php artisan db:seed`. Invoke
 * it explicitly, as the FIRST of the three Governance seeders:
 *
 *     # 1. This seeder — creates the platform_applications row
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 *     # 2. Permissions — creates the 37 permission rows
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernancePermissionsSeeder"
 *
 *     # 3. Roles — grants the permissions to the platform's roles
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceRolesSeeder"
 *
 * Running this seeder AFTER files 7 or 8 is harmless — those files
 * are idempotent — but the recommended order is as above, because
 * file 7's application-scoped permission requires this row to
 * exist.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Seeders/GovernanceApplicationSeeder.php
 *
 * Post-deployment verification:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 * Expected output:
 *
 *     Governance application registered: slug=governance, id=<N>, frontend_ready=false.
 *
 * Verification of the row:
 *
 *     php artisan tinker
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\PlatformApplication
 *     ...     ::where('slug', 'governance')->first()
 *     => App\Models\... { slug: 'governance', icon_key: 'landmark', ... }
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\PlatformApplication
 *     ...     ::where('slug', 'governance')->value('domain')
 *     => "app.maalearna.com.et/governance"
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\PlatformApplication
 *     ...     ::where('slug', 'governance')->value('is_frontend_ready')
 *     => false
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Rows inserted (first run): 1 (platform_applications.slug = 'governance')
 * Rows updated (later runs): 1 (same row)
 * BaseIcon.vue amendment:    none required (landmark is registered)
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;

/**
 * GovernanceApplicationSeeder
 *
 * Registers the Governance domain as a first-class MASES
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
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 * Expected output:
 *
 *     Governance application registered: slug=governance, id=<N>, frontend_ready=false.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Database\Seeders
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