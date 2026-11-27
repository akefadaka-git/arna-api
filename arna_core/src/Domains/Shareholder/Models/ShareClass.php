<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Shareholder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Share-class vocabulary is configuration-driven; this model does not change holding balances.
 *
 * @property int              $id
 * @property string           $code
 * @property string|null      $name_am
 * @property string|null      $name_en
 * @property string           $par_value
 * @property string           $currency
 * @property bool             $is_active
 * @property bool             $is_treasury_eligible
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string      $display_name  Locale-aware display name.
 * @package MaaleArna\Arna\Core\Domains\Shareholder\Models
 */
final class ShareClass extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'share_classes';

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
     * Order is Amharic-first: `name_am` precedes `name_en`. `code` is
     * listed first because it is the canonical identifier.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_am',
        'name_en',
        'par_value',
        'currency',
        'is_active',
        'is_treasury_eligible',
    ];

    /**
     * The attributes that should be cast.
     *
     * `par_value` is cast as `decimal:4`, which yields a string. Do not
     * change this to `float` or `double` — see the class-level docblock.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'par_value'            => 'decimal:4',
        'is_active'            => 'boolean',
        'is_treasury_eligible' => 'boolean',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose: `display_name` is available on demand via
     * `$shareClass->display_name`, but it is not force-serialized into
     * every JSON response. Resources that need it will include it
     * explicitly (2A.26).
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
     * The canonical form of a share-class code is UPPERCASE with no
     * surrounding whitespace. Normalising at the model boundary means the
     * store-side always contains a single canonical representation, so a
     * `findByCode('founders')` lookup and a `findByCode('FOUNDERS')` lookup
     * produce the same result.
     *
     * Leading and trailing whitespace is trimmed; the value is uppercased
     * using the multibyte-safe strtoupper for consistency.
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
     * Get the share class name in the active application locale.
     *
     * Resolution order:
     *
     *   1. Amharic (`name_am`) when the locale is `am`.
     *   2. English (`name_en`) when the locale is not `am`.
     *   3. The other language as a fallback when the preferred one is null.
     *   4. The canonical `code` as a last resort, so the accessor NEVER
     *      returns an empty string.
     *
     * The accessor is read-only. Setting `display_name` has no effect;
     * callers must set `name_am` or `name_en` directly.
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
    // Relations
    // =========================================================================

    /**
     * Every share holding that references this share class.
     *
     * A holding is the current-state projection of a (shareholder, share class)
     * pair. The relation is one-to-many: a single share class may be held by
     * many shareholders, each holding producing one row.
     *
     * @return HasMany
     */
    public function shareHoldings(): HasMany
    {
        return $this->hasMany(
            ShareHolding::class,
            'share_class_id',
            'id'
        );
    }

    /**
     * Every share certificate issued under this share class.
     *
     * @return HasMany
     */
    public function shareCertificates(): HasMany
    {
        return $this->hasMany(
            ShareCertificate::class,
            'share_class_id',
            'id'
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to only include active share classes.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query by share-class code.
     *
     * The supplied code is normalised the same way the mutator normalises
     * stored values, so callers can pass either case safely.
     *
     * @param Builder $query
     * @param string  $code
     * @return Builder
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper(trim($code)));
    }

    // =========================================================================
    // Domain helpers
    // =========================================================================

    /**
     * Find a share class by its code, returning null when not found.
     *
     * The lookup is case-insensitive because the mutator normalises both
     * sides of the comparison.
     *
     * @param string $code
     * @return self|null
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()
            ->byCode($code)
            ->first();
    }

    /**
     * Find a share class by its code, or throw if not found.
     *
     * @param string $code
     * @return self
     *
     * @throws ModelNotFoundException
     */
    public static function findByCodeOrFail(string $code): self
    {
        return static::query()
            ->byCode($code)
            ->firstOrFail();
    }

    /**
     * Whether this share class is the founders' class.
     *
     * The comparison is against `config('shareholder.share_classes.founder_code')`,
     * which declares the canonical code. Changing which class is considered
     * the founder class requires a Change Request, because it changes the
     * interpretation of existing data.
     *
     * @return bool
     */
    public function isFounderClass(): bool
    {
        $founderCode = (string) config('shareholder.share_classes.founder_code', 'FOUNDERS');

        return strtoupper($this->code) === strtoupper($founderCode);
    }

    /**
     * Whether this share class is the default class for newly registered
     * shareholders.
     *
     * @return bool
     */
    public function isDefaultClass(): bool
    {
        $defaultCode = (string) config('shareholder.share_classes.default_code', 'STANDARD');

        return strtoupper($this->code) === strtoupper($defaultCode);
    }

    /**
     * Whether this share class is currently active.
     *
     * Mirrors `User::isActive()`. Does not consider soft-deletion — a
     * soft-deleted share class is not returned by a default query at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /**
     * Whether this share class supports treasury / buyback operations.
     *
     * Per D7, treasury is permitted at the platform level. This per-class
     * flag allows an administrator to disable treasury on a specific class
     * without disabling the feature globally.
     *
     * @return bool
     */
    public function isTreasuryEligible(): bool
    {
        return $this->is_treasury_eligible === true;
    }
}