<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — P2.1 · File 2 of 2
 * =============================================================================
 *
 * File:            ShareholderSeeder.php
 * Unit:            P2.1 — close D9 and D11 defects in the register write
 * Delivery type:   Amendment
 * Net lines:       -1 constant, +~40 docblock lines, 3 statement edits,
 *                  14 ternary-condition renames
 *
 * Before:
 *   The seeder held two constants that mapped the register's `type`
 *   discriminator into shareholders.shareholder_type:
 *
 *     private const SHAREHOLDER_TYPE_INDIVIDUAL   = 'individual';
 *     private const SHAREHOLDER_TYPE_ORGANIZATION = 'organization';
 *
 *   Both discriminator sites evaluated
 *     $definition['type'] === self::SHAREHOLDER_TYPE_ORGANIZATION
 *   and the shareholders-row upsert wrote
 *     $isOrganization
 *         ? self::SHAREHOLDER_TYPE_ORGANIZATION
 *         : self::SHAREHOLDER_TYPE_INDIVIDUAL
 *   into the shareholder_type column. The two entities (SH-0023,
 *   SH-0088) therefore received the literal 'organization', a value
 *   that is not a member of the ShareholderType enum (D9 violation).
 *
 *   The same upsert also wrote, unconditionally:
 *     'user_id'                => $user->id,
 *     'representative_user_id' => null,
 *   so both entity rows received a direct user_id link and no
 *   representative link — contradicting D11, which forbids a direct
 *   user link on any non-individual shareholder.
 *
 * After:
 *   · SHAREHOLDER_TYPE_ORGANIZATION is deleted. The constant is
 *     obsolete: with the register now carrying the enum value
 *     directly (File 1 of this unit), no mapping is required.
 *
 *   · SHAREHOLDER_TYPE_INDIVIDUAL is renamed to
 *     REGISTER_TYPE_INDIVIDUAL. The constant now names the register's
 *     individual discriminator, not the enum value written to the
 *     column. Its value is unchanged: 'individual'.
 *
 *   · Both discriminator sites evaluate
 *       $definition['type'] !== self::REGISTER_TYPE_INDIVIDUAL
 *     The predicate is inverted so the variable can be named
 *     $isEntity (the old name, $isOrganization, is misleading now
 *     that the register carries enum values that include
 *     'religious_body'). Every ternary condition that read the old
 *     variable is updated to read the new one; the branches are
 *     unchanged.
 *
 *   · The shareholders-row upsert's `shareholder_type` field now
 *     writes `$definition['type']` verbatim. The register's value is
 *     the enum member that the column requires. This is the D9 fix.
 *
 *   · The shareholders-row upsert's user-link pair is changed to:
 *       'user_id'                => $isEntity ? null     : $user->id,
 *       'representative_user_id' => $isEntity ? $user->id : null,
 *     Individuals retain the direct user_id link they had before.
 *     Entities now receive the representative link instead. This is
 *     the D11 fix.
 *
 *   · No change to the discriminator semantics: every register row
 *     whose `type` is not 'individual' is treated as an entity, and
 *     every row whose `type` is 'individual' is treated as an
 *     individual. Because the register's `type` field carries
 *     ShareholderType enum values, a future CR that adds a register
 *     row of another entity type (a company, a trust) requires no
 *     change to this seeder — the row is automatically classified
 *     as an entity and its value written verbatim.
 *
 * =============================================================================
 * What does NOT change
 * =============================================================================
 *   · The seeder's public surface: seed() and run().
 *   · The email and username derivation (buildShareholderEmail,
 *     buildShareholderUsername, nameToLocalPart).
 *   · The collision-suffix algorithm.
 *   · The tenant-membership attachment.
 *   · The role attachment logic, including the board-member
 *     assignments via BoardRoleAssignments.
 *   · The users-table upsert (upsertShareholder) beyond the
 *     variable rename.
 *   · The Schema::hasTable('shareholders') guard.
 *   · The three RuntimeException checks in run().
 *   · Every import.
 *
 * =============================================================================
 * Deployment dependency — Files 1 and 2 are one change
 * =============================================================================
 * This file alone, against the current (pre-File-1) register, produces
 * no behaviour change: both entity rows still carry 'organization', so
 * they are classified as entities and written with 'organization' —
 * the same D9 violation the seeder already produced.
 *
 * Files 1 and 2 must deploy together, and the re-seed follows both.
 *
 * =============================================================================
 * Files modified:            this file only
 * Files added:               none
 * Files deleted:             none
 * Constants removed:         1 (SHAREHOLDER_TYPE_ORGANIZATION)
 * Constants renamed:         1 (SHAREHOLDER_TYPE_INDIVIDUAL
 *                               → REGISTER_TYPE_INDIVIDUAL)
 * =============================================================================
 */

