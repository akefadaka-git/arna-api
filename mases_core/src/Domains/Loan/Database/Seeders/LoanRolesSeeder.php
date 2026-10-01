<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use LogicException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;

/**
 * LoanRolesSeeder
 *
 * Assigns the fifteen Loan-domain permissions to the five roles in the
 * frozen RBAC matrix (B7), and creates the `shareholder` role required
 * by decision C1.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention, this file lives INSIDE the Loan
 * module at `mases_core/src/Domains/Loan/Database/Seeders/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Database\Seeders`.
 *
 * =============================================================================
 * The Permission and Role model FQCNs
 * =============================================================================
 * Both models live in the platform's `Authorization` domain:
 *
 *     MaaleArna\MASES\Core\Authorization\Models\Permission
 *     MaaleArna\MASES\Core\Authorization\Models\Role
 *
 * The `Permission` FQCN is confirmed directly by every Phase 2A and
 * Phase 2B test suite that creates permissions via a
 * `createXxxPermission()` helper. The Shareholder domain's
 * `TenantIsolationTest`, `CertificateTest`, `ShareHoldingTest`, and
 * `ShareholderRegistryTest`; the Wallet domain's `TenantIsolationTest`,
 * `AllocationTest`, `StatementTest`, `PaymentTest`, `ObligationTest`,
 * and `WalletTest` all import `Permission` from `Core\Authorization\Models`.
 *
 * The `Role` FQCN is inferred by symmetry with `Permission` — the two
 * models are siblings in the same domain. The sample test suites do not
 * import `Role` directly (their fixtures attach permissions to users,
 * not to roles), so the `Role` FQCN is not directly confirmed by the
 * samples. If the platform's `Role` model actually lives in a different
 * namespace, adjust the `use` statement accordingly.
 *
 * The `Auth` namespace (`Core\Auth\Models\User`) contains the `User`
 * model, not the `Permission` or `Role` models. Do NOT confuse the two
 * namespaces.
 *
 * =============================================================================
 * THE CROSS-DOMAIN HAZARD (F-2A43-1) — READ BEFORE MODIFYING
 * =============================================================================
 * A naïve implementation of this seeder would call
 * `$role->permissions()->sync($loanPermissionIds)`, intending to set the
 * role's Loan-domain permissions. That call is DESTRUCTIVE:
 *
 *     `sync()` REPLACES the entire pivot set for the role. Any permission
 *     the role currently holds from ANOTHER domain (Shareholder, Wallet,
 *     Core, or any future phase) is SILENTLY DETACHED.
 *
 * This hazard was first observed in Phase 2A (hence the identifier
 * F-2A43-1, referring to the file that first hit it — the shareholder
 * seeder 2A.43) and is repeated here because this seeder is on the same
 * code path. Concretely:
 *
 *   - The `tenant_admin` role holds Shareholder-domain permissions
 *     (`shareholder.view`, `shareholder.manage`, …) and Wallet-domain
 *     permissions (`wallet.view`, `wallet.open`, …), seeded by the
 *     Phase 2A and Phase 2B seeders.
 *
 *   - Calling `sync($loanIds)` on `tenant_admin` would detach EVERY
 *     non-Loan permission from the role, leaving the tenant_admin able
 *     to manage loans but unable to open a wallet or view a shareholder.
 *
 *   - The failure is silent: no exception, no log entry, no test
 *     failure by default. The bug is only observed when a caller who
 *     should have held the detached permission receives a 403.
 *
 * The correct pattern — and the one this seeder implements — is to
 * COMPUTE the target set as the union of (a) the role's CURRENT
 * non-Loan permissions and (b) the role's FROZEN Loan permissions, then
 * call `sync()` with that composite set. The composite set preserves
 * every cross-domain assignment while still REMOVING any stale Loan
 * permissions a role may hold from a prior, outdated seeder run.
 *
 * Concretely, the seeder's algorithm for each role is:
 *
 *     1. Load the role.
 *     2. Load the role's current permission IDs.
 *     3. Partition the current IDs into "Loan" (slug starts with
 *        `loan.`) and "non-Loan" (everything else).
 *     4. Compute the target Loan set from the frozen matrix.
 *     5. Compute the composite set = non-Loan IDs ∪ target Loan IDs.
 *     6. `sync(compositeSet)`.
 *
 * Step 3's partitioning is what makes step 6 safe: any non-Loan
 * permission the role holds is preserved by being part of the composite
 * set. Step 5's "target Loan set" is authoritative — any stale Loan
 * permission the role holds from a prior run is dropped because it is
 * NOT in the target set.
 *
 * A future contributor MUST NOT simplify this algorithm back to a bare
 * `sync($loanIds)`. The simplification would re-introduce F-2A43-1.
 *
 * =============================================================================
 * The frozen RBAC matrix (B7)
 * =============================================================================
 * The matrix assigns the fifteen permissions to five roles. The total
 * is 45 assignments:
 *
 *   super_admin    → all 15 permissions
 *
 *   platform_admin → 13 permissions
 *                    (all EXCEPT `loan.writeoff` and
 *                    `loan.collateral.release`)
 *
 *   tenant_admin   → 9 permissions
 *                    (loan.view, loan.apply, loan.approve,
 *                     loan.reject, loan.disburse, loan.repay,
 *                     loan.schedule.view, loan.statement.view,
 *                     loan.collateral.view)
 *
 *   manager        → 4 permissions
 *                    (loan.view_own, loan.schedule.view,
 *                     loan.statement.view_own, loan.collateral.view)
 *
 *   shareholder    → 4 permissions
 *                    (loan.view_own, loan.apply,
 *                     loan.schedule.view, loan.statement.view_own)
 *
 * The `shareholder` role is NEW in Phase 2C (decision C1). It does not
 * exist in Phase 2A or Phase 2B; this seeder creates it if absent. The
 * role's four permissions give a borrower visibility into their own
 * loans and the ability to apply for a new one, but no authority over
 * other borrowers' loans.
 *
 * =============================================================================
 * Idempotency — firstOrCreate + composite sync
 * =============================================================================
 * The seeder is idempotent:
 *
 *   - The `shareholder` role's creation uses `Role::firstOrCreate()`,
 *     keyed on the `slug` column. A re-run reuses the existing row
 *     rather than attempting a duplicate insert.
 *
 *   - The permission assignments use the composite-`sync()` pattern
 *     documented above. A re-run recomputes the same composite set and
 *     produces no pivot change.
 *
 * A re-run after a permission-set change in a future CR produces the
 * correct delta: newly-added permissions are attached, removed
 * permissions are detached, and cross-domain assignments are preserved.
 *
 * =============================================================================
 * The `shareholder` role's column shape
 * =============================================================================
 * The `firstOrCreate()` call supplies a minimal safe payload:
 *
 *     'name_am'   => null,
 *     'name_en'   => 'Shareholder',
 *     'is_active' => true,
 *     'is_system' => true,
 *
 * This mirrors the sample test fixtures' pattern for `Permission`
 * creation — the bilingual `name_am` / `name_en` pair plus the
 * `is_active` / `is_system` flags — but narrowed to the columns most
 * likely present on a `roles` table. The `Permission`-specific columns
 * (`category`, `scope`, `tenant_id`, `application_id`,
 * `description_am`, `description_en`) are NOT written to the `Role`
 * row, because they are not meaningful for a role.
 *
 * If the platform's `roles` table requires additional NOT NULL columns
 * without defaults, the payload must be extended. The deployment notes
 * flag this assumption.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT create permissions. Those are `LoanPermissionsSeeder`'s
 *     (A.86) concern. This seeder assumes the fifteen permissions exist
 *     by slug and resolves them.
 *
 *   - It does NOT create the four pre-existing roles (`super_admin`,
 *     `platform_admin`, `tenant_admin`, `manager`). Those are created by
 *     the platform's Phase 1 RBAC seeder. This seeder assumes they exist
 *     and resolves them by slug.
 *
 *   - It does NOT delete the `shareholder` role on rollback. A seeder is
 *     not reversed; a deployment that needs to remove the role issues a
 *     dedicated CR.
 *
 *   - It does NOT touch any non-Loan permission. See §"THE CROSS-DOMAIN
 *     HAZARD".
 *
 *   - It does NOT use `syncWithoutDetaching()`. That method would ADD
 *     the target Loan permissions but would NOT remove stale Loan
 *     permissions from a prior run. The composite-`sync()` pattern is
 *     strictly more correct: it adds, preserves, and removes as needed.
 *
 *   - It does NOT log per-role changes. The seeder is silent; a future
 *     CR that wants verbose output adds it at that time.
 *
 *   - It does NOT verify the frozen list of fifteen permissions is
 *     complete in the database. If a permission is missing (because
 *     `LoanPermissionsSeeder` (A.86) has not run), the seeder throws a
 *     `LogicException` naming the missing slugs. The correct deployment
 *     order is A.86 before A.87.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC)
 *   Phase 2C Clarification Response, 2026-09-12  — C1 (shareholder role)
 *   Phase 2C A.86                                — `LoanPermissionsSeeder`
 *   Phase 2A 2A.43                               — the seeder that first
 *                                                  hit the cross-domain
 *                                                  hazard (F-2A43-1)
 *   Phase 2A 2A.44                               — `ShareholderPermissionsSeeder`
 *   Phase 2B 2B.55                               — `WalletPermissionsSeeder`
 *   Phase 2A / 2B test suites                    — the source of truth
 *                                                  for the `Permission`
 *                                                  and `Role` FQCNs and
 *                                                  for the `permissions`
 *                                                  table column shape
 *   Step 5 (manifest) A.87                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Database\Seeders
 * @version 1.0.1
 */
