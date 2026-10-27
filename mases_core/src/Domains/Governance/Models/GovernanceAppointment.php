<?php

declare(strict_types=1);

/*
 * =============================================================================
 * CHANGE VERIFICATION — G2c · File 5 of 31
 * =============================================================================
 *
 * File:            Models/GovernanceAppointment.php  (NEW)
 * Unit:            G2c — GovernanceAppointment vertical slice (model)
 * Delivery type:   New file
 * Net lines:       new file
 *
 * Before:
 *   The file did not exist. No Eloquent model represented the
 *   `governance_appointments` table. The table existed (created by
 *   G2c file 1 of 31), and its three vocabulary enums existed
 *   (G2c files 2, 3, 4 of 31), but there was no application object
 *   bridging them to the domain — nothing that could load an
 *   appointment row, traverse its owning-body relation, resolve its
 *   three enum-typed columns, expose its bilingual title to a
 *   resource layer, or provide the query scopes the repository
 *   (G2c file 6) and service (G2c file 7) will need.
 *
 * After:
 *   One file that declares the `GovernanceAppointment` Eloquent
 *   model. The class is deliberately read-oriented: it describes
 *   the shape of an appointment row, its relations to the body it
 *   belongs to and to its owning tenant, its relations to the
 *   (optional) target position and target term, its forward
 *   relation to the memberships it creates, its scopes for common
 *   query filters, and its read-only accessors and enum resolvers.
 *   It provides no write methods — every mutation flows through
 *   `GovernanceAppointmentService` (G2c file 7 of 31).
 *
 *   What the model provides:
 *
 *     · Column-to-PHP-type casting for the columns whose native PHP
 *       representation differs from MySQL storage (four integer
 *       FKs and the id, two date columns, three Eloquent timestamps).
 *
 *     · Three enum resolvers — `statusEnum()`, `mode()`, and
 *       `instrumentType()` — that translate the three VARCHAR
 *       columns to their enum cases on demand. Following the
 *       pattern `GovernanceBody::type()` established, none of the
 *       three columns is cast to its enum at the Eloquent layer;
 *       the raw string remains queryable, and a corrupted row is
 *       diagnosable rather than crashing on access.
 *
 *     · One accessor (`display_label`) that resolves the
 *       locale-appropriate bilingual title with the Amharic-first,
 *       English-fallback policy, falling back through
 *       `instrument_reference` and then through a synthesised
 *       date string when no title is set — the same
 *       never-empty-string posture the `GovernanceTerm` model
 *       established for its own label accessor.
 *
 *     · Five relations to existing classes: `tenant()`, `body()`,
 *       `position()`, `term()`.
 *
 *     · One forward relation to a class not yet on disk:
 *       `memberships()` (delivered in the second half of G2c, files
 *       16–28 of 31). Declared as a fully-qualified class string,
 *       matching the pattern `GovernanceBody`, `GovernancePosition`,
 *       and `GovernanceTerm` all use for their own forward
 *       relations.
 *
 *     · Three query scopes: `forTenant()`, `forBody()`, and
 *       `active()` (the last filtering on `status = 'active'`,
 *       NOT on an `is_active` boolean — see the file-level
 *       change-verification block §"Why the active() scope
 *       filters on status").
 *
 *     · Twelve boolean read-only helpers: `isDraft()`,
 *       `isActive()`, `isRevoked()`, `isSuperseded()`,
 *       `isTerminal()`, `isOpen()`, `involvesElection()`,
 *       `hasPosition()`, `hasTerm()`, `hasInstrumentReference()`,
 *       `hasInstrumentDate()`, `hasTitle()`.
 *
 *   What the model deliberately does NOT do:
 *
 *     · It does NOT provide any write method. There is no
 *       `register()`, no `update()`, no `activate()`, no `revoke()`,
 *       no `supersede()`. Every one of those operations is
 *       performed by `GovernanceAppointmentService` (G2c file 7 of
 *       31), which validates domain invariants (body in the same
 *       tenant, position/term in the same tenant and body,
 *       effective-date ordering), wraps the mutation in a
 *       transaction, and dispatches an event after commit.
 *
 *     · It does NOT define an immutability guard on
 *       `governance_body_id`, `governance_position_id`, or
 *       `governance_term_id`. Immutability of those three FK
 *       columns is enforced by the SERVICE layer, which refuses
 *       any `update()` call whose FK values differ from the current
 *       ones. The model accepts whatever the service writes.
 *
 *     · It does NOT cast the three enum columns at the Eloquent
 *       layer. All three are stored as VARCHAR and cast to string;
 *       the `statusEnum()`, `mode()`, and `instrumentType()`
 *       resolvers translate on demand. See the file-level
 *       change-verification block §"Why the three enum columns
 *       are not Eloquent casts" for the full reasoning, which
 *       mirrors the identical decision `GovernanceBody` made for
 *       its own `body_type` column.
 *
 *     · It does NOT declare an `$appends` array. The
 *       `display_label` accessor is available on demand via
 *       `$appointment->display_label` but is not force-serialised
 *       into every JSON response. The resource layer (G2c file 9)
 *       selects which fields to expose explicitly.
 *
 *     · It does NOT define a `$with` array to eager-load relations
 *       by default. A default eager-load would impose a hidden cost
 *       on every query. Callers that need relations eager-load them
 *       explicitly. This is the platform's convention across every
 *       domain.
 *
 *     · It does NOT override `update()` or `delete()` with an
 *       immutability guard. An appointment is meant to be editable
 *       within its lifecycle — its title can be corrected, its
 *       notes refined, its reference number fixed. Historic-
 *       immutability belongs to the membership-recording entities
 *       that arrive later in G2c; the appointment itself is a
 *       working record.
 *
 * =============================================================================
 * Why the three enum columns are not Eloquent casts
 * =============================================================================
 * The model has three enum-typed columns:
 *
 *     status           →  GovernanceAppointmentStatus
 *     appointment_mode →  GovernanceAppointmentMode
 *     instrument_type  →  GovernanceAppointmentInstrumentType
 *
 * A reader might reasonably expect each to be cast via the `$casts`
 * array as `GovernanceAppointmentStatus::class`, etc. The model
 * does NOT do this, deliberately. The reasoning is the same as
 * `GovernanceBody`'s for its own `body_type` column (see that
 * model's class docblock §"What this model deliberately does NOT
 * do"):
 *
 *   · Raw string queries against the columns remain usable. A
 *     caller that wants to write `->where('status', 'active')`
 *     does not need to know the enum exists.
 *
 *   · A row whose column holds an unrecognized value — a
 *     historical import, a hand-edited row, a future CR that added
 *     a value to the enum without a data migration — can be
 *     LOADED and DIAGNOSED. The resolver method returns null, the
 *     caller decides what to do, and the row's other attributes
 *     remain readable. With an Eloquent enum cast, accessing the
 *     attribute on such a row throws a PHP `ValueError` that
 *     crashes the request.
 *
 *   · The three resolvers give the caller an explicit, named
 *     entry point: `$appointment->statusEnum()` reads as "resolve
 *     the status enum" and its nullable return type is a visible
 *     part of the contract. An Eloquent cast hides the same
 *     nullable surface behind a magic attribute access.
 *
 * This is the OPPOSITE of the pattern used in some other domains
 * (e.g., `ShareCertificate::status` casts its status column to an
 * enum because an unrecognized value on a persisted certificate is
 * a bug that should surface loudly). The two choices are deliberate
 * and the difference is recorded here so a future reader does not
 * mistake it for inconsistency. A governance appointment is a
 * long-lived record whose three enum columns are set at registration
 * and are rarely re-validated; a certificate's status is a lifecycle
 * state that every code path validates before persisting.
 *
 * =============================================================================
 * Why the active() scope filters on status
 * =============================================================================
 * The three sibling models in this domain — `GovernanceBody`,
 * `GovernancePosition`, `GovernanceTerm` — each declare a scope
 * named `active()` that filters on a boolean `is_active` column.
 *
 * The appointment entity has no `is_active` column. Its lifecycle
 * is expressed by the four-valued `status` column (draft / active /
 * revoked / superseded), which the migration's docblock (file 1 of
 * 31, §"is_active — NOT a column") explicitly chose in preference to
 * a boolean. The `active()` scope on this model therefore filters
 * on `status = 'active'`.
 *
 * The name is kept identical to the sibling scopes so that callers
 * can write `->active()` uniformly across the four models without
 * caring about the underlying column difference. The scope's
 * docblock makes the difference explicit so that a reader who
 * notices the divergence from the sibling scopes is not left
 * guessing.
 *
 * =============================================================================
 * Forward relation — declared now, resolvable later
 * =============================================================================
 * The `memberships()` relation references `GovernanceMembership`,
 * which is delivered later in this unit (files 16–28 of 31).
 * Eloquent resolves a relation's target class lazily, on first
 * access, by calling `Class::class` — a compile-time string
 * constant, not a runtime class reference. The model loads fine
 * even when `GovernanceMembership` does not exist.
 *
 * The forward relation is declared now for the same reasons the
 * three sibling models declare theirs:
 *
 *   · It documents the intended shape of the domain in one file.
 *   · It lets a future `GovernanceAppointmentResource` expose
 *     `memberships` via `whenLoaded()` even before the target
 *     class lands.
 *   · It prevents a future CR from having to touch this file for
 *     every downstream entity.
 *
 * A caller MUST NOT rely on the forward relation being functional
 * until its target class exists. It becomes functional when G2c
 * delivers `GovernanceMembership`.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   tenant()       — the tenant this appointment belongs to
 *                    (BelongsTo).
 *   body()         — the governance body this appointment belongs
 *                    to (BelongsTo).
 *   position()     — the optional target position (BelongsTo,
 *                    nullable).
 *   term()         — the optional target term (BelongsTo,
 *                    nullable).
 *   memberships()  — the memberships this appointment creates
 *                    (HasMany) [G2c forward — inert until the
 *                    target class lands].
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForTenant(Builder $query, int $tenantId)  — tenant_id = ?
 *   scopeForBody(Builder $query, int $bodyId)      — governance_body_id = ?
 *   scopeActive(Builder $query)                    — status = 'active'
 *
 * =============================================================================
 * Amharic-first display label
 * =============================================================================
 * The model supplies one name accessor:
 *
 *   `display_label`  — locale-aware. Returns `title_am` in the `am`
 *                      locale, `title_en` in `en`, with a fallback
 *                      to the other language. When neither title is
 *                      set, falls back through `instrument_reference`.
 *                      When that too is empty, synthesises a string
 *                      from the instrument type and the effective
 *                      date — so the accessor NEVER returns an
 *                      empty string for a row that has at least an
 *                      instrument type and an effective date (both
 *                      of which the migration declares NOT NULL).
 *
 * The fallback chain:
 *
 *   1. `title_am` / `title_en` — locale-aware, other-language
 *      fallback.
 *   2. `instrument_reference`  — the free-text identifier.
 *   3. Synthesised: `"{InstrumentType label} — {effective_date}"`.
 *
 * The synthesised fallback is deliberately not the same as
 * `GovernanceTerm`'s (which uses only the date range, because a
 * term has no instrument type). An appointment's instrument type is
 * the most stable identifying fact about it after its position and
 * body; putting it in the fallback gives a reader a meaningful
 * handle ("Resolution — 2026-09-18") rather than a bare date.
 *
 * The English rendering of the instrument type in the synthesised
 * fallback uses a small private map rather than the translation
 * catalogue. The map exists so the fallback is deterministic
 * without depending on the catalogue being registered; the
 * catalogue is still the correct source for the resource layer's
 * `instrument_type_label` field, which the resource emits as a
 * sibling of the raw value.
 *
 * =============================================================================
 * Casts summary
 * =============================================================================
 *   tenant_id              integer
 *   governance_body_id     integer
 *   governance_position_id integer
 *   governance_term_id     integer
 *   instrument_date        date    (yields CarbonImmutable at midnight)
 *   effective_date         date    (yields CarbonImmutable at midnight)
 *   created_at             datetime
 *   updated_at             datetime
 *   deleted_at             datetime
 *
 * No cast on `instrument_type`, `appointment_mode`, `status` — see
 * the file-level change-verification block §"Why the three enum
 * columns are not Eloquent casts".
 *
 * No cast on `instrument_reference`, `title_am`, `title_en`,
 * `notes_am`, `notes_en` — all strings.
 *
 * =============================================================================
 * @property int                          $id
 * @property int                          $tenant_id
 * @property int                          $governance_body_id
 * @property int|null                     $governance_position_id
 * @property int|null                     $governance_term_id
 * @property string                       $instrument_type   Raw string; use instrumentType() for the enum.
 * @property string|null                  $instrument_reference
 * @property \Carbon\CarbonImmutable|null $instrument_date
 * @property \Carbon\CarbonImmutable      $effective_date
 * @property string                       $appointment_mode   Raw string; use mode() for the enum.
 * @property string                       $status             Raw string; use statusEnum() for the enum.
 * @property string|null                  $title_am
 * @property string|null                  $title_en
 * @property string|null                  $notes_am
 * @property string|null                  $notes_en
 * @property \Carbon\Carbon|null          $created_at
 * @property \Carbon\Carbon|null          $updated_at
 * @property \Carbon\Carbon|null          $deleted_at
 *
 * @property-read string                  $display_label   Locale-aware bilingual label.
 * @property-read \MaaleArna\MASES\Core\Tenancy\Models\Tenant|null                                 $tenant
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceBody|null              $body
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernancePosition|null          $position
 * @property-read \MaaleArna\MASES\Core\Domains\Governance\Models\GovernanceTerm|null              $term
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
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\MASES\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\MASES\Core\Tenancy\Models\Tenant;

/**
 * GovernanceAppointment
 *
 * Eloquent model for a governance appointment — the INSTRUMENT (a
 * resolution, a board decision, a general-assembly act, an external
 * document, or a by-law provision) that creates one or more
 * governance memberships.
 *
 * See the file-level change-verification block for the model's
 * shape, its relations (including the forward relation to G2c's
 * `GovernanceMembership`), its scopes, its three enum resolvers,
 * its locale-aware label accessor with its synthesised fallback,
 * and its deliberate absence of write methods.
 *
 * @package MaaleArna\MASES\Core\Domains\Governance\Models
 * @version 1.0.0
 */
