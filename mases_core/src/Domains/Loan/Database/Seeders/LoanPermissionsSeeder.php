<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;

/**
 * LoanPermissionsSeeder
 *
 * Seeds the fifteen Loan-domain permissions into the platform's
 * permission table.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery. Fifteen permissions, keyed by slug, with
 *          `name_am` and `description_am` seeded as NULL. The
 *          docblock §"The permission slugs and their display names"
 *          recorded the future `name_am` sibling as an extension that
 *          a deployment "would" make; no CR was filed against it
 *          because the platform's precedent had been to defer the
 *          Amharic backfill (see the Shareholder and Wallet domains'
 *          CRs).
 *
 * 1.0.1  — documentation correction. No code change. Corrected a
 *          typographic artefact in the docblock's reference to the
 *          handoff, and recorded the version bump.
 *
 * 1.1.0  — Amharic-first labels (Unit P-3):
 *          · The `PERMISSIONS` constant's value type changes from
 *            `string` (English name only) to
 *            `array{name_am: string, name_en: string}`. The key —
 *            the slug — is unchanged.
 *          · The `run()` loop reads both labels from the entry and
 *            writes both to `updateOrCreate`.
 *          · `name_am` and `description_am` are now supplied with
 *            Amharic values instead of `null`. The description
 *            mirrors the name (the file's reuse-name policy,
 *            unchanged from 1.0.0).
 *          · The fifteen slugs, the English labels, the `CATEGORY`
 *            constant (`'loan'`), the `SCOPE` constant (`'tenant'`),
 *            and the other fields written by `updateOrCreate` are
 *            all unchanged from 1.0.1. Only the two Amharic columns
 *            differ.
 *          · The docblock §"Amharic labels" is added to record the
 *            closure. The docblock §"The permission slugs and their
 *            display names" is updated to state that the array now
 *            carries the `name_am` sibling the 1.0.0 text described
 *            as future work.
 *          · MINOR version bump because the change is additive
 *            (labels supplied) without altering the permission set
 *            or the seeder's public surface.
 *
 * =============================================================================
 * File placement
 * =============================================================================
 * Per the platform-wide convention, this file lives INSIDE the Loan
 * module at `mases_core/src/Domains/Loan/Database/Seeders/` and carries
 * the namespace `MaaleArna\MASES\Core\Domains\Loan\Database\Seeders`.
 * The layout mirrors the Shareholder domain's
 * `Shareholder\Tests\Feature\*` and the Wallet domain's
 * `Wallet\Database\Seeders\WalletPermissionsSeeder` (2B.55).
 *
 * =============================================================================
 * The Permission model FQCN
 * =============================================================================
 * The Permission model lives in the platform's `Authorization` domain,
 * not the `Auth` domain:
 *
 *     MaaleArna\MASES\Core\Authorization\Models\Permission
 *
 * This is confirmed by every Phase 2A and Phase 2B test suite that
 * creates permissions via a `createXxxPermission()` helper. The
 * Shareholder domain's `TenantIsolationTest`, `CertificateTest`,
 * `ShareHoldingTest`, and `ShareholderRegistryTest`; the Wallet domain's
 * `TenantIsolationTest`, `AllocationTest`, `StatementTest`,
 * `PaymentTest`, `ObligationTest`, and `WalletTest` all import
 * `Permission` from `Core\Authorization\Models`.
 *
 * The `Auth` namespace (`Core\Auth\Models\User`) contains the `User`
 * model, not the `Permission` model. Do NOT confuse the two.
 *
 * =============================================================================
 * The fifteen permissions (frozen by B7)
 * =============================================================================
 * The list is frozen verbatim by the Phase 2C decision register (B7). The
 * frozen RBAC matrix assigns them to roles as follows:
 *
 *   super_admin    → all 15
 *   platform_admin → 13 (excludes `loan.writeoff` and
 *                        `loan.collateral.release`)
 *   tenant_admin   → 9  (view, apply, approve, reject, disburse, repay,
 *                        schedule.view, statement.view, collateral.view)
 *   manager        → 4  (view_own, schedule.view, statement.view_own,
 *                        collateral.view)
 *   shareholder    → 4  (view_own, apply, schedule.view,
 *                        statement.view_own)
 *
 * This seeder only CREATES the permissions; the role-to-permission
 * assignment is `LoanRolesSeeder`'s (A.87) concern.
 *
 * =============================================================================
 * The permission slugs and their display names
 * =============================================================================
 * The fifteen slugs and their bilingual labels, in the order this
 * seeder creates them:
 *
 *   loan.view                 View loans
 *                             ብድሮችን ማየት
 *   loan.view_own             View own loans
 *                             የራስን ብድሮች ማየት
 *   loan.apply                Apply for a loan
 *                             ብድር ማመልከት
 *   loan.approve              Approve a loan
 *                             ብድር ማጽደቅ
 *   loan.reject               Reject a loan
 *                             ብድር ውድቅ ማድረግ
 *   loan.disburse             Disburse a loan
 *                             ብድር መልቀቅ
 *   loan.repay                Record a loan repayment
 *                             የብድር ክፍያ መመዝገብ
 *   loan.restructure          Restructure a loan
 *                             ብድር እንደገና ማዋቀር
 *   loan.writeoff             Write off a loan
 *                             ብድር መሰረዝ
 *   loan.schedule.view        View a loan's schedule
 *                             የብድር መርሐግብር ማየት
 *   loan.statement.view       View a loan's statement
 *                             የብድር መግለጫ ማየት
 *   loan.statement.view_own   View own loan statements
 *                             የራስን የብድር መግለጫ ማየት
 *   loan.collateral.view      View loan collateral
 *                             የብድር ዋስትና ማየት
 *   loan.collateral.manage    Register loan collateral
 *                             የብድር ዋስትና መመዝገብ
 *   loan.collateral.release   Release loan collateral
 *                             የብድር ዋስትና መልቀቅ
 *
 * The display names are the platform's canonical English names, and the
 * Amharic labels are the domain's canonical Amharic names. The bilingual
 * pair follows the convention used elsewhere in the platform (handoff
 * §3, "Bilingual (Amharic-first) convention").
 *
 * =============================================================================
 * Amharic labels
 * =============================================================================
 * Every permission's `name_am` and `description_am` are seeded with
 * Amharic values. The platform's §2.14 (Amharic-first) rule governs:
 * Amharic is the primary display language, English is the fallback. The
 * resource layer reads `name_am` first and falls back to `name_en` only
 * when the Amharic value is empty.
 *
 * The Amharic strings were chosen to match the platform's existing
 * vocabulary. The financial verbs use the standard Amharic register
 * (loanword-free where a native term is available); the noun forms
 * (`ብድር`, `መርሐግብር`, `መግለጫ`, `ዋስትና`) are the domain's established
 * terms. The "own" variants use the possessor-suffix prefix `የራስን`
 * to mirror the Shareholder domain's choice (Unit P-1) and the Wallet
 * domain's (Unit P-2).
 *
 * A full list of the Amharic term choices, with rationale, is in the
 * Unit P-3 delivery that introduced them. The choices are flagged for
 * native-speaker review; adjustments would be a follow-up CR that
 * touches only this file's `name_am` values.
 *
 * =============================================================================
 * Idempotency — updateOrCreate, not create
 * =============================================================================
 * The seeder uses `Permission::updateOrCreate()` keyed on the `slug`
 * column. The choice is deliberate:
 *
 *   - A re-run of the seeder MUST NOT fail. `create()` would raise a
 *     unique-constraint violation on the second run; `updateOrCreate()`
 *     upserts the row idempotently.
 *
 *   - A re-run of the seeder MUST NOT create duplicates. The unique
 *     constraint on `permissions.slug` (the platform's convention, per
 *     every Phase 2A and Phase 2B seeder) guarantees uniqueness;
 *     `updateOrCreate()` respects it.
 *
 *   - A re-run MUST NOT clobber the row's `id`. `updateOrCreate()` updates
 *     in place, so any foreign key referencing the permission's primary
 *     key (e.g. a role-permission pivot) remains valid.
 *
 *   - A display-name change in a future CR is reflected by re-running the
 *     seeder. `updateOrCreate()` updates the `name_am` and `name_en`
 *     columns in place.
 *
 * This mirrors the pattern established by `ShareholderPermissionsSeeder`
 * (2A.44) and `WalletPermissionsSeeder` (2B.55).
 *
 * =============================================================================
 * The `name_en` column, not `name`
 * =============================================================================
 * The sample test fixtures write to the `name_en` column:
 *
 *     Permission::create([
 *         'slug'           => $slug,
 *         'name_am'        => null,
 *         'name_en'        => $label,
 *         'description_am' => null,
 *         'description_en' => $label,
 *         'category'       => $category,
 *         'scope'          => 'tenant',
 *         'tenant_id'      => null,
 *         'application_id' => null,
 *         'is_active'      => true,
 *         'is_system'      => true,
 *     ]);
 *
 * The `permissions` table carries the bilingual pair `name_am` /
 * `name_en`, not a single `name` column. This seeder matches the
 * sample fixtures' shape exactly: it writes both labels and supplies
 * the Amharic pair.
 *
 * The other columns written by the sample fixtures — `description_am`,
 * `description_en`, `category`, `scope`, `tenant_id`, `application_id`,
 * `is_active`, `is_system` — are also supplied so that the seeder's
 * rows match the shape the test fixtures use and any platform-level
 * permission-sync logic that expects them is satisfied.
 *
 * The `category` value is `'loan'` for every row. The `scope` is
 * `'tenant'` (the frozen scope for all Loan permissions). The
 * `tenant_id` and `application_id` are null (platform-wide
 * permissions). `is_active` and `is_system` are both true.
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT create roles. Roles are `LoanRolesSeeder`'s (A.87)
 *     concern.
 *
 *   - It does NOT assign permissions to roles. Same reasoning as (1).
 *
 *   - It does NOT delete stale permissions. A permission that is
 *     removed from the frozen list in a future CR would need a
 *     dedicated data-migration plan (a role reassignment and a cascade
 *     analysis), not an unconditional delete in a seeder. The seeder's
 *     job is to ensure the current set exists, not to prune the set.
 *
 *   - It does NOT touch other domains' permissions. Every slug is
 *     prefixed with `loan.`; the seeder makes no other domain's rows.
 *
 *   - It does NOT seed the `shareholder` role. The role is created by
 *     A.87; this seeder only creates the permissions the role will
 *     reference.
 *
 *   - It does NOT assume any column beyond the ones the sample test
 *     fixtures use. If the platform's `permissions` table declares
 *     additional non-nullable columns without defaults, the
 *     `updateOrCreate()` payload must be extended; the sample fixtures
 *     provide the canonical shape.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — B7 (RBAC, the frozen
 *                                                  15-permission list
 *                                                  and the 45 role
 *                                                  assignments)
 *   Phase 2C Clarification Response, 2026-09-12  — C1 (dedicated
 *                                                  `shareholder` role)
 *   Phase 2C A.87                                — `LoanRolesSeeder` (the
 *                                                  companion that
 *                                                  assigns these
 *                                                  permissions to roles)
 *   Phase 2A 2A.44                               — `ShareholderPermissionsSeeder`
 *                                                  (the shape precedent)
 *   Phase 2B 2B.55                               — `WalletPermissionsSeeder`
 *                                                  (the direct sibling)
 *   Phase 2A / 2B test suites                    — the source of truth
 *                                                  for the `Permission`
 *                                                  FQCN and the
 *                                                  `permissions` table
 *                                                  column shape
 *   Step 5 (manifest) A.86                       — This file
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Database\Seeders
 * @version 1.1.0
 */