final class LoanRolesSeeder extends Seeder
{
    /**
     * The slug of the `shareholder` role created by this seeder.
     *
     * Decision C1 declares the role's existence; this seeder is the
     * Phase 2C code path that introduces it.
     */
    private const SHAREHOLDER_ROLE_SLUG = 'shareholder';

    /**
     * The English display name of the `shareholder` role.
     */
    private const SHAREHOLDER_ROLE_NAME_EN = 'Shareholder';

    /**
     * The prefix that identifies a Loan-domain permission.
     *
     * Used to partition a role's current permissions into Loan-domain
     * and non-Loan-domain sets, so that the composite sync preserves
     * the non-Loan permissions. See §"THE CROSS-DOMAIN HAZARD".
     */
    private const LOAN_PERMISSION_PREFIX = 'loan.';

    /**
     * The frozen RBAC matrix.
     *
     * The outer key is the role slug. The value is a proper list of
     * permission slugs the role is assigned.
     *
     * Every permission in the lists MUST exist in the `permissions`
     * table by slug; the seeder resolves each via a
     * `Permission::where('slug', ...)` lookup. A missing permission
     * would produce a `LogicException` — see §"The frozen RBAC
     * matrix" and the class docblock of A.86 for the deployment
     * ordering.
     *
     * The list's order is presentation-only; the seeder's operations
     * are set-based.
     *
     * @var array<string, list<string>>
     */
    private const ROLE_MATRIX = [
        // ---------------------------------------------------------------------
        // super_admin — all fifteen.
        // ---------------------------------------------------------------------
        'super_admin' => [
            'loan.view',
            'loan.view_own',
            'loan.apply',
            'loan.approve',
            'loan.reject',
            'loan.disburse',
            'loan.repay',
            'loan.restructure',
            'loan.writeoff',
            'loan.schedule.view',
            'loan.statement.view',
            'loan.statement.view_own',
            'loan.collateral.view',
            'loan.collateral.manage',
            'loan.collateral.release',
        ],

        // ---------------------------------------------------------------------
        // platform_admin — thirteen (excludes writeoff and
        // collateral.release).
        // ---------------------------------------------------------------------
        'platform_admin' => [
            'loan.view',
            'loan.view_own',
            'loan.apply',
            'loan.approve',
            'loan.reject',
            'loan.disburse',
            'loan.repay',
            'loan.restructure',
            'loan.schedule.view',
            'loan.statement.view',
            'loan.statement.view_own',
            'loan.collateral.view',
            'loan.collateral.manage',
        ],

        // ---------------------------------------------------------------------
        // tenant_admin — nine.
        // ---------------------------------------------------------------------
        'tenant_admin' => [
            'loan.view',
            'loan.apply',
            'loan.approve',
            'loan.reject',
            'loan.disburse',
            'loan.repay',
            'loan.schedule.view',
            'loan.statement.view',
            'loan.collateral.view',
        ],

        // ---------------------------------------------------------------------
        // manager — four.
        // ---------------------------------------------------------------------
        'manager' => [
            'loan.view_own',
            'loan.schedule.view',
            'loan.statement.view_own',
            'loan.collateral.view',
        ],

        // ---------------------------------------------------------------------
        // shareholder — four (NEW role per C1).
        // ---------------------------------------------------------------------
        self::SHAREHOLDER_ROLE_SLUG => [
            'loan.view_own',
            'loan.apply',
            'loan.schedule.view',
            'loan.statement.view_own',
        ],
    ];

