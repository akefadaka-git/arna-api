<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\Permission;

/**
 * WalletPermissionsSeeder
 *
 * Seeds the Wallet domain's permissions into the platform `permissions`
 * table.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
 * @package MaaleArna\Arna\Core\Domains\Wallet\Database\Seeders
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