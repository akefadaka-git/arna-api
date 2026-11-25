<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * ShareholderPermissionsSeeder
 *
 * Seeds the Shareholder domain's permissions into the platform
 * `permissions` table.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders
 * @version 1.1.0
 */
final class ShareholderPermissionsSeeder extends Seeder
{
    /**
     * The 13 permissions this seeder manages.
     *
     * Each entry carries a slug, an Amharic label, an English label,
     * and a category. The `scope` is uniform across the set
     * (`'tenant'`) and is applied when the row is written rather than
     * repeated in each entry.
     *
     * Ordering is presentation-only; `updateOrCreate` uses slug as the
     * identity key.
     *
     * @var array<int, array{slug: string, name_am: string, name_en: string, category: string}>
     */
    private const PERMISSIONS = [
        // ---------------------------------------------------------------------
        // Shareholder — the primary resource.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'shareholder.view_own',
            'name_am'  => 'የራስን የባለአክሲዮን መዝገብ ማየት',
            'name_en'  => 'View own shareholder record',
            'category' => 'shareholder',
        ],
        [
            'slug'     => 'shareholder.view',
            'name_am'  => 'ባለአክሲዮኖችን ማየት',
            'name_en'  => 'View shareholders',
            'category' => 'shareholder',
        ],
        [
            'slug'     => 'shareholder.create',
            'name_am'  => 'ባለአክሲዮን መፍጠር',
            'name_en'  => 'Create shareholder',
            'category' => 'shareholder',
        ],
        [
            'slug'     => 'shareholder.update',
            'name_am'  => 'ባለአክሲዮን ማዘመን',
            'name_en'  => 'Update shareholder',
            'category' => 'shareholder',
        ],

        // ---------------------------------------------------------------------
        // Share class — reference data.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'share_class.view',
            'name_am'  => 'የአክሲዮን ክፍሎችን ማየት',
            'name_en'  => 'View share classes',
            'category' => 'share_class',
        ],
        [
            'slug'     => 'share_class.manage',
            'name_am'  => 'የአክሲዮን ክፍሎችን ማስተዳደር',
            'name_en'  => 'Manage share classes',
            'category' => 'share_class',
        ],

        // ---------------------------------------------------------------------
        // Share holding — the ledger surface.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'share_holding.view_own',
            'name_am'  => 'የራስን አክሲዮን ይዞታዎች ማየት',
            'name_en'  => 'View own share holdings',
            'category' => 'share_holding',
        ],
        [
            'slug'     => 'share_holding.view',
            'name_am'  => 'የአክሲዮን ይዞታዎችን ማየት',
            'name_en'  => 'View share holdings',
            'category' => 'share_holding',
        ],
        [
            'slug'     => 'share_holding.entry',
            'name_am'  => 'የአክሲዮን ይዞታ ግቤት መመዝገብ',
            'name_en'  => 'Record share holding entry',
            'category' => 'share_holding',
        ],

        // ---------------------------------------------------------------------
        // Certificate — the lifecycle surface.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'certificate.view_own',
            'name_am'  => 'የራስን ምስክር ወረቀቶች ማየት',
            'name_en'  => 'View own certificates',
            'category' => 'certificate',
        ],
        [
            'slug'     => 'certificate.view',
            'name_am'  => 'ምስክር ወረቀቶችን ማየት',
            'name_en'  => 'View certificates',
            'category' => 'certificate',
        ],
        [
            'slug'     => 'certificate.issue',
            'name_am'  => 'ምስክር ወረቀት መስጠት',
            'name_en'  => 'Issue certificate',
            'category' => 'certificate',
        ],
        [
            'slug'     => 'certificate.cancel',
            'name_am'  => 'ምስክር ወረቀት መሰረዝ',
            'name_en'  => 'Cancel certificate',
            'category' => 'certificate',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $definition) {
            Permission::updateOrCreate(
                // Identity key.
                ['slug' => $definition['slug']],
                // Values to set on insert and on update.
                [
                    'name_am'        => $definition['name_am'],
                    'name_en'        => $definition['name_en'],

                    // Reuse-name policy: the description mirrors the name
                    // in both languages. Amharic is primary per §2.14.
                    'description_am' => $definition['name_am'],
                    'description_en' => $definition['name_en'],

                    'category'       => $definition['category'],
                    'scope'          => 'tenant',
                    'tenant_id'      => null,
                    'application_id' => null,
                    'is_active'      => true,
                    'is_system'      => true,
                ]
            );
        }

        $this->command->info(
            'Shareholder permissions seeded: ' . count(self::PERMISSIONS) . ' entries (Amharic-first).'
        );
        // The 1.0.0 revision printed a warning about NULL Amharic labels
        // and referenced CR-015 as the deferred backfill. CR-015 is
        // closed by this revision; the warning has been removed.
    }
}