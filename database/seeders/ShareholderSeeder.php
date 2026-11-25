<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Data\BoardRoleAssignments;
use Database\Seeders\Data\ShareholderRegister;
use Database\Seeders\Support\UserAttacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Authorization\Models\Role;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;
use RuntimeException;


/* D9/D11: preserve ShareholderType values; link entities through representative_user_id. */
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
     * Return the exact email addresses generated from the canonical demo
     * shareholder register, using the same collision-suffix algorithm as
     * seed(). This lets security maintenance target only seeded identities
     * without rotating unrelated shareholders or their representatives.
     *
     * @return list<string>
     */
    public function seededAccountEmails(): array
    {
        $usedEmails = [];
        $emails = [];

        foreach (ShareholderRegister::ROWS as $shId => $definition) {
            $email = $this->buildShareholderEmail($definition, $usedEmails);
            $usedEmails[strtolower($email)] = $shId;
            $emails[] = $email;
        }

        return $emails;
    }

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
        $isNew = ! $user->exists;

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
         * New demo accounts receive unique random secrets. They are
         * never displayed or returned; account holders establish
         * usable passwords through the verified-email reset flow.
         * Existing account passwords are preserved on ordinary reseeds.
         */
        if ($isNew) {
            $user->password = Hash::make(Str::random(64));
        }

        /*
         * Null the three audit columns if they exist on this schema
         * branch. Same guard as the admin seeder.
         */
        if ($isNew && Schema::hasColumn('users', 'password_changed_at')) {
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