<?php

declare(strict_types=1);

namespace MaaleArna\Arna\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\Arna\Core\Domains\Wallet\Models\Obligation;

/**
 * Loan-side record of penalties already accrued to Wallet obligations; scheduled accrual must be idempotent after the grace threshold.
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property int                     $installment_id
 * @property string                  $accrual_period_key
 * @property string                  $penalty_amount
 * @property int|null                $wallet_obligation_id
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property-read Loan|null          $loan
 * @property-read LoanInstallment|null $installment
 * @property-read Obligation|null    $walletObligation
 * @package MaaleArna\Arna\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanPenaltyAccrual extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_penalty_accruals';

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
     * Whether Eloquent should manage `created_at` / `updated_at`
     * automatically.
     *
     * Set to false. The migration A.16 declares `created_at` as nullable
     * and does NOT declare an `updated_at` column. With
     * `$timestamps = false`, Eloquent will not attempt to write either
     * column on `save()`. `created_at` is written explicitly by
     * `LoanArrearsService` (A.29) at insert time.
     *
     * This mirrors the pattern established by Phase 2A's
     * `ShareHoldingEntry` (2A.9), Phase 2B's `WalletEntry` (2B.10), and
     * the sibling `LoanInterestAccrual` (A.24).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * The list is authoritative for the `LoanArrearsService` (A.29)
     * insert call site. Because the row is inserted in one step and only
     * `wallet_obligation_id` is ever updated afterwards, the `$fillable`
     * list includes every column except the primary key. The
     * `wallet_obligation_id` column is fillable because the writer
     * supplies it as null at insert time; the subsequent write-back
     * happens through explicit attribute assignment, not mass assignment.
     *
     * `created_at` IS fillable: the writer writes it explicitly, and
     * `$timestamps = false` means Eloquent will not manage it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'loan_id',
        'installment_id',
        'accrual_period_key',
        'penalty_amount',
        'wallet_obligation_id',
        'recorded_at',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * See the class docblock §"Casts" for the rationale on each group.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'loan_id'              => 'integer',
        'installment_id'       => 'integer',
        'penalty_amount'       => 'decimal:4',
        'wallet_obligation_id' => 'integer',
        'recorded_at'          => 'datetime',
        'created_at'           => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. This model has no resource in the Phase 2C
     * manifest; penalty-accrual rows are surfaced through aggregate
     * reports and through the loan's detail view, not through a dedicated
     * endpoint.
     *
     * @var list<string>
     */
    protected $appends = [];

    // =========================================================================
    // Lifecycle — partial-mutability enforcement
    // =========================================================================

    /**
     * Register the model's mutability guard.
     *
     * Called once per model class, after the model's traits have booted.
     * Registers two guards, mirroring `LoanInterestAccrual` (A.24):
     *
     *   - `updating` — throws on any attempt to persist a change to a
     *                   loaded row, EXCEPT the one-time write of
     *                   `wallet_obligation_id` from null to a non-null
     *                   value. That single transition is the row's
     *                   designed write-back, and it occurs inside the
     *                   transaction that inserted the row.
     *
     *   - `deleting` — throws unconditionally on any attempt to remove a
     *                   row.
     *
     * `creating` is intentionally NOT guarded: the writer creates rows,
     * and creation is the only legitimate way to introduce a new penalty
     * accrual.
     *
     * The `updating` guard's permissive branch checks THREE conditions
     * together, all of which must hold for the update to be permitted:
     *
     *     1. The only dirty attribute is `wallet_obligation_id`.
     *     2. The persisted value of `wallet_obligation_id` is null (the
     *        row has not yet been written back).
     *     3. The in-memory value of `wallet_obligation_id` is non-null
     *        (the write-back is actually setting a value, not clearing
     *        one).
     *
     * If any condition fails, the guard throws `LogicException`. This
     * blocks — among other things — a caller that attempts to update
     * `penalty_amount`, `recorded_at`, or any other column after insert,
     * and a caller that attempts to null the `wallet_obligation_id` on a
     * row that already had one.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanPenaltyAccrual $row): void {
            $dirty = $row->getDirty();

            // Permitted case: the one-time write-back of
            // wallet_obligation_id from null to non-null, and nothing
            // else dirty.
            if (
                array_keys($dirty) === ['wallet_obligation_id']
                && $row->getOriginal('wallet_obligation_id') === null
                && $row->wallet_obligation_id !== null
            ) {
                return;
            }

            throw new LogicException(
                'LoanPenaltyAccrual rows permit only the one-time write '
                . 'of wallet_obligation_id from null to a non-null value, '
                . 'inside the inserting transaction (migration A.16, '
                . 'class docblock §"Partial mutability"). Any other '
                . 'attempted update — including a change to any other '
                . 'column, a re-write of wallet_obligation_id after the '
                . 'first write, or a nulling of wallet_obligation_id on a '
                . 'committed row — is forbidden. '
                . 'Attempted update on loan_penalty_accruals.id='
                . $row->getKey() . '; dirty attributes: '
                . implode(', ', array_keys($dirty)) . '.'
            );
        });

        static::deleting(static function (LoanPenaltyAccrual $row): void {
            throw new LogicException(
                'LoanPenaltyAccrual rows cannot be deleted '
                . '(migration A.16, class docblock §"Partial mutability"). '
                . 'Penalty history is permanent. '
                . 'Attempted delete on loan_penalty_accruals.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this penalty belongs to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The installment whose grace-threshold crossing triggered this
     * penalty.
     *
     * @return BelongsTo
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(
            LoanInstallment::class,
            'installment_id',
            'id',
        );
    }

    /**
     * The Wallet obligation produced by this penalty, if any.
     *
     * Targets `Obligation` from the Wallet domain (Phase 2B, 2B.6). This
     * is the sibling cross-domain relation to the one on A.24; see that
     * model's class docblock for the rationale on why the Loan domain
     * holds a direct FK rather than an opaque string reference.
     *
     * Nullable at the model level: the FK column is nullable to permit
     * the insert-then-update sequence (see the class docblock
     * §"Partial mutability"). A committed row always has a non-null value
     * in practice; `hasWalletObligation()` reports whether the value is
     * set.
     *
     * @return BelongsTo
     */
    public function walletObligation(): BelongsTo
    {
        return $this->belongsTo(
            Obligation::class,
            'wallet_obligation_id',
            'id',
        );
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope a query to a specific loan.
     *
     * Used by `LoanReconciliationService` (A.32) and by the loan detail
     * view to enumerate a loan's penalty accruals.
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
     * Scope a query to a specific installment.
     *
     * Because the unique constraint on `accrual_period_key` permits at
     * most one penalty per installment in the current scheme, this scope
     * returns at most one row.
     *
     * @param Builder $query
     * @param int     $installmentId
     * @return Builder
     */
    public function scopeForInstallment(Builder $query, int $installmentId): Builder
    {
        return $query->where('installment_id', $installmentId);
    }

    /**
     * Scope a query to penalties that have been written back to a Wallet
     * obligation.
     *
     * A committed penalty row always satisfies this scope. The scope's
     * complement (`scopeUnlinked`) exists for defensive diagnostics and
     * for tests that need to distinguish the transient
     * intra-transaction state from the committed state.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeLinkedToWalletObligation(Builder $query): Builder
    {
        return $query->whereNotNull('wallet_obligation_id');
    }

    /**
     * Scope a query to penalties that have NOT been written back to a
     * Wallet obligation.
     *
     * Because the writer performs the write-back inside the inserting
     * transaction, a committed penalty row should never satisfy this
     * scope. A non-empty result indicates a data-integrity anomaly: either
     * a defect in the writer that left a row half-inserted (which would
     * require a bug in the transaction discipline to have committed) or
     * an out-of-band edit. The scope exists so that an audit or
     * reconciliation pass can detect such rows.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereNull('wallet_obligation_id');
    }

    /**
     * Scope a query to chronological order.
     *
     * Orders by `recorded_at` ascending, with an `id` tiebreaker for
     * same-instant penalties. Matches the migration A.16's composite
     * index `(loan_id, recorded_at)`.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('recorded_at')->orderBy('id');
    }

    /**
     * Scope a query to most-recent-first order.
     *
     * The reverse of `scopeChronological()`. Used by the loan detail view
     * and by administrative dashboards that show the latest penalties
     * first.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeMostRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('recorded_at')->orderByDesc('id');
    }

    // =========================================================================
    // Domain helpers (read-only)
    // =========================================================================

    /**
     * Whether the penalty has been written back to a Wallet obligation.
     *
     * True when `wallet_obligation_id` is non-null. A committed penalty
     * row always satisfies this predicate; a row read inside the
     * inserting transaction (before the write-back) does not.
     *
     * @return bool
     */
    public function hasWalletObligation(): bool
    {
        return $this->wallet_obligation_id !== null;
    }

    /**
     * Whether the penalty is fully committed — the write-back has
     * occurred.
     *
     * Alias for `hasWalletObligation()`. The distinction the two names
     * express is subtle but useful at call sites: `hasWalletObligation()`
     * asks "is the FK set?"; `isCommitted()` asks "is the penalty a
     * complete, durable fact?". For this table the two conditions
     * coincide, because the write-back occurs inside the inserting
     * transaction and the row is only visible to outside readers after
     * commit.
     *
     * @return bool
     */
    public function isCommitted(): bool
    {
        return $this->hasWalletObligation();
    }
}