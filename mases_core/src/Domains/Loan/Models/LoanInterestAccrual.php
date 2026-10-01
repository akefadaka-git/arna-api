<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Loan\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use MaaleArna\MASES\Core\Domains\Wallet\Models\Obligation;

/**
 * LoanInterestAccrual
 *
 * Eloquent model for a single interest-accrual bookkeeping row.
 *
 * =============================================================================
 * What this model represents
 * =============================================================================
 * One row per interest accrual recognized against a loan installment. The
 * row links a specific installment to the Wallet obligation that was
 * created when the installment's due date arrived, and records the
 * interest amount, the moment of recognition, and the resulting Wallet
 * obligation's id.
 *
 * The table is the Loan domain's bookkeeping for the interest obligations
 * it has recorded on the Wallet. It does NOT carry the monetary ledger —
 * that belongs to the Wallet domain (Phase 2B, `wallet_obligations` and
 * `wallet_entries`). It carries the LOAN-side record of what has already
 * been accrued, so that the scheduled accrual command (A.87) can be run
 * repeatedly without creating duplicate Wallet obligations.
 *
 * =============================================================================
 * The idempotency role (migration A.15)
 * =============================================================================
 * The table's central purpose is idempotency. The unique constraint on
 * `accrual_period_key` (migration A.15) is what makes the daily
 * `AccrueInterestCommand` (A.87) idempotent across duplicate runs,
 * overlapping instances, and manual invocations. The key is deterministic
 * from the loan and installment it accrues against:
 *
 *     accrual_period_key = "{loan_id}:{installment_id}"
 *
 * Because the key is deterministic, the unique constraint IS the
 * one-accrual-per-installment rule. See the migration A.15 §"The
 * idempotency role" for the full transactional sequence.
 *
 * This model does NOT compute the key. That is the responsibility of
 * `LoanInterestAccrualService` (A.28), which is the sole writer.
 *
 * =============================================================================
 * Partial mutability — the one-time wallet_obligation_id write-back
 * =============================================================================
 * This model is PARTIALLY MUTABLE at the transactional boundary, in a way
 * that is more nuanced than the other Loan-domain models:
 *
 *   1. The row is INSERTED with `wallet_obligation_id = null`. The insert
 *      is what enforces the idempotency: the unique constraint on
 *      `accrual_period_key` either admits the new row or rejects a
 *      duplicate.
 *
 *   2. The command then calls `ObligationService::record()` on the Wallet
 *      (Phase 2B), receiving the created obligation's id.
 *
 *   3. The command UPDATEs the row's `wallet_obligation_id` column to the
 *      resulting id. This is the ONLY permitted mutation on the row.
 *
 *   4. The whole sequence runs inside ONE transaction. From the
 *      perspective of any reader outside the transaction, the row
 *      appears atomically complete: either it exists with a non-null
 *      `wallet_obligation_id`, or it does not exist at all.
 *
 * The model ENFORCES this narrow mutability at the language level, via a
 * `booted()` guard that throws `LogicException` on any `updating` event
 * EXCEPT the one-time write of `wallet_obligation_id` from null to a
 * non-null value. The guard's precise condition is documented in the
 * `booted()` method's docblock.
 *
 * All other columns are IMMUTABLE after insert:
 *
 *     loan_id, installment_id, accrual_period_key,
 *     interest_amount, recorded_at, created_at
 *
 * Deletion is FORBIDDEN unconditionally, mirroring the pattern on
 * `LoanRestructuring` (A.21), `LoanWriteOff` (A.22), and
 * `LoanStatusHistory` (A.23).
 *
 * =============================================================================
 * No `updated_at` column
 * =============================================================================
 * The migration A.15 does NOT declare an `updated_at` column. The table
 * is treated as append-only at the reader level: the single
 * `wallet_obligation_id` write happens inside the row's own insert
 * transaction and is invisible to any interleaved reader.
 *
 * The model therefore sets `$timestamps = false`, matching the pattern
 * established by Phase 2A's `ShareHoldingEntry` (2A.9), Phase 2B's
 * `WalletEntry` (2B.10), and the sibling `LoanStatusHistory` (A.23).
 * `created_at` is written explicitly by the writer; `updated_at` does not
 * exist.
 *
 * =============================================================================
 * Relations
 * =============================================================================
 *   loan()              — the loan this accrual belongs to.
 *   installment()       — the installment whose due date triggered the
 *                          accrual.
 *   walletObligation()  — the Wallet obligation produced by this accrual.
 *
 * The `walletObligation()` relation targets `Obligation` from the Wallet
 * domain (Phase 2B, 2B.6). This is the ONE cross-domain relation in the
 * Loan domain: the accrual row references a specific Wallet obligation by
 * primary key, because the reconciliation service (A.32) and the loan
 * detail view need to link each accrual to the monetary fact it
 * represents. This is the reason the migration A.15 declares an actual
 * FK on `wallet_obligation_id`, unlike the Wallet's cross-domain
 * references to Loan objects, which are string-keyed.
 *
 * The relation is NULLABLE at the model level: the FK column is nullable
 * to permit the insert-then-update sequence. In practice a committed row
 * always has a non-null value.
 *
 * =============================================================================
 * Casts
 * =============================================================================
 *   loan_id,
 *   installment_id,
 *   wallet_obligation_id       — integer (nullable on the last).
 *
 *   interest_amount            — decimal:4 (string).
 *   recorded_at                — datetime.
 *   created_at                 — datetime|null (nullable column, written
 *                                 explicitly).
 *
 * All monetary values are strings at scale 4. The platform-wide
 * convention: financial arithmetic uses bcmath on strings, never floats.
 *
 * There are NO enum casts on this model: the accrual row carries only
 * concrete values.
 *
 * =============================================================================
 * Scopes
 * =============================================================================
 *   scopeForLoan($query, $loanId)          — filter by owning loan.
 *   scopeForInstallment($query, $installmentId) — filter by installment.
 *   scopeLinkedToWalletObligation($query)  — wallet_obligation_id IS NOT
 *                                             NULL.
 *   scopeUnlinked($query)                  — wallet_obligation_id IS NULL
 *                                             (defensive; a committed
 *                                             row always has a non-null
 *                                             value in practice).
 *   scopeChronological($query)             — order by recorded_at, id.
 *   scopeMostRecentFirst($query)           — order by recorded_at DESC,
 *                                             id DESC.
 *
 * =============================================================================
 * Domain helpers (read-only)
 * =============================================================================
 *   hasWalletObligation()  — wallet_obligation_id is non-null.
 *   isCommitted()          — alias for hasWalletObligation(): the row
 *                             represents a committed accrual (the
 *                             transient intra-transaction state is
 *                             null).
 *
 * =============================================================================
 * What this model deliberately does NOT do
 * =============================================================================
 *   - It does not compute the `accrual_period_key`. The writer
 *     (`LoanInterestAccrualService`, A.28) computes it deterministically
 *     from the loan and installment ids.
 *
 *   - It does not compute the `interest_amount`. The writer reads the
 *     loan's frozen `loan_product_rules` policy and computes the amount
 *     from the schedule's installment.
 *
 *   - It does not call the Wallet. The call to
 *     `ObligationService::record()` is the writer's responsibility; the
 *     model records the resulting id.
 *
 *   - It does not dispatch events. The events that fire on an interest
 *     accrual are dispatched by the writer as part of the transaction's
 *     after-commit callbacks.
 *
 *   - It does not use SoftDeletes. Accrual history is permanent.
 *
 *   - It does not attempt a `reverse()` method. Phase 2C does not support
 *     reversing an accrual; a correction would be a new accrual row or an
 *     explicit Wallet-side adjustment, both of which are out of scope.
 *
 *   - It does not expose a `record()` or `accrue()` method. The model is
 *     a data container; the writer owns the sequence.
 *
 * =============================================================================
 * References
 * =============================================================================
 *   Phase 2C Decision Register, 2026-09-12       — A9 (interest method),
 *                                                  B3 (interest recording
 *                                                  pattern)
 *   Phase 2C Clarification Response, 2026-09-12  — C5 (reconciliation)
 *   Phase 2C Final Reconciliation, 2026-09-12    — §4.2 (this table's
 *                                                  specification),
 *                                                  §4.3 (command
 *                                                  semantics), §4.4
 *                                                  (policy-data-driven
 *                                                  amounts)
 *   Phase 2B migration 2B.50                     — `wallet_obligations`
 *   Phase 2B `Obligation`                        — the FK target model
 *   Step 5 (manifest) A.24                       — This file
 *   Step 6 v1.1.0 §3.15                          — `loan_interest_accruals`
 *                                                  table specification
 *
 * @property int                     $id
 * @property int                     $loan_id
 * @property int                     $installment_id
 * @property string                  $accrual_period_key
 * @property string                  $interest_amount
 * @property int|null                $wallet_obligation_id
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 *
 * @property-read Loan|null          $loan
 * @property-read LoanInstallment|null $installment
 * @property-read Obligation|null    $walletObligation
 *
 * @package MaaleArna\MASES\Core\Domains\Loan\Models
 * @version 1.0.0
 */
