<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Wallet\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use MaaleArna\Arna\Core\Authorization\Models\Permission;
use MaaleArna\Arna\Core\Authorization\Models\Role;
use RuntimeException;

/**
 * WalletRolesSeeder
 *
 * Assigns the Wallet domain's permissions to the platform's pre-existing
 * roles via the `role_has_permissions` pivot.
 *
 * =============================================================================
 * Historical changes are recorded in Git.
*/
final class WalletRolesSeeder extends Seeder
{
    /**
     * The prefix that identifies a Wallet-domain permission slug.
     *
     * See the class docblock §"The Wallet-domain prefix" for why this
     * is a single prefix and why it does not collide with any other
     * domain's prefix.
     *
     * @var list<string>
     */
    private const WALLET_PERMISSION_PREFIXES = [
        'wallet.',
    ];

    /**
     * The RBAC matrix.
     *
     * Keys are role slugs; values are arrays of permission slugs that
     * the role should hold. The set is authoritative WITHIN the
     * Wallet domain — the composite sync will add missing Wallet
     * pivots and remove surplus Wallet ones, while leaving every
     * non-Wallet pivot untouched.
     *
     * The matrix is reproduced verbatim from Step 7 v1.1.0 §A.2. Each
     * role's set is stated exhaustively rather than as a
     * base-plus-diff so that a reader can audit a single role's
     * permissions without cross-referencing another role's row.
     *
     *   super_admin     — universal, including the privileged
     *                     `wallet.adjust`.
     *
     *   platform_admin  — universal except `wallet.adjust`.
     *
     *   tenant_admin    — administrative; carries the broad view and
     *                     the routine write permissions, but neither
     *                     the `_view_own` variants nor the two
     *                     privileged slugs (`wallet.adjust`,
     *                     `wallet.allocation.reverse`).
     *
     *   manager         — read-only, own-records-only. Holds the two
     *                     `_view_own` slugs; holds none of the write
     *                     or administrative slugs.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_PERMISSIONS = [

        'super_admin' => [
            // Wallet
            'wallet.view_own',
            'wallet.view',
            'wallet.open',
            'wallet.adjust',
            // Obligation
            'wallet.obligation.create',
            // Payment
            'wallet.payment.record',
            // Allocation
            'wallet.allocation.create',
            'wallet.allocation.reverse',
            // Statement
            'wallet.statement.view_own',
            'wallet.statement.view',
        ],

        'platform_admin' => [
            // Wallet — excludes `wallet.adjust`
            'wallet.view_own',
            'wallet.view',
            'wallet.open',
            // Obligation
            'wallet.obligation.create',
            // Payment
            'wallet.payment.record',
            // Allocation
            'wallet.allocation.create',
            'wallet.allocation.reverse',
            // Statement
            'wallet.statement.view_own',
            'wallet.statement.view',
        ],

        'tenant_admin' => [
            // Wallet — broad view only; no `_view_own`, no `wallet.adjust`
            'wallet.view',
            'wallet.open',
            // Obligation
            'wallet.obligation.create',
            // Payment
            'wallet.payment.record',
            // Allocation — `create` only; no `reverse`
            'wallet.allocation.create',
            // Statement — broad view only; no `_view_own`
            'wallet.statement.view',
        ],

        'manager' => [
            // Read-only, own-records-only. Both `_view_own` slugs.
            'wallet.view_own',
            'wallet.statement.view_own',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     *
     * @throws RuntimeException  When a referenced role or permission does
     *                           not exist.
     */
    public function run(): void
    {
        // Pre-resolve every permission slug referenced by the matrix into
        // its primary key. Failing before touching any role ensures we do
        // not partially apply the matrix if a slug is missing.
        $permissionIds = $this->resolvePermissionIds();

        // Pre-resolve every role slug into a Role model. Same rationale.
        $roles = $this->resolveRoles();

        // Apply the matrix.
        foreach (self::ROLE_PERMISSIONS as $roleSlug => $permissionSlugs) {
            $role = $roles[$roleSlug];

            $targetWalletIds = array_map(
                static fn (string $slug): int => $permissionIds[$slug],
                $permissionSlugs,
            );

            // Composite sync — preserves the role's non-Wallet
            // permissions. See the class docblock §"THE CROSS-DOMAIN
            // HAZARD (F-2A43-1)" for the rationale and the five-step
            // algorithm.
            $this->syncWalletPermissions($role, $targetWalletIds);
        }

        $this->command->info(sprintf(
            'Wallet role permissions synced: %d roles, %d total assignments.',
            count(self::ROLE_PERMISSIONS),
            array_sum(array_map('count', self::ROLE_PERMISSIONS)),
        ));
        $this->command->warn(
            'Amharic labels for the Wallet permissions are NULL. '
            . 'CR-WALLET-PERM-AMHARIC backfills them.'
        );
        // NOTE: the 1.0.0 revision printed a second warning about the
        // destructive sync() semantics. That warning has been removed
        // because the composite-sync pattern used by 1.1.0 does not
        // have those semantics.
    }

    // =========================================================================
    // Private — composite sync
    // =========================================================================

