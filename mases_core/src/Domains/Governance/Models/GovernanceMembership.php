<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 18 of 31
 * =============================================================================
 *
 * File:            Models/GovernanceMembership.php  (NEW)
 * Unit:            G2c — GovernanceMembership vertical slice (model)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No Eloquent model represented the
 *   `governance_memberships` table. The table existed (created by
 *   G2c file 16 of 31) and its vocabulary enum existed (G2c file 17
 *   of 31), but there was no application object bridging them to
 *   the domain.
 *
 *   As a consequence, three forward relations declared earlier in
 *   this domain were still INERT:
 *
 *     · `GovernanceBody::memberships()`      (G2a file 3 of 17)
 *     · `GovernancePosition::memberships()`  (G2b file 2 of 27)
 *     · `GovernanceTerm::memberships()`      (G2b file 14 of 27)
 *     · `GovernanceAppointment::memberships()` (G2c file 5 of 31)
 *
 *   Each declared `hasMany(GovernanceMembership::class, ...)` using
 *   a fully-qualified class string. Eloquent resolved the target
 *   lazily; accessing any of the four relations before this file
 *   landed would have thrown a class-not-found error. With this
 *   file on disk, all four forward relations become functional.
 *
 * After:
 *   One file that declares the `GovernanceMembership` Eloquent
 *   model. The class is deliberately read-oriented: it describes
 *   the shape of a membership row, its relations to the body,
 *   position, term, and appointment it references and to the user
 *   (when one exists), its scopes for common query filters, and its
 *   read-only accessors, enum resolver, and domain helpers. It
 *   provides no write methods — every mutation flows through
 *   `GovernanceMembershipService` (G2c file 20 of 31).
 *
 *   What the model provides:
 *
 *     · Column-to-PHP-type casting for the columns whose native PHP
 *       representation differs from MySQL storage (five integer FKs
 *       plus `user_id` plus the id, two date columns, one boolean,
 *       three Eloquent timestamps).
 *
 *     · One accessor (`display_name`) that resolves the
 *       locale-appropriate member name via the same fallback chain
 *       the sibling models use: the membership's own free-text name
 *       columns first, the loaded user relation second, the
 *       external reference third.
 *
 *     · Six relations to existing classes: `tenant()`, `body()`,
 *       `position()`, `term()`, `appointment()`, `user()`.
 *
 *     · No forward relations. This is the last entity in the
 *       current G2c unit; there is nothing downstream of a
 *       membership that does not yet exist.
 *
 *     · Six query scopes: `forTenant()`, `forBody()`, `active()`,
 *       `ongoing()`, `terminal()`, `current()`.
 *
 *     · One enum resolver (`statusEnum()`) with the same
 *       null-returning contract the sibling resolvers use.
 *
 *     · Twelve boolean read-only helpers: `isActive()`,
 *       `isSuspended()`, `isEnded()`, `isResigned()`, `isRemoved()`,
 *       `isExpired()`, `isTerminal()`, `isOngoing()`, `isCurrent()`,
 *       `isExOfficio()`, `hasUser()`, `hasName()`,
 *       `hasExternalReference()`, `hasEndDate()`, `hasAppointment()`,
 *       `hasTerm()`.
 *
 *     · One domain helper (`memberKey()`) that produces a stable
 *       string identifying the member, used by the service layer's
 *       overlap check (ruling B4) and by tests. The helper is
 *       documented as a PHP-only aid; the actual overlap query is
 *       constructed by the service.
 *
 *   What the model deliberately does NOT do:
 *
 *     · It does NOT provide any write method. There is no
 *       `register()`, no `update()`, no `end()`, no `suspend()`,
 *       no `resign()`, no `remove()`. Every one of those
 *       operations is performed by
 *       `GovernanceMembershipService` (G2c file 20 of 31).
 *
 *     · It does NOT define an immutability guard on
 *       `governance_body_id`, `governance_position_id`,
 *       `governance_term_id`, or `governance_appointment_id`.
 *       Immutability of those four FK columns is enforced by the
 *       service layer, which refuses any `update()` call whose FK
 *       values differ from the current ones.
 *
 *     · It does NOT cast the `status` column at the Eloquent layer.
 *       The column is stored as VARCHAR and cast to string; the
 *       `statusEnum()` resolver translates on demand. See the
 *       file-level change-verification block §"Why the status
 *       column is not an Eloquent cast" for the full reasoning,
 *       which mirrors the identical decision the four sibling
 *       models made for their own enum-typed columns.
 *
 *     · It does NOT declare an `$appends` array.
 *
 *     · It does NOT define a `$with` array to eager-load relations
 *       by default.
 *
 *     · It does NOT override `update()` or `delete()` with an
 *       immutability guard.
 *
 * =============================================================================
 * Why the status column is not an Eloquent cast
 * =============================================================================
 * The model has one enum-typed column:
 *
 *     status  →  GovernanceMembershipStatus
 *
 * A reader might reasonably expect it to be cast via the `$casts`
 * array as `GovernanceMembershipStatus::class`. The model does NOT
 * do this, deliberately. The reasoning is the same as the four
 * sibling models' for their own enum-typed columns:
 *
 *   · Raw string queries against the column remain usable. A
 *     caller that wants to write `->where('status', 'active')`
 *     does not need to know the enum exists.
 *
 *   · A row whose column holds an unrecognized value — a
 *     historical import, a hand-edited row, a future CR that added
 *     a value to the enum without a data migration — can be
 *     LOADED and DIAGNOSED. The resolver returns null, the caller
 *     decides what to do, and the row's other attributes remain
 *     readable. With an Eloquent enum cast, accessing the
 *     attribute on such a row throws a PHP `ValueError` that
 *     crashes the request.
 *
 *   · The resolver gives the caller an explicit, named entry
 *     point: `$membership->statusEnum()` reads as "resolve the
 *     status enum" and its nullable return type is a visible
 *     part of the contract.
 *
 * =============================================================================
 * The member reference — four columns, one logical person
 * =============================================================================
 * The migration (G2c file 16 of 31) declares four columns that
 * together identify the member:
 *
 *     user_id             — the platform account, when one exists.
 *     member_name_am      — the Amharic name for a non-user member.
 *     member_name_en      — the English name for a non-user member.
 *     external_reference  — an external identifier.
 *
 * The service layer requires at least one of these to be present;
 * the schema itself is permissive. See the migration's docblock
 * §"Ruling B1" for the full reasoning.
 *
 * The `memberKey()` helper produces a stable string from whichever
 * of the four columns is present, preferring `user_id` (the
 * strongest identity), then `external_reference`, then the
 * bilingual name pair. The helper is used by the service's overlap
 * check and by tests; its exact output format is documented on the
 * method itself.
 *
 * =============================================================================
 * The relations, in full
 * =============================================================================
 *   tenant()       — the tenant this membership belongs to
 *                    (BelongsTo, mandatory).
 *   body()         — the governance body this membership belongs
 *                    to (BelongsTo, mandatory).
 *   position()     — the position the member occupies (BelongsTo,
 *                    mandatory).
 *   term()         — the optional term (BelongsTo, nullable).
 *   appointment()  — the optional appointment that created this
 *                    membership (BelongsTo, nullable).
 *   user()         — the optional platform user account
 *                    (BelongsTo, nullable).
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForTenant(Builder $query, int $tenantId)  — tenant_id = ?
 *   scopeForBody(Builder $query, int $bodyId)      — governance_body_id = ?
 *   scopeActive(Builder $query)                    — status = 'active'
 *   scopeOngoing(Builder $query)                   — status IN ('active','suspended')
 *   scopeTerminal(Builder $query)                  — status IN ('ended','resigned','removed','expired')
 *   scopeCurrent(Builder $query)                   — date range contains today
 *
 * The `current` scope matches `isCurrent()` exactly — a query
 * filtered by `current()` returns the same set of rows for which
 * `isCurrent()` returns true. It does NOT filter by status; a
 * caller that wants "ongoing AND current" chains
 * `->ongoing()->current()`.
 *
 * =============================================================================
 * Casts summary
 * =============================================================================
 *   tenant_id                integer
 *   governance_body_id       integer
 *   governance_position_id   integer
 *   governance_term_id       integer
 *   governance_appointment_id integer
 *   user_id                  integer
 *   start_date               date  (yields CarbonImmutable at midnight)
 *   end_date                 date  (yields CarbonImmutable at midnight)
 *   is_ex_officio            boolean
 *   created_at               datetime
 *   updated_at               datetime
 *   deleted_at               datetime
 *
 * No cast on `status` — see §"Why the status column is not an
 * Eloquent cast".
 *
 * No cast on `member_name_am`, `member_name_en`,
 * `external_reference`, `ended_reason`, `notes_am`, `notes_en` —
 * all strings.
 *
 * =============================================================================
 * @property int                          $id
 * @property int                          $tenant_id
 * @property int                          $governance_body_id
 * @property int                          $governance_position_id
 * @property int|null                     $governance_term_id
 * @property int|null                     $governance_appointment_id
 * @property int|null                     $user_id
 * @property string|null                  $member_name_am
 * @property string|null                  $member_name_en
 * @property string|null                  $external_reference
 * @property \Carbon\CarbonImmutable      $start_date
 * @property \Carbon\CarbonImmutable|null $end_date
 * @property string                       $status  Raw string; use statusEnum() for the enum.
 * @property bool                         $is_ex_officio
 * @property string|null                  $ended_reason
 * @property string|null                  $notes_am
 * @property string|null                  $notes_en
 * @property \Carbon\Carbon|null          $created_at
 * @property \Carbon\Carbon|null          $updated_at
 * @property \Carbon\Carbon|null          $deleted_at
 *
 * @property-read string                  $display_name   Locale-aware member name.
 * @property-read \MaaleArna\MASES\Core\Tenancy\Models\Tenant|null                                       $tenant
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody|null                    $body
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition|null                $position
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm|null                    $term
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceAppointment|null             $appointment
 * @property-read \MaaleArna\MASES\Core\Auth\Models\User|null                                            $user
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceMembershipStatus;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * GovernanceMembership
 *
 * Eloquent model for a governance membership — the temporal record
 * of one person occupying one position in one body, from a start
 * date, for a bounded or open-ended period.
 *
 * See the file-level change-verification block for the model's
 * shape, its six relations (all backed by real classes on disk —
 * this is the last entity in G2c), its six scopes, its enum
 * resolver, its `memberKey()` helper for the overlap invariant,
 * and its deliberate absence of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
