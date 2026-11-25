<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use RuntimeException;


/**
 * Synchronizes Governance grants for the managed roles.
 * Preserve all non-Governance permissions when syncing; only the Governance
 * permission set is replaced by the approved role matrix.
 */
final class GovernanceRolesSeeder extends Seeder
{
    /**
     * The exact slug of the Governance application-scoped permission.
     *
     * Checked explicitly by `isGovernancePermissionSlug()` because
     * the string does not begin with the `governance.` prefix that
     * identifies the other 41 Governance slugs.
     */
    private const APPLICATION_PERMISSION_SLUG = 'app.governance.access';

    /**
     * The prefix that identifies a tenant-scoped Governance
     * permission slug.
     *
     * All 41 tenant-scoped Governance permissions begin with this
     * prefix. The 37th (the application-scoped permission) is
     * identified by exact match in `isGovernancePermissionSlug()`.
     */
    private const GOVERNANCE_PERMISSION_PREFIX = 'governance.';

    /**
     * The RBAC matrix.
     *
     * Keys are role slugs; values are arrays of permission slugs
     * that the role should hold. The set is authoritative WITHIN
     * the Governance domain — the composite sync will add missing
     * Governance pivots and remove surplus Governance ones, while
     * leaving every non-Governance pivot untouched.
     *
     * The matrix is an INITIAL matrix. See the file-level
     * change-verification block §"The role → permission matrix" for
     * the reasoning behind each role's grant set.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLE_PERMISSIONS = [

        /*
         * super_admin — role 1
         *
         * Every Governance permission, including the sensitive
         * `governance.declaration.manage`. The role already holds
         * every other domain's full permission set; granting the
         * full Governance set is consistent with that pattern.
         */
        'super_admin' => [
            // Top-level
            'governance.view',
            'governance.manage',

            // Bodies
            'governance.body.view',
            'governance.body.manage',

            // Members
            'governance.member.view',
            'governance.member.manage',

            // Meetings
            'governance.meeting.view',
            'governance.meeting.create',
            'governance.meeting.manage',
            'governance.meeting.approve',

            // Agendas
            'governance.agenda.view',
            'governance.agenda.manage',

            // Attendance
            'governance.attendance.view',
            'governance.attendance.manage',

            // Minutes
            'governance.minute.view',
            'governance.minute.manage',
            'governance.minute.approve',

            // Resolutions
            'governance.resolution.view',
            'governance.resolution.create',
            'governance.resolution.manage',
            'governance.resolution.approve',

            // Actions
            'governance.action.view',
            'governance.action.create',
            'governance.action.manage',
            'governance.action.verify',

            // Delegations
            'governance.delegation.view',
            'governance.delegation.manage',

            // Declarations
            'governance.declaration.view',
            'governance.declaration.manage',

            // Elections
            'governance.election.view',
            'governance.election.manage',
            'governance.election.authorize',
            'governance.election.schedule',
            'governance.election.suspend',
            'governance.election.cancel',
            'governance.election.archive',
            'governance.election.authorize',
            'governance.election.schedule',
            'governance.election.suspend',
            'governance.election.cancel',
            'governance.election.archive',

            // Documents
            'governance.document.view',
            'governance.document.upload',
            'governance.document.download',

            // Reports
            'governance.report.view',
            'governance.report.generate',

            // Application-scoped launcher gate
            'app.governance.access',
        ],

        /*
         * platform_admin — role 2
         *
         * Every Governance permission EXCEPT
         * `governance.declaration.manage`. Conflict-of-interest
         * records are sensitive even to platform administrators;
         * the exclusion mirrors the Shareholder domain's
         * `platform_admin` grant, which excludes
         * `certificate.cancel`.
         */
        'platform_admin' => [
            // Top-level
            'governance.view',
            'governance.manage',

            // Bodies
            'governance.body.view',
            'governance.body.manage',

            // Members
            'governance.member.view',
            'governance.member.manage',

            // Meetings
            'governance.meeting.view',
            'governance.meeting.create',
            'governance.meeting.manage',
            'governance.meeting.approve',

            // Agendas
            'governance.agenda.view',
            'governance.agenda.manage',

            // Attendance
            'governance.attendance.view',
            'governance.attendance.manage',

            // Minutes
            'governance.minute.view',
            'governance.minute.manage',
            'governance.minute.approve',

            // Resolutions
            'governance.resolution.view',
            'governance.resolution.create',
            'governance.resolution.manage',
            'governance.resolution.approve',

            // Actions
            'governance.action.view',
            'governance.action.create',
            'governance.action.manage',
            'governance.action.verify',

            // Delegations
            'governance.delegation.view',
            'governance.delegation.manage',

            // Declarations — read only
            'governance.declaration.view',

            // Elections
            'governance.election.view',
            'governance.election.manage',

            // Documents
            'governance.document.view',
            'governance.document.upload',
            'governance.document.download',

            // Reports
            'governance.report.view',
            'governance.report.generate',

            // Application-scoped launcher gate
            'app.governance.access',
        ],

