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
use MaaleArna\MASES\Core\Auth\Models\User;
use MaaleArna\MASES\Core\Domains\Shareholder\Enums\HoldingDirection;

/**
 * ShareHoldingEntry
 *
 * Eloquent model for a single row in the share-holding ledger.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * Every change to a ShareHolding's `current_quantity` produces exactly one
 * row on this table. The row records:
 *
 *   quantity        — the SIGNED change applied to the holding. Positive
 *                     for increases, negative for decreases. The sign is
 *                     derived from the direction supplied by the caller,
 *                     applied to a non-negative magnitude (see
 *                     HoldingDirection::applyTo, 2A.4).
 *
 *   balance_before  — the value of ShareHolding.current_quantity immediately
 *                     before this entry.
 *
 *   balance_after   — the value of ShareHolding.current_quantity immediately
 *                     after this entry.
 *
 *   entry_type      — a controlled vocabulary value describing WHAT kind of
 *                     business event produced the change. Six values are
 *                     permitted in Phase 2A (D16-R8):
 *
 *                         subscription
 *                         transfer_in
 *                         transfer_out
 *                         allocation
 *                         adjustment
 *                         reversal
 *
 *   reference_type  — an optional free-form string identifying the source
 *   reference_id      object of the change (e.g. "ShareTransfer" / "42").
 *                     This is the primary audit link from the ledger back to
 *                     the business operation. The `description` field is
 *                     informational and MUST NOT be the only explanation of
 *                     what happened.
 *
 *   occurred_at     — when the business event actually happened. This is NOT
 *                     the same as `created_at`: an administrative backfill
 *                     may create an entry now for an event that occurred
 *                     earlier. The ledger orders by `occurred_at`.
 *
 *   created_by      — the user who recorded the entry. Nullable for system-
 *                     generated entries.
 *
 *   reversed_entry_id — set on a reversal entry. Points back to the original
 *                       entry that is being reversed. The original is never
 *                       modified, deleted, or flagged — the linkage is
 *                       one-directional from the reversal to the original,
 *                       so any single row can be inspected without a
 *                       follow-up query to discover whether it is a
 *                       reversal.
 *
 * =============================================================================
 * Immutability (D16-R1)
 * =============================================================================
 * A posted entry is immutable. It cannot be edited and cannot be deleted:
 *
 *   - The model does NOT define `$fillable` for update; every column is
 *     assigned via the service's `create()` call, and no subsequent save is
 *     permitted by the domain code.
 *
 *   - The model sets `public $timestamps = false`. Laravel will therefore
 *     not attempt to touch `updated_at` (which does not exist on the table)
 *     during any operation.
 *
 *   - The model provides NO `update()`, `save()` override, or mutator that
 *     would let a caller change a posted row.
 *
 *   - Corrective action is performed by creating a NEW entry whose
 *     `reversed_entry_id` points to the original. The original row remains
 *     exactly as it was.
 *
 * If a future business requirement genuinely needs to modify a posted entry
 * — which would be unusual — the correct answer is a Change Request that
 * adds a new entry type or a new reversal pattern, not a new mutator.
 *
 * =============================================================================
 * Directional semantics
 * =============================================================================
 * The ledger does not store a `direction` column. Direction is encoded by
 * the SIGN of `quantity`. The `direction()` accessor infers the direction
 * from that sign:
 *
 *     quantity > 0   →  HoldingDirection::In
 *     quantity < 0   →  HoldingDirection::Out
 *     quantity = 0   →  HoldingDirection::Neutral
 *
 * For entry types where the direction is implied by the type itself
 * (`subscription`, `transfer_in`, `allocation` → In; `transfer_out` → Out),
 * `HoldingDirection::tryFromEntryType()` gives the same answer. For
 * `adjustment` and `reversal`, direction must come from the sign.
 *
 * The two methods — `directionFromSign()` and `directionFromType()` — are
 * provided separately so callers can be explicit about which they mean.
 * The plain `direction()` accessor prefers the sign, because the sign is
 * what the invariant `balance_before + quantity = balance_after` rests on.
 *
 * =============================================================================
 * The reversal linkage (D16-R2, D16-X5)
 * =============================================================================
 * When an entry is reversed:
 *
 *   1. The original entry remains unchanged.
 *   2. A NEW entry is created with `entry_type = 'reversal'`.
 *   3. The new entry has `reversed_entry_id = original.id`.
 *   4. The new entry's `quantity` is the numeric negation of the original.
 *   5. Its `balance_before` matches the original's `balance_after`.
 *   6. Its `balance_after` matches the original's `balance_before`.
 *
 * The model exposes two read helpers for this:
 *
 *   reversedEntry()  — the row this entry reverses (belongsTo self).
 *   reversals()      — the row(s) that reverse this entry (hasMany self).
 *                      By construction there should be at most one, but the
 *                      relation is HasMany so a double-reversal cannot be
 *                      masked by a relation that silently returns one.
 *                      The service is responsible for preventing double
 *                      reversals at write time.
 *
 * =============================================================================
 * No fractional shares (D6)
 * =============================================================================
 * The `quantity`, `balance_before`, and `balance_after` columns are all
 * DECIMAL(20,4). The ShareHoldingService enforces at write time that every
 * magnitude for a ShareHoldingEntry is a whole number. A row whose
 * fractional part is non-zero should never exist; if one is discovered, it
 * is a data-integrity incident, and the `hasWholeNumberQuantities()` helper
 * on this model will detect it for reporting.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   shareHolding()   — the holding this entry belongs to.
 *   createdBy()      — the user who recorded the entry.
 *   reversedEntry()  — the row this entry reverses (if any).
 *   reversals()      — the row(s) that reverse this entry (if any).
 *
 * @property int                 $id
 * @property int                 $share_holding_id
 * @property string              $entry_type
 * @property string              $quantity          Signed decimal string.
 * @property string              $balance_before    Decimal string.
 * @property string              $balance_after     Decimal string.
 * @property string|null         $reference_type
 * @property string|null         $reference_id
 * @property string|null         $description
 * @property Carbon $occurred_at
 * @property int|null            $created_by
 * @property int|null            $reversed_entry_id
 * @property Carbon|null $created_at
 *
 * @property-read ShareHolding|null $shareHolding
 * @property-read User|null         $createdBy
 * @property-read ShareHoldingEntry|null $reversedEntry
 * @property-read Collection<int, ShareHoldingEntry> $reversals
 *
 * @package MaaleArna\MASES\Core\Domains\Shareholder\Models
 */
