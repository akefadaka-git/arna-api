<?php

declare(strict_types=1);


namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceBodyType;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

/**
 * GovernanceBody
 *
 * Eloquent model for a governance body — the General Assembly, the
 * Board of Directors, a standing committee, a subcommittee, or an
 * ad-hoc body.
 *
 * See the file-level change-verification block for the full
 * rationale on the model's shape, its relations (including the four
 * forward relations to G2b and G2c classes), its scopes, and its
 * deliberate absence of write methods.
 *
 * @package MaaleArna\Arna\Core\Domains\Governance\Models
 * @version 1.0.0
 */
final class GovernanceBody extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'governance_bodies';

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
     * Every column the service layer (G2a file 5) writes is declared
     * here. Excluded by design:
     *
     *   · `id`             — assigned by the database.
     *   · `created_at`     — managed by Eloquent's `timestamps`.
     *   · `updated_at`     — managed by Eloquent's `timestamps`.
     *   · `deleted_at`     — managed by the `SoftDeletes` trait.
     *
     * Order is Amharic-first for the bilingual pair (name_am before
     * name_en), mirroring the migration's column order.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'body_type',
        'name_am',
        'name_en',
        'parent_body_id',
        'purpose',
        'mandate',
        'quorum_rule_json',
        'meeting_frequency',
        'reporting_body_id',
        'effective_from',
        'effective_until',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * `quorum_rule_json` is cast as `array`, which yields a PHP array
     * on read and accepts a PHP array on write. Laravel serialises
     * the array to JSON on the way into the database and deserialises
     * it on the way out.
     *
     * `effective_from` and `effective_until` are cast as `date`,
     * which yields `CarbonImmutable` instances at midnight in the
     * application's configured timezone. The `date` cast (as opposed
     * to `datetime`) matches the columns' MySQL type (`date`) and
     * the concept they represent (a calendar day, not an instant).
     *
     * `body_type` is NOT cast to the enum — see the file-level
     * change-verification block §"What this model deliberately does
     * NOT do" for the reasoning. The `type()` helper resolves the
     * enum lazily.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'         => 'integer',
        'parent_body_id'    => 'integer',
        'reporting_body_id' => 'integer',
        'is_active'         => 'boolean',
        'quorum_rule_json'  => 'array',
        'effective_from'    => 'date',
        'effective_until'   => 'date',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
        'deleted_at'        => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `display_name` is available on demand
     * via `$body->display_name`, but is not force-serialised into
     * every JSON response. Resources (G2a file 11) select which
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
     * The canonical form of a governance body's code is UPPERCASE
     * with no surrounding whitespace. Normalising at the model
     * boundary means the stored value is always a single canonical
     * representation, so a `findByCode('board')` lookup and a
     * `findByCode('BOARD')` lookup produce the same result.
     *
     * This mirrors `ShareClass::code()`'s contract exactly. The two
     * are the platform's only `code`-bearing models with a
     * set-mutator; a future CR that adds a third should follow the
     * same pattern.
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
     * Get the body's locale-aware display name.
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
     * `ShareClass::getDisplayNameAttribute()`. The two models are
     * the platform's canonical bilingual reference-data accessors;
     * keeping the resolution order identical across them means a
     * UI that renders one can render the other with the same
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
    // Relations — tenants
    // =========================================================================

    /**
     * The tenant this governance body belongs to.
     *
     * Every governance body belongs to exactly one tenant. In the
     * current deployment only tenant id 1 exists, but the column,
     * the model, and the repository are all tenant-scoped from day
     * one.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    // =========================================================================
    // Relations — self-references (parent / children)
    // =========================================================================

    /**
     * The body's structural parent, if any.
     *
     * Returns null for a top-level body — one with no parent.
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_body_id', 'id');
    }

    /**
     * The bodies whose structural parent is this one.
     *
     * An empty collection for a body with no children. The relation
     * does not order by default; callers that need a stable order
     * should chain `->orderBy('code')` or similar.
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_body_id', 'id');
    }

    // =========================================================================
    // Relations — self-references (reporting)
    // =========================================================================

    /**
     * The body this body reports to, if any.
     *
     * Distinct from `parent()`. See the migration's docblock
     * §"reporting_body_id" for the reasoning behind the two separate
     * relationships.
     *
     * @return BelongsTo
     */
    public function reportingBody(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reporting_body_id', 'id');
    }

    /**
     * The bodies reporting to this one.
     *
     * The reverse of `reportingBody()`. An empty collection for a
     * body that no other body reports to.
     *
     * @return HasMany
     */
    public function reportingChildren(): HasMany
    {
        return $this->hasMany(self::class, 'reporting_body_id', 'id');
    }

    // =========================================================================
    // Relations — forward references (G2b / G2c)
    // =========================================================================
    /*
     * The four relations below reference model classes that are not
     * yet on disk. Each is declared with a fully-qualified class
     * string. Eloquent resolves the string lazily on first access;
     * a query that does not touch these relations is unaffected by
     * the target class being absent.
     *
     * See the file-level change-verification block
     * §"Forward relations — declared now, resolvable later" for the
     * reasoning and for the Shareholder-domain precedent.
     */

    /**
     * The body's positions — the Chairperson, Secretary, Member,
     * etc. slots the body defines for its membership.
     *
     * Delivered in G2b. Inert until `GovernancePosition` exists on
     * disk.
     *
     * @return HasMany
     */
    public function positions(): HasMany
    {
        return $this->hasMany(
            GovernancePosition::class,
            'governance_body_id',
            'id'
        );
    }

    /**
     * The body's terms — the time-bounded periods during which the
     * body operates with a particular composition.
     *
     * Delivered in G2b. Inert until `GovernanceTerm` exists on disk.
     *
     * @return HasMany
     */
    public function terms(): HasMany
    {
        return $this->hasMany(
            GovernanceTerm::class,
            'governance_body_id',
            'id'
        );
    }

    /**
     * The body's appointments — the instruments by which the body's
     * memberships have been created.
     *
     * Delivered in G2c. Inert until `GovernanceAppointment` exists
     * on disk.
     *
     * @return HasMany
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(
            GovernanceAppointment::class,
            'governance_body_id',
            'id'
        );
    }

    /**
     * The body's memberships — the temporal records of who occupied
     * which position in this body, when.
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
            'governance_body_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to active bodies only.
     *
     * Excludes bodies with `is_active = false`. Does NOT exclude
     * soft-deleted bodies — the `SoftDeletes` global scope handles
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
     * a body from another tenant.
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
     * Scope a query by body type.
     *
     * Accepts either the enum case or its backing string, so callers
     * do not need to normalise before invoking the scope.
     *
     * @param  Builder                            $query
     * @param  GovernanceBodyType|string          $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, GovernanceBodyType|string $type): Builder
    {
        $value = $type instanceof GovernanceBodyType ? $type->value : $type;

        return $query->where('body_type', $value);
    }

    // =========================================================================
    // Domain helpers — type resolution
    // =========================================================================

    /**
     * Resolve the body's type as an enum case.
     *
     * Returns null if the stored value is not a recognized
     * `GovernanceBodyType`. This should never happen for a row
     * written by the domain's own service, but the null return is
     * defensive: callers that branch on the type must handle the
     * null case, which prevents a corrupted row from crashing a
     * request.
     *
     * The helper resolves the enum lazily rather than via a `casts`
     * declaration — see the file-level change-verification block
     * §"What this model deliberately does NOT do".
     *
     * @return GovernanceBodyType|null
     */
    public function type(): ?GovernanceBodyType
    {
        return GovernanceBodyType::tryFrom($this->body_type);
    }

    // =========================================================================
    // Domain helpers — boolean predicates
    // =========================================================================

    /**
     * Whether the body is currently active.
     *
     * Mirrors `Shareholder::isActive()`, `ShareClass::isActive()`,
     * and `User::isActive()`. Does not consider soft-deletion — a
     * soft-deleted body is not returned by default queries at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether the body is a standing (ongoing) body.
     *
     * Delegates to `GovernanceBodyType::isStanding()`. Returns false
     * for an unrecognized type — safer than assuming "standing" for
     * a corrupted row.
     *
     * @return bool
     */
    public function isStanding(): bool
    {
        return $this->type()?->isStanding() ?? false;
    }

    /**
     * Whether the body is a temporary (ad-hoc) body.
     *
     * Delegates to `GovernanceBodyType::isTemporary()`. Returns false
     * for an unrecognized type.
     *
     * @return bool
     */
    public function isTemporary(): bool
    {
        return $this->type()?->isTemporary() ?? false;
    }

    /**
     * Whether the body has a structural parent.
     *
     * @return bool
     */
    public function hasParent(): bool
    {
        return $this->parent_body_id !== null;
    }

    /**
     * Whether the body has an explicit reporting target.
     *
     * @return bool
     */
    public function hasReportingBody(): bool
    {
        return $this->reporting_body_id !== null;
    }

    /**
     * Whether the body has a bounded effective range.
     *
     * Returns true when BOTH `effective_from` and `effective_until`
     * are non-null. A body with only one of the two set is
     * considered "open-ended" — a starting date with no known end,
     * or an end date with no known start.
     *
     * This is a read-only diagnostic, not an enforcer. The service
     * layer validates the ordering of the two dates on write.
     *
     * @return bool
     */
    public function hasEffectiveRange(): bool
    {
        return $this->effective_from !== null
            && $this->effective_until !== null;
    }
}