        /*
         * tenant_admin — role 3
         *
         * The configuration and read set. View on every resource
         * noun; manage on the two configuration-only nouns (bodies,
         * members); download on documents; report generation. No
         * operational, approval, or verification permission —
         * a tenant administrator is a configuration authority, not
         * a governance actor.
         */
        'tenant_admin' => [
            // Top-level
            'governance.view',

            // Bodies — configuration authority
            'governance.body.view',
            'governance.body.manage',

            // Members — configuration authority
            'governance.member.view',
            'governance.member.manage',

            // Read-only on operational resources
            'governance.meeting.view',
            'governance.agenda.view',
            'governance.attendance.view',
            'governance.minute.view',
            'governance.resolution.view',
            'governance.action.view',
            'governance.delegation.view',
            'governance.declaration.view',
            'governance.election.view',

            // Documents — read and download only
            'governance.document.view',
            'governance.document.download',

            // Reports
            'governance.report.view',
            'governance.report.generate',

            // Application-scoped launcher gate
            'app.governance.access',
        ],

        /*
         * manager — role 4
         *
         * The minimum set that lets a manager see the Governance
         * domain exists and manage action items assigned to their
         * team. Managers are responsible for executing governance
         * decisions within their units; they do not attend meetings
         * or approve minutes.
         */
        'manager' => [
            'governance.view',
            'governance.action.view',
            'governance.action.manage',
            'app.governance.access',
        ],

        /*
         * board — role 8
         *
         * The read-only board set. Board members see what happened
         * and what is planned; they do not create, modify, or
         * approve records through the application. Their
         * authoritative acts (adopting resolutions, approving
         * minutes) are governed by `president` and are recorded by
         * `secretary`.
         */
        'board' => [
            'governance.view',
            'governance.body.view',
            'governance.member.view',
            'governance.meeting.view',
            'governance.agenda.view',
            'governance.attendance.view',
            'governance.minute.view',
            'governance.resolution.view',
            'governance.action.view',
            'governance.delegation.view',
            'governance.election.view',
            'governance.document.view',
            'governance.document.download',
            'app.governance.access',
        ],

        /*
         * president — role 9
         *
         * The board set plus five management/approval permissions.
         * The president chairs meetings and approves minutes and
         * resolutions. The president does NOT create meetings or
         * record attendance — those are the secretary's acts.
         */
        'president' => [
            'governance.view',
            'governance.body.view',
            'governance.member.view',

            'governance.meeting.view',
            'governance.meeting.manage',
            'governance.meeting.approve',

            'governance.agenda.view',
            'governance.agenda.manage',

            'governance.attendance.view',

            'governance.minute.view',
            'governance.minute.approve',

            'governance.resolution.view',
            'governance.resolution.approve',

            'governance.action.view',

            'governance.delegation.view',
            'governance.election.view',
            'governance.document.view',
            'governance.document.download',
            'app.governance.access',
        ],

        /*
         * secretary — role 10
         *
         * The board set plus six operational permissions. The
         * secretary is the operational steward of the governance
         * process. The secretary drafts minutes but does NOT approve
         * them; that is the president's or body's act.
         */
        'secretary' => [
            'governance.view',
            'governance.body.view',
            'governance.member.view',

            'governance.meeting.view',
            'governance.meeting.create',
            'governance.meeting.manage',

            'governance.agenda.view',
            'governance.agenda.manage',

            'governance.attendance.view',
            'governance.attendance.manage',

            'governance.minute.view',
            'governance.minute.manage',

            'governance.resolution.view',

            'governance.action.view',

            'governance.delegation.view',
            'governance.election.view',

            'governance.document.view',
            'governance.document.upload',
            'governance.document.download',

            'app.governance.access',
        ],

