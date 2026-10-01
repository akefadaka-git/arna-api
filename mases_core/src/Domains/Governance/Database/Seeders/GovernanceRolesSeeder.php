<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G1 · File 8 of 10
 * =============================================================================
 *
 * File:            Database/Seeders/GovernanceRolesSeeder.php  (NEW)
 * Unit:            G1 — Governance domain foundation (role grants)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `role_has_permissions` pivot
 *   contained the platform's pre-existing grants for twelve roles:
 *   `super_admin` (all 75 permissions), `platform_admin`,
 *   `tenant_admin`, `manager`, `shareholder`, `board`,
 *   `president`, `secretary`, `cashier`, `ceo`, and two
 *   application-scoped manager roles. None of the 37 Governance
 *   permissions seeded by G1 file 7 was yet granted to any role.
 *   Consequently, the ping endpoint's
 *   `auth.permission:governance.view` middleware denied every
 *   caller, including `super_admin`.
 *
 * After:
 *   One file that grants the appropriate Governance permissions to
 *   eight of the platform's twelve roles, using the composite-sync
 *   algorithm established by `ShareholderRolesSeeder` v1.2.0.
 *
 *   The eight managed roles and their Governance grant counts:
 *
 *     super_admin    (role 1)  →  37  (all)
 *     platform_admin (role 2)  →  36  (all except declaration.manage)
 *     tenant_admin   (role 3)  →  19  (config + read)
 *     manager        (role 4)  →   4  (view + action)
 *     board          (role 8)  →  14  (read-only board set)
 *     president      (role 9)  →  19  (board set + management/approval)
 *     secretary      (role 10) →  20  (board set + operational)
 *     ceo            (role 12) →  12  (read + action management)
 *                                ──
 *                                161 total grant assignments
 *
 *   The four unmanaged roles — `finance_manager` (5), `hr_manager`
 *   (6), `shareholder` (7), and `cashier` (11) — receive no
 *   Governance permissions and are not touched by this seeder.
 *   The master prompt §29 grants `cashier` no Governance
 *   permissions; the other three are outside the Governance
 *   domain's scope. If a future CR decides one of them should
 *   receive a Governance grant, that CR adds the role to the
 *   `ROLE_PERMISSIONS` matrix.
 *
 *   What the seeder deliberately does NOT do:
 *
 *     · It does NOT create any new role. The eight roles it manages
 *       already exist; the seeder grants permissions to them.
 *       Master prompt §27: "Do not create governance_board,
 *       governance_ceo, governance_manager unless a later approved
 *       architecture decision explicitly requires new roles."
 *
 *     · It does NOT delete any permission. Grants outside the
 *       Governance domain's prefix set — every Core, File,
 *       Shareholder, Wallet, and Loan grant the roles currently
 *       hold — are preserved by the composite-sync algorithm.
 *
 *     · It does NOT touch the `permissions` table, the `roles`
 *       table, the `users` table, or any direct user-permission
 *       grant. It writes only to `role_has_permissions`.
 *
 * =============================================================================
 * The composite-sync algorithm — why a bare sync() would be a defect
 * =============================================================================
 * The natural implementation of a per-domain role seeder is:
 *
 *     $role->permissions()->sync($governancePermissionIds);
 *
 * That implementation is WRONG. `sync()` REPLACES the role's entire
 * permission set. Every permission the role holds from another
 * domain — Core, File, Shareholder, Wallet, Loan — is silently
 * detached.
 *
 * The defect was first observed in Phase 2A's Shareholder domain
 * (recorded as F-2A43-1), where it silently wiped the
 * platform-level grants from `platform_admin` and produced the
 * "anomalous permission counts" that Phase 2C's Layer 3 validation
 * later caught. `ShareholderRolesSeeder` was corrected in its own
 * v1.1.0 revision to use the composite-sync pattern; Wallet and
 * Loan followed. This file is the fourth domain to adopt the
 * pattern.
 *
 * The correct algorithm:
 *
 *   1. Load the role's current permission IDs (with slugs).
 *   2. Partition them into:
 *        · governance-domain IDs  — slugs that begin with one of
 *          the Governance prefixes (see below);
 *        · non-governance IDs     — everything else.
 *   3. Compute the target governance IDs from the frozen matrix.
 *   4. Compute the composite set:
 *        composite = preservedNonGovernanceIds ∪ targetGovernanceIds
 *   5. `$role->permissions()->sync($composite)`.
 *
 * Step 2's partition is what makes step 5 safe. Any non-Governance
 * permission the role holds is preserved by being part of the
 * composite set. Step 3's target set is authoritative WITHIN the
 * Governance domain — any stale Governance permission the role
 * holds from a prior run is dropped because it is not in the
 * target set.
 *
 * A future contributor MUST NOT simplify this back to a bare
 * `sync($governancePermissionIds)`. That simplification would
 * re-introduce F-2A43-1 in the Governance domain.
 *
 * =============================================================================
 * The Governance-domain prefix set
 * =============================================================================
 * The following slugs belong to the Governance domain:
 *
 *     governance.*            36 slugs with the `governance.` prefix
 *     app.governance.access    1 slug with an EXACT match
 *
 * The `governance.` prefix is used for the 36 tenant-scoped
 * permissions. The `app.governance.access` slug is the one
 * application-scoped permission; it cannot be identified by the
 * `governance.` prefix alone, so this seeder uses an explicit
 * exact-match check for it in addition to the prefix check.
 *
 * No other domain's slugs collide with these prefixes:
 *
 *   · Core:        `user.`, `role.`, `permission.`, `app.*.access`,
 *                  `file.`, `tenant.`, `test.` — none begin with
 *                  `governance.`. The Core `app.*.access` slugs
 *                  (IDs 17–23) include `app.portal.access` and
 *                  `app.app.access` but NOT `app.governance.access`.
 *   · Shareholder: `shareholder.`, `share_class.`,
 *                  `share_holding.`, `certificate.`
 *   · Wallet:      `wallet.`, `wallet_obligation.`,
 *                  `wallet_payment.`, `wallet_allocation.`,
 *                  `wallet_statement.`
 *   · Loan:        `loan.`, `loan_product.` (if it exists)
 *
 * The `app.` prefix used by all `app.*.access` permissions is NOT
 * treated as a Governance prefix. Only `app.governance.access`
 * specifically is a Governance slug.
 *
 * =============================================================================
 * The role → permission matrix
 * =============================================================================
 * The matrix below follows the master prompt §29's direction. It is
 * an INITIAL matrix — the phase that seeds it (G1) does not have
 * organizational evidence in hand for the precise permission
 * boundaries each role should have. The matrix is defensible as a
 * starting point because:
 *
 *   · It separates read from write from approve.
 *   · It separates operational (secretary) from authoritative
 *     (president) from execution (ceo) from self-service
 *     (manager, board).
 *   · It grants the maximum permission set only to the two
 *     platform-level admin roles whose scope is explicitly
 *     platform-wide.
 *
 * The matrix is subject to revision by a future CR once the
 * organization has approved role definitions in its governance
 * policy. Such a CR amends the `ROLE_PERMISSIONS` constant below;
 * the composite-sync algorithm remains unchanged.
 *
 *   super_admin (role 1)
 *     The platform's highest-privilege role. Receives every
 *     Governance permission, including the sensitive declaration
 *     management permission. The role already holds every other
 *     domain's full permission set; the Governance grant is
 *     consistent with that pattern.
 *
 *   platform_admin (role 2)
 *     Receives every Governance permission EXCEPT
 *     `governance.declaration.manage`. Declaration management is
 *     the one Governance permission whose grant to a
 *     platform-level role is subject to organizational policy —
 *     conflict-of-interest records are sensitive even to platform
 *     administrators. This exclusion mirrors the Shareholder
 *     domain's `platform_admin` grant, which excludes
 *     `certificate.cancel` for a comparable sensitivity reason.
 *
 *   tenant_admin (role 3)
 *     Receives the Governance configuration and read set: view on
 *     every resource noun; manage on the two configuration-only
 *     nouns (bodies, members); download on documents; and the
 *     report generation permission. Does NOT receive any
 *     operational, approval, or verification permission — a
 *     tenant administrator is a configuration authority, not a
 *     governance actor.
 *
 *   manager (role 4)
 *     Receives the minimum set that lets a manager see the
 *     Governance domain exists and manage action items assigned to
 *     their team: `governance.view`, `governance.action.view`,
 *     `governance.action.manage`, and `app.governance.access`.
 *     Managers are responsible for executing governance decisions
 *     within their units; they do not attend meetings or approve
 *     minutes.
 *
 *   board (role 8)
 *     Receives the read-only board set: view on every resource
 *     noun, plus document download. Board members see what happened
 *     and what is planned; they do not create, modify, or approve
 *     records through the application. Their authoritative acts
 *     (adopting resolutions, approving minutes) are governed by
 *     `president` and are recorded by `secretary`.
 *
 *   president (role 9)
 *     Receives the board set plus five management/approval
 *     permissions:
 *       · governance.meeting.manage
 *       · governance.meeting.approve
 *       · governance.agenda.manage
 *       · governance.minute.approve
 *       · governance.resolution.approve
 *     The president chairs meetings and approves minutes and
 *     resolutions. The president does NOT create meetings or
 *     record attendance — those are the secretary's acts.
 *
 *   secretary (role 10)
 *     Receives the board set plus six operational permissions:
 *       · governance.meeting.create
 *       · governance.meeting.manage
 *       · governance.agenda.manage
 *       · governance.attendance.manage
 *       · governance.minute.manage
 *       · governance.document.upload
 *     The secretary is the operational steward of the governance
 *     process. The secretary drafts minutes but does NOT approve
 *     them; that is the president's or body's act.
 *
 *   ceo (role 12)
 *     Receives a read set focused on the CEO's operational
 *     interest plus action management:
 *       · governance.view
 *       · governance.body.view
 *       · governance.member.view
 *       · governance.meeting.view
 *       · governance.agenda.view
 *       · governance.minute.view
 *       · governance.resolution.view
 *       · governance.action.view
 *       · governance.action.manage
 *       · governance.document.view
 *       · governance.delegation.view
 *       · app.governance.access
 *     The CEO does not attend board meetings by default; whether
 *     the CEO is a Board member (ex-officio or otherwise) is a
 *     [BUSINESS DECISION] (G0 §12 items 15–16). The RBAC grant
 *     here establishes what the CEO CAN do through the
 *     application; whether the CEO has a governance membership
 *     row is a separate, data-level fact.
 *
 * =============================================================================
 * Why the seeder is not registered in Database\Seeders\DatabaseSeeder
 * =============================================================================
 * The Governance seeders (files 7, 8, 9 of this unit) are invoked
 * explicitly, matching the pattern established by the Shareholder,
 * Wallet, and Loan domains. `php artisan db:seed` without a
 * `--class` argument runs only the aggregate `DatabaseSeeder`,
 * which does not include the domain-level seeders. This keeps
 * domain seeders from firing automatically during unrelated
 * operations (fresh installs, test setup, etc.).
 *
 * =============================================================================
 * Deployment order
 * =============================================================================
 * The seeder must run AFTER:
 *
 *   1. GovernanceApplicationSeeder (file 9) — creates the
 *      `platform_applications` row whose id
 *      `app.governance.access` references.
 *
 *   2. GovernancePermissionsSeeder (file 7) — creates the 37
 *      permission rows this seeder grants. This seeder's
 *      `resolvePermissionIds()` step will throw a RuntimeException
 *      naming file 7 as the prerequisite if any slug is missing.
 *
 * The recommended order is:
 *
 *     php artisan db:seed --class="…\\GovernanceApplicationSeeder"
 *     php artisan db:seed --class="…\\GovernancePermissionsSeeder"
 *     php artisan db:seed --class="…\\GovernanceRolesSeeder"
 *
 * Running this seeder out of order is safe: it throws a
 * RuntimeException naming the missing prerequisite before writing
 * any pivot row. No partial state is produced.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * The seeder is idempotent. Running it repeatedly produces the same
 * final state:
 *
 *   · The first run adds the target Governance grants to each
 *     managed role.
 *   · A second run computes the same composite set and calls
 *     `sync()` with it. Because the composite set already equals
 *     the role's current set, `sync()` is a no-op for that role's
 *     Governance grants (it may still re-attach unchanged pivots,
 *     depending on Laravel's sync implementation, but the SET is
 *     identical).
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Seeders/GovernanceRolesSeeder.php
 *
 * Post-deployment verification (after files 7 and 9 have run):
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceRolesSeeder"
 *
 * Expected output:
 *
 *     Governance role permissions synced: 8 roles, 161 total assignments.
 *
 * Verification of one role's grants:
 *
 *     php artisan tinker
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\Role
 *     ...     ::where('slug', 'super_admin')->first()
 *     ...     ->permissions()->where('slug', 'like', 'governance.%')->count()
 *     => 36
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\Role
 *     ...     ::where('slug', 'super_admin')->first()
 *     ...     ->permissions()->where('slug', 'app.governance.access')->exists()
 *     => true
 *
 * Verification that no other-domain grant was disturbed:
 *
 *     php artisan tinker
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\Role
 *     ...     ::where('slug', 'super_admin')->first()
 *     ...     ->permissions()->where('slug', 'loan.approve')->exists()
 *     => true
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Roles managed:             8 (super_admin, platform_admin, tenant_admin,
 *                               manager, board, president, secretary, ceo)
 * Roles not managed:         4 (finance_manager, hr_manager, shareholder,
 *                               cashier)
 * Pivot rows affected:       up to 161 (idempotent)
 * Permission rows added:     0
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use RuntimeException;