final class LoanPermissionsSeeder extends Seeder
{
    /**
     * The fifteen permissions, keyed by slug.
     *
     * Each value is the bilingual label pair:
     *
     *     name_am — the display name in Amharic. Primary per §2.14.
     *     name_en — the display name in Latin script. Fallback.
     *
     * The array's order matches the order documented in the class
     * docblock §"The permission slugs and their display names" — the
     * frozen B7 order, reproduced for administrative readability. The
     * order is presentation-only; no logic depends on it.
     *
     * @var array<string, array{name_am: string, name_en: string}>
     */
    private const PERMISSIONS = [
        'loan.view' => [
            'name_am' => 'ብድሮችን ማየት',
            'name_en' => 'View loans',
        ],
        'loan.view_own' => [
            'name_am' => 'የራስን ብድሮች ማየት',
            'name_en' => 'View own loans',
        ],
        'loan.apply' => [
            'name_am' => 'ብድር ማመልከት',
            'name_en' => 'Apply for a loan',
        ],
        'loan.approve' => [
            'name_am' => 'ብድር ማጽደቅ',
            'name_en' => 'Approve a loan',
        ],
        'loan.reject' => [
            'name_am' => 'ብድር ውድቅ ማድረግ',
            'name_en' => 'Reject a loan',
        ],
        'loan.disburse' => [
            'name_am' => 'ብድር መልቀቅ',
            'name_en' => 'Disburse a loan',
        ],
        'loan.repay' => [
            'name_am' => 'የብድር ክፍያ መመዝገብ',
            'name_en' => 'Record a loan repayment',
        ],
        'loan.restructure' => [
            'name_am' => 'ብድር እንደገና ማዋቀር',
            'name_en' => 'Restructure a loan',
        ],
        'loan.writeoff' => [
            'name_am' => 'ብድር መሰረዝ',
            'name_en' => 'Write off a loan',
        ],
        'loan.schedule.view' => [
            'name_am' => 'የብድር መርሐግብር ማየት',
            'name_en' => 'View a loan\'s schedule',
        ],
        'loan.statement.view' => [
            'name_am' => 'የብድር መግለጫ ማየት',
            'name_en' => 'View a loan\'s statement',
        ],
        'loan.statement.view_own' => [
            'name_am' => 'የራስን የብድር መግለጫ ማየት',
            'name_en' => 'View own loan statements',
        ],
        'loan.collateral.view' => [
            'name_am' => 'የብድር ዋስትና ማየት',
            'name_en' => 'View loan collateral',
        ],
        'loan.collateral.manage' => [
            'name_am' => 'የብድር ዋስትና መመዝገብ',
            'name_en' => 'Register loan collateral',
        ],
        'loan.collateral.release' => [
            'name_am' => 'የብድር ዋስትና መልቀቅ',
            'name_en' => 'Release loan collateral',
        ],
    ];

