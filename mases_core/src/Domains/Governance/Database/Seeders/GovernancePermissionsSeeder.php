<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G1 · File 7 of 10
 * =============================================================================
 *
 * File:            Database/Seeders/GovernancePermissionsSeeder.php  (NEW)
 * Unit:            G1 — Governance domain foundation (permissions)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. The `permissions` table contained 75
 *   rows (IDs 1–75) covering the Core, File, Shareholder, Wallet,
 *   and Loan domains. No `governance.*` slug existed; no
 *   `app.governance.access` slug existed. The route declared in
 *   G1 file 5 (`GET /api/governance/ping`) carried the middleware
 *   `auth.permission:governance.view`, which resolves that slug
 *   through `AuthorizationService::hasPermission()`. With the slug
 *   absent from the `permissions` table, the middleware denied
 *   every caller — including `super_admin` — because the role
 *   grant in `GovernanceRolesSeeder` (G1 file 8 of 10) had nothing
 *   to grant.
 *
 * After:
 *   One file that registers every Governance-domain permission the
 *   domain will consume from G1 through G13. The seed is
 *   idempotent: a repeated run updates each row in place rather
 *   than inserting duplicates.
 *
 *   What the seeder writes:
 *
 *     · 38 tenant-scoped permissions with the `governance.` prefix.
 *       Each carries `scope = 'tenant'`, `tenant_id = null`,
 *       `application_id = null`, `is_active = true`,
 *       `is_system = true`.
 *
 *     · 1 application-scoped permission `app.governance.access`.
 *       Carries `scope = 'application'`,
 *       `application_id = <resolved at run time>`,
 *       `tenant_id = null`, `is_active = true`,
 *       `is_system = false` — matching the convention used by the
 *       other six `app.*.access` rows, all of which are
 *       `is_system = 0` except `app.admin.access`.
 *
 *   Total: 39 permission rows.
 *
 *   What the seeder deliberately does NOT do:
 *
 *     · It does NOT delete any permission. Rows that a previous
 *       run inserted and a later CR removes from the constant
 *       remain in the database — historical role grants and audit
 *       rows reference them by id. Removing a permission is a
 *       separate CR with its own cleanup procedure.
 *
 *     · It does NOT grant permissions to any role. That is the
 *       sole responsibility of GovernanceRolesSeeder (G1 file 8 of
 *       10), which uses the composite-sync pattern to add
 *       `governance.*` and `app.governance.access` grants without
 *       disturbing any other domain's grants.
 *
 *     · It does NOT alter the `roles` table, the `role_has_permissions`
 *       pivot, or any user's direct permission grants. The seeder
 *       writes only to `permissions`.
 *
 *     · It does NOT depend on the `platform_applications` row
 *       existing at file-upload time. The dependency is resolved at
 *       RUN time by querying the table for `slug = 'governance'`.
 *       If the row is missing, the seeder throws a RuntimeException
 *       naming GovernanceApplicationSeeder (G1 file 9 of 10) as the
 *       prerequisite. This mirrors the resolution pattern
 *       ShareholderRolesSeeder uses for role lookups.
 *
 * =============================================================================
 * Correction of a prior statement
 * =============================================================================
 * Two messages in this session (the G1 file 6 delivery's status
 * block and the G1 file 5 delivery's status block) stated the
 * seeder would register "39 governance.* slugs". That was an
 * arithmetic error on this author's part. The correct count is 36,
 * matching the list in the master prompt §28 as refined during the
 * G0 assessment (which dropped `decision.*` pending the
 * Resolution-vs-Decision business decision, and unified every slug
 * under the `governance.` prefix). The 37-row total is what this
 * seeder writes.
 *
 * =============================================================================
 * The 36-slug inventory (by group)
 * =============================================================================
 * Grouped by the resource noun the slug addresses. Counts:
 *
 *     governance               2  (view, manage)
 *     governance.body          2  (view, manage)
 *     governance.member        2  (view, manage)
 *     governance.meeting       4  (view, create, manage, approve)
 *     governance.agenda        2  (view, manage)
 *     governance.attendance    2  (view, manage)
 *     governance.minute        3  (view, manage, approve)
 *     governance.resolution    4  (view, create, manage, approve)
 *     governance.action        4  (view, create, manage, verify)
 *     governance.delegation    2  (view, manage)
 *     governance.declaration   2  (view, manage)
 *     governance.election      4  (view, manage, vote, certify)
 *     governance.document      3  (view, upload, download)
 *     governance.report        2  (view, generate)
 *                              ──
 *                              36
 *
 * Plus:
 *
 *     app.governance.access    1  (application-scoped launcher gate)
 *                              ──
 *                              37 total
 *
 * The `verify` verb on the action group is the only verb in the
 * inventory that is not one of the four standard verbs (view,
 * create, manage, approve). It exists because the master prompt
 * §21 mandates that action verification is a distinct act from
 * action completion — see the `action_item_statuses` array in
 * `Config/governance.php` and the Amharic catalogue's
 * `action_item_status.verified` label.
 *
 * =============================================================================
 * The application-scoped permission — Option A resolution
 * =============================================================================
 * `app.governance.access` carries `application_id = 8` (the id the
 * future Governance row in `platform_applications` will receive —
 * see G1 file 9). Because a foreign key exists between
 * `permissions.application_id` and `platform_applications.id`, the
 * row cannot be inserted before the application row exists.
 *
 * Two deployment orders are possible:
 *
 *   Option A (this file's design): resolve `application_id` at RUN
 *   time by querying `platform_applications` for `slug = 'governance'`.
 *   If the row is not present, throw a RuntimeException that names
 *   GovernanceApplicationSeeder as the prerequisite.
 *
 *   Option B: hard-code `application_id = 8` in this file and
 *   require the operator to run file 9 before file 7.
 *
 * The G1 approval chose Option A. The advantage is that the
 * seeder's failure mode is self-describing: an operator who runs
 * file 7 before file 9 receives a message naming the missing
 * prerequisite, rather than an opaque foreign-key violation.
 *
 * The same approach is what ShareholderRolesSeeder uses for role
 * lookups — it queries the `roles` table by slug at run time and
 * throws a RuntimeException naming MasesCoreSeeder as the
 * prerequisite if a role is missing. This file follows that
 * precedent.
 *
 * =============================================================================
 * The Amharic labels
 * =============================================================================
 * Every permission carries a `name_am` and a `description_am`. The
 * description mirrors the name (the reuse-name policy that
 * ShareholderPermissionsSeeder established), because the permission
 * name is already a complete noun phrase and does not benefit from
 * a second, different sentence.
 *
 * The label vocabulary follows the platform's convention for
 * permission verbs:
 *
 *     view      → ማየት          (infinitive of "to see")
 *     create    → መፍጠር        (infinitive of "to create")
 *     manage    → ማስተዳደር      (infinitive of "to manage")
 *     approve   → ማጽደቅ        (infinitive of "to approve")
 *     verify    → ማረጋገጥ       (infinitive of "to verify")
 *     upload    → መስቀል         (infinitive of "to upload")
 *     download  → ማውረድ        (infinitive of "to download")
 *     generate  → ማዘጋጀት       (infinitive of "to prepare")
 *
 * The resource nouns use the terms already established in the
 * Amharic catalogue (G1 file 2 of 10):
 *
 *     governance          → የአስተዳደር ሥርዓት
 *     body                → አካል / አካላት
 *     member              → አባል / አባላት
 *     meeting             → ስብሰባ / ስብሰባዎች
 *     agenda              → አጀንዳ / አጀንዳዎች
 *     attendance          → ተሳትፎ
 *     minute              → ቃለ ጉባኤ / ቃለ ጉባኤዎች
 *     resolution          → ውሳኔ / ውሳኔዎች
 *     action              → የእርምጃ ነጥብ / የእርምጃ ነጥቦች
 *     delegation          → ስልጣን ውክልና / ስልጣን ውክልናዎች
 *     declaration         → ማስታወቂያ / ማስታወቂያዎች
 *     election            → ምርጫ / ምርጫዎች
 *     document            → ሰነድ / ሰነዶች
 *     report              → ሪፖርት / ሪፖርቶች
 *
 * The "own-record" forms that appear in the Shareholder domain
 * (`shareholder.view_own`, `wallet.view_own`, etc.) have no
 * counterpart here. The Governance domain has no single-record
 * concept analogous to "the caller's own shareholder record" or
 * "the caller's own wallet" — a user may be a member of zero, one,
 * or several bodies across several terms. If a future phase
 * introduces a self-service surface (e.g. a "my governance"
 * dashboard), the `_own` slug family is introduced then, alongside
 * the endpoint that consumes it. No `_own` slug is declared here.
 *
 * =============================================================================
 * Native-speaker review needed
 * =============================================================================
 * The Amharic catalogue's review list (G1 file 2 of 10) flagged
 * twelve strings. This file's review list flags three additional
 * items, all verbs whose choice may be adjusted by a reviewer
 * without affecting any other string:
 *
 *   · `manage` → ማስተዳደር vs. ማስተዳደርም. The former is the standard
 *     infinitive. The latter is a defensive form that reads
 *     slightly more formal; a reviewer may prefer it if the
 *     register in the existing Shareholder permission labels
 *     suggests it. The existing labels use the plain form; this
 *     file follows that precedent.
 *
 *   · `verify` → ማረጋገጥ. Idiomatic, but a reviewer may prefer
 *     `ማረጋገጥ`'s longer variant `ማረጋገጥ` (the two are variants of
 *     the same root). The shorter form is used here.
 *
 *   · `generate` → ማዘጋጀት. Literally "to prepare". The English term
 *     "generate" for a report has no direct Amharic rendering; the
 *     chosen form means "to prepare" and reads naturally. A
 *     reviewer may prefer `ማፍለቅ` ("to produce") or another
 *     construction.
 *
 * Each is a one-line change to one entry in the `PERMISSIONS`
 * constant (and the corresponding description, since the
 * reuse-name policy mirrors them).
 *
 * The governance-domain resource nouns (`አካል`, `አባል`, `ስብሰባ`,
 * `አጀንዳ`, `ተሳትፎ`, `ቃለ ጉባኤ`, `ውሳኔ`, `የእርምጃ ነጥብ`, `ስልጣን ውልና`,
 * `ማስታወቂያ`, `ምርጫ`, `ሰነድ`, `ሪፖርት`) are the same terms the
 * catalogue file 2 declares. A reviewer's decision about any one
 * of them applies to both files in the same change.
 *
 * =============================================================================
 * Idempotency
 * =============================================================================
 * Every write goes through `Permission::updateOrCreate(['slug' =>
 * $slug], [...])`. Running the seeder repeatedly is safe:
 *
 *   · A row whose slug is present is updated to the values
 *     declared here.
 *   · A row whose slug is absent is inserted.
 *   · A row whose slug is neither referenced here nor pre-existing
 *     is never touched. The seeder does not delete permissions.
 *
 * The `application_id` lookup runs on every invocation and does not
 * itself write. A repeat run performs one extra read on
 * `platform_applications` and then the same 37 idempotent writes.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * The seeder is not part of `Database\Seeders\DatabaseSeeder`, so
 * it does not run automatically on `php artisan db:seed`. Invoke
 * it explicitly, in this order (after G1 files 8 and 9 have been
 * uploaded):
 *
 *     # 1. Register the application row (creates
 *     #    platform_applications row for slug='governance')
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *
 *     # 2. Register the governance permissions (37 rows)
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernancePermissionsSeeder"
 *
 *     # 3. Grant the permissions to the existing roles
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceRolesSeeder"
 *
 * Running this seeder out of order — before file 9 — is safe: the
 * seeder throws a RuntimeException naming the missing prerequisite
 * before writing any row. No partial state is produced.
 *
 * =============================================================================
 * Deployment
 * =============================================================================
 * Upload this file to:
 *
 *     mases_core/src/Domains/Governance/Database/Seeders/GovernancePermissionsSeeder.php
 *
 * The directory `mases_core/src/Domains/Governance/Database/Seeders/`
 * must exist. If it does not, create it before uploading — the
 * file's path is the full path.
 *
 * Post-deployment verification:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernanceApplicationSeeder"
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernancePermissionsSeeder"
 *     php artisan tinker
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\Permission
 *     ...     ::where('slug', 'like', 'governance.%')->count()
 *     => 36
 *     >>> \MaaleArna\MASES\Core\Authorization\Models\Permission
 *     ...     ::where('slug', 'app.governance.access')->value('application_id')
 *     => 8
 *
 * Expected: 36 tenant-scoped governance permissions and one
 * application-scoped `app.governance.access` row pointing at
 * `application_id = 8`.
 *
 * =============================================================================
 * Files modified:            none (this is a new file)
 * Files added:               this file only
 * Files deleted:             none
 * Permissions added:         37 (36 tenant-scoped + 1 application-scoped)
 * Roles modified:            none (G1 file 8)
 * Users modified:            none
 * =============================================================================
 */

namespace MaaleArna\MASES\Core\Domains\Governance\Database\Seeders;

use Illuminate\Database\Seeder;
use MaaleArna\MASES\Core\Authorization\Models\Permission;
use MaaleArna\MASES\Core\Authorization\Models\PlatformApplication;
use RuntimeException;

/**
 * GovernancePermissionsSeeder
 *
 * Registers the Governance domain's 37 permissions into the
 * platform `permissions` table.
 *
 * =============================================================================
 * Revision history
 * =============================================================================
 * 1.0.0  — initial delivery (G1 file 7 of 10). 36 tenant-scoped
 *          `governance.*` slugs and 1 application-scoped
 *          `app.governance.access` slug, all with Amharic labels
 *          from the first delivery. Idempotent via `updateOrCreate`.
 *          The application-scoped row's `application_id` is
 *          resolved at run time by querying `platform_applications`
 *          for `slug = 'governance'`; a missing row throws a
 *          RuntimeException naming GovernanceApplicationSeeder as
 *          the prerequisite.
 *
 * =============================================================================
 * What this seeder writes
 * =============================================================================
 * 37 rows across 2 scopes:
 *
 *   governance.*        36 rows (scope = 'tenant', category = 'governance')
 *   app.governance.*     1 row  (scope = 'application', category = 'app_access')
 *
 * The set is frozen by the master prompt §28 as refined during the
 * G0 assessment. Adding, removing, or renaming a slug is a Change
 * Request, because historical rows and audit trails reference
 * slugs.
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
 * Amharic values. The platform's Amharic-first policy governs:
 * Amharic is the primary display language, English is the fallback.
 * The resource layer reads `name_am` first and falls back to
 * `name_en` only when the Amharic value is empty.
 *
 * The `_am` values follow the same vocabulary as the domain's
 * Amharic catalogue (G1 file 2) and the same verb forms as the
 * Shareholder domain's permission labels. The three flagged items
 * in the file-level change-verification block §"Native-speaker
 * review needed" are refinements, not blockers.
 *
 * =============================================================================
 * How to run
 * =============================================================================
 * Invoke explicitly, after the application row exists:
 *
 *     php artisan db:seed \
 *         --class="MaaleArna\\MASES\\Core\\Domains\\Governance\\Database\\Seeders\\GovernancePermissionsSeeder"
 *
 * Expected output:
 *
 *     Governance permissions seeded: 37 entries (36 tenant + 1 application).
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Database\Seeders
 * @version 1.0.0
 */
final class GovernancePermissionsSeeder extends Seeder
{
    /**
     * The 36 tenant-scoped permissions this seeder manages.
     *
     * Each entry carries a slug, an Amharic label, an English label,
     * and a category. The `scope` is uniform across the set
     * (`'tenant'`) and is applied when the row is written rather
     * than repeated in each entry.
     *
     * Ordering is presentation-only; `updateOrCreate` uses slug as
     * the identity key. The entries are grouped by resource noun so
     * that a reader can see the shape of the domain's surface at a
     * glance.
     *
     * @var array<int, array{slug: string, name_am: string, name_en: string, category: string}>
     */
    private const PERMISSIONS = [
        // ---------------------------------------------------------------------
        // Governance — top-level
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.view',
            'name_am'  => 'የአስተዳደር ሥርዓትን ማየት',
            'name_en'  => 'View governance',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.manage',
            'name_am'  => 'የአስተዳደር ሥርዓትን ማስተዳደር',
            'name_en'  => 'Manage governance',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Governance bodies
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.body.view',
            'name_am'  => 'የአስተዳደር አካላትን ማየት',
            'name_en'  => 'View governance bodies',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.body.manage',
            'name_am'  => 'የአስተዳደር አካላትን ማስተዳደር',
            'name_en'  => 'Manage governance bodies',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Memberships
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.member.view',
            'name_am'  => 'የአስተዳደር አባላትን ማየት',
            'name_en'  => 'View governance members',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.member.manage',
            'name_am'  => 'የአስተዳደር አባላትን ማስተዳደር',
            'name_en'  => 'Manage governance members',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Meetings
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.meeting.view',
            'name_am'  => 'ስብሰባዎችን ማየት',
            'name_en'  => 'View meetings',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.meeting.create',
            'name_am'  => 'ስብሰባ መፍጠር',
            'name_en'  => 'Create meeting',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.meeting.manage',
            'name_am'  => 'ስብሰባዎችን ማስተዳደር',
            'name_en'  => 'Manage meetings',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.meeting.approve',
            'name_am'  => 'ስብሰባ ማጽደቅ',
            'name_en'  => 'Approve meeting',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Agendas
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.agenda.view',
            'name_am'  => 'አጀንዳዎችን ማየት',
            'name_en'  => 'View agendas',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.agenda.manage',
            'name_am'  => 'አጀንዳዎችን ማስተዳደር',
            'name_en'  => 'Manage agendas',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Attendance
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.attendance.view',
            'name_am'  => 'ተሳትፎን ማየት',
            'name_en'  => 'View attendance',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.attendance.manage',
            'name_am'  => 'ተሳትፎን ማስተዳደር',
            'name_en'  => 'Manage attendance',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Minutes
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.minute.view',
            'name_am'  => 'ቃለ ጉባኤዎችን ማየት',
            'name_en'  => 'View minutes',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.minute.manage',
            'name_am'  => 'ቃለ ጉባኤዎችን ማስተዳደር',
            'name_en'  => 'Manage minutes',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.minute.approve',
            'name_am'  => 'ቃለ ጉባኤ ማጽደቅ',
            'name_en'  => 'Approve minutes',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Resolutions
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.resolution.view',
            'name_am'  => 'ውሳኔዎችን ማየት',
            'name_en'  => 'View resolutions',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.resolution.create',
            'name_am'  => 'ውሳኔ መፍጠር',
            'name_en'  => 'Create resolution',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.resolution.manage',
            'name_am'  => 'ውሳኔዎችን ማስተዳደር',
            'name_en'  => 'Manage resolutions',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.resolution.approve',
            'name_am'  => 'ውሳኔ ማጽደቅ',
            'name_en'  => 'Approve resolution',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Action items
        //
        // The `verify` verb is the only non-standard verb in the
        // inventory; it exists because the master prompt §21
        // mandates that verification is a distinct act from
        // completion.
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.action.view',
            'name_am'  => 'የእርምጃ ነጥቦችን ማየት',
            'name_en'  => 'View action items',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.action.create',
            'name_am'  => 'የእርምጃ ነጥብ መፍጠር',
            'name_en'  => 'Create action item',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.action.manage',
            'name_am'  => 'የእርምጃ ነጥቦችን ማስተዳደር',
            'name_en'  => 'Manage action items',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.action.verify',
            'name_am'  => 'የእርምጃ ነጥብ ማረጋገጥ',
            'name_en'  => 'Verify action item',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Delegations
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.delegation.view',
            'name_am'  => 'ስልጣን ውክልናዎችን ማየት',
            'name_en'  => 'View delegations',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.delegation.manage',
            'name_am'  => 'ስልጣን ውክልናዎችን ማስተዳደር',
            'name_en'  => 'Manage delegations',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Declarations
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.declaration.view',
            'name_am'  => 'ማስታወቂያዎችን ማየት',
            'name_en'  => 'View declarations',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.declaration.manage',
            'name_am'  => 'ማስታወቂያዎችን ማስተዳደር',
            'name_en'  => 'Manage declarations',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Elections
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.election.view',
            'name_am'  => 'ምርጫዎችን ማየት',
            'name_en'  => 'View elections',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.election.manage',
            'name_am'  => 'ምርጫዎችን ማስተዳደር',
            'name_en'  => 'Manage elections',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.election.vote',
            'name_am'  => 'በምርጫ መሳተፍ',
            'name_en'  => 'Cast election ballot',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.election.certify',
            'name_am'  => 'የምርጫ ውጤት ማረጋገጥ',
            'name_en'  => 'Certify election results',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Documents
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.document.view',
            'name_am'  => 'የአስተዳደር ሰነዶችን ማየት',
            'name_en'  => 'View governance documents',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.document.upload',
            'name_am'  => 'የአስተዳደር ሰነድ መስቀል',
            'name_en'  => 'Upload governance document',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.document.download',
            'name_am'  => 'የአስተዳደር ሰነድ ማውረድ',
            'name_en'  => 'Download governance document',
            'category' => 'governance',
        ],

        // ---------------------------------------------------------------------
        // Reports
        // ---------------------------------------------------------------------
        [
            'slug'     => 'governance.report.view',
            'name_am'  => 'የአስተዳደር ሪፖርቶችን ማየት',
            'name_en'  => 'View governance reports',
            'category' => 'governance',
        ],
        [
            'slug'     => 'governance.report.generate',
            'name_am'  => 'የአስተዳደር ሪፖርት ማዘጋጀት',
            'name_en'  => 'Generate governance report',
            'category' => 'governance',
        ],
    ];

    /**
     * The slug of the `platform_applications` row this seeder's
     * application-scoped permission references.
     */
    private const APPLICATION_SLUG = 'governance';

    /**
     * The slug of the application-scoped permission this seeder
     * registers.
     */
    private const APPLICATION_PERMISSION_SLUG = 'app.governance.access';

    /**
     * Run the database seeds.
     *
     * Writes the 36 tenant-scoped permissions, then resolves the
     * Governance platform application and writes the
     * application-scoped permission. Fails loudly if the
     * application row is missing.
     *
     * @return void
     *
     * @throws RuntimeException  When the Governance platform
     *                           application is not present.
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
                    // in both languages. Amharic is primary.
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

        $this->seedApplicationAccessPermission();

        $this->command->info(sprintf(
            'Governance permissions seeded: %d entries (%d tenant + 1 application).',
            count(self::PERMISSIONS) + 1,
            count(self::PERMISSIONS),
        ));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve the Governance platform application and register the
     * application-scoped `app.governance.access` permission.
     *
     * The application id is resolved at run time by querying the
     * `platform_applications` table for `slug = 'governance'`. If
     * the row is absent, the seeder throws a RuntimeException
     * naming GovernanceApplicationSeeder as the prerequisite — the
     * same pattern ShareholderRolesSeeder uses for role lookups.
     *
     * @return void
     *
     * @throws RuntimeException  When the Governance platform
     *                           application is not present.
     */
    private function seedApplicationAccessPermission(): void
    {
        $applicationId = PlatformApplication::query()
            ->where('slug', self::APPLICATION_SLUG)
            ->value('id');

        if ($applicationId === null) {
            throw new RuntimeException(
                'GovernancePermissionsSeeder: the `' . self::APPLICATION_SLUG
                . '` row is not present in the `platform_applications` table. '
                . 'Run GovernanceApplicationSeeder (G1 file 9 of 10) first, '
                . 'then re-run this seeder.'
            );
        }

        Permission::updateOrCreate(
            // Identity key.
            ['slug' => self::APPLICATION_PERMISSION_SLUG],
            // Values to set on insert and on update.
            [
                'name_am'        => 'የአስተዳደር ሥርዓት መድረስ',
                'name_en'        => 'Access governance',

                // Reuse-name policy: the description mirrors the name.
                'description_am' => 'የአስተዳደር ሥርዓት መድረስ',
                'description_en' => 'Access governance',

                'category'       => 'app_access',
                'scope'          => 'application',
                'tenant_id'      => null,
                'application_id' => (int) $applicationId,
                'is_active'      => true,

                // `is_system` = false, matching the convention used by the
                // six existing `app.*.access` permissions (IDs 17–23). Only
                // `app.admin.access` (ID 20) carries `is_system = true`.
                'is_system'      => false,
            ]
        );
    }
}