<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P1-2
 * =============================================================================
 *
 * File:            ShareholderRolesSeeder.php
 * Unit:            P1-2 — shareholder role RBAC fix
 * Delivery type:   Amendment
 * Net lines:       +5 (one matrix entry), -5 (one stale warning block),
 *                  +… (docblock revisions)
 *
 * Before:
 *   The seeder's ROLE_PERMISSIONS matrix declared four roles
 *   (super_admin, platform_admin, tenant_admin, manager). The
 *   `shareholder` role (roles.id = 7) was not managed here — it received
 *   its permissions from the Phase 1 seed only, which does not include
 *   the three Shareholder-domain "own" permissions. As a result the 98
 *   users carrying the `shareholder` role could not view their own
 *   shareholder record via ShareholderPolicy::view path 2, because that
 *   path requires the `shareholder.view_own` permission.
 *
 * After:
 *   One entry is added to ROLE_PERMISSIONS:
 *
 *       'shareholder' => [
 *           'shareholder.view_own',
 *           'share_holding.view_own',
 *           'certificate.view_own',
 *       ],
 *
 *   The composite-sync algorithm (unchanged) ensures:
 *     · the three Shareholder-domain slugs are added,
 *     · every non-Shareholder-domain permission the role currently holds
 *       is preserved (the nine Phase 1 grants),
 *     · no other role's assignment changes (their target sets are
 *       unchanged; the composite equals the current state for each).
 *
 *   The stale runtime warning about CR-015 is removed: CR-015 was
 *   closed by ShareholderPermissionsSeeder v1.1.0, which backfilled
 *   every permission's Amharic labels. The warning contradicted the
 *   current state.
 *
 * Matrix entry introduced:
 *   · 'shareholder' → 3 slugs
 *
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Permissions added:         none (all three slugs already exist)
 * Permissions removed:       none
 * Roles added:               none
 * Roles removed:             none
 * Algorithm changed:         no
 * Prefix list changed:       no
 *
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use RuntimeException;