    /**
     * Apply the role's frozen Wallet permission set via the
     * composite-sync pattern.
     *
     * The algorithm is the five-step procedure documented in the class
     * docblock's §"THE CROSS-DOMAIN HAZARD (F-2A43-1)" section:
     *
     *   1. Load the role's current permission IDs.
     *   2. Partition them into Wallet-domain IDs and non-Wallet IDs.
     *   3. Compute the target Wallet IDs from the frozen matrix.
     *   4. Compute the composite set = non-Wallet ∪ target Wallet.
     *   5. `sync(compositeSet)`.
     *
     * Step 2's partition is what makes step 5 safe. Any non-Wallet
     * permission the role holds is preserved by being part of the
     * composite set. Step 4's "target Wallet set" is authoritative
     * WITHIN the Wallet domain — any stale Wallet permission the role
     * holds from a prior run is dropped because it is not in the
     * target set.
     *
     * @param  Role            $role
     * @param  array<int, int> $targetWalletIds
     * @return void
     */
    private function syncWalletPermissions(Role $role, array $targetWalletIds): void
    {
        // ---------------------------------------------------------------------
        // 1. Load the role's current permission IDs (with slugs).
        // ---------------------------------------------------------------------
        /** @var Collection<int, Permission> $currentPermissions */
        $currentPermissions = $role->permissions()->get(['id', 'slug']);

        // ---------------------------------------------------------------------
        // 2. Partition: Wallet-domain IDs vs. non-Wallet IDs.
        // ---------------------------------------------------------------------
        $preservedNonWalletIds = [];

        foreach ($currentPermissions as $permission) {
            $slug = (string) $permission->slug;

            if (!$this->isWalletPermissionSlug($slug)) {
                // Non-Wallet permission — preserve it.
                $preservedNonWalletIds[] = (int) $permission->getKey();
            }
            // Wallet permissions are NOT preserved here. They are
            // re-derived from the frozen matrix at step 4. Any Wallet
            // permission the role holds that is NOT in the matrix is
            // thereby dropped (self-correcting for a CR that removes a
            // permission from the frozen set).
        }

        // ---------------------------------------------------------------------
        // 3. Target Wallet IDs — passed in.
        // ---------------------------------------------------------------------
        // $targetWalletIds is the caller-supplied array. See the caller
        // (run()) for how it is built.

        // ---------------------------------------------------------------------
        // 4. Composite set = preserved non-Wallet ∪ target Wallet.
        // ---------------------------------------------------------------------
        $composite = array_values(array_unique(
            array_merge($preservedNonWalletIds, $targetWalletIds),
        ));

        // ---------------------------------------------------------------------
        // 5. Sync the composite set.
        // ---------------------------------------------------------------------
        $role->permissions()->sync($composite);
    }

    /**
     * Determine whether a permission slug belongs to the Wallet domain.
     *
     * A slug is a Wallet permission if it begins with any of the
     * prefixes in `WALLET_PERMISSION_PREFIXES`. See the class docblock
     * §"The Wallet-domain prefix" for the prefix list's rationale and
     * for how the prefix avoids collisions with other domains.
     *
     * @param  string $slug
     * @return bool
     */
    private function isWalletPermissionSlug(string $slug): bool
    {
        foreach (self::WALLET_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Private — resolution helpers
    // =========================================================================

    /**
     * Resolve every permission slug referenced by the matrix into its id.
     *
     * Fails loudly if any slug is not present in the `permissions` table —
     * that means 2B.55 has not been run (or was run for a subset).
     *
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    private function resolvePermissionIds(): array
    {
        // Collect the union of all permission slugs referenced by the
        // matrix.
        $allSlugs = [];
        foreach (self::ROLE_PERMISSIONS as $slugs) {
            foreach ($slugs as $slug) {
                $allSlugs[$slug] = true;
            }
        }

        $uniqueSlugs = array_keys($allSlugs);

        // Fetch every referenced permission in a single query.
        $rows = Permission::query()
            ->whereIn('slug', $uniqueSlugs)
            ->get(['id', 'slug']);

        $resolved = [];
        foreach ($rows as $row) {
            $resolved[$row->slug] = (int) $row->id;
        }

        // Verify that every referenced slug was found.
        $missing = array_diff($uniqueSlugs, array_keys($resolved));

        if ($missing !== []) {
            throw new RuntimeException(
                'WalletRolesSeeder: the following permission slugs are not '
                . 'present in the `permissions` table: '
                . implode(', ', $missing)
                . '. Run WalletPermissionsSeeder (2B.55) first.'
            );
        }

        return $resolved;
    }

    /**
     * Resolve every role slug referenced by the matrix into a Role model.
     *
     * Fails loudly if any role slug is not present in the `roles` table —
     * that means the Phase 1 ArnaCoreSeeder has not been run.
     *
     * @return array<string, Role>
     *
     * @throws RuntimeException
     */
    private function resolveRoles(): array
    {
        $slugs = array_keys(self::ROLE_PERMISSIONS);

        $rows = Role::query()
            ->whereIn('slug', $slugs)
            ->get();

        $resolved = [];
        foreach ($rows as $role) {
            $resolved[$role->slug] = $role;
        }

        $missing = array_diff($slugs, array_keys($resolved));

        if ($missing !== []) {
            throw new RuntimeException(
                'WalletRolesSeeder: the following role slugs are not '
                . 'present in the `roles` table: '
                . implode(', ', $missing)
                . '. Run ArnaCoreSeeder (Phase 1) first.'
            );
        }

        return $resolved;
    }
}