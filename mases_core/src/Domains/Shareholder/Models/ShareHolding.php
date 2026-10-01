<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ShareHolding
 *
 * Eloquent model for a shareholder's current holding of a specific share class.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * A share holding is the CURRENT-STATE PROJECTION of the (shareholder,
 * share class) pair. It answers exactly one question:
 *
 *     How many shares of class X does shareholder Y currently hold?
 *
 * The answer is stored in the single column `current_quantity`. Nothing else
 * on this table changes the meaning of that number: no currency, no amount,
 * no aggregate, no alternate representation.
 *
 * =============================================================================
 * Why the balance is not the source of truth
 * =============================================================================
 * Decision D16-CORE is unambiguous:
 *
 *     ShareHolding       = current-state projection
 *     ShareHoldingEntry  = append-only ledger
 *
 * Every change to `current_quantity` MUST be accompanied by a
 * `ShareHoldingEntry` row recording:
 *
 *     quantity        — the signed change that produced the new balance
 *     balance_before  — the previous value of `current_quantity`
 *     balance_after   — the new value of `current_quantity`
 *
 * Both writes occur inside one database transaction, with the `ShareHolding`
 * row locked FOR UPDATE, per D16-R6. The invariant
 *
 *     balance_before + quantity = balance_after
 *
 * holds for every entry ever written. If the holding row and the ledger
 * entries ever disagree, the ledger is authoritative; the projection is
 * rebuilt from it.
 *
 * =============================================================================
 * Why this model exposes no mutation methods
 * =============================================================================
 * The model deliberately provides NO method that changes `current_quantity`:
 *
 *   - No `increment()` / `decrement()` overrides.
 *   - No `addQuantity()`, `subtractQuantity()`, or `adjustQuantity()`.
 *   - No `setCurrentQuantityAttribute()` mutator beyond Eloquent's default.
 *
 * This is not an oversight. Any such method would be reachable from a
 * controller and would let the caller change the projection without writing
 * the ledger. Removing that path at the model level is the simplest
 * structural guarantee that D16-CORE is honoured.
 *
 * The ONLY permitted writer is `ShareHoldingService` (2A.14), which:
 *   1. Opens a database transaction.
 *   2. Locks the ShareHolding row FOR UPDATE.
 *   3. Reads `current_quantity` into `balance_before`.
 *   4. Computes `balance_after` from the caller's direction and magnitude.
 *   5. Rejects the operation if `balance_after` would be negative (D16-R5).
 *   6. Creates a ShareHoldingEntry row with the computed values.
 *   7. Updates `current_quantity` to `balance_after`.
 *   8. Commits.
 *
 * Controllers, policies, and this model do not participate in that flow.
 * They read; the service writes.
 *
 * =============================================================================
 * Fractional shares (D6)
 * =============================================================================
 * The `current_quantity` column is DECIMAL(20,4) — four decimal places are
 * reserved for future business needs (D16-X2). However, decision D6 states
 * that FRACTIONAL SHARES ARE NOT PERMITTED at the business level:
 * a share is an indivisible unit.
 *
 * The model therefore provides a `quantityIsWholeNumber()` helper that
 * reports whether the current value happens to be a whole number. The
 * authoritative enforcement of D6 lives in `ShareHoldingService`, which
 * rejects any incoming magnitude whose fractional part is non-zero. This
 * helper is a read-only check for diagnostics and tests, not an enforcer.
 *
 * =============================================================================
 * No currency (D16-R7)
 * =============================================================================
 * This table stores a quantity of shares. It does NOT store a monetary value.
 * Currency and per-share price, when they matter, belong to the future
 * transaction objects (subscriptions, transfers, issuances) that will be
 * defined by their own manifests. Adding a `currency` column here would
 * conflate quantity with value and would violate D16-R7.
 *
 * =============================================================================
 * Deletion semantics
 * =============================================================================
 * This model does NOT use SoftDeletes. A holding row is a lightweight
 * projection; it has no intrinsic history that must be preserved beyond the
 * ledger. When a shareholder is soft-deleted, its holding rows persist
 * unchanged so that historical reporting and audit queries continue to
 * resolve. Holding rows are never hard-deleted by the domain.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   shareholder()  — the owner of this holding.
 *   shareClass()   — the class of shares being held.
 *   entries()      — the ordered ledger of every change that produced the
 *                    current quantity. Ordered by occurred_at ascending.
 *
 * The `entries()` relation target `ShareHoldingEntry` (2A.9) is referenced
 * by fully-qualified string, so this file loads before that class exists.
 *
 * @property int                 $id
 * @property int                 $shareholder_id
 * @property int                 $share_class_id
 * @property string              $current_quantity   Decimal string, e.g. "145.0000"
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Shareholder|null           $shareholder
 * @property-read ShareClass|null            $shareClass
 * @property-read Collection<int, ShareHoldingEntry> $entries
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Models
 */