    /**
     * Run the seeder.
     *
     * See the class docblock §"THE CROSS-DOMAIN HAZARD" for the
     * algorithm's rationale, and §"Idempotency" for the re-run
     * behavior.
     *
     * @return void
     */
    public function run(): void
    {
        $this->ensureShareholderRoleExists();

        $permissionIdsBySlug = $this->resolvePermissionIdsBySlug();

        foreach (self::ROLE_MATRIX as $roleSlug => $loanPermissionSlugs) {
            $role = $this->resolveRole($roleSlug);

            $this->assignLoanPermissionsToRole(
                $role,
                $loanPermissionSlugs,
                $permissionIdsBySlug,
            );
        }
    }

    // =========================================================================
    // Private — role creation and resolution
    // =========================================================================

    /**
     * Ensure the `shareholder` role exists.
     *
     * The `shareholder` role is new in Phase 2C (C1). The method is
     * idempotent: a re-run reuses the existing row.
     *
     * The payload writes only the columns most likely present on the
     * `roles` table — the bilingual name pair plus the two flags. See
     * the class docblock §"The `shareholder` role's column shape".
     *
     * @return void
     */
    private function ensureShareholderRoleExists(): void
    {
        Role::firstOrCreate(
            ['slug' => self::SHAREHOLDER_ROLE_SLUG],
            [
                'name_am'   => null,
                'name_en'   => self::SHAREHOLDER_ROLE_NAME_EN,
                'is_active' => true,
                'is_system' => true,
            ],
        );
    }

