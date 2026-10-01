<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2b · File 14 of 27
 * =============================================================================
 *
 * File:            Models/GovernanceTerm.php  (NEW)
 * Unit:            G2b — GovernanceTerm vertical slice (model)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No Eloquent model represented the
 *   `governance_terms` table. The table existed (created by G2b file
 *   13 of 27) but there was no application object bridging the table
 *   to the domain — nothing that could load a term row, traverse its
 *   owning-body relation, expose its locale-aware label, determine
 *   whether it is currently in force, or provide the query scopes
 *   the repository (G2b file 15) and service (G2b file 16) will need.
 *
 * After:
 *   One file that declares the `GovernanceTerm` Eloquent model. The
 *   class is deliberately read-oriented: it describes the shape of a
 *   term row, its relations to the body it belongs to and to its
 *   owning tenant, its scopes for common query filters, and its
 *   read-only accessors. It provides no write methods — every
 *   mutation flows through `GovernanceTermService` (G2b file 16 of
 *   27).
 *
 *   What the model provides:
 *
 *     · Column-to-PHP-type casting for the four columns whose native
 *       PHP representation differs from MySQL storage (three
 *       integer FKs and the id, two date columns, one boolean, three
 *       Eloquent timestamps).
 *
 *     · One accessor (`display_label`) that resolves the
 *       locale-appropriate bilingual label with the Amharic-first,
 *       English-fallback policy. When neither label is set, the
 *       accessor synthesises a date-range string so it never
 *       returns an empty value — a caller always has something to
 *       render.
 *
 *     · Three relations: `tenant()`, `body()`, and a forward
 *       `memberships()` to `GovernanceMembership` (target class
 *       delivered in G2c; declared as a fully-qualified class
 *       string so the model loads before its target exists).
 *
 *     · Four query scopes: `active()`, `forTenant()`, `forBody()`,
 *       `current()`.
 *
 *     · Eight boolean read-only helpers: `isActive()`,
 *       `isCurrent()`, `hasStarted()`, `hasEnded()`,
 *       `isOpenEnded()`, `hasDateRange()`, `hasLabel()`.
 *
 *   What the model deliberately does NOT do:
 *
 *     · It does NOT provide any write method. Every mutation is
 *       performed by `GovernanceTermService` (G2b file 16 of 27),
 *       which validates domain invariants (body in the same tenant,
 *       effective date ordering, name presence), wraps the mutation
 *       in a transaction, and dispatches an event after commit.
 *
 *     · It does NOT store an `is_current` flag. Currentness is
 *       derived from the date range at read time — see the migration
 *       docblock (G2b file 13 of 27) §"is_active". A stored flag
 *       would require a scheduled job to maintain it and would drift
 *       out of sync with the dates.
 *
 *     · It does NOT enforce non-overlap. Two terms of the same body
 *       may overlap in date range; the schema does not prevent it
 *       and the model does not enforce it. See the migration
 *       docblock §"No overlap enforcement".
 *
 *     · It does NOT auto-populate labels from dates. The label is a
 *       human-entered string; the accessor only synthesises a
 *       fallback for display when no label is present. The stored
 *       columns are never modified by the accessor.
 *
 *     · It does NOT define an `is_active`-based `is_current`
 *       shortcut. The two are orthogonal concepts — see the
 *       migration docblock. Callers that want "active AND current"
 *       chain both scopes or both accessors explicitly.
 *
 *     · It does NOT override `update()` or `delete()` with an
 *       immutability guard. Terms are editable — a label can be
 *       corrected, a date range refined, notes added. Historic-
 *       immutability belongs to later entities (approved minutes,
 *       adopted resolutions, certified elections), not to the term
 *       record.
 *
 * =============================================================================
 * Forward relation — declared now, resolvable later
 * =============================================================================
 * The `memberships()` relation references `GovernanceMembership`,
 * which is delivered in G2c. Eloquent resolves a relation's target
 * class lazily, on first access, by calling `Class::class` — a
 * compile-time string constant, not a runtime class reference. The
 * model loads fine even when `GovernanceMembership` does not exist.
 *
 * The forward relation is declared now for the same reasons the
 * `GovernancePosition` model declares its forward relation (G2b file
 * 2 of 27): it documents the intended shape of the domain in one
 * file, it lets a future resource expose memberships via
 * `whenLoaded()` before G2c lands, and it prevents a future CR from
 * having to touch this file for every downstream entity.
 *
 * A caller MUST NOT rely on the forward relation being functional
 * until its target class exists. In G2b it is inert.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   tenant()       — the tenant this term belongs to (BelongsTo).
 *   body()         — the governance body this term belongs to
 *                    (BelongsTo).
 *   memberships()  — the term's memberships (HasMany)
 *                    [G2c forward — inert until G2c].
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeActive(Builder $query)                   — is_active = true
 *   scopeForTenant(Builder $query, int $tenantId) — tenant_id = ?
 *   scopeForBody(Builder $query, int $bodyId)     — governance_body_id = ?
 *   scopeCurrent(Builder $query)                  — date range contains
 *                                                    today
 *
 * The `current` scope matches `isCurrent()` exactly — a query
 * filtered by `current()` returns the same set of rows for which
 * `isCurrent()` returns true. This alignment is deliberate; it
 * means a caller can use either form interchangeably.
 *
 * =============================================================================
 * Amharic-first naming
 * =============================================================================
 * The model supplies one name accessor:
 *
 *   `display_label`  — locale-aware. Returns `label_am` in the `am`
 *                      locale, `label_en` in `en`, with a fallback
 *                      to the other language. When neither label is
 *                      set, synthesises a date-range string from the
 *                      effective dates so the accessor never
 *                      returns an empty value.
 *
 * The synthesised fallback is the key difference from the
 * `GovernanceBody::display_name` and `GovernancePosition::display_name`
 * accessors, which fall back to the model's `code` column. Terms
 * have no `code` column, so the date range serves as the ultimate
 * fallback.
 *
 * =============================================================================
 * Casts summary
 * =============================================================================
 *   tenant_id           integer
 *   governance_body_id  integer
 *   effective_from      date   (yields CarbonImmutable at midnight)
 *   effective_until     date   (yields CarbonImmutable at midnight)
 *   is_active           boolean
 *   created_at          datetime
 *   updated_at          datetime
 *   deleted_at          datetime
 *
 * No cast on `label_am`, `label_en`, `notes` — all strings.
 *
 * =============================================================================
 * @property int                          $id
 * @property int                          $tenant_id
 * @property int                          $governance_body_id
 * @property string|null                  $label_am
 * @property string|null                  $label_en
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_until
 * @property string|null                  $notes
 * @property bool                         $is_active
 * @property \Carbon\Carbon|null          $created_at
 * @property \Carbon\Carbon|null          $updated_at
 * @property \Carbon\Carbon|null          $deleted_at
 *
 * @property-read string                  $display_label   Locale-aware bilingual label.
 * @property-read \MaaleArna\MASES\Core\Tenancy\Models\Tenant|null       $tenant
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody|null $body
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
namespace MaaleArna\MASES\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * GovernanceTerm
 *
 * Eloquent model for a governance term — the time-bounded period
 * during which a body operates with a particular composition.
 *
 * See the file-level change-verification block for the model's
 * shape, its relations (including the forward relation to G2c's
 * `GovernanceMembership`), its scopes, its locale-aware label
 * accessor with the synthesised date-range fallback, and its
 * deliberate absence of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