final class GovernanceMembership extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'governance_memberships';

    /**
     * The primary key column.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Whether the primary key auto-increments.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The primary key type.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * Every column the service layer (G2c file 20 of 31) writes is
     * declared here. Excluded by design:
     *
     *   · `id`             — assigned by the database.
     *   · `created_at`     — managed by Eloquent's `timestamps`.
     *   · `updated_at`     — managed by Eloquent's `timestamps`.
     *   · `deleted_at`     — managed by the `SoftDeletes` trait.
     *
     * Order is Amharic-first for the two bilingual pairs
     * (member_name_am before member_name_en, notes_am before
     * notes_en), mirroring the migration's column order.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
        'governance_appointment_id',
        'user_id',
        'member_name_am',
        'member_name_en',
        'external_reference',
        'start_date',
        'end_date',
        'status',
        'is_ex_officio',
        'ended_reason',
        'notes_am',
        'notes_en',
    ];

    /**
     * The attributes that should be cast.
     *
     * `start_date` and `end_date` are cast as `date`, which yields
     * `CarbonImmutable` instances at midnight in the application's
     * configured timezone. The `date` cast (as opposed to
     * `datetime`) matches the columns' MySQL type (`date`) and the
     * concept they represent (a calendar day, not an instant).
     *
     * The `status` column is NOT cast. See the file-level
     * change-verification block §"Why the status column is not an
     * Eloquent cast".
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'                => 'integer',
        'governance_body_id'       => 'integer',
        'governance_position_id'   => 'integer',
        'governance_term_id'       => 'integer',
        'governance_appointment_id'=> 'integer',
        'user_id'                  => 'integer',
        'start_date'               => 'date',
        'end_date'                 => 'date',
        'is_ex_officio'            => 'boolean',
        'created_at'               => 'datetime',
        'updated_at'               => 'datetime',
        'deleted_at'               => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `display_name` is available on demand
     * via `$membership->display_name` but is not force-serialised.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the membership's locale-aware member display name.
     *
     * Resolution order:
     *
     *   1. Amharic (`member_name_am`) when the active locale is `am`.
     *   2. English (`member_name_en`) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one
     *      is null or empty.
     *   4. The loaded user relation's `display_name` — when the
     *      relation is eager-loaded. The accessor does NOT traverse
     *      the relation lazily; a caller that needs the user's name
     *      eager-loads the relation first. This mirrors the
     *      "don't trigger a lazy load" posture the sibling models'
     *      `isStanding()` helper uses.
     *   5. `external_reference` — the identifier for a non-user
     *      member whose name was not recorded.
     *   6. An empty string when none of the above is present.
     *
     * The accessor is read-only. Setting `display_name` has no
     * effect; callers must set `member_name_am`, `member_name_en`,
     * or `user_id` directly.
     *
     * The precedence order reflects the platform's Amharic-first
     * policy AND the historical primacy of the membership's own
     * name column: the name as it appeared on the source instrument
     * takes priority over the current name on the user record,
     * because a name may change over time and the historical
     * membership should reflect the name as recorded.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        $name = $locale === 'am'
            ? ($this->member_name_am ?? $this->member_name_en)
            : ($this->member_name_en ?? $this->member_name_am);

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        // Fall back to the loaded user relation, if any.
        if ($this->relationLoaded('user') && $this->user !== null) {
            $userName = $this->user->display_name;

            if (is_string($userName) && trim($userName) !== '') {
                return $userName;
            }
        }

        if (is_string($this->external_reference) && trim($this->external_reference) !== '') {
            return $this->external_reference;
        }

        return '';
    }

    // =========================================================================
    // Relations — all backed by real classes on disk
    // =========================================================================

    /**
     * The tenant this membership belongs to.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The governance body this membership belongs to.
     *
     * @return BelongsTo
     */
    public function body(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id', 'id');
    }

    /**
     * The position the member occupies.
     *
     * @return BelongsTo
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(GovernancePosition::class, 'governance_position_id', 'id');
    }

    /**
     * The optional term this membership is scoped to.
     *
     * Null for term-less memberships — typically ex-officio seats
     * whose duration is determined by an underlying role.
     *
     * @return BelongsTo
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(GovernanceTerm::class, 'governance_term_id', 'id');
    }

    /**
     * The optional appointment that created this membership.
     *
     * Null for memberships with no appointment instrument — an
     * ex-officio seat, a direct recording, or a legacy import.
     *
     * @return BelongsTo
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(GovernanceAppointment::class, 'governance_appointment_id', 'id');
    }

    /**
     * The optional platform user account for this member.
     *
     * Null when the member is not a platform user. The free-text
     * name columns carry the member's identity in that case.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific tenant.
     *
     * @param  Builder $query
     * @param  int     $tenantId
     * @return Builder
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to a specific body.
     *
     * The primary list query: "memberships of a body". Combined
     * with an `orderBy('start_date', 'desc')` in the repository,
     * this scope produces the reverse-chronological list the
     * resource layer expects — the most recent membership first.
     *
     * @param  Builder $query
     * @param  int     $bodyId
     * @return Builder
     */
    public function scopeForBody(Builder $query, int $bodyId): Builder
    {
        return $query->where('governance_body_id', $bodyId);
    }

    /**
     * Scope a query to memberships with status `active`.
     *
     * A narrow scope that filters to the single `active` state. A
     * caller that wants "active OR suspended" uses `scopeOngoing()`.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GovernanceMembershipStatus::Active->value);
    }

    /**
     * Scope a query to ongoing memberships (active OR suspended).
     *
     * Matches the `GovernanceMembershipStatus::isOngoing()`
     * partition. Used by the service layer's overlap check (ruling
     * B4) to compare a candidate membership against every
     * membership that currently reserves a seat.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeOngoing(Builder $query): Builder
    {
        return $query->whereIn('status', [
            GovernanceMembershipStatus::Active->value,
            GovernanceMembershipStatus::Suspended->value,
        ]);
    }

    /**
     * Scope a query to terminal memberships (ended, resigned,
     * removed, or expired).
     *
     * Matches the `GovernanceMembershipStatus::isTerminal()`
     * partition — the inverse of `scopeOngoing()`.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            GovernanceMembershipStatus::Ended->value,
            GovernanceMembershipStatus::Resigned->value,
            GovernanceMembershipStatus::Removed->value,
            GovernanceMembershipStatus::Expired->value,
        ]);
    }

    /**
     * Scope a query to memberships whose date range contains today.
     *
     * Matches the `isCurrent()` accessor exactly. A membership is
     * "current" when:
     *
     *   · `start_date <= today`, AND
     *   · `end_date IS NULL OR end_date >= today`.
     *
     * The scope does NOT filter by status. An inactive membership
     * whose dates contain today is still "current" in the temporal
     * sense. A caller that wants "ongoing AND current" chains
     * `->ongoing()->current()`.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query
            ->where('start_date', '<=', $today)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $today);
            });
    }

    // =========================================================================
    // Enum resolver
    // =========================================================================

    /**
     * Resolve the membership's status as an enum case.
     *
     * Returns null if the stored value is not a recognized
     * `GovernanceMembershipStatus`. This should never happen for a
     * row written by the domain's own service, but the null return
     * is defensive: callers that branch on the status must handle
     * the null case.
     *
     * @return GovernanceMembershipStatus|null
     */
    public function statusEnum(): ?GovernanceMembershipStatus
    {
        return GovernanceMembershipStatus::tryFrom($this->status);
    }

    // =========================================================================
    // Domain helpers — status predicates (delegate to the enum)
    // =========================================================================

    /**
     * Whether the membership is in the Active state.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->statusEnum()?->isActive() ?? false;
    }

    /**
     * Whether the membership is in the Suspended state.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->statusEnum()?->isSuspended() ?? false;
    }

    /**
     * Whether the membership is in the Ended state.
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return $this->statusEnum()?->isEnded() ?? false;
    }

    /**
     * Whether the membership is in the Resigned state.
     *
     * @return bool
     */
    public function isResigned(): bool
    {
        return $this->statusEnum()?->isResigned() ?? false;
    }

    /**
     * Whether the membership is in the Removed state.
     *
     * @return bool
     */
    public function isRemoved(): bool
    {
        return $this->statusEnum()?->isRemoved() ?? false;
    }

    /**
     * Whether the membership is in the Expired state.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->statusEnum()?->isExpired() ?? false;
    }

    /**
     * Whether the membership's status is terminal.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->statusEnum()?->isTerminal() ?? false;
    }

    /**
     * Whether the membership's status is ongoing.
     *
     * @return bool
     */
    public function isOngoing(): bool
    {
        return $this->statusEnum()?->isOngoing() ?? false;
    }

    // =========================================================================
    // Domain helpers — temporal and structural predicates
    // =========================================================================

    /**
     * Whether the membership's date range contains today.
     *
     * A membership is "current" when today falls within its
     * effective range:
     *
     *   · `start_date <= today`, AND
     *   · `end_date IS NULL OR end_date >= today`.
     *
     * The accessor does NOT consider status. An active membership
     * whose natural end date has passed (a data-entry defect the
     * domain does not correct automatically) is "not current" by
     * this helper. A caller that wants "ongoing AND current" tests
     * both `isOngoing()` and `isCurrent()` explicitly.
     *
     * @return bool
     */
    public function isCurrent(): bool
    {
        $today = Carbon::today();

        if ($this->start_date !== null && $this->start_date->greaterThan($today)) {
            return false;
        }

        if ($this->end_date !== null && $this->end_date->lessThan($today)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the membership is an ex-officio seat.
     *
     * Delegates to the `is_ex_officio` boolean column. See the
     * migration's docblock §"Ruling B5" for the distinction between
     * this flag and the appointment's `appointment_mode`.
     *
     * @return bool
     */
    public function isExOfficio(): bool
    {
        return $this->is_ex_officio === true;
    }

    // =========================================================================
    // Domain helpers — presence checks
    // =========================================================================

    /**
     * Whether the membership references a platform user account.
     *
     * @return bool
     */
    public function hasUser(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Whether the membership carries at least one non-empty
     * bilingual member name.
     *
     * @return bool
     */
    public function hasName(): bool
    {
        $hasAm = is_string($this->member_name_am) && trim($this->member_name_am) !== '';
        $hasEn = is_string($this->member_name_en) && trim($this->member_name_en) !== '';

        return $hasAm || $hasEn;
    }

    /**
     * Whether the membership carries a non-empty external
     * reference.
     *
     * @return bool
     */
    public function hasExternalReference(): bool
    {
        return is_string($this->external_reference)
            && trim($this->external_reference) !== '';
    }

    /**
     * Whether the membership has an upper date bound.
     *
     * @return bool
     */
    public function hasEndDate(): bool
    {
        return $this->end_date !== null;
    }

    /**
     * Whether the membership references an appointment.
     *
     * @return bool
     */
    public function hasAppointment(): bool
    {
        return $this->governance_appointment_id !== null;
    }

    /**
     * Whether the membership is scoped to a term.
     *
     * @return bool
     */
    public function hasTerm(): bool
    {
        return $this->governance_term_id !== null;
    }

    // =========================================================================
    // Domain helpers — member identity
    // =========================================================================

    /**
     * Produce a stable string identifier for the member.
     *
     * The identifier is derived from whichever of the four
     * member-reference columns is present, in this precedence:
     *
     *   1. `user_id`              →  `user:{id}`
     *   2. `external_reference`   →  `ext:{reference}` (trimmed)
     *   3. `member_name_am` + `member_name_en`
     *                             →  `name:{am}|{en}` (both trimmed;
     *                                 absent side is empty)
     *   4. `member_name_am` alone →  `name:{am}|`
     *   5. `member_name_en` alone →  `name:|{en}`
     *   6. none of the above      →  empty string
     *
     * The string is used by the service layer's overlap check
     * (ruling B4) to identify members consistently across the rows
     * the check compares, and by tests that assert two memberships
     * are for the same person.
     *
     * IMPORTANT — this helper is a PHP-only aid. The service layer's
     * overlap query is an SQL query, not a PHP loop over models.
     * The service constructs the WHERE clause from the same
     * precedence the helper documents; this method exists so the
     * precedence has a single authoritative declaration that the
     * service's docblock can reference.
     *
     * The helper never returns a value derived from `id`; two
     * unsaved memberships for the same person produce the same key,
     * which is the intended behaviour for the overlap check.
     *
     * @return string
     */
    public function memberKey(): string
    {
        if ($this->user_id !== null) {
            return 'user:' . (int) $this->user_id;
        }

        if ($this->hasExternalReference()) {
            return 'ext:' . trim((string) $this->external_reference);
        }

        $nameAm = is_string($this->member_name_am) ? trim($this->member_name_am) : '';
        $nameEn = is_string($this->member_name_en) ? trim($this->member_name_en) : '';

        if ($nameAm !== '' || $nameEn !== '') {
            return 'name:' . $nameAm . '|' . $nameEn;
        }

        return '';
    }
}