namespace Database\Seeders;

use Database\Seeders\Data\BoardRoleAssignments;
use Database\Seeders\Data\ShareholderRegister;
use Database\Seeders\Support\UserAttacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * ShareholderSeeder
 *
 * Creates the platform's shareholder cohort — one hundred users and
 * one hundred shareholder-register rows — and attaches each to the
 * seeded tenant and to the role(s) its register entry and board
 * assignment define. Runs after AdminUserSeeder, as the last
 * reference-data seeder in the orchestrator's flow.
 *
 * =============================================================================
 * What this seeder is — and is not
 * =============================================================================
 * This is a DOMAIN SEEDER. Its single responsibility is to write the
 * hundred-user cohort and hand the resulting map of persisted models
 * back to the caller. It contains no reference data — that lives in
 * Data\ShareholderRegister and Data\BoardRoleAssignments. It contains
 * no admin logic — that is AdminUserSeeder's job. It contains no
 * pivot-attach logic — that is UserAttacher's job.
 *
 * The class was extracted from MasesCoreSeeder during the seeder
 * refactoring (Unit S-16). Its behaviour was byte-for-byte identical
 * to the combination of the six private methods it consolidates
 * (seedShareholders, buildShareholderEmail, buildShareholderUsername,
 * nameToLocalPart, upsertShareholder, upsertShareholderRecord) and
 * the shareholder-specific portion of the source's assignUserRelations
 * method.
 *
 * The extraction's byte-for-byte fidelity is preserved by this seeder
 * for individual rows. It is DELIBERATELY BROKEN for entity rows by
 * P2.1: the shareholders-row write no longer maps the register's
 * `type` field into a shareholder_type constant, and no longer writes
 * an unconditional `user_id`. Both changes are corrections to defects
 * that the pre-refactoring source carried. See §"The upsert pattern"
 * for the D9 and D11 rules that now apply.
 *
 * =============================================================================
 * The two-method surface
 * =============================================================================
 *   seed(Tenant $tenant, array $roles, User $superAdmin): array
 *     The primary entry point. Creates all 100 shareholders, writes
 *     their register rows, attaches their pivots, and returns the map
 *     of persisted models. Called by the orchestrator.
 *
 *   run(): void
 *     The Seeder base class's canonical method. Resolves its three
 *     prerequisites from the database and delegates to seed().
 *     Throws a RuntimeException when any prerequisite is missing —
 *     see §"Why run() throws" below.
 *
 * =============================================================================
 * The three arguments
 * =============================================================================
 *   $tenant
 *     The persisted Tenant model, as returned by TenantSeeder::seed().
 *     Written into each shareholder's `default_tenant_id` column and
 *     into the `tenant_id` column of each shareholder register row,
 *     and used by UserAttacher to write the tenant-membership pivot.
 *
 *   $roles
 *     The persisted Role models, keyed by slug, as returned by
 *     RoleSeeder::seed(). Each shareholder's register entry is
 *     assigned the 'shareholder' role by default, or the roles named
 *     by Data\BoardRoleAssignments for the eight board-member SH-IDs.
 *     Each role is looked up by slug in this map.
 *
 *   $superAdmin
 *     The persisted super administrator, as returned by
 *     AdminUserSeeder::seed() under the 'super' key. Passed to
 *     UserAttacher as the `assignedBy` value for every role
 *     assignment this seeder makes — the semantic is that the
 *     platform's bootstrap identity is the attributor of the entire
 *     shareholder cohort's role grants.
 *
 * =============================================================================
 * The return shape
 * =============================================================================
 * The return is a map keyed by SH-ID:
 *
 *     [
 *         'SH-0001' => User,
 *         'SH-0002' => User,
 *         …
 *         'SH-0100' => User,
 *     ]
 *
 * Each value is the persisted User model created or updated for that
 * register entry. The user's `id` is the foreign key that the
 * corresponding `shareholders.user_id` column carries — for
 * individual rows. For entity rows, the user's id is carried by the
 * `shareholders.representative_user_id` column instead; see
 * §"The upsert pattern" and D11.
 *
 * No downstream seeder consumes this map — ShareholderSeeder is the
 * last domain seeder before the orchestrator. It is returned for
 * consistency with the earlier seeders and to give a future CR a
 * shape to consume.
 *
 * =============================================================================
 * The email and username collision rules
 * =============================================================================
 * Both the email local-part and the username are derived from the
 * register entry's Latin-script name fields. Six usernames and one
 * email collide across the register; both collisions are resolved by
 * appending a numeric suffix, assigned in SH-ID iteration order.
 *
 * EMAIL:
 *
 *     base      = normalize(first_en . father_en)     (individual)
 *               = normalize(entity_en)               (entity)
 *     candidate = base
 *     for n = 2, 3, …:
 *         if candidate is unused: return candidate . '@maalearna.com.et'
 *         candidate = base . n
 *
 * USERNAME:
 *
 *     base      = normalize(first_en)                (individual)
 *               = normalize(entity_en)               (entity)
 *     candidate = base
 *     for n = 2, 3, …:
 *         if candidate is unused: return candidate
 *         candidate = base . n
 *
 * `normalize()` lowercases the string and strips every non-letter
 * character. If the result is empty — neither a name nor an entity
 * name is present — `normalize()` returns the literal `'shareholder'`.
 *
 * THE SUFFIX IS DETERMINISTIC. Two runs against the same register
 * produce the same usernames and emails, because the collision
 * resolution depends only on the iteration order (SH-0001 through
 * SH-0100) and the register's content.
 *
 * THE COLLISIONS THE CURRENT REGISTER PRODUCES:
 *
 *     amanuel       SH-0003, SH-0029  →  amanuel, amanuel2
 *     oysha         SH-0014, SH-0045  →  oysha, oysha2
 *     wegene        SH-0013, SH-0049  →  wegene, wegene2
 *     aman          SH-0028, SH-0066  →  aman, aman2
 *     tareken       SH-0061, SH-0082  →  tareken, tareken2
 *     matewos       SH-0015, SH-0094  →  matewos, matewos2
 *
 *     oyshatushkulo SH-0014, SH-0045  →  oyshatushkulo@…
 *                                        oyshatushkulo2@…
 *
 * =============================================================================
 * The upsert pattern — two upserts per shareholder
 * =============================================================================
 * Each shareholder entry produces TWO rows:
 *
 *   1. A `users` row — the platform identity. Upserted via
 *      User::firstOrNew(['email' => …]) + fill + direct password +
 *      save. The password column is guarded on the model, so it is
 *      assigned directly rather than through fill(). The three
 *      audit columns (password_changed_at, last_login_at,
 *      last_login_ip) are nulled conditionally, guarded by
 *      Schema::hasColumn() for portability across schema branches.
 *
 *   2. A `shareholders` row — the register entry. Upserted via
 *      DB::table('shareholders') (query builder, not Eloquent),
 *      because the shareholders table has no corresponding model in
 *      the current schema. The upsert key is `user_id`: the method
 *      looks up an existing row by the user's id and either updates
 *      it in place or inserts a new one.
 *
 * The two upserts are separate steps. This matches the source: a
 * register entry has identity information (in the users table) and
 * domain information (in the shareholders table) that the platform
 * stores separately. The seeder preserves that separation.
 *
 * ## The D9 rule — shareholder_type is the register's `type` value
 * ##
 * The register row's `type` field carries a ShareholderType enum
 * value (D9). The seeder writes it into the shareholders row's
 * `shareholder_type` column verbatim:
 *
 *     'shareholder_type' => $definition['type'],
 *
 * The seeder does NOT map, translate, or normalise the value. The
 * register is the source of truth for the classification; the seeder
 * is a pass-through. A future CR that adds a register row of a new
 * entity type (a company, a trust, a cooperative) requires no change
 * to this seeder.
 *
 * ## The D11 rule — entities receive representative_user_id
 * ##
 * D11 requires a legal-entity shareholder to be represented through
 * `representative_user_id`; a direct `user_id` link is forbidden on
 * non-individuals. The seeder therefore writes:
 *
 *     'user_id'                => $isEntity ? null     : $user->id,
 *     'representative_user_id' => $isEntity ? $user->id : null,
 *
 * For an individual register row, this leaves `user_id` populated
 * (the user IS the shareholder) and `representative_user_id` null.
 * For an entity register row, this leaves `user_id` null and
 * `representative_user_id` populated (the user REPRESENTS the
 * shareholder).
 *
 * ## Consequence for the upsert key
 * ##
 * The `shareholders` upsert keys on `user_id`. For entity rows,
 * `user_id` is now null, so an upsert that looks for an existing
 * entity row by `user_id` will not find one on a re-run. This is not
 * a defect: the orchestrator (MasesCoreSeeder) invokes
 * UserPivotCleaner::clear() before every run, which deletes every
 * `shareholders` row. The upsert therefore always starts from an
 * empty table and the INSERT branch runs. A direct invocation of
 * ShareholderSeeder::run() outside the orchestrator, against a
 * database that already contains entity rows, would insert
 * duplicates; the run() method's docblock does not document such an
 * invocation as supported. A future CR that needs a broader upsert
 * key adds it here.
 *
 * =============================================================================
 * The shareholders-table guard
 * =============================================================================
 * The shareholders-row upsert is guarded by Schema::hasTable(). This
 * lets the seeder run on a schema branch where the shareholders table
 * does not yet exist. In that case the users are created but the
 * register rows are not, and the run completes without error.
 *
 * The guard matches the source method exactly. Removing it would
 * break the seeder on any schema that lacks the shareholders table.
 *
 * =============================================================================
 * The role attachment rules
 * =============================================================================
 * For each shareholder:
 *
 *   1. Attach to the tenant. Always.
 *
 *   2. Attach roles. The roles come from Data\BoardRoleAssignments
 *      if the SH-ID is a board member, else the default
 *      ['shareholder'].
 *
 *   3. Every attachment is attributed to $superAdmin via
 *      UserAttacher's `assignedBy` argument.
 *
 * The eight board members and their roles are documented in
 * Data\BoardRoleAssignments (Unit S-8). The remaining 92 shareholders
 * receive only the 'shareholder' role.
 *
 * =============================================================================
 * Why run() throws
 * =============================================================================
 * The standalone invocation requires three prerequisites. Three
 * failure cases are guarded against:
 *
 *   · No tenant. Each shareholder's `default_tenant_id` must be set
 *     and each must be attached to a tenant. Without a tenant, both
 *     writes are impossible. The seeder throws.
 *
 *   · No roles. Each shareholder's role attachment depends on a
 *     resolution against the roles map. Without a map, every lookup
 *     resolves to null and every shareholder is created WITHOUT a
 *     role — a state the operator would not have asked for and might
 *     not notice. The seeder throws.
 *
 *   · No super admin. Every role attachment is attributed to a
 *     super administrator. Without one, the attribution is impossible
 *     and the pivot's `assigned_by` column would be null throughout
 *     the cohort, contradicting the platform's attribution rule. The
 *     seeder throws.
 *
 * All three throws name the specific prerequisite and point the
 * operator to the fix. This matches RoleSeeder (S-13),
 * RolePermissionSeeder (S-14), and AdminUserSeeder (S-15b).
 *
 * =============================================================================
 * What this seeder deliberately does NOT do
 * =============================================================================
 *   - It does NOT clear existing shareholders. Clearing is
 *     UserPivotCleaner's job (Unit S-9). A shareholder left over from
 *     a previous run is updated in place by this seeder's upserts,
 *     subject to the note in §"Consequence for the upsert key".
 *
 *   - It does NOT write share-holding ledger rows, share certificates,
 *     wallet entries, or loan records. Those belong to other domains;
 *     the register entry this seeder creates is their anchor, not
 *     their content.
 *
 *   - It does NOT reference Data\ApplicationRegistry. Shareholders
 *     gain application access through roles, not directly. The roles
 *     are already attached to applications by RoleSeeder (S-13).
 *
 *   - It does NOT log per-row progress. The orchestrator's
 *     printSummary() reports the run; individual seeders do not
 *     report their own work.
 *
 *   - It does NOT swallow exceptions. If a row write fails — a
 *     UNIQUE violation on username or email, a foreign-key violation
 *     on tenant_id or user_id, a database connection error — the
 *     exception propagates to the orchestrator, which is where a
 *     seeding run should fail loudly.
 *
 * @package Database\Seeders
 */