/**
 * ShareholderRolesSeeder
 *
 * Assigns the Shareholder domain's permissions to the platform's
 * pre-existing roles via the `role_has_permissions` pivot.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Four role → permission assignments using
 *          `sync($shareholderIds)`. Documented the F-2A43-1 hazard in
 *          the class docblock and printed a runtime warning about it,
 *          but did NOT protect against it — the code shipped the
 *          destructive `sync()` call anyway.
 *
 * 1.1.0  — COMPOSITE SYNC (F-2A43-1 fix):
 *
 *          · Replaced the bare `sync($shareholderIds)` call with the
 *            composite-sync pattern that preserves the role's
 *            non-Shareholder permissions. The algorithm mirrors
 *            `WalletRolesSeeder` 1.1.0 and `LoanRolesSeeder` 1.0.1:
 *
 *              1. Load the role's current permission IDs.
 *              2. Partition them into Shareholder-domain IDs (those
 *                 whose slug begins with one of the four Shareholder
 *                 prefixes) and non-Shareholder IDs (everything else).
 *              3. Compute the target Shareholder IDs from the frozen
 *                 matrix.
 *              4. Compute the composite set = non-Shareholder IDs ∪
 *                 target Shareholder IDs.
 *              5. `sync(compositeSet)`.
 *
 *          · The fix was necessitated by the Phase 2C deployment's
 *            Layer 3 validation. The validation's 3.2 output showed
 *            four roles with anomalous permission counts
 *            (`platform_admin` 34, `tenant_admin` 22, `super_admin`
 *            38, `shareholder` 9) — every one of which is explained by
 *            this seeder's bare `sync()` having wiped the role's
 *            permission set immediately before the Wallet and Loan
 *            seeders ran their composite syncs.
 *
 *            Specifically: `platform_admin` was written by
 *            `MasesCoreSeeder` with 33 permissions, including the
 *            three `app.{slug}.access` slugs Unit C-1 granted. This
 *            seeder's `sync()` replaced them with the 12
 *            Shareholder-domain slugs. The subsequent Wallet (9) and
 *            Loan (13) seeders preserved the Shareholder slugs and
 *            added their own, producing the observed 34 = 12 + 9 + 13.
 *
 *          · With all three role seeders (Shareholder, Wallet, Loan)
 *            applying the composite-sync pattern, the three are
 *            order-independent: any sequence of runs produces the
 *            union of the three domains' assignments on every role,
 *            preserving the core's original grant on each role.
 *
 *          · The runtime warning from 1.0.0 is REMOVED. The condition
 *            it warned about no longer occurs.
 *
 *          · No change to the frozen RBAC matrix, the slug set, the
 *            role set, or the resolution helpers. The only change is
 *            the shape of the pivot update.
 *
 * 1.2.0  — shareholder role added to the managed matrix (Portal P1-2):
 *
 *          · One entry is added to ROLE_PERMISSIONS:
 *
 *                'shareholder' => [
 *                    'shareholder.view_own',
 *                    'share_holding.view_own',
 *                    'certificate.view_own',
 *                ],
 *
 *          · The `shareholder` role is the domain's self-service role.
 *            Every individual shareholder user in the current seed
 *            carries it. Before this revision, it held none of the
 *            domain's "own" permissions, so a shareholder-role user
 *            could not view their own shareholder record via
 *            ShareholderPolicy::view path 2. The three slugs activate
 *            that existing path.
 *
 *          · This is the first role in the matrix whose slug does not
 *            begin with `super_`, `platform_`, or `tenant_` and is not
 *            `manager`. The composite-sync algorithm treats it
 *            identically to the others: non-Shareholder permissions
 *            are preserved, Shareholder-domain permissions are set to
 *            the matrix's target. The role's nine Phase 1 grants
 *            (user.profile.view, app.portal.access, three file
 *            permissions, four loan permissions) are preserved by the
 *            partition step.
 *
 *          · The stale runtime warning about CR-015 is removed.
 *            CR-015 was closed by ShareholderPermissionsSeeder v1.1.0,
 *            which backfilled the Amharic labels on every Shareholder-
 *            domain permission. The warning contradicted the current
 *            state and was misleading on every run.
 *
 *          · No change to the composite-sync algorithm, the
 *            Shareholder-domain prefix list, the four pre-existing
 *            matrix entries, or the resolution helpers.
 *
 *          · MINOR version bump: the matrix shape is additive (one
 *            entry) and the algorithm is unchanged. The removal of the
 *            stale warning is a documentation-only correction, not a
 *            behaviour change.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * Five role → permission assignments, per the frozen RBAC matrix:
 *
 *   super_admin     → all 13 Shareholder-domain permissions
 *   platform_admin  → all 13 except `certificate.cancel`        (12)
 *   tenant_admin    → shareholder.view, shareholder.create,
 *                     shareholder.update, share_class.view,
 *                     share_holding.view, certificate.view,
 *                     certificate.issue                           (7)
 *   manager         → shareholder.view_own, share_holding.view_own,
 *                     certificate.view_own                        (3)
 *   shareholder     → shareholder.view_own, share_holding.view_own,
 *                     certificate.view_own                        (3)
 *
 * Total Shareholder-domain assignments this seeder manages: 38 across
 * the five roles. Unchanged from 1.1.0 for the four administrative
 * roles; the shareholder role's three assignments are new in 1.2.0.
 *
 * Note: the assignment of `share_holding.view_own` and
 * `certificate.view_own` to the shareholder role is prospective. No
 * route middleware and no policy currently reads either slug — they
 * are declared permissions whose enforcement path is the subject of
 * Portal P2.3 (wire or retire). Until P2.3, the grants are inert
 * bookkeeping. `shareholder.view_own` is the only one of the three
 * that is consumed by an existing code path
 * (ShareholderPolicy::view path 2).
 *
 * =============================================================================
 * THE CROSS-DOMAIN HAZARD (F-2A43-1) — RESOLVED BY 1.1.0
 * =============================================================================
 * Before 1.1.0, the seeder called:
 *
 *     $role->permissions()->sync($shareholderIds);
 *
 * `sync()` REPLACES the role's entire permission set. Any permission
 * the role held from another domain (Wallet, Loan, Core, or any future
 * phase) was SILENTLY DETACHED.
 *
 * The hazard was first observed in Phase 2A (file 2A.43, this file) and
 * repeated in Phase 2B (WalletRolesSeeder 1.0.0) and Phase 2C
 * (LoanRolesSeeder 1.0.0). The Wallet and Loan seeders were corrected
 * in their own 1.1.0 / 1.0.1 revisions; this file's correction was the
 * last of the three.
 *
 * The correct pattern — implemented since 1.1.0 and unchanged by 1.2.0
 * — is to compute the composite set = (non-Shareholder IDs currently on
 * the role) ∪ (target Shareholder IDs from the frozen matrix), then call
 * `sync()` with that composite. See the current §"Revision history"
 * entry for 1.1.0, and §"Composite sync" below for the five-step
 * algorithm.
 *
 * A future contributor MUST NOT simplify this back to a bare
 * `sync($shareholderIds)`. That simplification would re-introduce
 * F-2A43-1 in the Shareholder domain.
 *
 * =============================================================================
 * The Shareholder-domain prefixes
 * =============================================================================
 * Every Shareholder-domain permission slug begins with one of four
 * prefixes:
 *
 *     shareholder.    — shareholder.{view,view_own,create,update}
 *     share_class.    — share_class.{view,manage}
 *     share_holding.  — share_holding.{view,view_own,entry}
 *     certificate.    — certificate.{view,view_own,issue,cancel}
 *
 * These prefixes do NOT collide with any other domain's prefix:
 *
 *   · Wallet:  `wallet.` — does not begin with any Shareholder prefix
 *   · Loan:    `loan.`   — does not begin with any Shareholder prefix
 *   · Core:    `user.`, `role.`, `permission.`, `app.`, `file.`,
 *              `tenant.`, `test.` — none begin with a Shareholder
 *              prefix
 *
 * The four prefixes are declared as a class constant so that a future
 * CR that adds a fifth Shareholder sub-namespace extends the list in
 * one place.
 *
 * Note on the `app.` collision: Core's `app.{slug}.access` slugs begin
 * with `app.`, which is NOT a Shareholder prefix. They are therefore
 * preserved as non-Shareholder by this seeder's partition step. This
 * is exactly what makes the C-1 slugs survive.
 *
 * =============================================================================
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Database\Seeders
 * @version 1.2.0
 */
