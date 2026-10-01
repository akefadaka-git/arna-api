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
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Thirteen permissions across four
 *          categories, with `name_am` and `description_am` seeded as
 *          NULL. See finding F-2A42-1. CR-015 (Shareholder Permission
 *          Amharic Labels) was recorded as the deferred backfill.
 *
 * 1.1.0  — Amharic-first labels (Unit P-1):
 *          · Every permission record gains a `name_am` field.
 *          · The `updateOrCreate` payload writes `name_am` and
 *            `description_am` from the record instead of `null`. The
 *            description mirrors the name (the file's reuse-name
 *            policy, unchanged from 1.0.0).
 *          · The runtime warning about NULL labels is removed — the
 *            condition no longer holds.
 *          · CR-015 is closed by this revision. The file's docblock
 *            section §"Amharic labels" is rewritten to record the
 *            closure and to preserve the 1.0.0 state in the history.
 *          · The thirteen slugs, the four categories, the English
 *            labels, the `scope`, `tenant_id`, `application_id`,
 *            `is_active`, and `is_system` fields are all unchanged
 *            from 1.0.0. Only the two Amharic columns differ.
 *          · MINOR version bump because the change is additive
 *            (labels supplied) without altering the permission set or
 *            the seeder's public surface.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * Thirteen permission rows across four categories:
 *
 *   shareholder.*      4 permissions (view_own, view, create, update)
 *   share_class.*      2 permissions (view, manage)
 *   share_holding.*    3 permissions (view_own, view, entry)
 *   certificate.*      4 permissions (view_own, view, issue, cancel)
 *
 * The set is frozen by the RBAC plan in the Phase 2A frozen manifest.
 * Adding, removing, or renaming a slug is a Change Request, because
 * historical rows reference slugs.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * Every insert is performed via `updateOrCreate(['slug' => $slug], [...])`.
 * Running this seeder repeatedly is safe:
 *
 *   - A row whose slug is present is updated to the values declared here.
 *   - A row whose slug is absent is inserted.
 *   - A row whose slug is neither referenced here nor pre-existing is
 *     never touched. The seeder does not delete permissions.
 *
 * =============================================================================
 * Amharic labels
 * =============================================================================
 * Every permission's `name_am` and `description_am` are seeded with
 * Amharic values. The platform's §2.14 (Amharic-first) rule governs:
 * Amharic is the primary display language, English is the fallback.
 * The resource layer (ShareholderResource, 2A.25) reads `name_am` first
 * and falls back to `name_en` only when the Amharic value is empty.
 *
 * The Amharic strings were chosen to match the platform's existing
 * vocabulary — the shareholder, share class, share holding, and
 * certificate terms are the same ones the frontend's shareholder label
 * file (Unit A-3) uses, and the "view", "create", "update", "manage",
 * "record", "issue", and "cancel" verbs are the same forms the frontend
 * label files use.
 *
 * The `_view_own` variants use the possessor-suffix prefix `የራስን`
 * ("own") to distinguish them from their broad counterparts, matching
 * the English distinction and the frontend's convention.
 *
 * A full list of the Amharic term choices, with rationale, is in the
 * Unit P-1 delivery that introduced them. The choices are flagged for
 * native-speaker review; adjustments would be a follow-up CR that
 * touches only this file's `name_am` values.
 *
 * CR-015 (the deferred Amharic-labels CR the 1.0.0 file referenced) is
 * closed by the 1.1.0 revision.
 *
 * =============================================================================
 * Scope and category
 * =============================================================================
 * `scope` is `'tenant'` for all 13 permissions. The Phase 1 `permissions`
 * table declares `scope` as
 * `ENUM('global', 'tenant', 'application')`; domain permissions operate
 * within a tenant boundary.
 *
 * `category` is a free-form VARCHAR(100). The four values used here
 * (`shareholder`, `share_class`, `share_holding`, `certificate`) are the
 * domain's controlled vocabulary for grouping permission lists in the
 * admin UI and in reporting.
 *
 * =============================================================================
 * `is_system`
 * =============================================================================
 * All 13 permissions are flagged `is_system = true`. They are defined by
 * the domain and should not be casually deleted by an administrator. This
 * mirrors the treatment of `app.admin.access` and `user.profile.view` in
 * the Phase 1 MasesCoreSeeder (Phase 1 Amendment A10).
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * The seeder is not part of `Database\Seeders\DatabaseSeeder`, so it does
 * not run automatically on `php artisan db:seed`. Invoke it explicitly:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Database\\Seeders\\ShareholderPermissionsSeeder"
 *
 * A future CR may add an aggregate domain seeder that chains the
 * permission, role, and share-class seeders. Until then, run the three
 * domain seeders explicitly.
 *
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