final class ShareholderSeeder extends Seeder
{
    /**
     * The register's individual discriminator.
     *
     * The register's `type` field carries a ShareholderType enum
     * value. This constant is the individual branch's value.
     *
     * The seeder tests each row's `type` against this constant. Every
     * row whose type is NOT this value is treated as an entity row —
     * it carries entity-name fields, and its shareholder row is
     * written with a `representative_user_id` rather than a `user_id`
     * (D11).
     *
     * The constant is named for the register's field, not for the
     * enum, because that is what the seeder compares against. The
     * enum's own individual value happens to be the same string.
     */
    private const REGISTER_TYPE_INDIVIDUAL = 'individual';

    /**
     * Create the shareholder cohort, write each register row, attach
     * each user's pivots, and return the map of persisted models
     * keyed by SH-ID.
     *
     * Idempotent: each user is upserted by email, each shareholder
     * row is upserted by user_id, and every pivot attach is guarded
     * by an existence check in UserAttacher.
     *
     * @param Tenant $tenant
     *        the tenant the cohort is scoped to
     *
     * @param array<string, Role> $roles
     *        the persisted role models, keyed by slug, as returned by
     *        RoleSeeder::seed()
     *
     * @param User $superAdmin
     *        the super administrator, as returned by
     *        AdminUserSeeder::seed() under the 'super' key. Used as
     *        the `assignedBy` value for every role attachment this
     *        seeder makes.
     *
     * @return array<string, User>
     *         the persisted shareholder user models, keyed by the
     *         register's SH-ID
     */
    public function seed(Tenant $tenant, array $roles, User $superAdmin): array
    {
        \Database\Seeders\Support\DemoSeederGuard::assertAllowed();
        /*
         * Read the register from the Data\ layer. See Unit S-1 for
         * the row shape. The register is a MAP keyed by SH-ID; the
         * iteration order of that map (SH-0001 through SH-0100) is
         * the order in which collision suffixes are assigned. See
         * the class docblock §"The email and username collision
         * rules".
         */
        $register = ShareholderRegister::ROWS;

        /*
         * Two parallel tracking arrays for collision detection.
         *
         * Both are keyed by the LOWERCASE candidate — the email
         * lookup uses the full lowercase email address, the username
         * lookup uses the lowercase username. The keys are not the
         * SH-IDs; the values (stored for a future debugging CR)
         * happen to be the SH-IDs that first claimed each candidate.
         *
         * See the source's buildShareholderEmail and
         * buildShareholderUsername for the exact keying rules.
         */
        $usedEmails    = [];
        $usedUsernames = [];

        /*
         * A single UserAttacher instance, reused for every
         * shareholder's pivot writes. See UserAttacher's docblock
         * §"Why the methods are on an instance, not static" for the
         * cached-instance rationale.
         */
        $attacher = new UserAttacher();

        $result = [];

        foreach ($register as $shId => $definition) {
            /*
             * Build the email and username. Both methods consult
             * and mutate the tracking arrays. The email build runs
             * first so that the email candidate space is fully
             * populated before the username build runs; the two
             * spaces are independent, so the order does not affect
             * any outcome — but keeping the source order avoids a
             * subtle divergence if a future CR couples them.
             */
            $email = $this->buildShareholderEmail($definition, $usedEmails);
            $usedEmails[strtolower($email)] = $shId;

            $username = $this->buildShareholderUsername($definition, $usedUsernames);
            $usedUsernames[strtolower($username)] = $shId;

            /*
             * Upsert the two rows: the users row and the
             * shareholders row. Both upserts preserve the source
             * semantics — see the class docblock §"The upsert
             * pattern".
             */
            $user = $this->upsertShareholder(
                $shId,
                $definition,
                $email,
                $username,
                $tenant,
            );

            $this->upsertShareholderRecord($shId, $definition, $user, $tenant);

            /*
             * Attach the user's pivots: the tenant membership, and
             * the role(s) the register and board assignments define.
             */
            $this->attachRelations($user, $shId, $tenant, $roles, $superAdmin, $attacher);

            $result[$shId] = $user;
        }

        return $result;
    }