final class ShareHoldingEntry extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'share_holding_entries';

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
     * Whether Eloquent should manage created_at/updated_at automatically.
     *
     * Set to false because:
     *
     *   1. The table has NO updated_at column — the row is immutable.
     *   2. `created_at` is set explicitly by the ShareHoldingService from
     *      the same transaction clock that produces `occurred_at`, which
     *      keeps the two values aligned and avoids a race with Laravel's
     *      automatic timestamping.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * This list is used ONLY by ShareHoldingService (2A.14). Controllers,
     * requests, and resources MUST NOT construct an entry directly — they
     * pass a direction + magnitude to the service and the service records
     * the entry.
     *
     * @var list<string>
     */
    protected $fillable = [
        'share_holding_id',
        'entry_type',
        'quantity',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'occurred_at',
        'created_by',
        'reversed_entry_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * `quantity`, `balance_before`, and `balance_after` are all cast as
     * `decimal:4`, which yields STRINGS with four decimal places. Do not
     * change these to float — financial and share-quantity arithmetic must
     * be performed with bcmath or a decimal library, never with PHP's
     * native floating-point operators.
     *
     * `occurred_at` and `created_at` are cast as datetime so that callers
     * receive Carbon instances rather than raw strings.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'share_holding_id'  => 'integer',
        'quantity'          => 'decimal:4',
        'balance_before'    => 'decimal:4',
        'balance_after'     => 'decimal:4',
        'occurred_at'       => 'datetime',
        'created_by'        => 'integer',
        'reversed_entry_id' => 'integer',
        'created_at'        => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. Resources (2A.27) select fields explicitly.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The holding this entry belongs to.
     *
     * @return BelongsTo
     */
    public function shareHolding(): BelongsTo
    {
        return $this->belongsTo(ShareHolding::class, 'share_holding_id', 'id');
    }

    /**
     * The user who recorded this entry, if any.
     *
     * Nullable for entries written by the system itself (e.g. automated
     * corrections). The user is never nullable-by-default for entries
     * recorded through the HTTP API; the ShareHoldingService populates
     * `created_by` from the authenticated user.
     *
     * @return BelongsTo
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * The entry this row reverses, if any.
     *
     * Non-null only when this entry's `entry_type` is `reversal`. Points
     * to the original posted entry that the reversal undoes.
     *
     * @return BelongsTo
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id', 'id');
    }

    /**
     * The row(s) that reverse this entry, if any.
     *
     * By construction there should be at most one, because the service
     * prevents double-reversals at write time. The relation is HasMany so
     * that a violated invariant surfaces as multiple rows rather than
     * being masked by a `hasOne` that silently returns the first one.
     *
     * @return HasMany
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_entry_id', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to entries of a specific holding.
     *
     * @param Builder $query
     * @param int     $shareHoldingId
     * @return Builder
     */
    public function scopeForHolding(Builder $query, int $shareHoldingId): Builder
    {
        return $query->where('share_holding_id', $shareHoldingId);
    }

    /**
     * Scope a query by entry type.
     *
     * @param Builder $query
     * @param string  $entryType
     * @return Builder
     */
    public function scopeOfType(Builder $query, string $entryType): Builder
    {
        return $query->where('entry_type', $entryType);
    }

    /**
     * Scope a query to entries that are reversal rows.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeReversals(Builder $query): Builder
    {
        return $query->where('entry_type', 'reversal');
    }

    /**
     * Scope a query to entries that have been reversed.
     *
     * A row has been reversed if another row exists with
     * `reversed_entry_id = this.id`.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeReversed(Builder $query): Builder
    {
        return $query->whereIn('id', function ($sub) {
            $sub->select('reversed_entry_id')
                ->from('share_holding_entries')
                ->whereNotNull('reversed_entry_id');
        });
    }

    // =========================================================================
    // Direction inference (read-only)
    // =========================================================================

    /**
     * Infer direction from the SIGN of `quantity`.
     *
     * This is the authoritative direction for the entry, because the
     * invariant `balance_before + quantity = balance_after` is defined on
     * the sign. Callers should prefer this method when they need the
     * direction that the ledger actually applied.
     *
     * @return HoldingDirection
     */
    public function directionFromSign(): HoldingDirection
    {
        $q = (string) $this->quantity;

        // bcmath-based comparison avoids float coercion on the decimal:4 string.
        if (bccomp($q, '0', 4) > 0) {
            return HoldingDirection::In;
        }

        if (bccomp($q, '0', 4) < 0) {
            return HoldingDirection::Out;
        }

        return HoldingDirection::Neutral;
    }

    /**
     * Infer direction from the ENTRY TYPE alone.
     *
     * Returns null for `adjustment` and `reversal`, because the type alone
     * does not determine direction. Callers that need a definite direction
     * for those types must fall back to `directionFromSign()`.
     *
     * @return HoldingDirection|null
     */
    public function directionFromType(): ?HoldingDirection
    {
        return HoldingDirection::tryFromEntryType($this->entry_type);
    }

    /**
     * The effective direction of the entry.
     *
     * Prefers the sign of `quantity` because that is the value that
     * determined the balance change. For unambiguous entry types the
     * result is the same either way; for ambiguous types the sign is
     * the correct answer.
     *
     * @return HoldingDirection
     */
    public function direction(): HoldingDirection
    {
        return $this->directionFromSign();
    }

    // =========================================================================
    // Read-only helpers
    // =========================================================================

    /**
     * Whether this entry has been reversed by another entry.
     *
     * @return bool
     */
    public function isReversed(): bool
    {
        return $this->reversals()->exists();
    }

    /**
     * Whether this entry is itself a reversal of another entry.
     *
     * @return bool
     */
    public function isReversal(): bool
    {
        return $this->entry_type === 'reversal' && $this->reversed_entry_id !== null;
    }

    /**
     * Whether this entry's `quantity`, `balance_before`, and `balance_after`
     * are all whole numbers.
     *
     * This is a defensive check. Per D6, fractional shares are not permitted,
     * and the service enforces that at write time. A persisted entry with a
     * non-whole value is a data-integrity incident and should be surfaced
     * by diagnostics, not silently tolerated.
     *
     * @return bool
     */
    public function hasWholeNumberQuantities(): bool
    {
        return $this->isWholeDecimal((string) $this->quantity)
            && $this->isWholeDecimal((string) $this->balance_before)
            && $this->isWholeDecimal((string) $this->balance_after);
    }

    /**
     * Verify that the entry's quantities satisfy the ledger invariant:
     *
     *     balance_before + quantity = balance_after
     *
     * Uses bcmath. This is a READ-ONLY integrity check, intended for
     * diagnostics and tests. It does not enforce anything at write time.
     *
     * @return bool
     */
    public function satisfiesBalanceInvariant(): bool
    {
        $sum = bcadd((string) $this->balance_before, (string) $this->quantity, 4);

        return bccomp($sum, (string) $this->balance_after, 4) === 0;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Whether a decimal string has a zero fractional part.
     *
     * @param string $value
     * @return bool
     */
    private function isWholeDecimal(string $value): bool
    {
        $unsigned = ltrim($value, '+-');
        $dotPos   = strpos($unsigned, '.');

        if ($dotPos === false) {
            return true;
        }

        return trim(substr($unsigned, $dotPos + 1), '0') === '';
    }
}