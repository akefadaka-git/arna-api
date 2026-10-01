<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ShareClass
 *
 * Eloquent model for a share class issued by Maale Arna Share Company.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A share class is a category of share capital with defined characteristics
 * (par value, currency, and — in future phases — rights and restrictions).
 * Phase 2A recognises exactly two classes:
 *
 *   FOUNDERS  (name_am = መስራች)  — held by the 29 founding shareholders.
 *   STANDARD  (name_am = መደበኛ)  — the default class for new shareholders.
 *
 * The set of valid codes is declared in
 * `Config/shareholder.php` under `share_classes.codes`. This model does not
 * hard-code the codes; it reads them from configuration so that a future
 * business decision to add or rename a class does not require editing the
 * model. Adding a code is still a Change Request, because it changes the
 * vocabulary of historical data.
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not change ShareHolding balances. Balances are modified only
 *     by ShareHoldingService (2A.14), inside a database transaction, via
 *     append-only ShareHoldingEntry rows (D16-CORE).
 *
 *   - It does not enforce any lifecycle beyond `is_active`. Share classes
 *     are reference data; they are activated, deactivated, or soft-deleted.
 *
 *   - It does not provide display strings. Localized labels are resolved by
 *     the resource layer (2A.26) through translation keys of the form
 *     `shareholder.share_class.{code}`.
 *
 * =============================================================================
 * Amharic-first policy
 * =============================================================================
 * The `$fillable` array lists `name_am` before `name_en`, mirroring the
 * column order declared by the migration. The `display_name` accessor
 * resolves the value based on the active application locale, with Amharic
 * as the default and English as the fallback, and further falls back to
 * the canonical `code` when both name columns are null. This guarantees
 * that the accessor never returns an empty string in any locale.
 *
 * =============================================================================
 * Casts
 * =============================================================================
 * `par_value` is cast as `decimal:4`, which yields a STRING with four
 * decimal places (e.g. "10000.0000"). This is deliberate: monetary values
 * must not be handled as floats, because floats cannot represent all
 * decimal fractions exactly, and financial arithmetic requires decimal
 * semantics. Callers that need arithmetic MUST use bcmath or a decimal
 * value object on the string value, not PHP arithmetic operators.
 *
 * `is_active` and `is_treasury_eligible` are cast as booleans so that
 * consumers can branch on truthiness directly.
 *
 * =============================================================================
 * Soft deletes
 * =============================================================================
 * The model uses SoftDeletes. Share classes that are no longer active
 * remain in the database for historical reporting — a ShareHolding or
 * ShareCertificate may still reference a class that has been retired,
 * and that reference must remain resolvable.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   shareHoldings()      — every holding that references this class.
 *   shareCertificates()  — every certificate issued under this class.
 *
 * Relations are declared with fully-qualified class references so that the
 * related model classes (2A.8, 2A.10) do not need to exist at file load
 * time. Eloquent resolves them lazily on first access.
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
 *
 * @property-read string      $display_name  Locale-aware display name.
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Models
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