    /**
     * The Seeder base class's canonical entry point.
     *
     * Resolves the three prerequisites from the database and
     * delegates to seed(). Throws a RuntimeException when any is
     * missing — see the class docblock §"Why run() throws".
     *
     * Exists so that Laravel's seeder dispatcher (`php artisan
     * db:seed --class=…`) can invoke this class without silently
     * no-op'ing through the base class's empty run().
     *
     * The orchestrator uses seed() directly — it holds all three
     * prerequisites from earlier steps.
     *
     * @throws RuntimeException when the tenant, roles, or super
     *         administrator is missing.
     */
    public function run(): void
    {
        /*
         * Resolve the tenant. The lookup uses the same slug the
         * admin and role seeders use; see Data\TenantProfile for
         * the source.
         */
        $tenant = Tenant::query()
            ->where('slug', 'main')
            ->first();

        if ($tenant === null) {
            throw new RuntimeException(
                'ShareholderSeeder requires the default tenant to exist '
                . 'before it can create the shareholder cohort. '
                . 'Run TenantSeeder first, or invoke the seeder through '
                . 'the MasesCoreSeeder orchestrator, which passes the '
                . 'tenant explicitly.'
            );
        }

        /*
         * Resolve the roles map. Same shape RoleSeeder::seed()
         * returns.
         */
        $roles = Role::query()
            ->get()
            ->keyBy('slug')
            ->all();

        if ($roles === []) {
            throw new RuntimeException(
                'ShareholderSeeder requires the role catalogue to exist '
                . 'before it can attach roles to the shareholder cohort. '
                . 'Run RoleSeeder first, or invoke the seeder through '
                . 'the MasesCoreSeeder orchestrator, which passes the '
                . 'roles map explicitly.'
            );
        }

        /*
         * Resolve the super administrator by email. The email is
         * the same one AdminUserSeeder writes; see
         * Data\BootstrapUsers for the source.
         */
        $superAdmin = User::query()
            ->where('email', 'superadmin@maalearna.com.et')
            ->first();

        if ($superAdmin === null) {
            throw new RuntimeException(
                'ShareholderSeeder requires the super administrator to '
                . 'exist before it can attribute role assignments. '
                . 'Run AdminUserSeeder first, or invoke the seeder '
                . 'through the MasesCoreSeeder orchestrator, which '
                . 'passes the super administrator explicitly.'
            );
        }

        $this->seed($tenant, $roles, $superAdmin);
    }

