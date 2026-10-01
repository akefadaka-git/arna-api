<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Wallet\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * WalletPermissionsSeeder
 *
 * Seeds the Wallet domain's permissions into the platform `permissions`
 * table.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Ten permissions across five categories,
 *          with `name_am` and `description_am` seeded as NULL. See
 *          finding F-2A42-1, carried forward. CR-WALLET-PERM-AMHARIC
 *          was recorded as the deferred backfill.
 *
 * 1.1.0  — Amharic-first labels (Unit P-2):
 *          · Every permission record gains a `name_am` field.
 *          · The `updateOrCreate` payload writes `name_am` and
 *            `description_am` from the record instead of `null`. The
 *            description mirrors the name (the file's reuse-name
 *            policy, unchanged from 1.0.0).
 *          · The runtime warning about NULL labels is removed — the
 *            condition no longer holds.
 *          · CR-WALLET-PERM-AMHARIC is closed by this revision. The
 *            file's docblock section §"Amharic labels" is rewritten
 *            to record the closure and to preserve the 1.0.0 state in
 *            the history.
 *          · The ten slugs, the five categories, the English labels,
 *            the `scope`, `tenant_id`, `application_id`, `is_active`,
 *            and `is_system` fields are all unchanged from 1.0.0.
 *            Only the two Amharic columns differ.
 *          · MINOR version bump because the change is additive
 *            (labels supplied) without altering the permission set or
 *            the seeder's public surface.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * Ten permission rows across five categories:
 *
 *   wallet.*               4 permissions (view_own, view, open, adjust)
 *   wallet_obligation.*    1 permission  (create)
 *   wallet_payment.*       1 permission  (record)
 *   wallet_allocation.*    2 permissions (create, reverse)
 *   wallet_statement.*     2 permissions (view_own, view)
 *
 * The set is frozen by Step 7 v1.1.0 §A.1.2, resolution (c). It includes
 * the two `_view_own` variants that the 2A precedent established and that
 * the manager role requires:
 *
 *     wallet.view_own
 *     wallet.statement.view_own
 *
 * The two `_view_own` slugs are alternatives to their broad counterparts
 * in the policy layer (2B.37): a caller with `wallet.view_own` can view
 * ONLY their own wallet; a caller with `wallet.view` can view every
 * wallet in the tenant.
 *
 * Adding, removing, or renaming a slug is a Change Request, because
 * historical rows reference slugs and because the role binding matrix
 * (WalletRolesSeeder, 2B.56) depends on the exact set.
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
 * This mirrors the 2A `ShareholderPermissionsSeeder` (2A.42) verbatim.
 *
 * =============================================================================
 * Amharic labels
 * =============================================================================
 * Every permission's `name_am` and `description_am` are seeded with
 * Amharic values. The platform's §2.14 (Amharic-first) rule governs:
 * Amharic is the primary display language, English is the fallback.
 * The resource layer reads `name_am` first and falls back to `name_en`
 * only when the Amharic value is empty.
 *
 * The Amharic strings were chosen to match the platform's existing
 * vocabulary. The financial verbs ("view", "open", "record", "reverse")
 * use the same forms the frontend label files use; the "own" variants
 * use the possessor-suffix prefix `የራስን` to mirror the Shareholder
 * domain's choice (Unit P-1). The noun `የገንዘብ ማስቀመጫ` for "wallet" is
 * a compound of `የገንዘብ` (of money) and `ማስቀመጫ` (storage place); it is
 * flagged for native-speaker review alongside the other labels.
 *
 * A full list of the Amharic term choices, with rationale, is in the
 * Unit P-2 delivery that introduced them. The choices are flagged for
 * native-speaker review; adjustments would be a follow-up CR that
 * touches only this file's `name_am` values.
 *
 * CR-WALLET-PERM-AMHARIC (the deferred Amharic-labels CR the 1.0.0
 * file referenced) is closed by the 1.1.0 revision.
 *
 * =============================================================================
 * Scope and category
 * =============================================================================
 * `scope` is `'tenant'` for all ten permissions. The Phase 1
 * `permissions` table declares `scope` as
 * `ENUM('global', 'tenant', 'application')`; domain permissions operate
 * within a tenant boundary.
 *
 * `category` is a free-form VARCHAR(100). The five values used here
 * (`wallet`, `wallet_obligation`, `wallet_payment`, `wallet_allocation`,
 * `wallet_statement`) subdivide the domain's permissions more finely than
 * the 2A seeders did, because the Wallet domain's operation set is
 * broader. The vocabulary is the domain's controlled grouping for
 * permission lists in the admin UI and in reporting.
 *
 * =============================================================================
 * `is_system`
 * =============================================================================
 * All ten permissions are flagged `is_system = true`. They are defined by
 * the domain and should not be casually deleted by an administrator. This
 * mirrors the treatment of the 2A Shareholder permissions (2A.42
 * §"`is_system`") and of the Phase 1 `app.admin.access` and
 * `user.profile.view` permissions.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * The seeder is not part of `Database\Seeders\DatabaseSeeder`, so it does
 * not run automatically on `php artisan db:seed`. Invoke it explicitly:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Wallet\\Database\\Seeders\\WalletPermissionsSeeder"
 *
 * A future CR may add an aggregate domain seeder that chains the
 * permission, role, and any domain-data seeders. Until then, run the two
 * Wallet domain seeders explicitly, in this order:
 *
 *   1. WalletPermissionsSeeder (this file)
 *   2. WalletRolesSeeder (2B.56)
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Architecture v1.2.0 §13      — RBAC integration
 *   Architecture v1.2.0 §19      — Q3 resolution (adjustment privileged)
 *   Step 5 (manifest) 2B.55      — This file
 *   Step 5 (manifest) 2B.56      — WalletRolesSeeder (the sibling)
 *   Step 7 v1.1.0 §A.1.1         — Permission slugs
 *   Step 7 v1.1.0 §A.1.2         — F-2B-RBAC-1 resolution (choice c)
 *   Step 7 v1.1.0 §A.2           — Role binding matrix
 *   Step 7 v1.1.0 §A.3.1         — Seeder structure
 *   Step 7 v1.1.0 §A.2.1         — Rationale for the privileged scope
 *   §2.4 (conventions)           — Service / seeder conventions
 *   §2.14 (conventions)          — Amharic-first rules
 *
 * @package MaaleArna\MASES\Core\Domains\Wallet\Database\Seeders
 * @version 1.1.0
 */