final class GovernanceAppointment extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'governance_appointments';

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
     * Every column the service layer (G2c file 7 of 31) writes is
     * declared here. Excluded by design:
     *
     *   · `id`             — assigned by the database.
     *   · `created_at`     — managed by Eloquent's `timestamps`.
     *   · `updated_at`     — managed by Eloquent's `timestamps`.
     *   · `deleted_at`     — managed by the `SoftDeletes` trait.
     *
     * Order is Amharic-first for the two bilingual pairs (title_am
     * before title_en, notes_am before notes_en), mirroring the
     * migration's column order.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'governance_body_id',
        'governance_position_id',
        'governance_term_id',
        'instrument_type',
        'instrument_reference',
        'instrument_date',
        'effective_date',
        'effective_until',
        'appointment_mode',
        'appointment_role',
        'acting_for_appointment_id',
        'status',
        'title_am',
        'title_en',
        'notes_am',
        'notes_en',
    ];

    /**
     * The attributes that should be cast.
     *
     * `instrument_date` and `effective_date` are cast as `date`,
     * which yields `CarbonImmutable` instances at midnight in the
     * application's configured timezone. The `date` cast (as opposed
     * to `datetime`) matches the columns' MySQL type (`date`) and
     * the concept they represent (a calendar day, not an instant).
     *
     * The three enum-typed columns (`instrument_type`,
     * `appointment_mode`, `status`) are NOT cast. See the file-level
     * change-verification block §"Why the three enum columns are
     * not Eloquent casts" for the reasoning.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'              => 'integer',
        'governance_body_id'     => 'integer',
        'governance_position_id' => 'integer',
        'governance_term_id'     => 'integer',
        'instrument_date'        => 'date',
        'effective_date'         => 'date',
        'effective_until'        => 'date',
        'acting_for_appointment_id' => 'integer',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `display_label` is available on demand
     * via `$appointment->display_label`, but is not force-serialised
     * into every JSON response. Resources (G2c file 9 of 31) select
     * which fields to expose explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    /**
     * Short English labels for the five instrument types.
     *
     * Used ONLY by the `display_label` accessor's synthesised
     * fallback (step 3 of the fallback chain). The resource layer
     * uses the translation catalogue for its `instrument_type_label`
     * field, NOT this map; the map exists so the fallback is
     * deterministic when the catalogue has not been registered.
     *
     * The map's values are deliberately short — they appear inside a
     * fallback string of the form "{Label} — {date}".
     *
     * @var array<string, string>
     */
    private const INSTRUMENT_TYPE_FALLBACK_LABELS = [
        'resolution'        => 'Resolution',
        'board_decision'    => 'Board Decision',
        'general_assembly'  => 'General Assembly',
        'external_document' => 'External Document',
        'by_law'            => 'By-law',
    ];

    // =========================================================================
    // Accessors
    // =========================================================================

    /**
     * Get the appointment's locale-aware display label.
     *
     * Resolution order:
     *
     *   1. Amharic (`title_am`) when the active locale is `am`.
     *   2. English (`title_en`) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one
     *      is null or empty.
     *   4. `instrument_reference` when neither title is set.
     *   5. A synthesised `"{InstrumentType} — {effective_date}"`
     *      string when neither title nor reference is set.
     *   6. The effective date alone when the instrument type is
     *      unrecognized (which should never happen for a
     *      service-written row, but is handled defensively).
     *
     * The accessor is read-only. Setting `display_label` has no
     * effect; callers must set `title_am`, `title_en`, or
     * `instrument_reference` directly.
     *
     * See the file-level change-verification block §"Amharic-first
     * display label" for the reasoning behind each step of the
     * fallback chain.
     *
     * @return string
     */
    public function getDisplayLabelAttribute(): string
    {
        $locale = app()->getLocale();

        $title = $locale === 'am'
            ? ($this->title_am ?? $this->title_en)
            : ($this->title_en ?? $this->title_am);

        if (is_string($title) && trim($title) !== '') {
            return $title;
        }

        if (is_string($this->instrument_reference) && trim($this->instrument_reference) !== '') {
            return $this->instrument_reference;
        }

        return $this->synthesizeLabelFromTypeAndDate();
    }

    // =========================================================================
    // Relations — tenant, body, position, term
    // =========================================================================

    /**
     * The tenant this appointment belongs to.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The governance body this appointment belongs to.
     *
     * @return BelongsTo
     */
    public function body(): BelongsTo
    {
        return $this->belongsTo(GovernanceBody::class, 'governance_body_id', 'id');
    }

    /**
     * The substantive primary appointment this acting appointment covers.
     * Acting powers are not inherited from this relationship; they must be
     * independently granted through the action-bound authority system.
     *
     * @return BelongsTo
     */
    public function actingForAppointment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'acting_for_appointment_id', 'id');
    }

    /**
     * The position this appointment targets, if any.
     *
     * Null when the instrument spans multiple positions or when the
     * appointment is recorded without a single-position target.
     *
     * @return BelongsTo
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(GovernancePosition::class, 'governance_position_id', 'id');
    }

    /**
     * The term this appointment targets, if any.
     *
     * Null when the appointment is not scoped to a specific term —
     * typical for ex-officio appointments whose duration is
     * determined by an underlying role rather than by a body term.
     *
     * @return BelongsTo
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(GovernanceTerm::class, 'governance_term_id', 'id');
    }

    // =========================================================================
    // Relations — forward reference (G2c later files)
    // =========================================================================
    /*
     * The relation below references a model class that is not yet
     * on disk. It is declared with a fully-qualified class string;
     * Eloquent resolves the string lazily on first access.
     *
     * See the file-level change-verification block
     * §"Forward relation — declared now, resolvable later".
     */

    /**
     * The memberships this appointment creates.
     *
     * An appointment is the instrument; memberships are the
     * per-person occupancy records it authorises. The relation is
     * one-to-many: a single appointment may create one membership
     * (a single-seat appointment) or many (a multi-seat appointment,
     * such as an election that fills every seat on a body).
     *
     * Delivered later in G2c. Inert until `GovernanceMembership`
     * exists on disk.
     *
     * @return HasMany
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(
            GovernanceMembership::class,
            'governance_appointment_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific tenant.
     *
     * Every repository method that touches this model chains this
     * scope, so a query built against the repository cannot return
     * an appointment from another tenant.
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
     * The primary list query: "appointments of a body". Combined
     * with an `orderBy('effective_date')` in the repository, this
     * scope produces the chronological list the resource layer
     * expects.
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
     * Scope a query to appointments whose status is `active`.
     *
     * DIFFERS FROM THE SIBLING MODELS' `active()` SCOPE: those
     * filter on an `is_active` boolean; this one filters on
     * `status = 'active'`, because the appointment entity has no
     * `is_active` column. See the file-level change-verification
     * block §"Why the active() scope filters on status" for the
     * reasoning.
     *
     * The scope does NOT filter by `draft`, `revoked`, or
     * `superseded` explicitly — a caller that wants "not terminal"
     * chains `->active()` OR `->where('status', 'draft')` explicitly,
     * or uses the model's `isOpen()` helper on a loaded model. The
     * scope's job is narrow: filter to the `active` state.
     *
     * @param  Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GovernanceAppointmentStatus::Active->value);
    }

    // =========================================================================
    // Enum resolvers
    // =========================================================================

    /**
     * Resolve the appointment's status as an enum case.
     *
     * Returns null if the stored value is not a recognized
     * `GovernanceAppointmentStatus`. This should never happen for a
     * row written by the domain's own service, but the null return
     * is defensive: callers that branch on the status must handle
     * the null case, which prevents a corrupted row from crashing a
     * request.
     *
     * The resolver resolves the enum lazily rather than via a
     * `casts` declaration — see the file-level change-verification
     * block §"Why the three enum columns are not Eloquent casts".
     *
     * @return GovernanceAppointmentStatus|null
     */
    public function statusEnum(): ?GovernanceAppointmentStatus
    {
        return GovernanceAppointmentStatus::tryFrom($this->status);
    }

    /**
     * Resolve the appointment's mode as an enum case.
     *
     * Returns null if the stored value is not a recognized
     * `GovernanceAppointmentMode`. See `statusEnum()` for the
     * reasoning on the null return.
     *
     * @return GovernanceAppointmentMode|null
     */
    public function mode(): ?GovernanceAppointmentMode
    {
        return GovernanceAppointmentMode::tryFrom($this->appointment_mode);
    }

    /**
     * Resolve the appointment's instrument type as an enum case.
     *
     * Returns null if the stored value is not a recognized
     * `GovernanceAppointmentInstrumentType`. See `statusEnum()` for
     * the reasoning on the null return.
     *
     * @return GovernanceAppointmentInstrumentType|null
     */
    public function instrumentType(): ?GovernanceAppointmentInstrumentType
    {
        return GovernanceAppointmentInstrumentType::tryFrom($this->instrument_type);
    }

    // =========================================================================
    // Domain helpers — status predicates (delegate to the enum)
    // =========================================================================
    /*
     * Each helper resolves the status enum and delegates to its
     * predicate. An unrecognized status value makes every helper
     * return false — the fail-closed posture that a corrupted row
     * cannot accidentally satisfy a positive branch.
     */

    /**
     * Whether the appointment is in the Draft state.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->statusEnum()?->isDraft() ?? false;
    }

    /**
     * Whether the appointment is in the Active state.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->statusEnum()?->isActive() ?? false;
    }

    /**
     * Whether the appointment is in the Revoked state.
     *
     * @return bool
     */
    public function isRevoked(): bool
    {
        return $this->statusEnum()?->isRevoked() ?? false;
    }

    /**
     * Whether the appointment is in the Superseded state.
     *
     * @return bool
     */
    public function isSuperseded(): bool
    {
        return $this->statusEnum()?->isSuperseded() ?? false;
    }

    /**
     * Whether the appointment's status is terminal (revoked or
     * superseded).
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->statusEnum()?->isTerminal() ?? false;
    }

    /**
     * Whether the appointment's status is open (draft or active).
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->statusEnum()?->isOpen() ?? false;
    }

    // =========================================================================
    // Domain helpers — mode and instrument predicates
    // =========================================================================

    /**
     * Whether the appointment's acquisition mode involved a vote of
     * an electing body.
     *
     * Delegates to `GovernanceAppointmentMode::involvesElection()`.
     * An unrecognized mode value makes this helper return false —
     * a fail-closed posture for a corrupted row.
     *
     * @return bool
     */
    public function involvesElection(): bool
    {
        return $this->mode()?->involvesElection() ?? false;
    }

    /**
     * Whether the appointment's instrument originates from inside
     * the platform's act-recording surface.
     *
     * Delegates to
     * `GovernanceAppointmentInstrumentType::isInternal()`.
     *
     * @return bool
     */
    public function hasInternalInstrument(): bool
    {
        return $this->instrumentType()?->isInternal() ?? false;
    }

    /**
     * Whether the appointment's instrument originates from outside
     * the platform's act-recording surface.
     *
     * Delegates to
     * `GovernanceAppointmentInstrumentType::isExternal()`.
     *
     * @return bool
     */
    public function hasExternalInstrument(): bool
    {
        return $this->instrumentType()?->isExternal() ?? false;
    }

    // =========================================================================
    // Domain helpers — presence checks
    // =========================================================================

    /**
     * Whether the appointment targets a specific position.
     *
     * A `null` `governance_position_id` means the instrument spans
     * multiple positions; a non-null value means the instrument is
     * scoped to one position.
     *
     * @return bool
     */
    public function hasPosition(): bool
    {
        return $this->governance_position_id !== null;
    }

    /**
     * Whether the appointment targets a specific term.
     *
     * A `null` `governance_term_id` means the appointment is not
     * scoped to a body term — typical for ex-officio and
     * transitional appointments.
     *
     * @return bool
     */
    public function hasTerm(): bool
    {
        return $this->governance_term_id !== null;
    }

    /**
     * Whether the appointment carries an instrument reference.
     *
     * The reference is the free-text identifier of the instrument
     * ("Resolution 2026-042", "Article 12.3"). Its presence is
     * separate from `hasInstrumentDate()`; an appointment may have
     * one, both, or neither.
     *
     * @return bool
     */
    public function hasInstrumentReference(): bool
    {
        return is_string($this->instrument_reference)
            && trim($this->instrument_reference) !== '';
    }

    /**
     * Whether the appointment carries an instrument issue date.
     *
     * @return bool
     */
    public function hasInstrumentDate(): bool
    {
        return $this->instrument_date !== null;
    }

    /**
     * Whether the appointment has at least one non-empty bilingual
     * title.
     *
     * Read-only diagnostic. The service layer does NOT require a
     * title at write time (see the migration's docblock, §"title_am
     * / title_en"), so this helper is for the UI's decision on
     * whether to render a fallback or the title.
     *
     * @return bool
     */
    public function hasTitle(): bool
    {
        $hasAm = is_string($this->title_am) && trim($this->title_am) !== '';
        $hasEn = is_string($this->title_en) && trim($this->title_en) !== '';

        return $hasAm || $hasEn;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Synthesise a display label from the appointment's instrument
     * type and effective date.
     *
     * Used by `getDisplayLabelAttribute()` when neither a bilingual
     * title nor an instrument reference is set. The effective date
     * is always present (the migration declares `effective_date` as
     * NOT NULL), so this method always has at least a date to fall
     * back on.
     *
     * The format is:
     *
     *     "{InstrumentType label} — {effective_date}"
     *
     * If the instrument type's stored value is unrecognized, the
     * label segment is omitted and only the date is returned. This
     * case cannot arise from a service-written row, but is handled
     * so that a corrupted row's accessor does not throw.
     *
     * The em-dash separator (U+2013, `–`) matches the convention
     * `GovernanceTerm::synthesizeLabelFromDates()` uses for its
     * own range.
     *
     * @return string
     */
    private function synthesizeLabelFromTypeAndDate(): string
    {
        $date = $this->effective_date?->toDateString() ?? '';

        $typeLabel = self::INSTRUMENT_TYPE_FALLBACK_LABELS[$this->instrument_type] ?? null;

        if ($typeLabel === null) {
            return $date;
        }

        if ($date === '') {
            return $typeLabel;
        }

        return $typeLabel . ' – ' . $date;
    }
}