    // =========================================================================
    // Email and username derivation
    // =========================================================================

    /**
     * Build the shareholder's email address, resolving collisions
     * by numeric suffix.
     *
     * See the class docblock §"The email and username collision
     * rules" for the exact rule.
     *
     * @param array<string, mixed>  $definition  one register entry
     * @param array<string, string> $usedEmails  the tracking map for
     *                                            collision detection
     *                                            (mutated by the
     *                                            caller, read here)
     */
    private function buildShareholderEmail(array $definition, array $usedEmails): string
    {
        $base = $this->nameToLocalPart($definition, 'first_en', 'father_en');

        $candidate = $base;
        $suffix = 2;
        while (isset($usedEmails[$candidate . '@maalearna.com.et'])) {
            $candidate = $base . $suffix;
            $suffix++;
        }

        return $candidate . '@maalearna.com.et';
    }

    /**
     * Build the shareholder's username, resolving collisions by
     * numeric suffix.
     *
     * See the class docblock §"The email and username collision
     * rules" for the exact rule.
     *
     * @param array<string, mixed>  $definition     one register entry
     * @param array<string, string> $usedUsernames  the tracking map
     *                                               for collision
     *                                               detection
     */
    private function buildShareholderUsername(array $definition, array $usedUsernames): string
    {
        $base = $this->nameToLocalPart($definition, 'first_en', null);

        $candidate = $base;
        $suffix = 2;
        while (isset($usedUsernames[strtolower($candidate)])) {
            $candidate = $base . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Normalize a register entry's name fields into a lowercase,
     * letter-only local part.
     *
     * Concatenates the primary and secondary fields (the secondary
     * is null for the username derivation), falls back to the
     * entity name for entity rows, strips every non-letter
     * character, and lowercases the result.
     *
     * If the result is empty — neither a name nor an entity name
     * is present — returns the literal 'shareholder'. This matches
     * the source method.
     *
     * The fallback is driven by the presence of the `entity_en`
     * field, not by the row's `type` value. That is deliberate:
     * the derivation is a string operation over the row's content,
     * independent of the row's classification.
     *
     * @param array<string, mixed> $definition  one register entry
     * @param string               $primaryKey  the field name to
     *                                           use as the primary
     *                                           name source
     * @param string|null          $secondKey   the field name to
     *                                           append, or null
     */
    private function nameToLocalPart(array $definition, string $primaryKey, ?string $secondKey): string
    {
        $primary = $definition[$primaryKey] ?? '';
        $secondary = $secondKey !== null ? ($definition[$secondKey] ?? '') : '';

        if ($primary === '' && $secondary === '' && isset($definition['entity_en'])) {
            $primary = $definition['entity_en'];
        }

        $combined = $primary . $secondary;
        $normalized = strtolower(preg_replace('/[^a-z]/i', '', $combined) ?? '');

        return $normalized !== '' ? $normalized : 'shareholder';
    }

    // =========================================================================
    // User upsert
    // =========================================================================

    /**
     * Upsert one shareholder user by email.
     *
     * The two-step pattern — firstOrNew + fill + direct password +
     * save — matches the source method. See the class docblock
     * §"The upsert pattern".
     *
     * @param string               $shId        the register's SH-ID
     *                                           (preserved for source
     *                                           fidelity; not
     *                                           referenced)
     * @param array<string, mixed> $definition  one register entry
     * @param string               $email       the derived email
     * @param string               $username    the derived username
     * @param Tenant               $tenant      the tenant
     */
    private function upsertShareholder(
        string $shId,
        array $definition,
        string $email,
        string $username,
        Tenant $tenant,
    ): User {
        /** @var User $user */
        $user = User::firstOrNew(['email' => $email]);

        $isEntity = $definition['type'] !== self::REGISTER_TYPE_INDIVIDUAL;

        /*
         * Entity rows carry no individual name fields on their users
         * row; their identity lives in the shareholders row's
         * entity_name_am and entity_name_en columns. Individuals
         * carry all six name fields.
         *
         * The two branches are expressed as ternaries in the fill
         * array, matching the source.
         */
        $fill = [
            'username'            => $username,
            'first_name_am'       => $isEntity ? null : ($definition['first_am'] ?? null),
            'first_name_en'       => $isEntity ? null : ($definition['first_en'] ?? null),
            'father_name_am'      => $isEntity ? null : ($definition['father_am'] ?? null),
            'father_name_en'      => $isEntity ? null : ($definition['father_en'] ?? null),
            'grandfather_name_am' => $isEntity ? null : ($definition['grand_am'] ?? null),
            'grandfather_name_en' => $isEntity ? null : ($definition['grand_en'] ?? null),
            'address'             => $definition['address'] ?? null,
            'default_tenant_id'   => $tenant->id,
            'locale'              => 'am',
            'is_active'           => true,
            'email_verified_at'   => now(),
        ];

        $user->fill($fill);

        /*
         * Hash and assign the bootstrap password directly. The
         * password column is guarded on the model, so it is not in
         * the fill array. The password value is referenced from
         * AdminUserSeeder — see the class docblock §"The
         * DEFAULT_PASSWORD constant".
         */
        $user->password = Hash::make(AdminUserSeeder::DEFAULT_PASSWORD);

        /*
         * Null the three audit columns if they exist on this schema
         * branch. Same guard as the admin seeder.
         */
        if (Schema::hasColumn('users', 'password_changed_at')) {
            $user->password_changed_at = null;
        }
        if (Schema::hasColumn('users', 'last_login_at')) {
            $user->last_login_at = null;
        }
        if (Schema::hasColumn('users', 'last_login_ip')) {
            $user->last_login_ip = null;
        }

        $user->save();

        return $user;
    }

    // =========================================================================
    // Shareholder-record upsert
    // =========================================================================

    /**
     * Upsert one shareholder register row, keyed by user_id.
     *
     * Uses DB::table (the query builder), not an Eloquent model,
     * because the shareholders table has no corresponding model in
     * the current schema. The upsert key is `user_id`.
     *
     * The whole method is guarded by Schema::hasTable('shareholders')
     * — see the class docblock §"The shareholders-table guard".
     *
     * See the class docblock §"The D9 rule" and §"The D11 rule" for
     * the two domain rules this method applies to the shareholder_type
     * and user-link columns.
     *
     * @param string               $shId        the register's SH-ID
     *                                           (preserved for source
     *                                           fidelity; not
     *                                           referenced)
     * @param array<string, mixed> $definition  one register entry
     * @param User                 $user        the persisted user
     * @param Tenant               $tenant      the tenant
     */
    private function upsertShareholderRecord(
        string $shId,
        array $definition,
        User $user,
        Tenant $tenant,
    ): void {
        if (!Schema::hasTable('shareholders')) {
            return;
        }

        $isEntity = $definition['type'] !== self::REGISTER_TYPE_INDIVIDUAL;

        $attributes = [
            'tenant_id'              => $tenant->id,

            /*
             * D9 — the register's `type` field carries the
             * ShareholderType enum value. Write it verbatim.
             */
            'shareholder_type'       => $definition['type'],

            'first_name_am'          => $isEntity ? null : ($definition['first_am'] ?? null),
            'first_name_en'          => $isEntity ? null : ($definition['first_en'] ?? null),
            'father_name_am'         => $isEntity ? null : ($definition['father_am'] ?? null),
            'father_name_en'         => $isEntity ? null : ($definition['father_en'] ?? null),
            'grandfather_name_am'    => $isEntity ? null : ($definition['grand_am'] ?? null),
            'grandfather_name_en'    => $isEntity ? null : ($definition['grand_en'] ?? null),
            'entity_name_am'         => $isEntity ? ($definition['entity_am'] ?? null) : null,
            'entity_name_en'         => $isEntity ? ($definition['entity_en'] ?? null) : null,

            /*
             * D11 — individuals are linked directly; entities are
             * represented. The two columns are mutually exclusive
             * per row.
             */
            'user_id'                => $isEntity ? null     : $user->id,
            'representative_user_id' => $isEntity ? $user->id : null,

            'is_active'              => true,
            'updated_at'             => now(),
        ];

        /*
         * Lookup by user_id. For individual rows, the register entry
         * and the user row are one-to-one via the user_id column.
         * For entity rows, the user_id column is null; the lookup
         * will not find an existing entity row by user_id. See the
         * class docblock §"Consequence for the upsert key" for the
         * reasoning and the reliance on UserPivotCleaner.
         */
        $existing = DB::table('shareholders')->where('user_id', $user->id)->first();

        if ($existing !== null) {
            /*
             * Existing register entry: update in place. The
             * `updated_at` timestamp was set in $attributes above.
             * The `created_at` column is left untouched.
             */
            DB::table('shareholders')
                ->where('id', $existing->id)
                ->update($attributes);
            return;
        }

        /*
         * New register entry: insert with `created_at` set to now().
         * The `updated_at` column was already set in $attributes.
         */
        $attributes['created_at'] = now();
        DB::table('shareholders')->insert($attributes);
    }

    // =========================================================================
    // Pivot attachment
    // =========================================================================

    /**
     * Attach one shareholder's pivots: the tenant membership, and
     * the role(s) the register and board assignments define.
     *
     * See the class docblock §"The role attachment rules".
     *
     * @param User                 $user       the persisted user
     * @param string               $shId       the register's SH-ID,
     *                                          used to look up the
     *                                          board assignments
     * @param Tenant               $tenant     the tenant
     * @param array<string, Role>  $roles      the roles map, keyed by
     *                                          slug
     * @param User                 $superAdmin the attributor for every
     *                                          role assignment
     * @param UserAttacher         $attacher   the shared UserAttacher
     *                                          instance
     */
    private function attachRelations(
        User $user,
        string $shId,
        Tenant $tenant,
        array $roles,
        User $superAdmin,
        UserAttacher $attacher,
    ): void {
        /*
         * Tenant membership. Every shareholder is a member of the
         * seeded tenant.
         */
        $attacher->attachUserToTenant($user, $tenant);

        /*
         * Role(s). The eight board-member SH-IDs use the roles
         * Data\BoardRoleAssignments defines; every other shareholder
         * receives the default ['shareholder'].
         */
        $roleSlugs = BoardRoleAssignments::MAP[$shId] ?? ['shareholder'];

        foreach ($roleSlugs as $roleSlug) {
            $attacher->attachRole(
                $user,
                $roles[$roleSlug] ?? null,
                $superAdmin,
            );
        }
    }
}