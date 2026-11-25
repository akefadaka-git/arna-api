<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\MASES\Core\Auth\Models\User;

/**
 * Immutable write-off audit record; a loan is written off once, as a terminal transition, with outstanding Wallet obligations discharged transactionally.
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property string                  $write_off_amount
 * @property string                  $outstanding_principal_at_writeoff
 * @property string                  $outstanding_interest_at_writeoff
 * @property string                  $outstanding_penalty_at_writeoff
 * @property string                  $reason
 * @property Carbon $written_off_at
 * @property int|null                $written_off_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Loan|null          $loan
 * @property-read User|null          $writtenOffBy
 * @package MaaleArna\MASES\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanWriteOff extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_write_offs';

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
     * The list is authoritative for the `LoanWriteOffService::writeOff()`
     * call site (A.31), which inserts one row per write-off. Because the
     * row is fully written at insert and never modified, the `$fillable`
     * list includes every column except the primary key and the Eloquent-
     * managed timestamps.
     *
     * `written_off_at` and `written_off_by` ARE fillable: the service
     * supplies them explicitly. The migration A.13 declares
     * `written_off_at` with a `useCurrent()` default, but the service
     * supplies the value for consistency with `created_at`.
     *
     * `id` is NOT fillable — it is auto-assigned by the database.
     * `created_at` and `updated_at` are NOT fillable — Eloquent manages
     * them via the `$timestamps` mechanism.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'write_off_amount',
        'outstanding_principal_at_writeoff',
        'outstanding_interest_at_writeoff',
        'outstanding_penalty_at_writeoff',
        'reason',
        'written_off_at',
        'written_off_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'                            => 'integer',
        'write_off_amount'                   => 'decimal:4',
        'outstanding_principal_at_writeoff'  => 'decimal:4',
        'outstanding_interest_at_writeoff'   => 'decimal:4',
        'outstanding_penalty_at_writeoff'    => 'decimal:4',
        'written_off_at'                     => 'datetime',
        'written_off_by'                     => 'integer',
        'created_at'                         => 'datetime',
        'updated_at'                         => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. The `LoanResource` (A.64) embeds the
     * write-off via `whenLoaded()` when a controller chooses to load it;
     * the model does not force-serialize a computed value.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Lifecycle — immutability enforcement
    // =========================================================================

    /**
     * Register the model's immutability guard.
     *
     * Called once per model class, after the model's traits have booted.
     * Registers two guards:
     *
     *   - `updating` — throws on any attempt to persist a change to a
     *                   loaded row.
     *   - `deleting` — throws on any attempt to remove a row.
     *
     * `creating` is intentionally NOT guarded: `LoanWriteOffService`
     * (A.31) creates rows, and creation is the only legitimate write.
     *
     * The guard mirrors the pattern established by Core Policy's
     * `VersionedPolicy` (CORE.3), Phase 2B's `WalletEntry` (2B.10), and
     * the sibling `LoanRestructuring` (A.21).
     *
     * The error message points at the correct remedy: a write-off is a
     * terminal financial fact. Reversing a write-off is out of Phase 2C
     * scope and requires its own Change Request.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanWriteOff $row): void {
            throw new LogicException(
                'LoanWriteOff rows are immutable once written '
                . '(migration A.13, class docblock §"Immutability"). '
                . 'A write-off is a terminal financial fact; reversing '
                . 'one requires a dedicated Change Request. '
                . 'Attempted update on loan_write_offs.id='
                . $row->getKey() . '.'
            );
        });

        static::deleting(static function (LoanWriteOff $row): void {
            throw new LogicException(
                'LoanWriteOff rows cannot be deleted '
                . '(migration A.13, class docblock §"Deletion semantics"). '
                . 'Write-off history is permanent. '
                . 'Attempted delete on loan_write_offs.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this write-off applies to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The user who performed the write-off.
     *
     * Nullable and `nullOnDelete()` per migration A.13: a hard-deleted
     * user detaches from the row, which survives with a null actor.
     *
     * The nullable column also accommodates a future Change Request that
     * introduces system-initiated write-offs (e.g. an end-of-fiscal-year
     * policy that forgives sub-threshold balances), for which there is no
     * human actor.
     *
     * @return BelongsTo
     */
    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by', 'id');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific loan.
     *
     * @param Builder $query
     * @param int     $loanId
     * @return Builder
     */
    public function scopeForLoan(Builder $query, int $loanId): Builder
    {
        return $query->where('loan_id', $loanId);
    }

    /**
     * Scope a query to chronological order.
     *
     * Orders by `written_off_at` ascending, with an `id` tiebreaker for
     * same-instant write-offs. Matches the migration A.13's single
     * composite index `(loan_id, written_off_at)`; the `id` tiebreaker
     * uses the primary key.
     *
     * Because a loan has at most one write-off, the ordering is
     * meaningful only when a report enumerates write-offs across many
     * loans.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('written_off_at')->orderBy('id');
    }

    /**
     * Scope a query to most-recent-first order.
     *
     * The reverse of `scopeChronological()`. Used by administrative
     * dashboards that show the latest write-offs first.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('written_off_at')->orderByDesc('id');
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * The sum of the three `*_at_writeoff` columns, as a decimal string
     * at scale 4.
     *
     * This is the loan's total outstanding at the moment the write-off
     * was performed. Under the frozen write-off flow, this equals the
     * row's `write_off_amount`; the two are separate for the reasons
     * documented in the migration A.13 §"The 'at write-off' columns and
     * the sum invariant".
     *
     * The three columns are NOT NULL per migration A.13, so no null
     * handling is required.
     *
     * @return string  Decimal string at scale 4.
     */
    public function outstandingAtWriteOffTotal(): string
    {
        return bcadd(
            bcadd(
                (string) $this->outstanding_principal_at_writeoff,
                (string) $this->outstanding_interest_at_writeoff,
                4,
            ),
            (string) $this->outstanding_penalty_at_writeoff,
            4,
        );
    }

    /**
     * Whether this is a full write-off.
     *
     * True when `write_off_amount` equals the sum of the three
     * `*_at_writeoff` columns at scale 4. This is the standard case that
     * the frozen write-off flow produces: the entire outstanding balance
     * is forgiven.
     *
     * @return bool
     */
    public function isFullWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) === 0;
    }

    /**
     * Whether this is a partial write-off.
     *
     * True when `write_off_amount` is strictly less than the sum of the
     * three `*_at_writeoff` columns at scale 4.
     *
     * The frozen Phase 2C write-off flow does NOT produce partial
     * write-offs — every write-off forgives the entire outstanding
     * balance, and the loan transitions to `written_off` in the same
     * transaction. The helper exists because:
     *
     *   1. A future Change Request may introduce partial write-offs (for
     *      example, a restructuring-adjacent operation that forgives a
     *      portion of the debt and reschedules the remainder).
     *
     *   2. A defensive check in an audit or reconciliation pass can use
     *      the helper to detect whether an unexpected partial write-off
     *      has appeared in the data.
     *
     * @return bool
     */
    public function isPartialWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) < 0;
    }

    /**
     * Whether this is an excess write-off — a write-off amount greater
     * than the sum of the three `*_at_writeoff` columns.
     *
     * The frozen write-off flow cannot produce an excess write-off: the
     * amount is computed from the same three values that are stored on
     * the row. A `true` return from this helper indicates a data
     * integrity anomaly (either a service defect or an out-of-band edit
     * that bypassed the guard). It is surfaced so that an audit or
     * reconciliation pass can detect the anomaly rather than silently
     * ignore it.
     *
     * @return bool
     */
    public function isExcessWriteOff(): bool
    {
        return bccomp(
            (string) $this->write_off_amount,
            $this->outstandingAtWriteOffTotal(),
            4,
        ) > 0;
    }
}