        /*
         * ceo — role 12
         *
         * The read set focused on the CEO's operational interest
         * plus action management. The CEO does not attend board
         * meetings by default; whether the CEO is a Board member
         * (ex-officio or otherwise) is a [BUSINESS DECISION].
         */
        'ceo' => [
            'governance.view',
            'governance.body.view',
            'governance.member.view',
            'governance.meeting.view',
            'governance.agenda.view',
            'governance.minute.view',
            'governance.resolution.view',
            'governance.action.view',
            'governance.action.manage',
            'governance.document.view',
            'governance.delegation.view',
            'app.governance.access',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     *
     * @throws RuntimeException  When a referenced role or permission
     *                           does not exist.
     */
    public function run(): void
    {
        // Pre-resolve every permission slug referenced by the matrix
        // into its primary key. Failing before touching any role
        // ensures we do not partially apply the matrix if a slug is
        // missing.
        $permissionIds = $this->resolvePermissionIds();

        // Pre-resolve every role slug into a Role model. Same
        // rationale.
        $roles = $this->resolveRoles();

        // Apply the matrix.
        foreach (self::ROLE_PERMISSIONS as $roleSlug => $permissionSlugs) {
            $role = $roles[$roleSlug];

            $targetGovernanceIds = array_map(
                static fn (string $slug): int => $permissionIds[$slug],
                $permissionSlugs,
            );

            // Composite sync — preserves the role's non-Governance
            // permissions. See the class docblock
            // §"THE CROSS-DOMAIN HAZARD (F-2A43-1)" for the rationale
            // and the five-step algorithm.
            $this->syncGovernancePermissions($role, $targetGovernanceIds);
        }

        $this->command->info(sprintf(
            'Governance role permissions synced: %d roles, %d total assignments.',
            count(self::ROLE_PERMISSIONS),
            array_sum(array_map('count', self::ROLE_PERMISSIONS)),
        ));
    }

    // =========================================================================
    // Private — composite sync
    // =========================================================================

    /**
     * Apply the role's frozen Governance permission set via the
     * composite-sync pattern.
     *
     * The algorithm is the five-step procedure documented in the
     * class docblock's §"THE CROSS-DOMAIN HAZARD (F-2A43-1)" section:
     *
     *   1. Load the role's current permission IDs.
     *   2. Partition them into Governance-domain IDs and
     *      non-Governance IDs.
     *   3. Compute the target Governance IDs from the frozen matrix.
     *   4. Compute the composite set = non-Governance ∪ target
     *      Governance.
     *   5. `sync(compositeSet)`.
     *
     * Step 2's partition is what makes step 5 safe. Any non-Governance
     * permission the role holds is preserved by being part of the
     * composite set. Step 4's "target Governance set" is authoritative
     * WITHIN the Governance domain — any stale Governance permission
     * the role holds from a prior run is dropped because it is not in
     * the target set.
     *
     * @param  Role            $role
     * @param  array<int, int> $targetGovernanceIds
     * @return void
     */
    private function syncGovernancePermissions(Role $role, array $targetGovernanceIds): void
    {
        // ---------------------------------------------------------------------
        // 1. Load the role's current permission IDs (with slugs).
        // ---------------------------------------------------------------------
        /** @var Collection<int, Permission> $currentPermissions */
        $currentPermissions = $role->permissions()->get(['id', 'slug']);

        // ---------------------------------------------------------------------
        // 2. Partition: Governance-domain IDs vs. non-Governance IDs.
        // ---------------------------------------------------------------------
        $preservedNonGovernanceIds = [];

        foreach ($currentPermissions as $permission) {
            $slug = (string) $permission->slug;

            if (!$this->isGovernancePermissionSlug($slug)) {
                // Non-Governance permission — preserve it.
                $preservedNonGovernanceIds[] = (int) $permission->getKey();
            }
            // Governance permissions are NOT preserved here. They are
            // re-derived from the frozen matrix at step 4. Any
            // Governance permission the role holds that is NOT in the
            // matrix is thereby dropped (self-correcting for a CR that
            // removes a permission from the frozen set).
        }

        // ---------------------------------------------------------------------
        // 3. Target Governance IDs — passed in.
        // ---------------------------------------------------------------------
        // $targetGovernanceIds is the caller-supplied array. See the
        // caller (run()) for how it is built.

        // ---------------------------------------------------------------------
        // 4. Composite set = preserved non-Governance ∪ target
        //    Governance.
        // ---------------------------------------------------------------------
        $composite = array_values(array_unique(
            array_merge($preservedNonGovernanceIds, $targetGovernanceIds),
        ));

        // ---------------------------------------------------------------------
        // 5. Sync the composite set.
        // ---------------------------------------------------------------------
        $role->permissions()->sync($composite);
    }

    /**
     * Determine whether a permission slug belongs to the Governance
     * domain.
     *
     * A slug is a Governance permission if and only if:
     *
     *   · it begins with the prefix `governance.`, OR
     *   · it equals the exact string `app.governance.access`.
     *
     * The two conditions are equivalent to "the slug identifies a
     * permission that GovernancePermissionsSeeder (G1 file 7 of 10)
     * registered". See the file-level change-verification block
     * §"The Governance-domain prefix set" for the reasoning and for
     * the collision check against every other domain's slugs.
     *
     * @param  string $slug
     * @return bool
     */
    private function isGovernancePermissionSlug(string $slug): bool
    {
        if ($slug === self::APPLICATION_PERMISSION_SLUG) {
            return true;
        }

        return str_starts_with($slug, self::GOVERNANCE_PERMISSION_PREFIX);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve every permission slug referenced by the matrix into
     * its id.
     *
     * Fails loudly if any slug is not present in the `permissions`
     * table — that means GovernancePermissionsSeeder (G1 file 7 of
     * 10) has not been run (or was run for a subset).
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
                'GovernanceRolesSeeder: the following permission slugs are not '
                . 'present in the `permissions` table: '
                . implode(', ', $missing)
                . '. Run GovernancePermissionsSeeder (G1 file 7 of 10) first.'
            );
        }

        return $resolved;
    }

    /**
     * Resolve every role slug referenced by the matrix into a Role
     * model.
     *
     * Fails loudly if any role slug is not present in the `roles`
     * table — that means the Phase 1 MasesCoreSeeder has not been
     * run, or the role was removed by a later CR.
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
                'GovernanceRolesSeeder: the following role slugs are not '
                . 'present in the `roles` table: '
                . implode(', ', $missing)
                . '. Run MasesCoreSeeder (Phase 1) first.'
            );
        }

        return $resolved;
    }
}