<?php

declare(strict_types=1);


namespace MaaleArna\Arna\Core\Domains\Governance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentInstrumentType;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentMode;
use MaaleArna\Arna\Core\Domains\Governance\Enums\GovernanceAppointmentStatus;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;

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
 * @package MaaleArna\Arna\Core\Domains\Governance\Models
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
        'appointment_mode',
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