/**
 * GovernanceRolesSeeder
 *
 * Assigns the Governance domain's permissions to the platform's
 * pre-existing roles via the `role_has_permissions` pivot.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery (G1 file 8 of 10). Eight managed roles,
 *          161 total grant assignments, composite-sync algorithm
 *          adopted from ShareholderRolesSeeder v1.2.0 to preserve
 *          non-Governance permissions across every role.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * Eight role → permission-set assignments, per the frozen matrix in
 * the `ROLE_PERMISSIONS` constant:
 *
 *   super_admin     → all 37 Governance permissions
 *   platform_admin  → 36 (all except declaration.manage)
 *   tenant_admin    → 19 (config + read)
 *   manager         →  4 (view + action)
 *   board           → 14 (read-only board set)
 *   president       → 19 (board set + management/approval)
 *   secretary       → 20 (board set + operational)
 *   ceo             → 12 (read + action management)
 *
 * Total: 161 grant assignments. See the file-level
 * change-verification block §"The role → permission matrix" for the
 * reasoning behind each grant set.
 *
 * =============================================================================
 * THE CROSS-DOMAIN HAZARD (F-2A43-1) — corrected in this domain by design
 * =============================================================================
 * A bare `$role->permissions()->sync($governanceIds)` call would
 * REPLACE the role's entire permission set, silently detaching
 * every grant the role holds from another domain. This is the
 * defect that was observed and corrected in the Shareholder domain
 * (F-2A43-1) and that Wallet and Loan also corrected in their own
 * seeders.
 *
 * This seeder ships with the corrected composite-sync algorithm
 * from the start. See the file-level change-verification block
 * §"The composite-sync algorithm — why a bare sync() would be a
 * defect" for the five-step procedure.
 *
 * A future contributor MUST NOT simplify this seeder back to a
 * bare `sync()`. Doing so would re-introduce F-2A43-1 in the
 * Governance domain and silently wipe every other domain's grants
 * from the eight managed roles.
 *
 * =============================================================================
 * The Governance-domain prefix set
 * =============================================================================
 * A permission slug is a Governance-domain slug if and only if:
 *
 *   · it begins with the prefix `governance.`, OR
 *   · it equals the exact string `app.governance.access`.
 *
 * The two conditions are implemented in
 * `isGovernancePermissionSlug()` below. See the file-level
 * change-verification block §"The Governance-domain prefix set" for
 * the reasoning and for the collision check against every other
 * domain's slugs.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * Invoke explicitly, after files 7 and 9 have run:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceRolesSeeder"
 *
 * Expected output:
 *
 *     Governance role permissions synced: 8 roles, 161 total assignments.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Database\Seeders
 * @version 1.0.0
 */
final class GovernanceRolesSeeder extends Seeder
{
    /**
     * The exact slug of the Governance application-scoped permission.
     *
     * Checked explicitly by `isGovernancePermissionSlug()` because
     * the string does not begin with the `governance.` prefix that
     * identifies the other 36 Governance slugs.
     */
    private const APPLICATION_PERMISSION_SLUG = 'app.governance.access';

    /**
     * The prefix that identifies a tenant-scoped Governance
     * permission slug.
     *
     * All 36 tenant-scoped Governance permissions begin with this
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