final class LoanInterestAccrual extends Model
{
    use HasFactory;

    /**
     * The database table backing the model.
     *
     * @var string
     */
    protected $table = 'loan_interest_accruals';

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
     * Set to false. The migration A.15 declares `created_at` as nullable
     * and does NOT declare an `updated_at` column. With
     * `$timestamps = false`, Eloquent will not attempt to write either
     * column on `save()`. `created_at` is written explicitly by
     * `LoanInterestAccrualService` (A.28) at insert time.
     *
     * This mirrors the pattern established by Phase 2A's
     * `ShareHoldingEntry` (2A.9), Phase 2B's `WalletEntry` (2B.10), and
     * the sibling `LoanStatusHistory` (A.23).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * The list is authoritative for the `LoanInterestAccrualService`
     * (A.28) insert call site. Because the row is inserted in one step
     * and only `wallet_obligation_id` is ever updated afterwards, the
     * `$fillable` list includes every column except the primary key. The
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
        'interest_amount',
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
        'interest_amount'      => 'decimal:4',
        'wallet_obligation_id' => 'integer',
        'recorded_at'          => 'datetime',
        'created_at'           => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * Left empty on purpose. This model has no resource in the Phase 2C
     * manifest; accrual rows are surfaced through aggregate reports and
     * through the loan's detail view, not through a dedicated endpoint.
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
     * Registers two guards:
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
     * and creation is the only legitimate way to introduce a new accrual.
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
     * `interest_amount`, `recorded_at`, or any other column after insert,
     * and a caller that attempts to null the `wallet_obligation_id` on a
     * row that already had one.
     *
     * The guard is the SECOND line of defence after the service's
     * discipline. The service is the sole writer and performs only the
     * designed write-back. The guard exists to catch a future contributor
     * who calls `$row->save()` on a loaded model outside the designed
     * path.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::updating(static function (LoanInterestAccrual $row): void {
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
                'LoanInterestAccrual rows permit only the one-time write '
                . 'of wallet_obligation_id from null to a non-null value, '
                . 'inside the inserting transaction (migration A.15, '
                . 'class docblock §"Partial mutability"). Any other '
                . 'attempted update — including a change to any other '
                . 'column, a re-write of wallet_obligation_id after the '
                . 'first write, or a nulling of wallet_obligation_id on a '
                . 'committed row — is forbidden. '
                . 'Attempted update on loan_interest_accruals.id='
                . $row->getKey() . '; dirty attributes: '
                . implode(', ', array_keys($dirty)) . '.'
            );
        });

        static::deleting(static function (LoanInterestAccrual $row): void {
            throw new LogicException(
                'LoanInterestAccrual rows cannot be deleted '
                . '(migration A.15, class docblock §"Partial mutability"). '
                . 'Accrual history is permanent. '
                . 'Attempted delete on loan_interest_accruals.id='
                . $row->getKey() . '.'
            );
        });
    }

    // =========================================================================
    // Relations
    // =========================================================================

    /**
     * The loan this accrual belongs to.
     *
     * @return BelongsTo
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id', 'id');
    }

    /**
     * The installment whose due date triggered this accrual.
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
     * The Wallet obligation produced by this accrual, if any.
     *
     * Targets `Obligation` from the Wallet domain (Phase 2B, 2B.6). This
     * is the ONE cross-domain relation in the Loan domain: the accrual
     * row references a specific Wallet obligation by primary key, because
     * the reconciliation service (A.32) and the loan detail view need to
     * link each accrual to the monetary fact it represents.
     *
     * Nullable at the model level: the FK column is nullable to permit
     * the insert-then-update sequence (see the class docblock
     * §"Partial mutability"). A committed row always has a non-null value
     * in practice; `hasWalletObligation()` reports whether the value is
     * set.
     *
     * A caller that reads this relation on a committed row receives the
     * Wallet obligation. A caller that reads it inside the inserting
     * transaction (before the write-back) receives null.
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
     * view to enumerate a loan's accruals.
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
     * most one accrual per installment in the current scheme, this scope
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
     * Scope a query to accruals that have been written back to a Wallet
     * obligation.
     *
     * A committed accrual row always satisfies this scope. The scope's
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
     * Scope a query to accruals that have NOT been written back to a
     * Wallet obligation.
     *
     * Because the writer performs the write-back inside the inserting
     * transaction, a committed accrual row should never satisfy this
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
     * same-instant accruals. Matches the migration A.15's composite index
     * `(loan_id, recorded_at)`.
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
     * and by administrative dashboards that show the latest accruals
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
     * Whether the accrual has been written back to a Wallet obligation.
     *
     * True when `wallet_obligation_id` is non-null. A committed accrual
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
     * Whether the accrual is fully committed — the write-back has
     * occurred.
     *
     * Alias for `hasWalletObligation()`. The distinction the two names
     * express is subtle but useful at call sites: `hasWalletObligation()`
     * asks "is the FK set?"; `isCommitted()` asks "is the accrual a
     * complete, durable fact?". For this table the two conditions
     * coincide, because the write-back occurs inside the inserting
     * transaction and the row is only visible to outside readers after
     * commit. Callers that prefer to express intent over mechanism use
     * `isCommitted()`.
     *
     * @return bool
     */
    public function isCommitted(): bool
    {
        return $this->hasWalletObligation();
    }
}