final class WalletPermissionsSeeder extends Seeder
{
    /**
     * The ten permissions this seeder manages.
     *
     * Each entry carries a slug, an Amharic label, an English label,
     * and a category. The `scope` is uniform across the set
     * (`'tenant'`) and is applied when the row is written rather than
     * repeated in each entry.
     *
     * Ordering is presentation-only; `updateOrCreate` uses slug as the
     * identity key.
     *
     * The grouping below mirrors the five categories declared in Step 7
     * v1.1.0 §A.3.1:
     *
     *     wallet             — the aggregate-root surface
     *     wallet_obligation  — the obligation write path
     *     wallet_payment     — the payment write path
     *     wallet_allocation  — the attribution surface
     *     wallet_statement   — the statement projection
     *
     * @var array<int, array{slug: string, name_am: string, name_en: string, category: string}>
     */
    private const PERMISSIONS = [
        // ---------------------------------------------------------------------
        // Wallet — the aggregate root.
        //
        // Four slugs. The two `_view_own` / `view` variants follow the 2A
        // convention: `_view_own` grants policy-mediated access to the
        // caller's own wallet; `view` grants the broad tenant-wide
        // permission. `open` creates a wallet (Q4 — explicit opening);
        // `adjust` is the privileged balance-correction operation (Q3 —
        // held only by super_admin in the frozen RBAC matrix).
        // ---------------------------------------------------------------------
        [
            'slug'     => 'wallet.view_own',
            'name_am'  => 'የራስን የገንዘብ ማስቀመጫ ማየት',
            'name_en'  => 'View own wallet',
            'category' => 'wallet',
        ],
        [
            'slug'     => 'wallet.view',
            'name_am'  => 'የገንዘብ ማስቀመጫዎችን ማየት',
            'name_en'  => 'View wallets',
            'category' => 'wallet',
        ],
        [
            'slug'     => 'wallet.open',
            'name_am'  => 'የገንዘብ ማስቀመጫ መክፈት',
            'name_en'  => 'Open wallet',
            'category' => 'wallet',
        ],
        [
            'slug'     => 'wallet.adjust',
            'name_am'  => 'የገንዘብ ማስቀመጫ ቀሪ ሂሳብ ማስተካከል',
            'name_en'  => 'Adjust wallet balance',
            'category' => 'wallet',
        ],

        // ---------------------------------------------------------------------
        // Wallet obligation — the obligation write path.
        //
        // A single slug. The 2B manifest exposes no update, delete, or
        // standalone read path for obligations; they are embedded under
        // the parent wallet's `show` response (Step 7 v1.1.0 §A.4,
        // F-2B-LIST-1). The permission guards the store action only.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'wallet.obligation.create',
            'name_am'  => 'የገንዘብ ግዴታ መመዝገብ',
            'name_en'  => 'Record obligation',
            'category' => 'wallet_obligation',
        ],

