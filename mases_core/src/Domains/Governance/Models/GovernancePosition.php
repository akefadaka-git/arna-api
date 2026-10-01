<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 2 of 27
 * =============================================================================
 *
 * File:            Models/GovernancePosition.php  (NEW)
 * Unit:            G2b — GovernancePosition vertical slice (model)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No Eloquent model represented the
 *   `governance_positions` table. The table existed (created by G2b
 *   file 1 of 27) but there was no application object bridging the
 *   table to the domain — nothing that could load a position row,
 *   traverse its owning-body relation, expose its bilingual name to
 *   a resource layer, or provide the query scopes the repository
 *   (G2b file 3) and service (G2b file 4) will need.
 *
 * After:
 *   One file that declares the `GovernancePosition` Eloquent model.
 *   The class is deliberately read-oriented: it describes the shape
 *   of a position row, its relations to the body it belongs to and
 *   to its owning tenant, its scopes for common query filters, and
 *   its read-only accessors. It provides no write methods — every
 *   mutation flows through `GovernancePositionService` (G2b file 4
 *   of 27).
 *
 *   What the model provides:
 *
 *     · Column-to-PHP-type casting for the seven columns whose
 *       native PHP representation differs from their MySQL storage
 *       (three integer FKs and the id, three booleans, three
 *       Eloquent timestamps).
 *
 *     · One set-mutator (`code`) that normalises the position's
 *       canonical identifier to uppercase on write, mirroring
 *       `GovernanceBody::code()` and `ShareClass::code()`.
 *
 *     · One accessor (`display_name`) that resolves the
 *       locale-appropriate bilingual name with the same
 *       Amharic-first, English-fallback, code-as-ultimate-fallback
 *       policy `GovernanceBody` and `ShareClass` use.
 *
 *     · Three relations: `tenant()`, `body()`, and a forward
 *       `memberships()` to `GovernanceMembership` (target class
 *       delivered in G2c; declared as a fully-qualified class
 *       string so the model loads before its target exists).
 *
 *     · Three query scopes: `active()`, `forTenant()`, `forBody()`.
 *
 *     · Six boolean read-only helpers: `isActive()`, `isVoting()`,
 *       `isOfficer()`, `hasName()`, `isStanding()` (delegates to
 *       the parent body's type), `sortBefore()` (comparison helper
 *       for consistent client-side ordering).
 *
 *   What the model deliberately does NOT do:
 *
 *     · It does NOT provide any write method. There is no
 *       `register()`, no `update()`, no `deactivate()`, no
 *       `reactivate()`. Every one of those operations is performed
 *       by `GovernancePositionService` (G2b file 4), which
 *       validates domain invariants (code uniqueness per body,
 *       body in the same tenant, name presence), wraps the
 *       mutation in a transaction, and dispatches an event after
 *       commit.
 *
 *     · It does NOT define a `code` immutability guard. Unlike
 *       `GovernanceBody.body_type` (which is immutable because
 *       changing it invalidates the row's structural meaning), a
 *       position's code is a LABEL. An error in the initial code
 *       assignment should be correctable via update. The
 *       code-uniqueness check in the service prevents a correction
 *       from colliding with another position.
 *
 *     · It does NOT enforce the "at least one non-empty name" rule.
 *       That rule is enforced by the service at write time and by
 *       the request classes (G2b files 7, 8) at the HTTP boundary.
 *
 *     · It does NOT declare a `votingWeight` field. The schema
 *       records a boolean `is_voting` flag, not a numeric weight.
 *       A future CR that needs weighted voting introduces a
 *       separate column and model accessor; the current design
 *       follows the master prompt §10's "voting rights must be
 *       configurable" without presuming the config is a number.
 *
 *     · It does NOT define a `$with` array to eager-load relations
 *       by default. A default eager-load would impose a hidden
 *       cost on every query. Callers that need relations eager-load
 *       them explicitly. This is the platform's convention.
 *
 *     · It does NOT override `update()` or `delete()` with an
 *       immutability guard. Positions are meant to be editable —
 *       a description can be refined, a sort order adjusted, an
 *       officer flag corrected. Historic-immutability belongs to
 *       later entities (approved minutes, adopted resolutions,
 *       certified elections), not to the position record.
 *
 * =============================================================================
 * Forward relation — declared now, resolvable later
 * =============================================================================
 * The `memberships()` relation references
 * `GovernanceMembership`, which is delivered in G2c. Eloquent
 * resolves a relation's target class lazily, on first access, by
 * calling `Class::class` — a compile-time string constant, not a
 * runtime class reference. The model loads perfectly fine even
 * when `GovernanceMembership` does not exist; the class is only
 * resolved when the relation is actually traversed.
 *
 * The forward relation is declared now rather than added later
 * because:
 *
 *   · It documents the intended shape of the domain in one file.
 *   · It lets a future `GovernancePositionResource` (G2b file 6)
 *     expose `memberships` via `whenLoaded()` even before G2c
 *     lands — an empty collection is returned rather than a
 *     missing-relation error.
 *   · It prevents a future CR from having to touch this file for
 *     every downstream entity.
 *
 * A caller MUST NOT rely on the forward relation being functional
 * until its target class exists. In G2b the forward relation is
 * inert; it becomes functional when G2c delivers
 * `GovernanceMembership`.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   tenant()       — the tenant this position belongs to
 *                    (BelongsTo).
 *   body()         — the governance body this position belongs to
 *                    (BelongsTo).
 *   memberships()  — the position's memberships (HasMany)
 *                    [G2c forward — inert until G2c].
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeActive(Builder $query)                       — is_active = true
 *   scopeForTenant(Builder $query, int $tenantId)     — tenant_id = ?
 *   scopeForBody(Builder $query, int $bodyId)         — governance_body_id = ?
 *
 * =============================================================================
 * Amharic-first naming
 * =============================================================================
 * The model supplies one name accessor:
 *
 *   `display_name`  — locale-aware. Returns `name_am` in the `am`
 *                     locale, `name_en` in `en`, with a fallback
 *                     to the other language, and `code` as the
 *                     ultimate fallback so the accessor never
 *                     returns an empty string.
 *
 * There is no `full_name` accessor (unlike `Shareholder`) because a
 * governance position has no composed multi-part name — its name
 * is a single bilingual phrase, like `GovernanceBody`'s and
 * `ShareClass`'s. There is no `name` alias either, for the same
 * reason.
 *
 * =============================================================================
 * Casts summary
 * =============================================================================
 *   tenant_id           integer
 *   governance_body_id  integer
 *   is_voting           boolean
 *   is_officer          boolean
 *   sort_order          integer
 *   is_active           boolean
 *   created_at          datetime
 *   updated_at          datetime
 *   deleted_at          datetime
 *
 * No cast on `code`, `name_am`, `name_en`, `description_am`,
 * `description_en` — all strings.
 *
 * =============================================================================
 * @property int                          $id
 * @property int                          $tenant_id
 * @property int                          $governance_body_id
 * @property string                       $code
 * @property string|null                  $name_am
 * @property string|null                  $name_en
 * @property string|null                  $description_am
 * @property string|null                  $description_en
 * @property bool                         $is_voting
 * @property bool                         $is_officer
 * @property int                          $sort_order
 * @property bool                         $is_active
 * @property \Carbon\Carbon|null          $created_at
 * @property \Carbon\Carbon|null          $updated_at
 * @property \Carbon\Carbon|null          $deleted_at
 *
 * @property-read string                  $display_name   Locale-aware bilingual name.
 * @property-read \MaaleArna\MASES\Core\Tenancy\Models\Tenant|null       $tenant
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody|null $body
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * GovernancePosition
 *
 * Eloquent model for a governance position — Chairperson,
 * Secretary, Member, Observer, Alternate, Ex-officio, or any other
 * structural slot a body defines for its membership.
 *
 * See the file-level change-verification block for the model's
 * shape, its relations (including the forward relation to G2c's
 * `GovernanceMembership`), its scopes, and its deliberate absence
 * of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
final class GovernancePosition extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'governance_positions';

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
     * Every column the service layer (G2b file 4) writes is declared
     * here. Excluded by design:
     *
     *   · `id`             — assigned by the database.
     *   · `created_at`     — managed by Eloquent's `timestamps`.
     *   · `updated_at`     — managed by Eloquent's `timestamps`.
     *   · `deleted_at`     — managed by the `SoftDeletes` trait.
     *
     * Order is Amharic-first for the bilingual pairs (name_am before
     * name_en, description_am before description_en), mirroring the
     * migration's column order.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'code',
        'name_am',
        'name_en',
        'description_am',
        'description_en',
        'is_voting',
        'is_officer',
        'sort_order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * `sort_order` is cast to `integer` even though the column is
     * `unsignedSmallInteger`; PHP has no unsigned-integer type, and
     * the `integer` cast produces a plain int on read. The value's
     * non-negativity is guaranteed by the database column.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'          => 'integer',
        'governance_body_id' => 'integer',
        'is_voting'          => 'boolean',
        'is_officer'         => 'boolean',
        'sort_order'         => 'integer',
        'is_active'          => 'boolean',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
        'deleted_at'         => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `display_name` is available on demand
     * via `$position->display_name`, but is not force-serialised
     * into every JSON response. Resources (G2b file 6) select which
     * fields to expose explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Mutators
    // =========================================================================

    /**
     * Normalise the `code` attribute on set.
     *
     * The canonical form of a position's code is UPPERCASE with no
     * surrounding whitespace. Normalising at the model boundary
     * means the stored value is always a single canonical
     * representation, so a `findByCode('chairperson')` lookup and a
     * `findByCode('CHAIRPERSON')` lookup produce the same result.
     *
     * This mirrors `GovernanceBody::code()` and
     * `ShareClass::code()`'s contracts exactly. The three are the
     * platform's `code`-bearing models with a set-mutator.
     *
     * @return Attribute
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => strtoupper(trim($value)),
        );
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the position's locale-aware display name.
     *
     * Resolution order:
     *
     *   1. Amharic (`name_am`) when the active locale is `am`.
     *   2. English (`name_en`) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one
     *      is null.
     *   4. The canonical `code` as a last resort, so the accessor
     *      NEVER returns an empty string.
     *
     * The accessor is read-only. Setting `display_name` has no
     * effect; callers must set `name_am` or `name_en` directly.
     *
     * The fallback chain is byte-for-byte identical to
     * `GovernanceBody::getDisplayNameAttribute()` and
     * `ShareClass::getDisplayNameAttribute()`. The three are the
     * platform's canonical bilingual reference-data accessors;
     * keeping the resolution order identical across them means a
     * UI that renders one can render the others with the same
     * confidence.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($locale === 'am') {
            return $this->name_am ?? $this->name_en ?? $this->code;
        }

        return $this->name_en ?? $this->name_am ?? $this->code;
    }

    // =========================================================================
    // Relations — tenant and body
    // =========================================================================

    /**
     * The tenant this position belongs to.
     *
     * Every position belongs to exactly one tenant. In the current
     * deployment only tenant id 1 exists, but the column, the
     * model, and the repository are all tenant-scoped from day one.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The governance body this position belongs to.
     *
     * Every position is defined by exactly one body. The relation
     * returns the owning `GovernanceBody` model; callers that need
     * the body's own body_type, mandate, or parent traverse the
     * relation.
     *
     * @return BelongsTo
     */
    public function body(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id', 'id');
    }

    // =========================================================================
    // Relations — forward reference (G2c)
    // =========================================================================
    /*
     * The relation below references a model class that is not yet on
     * disk. It is declared with a fully-qualified class string;
     * Eloquent resolves the string lazily on first access, so a
     * query that does not touch the relation is unaffected by the
     * target class being absent.
     *
     * See the file-level change-verification block
     * §"Forward relation — declared now, resolvable later".
     */

    /**
     * The memberships that reference this position.
     *
     * A position is a structural slot; memberships are the records
     * of who occupies the slot, when, and under which appointment.
     * The relation is one-to-many: a single position (e.g. "Member"
     * on the Board) may have many memberships over time.
     *
     * Delivered in G2c. Inert until `GovernanceMembership` exists
     * on disk.
     *
     * @return HasMany
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(
            GovernanceMembership::class,
            'governance_position_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to active positions only.
     *
     * Excludes positions with `is_active = false`. Does NOT exclude
     * soft-deleted positions — the `SoftDeletes` global scope handles
     * that separately, and a caller using `withTrashed()` would
     * bypass the soft-delete filter but still honour this scope.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to a specific tenant.
     *
     * Every repository method that touches this model chains this
     * scope, so a query built against the repository cannot return
     * a position from another tenant.
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
     * The primary list query: "positions of a body". Combined with
     * an `orderBy('sort_order')` in the repository, this scope
     * produces the display-ordered list the resource layer expects.
     *
     * @param  Builder $query
     * @param  int     $bodyId
     * @return Builder
     */
    public function scopeForBody(Builder $query, int $bodyId): Builder
    {
        return $query->where('governance_body_id', $bodyId);
    }

    // =========================================================================
    // Domain helpers — read-only
    // =========================================================================

    /**
     * Whether the position is currently active.
     *
     * Mirrors `GovernanceBody::isActive()`, `Shareholder::isActive()`,
     * and every other `isActive()` helper on the platform. Does not
     * consider soft-deletion — a soft-deleted position is not
     * returned by default queries at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether the position carries voting rights.
     *
     * The flag is a property of the position. G2b stores it; G5 and
     * G8 will consume it. See the migration's docblock §"is_voting"
     * for the full rationale.
     *
     * @return bool
     */
    public function isVoting(): bool
    {
        return $this->is_voting === true;
    }

    /**
     * Whether the position is an officer position.
     *
     * The flag is a property of the position. The definition of
     * "officer" is organizational policy; the flag is a marker.
     *
     * @return bool
     */
    public function isOfficer(): bool
    {
        return $this->is_officer === true;
    }

    /**
     * Whether the position has at least one non-empty bilingual
     * name.
     *
     * Read-only diagnostic. The service layer and the request
     * classes enforce the "at least one name" invariant on write;
     * this helper is for tests and for a UI that needs to render a
     * fallback when a row is missing a name (which the service
     * prevents in normal flow).
     *
     * @return bool
     */
    public function hasName(): bool
    {
        $hasAm = is_string($this->name_am) && trim($this->name_am) !== '';
        $hasEn = is_string($this->name_en) && trim($this->name_en) !== '';

        return $hasAm || $hasEn;
    }

    /**
     * Whether the owning body is a standing (ongoing) body.
     *
     * Delegates to `GovernanceBodyType::isStanding()` on the parent
     * body's type. Returns `false` when the parent body is not
     * loaded — the check does NOT traverse the relation lazily. A
     * caller that needs the value reliably eager-loads `body` first.
     *
     * This helper exists so that a UI can distinguish positions
     * that belong to a standing body (their memberships persist
     * until dissolution) from positions that belong to an ad-hoc
     * body (their memberships end with the body's purpose).
     *
     * @return bool
     */
    public function isStanding(): bool
    {
        if (!$this->relationLoaded('body')) {
            return false;
        }

        return $this->body?->isStanding() ?? false;
    }

    /**
     * Whether this position's `sort_order` is strictly less than the
     * given value.
     *
     * A convenience helper for a caller that is composing a
     * client-side ordering. Returns `false` when this position's
     * `sort_order` equals the given value (a tie) — the caller is
     * expected to fall back to a secondary key (typically `id`) for
     * ties.
     *
     * The helper does not replace the repository's `ORDER BY`
     * clause; it exists for a caller that is inserting a new
     * position between two existing ones and wants to compute an
     * appropriate `sort_order` value.
     *
     * @param  int  $other
     * @return bool
     */
    public function sortBefore(int $other): bool
    {
        return $this->sort_order < $other;
    }
}