    /**
     * The `category` value recorded on every Loan permission.
     *
     * Matches the sample fixtures' convention (the Shareholder fixtures
     * use `'shareholder'`, the Certificate fixtures use `'certificate'`,
     * the Wallet fixtures use `'wallet'`).
     */
    private const CATEGORY = 'loan';

    /**
     * The `scope` value recorded on every Loan permission.
     *
     * `'tenant'` — every Loan permission is tenant-scoped, matching the
     * sample fixtures' convention.
     */
    private const SCOPE = 'tenant';

    /**
     * Run the seeder.
     *
     * Every permission in the frozen list is upserted by slug. The
     * operation is idempotent: a re-run updates the display names and
     * the metadata in place and preserves the row's primary key, so any
     * existing role-permission pivot row remains valid.
     *
     * @return void
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $labels) {
            Permission::updateOrCreate(
                ['slug' => $slug],
                [
                    'name_am'        => $labels['name_am'],
                    'name_en'        => $labels['name_en'],

                    // Reuse-name policy: the description mirrors the name
                    // in both languages. Amharic is primary per §2.14.
                    'description_am' => $labels['name_am'],
                    'description_en' => $labels['name_en'],

                    'category'       => self::CATEGORY,
                    'scope'          => self::SCOPE,
                    'tenant_id'      => null,
                    'application_id' => null,
                    'is_active'      => true,
                    'is_system'      => true,
                ],
            );
        }

        $this->command->info(
            'Loan permissions seeded: ' . count(self::PERMISSIONS) . ' entries (Amharic-first).'
        );
    }
}