final class ShareholderRolesSeeder extends Seeder
{
    /**
     * The prefixes that identify a Shareholder-domain permission slug.
     *
     * See the class docblock §"The Shareholder-domain prefixes" for the
     * list's rationale and for how the prefixes avoid collisions with
     * other domains.
     *
     * @var list<string>
     */
    private const SHAREHOLDER_PERMISSION_PREFIXES = [
        'shareholder.',
        'share_class.',
        'share_holding.',
        'certificate.',
    ];

    /**
     * The RBAC matrix.
     *
     * Keys are role slugs; values are arrays of permission slugs that
     * the role should hold. The set is authoritative WITHIN the
     * Shareholder domain — the composite sync will add missing
     * Shareholder pivots and remove surplus Shareholder ones, while
     * leaving every non-Shareholder pivot untouched.
     *
     * `super_admin` and `platform_admin` receive overlapping sets.
     * `super_admin` includes `certificate.cancel`; `platform_admin`
     * does not. The difference is the sole distinction between the two
     * roles in the Shareholder domain.
     *
     * `shareholder` and `manager` hold the same three slugs. The two
     * roles are distinct in the broader platform — `manager` is a
     * tenant-scoped administrative role with additional grants from
     * other domains; `shareholder` is the baseline self-service role
     * held by every individual shareholder user. Their overlap in the
     * Shareholder domain reflects the fact that both need to view
     * their own record and its associated holdings and certificates.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_PERMISSIONS = [

        'super_admin' => [
            // Shareholder
            'shareholder.view_own',
            'shareholder.view',
            'shareholder.create',
            'shareholder.update',
            // Share class
            'share_class.view',
            'share_class.manage',
            // Share holding
            'share_holding.view_own',
            'share_holding.view',
            'share_holding.entry',
            // Certificate
            'certificate.view_own',
            'certificate.view',
            'certificate.issue',
            'certificate.cancel',
        ],

        'platform_admin' => [
            // Shareholder
            'shareholder.view_own',
            'shareholder.view',
            'shareholder.create',
            'shareholder.update',
            // Share class
            'share_class.view',
            'share_class.manage',
            // Share holding
            'share_holding.view_own',
            'share_holding.view',
            'share_holding.entry',
            // Certificate — excludes certificate.cancel
            'certificate.view_own',
            'certificate.view',
            'certificate.issue',
        ],

        'tenant_admin' => [
            // Shareholder
            'shareholder.view',
            'shareholder.create',
            'shareholder.update',
            // Share class (read-only)
            'share_class.view',
            // Share holding (read-only)
            'share_holding.view',
            // Certificate (issue only, no cancel)
            'certificate.view',
            'certificate.issue',
        ],

        'manager' => [
            // Read-only access to the caller's own records.
            'shareholder.view_own',
            'share_holding.view_own',
            'certificate.view_own',
        ],

        /*
         * The `shareholder` role — the domain's baseline self-service
         * role. Added in 1.2.0 (Portal P1-2). Every individual
         * shareholder user in the current seed carries it.
         *
         * The role's non-Shareholder permissions (user.profile.view,
         * app.portal.access, three file permissions, four loan
         * permissions) are preserved by the composite sync's partition
         * step and are not listed here — this matrix is authoritative
         * only within the Shareholder domain.
         *
         * `shareholder.view_own` is consumed by ShareholderPolicy::view
         * path 2. The other two slugs are prospective; see the class
         * docblock §"What this seeder writes".
         */
        'shareholder' => [
            'shareholder.view_own',
            'share_holding.view_own',
            'certificate.view_own',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     *
     * @throws RuntimeException  When a referenced role or permission does not exist.
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

            $targetShareholderIds = array_map(
                static fn (string $slug): int => $permissionIds[$slug],
                $permissionSlugs,
            );

            // Composite sync — preserves the role's non-Shareholder
            // permissions. See the class docblock §"THE CROSS-DOMAIN
            // HAZARD (F-2A43-1)" for the rationale and the five-step
            // algorithm.
            $this->syncShareholderPermissions($role, $targetShareholderIds);
        }