    /**
     * Resolve a role by slug.
     *
     * Throws `LogicException` when the role is missing. A missing
     * pre-existing role (`super_admin`, `platform_admin`,
     * `tenant_admin`, or `manager`) indicates that the platform's
     * Phase 1 RBAC seeder has not run; that is a deployment-order
     * defect, not a caller error, hence `LogicException` rather than
     * a Laravel `ModelNotFoundException`.
     *
     * The `shareholder` role is created by `run()` before this method
     * is called; its resolution therefore never misses.
     *
     * @param string $roleSlug
     * @return Role
     *
     * @throws LogicException
     */
    private function resolveRole(string $roleSlug): Role
    {
        /** @var Role|null $role */
        $role = Role::query()->where('slug', $roleSlug)->first();

        if ($role === null) {
            throw new LogicException(
                sprintf(
                    'LoanRolesSeeder cannot resolve the role "%s". '
                    . 'The platform\'s Phase 1 RBAC seeder must run '
                    . 'before this seeder. The four pre-existing roles '
                    . '(super_admin, platform_admin, tenant_admin, '
                    . 'manager) are created by that seeder; the '
                    . 'shareholder role is created by this seeder '
                    . 'before role assignment begins.',
                    $roleSlug,
                ),
            );
        }

        return $role;
    }