final class ShareHolding extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'share_holdings';

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
     * Note: `current_quantity` is listed only so that the ShareHoldingService
     * can set it during its update step via mass assignment inside the
     * transaction. Do NOT pass it from a controller. The service is the
     * only class that may legitimately assign it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shareholder_id',
        'share_class_id',
        'current_quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * `current_quantity` is cast as `decimal:4`, which yields a STRING with
     * four decimal places (e.g. "145.0000"). This is deliberate: monetary
     * and share-quantity values must not be handled as floats, because
     * floats cannot represent all decimal fractions exactly and financial
     * arithmetic requires decimal semantics. Callers performing arithmetic
     * must use bcmath or a decimal library on the string value.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'shareholder_id'   => 'integer',
        'share_class_id'   => 'integer',
        'current_quantity' => 'decimal:4',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. Resources select fields explicitly (2A.27).
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The shareholder who owns this holding.
     *
     * @return BelongsTo
     */
    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class, 'shareholder_id', 'id');
    }

    /**
     * The share class this holding is denominated in.
     *
     * @return BelongsTo
     */
    public function shareClass(): BelongsTo
    {
        return $this->belongsTo(ShareClass::class, 'share_class_id', 'id');
    }

    /**
     * Every ledger entry that affected this holding, in chronological order.
     *
     * The entries are the authoritative record of how `current_quantity`
     * reached its present value. This relation is read-only from the model's
     * perspective; entries are created by ShareHoldingService only.
     *
     * @return HasMany
     */
    public function entries(): HasMany
    {
        return $this->hasMany(
            ShareHoldingEntry::class,
            'share_holding_id',
            'id'
        )->orderBy('occurred_at', 'asc')->orderBy('id', 'asc');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to holdings of a specific shareholder.
     *
     * @param Builder $query
     * @param int     $shareholderId
     * @return Builder
     */
    public function scopeForShareholder(Builder $query, int $shareholderId): Builder
    {
        return $query->where('shareholder_id', $shareholderId);
    }

    /**
     * Scope a query to holdings of a specific share class.
     *
     * @param Builder $query
     * @param int     $shareClassId
     * @return Builder
     */
    public function scopeOfClass(Builder $query, int $shareClassId): Builder
    {
        return $query->where('share_class_id', $shareClassId);
    }

    /**
     * Scope a query to holdings with a non-zero current quantity.
     *
     * Useful for "list my current holdings" style endpoints that do not
     * want to show zero-balance rows.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithPositiveQuantity(Builder $query): Builder
    {
        return $query->where('current_quantity', '>', 0);
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * Whether the current quantity is a whole number.
     *
     * Uses string comparison to avoid float arithmetic. A decimal-cast value
     * such as "145.0000" is a whole number; "145.5000" is not.
     *
     * This is a READ-ONLY diagnostic. It does NOT enforce D6. The
     * authoritative enforcement of "no fractional shares" lives in
     * ShareHoldingService (2A.14), which rejects incoming magnitudes whose
     * fractional part is non-zero before persisting the entry and the new
     * balance.
     *
     * @return bool
     */
    public function quantityIsWholeNumber(): bool
    {
        $value = (string) $this->current_quantity;

        // Normalise: strip a leading sign, then test whether every character
        // after the decimal point is '0'.
        $unsigned = ltrim($value, '+-');
        $dotPos   = strpos($unsigned, '.');

        if ($dotPos === false) {
            return true;
        }

        $fractionalPart = substr($unsigned, $dotPos + 1);

        return trim($fractionalPart, '0') === '';
    }

    /**
     * Whether the current quantity is strictly positive.
     *
     * Uses numeric string comparison rather than float casting. The service
     * layer is responsible for ensuring this value is never negative
     * (D16-R5).
     *
     * @return bool
     */
    public function hasPositiveQuantity(): bool
    {
        return bccomp((string) $this->current_quantity, '0', 4) > 0;
    }

    /**
     * Whether the current quantity is exactly zero.
     *
     * A zero-balance holding row is valid: the shareholder existed, held
     * shares, and transferred all of them out. The row persists so that
     * the ledger entries remain anchored.
     *
     * @return bool
     */
    public function isZeroQuantity(): bool
    {
        return bccomp((string) $this->current_quantity, '0', 4) === 0;
    }

    /**
     * The current quantity as a decimal string with four places.
     *
     * Convenience for callers that need the raw string without going through
     * the Eloquent attribute accessor. Returns a normalised "0.0000" for a
     * null value, which should not occur on a persisted row but is defensive.
     *
     * @return string
     */
    public function quantityString(): string
    {
        if ($this->current_quantity === null) {
            return '0.0000';
        }

        return (string) $this->current_quantity;
    }
}