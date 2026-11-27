<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Shareholder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use MaaleArna\Arna\Core\Auth\Models\User;
use MaaleArna\Arna\Core\Domains\Shareholder\Enums\ShareholderType;
use MaaleArna\Arna\Core\Tenancy\Models\Tenant;
use MaaleArna\Arna\Core\Domains\People\Models\Person;

/**
 * Stores holder identity only: individual or legal entity. Holdings, certificates and KYC are separate; legal-entity rows must not link directly to a user.
 *
 * @property int                  $id
 * @property int                  $tenant_id
 * @property string|null          $shareholder_number Immutable tenant-scoped business identifier
 * @property string               $shareholder_type
 * @property string|null          $first_name_am
 * @property string|null          $first_name_en
 * @property string|null          $father_name_am
 * @property string|null          $father_name_en
 * @property string|null          $grandfather_name_am
 * @property string|null          $grandfather_name_en
 * @property string|null          $entity_name_am
 * @property string|null          $entity_name_en
 * @property int|null             $user_id
 * @property int|null             $representative_user_id
 * @property bool                 $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string          $full_name      Composed name (Amharic-first).
 * @property-read string          $name           Alias of full_name (compat).
 * @property-read string          $display_name   Locale-aware display name.
 * @package MaaleArna\Arna\Core\Domains\Shareholder\Models
 */