    // =========================================================================
    // Private — permission resolution
    // =========================================================================
    /**
     * Resolve the fifteen Loan-domain permissions by slug.
     *
     * Returns a map from slug to primary key. Throws `LogicException`
     * when a permission is missing — the correct deployment order is
     * `LoanPermissionsSeeder` (A.86) before this seeder.
     *
     * @return array<string, int>
     *
     * @throws LogicException
     */
    private function resolvePermissionIdsBySlug(): array
    {
        $slugs = $this->allLoanPermissionSlugs();

        /** @var Collection<int, Permission> $permissions */
        $permissions = Permission::query()
            ->whereIn('slug', $slugs)
            ->get();

        $bySlug = [];

        foreach ($permissions as $permission) {
            $bySlug[(string) $permission->slug] = (int) $permission->getKey();
        }

        $missing = array_diff($slugs, array_keys($bySlug));

        if ($missing !== []) {
            throw new LogicException(
                sprintf(
                    'LoanRolesSeeder cannot resolve the following Loan '
                    . 'permissions: [%s]. The LoanPermissionsSeeder '
                    . '(A.86) must run before this seeder.',
                    implode(', ', $missing),
                ),
            );
        }

        return $bySlug;
    }

    /**
     * The distinct set of Loan-domain permission slugs referenced by the
     * frozen matrix.
     *
     * Derived from the matrix rather than hard-coded, so that adding a
     * permission to a role's list automatically includes it in the
     * lookup. The set is deduplicated.
     *
     * @return list<string>
     */
    private function allLoanPermissionSlugs(): array
    {
        $seen = [];

        foreach (self::ROLE_MATRIX as $slugs) {
            foreach ($slugs as $slug) {
                $seen[$slug] = true;
            }
        }

        return array_keys($seen);
    }

    // =========================================================================
    // Private — composite sync
    // =========================================================================

    /**
     * Assign the role's frozen Loan-domain permissions via the composite
     * sync pattern.
     *
     * The algorithm:
     *
     *   1. Load the role's current permission IDs.
     *   2. Partition them into Loan-domain IDs (those whose slug
     *      begins with `loan.`) and non-Loan IDs (everything else).
     *   3. Compute the target Loan-domain IDs from the frozen matrix.
     *   4. Compute the composite set = non-Loan IDs ∪ target Loan IDs.
     *   5. `sync(compositeSet)`.
     *
     * See the class docblock §"THE CROSS-DOMAIN HAZARD" for why steps
     * 2 and 4 are essential: they preserve the role's cross-domain
     * permission assignments.
     *
     * @param Role                $role
     * @param list<string>        $loanPermissionSlugs
     * @param array<string, int>  $permissionIdsBySlug
     * @return void
     */
    private function assignLoanPermissionsToRole(
        Role $role,
        array $loanPermissionSlugs,
        array $permissionIdsBySlug,
    ): void {
        // ---------------------------------------------------------------------
        // 1. Load the role's current permission IDs.
        // ---------------------------------------------------------------------
        /** @var Collection<int, Permission> $currentPermissions */
        $currentPermissions = $role->permissions()->get();

        // ---------------------------------------------------------------------
        // 2. Partition the current IDs into Loan-domain and non-Loan.
        // ---------------------------------------------------------------------
        $preservedNonLoanIds = [];

        foreach ($currentPermissions as $permission) {
            $slug = (string) $permission->slug;

            if (!str_starts_with($slug, self::LOAN_PERMISSION_PREFIX)) {
                $preservedNonLoanIds[] = (int) $permission->getKey();
            }
            // Loan-domain permissions the role currently holds are NOT
            // preserved here; they are re-derived from the frozen
            // matrix at step 3. Any Loan permission the role holds that
            // is NOT in the matrix is thereby dropped.
        }

        // ---------------------------------------------------------------------
        // 3. Compute the target Loan-domain IDs from the frozen matrix.
        // ---------------------------------------------------------------------
        $targetLoanIds = [];

        foreach ($loanPermissionSlugs as $slug) {
            $targetLoanIds[] = $permissionIdsBySlug[$slug];
        }

        // ---------------------------------------------------------------------
        // 4. Composite set = preserved non-Loan ∪ target Loan.
        // ---------------------------------------------------------------------
        $composite = array_values(array_unique(
            array_merge($preservedNonLoanIds, $targetLoanIds),
        ));

        // ---------------------------------------------------------------------
        // 5. Sync the composite set.
        // ---------------------------------------------------------------------
        $role->permissions()->sync($composite);
    }
}