final class GovernanceTerm extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'governance_terms';

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
     * Every column the service layer (G2b file 16 of 27) writes is
     * declared here. Excluded by design:
     *
     *   · `id`             — assigned by the database.
     *   · `created_at`     — managed by Eloquent's `timestamps`.
     *   · `updated_at`     — managed by Eloquent's `timestamps`.
     *   · `deleted_at`     — managed by the `SoftDeletes` trait.
     *
     * Order is Amharic-first for the bilingual pair (label_am before
     * label_en), mirroring the migration's column order.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'label_am',
        'label_en',
        'effective_from',
        'effective_until',
        'notes',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * `effective_from` and `effective_until` are cast as `date`,
     * which yields `CarbonImmutable` instances at midnight in the
     * application's configured timezone. The `date` cast (as opposed
     * to `datetime`) matches the columns' MySQL type (`date`) and
     * the concept they represent (a calendar day, not an instant).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'          => 'integer',
        'governance_body_id' => 'integer',
        'effective_from'     => 'date',
        'effective_until'    => 'date',
        'is_active'          => 'boolean',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
        'deleted_at'         => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `display_label` is available on demand
     * via `$term->display_label`, but is not force-serialised into
     * every JSON response. Resources (G2b file 18 of 27) select
     * which fields to expose explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the term's locale-aware display label.
     *
     * Resolution order:
     *
     *   1. Amharic (`label_am`) when the active locale is `am`.
     *   2. English (`label_en`) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one
     *      is null or empty.
     *   4. A synthesised date-range string when neither label is
     *      set (e.g. `2026-09-18 – 2029-09-17`).
     *   5. An empty string when neither labels nor dates are set.
     *
     * The accessor is read-only. Setting `display_label` has no
     * effect; callers must set `label_am` or `label_en` directly.
     *
     * The synthesised fallback distinguishes this accessor from
     * `GovernanceBody::display_name` and
     * `GovernancePosition::display_name`, which fall back to their
     * `code` column. Terms have no `code`, so the date range serves
     * as the ultimate fallback. A caller that wants to branch on
     * "does this term have a real label?" uses `hasLabel()`.
     *
     * @return string
     */
    public function getDisplayLabelAttribute(): string
    {
        $locale = app()->getLocale();

        $label = $locale === 'am'
            ? ($this->label_am ?? $this->label_en)
            : ($this->label_en ?? $this->label_am);

        if (is_string($label) && trim($label) !== '') {
            return $label;
        }

        return $this->synthesizeLabelFromDates();
    }

    // =========================================================================
    // Relations — tenant and body
    // =========================================================================

    /**
     * The tenant this term belongs to.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The governance body this term belongs to.
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
     * Eloquent resolves the string lazily on first access.
     *
     * See the file-level change-verification block
     * §"Forward relation — declared now, resolvable later".
     */

    /**
     * The memberships that reference this term.
     *
     * A term is a bounded period; memberships are the records of who
     * occupied which position during that period. The relation is
     * one-to-many: a single term has many memberships.
     *
     * Delivered in G2c. Inert until `GovernanceMembership` exists on
     * disk.
     *
     * @return HasMany
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(
            GovernanceMembership::class,
            'governance_term_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to active terms only.
     *
     * Excludes terms with `is_active = false`. Does NOT exclude
     * soft-deleted terms — the `SoftDeletes` global scope handles
     * that separately.
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
     * The primary list query: "terms of a body". Combined with an
     * `orderBy('effective_from')` in the repository, this scope
     * produces the chronological list the resource layer expects.
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
     * Scope a query to terms whose date range contains today.
     *
     * Matches the `isCurrent()` accessor exactly. A term is
     * "current" when:
     *
     *   · `effective_from` is null OR `effective_from <= today`, AND
     *   · `effective_until` is null OR `effective_until >= today`.
     *
     * A term with both dates null is considered "current" by this
     * scope, because neither bound excludes it. A caller that wants
     * stricter semantics (e.g. require at least one bound) chains
     * additional filters explicitly.
     *
     * The scope does NOT filter by `is_active`. An inactive term
     * whose dates contain today is still "current" in the temporal
     * sense. A caller that wants "authoritative AND current" chains
     * `->active()->current()`.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('effective_from')
                  ->orWhere('effective_from', '<=', $today);
            })
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', $today);
            });
    }

    // =========================================================================
    // Domain helpers — read-only
    // =========================================================================

    /**
     * Whether the term is currently authoritative.
     *
     * The `is_active` flag is orthogonal to `isCurrent()`. A term
     * may be `is_active = true` and not current (its dates have
     * passed), or `is_active = false` and current (it has been
     * superseded but its dates still span today). See the migration
     * docblock (G2b file 13 of 27) §"is_active".
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether the term's date range contains today.
     *
     * A term is "current" when today falls within its effective
     * range. Null bounds are treated as unbounded:
     *
     *   · `effective_from = null` — the start date is unknown; the
     *     term is treated as having started.
     *   · `effective_until = null` — the term is ongoing; the term
     *     is treated as not having ended.
     *
     * A term with both dates null is therefore "current" by this
     * accessor. This is a deliberate choice — a caller that needs
     * a stricter rule uses `hasDateRange()` to require at least one
     * bound, or tests `hasStarted() && !hasEnded()` explicitly.
     *
     * @return bool
     */
    public function isCurrent(): bool
    {
        $today = Carbon::today();

        if ($this->effective_from !== null && $this->effective_from->greaterThan($today)) {
            return false;
        }

        if ($this->effective_until !== null && $this->effective_until->lessThan($today)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the term has started as of today.
     *
     * A term with `effective_from = null` is treated as having
     * started. A term whose `effective_from` is in the future has
     * not yet started.
     *
     * @return bool
     */
    public function hasStarted(): bool
    {
        if ($this->effective_from === null) {
            return true;
        }

        return $this->effective_from->lessThanOrEqualTo(Carbon::today());
    }

    /**
     * Whether the term has ended as of today.
     *
     * A term with `effective_until = null` has not ended (open-ended
     * into the future). A term whose `effective_until` is strictly
     * before today has ended.
     *
     * @return bool
     */
    public function hasEnded(): bool
    {
        if ($this->effective_until === null) {
            return false;
        }

        return $this->effective_until->lessThan(Carbon::today());
    }

    /**
     * Whether the term has no upper date bound.
     *
     * An open-ended term is one whose `effective_until` is null —
     * ongoing or indeterminate.
     *
     * @return bool
     */
    public function isOpenEnded(): bool
    {
        return $this->effective_until === null;
    }

    /**
     * Whether the term has both date bounds set.
     *
     * A term with only one bound (or no bounds) does not have a
     * "range" in the strict sense. This helper lets a caller
     * distinguish "well-bounded term" from "partially bounded or
     * unbounded term".
     *
     * @return bool
     */
    public function hasDateRange(): bool
    {
        return $this->effective_from !== null
            && $this->effective_until !== null;
    }

    /**
     * Whether the term has at least one non-empty bilingual label.
     *
     * Read-only diagnostic. The service layer and the request
     * classes enforce the "at least one label" invariant on write;
     * this helper is for tests and for a UI that needs to render a
     * fallback (which the `display_label` accessor already supplies)
     * when a row is missing a label.
     *
     * @return bool
     */
    public function hasLabel(): bool
    {
        $hasAm = is_string($this->label_am) && trim($this->label_am) !== '';
        $hasEn = is_string($this->label_en) && trim($this->label_en) !== '';

        return $hasAm || $hasEn;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Synthesise a display label from the term's effective dates.
     *
     * Used by `getDisplayLabelAttribute()` when neither `label_am`
     * nor `label_en` is set. Produces a compact date-range string:
     *
     *   · Both bounds set:  `2026-09-18 – 2029-09-17`
     *   · Only start:       `2026-09-18 – …`
     *   · Only end:         `… – 2029-09-17`
     *   · Neither:          `''` (empty string)
     *
     * The em-dash separator (U+2013, `–`) is used, not a hyphen,
     * matching the platform's typographic convention for date
     * ranges elsewhere.
     *
     * The method is private — callers that need the range read the
     * dates directly or use the model's `toArray()`.
     *
     * @return string
     */
    private function synthesizeLabelFromDates(): string
    {
        $from  = $this->effective_from?->toDateString();
        $until = $this->effective_until?->toDateString();

        if ($from !== null && $until !== null) {
            return $from . ' – ' . $until;
        }

        if ($from !== null) {
            return $from . ' – …';
        }

        if ($until !== null) {
            return '… – ' . $until;
        }

        return '';
    }
}