        // ---------------------------------------------------------------------
        // Wallet payment — the payment write path.
        //
        // A single slug. Payments are not component-tagged, not deletable,
        // and not addressable by id in 2B; the permission guards the store
        // action only.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'wallet.payment.record',
            'name_am'  => 'ክፍያ መመዝገብ',
            'name_en'  => 'Record payment',
            'category' => 'wallet_payment',
        ],

        // ---------------------------------------------------------------------
        // Wallet allocation — the attribution surface.
        //
        // Two slugs. `create` is the routine operation of applying a
        // payment to an obligation; `reverse` is the privileged correction
        // of a historical attribution. Per Step 7 v1.1.0 §A.2.1, the two
        // are deliberately NOT bundled: a caller may hold `create` without
        // `reverse`.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'wallet.allocation.create',
            'name_am'  => 'የገንዘብ ምደባ መመዝገብ',
            'name_en'  => 'Record allocation',
            'category' => 'wallet_allocation',
        ],
        [
            'slug'     => 'wallet.allocation.reverse',
            'name_am'  => 'የገንዘብ ምደባ መመለስ',
            'name_en'  => 'Reverse allocation',
            'category' => 'wallet_allocation',
        ],

        // ---------------------------------------------------------------------
        // Wallet statement — the statement projection.
        //
        // Two slugs. Same `_view_own` / `view` variant pattern as the
        // wallet view pair. The two are INDEPENDENT of the wallet-view
        // pair: a caller may hold `wallet.statement.view` without
        // `wallet.view`, and vice versa (Step 7 v1.1.0 §C.1.1).
        // ---------------------------------------------------------------------
        [
            'slug'     => 'wallet.statement.view_own',
            'name_am'  => 'የራስን የገንዘብ ማስቀመጫ መግለጫ ማየት',
            'name_en'  => 'View own wallet statement',
            'category' => 'wallet_statement',
        ],
        [
            'slug'     => 'wallet.statement.view',
            'name_am'  => 'የገንዘብ ማስቀመጫ መግለጫዎችን ማየት',
            'name_en'  => 'View wallet statements',
            'category' => 'wallet_statement',
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
            'Wallet permissions seeded: ' . count(self::PERMISSIONS) . ' entries (Amharic-first).'
        );
        // The 1.0.0 revision printed a warning about NULL Amharic labels
        // and referenced CR-WALLET-PERM-AMHARIC as the deferred backfill.
        // That CR is closed by this revision; the warning has been removed.
    }
}