final class Shareholder extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'shareholders';

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
     * Order is Amharic-first: `_am` precedes `_en` for every bilingual
     * pair. `shareholder_type`, `tenant_id`, and the two user references
     * are listed after the name fields for readability; their position in
     * the array has no runtime effect.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'shareholder_type',
        'first_name_am',
        'first_name_en',
        'father_name_am',
        'father_name_en',
        'grandfather_name_am',
        'grandfather_name_en',
        'entity_name_am',
        'entity_name_en',
        'user_id',
        'representative_user_id',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * `shareholder_type` is NOT cast to the enum at the Eloquent layer,
     * because doing so would make raw string queries harder and would
     * break the ability to store an unrecognised value for diagnostics.
     * The `type()` helper resolves the enum lazily on demand.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tenant_id'              => 'integer',
        'shareholder_number'     => 'string',
        'user_id'                => 'integer',
        'representative_user_id' => 'integer',
        'is_active'              => 'boolean',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. `full_name` and `display_name` are available
     * on demand, but are not force-serialized. Resources (2A.25) select
     * which fields to expose explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The tenant this shareholder belongs to.
     *
     * Every shareholder belongs to exactly one tenant. In Phase 2A the
     * platform has a single main tenant; the column is present so that a
     * future multi-tenant expansion does not require a schema migration.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * The direct self-service User linked to this shareholder.
     *
     * Populated for individuals whose access is granted directly, and for
     * any shareholder whose own identity is a ARNA user. Never populated
     * for a legal entity — the entity is not a login account.
     *
     * @return BelongsTo
     */
    /**
     * Canonical natural-person profile; null for legal-entity shareholders.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * The designated representative User for this shareholder.
     *
     * Mandatory for every non-individual shareholder (D11). A single User
     * may represent multiple legal-entity shareholders, so this relation
     * is not unique — it is a many-to-one from the shareholder side.
     *
     * @return BelongsTo
     */
    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id', 'id');
    }

    /**
     * The share holdings owned by this shareholder.
     *
     * One holding per (shareholder, share class) pair. A shareholder with
     * two classes of shares has two holding rows. Zero rows is valid —
     * a shareholder may hold no shares after transferring them all out.
     *
     * @return HasMany
     */
    public function shareHoldings(): HasMany
    {
        return $this->hasMany(
            ShareHolding::class,
            'shareholder_id',
            'id'
        );
    }

    /**
     * The share certificates issued to this shareholder.
     *
     * @return HasMany
     */
    public function shareCertificates(): HasMany
    {
        return $this->hasMany(
            ShareCertificate::class,
            'shareholder_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active shareholders.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to a specific tenant.
     *
     * @param Builder $query
     * @param int     $tenantId
     * @return Builder
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query by shareholder type.
     *
     * Accepts either the enum case or its string value, so callers do not
     * need to normalise before invoking the scope.
     *
     * @param Builder              $query
     * @param ShareholderType|string $type
     * @return Builder
     */
    public function scopeOfType(Builder $query, ShareholderType|string $type): Builder
    {
        $value = $type instanceof ShareholderType ? $type->value : $type;

        return $query->where('shareholder_type', $value);
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Resolve the shareholder type as an enum case.
     *
     * Returns null if the stored value is not a recognised
     * `ShareholderType`. This should never happen for a row written by the
     * domain's own service, but the null return is defensive: callers that
     * branch on the type must handle the null case, which prevents a
     * corrupted row from crashing a request.
     *
     * @return ShareholderType|null
     */
    public function type(): ?ShareholderType
    {
        return ShareholderType::tryFrom($this->shareholder_type);
    }

    /**
     * Whether this shareholder is a natural person.
     *
     * Returns false if the stored type is unrecognised — safer than
     * assuming "individual" for a corrupted row.
     *
     * @return bool
     */
    public function isIndividual(): bool
    {
        return $this->type() === ShareholderType::Individual;
    }

    /**
     * Whether this shareholder is a legal entity (any non-individual type).
     *
     * Returns false if the stored type is unrecognised, for the same reason
     * as `isIndividual()`.
     *
     * @return bool
     */
    public function isLegalEntity(): bool
    {
        $type = $this->type();

        return $type !== null && $type->isLegalEntity();
    }

    /**
     * Whether this shareholder requires a designated representative User.
     *
     * Delegates to `ShareholderType::requiresRepresentative()` (2A.3), which
     * reads the enforcement list from configuration and falls back to the
     * D11 default. Centralising the rule in the enum guarantees that
     * StoreShareholderRequest (2A.16), ShareholderService (2A.12), and this
     * helper cannot disagree.
     *
     * @return bool
     */
    public function requiresRepresentative(): bool
    {
        $type = $this->type();

        return $type !== null && $type->requiresRepresentative();
    }

    /**
     * Whether this shareholder has a direct self-service User link.
     *
     * @return bool
     */
    public function hasDirectUserAccess(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Whether this shareholder has a designated representative User.
     *
     * @return bool
     */
    public function hasRepresentative(): bool
    {
        return $this->representative_user_id !== null;
    }

    /**
     * Whether this shareholder is currently active.
     *
     * Mirrors `User::isActive()` and `ShareClass::isActive()`. Does not
     * consider soft-deletion — a soft-deleted shareholder is not returned
     * by default queries at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    // =========================================================================
    // Name accessors (Amharic-first)
    // =========================================================================

    /**
     * Compose the shareholder's full name.
     *
     * For individuals, this composes the three name components using the
     * same Amharic-first, English-fallback-per-component policy used by the
     * `User` model:
     *
     *     first_name_am ?? first_name_en
     *     father_name_am ?? father_name_en
     *     grandfather_name_am ?? grandfather_name_en
     *
     * For legal entities, it returns `entity_name_am ?? entity_name_en`.
     *
     * Returns an empty string if no name component is present. Callers
     * that require a non-empty name must validate before persist; the
     * ShareholderService does so for individuals and for legal entities.
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        if ($this->isLegalEntity()) {
            return (string) ($this->entity_name_am ?? $this->entity_name_en ?? '');
        }

        $parts = [
            $this->first_name_am ?? $this->first_name_en,
            $this->father_name_am ?? $this->father_name_en,
            $this->grandfather_name_am ?? $this->grandfather_name_en,
        ];

        return implode(' ', array_filter($parts, static fn ($p) => $p !== null && $p !== ''));
    }

    /**
     * Compatibility accessor — alias of `full_name`.
     *
     * Mirrors the pattern established by `User::getNameAttribute()` in
     * Phase 1 Amendment A4. Providing a `name` attribute allows generic
     * consumers (serializers, filters, templates) to treat any ARNA
     * identity model uniformly via `$model->name`, without needing to know
     * which underlying columns produce it.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return $this->getFullNameAttribute();
    }

    /**
     * Locale-aware display name.
     *
     * Resolution order:
     *
     *   1. Amharic (`_am` column) when the active locale is `am`.
     *   2. English (`_en` column) when the active locale is not `am`.
     *   3. The other language as a fallback when the preferred one is null.
     *   4. An empty string as a last resort.
     *
     * Read-only. Setting `display_name` has no effect; callers must set
     * the specific `_am` / `_en` column they want to change.
     *
     * @return string
     */
    public function getDisplayNameAttribute(): string
    {
        $locale = app()->getLocale();

        if ($this->isLegalEntity()) {
            return $locale === 'am'
                ? (string) ($this->entity_name_am ?? $this->entity_name_en ?? '')
                : (string) ($this->entity_name_en ?? $this->entity_name_am ?? '');
        }

        if ($locale === 'am') {
            $parts = [
                $this->first_name_am ?? $this->first_name_en,
                $this->father_name_am ?? $this->father_name_en,
                $this->grandfather_name_am ?? $this->grandfather_name_en,
            ];
        } else {
            $parts = [
                $this->first_name_en ?? $this->first_name_am,
                $this->father_name_en ?? $this->father_name_am,
                $this->grandfather_name_en ?? $this->grandfather_name_am,
            ];
        }

        return implode(' ', array_filter($parts, static fn ($p) => $p !== null && $p !== ''));
    }
}