        $this->command->info(sprintf(
            'Shareholder role permissions synced: %d roles, %d total assignments.',
            count(self::ROLE_PERMISSIONS),
            array_sum(array_map('count', self::ROLE_PERMISSIONS)),
        ));
        // The 1.0.0 revision printed a warning about the destructive
        // sync() semantics, which 1.1.0 removed. The 1.1.0 revision
        // additionally printed a warning about the NULL Amharic labels
        // on Shareholder permissions (CR-015), which 1.2.0 removes:
        // CR-015 was closed by ShareholderPermissionsSeeder v1.1.0,
        // which backfilled the labels. No runtime warning is emitted by
        // the current revision.
    }

    // =========================================================================
    // Private — composite sync
    // =========================================================================

    /**
     * Apply the role's frozen Shareholder permission set via the
     * composite-sync pattern.
     *
     * The algorithm is the five-step procedure documented in the class
     * docblock's §"THE CROSS-DOMAIN HAZARD (F-2A43-1)" section:
     *
     *   1. Load the role's current permission IDs.
     *   2. Partition them into Shareholder-domain IDs and
     *      non-Shareholder IDs.
     *   3. Compute the target Shareholder IDs from the frozen matrix.
     *   4. Compute the composite set = non-Shareholder ∪ target
     *      Shareholder.
     *   5. `sync(compositeSet)`.
     *
     * Step 2's partition is what makes step 5 safe. Any non-Shareholder
     * permission the role holds is preserved by being part of the
     * composite set. Step 4's "target Shareholder set" is authoritative
     * WITHIN the Shareholder domain — any stale Shareholder permission
     * the role holds from a prior run is dropped because it is not in
     * the target set.
     *
     * @param  Role            $role
     * @param  array<int, int> $targetShareholderIds
     * @return void
     */
    private function syncShareholderPermissions(Role $role, array $targetShareholderIds): void
    {
        // ---------------------------------------------------------------------
        // 1. Load the role's current permission IDs (with slugs).
        // ---------------------------------------------------------------------
        /** @var Collection<int, Permission> $currentPermissions */
        $currentPermissions = $role->permissions()->get(['id', 'slug']);

        // ---------------------------------------------------------------------
        // 2. Partition: Shareholder-domain IDs vs. non-Shareholder IDs.
        // ---------------------------------------------------------------------
        $preservedNonShareholderIds = [];

        foreach ($currentPermissions as $permission) {
            $slug = (string) $permission->slug;

            if (!$this->isShareholderPermissionSlug($slug)) {
                // Non-Shareholder permission — preserve it.
                $preservedNonShareholderIds[] = (int) $permission->getKey();
            }
            // Shareholder permissions are NOT preserved here. They are
            // re-derived from the frozen matrix at step 4. Any
            // Shareholder permission the role holds that is NOT in the
            // matrix is thereby dropped (self-correcting for a CR that
            // removes a permission from the frozen set).
        }

        // ---------------------------------------------------------------------
        // 3. Target Shareholder IDs — passed in.
        // ---------------------------------------------------------------------
        // $targetShareholderIds is the caller-supplied array. See the
        // caller (run()) for how it is built.

        // ---------------------------------------------------------------------
        // 4. Composite set = preserved non-Shareholder ∪ target
        //    Shareholder.
        // ---------------------------------------------------------------------
        $composite = array_values(array_unique(
            array_merge($preservedNonShareholderIds, $targetShareholderIds),
        ));

        // ---------------------------------------------------------------------
        // 5. Sync the composite set.
        // ---------------------------------------------------------------------
        $role->permissions()->sync($composite);
    }

    /**
     * Determine whether a permission slug belongs to the Shareholder
     * domain.
     *
     * A slug is a Shareholder permission if it begins with any of the
     * prefixes in `SHAREHOLDER_PERMISSION_PREFIXES`. See the class
     * docblock §"The Shareholder-domain prefixes" for the prefix list's
     * rationale and for how the prefixes avoid collisions with other
     * domains.
     *
     * @param  string $slug
     * @return bool
     */
    private function isShareholderPermissionSlug(string $slug): bool
    {
        foreach (self::SHAREHOLDER_PERMISSION_PREFIXES as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve every permission slug referenced by the matrix into its id.
     *
     * Fails loudly if any slug is not present in the `permissions` table —
     * that means 2A.42 has not been run (or was run for a subset).
     *
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    private function resolvePermissionIds(): array
    {
        // Collect the union of all permission slugs referenced by the matrix.
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
                'ShareholderRolesSeeder: the following permission slugs are not '
                . 'present in the `permissions` table: '
                . implode(', ', $missing)
                . '. Run ShareholderPermissionsSeeder (2A.42) first.'
            );
        }

        return $resolved;
    }

    /**
     * Resolve every role slug referenced by the matrix into a Role model.
     *
     * Fails loudly if any role slug is not present in the `roles` table —
     * that means the Phase 1 MasesCoreSeeder has not been run.
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
                'ShareholderRolesSeeder: the following role slugs are not '
                . 'present in the `roles` table: '
                . implode(', ', $missing)
                . '. Run MasesCoreSeeder (Phase 1) first.'
            );
        }

        return $resolved;
    }
}