<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2a · File 3 of 17
 * =============================================================================
 *
 * File:            Models/GovernanceBody.php  (NEW)
 * Unit:            G2a — GovernanceBody vertical slice (model)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No Eloquent model represented the
 *   `governance_bodies` table. The table existed (created by G2a
 *   file 1 of 17) and the vocabulary enum existed (G2a file 2 of
 *   17), but there was no application object bridging the two —
 *   nothing that could load a row, resolve its `body_type` to an
 *   enum case, traverse its self-referencing relations, or expose
 *   its bilingual display name to a resource layer.
 *
 * After:
 *   One file that declares the `GovernanceBody` Eloquent model. The
 *   class is deliberately read-oriented: it describes the shape of
 *   a governance body row, its relations to other rows in the same
 *   table and to its owning tenant, its scopes for common query
 *   filters, and its read-only accessors. It provides no write
 *   methods — every mutation flows through `GovernanceBodyService`
 *   (G2a file 5 of 17).
 *
 *   What the model provides:
 *
 *     · Column-to-PHP-type casting for the eight columns whose
 *       native PHP representation differs from their MySQL storage
 *       (integer FKs, boolean flag, JSON rule, date columns, and
 *       the three Eloquent timestamps).
 *
 *     · One set-mutator (`code`) that normalises the body's
 *       canonical identifier to uppercase on write, mirroring
 *       `ShareClass::code()`'s contract.
 *
 *     · One accessor (`display_name`) that resolves the
 *       locale-appropriate bilingual name with the same
 *       Amharic-first, English-fallback, code-as-ultimate-fallback
 *       policy `ShareClass` uses.
 *
 *     · Two relations to the `tenants` table and to
 *       `governance_bodies` itself: `tenant()`, `parent()`,
 *       `children()`, `reportingBody()`, `reportingChildren()`.
 *
 *     · Four forward relations to classes not yet on disk:
 *       `positions()`, `terms()` (G2b), `appointments()`,
 *       `memberships()` (G2c). These are declared as fully-
 *       qualified class strings (the same pattern
 *       `Shareholder::shareHoldings()` uses for its future-target
 *       relations) so the model loads before its targets exist.
 *
 *     · Three query scopes: `active()`, `forTenant()`, `ofType()`.
 *
 *     · One domain helper (`type()`) that resolves the raw
 *       `body_type` string to a `?GovernanceBodyType` enum case,
 *       returning `null` for an unrecognized value — the same
 *       fail-open diagnostic surface `Shareholder::type()`
 *       provides.
 *
 *     · Six boolean read-only helpers: `isActive()`,
 *       `isStanding()`, `isTemporary()`, `hasParent()`,
 *       `hasReportingBody()`, `hasEffectiveRange()`.
 *
 *   What the model deliberately does NOT do:
 *
 *     · It does NOT provide any write method. There is no
 *       `register()`, no `update()`, no `deactivate()`, no
 *       `reactivate()`. Every one of those operations is performed
 *       by `GovernanceBodyService` (G2a file 5), which validates
 *       domain invariants (code uniqueness per tenant, parent
 *       cycle prevention, effective-date ordering), wraps the
 *       mutation in a transaction, and dispatches an event after
 *       commit. A model-level write method would bypass all three.
 *
 *       This mirrors `Shareholder`'s deliberate absence of write
 *       methods; its docblock records the same rationale.
 *
 *     · It does NOT cast `body_type` to the enum at the Eloquent
 *       layer. The value is stored and read as a plain string; the
 *       `type()` helper resolves the enum lazily on demand. The
 *       same pattern `Shareholder` uses for `shareholder_type`, for
 *       the same reasons: (a) raw string queries against the column
 *       remain usable, and (b) a row whose `body_type` holds an
 *       unrecognized value can be loaded and diagnosed rather than
 *       crashing on access with a PHP `ValueError`.
 *
 *       Note: this is the OPPOSITE of the pattern
 *       `ShareCertificate::status` uses (where the column IS cast
 *       to the enum, because an unrecognized value on a persisted
 *       certificate is a bug that should surface loudly). The two
 *       choices are deliberate and the difference is recorded here
 *       so a future reader does not mistake it for inconsistency.
 *       A governance body is a long-lived identity record whose
 *       `body_type` is set once at registration; a certificate's
 *       `status` is a lifecycle state that every code path
 *       validates before persisting. The two are structurally
 *       different.
 *
 *     · It does NOT enforce the "at least one non-empty name" rule.
 *       That rule is enforced by `GovernanceBodyService` at write
 *       time and by the request classes (G2a files 12, 13) at the
 *       HTTP boundary. The model accepts whatever the database
 *       holds.
 *
 *     · It does NOT override `update()` or `delete()` with an
 *       immutability guard. Governance bodies are meant to be
 *       editable — a mandate text can be corrected, a purpose
 *       statement refined, a meeting frequency updated. The
 *       historic-immutability guard belongs to LATER entities
 *       (approved minutes, adopted resolutions, certified
 *       elections), not to the body itself.
 *
 *     · It does NOT define a `$with` array to eager-load relations
 *       by default. A default eager-load would impose a hidden cost
 *       on every query. Callers that need relations eager-load
 *       them explicitly. This is the platform's convention across
 *       every domain.
 *
 * =============================================================================
 * Forward relations — declared now, resolvable later
 * =============================================================================
 * The `positions()`, `terms()`, `appointments()`, and `memberships()`
 * relations reference model classes that do not yet exist on disk:
 *
 *     G2b will deliver:  GovernancePosition, GovernanceTerm
 *     G2c will deliver:  GovernanceAppointment, GovernanceMembership
 *
 * Eloquent resolves a relation's target class lazily, on first
 * access, by calling `Class::class` — which is a compile-time
 * string constant in PHP, not a runtime class reference. A model
 * that declares `hasMany(\Foo\Bar::class, ...)` loads perfectly
 * fine even when `\Foo\Bar` does not exist; the class is only
 * resolved when the relation is actually traversed.
 *
 * This pattern is established in the Shareholder domain:
 * `Shareholder::shareHoldings()` and
 * `Shareholder::shareCertificates()` declare fully-qualified
 * relation targets that were delivered in later 2A units. The
 * same convention applies here.
 *
 * The forward relations are declared NOW — rather than added by
 * the G2b and G2c deliveries — because:
 *
 *   · It documents the intended shape of the domain in one file.
 *   · It lets the resource layer (G2a file 11) load a body's
 *     memberships via `with('memberships')` even before G2c lands,
 *     returning an empty collection rather than a missing-relation
 *     error.
 *   · It prevents a future CR from having to touch this file for
 *     every downstream entity — each of which would be a small,
 *     reviewable change — in favour of one file that describes the
 *     domain's intended shape.
 *
 * A caller MUST NOT rely on a forward relation being functional
 * until its target class exists. In G2a the four forward relations
 * are inert; they become functional when G2b and G2c deliver their
 * models.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   tenant()             — the tenant this body belongs to (BelongsTo).
 *   parent()             — the structural parent body (BelongsTo, self).
 *   children()           — bodies whose parent is this one (HasMany, self).
 *   reportingBody()      — the body this one reports to (BelongsTo, self).
 *   reportingChildren()  — bodies reporting to this one (HasMany, self).
 *   positions()          — the body's positions (HasMany) [G2b forward].
 *   terms()              — the body's terms (HasMany) [G2b forward].
 *   appointments()       — the body's appointments (HasMany) [G2c forward].
 *   memberships()        — the body's memberships (HasMany) [G2c forward].
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeActive(Builder $query)                   — is_active = true
 *   scopeForTenant(Builder $query, int $tenantId) — tenant_id = ?
 *   scopeOfType(Builder $query, GovernanceBodyType|string $type)
 *                                                  — body_type = ?
 *
 * The `scopeOfType` accepts either the enum case or its backing
 * string, so callers do not need to normalise before invoking the
 * scope.
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
 * There is no `full_name` accessor (unlike `Shareholder`) because
 * a governance body has no composed multi-part name — its name is
 * a single bilingual phrase, like `ShareClass`'s. There is no
 * `name` alias either, for the same reason: the "polymorphic
 * identity model" convention applies to users and shareholders,
 * not to reference data.
 *
 * =============================================================================
 * Casts summary
 * =============================================================================
 *   tenant_id           integer
 *   parent_body_id      integer
 *   reporting_body_id   integer
 *   is_active           boolean
 *   quorum_rule_json    array  (Laravel's built-in JSON↔array cast)
 *   effective_from      date   (yields CarbonImmutable at midnight)
 *   effective_until     date   (yields CarbonImmutable at midnight)
 *   created_at          datetime
 *   updated_at          datetime
 *   deleted_at          datetime
 *
 * No cast on `body_type` — see the class docblock §"What this model
 * deliberately does NOT do".
 *
 * No cast on `code`, `name_am`, `name_en`, `purpose`, `mandate`, or
 * `meeting_frequency` — all strings.
 *
 * =============================================================================
 * @property int                          $id
 * @property int                          $tenant_id
 * @property string                       $code
 * @property string                       $body_type       Raw string; use type() for the enum.
 * @property string|null                  $name_am
 * @property string|null                  $name_en
 * @property int|null                     $parent_body_id
 * @property string|null                  $purpose
 * @property string|null                  $mandate
 * @property array<string, mixed>|null    $quorum_rule_json
 * @property string|null                  $meeting_frequency
 * @property int|null                     $reporting_body_id
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_until
 * @property bool                         $is_active
 * @property \Carbon\Carbon|null          $created_at
 * @property \Carbon\Carbon|null          $updated_at
 * @property \Carbon\Carbon|null          $deleted_at
 *
 * @property-read string                       $display_name   Locale-aware bilingual name.
 * @property-read \MaaleArna\MASES\Core\Tenancy\Models\Tenant|null      $tenant
 * @property-read self|null                    $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, self>  $children
 * @property-read self|null                    $reportingBody
 * @property-read \Illuminate\Database\Eloquent\Collection<int, self>  $reportingChildren
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
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceBodyType;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

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
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
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